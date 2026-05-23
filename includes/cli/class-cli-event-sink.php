<?php
/**
 * Event sink for the WP-CLI smoke command.
 *
 * Streams runner events directly to stdout. Mirrors the wire shape used
 * by the SSE emitter and the buffering sink so the CLI run is a faithful
 * smoke test of the same code path the browser hits.
 *
 * @package PersonalizedReader
 */

declare( strict_types=1 );

namespace PersonalizedReader\CLI;

use PersonalizedReader\Streaming\Event_Sink;

defined( 'ABSPATH' ) || exit;

final class CLI_Event_Sink implements Event_Sink {

	private bool $error  = false;

	private string $last_error = '';

	private string $assistant_text = '';

	public function __construct( private readonly bool $quiet ) {
	}

	public function start(): void {
		if ( ! $this->quiet ) {
			\WP_CLI::log( '— stream open —' );
		}
	}

	public function emit( string $event, array $data ): void {
		if ( 'assistant_chunk' === $event ) {
			$this->assistant_text .= (string) ( $data['text'] ?? '' );
		}
		if ( $this->quiet ) {
			return;
		}

		switch ( $event ) {
			case 'turn_started':
				\WP_CLI::log( sprintf( '[turn %d]', (int) ( $data['round'] ?? 0 ) ) );
				break;

			case 'user_message_persisted':
				// Useful in the browser, noisy on CLI — skip.
				break;

			case 'assistant_chunk':
				// Write without a trailing newline so streamed chunks concatenate.
				fwrite( STDOUT, (string) ( $data['text'] ?? '' ) );
				break;

			case 'tool_call':
				\WP_CLI::log( sprintf(
					"\n→ tool_call %s %s",
					(string) ( $data['name'] ?? '' ),
					(string) wp_json_encode( $data['arguments'] ?? array() )
				) );
				break;

			case 'tool_result':
				$result  = $data['result'] ?? array();
				$summary = is_array( $result ) ? $this->summarize_tool_result( $result ) : (string) $result;
				\WP_CLI::log( '← tool_result ' . $summary );
				break;

			default:
				\WP_CLI::log( '[' . $event . '] ' . (string) wp_json_encode( $data ) );
		}
	}

	public function done(): void {
		if ( ! $this->quiet ) {
			fwrite( STDOUT, "\n— done —\n" );
		}
	}

	public function error( string $message, array $context = array() ): void {
		$this->error      = true;
		$this->last_error = $message;
		if ( ! $this->quiet ) {
			\WP_CLI::log( '[error] ' . $message . ' ' . (string) wp_json_encode( $context ) );
		}
	}

	public function has_error(): bool {
		return $this->error;
	}

	public function last_error(): string {
		return $this->last_error;
	}

	public function assistant_text(): string {
		return $this->assistant_text;
	}

	private function summarize_tool_result( array $result ): string {
		// $result is the raw ability return payload (whatever the
		// execute_callback produced). Shape varies per ability.
		if ( isset( $result['results'] ) && is_array( $result['results'] ) ) {
			return count( $result['results'] ) . ' results';
		}
		if ( isset( $result['recommendations'] ) && is_array( $result['recommendations'] ) ) {
			return count( $result['recommendations'] ) . ' recommendations';
		}
		if ( isset( $result['title'] ) ) {
			return '"' . mb_substr( (string) $result['title'], 0, 60 ) . '"';
		}
		if ( isset( $result['is_subscriber'] ) ) {
			return 'subscription status: ' . ( $result['is_subscriber'] ? 'subscriber' : ( ( (int) $result['free_remaining'] ) . ' free remaining' ) );
		}
		if ( isset( $result['error'] ) ) {
			return 'error: ' . (string) $result['error'];
		}
		return mb_substr( (string) wp_json_encode( $result ), 0, 120 );
	}
}
