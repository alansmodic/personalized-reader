<?php
/**
 * Minimal event sink contract for the conversation runner.
 *
 * Same shape as editorial-assistant's EventSink so the runner stays
 * transport-agnostic and we can swap in a substrate observer later
 * without touching the runner.
 *
 * @package PersonalizedReader
 */

declare( strict_types=1 );

namespace PersonalizedReader\Streaming;

defined( 'ABSPATH' ) || exit;

interface Event_Sink {

	public function start(): void;

	public function emit( string $event, array $data ): void;

	public function done(): void;

	public function error( string $message, array $context = array() ): void;
}
