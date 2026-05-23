<?php
/**
 * WP-CLI commands for exercising the reader agent end-to-end without
 * touching the HTTP layer.
 *
 * Useful when iterating on the system prompt, ability schemas, or tool
 * results — turnaround is much faster than minting nonces and POSTing
 * SSE bodies in curl.
 *
 *   wp personalized-reader chat "What have you written about climate?"
 *   wp personalized-reader chat "Tell me more about that" --session=<token>
 *   wp personalized-reader transcript --session=<token>
 *   wp personalized-reader clear --session=<token>
 *
 * @package PersonalizedReader
 */

declare( strict_types=1 );

namespace PersonalizedReader\CLI;

use PersonalizedReader\Chat\Transcript_Store;
use PersonalizedReader\Conversation\Context_Composer;
use PersonalizedReader\Conversation\Conversation_Runner;

defined( 'ABSPATH' ) || exit;

final class CLI_Command {

	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		\WP_CLI::add_command( 'personalized-reader', self::class );
	}

	/**
	 * Send a single message to the reader agent and print every event the
	 * runner emits, in order, to stdout. Mints a fresh session token when
	 * --session is omitted.
	 *
	 * ## OPTIONS
	 *
	 * <message>
	 * : The user message to send.
	 *
	 * [--session=<token>]
	 * : Existing session token. If omitted, a fresh one is minted and
	 * printed before the run starts.
	 *
	 * [--quiet]
	 * : Suppress intermediate events; print only the final assistant text.
	 *
	 * ## EXAMPLES
	 *
	 *     wp personalized-reader chat "What have you written about housing?"
	 *     wp personalized-reader chat "More on that" --session=abc-123
	 *     wp personalized-reader chat "Recommend something" --quiet
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $assoc
	 */
	public function chat( array $args, array $assoc ): void {
		$message = (string) ( $args[0] ?? '' );
		if ( '' === trim( $message ) ) {
			\WP_CLI::error( 'Empty message.' );
		}

		$session = isset( $assoc['session'] ) ? (string) $assoc['session'] : '';
		if ( '' === $session ) {
			$session = Transcript_Store::new_session_token();
			\WP_CLI::log( 'Minted session: ' . $session );
		}

		$quiet = ! empty( $assoc['quiet'] );
		$sink  = new CLI_Event_Sink( $quiet );

		$runner = new Conversation_Runner(
			new Context_Composer(),
			new Transcript_Store(),
			$sink,
		);

		$runner->run( $session, $message );

		if ( $quiet ) {
			\WP_CLI::log( trim( $sink->assistant_text() ) );
		}

		if ( $sink->has_error() ) {
			\WP_CLI::error( $sink->last_error(), false );
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Print the stored transcript for a session.
	 *
	 * ## OPTIONS
	 *
	 * --session=<token>
	 * : Session token whose transcript to dump.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $assoc
	 */
	public function transcript( array $args, array $assoc ): void {
		$session = (string) ( $assoc['session'] ?? '' );
		if ( '' === $session ) {
			\WP_CLI::error( '--session is required.' );
		}

		$messages = ( new Transcript_Store() )->load( $session );
		if ( empty( $messages ) ) {
			\WP_CLI::log( '(empty transcript)' );
			return;
		}

		$rows = array_map(
			static function ( array $m ): array {
				return array(
					'ts'      => gmdate( 'c', (int) ( $m['ts'] ?? 0 ) ),
					'role'    => (string) ( $m['role'] ?? '' ),
					'tool'    => (string) ( $m['meta']['tool_name'] ?? '' ),
					'content' => mb_substr( (string) ( $m['content'] ?? '' ), 0, 200 ),
				);
			},
			$messages
		);

		\WP_CLI\Utils\format_items(
			(string) ( $assoc['format'] ?? 'table' ),
			$rows,
			array( 'ts', 'role', 'tool', 'content' )
		);
	}

	/**
	 * Clear the stored transcript for a session.
	 *
	 * ## OPTIONS
	 *
	 * --session=<token>
	 * : Session token whose transcript to clear.
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args
	 * @param array<string, mixed> $assoc
	 */
	public function clear( array $args, array $assoc ): void {
		$session = (string) ( $assoc['session'] ?? '' );
		if ( '' === $session ) {
			\WP_CLI::error( '--session is required.' );
		}
		( new Transcript_Store() )->clear( $session );
		\WP_CLI::success( 'Cleared transcript for session ' . $session );
	}
}
