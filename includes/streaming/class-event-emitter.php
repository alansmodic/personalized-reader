<?php
/**
 * Server-Sent Events emitter. Writes one `event:` + `data:` frame per
 * emit() call and flushes the output buffer so the client receives
 * chunks as they're produced.
 *
 * The caller is responsible for setting headers BEFORE constructing
 * this object (see Streaming\Chat_Stream_Endpoint when it lands).
 *
 * @package PersonalizedReader
 */

declare( strict_types=1 );

namespace PersonalizedReader\Streaming;

defined( 'ABSPATH' ) || exit;

final class Event_Emitter implements Event_Sink {

	public function start(): void {
		// SSE preamble: comment frame keeps proxies from buffering.
		echo ": personalized-reader stream open\n\n";
		$this->flush();
	}

	public function emit( string $event, array $data ): void {
		// Event names are always internal constants we control (turn_started,
		// assistant_chunk, tool_call, tool_result, done, error). Sanitize to a
		// token-safe charset belt-and-braces.
		$event   = preg_replace( '/[^a-zA-Z0-9_]/', '', $event );
		$payload = wp_json_encode( $data );
		if ( false === $payload ) {
			$payload = '{}';
		}
		// SSE frames are text/event-stream, not HTML, so HTML escaping would
		// corrupt the payload. The event name is already a sanitized token
		// and the payload is JSON-encoded by wp_json_encode().
		echo 'event: ' . $event . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SSE wire format; event name sanitized above.
		echo 'data: ' . $payload . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SSE wire format; payload is wp_json_encode output.
		$this->flush();
	}

	public function done(): void {
		$this->emit( 'done', array() );
	}

	public function error( string $message, array $context = array() ): void {
		$this->emit( 'error', array_merge( array( 'message' => $message ), $context ) );
	}

	private function flush(): void {
		// SSE flushing is best-effort: ob_flush/flush emit warnings when no
		// output buffer is active (perfectly normal for our usage), and we
		// genuinely don't want those warnings to corrupt the event stream.
		// Suppress is the right tool here, not error handling.
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			@ob_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- intentional; see above.
			@flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- intentional; see above.
			return;
		}
		@ob_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- intentional; see above.
		@flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- intentional; see above.
	}
}
