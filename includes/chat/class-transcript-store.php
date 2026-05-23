<?php
/**
 * Session-keyed transcript store for anonymous reader conversations.
 *
 * Stored as a transient under a key derived from the opaque session
 * token supplied by the chat endpoint. 24-hour TTL — long enough to
 * span a reading session, short enough to bound storage on a busy
 * front-end cache.
 *
 * Shape per entry:
 *   array{ role:string, content:string, ts:int, meta:array }
 *
 * Migrate to WP_Agent_Conversation_Store when the substrate ships its
 * anonymous-session variant.
 *
 * @package PersonalizedReader
 */

declare( strict_types=1 );

namespace PersonalizedReader\Chat;

defined( 'ABSPATH' ) || exit;

final class Transcript_Store {

	private const TTL_SECONDS = DAY_IN_SECONDS;

	private const KEY_PREFIX = 'pr_transcript_';

	/**
	 * Generate a fresh opaque session token.
	 */
	public static function new_session_token(): string {
		return wp_generate_uuid4();
	}

	/**
	 * @return array<int, array{role:string, content:string, ts:int, meta:array}>
	 */
	public function load( string $session_token ): array {
		$key = $this->key( $session_token );
		if ( '' === $key ) {
			return array();
		}
		$raw = get_transient( $key );
		return is_array( $raw ) ? $raw : array();
	}

	public function append( string $session_token, string $role, string $content, array $meta = array() ): void {
		$key = $this->key( $session_token );
		if ( '' === $key ) {
			return;
		}
		$messages   = $this->load( $session_token );
		$messages[] = array(
			'role'    => $role,
			'content' => $content,
			'ts'      => time(),
			'meta'    => $meta,
		);
		set_transient( $key, $messages, self::TTL_SECONDS );
	}

	/**
	 * Batch append: write a list of entries in one set_transient() call.
	 *
	 * @param array<int, array{role:string, content:string, ts:int, meta:array}> $entries
	 */
	public function batch_append( string $session_token, array $entries ): void {
		if ( empty( $entries ) ) {
			return;
		}
		$key = $this->key( $session_token );
		if ( '' === $key ) {
			return;
		}
		$messages = $this->load( $session_token );
		foreach ( $entries as $entry ) {
			$messages[] = $entry;
		}
		set_transient( $key, $messages, self::TTL_SECONDS );
	}

	public function clear( string $session_token ): void {
		$key = $this->key( $session_token );
		if ( '' === $key ) {
			return;
		}
		delete_transient( $key );
	}

	private function key( string $session_token ): string {
		$token = trim( $session_token );
		if ( '' === $token || strlen( $token ) > 128 ) {
			return '';
		}
		// Hash to keep the transient key short and within length limits.
		return self::KEY_PREFIX . substr( md5( $token ), 0, 24 );
	}
}
