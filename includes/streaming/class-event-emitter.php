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
		$payload = wp_json_encode( $data );
		if ( false === $payload ) {
			$payload = '{}';
		}
		echo 'event: ' . $event . "\n";
		echo 'data: ' . $payload . "\n\n";
		$this->flush();
	}

	public function done(): void {
		$this->emit( 'done', array() );
	}

	public function error( string $message, array $context = array() ): void {
		$this->emit( 'error', array_merge( array( 'message' => $message ), $context ) );
	}

	private function flush(): void {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			// Avoid calling here — that would close the connection. Just flush.
			@ob_flush();
			@flush();
			return;
		}
		@ob_flush();
		@flush();
	}
}
