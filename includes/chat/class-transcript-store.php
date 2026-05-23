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

use AgentsAPI\AI\WP_Agent_Conversation_Request;
use AgentsAPI\AI\WP_Agent_Transcript_Persister;

defined( 'ABSPATH' ) || exit;

final class Transcript_Store implements WP_Agent_Transcript_Persister {

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

	/**
	 * WP_Agent_Transcript_Persister implementation.
	 *
	 * Called by WP_Agent_Conversation_Loop at the end of a run to flush the
	 * final transcript. We replace whatever was stored for the session with
	 * the substrate's normalized envelope list and return the session id as
	 * the transcript id.
	 *
	 * The substrate passes session id via context; we look it up in the
	 * request's `context` field, falling back to a metadata key.
	 *
	 * @param array<int, array<string, mixed>> $messages
	 * @param WP_Agent_Conversation_Request    $request
	 * @param array<string, mixed>             $result
	 */
	public function persist( array $messages, WP_Agent_Conversation_Request $request, array $result ): string {
		$session_token = '';
		if ( method_exists( $request, 'runtimeContext' ) ) {
			$ctx           = $request->runtimeContext();
			$session_token = (string) ( $ctx['session_token'] ?? '' );
		}
		if ( '' === $session_token ) {
			return '';
		}

		$key = $this->key( $session_token );
		if ( '' === $key ) {
			return '';
		}

		// Tag each entry with a timestamp so transcript dumps still sort
		// chronologically. Envelope arrays already carry role/content, which
		// is all our load() consumers need.
		$tagged = array();
		$now    = time();
		foreach ( $messages as $envelope ) {
			$entry = is_array( $envelope ) ? $envelope : array();
			if ( ! isset( $entry['ts'] ) ) {
				$entry['ts'] = $now;
			}
			$tagged[] = $entry;
		}

		set_transient( $key, $tagged, self::TTL_SECONDS );
		return $session_token;
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
