<?php
/**
 * Thin orchestration over WP_Agent_Conversation_Loop.
 *
 * Responsibilities (just these — everything else belongs to the substrate):
 *
 *   - Load the session's prior transcript (envelopes).
 *   - Append the new user message as a text envelope.
 *   - Hand the substrate everything it needs to run a turn:
 *       • the message history
 *       • a turn_runner closure that calls wp_ai_client_prompt() and
 *         translates the model's response into substrate-shaped tool_calls
 *       • a WP_Agent_Tool_Executor that dispatches calls to abilities
 *       • a tool_declarations array (one entry per ability we expose)
 *       • a WP_Agent_Transcript_Persister (our Transcript_Store)
 *       • an on_event sink wired to our Event_Sink interface
 *   - On return, replay any text/tool events the runner missed back through
 *     the event sink. (The substrate already emitted lifecycle events live,
 *     but our SSE clients expect `assistant_chunk` payloads — those come
 *     from the turn_runner's text return value.)
 *
 * Streaming behavior is unchanged from the perspective of the widget: we
 * still emit assistant_chunk / tool_call / tool_result / done frames. They
 * just come from the substrate's lifecycle hooks now instead of our own
 * hand-rolled loop.
 *
 * @package PersonalizedReader
 */

declare( strict_types=1 );

namespace PersonalizedReader\Conversation;

use AgentsAPI\AI\WP_Agent_Conversation_Loop;
use AgentsAPI\AI\WP_Agent_Message;
use PersonalizedReader\Abilities\Abilities;
use PersonalizedReader\Chat\Conversation_Lock;
use PersonalizedReader\Chat\Transcript_Store;
use PersonalizedReader\Settings\Settings;
use PersonalizedReader\Streaming\Event_Sink;
use PersonalizedReader\Tools\Tool_Executor;
use WordPress\AiClient\Messages\DTO\Message as AiMessage;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

defined( 'ABSPATH' ) || exit;

final class Conversation_Runner {

	public function __construct(
		private readonly Context_Composer $composer,
		private readonly Transcript_Store $transcript,
		private readonly Event_Sink $events,
	) {
	}

	public function run( string $session_token, string $user_message, string $request_id = '' ): void {
		$this->events->start();

		if ( '' === trim( $session_token ) ) {
			$this->events->error( 'missing_session_token' );
			$this->events->done();
			return;
		}
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			$this->events->error( 'wp_ai_client_prompt unavailable' );
			$this->events->done();
			return;
		}
		if ( ! class_exists( WP_Agent_Conversation_Loop::class ) ) {
			$this->events->error( 'agents_api_loop_unavailable' );
			$this->events->done();
			return;
		}

		$history = $this->transcript->load( $session_token );

		// Append the new user message as a substrate envelope. The
		// substrate will normalize it on entry, but we keep the shape
		// explicit so transcript dumps stay readable.
		$history[] = WP_Agent_Message::text( 'user', $user_message, array(
			'request_id' => $request_id,
		) );

		$declarations  = $this->build_tool_declarations();
		$executor      = new Tool_Executor();
		$turn_runner   = $this->build_turn_runner();
		$event_sink    = $this->build_event_sink_bridge();

		try {
			$loop_result = WP_Agent_Conversation_Loop::run(
				$history,
				$turn_runner,
				array(
					'max_turns'            => (int) Settings::get( 'max_tool_rounds' ),
					'tool_executor'        => $executor,
					'tool_declarations'    => $declarations,
					'transcript_persister' => $this->transcript,
					'on_event'             => $event_sink,
					'context'              => array(
						'session_token' => $session_token,
						'request_id'    => $request_id,
					),
					// Single-writer lock. If a concurrent request is still
					// holding the lock, the substrate emits
					// `transcript_lock_contention` and exits without
					// generating — our event sink turns that into a clean
					// error frame for the client.
					'transcript_lock'        => new Conversation_Lock(),
					'transcript_session_id'  => $session_token,
					'transcript_lock_ttl'    => 120,
					// Stop the loop the moment the model produces a plain
					// text answer (no tool_calls). The substrate's default
					// for mediation-enabled is "always continue" and relies
					// on max_turns alone, which makes the model repeat its
					// final answer N times.
					'should_continue'      => static function ( array $result ): bool {
						return ! empty( $result['tool_calls'] ?? array() );
					},
				)
			);

			( new Usage_Tracker() )->record( is_array( $loop_result ) ? $loop_result : array() );
		} catch ( \Throwable $e ) {
			$this->events->error( $e->getMessage() );
		}

		$this->events->done();
	}

	// -- Turn runner --------------------------------------------------

	private function build_turn_runner(): callable {
		$composer = $this->composer;
		$events   = $this->events;

		return static function ( array $messages, array $context ) use ( $composer, $events ): array {
			$history = self::envelopes_to_ai_messages( $messages );

			// The latest user-side envelope becomes the prompt; everything
			// before it is history. AI Client's with_history() expects the
			// past; the constructor argument is the new turn.
			$prompt = array_pop( $history );
			if ( null === $prompt ) {
				return array( 'content' => '', 'tool_calls' => array() );
			}

			$builder = \wp_ai_client_prompt( $prompt )
				->using_system_instruction( $composer->system_prompt() )
				->using_abilities( ...Abilities::ABILITY_SLUGS );

			if ( ! empty( $history ) ) {
				$builder = $builder->with_history( ...$history );
			}

			$result = $builder->generate_text_result();
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( $result->get_error_message() );
			}

			$model_msg = $result->toMessage();
			$usage_dto = $result->getTokenUsage();
			$usage     = array(
				'prompt_tokens'     => $usage_dto->getPromptTokens(),
				'completion_tokens' => $usage_dto->getCompletionTokens(),
				'total_tokens'      => $usage_dto->getTotalTokens(),
			);

			$text       = '';
			$tool_calls = array();
			foreach ( $model_msg->getParts() as $part ) {
				if ( $part->getType()->isText() ) {
					$text .= (string) $part->getText();
				} elseif ( $part->getType()->isFunctionCall() ) {
					$call = $part->getFunctionCall();
					if ( ! $call ) {
						continue;
					}
					$function_name = (string) $call->getName();
					$ability_name  = \WP_AI_Client_Ability_Function_Resolver::function_name_to_ability_name( $function_name );
					$tool_calls[]  = array(
						'name'         => $ability_name,
						'parameters'   => (array) ( $call->getArgs() ?? array() ),
						'tool_call_id' => (string) ( $call->getId() ?? '' ),
					);
				}
			}

			$text = trim( $text );
			if ( '' !== $text ) {
				$events->emit( 'assistant_chunk', array( 'text' => $text ) );
			}

			// Always pass `messages` through. The substrate's tool mediator
			// starts from `$result['messages']`; if we omit it, the prior
			// turn's history is dropped between rounds.
			//
			// `content` is kept even when tool_calls are present so the
			// preamble ("let me search…") makes it into the persisted
			// transcript. The substrate stores it as a separate assistant
			// text envelope. On the way back in (next turn's history),
			// envelopes_to_ai_messages() merges consecutive assistant
			// envelopes into a single ModelMessage with both text and
			// FunctionCall parts — that's the shape Anthropic expects;
			// two separate Model messages would be rejected.
			return array(
				'messages'   => $messages,
				'content'    => $text,
				'tool_calls' => $tool_calls,
				'usage'      => $usage,
			);
		};
	}

	// -- Event sink bridge --------------------------------------------

	/**
	 * Translate substrate lifecycle events into our Event_Sink contract.
	 * Most substrate events are dropped on the floor — turn_started and
	 * tool_call/result are what the SSE clients want.
	 */
	private function build_event_sink_bridge(): callable {
		$events = $this->events;
		return static function ( string $event, array $payload ) use ( $events ): void {
			switch ( $event ) {
				case 'turn_started':
					$events->emit( 'turn_started', array( 'round' => (int) ( $payload['turn'] ?? 0 ) ) );
					break;
				case 'tool_call':
					$events->emit( 'tool_call', array(
						'name'      => (string) ( $payload['tool_name'] ?? '' ),
						'arguments' => (array) ( $payload['parameters'] ?? array() ),
					) );
					break;
				case 'tool_result':
					$events->emit( 'tool_result', array(
						'name'   => (string) ( $payload['tool_name'] ?? '' ),
						'result' => (array) ( $payload['result'] ?? $payload ),
					) );
					break;
				case 'failed':
					$events->error( (string) ( $payload['error'] ?? 'turn_failed' ), $payload );
					break;
				case 'transcript_lock_contention':
					$events->error( 'transcript_lock_contention', $payload );
					break;
			}
		};
	}

	// -- Tool declarations -------------------------------------------

	/**
	 * Build the substrate's tool_declarations array. Schema is sourced from
	 * each registered ability — single source of truth, no drift between
	 * the agent's declarations and the abilities' input schemas.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function build_tool_declarations(): array {
		$out = array();
		foreach ( Abilities::ABILITY_SLUGS as $slug ) {
			$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $slug ) : null;
			if ( ! is_object( $ability ) ) {
				continue;
			}
			$out[ $slug ] = array(
				'name'        => $slug,
				'description' => method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '',
				'parameters'  => method_exists( $ability, 'get_input_schema' ) ? (array) $ability->get_input_schema() : array(),
			);
		}
		return $out;
	}

	// -- Envelope → AI Client conversion ------------------------------

	/**
	 * Convert a list of substrate envelopes to AI Client Message DTOs,
	 * merging consecutive assistant envelopes into a single ModelMessage
	 * with multiple parts (text + FunctionCall).
	 *
	 * Why merge: when the model returns "let me search…" followed by a
	 * tool call, the substrate stores those as two separate assistant
	 * envelopes (one text, one tool_call). Passed back as two ModelMessages
	 * they would form a consecutive Model→Model sequence that Anthropic
	 * rejects. As a single ModelMessage with two parts, they're valid.
	 *
	 * Tool_result envelopes (role=user, FunctionResponse parts) are also
	 * grouped with any text user envelope that immediately follows or
	 * precedes them on the user side, for the same reason.
	 *
	 * @param array<int, array<string, mixed>> $envelopes
	 * @return array<int, AiMessage>
	 */
	private static function envelopes_to_ai_messages( array $envelopes ): array {
		$out          = array();
		$pending_role = null;
		$pending_parts = array();

		$flush = static function () use ( &$out, &$pending_role, &$pending_parts ): void {
			if ( null === $pending_role || empty( $pending_parts ) ) {
				return;
			}
			$out[] = 'assistant' === $pending_role
				? new ModelMessage( $pending_parts )
				: new UserMessage( $pending_parts );
			$pending_role  = null;
			$pending_parts = array();
		};

		foreach ( $envelopes as $envelope ) {
			$role = (string) ( $envelope['role'] ?? 'user' );
			$part = self::envelope_to_part( $envelope );
			if ( null === $part ) {
				continue;
			}

			if ( null !== $pending_role && $pending_role !== $role ) {
				$flush();
			}
			$pending_role    = $role;
			$pending_parts[] = $part;
		}
		$flush();

		return $out;
	}

	/**
	 * Convert a single envelope into a MessagePart suitable for inclusion
	 * in either a UserMessage or ModelMessage. Returns null for envelopes
	 * we can't represent (which the caller drops, not rejects).
	 */
	private static function envelope_to_part( array $envelope ): ?MessagePart {
		$type    = (string) ( $envelope['type'] ?? 'text' );
		$content = (string) ( $envelope['content'] ?? '' );
		$payload = (array) ( $envelope['payload'] ?? array() );

		if ( 'tool_call' === $type ) {
			$tool_name     = (string) ( $payload['tool_name'] ?? '' );
			$function_name = \WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name( $tool_name );
			$call          = new FunctionCall(
				(string) ( $envelope['metadata']['tool_call_id'] ?? '' ),
				$function_name,
				(array) ( $payload['parameters'] ?? array() )
			);
			return new MessagePart( $call );
		}

		if ( 'tool_result' === $type ) {
			$tool_name     = (string) ( $payload['tool_name'] ?? '' );
			$function_name = \WP_AI_Client_Ability_Function_Resolver::ability_name_to_function_name( $tool_name );
			$decoded       = json_decode( $content, true );
			$response_data = is_array( $decoded ) ? $decoded : array( 'result' => $content );
			$response      = new FunctionResponse(
				(string) ( $envelope['metadata']['tool_call_id'] ?? '' ),
				$function_name,
				$response_data
			);
			return new MessagePart( $response );
		}

		// Default: text envelope. Drop empty text (which the substrate
		// occasionally emits when content is omitted) so we don't generate
		// empty MessagePart instances.
		if ( '' === $content ) {
			return null;
		}
		return new MessagePart( $content );
	}
}
