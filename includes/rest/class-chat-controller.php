<?php
/**
 * REST controller for the reader chat UI.
 *
 *   POST /personalized-reader/v1/session     — mint a session token + nonce
 *   GET  /personalized-reader/v1/transcript  — load visible history for a session
 *   POST /personalized-reader/v1/clear       — drop a session's transcript
 *   POST /personalized-reader/v1/send        — non-streaming fallback (buffered events)
 *
 * The live streaming path lives in Streaming\Chat_Stream_Endpoint. This
 * controller exists for clients on hosts where SSE is unavailable, and
 * for the initial handshake (mint a token + nonce before the first turn).
 *
 * All routes are public (permission_callback returns true). Nonces and
 * the per-session rate limiter are the guardrails. Auth-cookie binding
 * is unavailable for anonymous readers.
 *
 * @package PersonalizedReader
 */

declare( strict_types=1 );

namespace PersonalizedReader\Rest;

use PersonalizedReader\Chat\Transcript_Store;
use PersonalizedReader\Conversation\Context_Composer;
use PersonalizedReader\Conversation\Conversation_Runner;
use PersonalizedReader\Settings\Settings;
use PersonalizedReader\Streaming\Buffering_Event_Sink;
use PersonalizedReader\Streaming\Chat_Stream_Endpoint;
use PersonalizedReader\Utils\Rate_Limiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class Chat_Controller {

	public const NAMESPACE_ROOT = 'personalized-reader/v1';

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_ROOT,
			'/session',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'mint_session' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE_ROOT,
			'/transcript',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'transcript' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'session_token' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_ROOT,
			'/clear',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'clear' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'session_token' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_ROOT,
			'/send',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send_fallback' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'session_token' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'message'       => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'request_id'    => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	public function mint_session( WP_REST_Request $request ): WP_REST_Response {
		// $request is unused but required by the REST callback signature.
		unset( $request );
		$token = Transcript_Store::new_session_token();
		return new WP_REST_Response(
			array(
				'session_token' => $token,
				'nonce'         => wp_create_nonce( Chat_Stream_Endpoint::NONCE_ACTION ),
				'stream_url'    => home_url( '/personalized-reader/chat-stream' ),
				'send_url'      => rest_url( self::NAMESPACE_ROOT . '/send' ),
			),
			200
		);
	}

	public function transcript( WP_REST_Request $request ): WP_REST_Response {
		$session  = (string) $request->get_param( 'session_token' );
		$store    = new Transcript_Store();
		$messages = $store->load( $session );

		// Hide the system + tool rows from the UI — only user/assistant turns
		// are reader-facing.
		$visible = array_values(
			array_filter(
				$messages,
				static fn( $m ) => in_array( $m['role'] ?? '', array( 'user', 'assistant' ), true )
			)
		);

		return new WP_REST_Response( array( 'messages' => $visible ), 200 );
	}

	public function clear( WP_REST_Request $request ): WP_REST_Response {
		$session = (string) $request->get_param( 'session_token' );
		( new Transcript_Store() )->clear( $session );
		return new WP_REST_Response( array( 'cleared' => true ), 200 );
	}

	public function send_fallback( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$session    = (string) ( $request->get_param( 'session_token' ) ?? '' );
		$message    = (string) $request->get_param( 'message' );
		$request_id = (string) ( $request->get_param( 'request_id' ) ?? '' );

		if ( '' === trim( $message ) ) {
			return new WP_Error( 'empty_message', 'Empty message', array( 'status' => 400 ) );
		}

		if ( '' === $session ) {
			$session = Transcript_Store::new_session_token();
		}

		$identity = 'session:' . $session;
		$limiter  = new Rate_Limiter( 'chat_send', (int) Settings::get( 'rate_limit_per_minute' ), MINUTE_IN_SECONDS );
		if ( ! $limiter->allow( $identity ) ) {
			return new WP_Error(
				'personalized_reader_rate_limited',
				__( 'Too many requests. Please wait before sending another message.', 'personalized-reader' ),
				array(
					'status'      => 429,
					'retry_after' => $limiter->retry_after( $identity ),
				)
			);
		}

		$sink   = new Buffering_Event_Sink();
		$runner = new Conversation_Runner(
			new Context_Composer(),
			new Transcript_Store(),
			$sink,
		);

		// Long-running REST endpoint — extend timeout best-effort. No-op
		// on hosts that disable it; suppress so a warning can't leak into
		// the JSON response.
		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- intentional; see above.
		$runner->run( $session, $message, $request_id );

		return new WP_REST_Response(
			array(
				'session_token' => $session,
				'events'        => $sink->events(),
				'done'          => $sink->is_done(),
			),
			200
		);
	}
}
