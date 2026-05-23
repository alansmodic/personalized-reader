<?php
/**
 * Custom POST endpoint at /personalized-reader/chat-stream
 *
 * Server-Sent Events for the live chat stream. Not REST — REST flushes
 * once at the end and runs late filters that re-buffer, which breaks
 * SSE on most hosts.
 *
 * Auth model for anonymous readers:
 *   - Nonce verifies the request originated from a page that loaded
 *     the widget script (which receives the nonce via wp_localize_script).
 *   - Rate limit keyed by client IP.
 *   - No `current_user_can()` check — public surface.
 *
 * Mirrors editorial-assistant's ChatStreamEndpoint structure.
 *
 * @package PersonalizedReader
 */

declare( strict_types=1 );

namespace PersonalizedReader\Streaming;

use PersonalizedReader\Chat\Transcript_Store;
use PersonalizedReader\Conversation\Context_Composer;
use PersonalizedReader\Conversation\Streaming_Runner;
use PersonalizedReader\Settings\Settings;
use PersonalizedReader\Utils\Rate_Limiter;

defined( 'ABSPATH' ) || exit;

final class Chat_Stream_Endpoint {

	private const QUERY_VAR = 'personalized_reader_chat_stream';

	public const NONCE_ACTION = 'personalized_reader_chat';

	public static function register(): void {
		add_action( 'init', array( self::class, 'add_rewrite' ) );
		add_action( 'parse_request', array( self::class, 'maybe_handle' ) );
	}

	public static function add_rewrite(): void {
		add_rewrite_rule(
			'^personalized-reader/chat-stream/?$',
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);
		add_rewrite_tag( '%' . self::QUERY_VAR . '%', '1' );
	}

	public static function maybe_handle( \WP $wp ): void {
		if ( empty( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			status_header( 405 );
			exit;
		}

		$raw  = file_get_contents( 'php://input' );
		$body = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $body ) ) {
			status_header( 400 );
			echo 'Invalid JSON body';
			exit;
		}

		$nonce = (string) ( $body['_wpnonce'] ?? '' );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			status_header( 403 );
			echo 'Bad nonce';
			exit;
		}

		$session_token = sanitize_text_field( (string) ( $body['session_token'] ?? '' ) );
		$message       = sanitize_textarea_field( (string) ( $body['message'] ?? '' ) );
		$request_id    = sanitize_text_field( (string) ( $body['request_id'] ?? '' ) );

		if ( '' === $session_token ) {
			$session_token = Transcript_Store::new_session_token();
		}
		if ( '' === trim( $message ) ) {
			status_header( 400 );
			echo 'Missing message';
			exit;
		}

		$identity = self::client_identity( $session_token );
		$limiter  = new Rate_Limiter( 'chat_stream', (int) Settings::get( 'rate_limit_per_minute' ), MINUTE_IN_SECONDS );
		if ( ! $limiter->allow( $identity ) ) {
			status_header( 429 );
			header( 'Retry-After: ' . $limiter->retry_after( $identity ) );
			echo 'Too many requests.';
			exit;
		}

		// SSE headers — must be set before any output.
		nocache_headers();
		header( 'Content-Type: text/event-stream' );
		header( 'X-Accel-Buffering: no' ); // nginx
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );

		// Surface the session token to the client even when it was just minted.
		header( 'X-Personalized-Reader-Session: ' . $session_token );

		@set_time_limit( 120 );
		ignore_user_abort( false );

		$runner = new Streaming_Runner(
			new Context_Composer(),
			new Transcript_Store(),
			new Event_Emitter(),
		);

		$runner->run( $session_token, $message, $request_id );

		exit;
	}

	/**
	 * Compose a rate-limit identity. Prefer session_token (one chat = one
	 * identity); fall back to IP if the session is brand new and the
	 * client somehow didn't echo it back.
	 */
	private static function client_identity( string $session_token ): string {
		if ( '' !== $session_token ) {
			return 'session:' . $session_token;
		}
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		return 'ip:' . $ip;
	}
}
