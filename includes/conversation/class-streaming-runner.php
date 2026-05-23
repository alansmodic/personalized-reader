<?php
/**
 * Drives one chat turn end-to-end for the reader agent.
 *
 * Uses the native WordPress AI client surface:
 *
 *   - `wp_ai_client_prompt()->using_abilities( ...$slugs )` registers
 *     the four reader abilities as function declarations using the
 *     `wpab__` naming convention. The wrapper handles slug encoding
 *     and FunctionDeclaration construction for us.
 *   - `WP_AI_Client_Ability_Function_Resolver` checks the model's
 *     reply for ability calls and executes them, returning a
 *     UserMessage with FunctionResponse parts that we feed back into
 *     the next round.
 *
 * Transcript shape:
 *   - 'user' / 'assistant' rows store plain text in `content` and the
 *     full Message DTO array in `meta.message` so we can rebuild the
 *     conversation history precisely (preserving function-call parts).
 *   - 'tool' rows store a human-readable summary in `content` and the
 *     FunctionResponse UserMessage in `meta.message`.
 *
 * Iteration budget: 4 tool-call rounds per user message.
 *
 * The AI client surface here does not expose live token streaming;
 * each round resolves to a complete model message that we emit as one
 * `assistant_chunk` event. The SSE/buffering split below the runner is
 * preserved so streaming can drop in later without runner changes.
 *
 * @package PersonalizedReader
 */

declare( strict_types=1 );

namespace PersonalizedReader\Conversation;

use PersonalizedReader\Abilities\Abilities;
use PersonalizedReader\Chat\Transcript_Store;
use PersonalizedReader\Settings\Settings;
use PersonalizedReader\Streaming\Event_Sink;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;

defined( 'ABSPATH' ) || exit;

final class Streaming_Runner {

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

		// Idempotency replay: re-emit assistant/tool messages tied to this
		// request_id without re-calling the model.
		if ( '' !== $request_id ) {
			$existing = $this->transcript->load( $session_token );
			foreach ( array_reverse( $existing ) as $msg ) {
				if ( 'user' === $msg['role'] && ( $msg['meta']['request_id'] ?? '' ) === $request_id ) {
					$this->replay_from_transcript( $existing, $request_id );
					$this->events->done();
					return;
				}
			}
		}

		$user_dto = new UserMessage( array( new \WordPress\AiClient\Messages\DTO\MessagePart( $user_message ) ) );

		$this->transcript->append( $session_token, 'user', $user_message, array(
			'request_id' => $request_id,
			'message'    => $user_dto->toArray(),
		) );
		$this->events->emit( 'user_message_persisted', array( 'ts' => time() ) );

		// History contains everything *before* this turn's user message.
		// The user message itself becomes the first $next_msg; each round
		// appends the previous $next_msg + the model's reply to history
		// before generating again.
		$history  = $this->history_messages_excluding_last( $session_token );
		$pending  = array();
		$next_msg = $user_dto;

		$resolver = new \WP_AI_Client_Ability_Function_Resolver( ...Abilities::ABILITY_SLUGS );

		$max_rounds = (int) Settings::get( 'max_tool_rounds' );
		$round      = 0;
		while ( $round < $max_rounds ) {
			$round++;
			$this->events->emit( 'turn_started', array( 'round' => $round ) );

			$result = $this->call_model( $next_msg, $history );
			if ( isset( $result['error'] ) ) {
				$this->events->error( (string) $result['error'] );
				$pending[] = array(
					'role'    => 'assistant',
					'content' => '[error: ' . $result['error'] . ']',
					'ts'      => time(),
					'meta'    => array(),
				);
				$this->transcript->batch_append( $session_token, $pending );
				return;
			}

			/** @var ModelMessage $model_msg */
			$model_msg     = $result['message'];
			$assistant_txt = $this->message_text( $model_msg );

			if ( '' !== $assistant_txt ) {
				$this->events->emit( 'assistant_chunk', array( 'text' => $assistant_txt ) );
			}

			$pending[] = array(
				'role'    => 'assistant',
				'content' => $assistant_txt,
				'ts'      => time(),
				'meta'    => array( 'message' => $model_msg->toArray() ),
			);

			// Record this round in history: the user-side message we sent
			// and the model's reply. Subsequent rounds will see both.
			$history[] = $next_msg;
			$history[] = $model_msg;

			if ( ! $resolver->has_ability_calls( $model_msg ) ) {
				$this->transcript->batch_append( $session_token, $pending );
				$this->events->done();
				return;
			}

			// Execute every ability call in this message; emit one event
			// per call before/after so the UI can render progress.
			$this->emit_tool_calls( $model_msg );

			$response_msg = $resolver->execute_abilities( $model_msg );

			$this->emit_tool_results( $response_msg );

			$pending[] = array(
				'role'    => 'tool',
				'content' => $this->message_summary( $response_msg ),
				'ts'      => time(),
				'meta'    => array( 'message' => $response_msg->toArray() ),
			);

			// $response_msg becomes the next user-side turn. It will be
			// appended to history at the top of the next round, after
			// $model_msg already was — so the model never sees a missing
			// user→model→user alternation.
			$next_msg = $response_msg;
		}

		$this->transcript->batch_append( $session_token, $pending );
		$this->events->error( 'tool_round_budget_exceeded', array( 'max' => $max_rounds ) );
		$this->events->done();
	}

	/**
	 * Issue one model call. The new user-side message (either the user's
	 * original prompt or the resolver's FunctionResponse) becomes the
	 * builder's prompt; everything else is history.
	 *
	 * @param Message              $next_msg The new message to send.
	 * @param array<int, Message>  $history  Preceding messages.
	 * @return array{message?:Message, error?:string}
	 */
	private function call_model( Message $next_msg, array $history ): array {
		try {
			$builder = \wp_ai_client_prompt( $next_msg )
				->using_system_instruction( $this->composer->system_prompt() )
				->using_abilities( ...Abilities::ABILITY_SLUGS );

			if ( ! empty( $history ) ) {
				$builder = $builder->with_history( ...$history );
			}

			$result = $builder->generate_text_result();
			if ( is_wp_error( $result ) ) {
				return array( 'error' => $result->get_error_message() );
			}

			return array( 'message' => $result->toMessage() );
		} catch ( \Throwable $e ) {
			return array( 'error' => $e->getMessage() );
		}
	}

	/**
	 * Rebuild Message DTOs from transcript so we can pass them to
	 * with_history(). Falls back to a plain UserMessage when a stored
	 * `meta.message` is missing (legacy or imported rows).
	 *
	 * @return array<int, Message>
	 */
	private function history_messages_excluding_last( string $session_token ): array {
		$entries = $this->transcript->load( $session_token );
		$out     = array();

		foreach ( $entries as $entry ) {
			$serialized = $entry['meta']['message'] ?? null;
			if ( is_array( $serialized ) ) {
				try {
					$out[] = Message::fromArray( $serialized );
					continue;
				} catch ( \Throwable $e ) {
					// fall through to text fallback
				}
			}

			$role    = (string) ( $entry['role'] ?? '' );
			$content = (string) ( $entry['content'] ?? '' );
			if ( '' === $content ) {
				continue;
			}
			$part = new \WordPress\AiClient\Messages\DTO\MessagePart( $content );
			$out[] = 'assistant' === $role
				? new ModelMessage( array( $part ) )
				: new UserMessage( array( $part ) );
		}

		// The last entry in history is the just-stored user message; drop
		// it because call_model() passes it separately as $next_msg.
		array_pop( $out );

		return $out;
	}

	private function message_text( Message $message ): string {
		$out = '';
		foreach ( $message->getParts() as $part ) {
			if ( $part->getType()->isText() ) {
				$out .= (string) $part->getText();
			}
		}
		return trim( $out );
	}

	private function emit_tool_calls( Message $model_msg ): void {
		foreach ( $model_msg->getParts() as $part ) {
			if ( ! $part->getType()->isFunctionCall() ) {
				continue;
			}
			$call = $part->getFunctionCall();
			if ( ! $call ) {
				continue;
			}
			$this->events->emit( 'tool_call', array(
				'name'      => (string) $call->getName(),
				'arguments' => $call->getArgs() ?? array(),
			) );
		}
	}

	private function emit_tool_results( Message $response_msg ): void {
		foreach ( $response_msg->getParts() as $part ) {
			if ( ! $part->getType()->isFunctionResponse() ) {
				continue;
			}
			$response = $part->getFunctionResponse();
			if ( ! $response ) {
				continue;
			}
			$this->events->emit( 'tool_result', array(
				'name'   => (string) $response->getName(),
				'result' => $response->getResponse() ?? array(),
			) );
		}
	}

	private function message_summary( Message $message ): string {
		$names = array();
		foreach ( $message->getParts() as $part ) {
			if ( $part->getType()->isFunctionResponse() ) {
				$resp = $part->getFunctionResponse();
				if ( $resp ) {
					$names[] = (string) $resp->getName();
				}
			}
		}
		return 'tool_responses: ' . implode( ', ', $names );
	}

	private function replay_from_transcript( array $messages, string $request_id ): void {
		$emit_after = false;
		foreach ( $messages as $msg ) {
			if ( ! $emit_after ) {
				if ( 'user' === $msg['role'] && ( $msg['meta']['request_id'] ?? '' ) === $request_id ) {
					$emit_after = true;
				}
				continue;
			}

			if ( 'assistant' === $msg['role'] ) {
				$this->events->emit( 'assistant_chunk', array( 'text' => (string) $msg['content'] ) );
			} elseif ( 'tool' === $msg['role'] ) {
				$serialized = $msg['meta']['message'] ?? null;
				if ( is_array( $serialized ) ) {
					try {
						$this->emit_tool_results( Message::fromArray( $serialized ) );
					} catch ( \Throwable $e ) {
						// skip
					}
				}
			}
		}
	}
}
