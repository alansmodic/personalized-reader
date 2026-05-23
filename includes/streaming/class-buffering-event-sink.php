<?php
/**
 * In-memory event sink for the non-streaming fallback endpoint.
 *
 * Same runner code path as the SSE emitter — events are collected here
 * and returned as a single JSON payload at the end of the turn.
 *
 * @package PersonalizedReader
 */

declare( strict_types=1 );

namespace PersonalizedReader\Streaming;

defined( 'ABSPATH' ) || exit;

final class Buffering_Event_Sink implements Event_Sink {

	/** @var array<int, array{event:string, data:array}> */
	private array $events = array();

	private bool $done = false;

	public function start(): void {
		// no-op
	}

	public function emit( string $event, array $data ): void {
		$this->events[] = array( 'event' => $event, 'data' => $data );
	}

	public function done(): void {
		$this->done     = true;
		$this->events[] = array( 'event' => 'done', 'data' => array() );
	}

	public function error( string $message, array $context = array() ): void {
		$this->events[] = array(
			'event' => 'error',
			'data'  => array_merge( array( 'message' => $message ), $context ),
		);
	}

	/** @return array<int, array{event:string, data:array}> */
	public function events(): array {
		return $this->events;
	}

	public function is_done(): bool {
		return $this->done;
	}
}
