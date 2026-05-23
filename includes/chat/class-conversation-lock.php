<?php
/**
 * Single-writer transcript lock for the agents-api substrate loop.
 *
 * Two concurrent requests for the same session would otherwise race on
 * persist() and one's changes would clobber the other's. The substrate
 * supports an optional WP_Agent_Conversation_Lock; we wire one here.
 *
 * Storage: `wp_options` via add_option(), which under MySQL is
 * INSERT … IGNORE — atomic enough to act as a try-acquire primitive.
 * The option value is a JSON blob holding `{token, expires_at}`. On
 * acquire we first delete any option whose expires_at has passed, then
 * attempt the add; on release we verify the stored token matches.
 *
 * Why options, not transients: transients fall back to options on hosts
 * without an object cache, and we need the atomic-insert behavior either
 * way. Using options directly keeps the semantics consistent across
 * environments. We mark autoload=no so the lock rows don't bloat every
 * page load.
 *
 * @package PersonalizedReader
 */

declare( strict_types=1 );

namespace PersonalizedReader\Chat;

use AgentsAPI\Core\Database\Chat\WP_Agent_Conversation_Lock;

defined( 'ABSPATH' ) || exit;

final class Conversation_Lock implements WP_Agent_Conversation_Lock {

	private const PREFIX = 'pr_lock_';

	public function acquire_session_lock( string $session_id, int $ttl_seconds = 300 ): ?string {
		$session_id = trim( $session_id );
		if ( '' === $session_id ) {
			return null;
		}

		$key = $this->key( $session_id );

		// First clear any expired holder so a crashed/timed-out request
		// can't lock the session forever.
		$existing = get_option( $key, null );
		if ( is_array( $existing ) && (int) ( $existing['expires_at'] ?? 0 ) <= time() ) {
			delete_option( $key );
		}

		$token   = wp_generate_uuid4();
		$payload = array(
			'token'      => $token,
			'expires_at' => time() + max( 1, $ttl_seconds ),
		);

		// add_option returns false when the option already exists — that's
		// our "lock contended" signal. Set autoload=no so these rows don't
		// pile up in the autoloaded options blob.
		$inserted = add_option( $key, $payload, '', 'no' );
		if ( ! $inserted ) {
			return null;
		}

		return $token;
	}

	public function release_session_lock( string $session_id, string $lock_token ): bool {
		$session_id = trim( $session_id );
		if ( '' === $session_id || '' === $lock_token ) {
			return false;
		}

		$key      = $this->key( $session_id );
		$existing = get_option( $key, null );
		if ( ! is_array( $existing ) ) {
			return false;
		}

		// Token mismatch: a different runner reacquired the lock after our
		// TTL expired. Do NOT delete — that would release someone else's
		// lock.
		if ( ! hash_equals( (string) ( $existing['token'] ?? '' ), $lock_token ) ) {
			return false;
		}

		return (bool) delete_option( $key );
	}

	private function key( string $session_id ): string {
		return self::PREFIX . substr( md5( $session_id ), 0, 24 );
	}
}
