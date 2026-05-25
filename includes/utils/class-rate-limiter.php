<?php
/**
 * Tiny transient-based rate limiter.
 *
 * Identity-agnostic — pass any string (user_id, IP, session token). For
 * anonymous reader traffic we key by client IP, accepting that this
 * undercounts behind shared NATs and overcounts on shared egress. Good
 * enough as a brake against accidental abuse; not a security boundary.
 *
 * @package PersonalizedReader
 */

declare( strict_types=1 );

namespace PersonalizedReader\Utils;

defined( 'ABSPATH' ) || exit;

final class Rate_Limiter {

	public function __construct(
		private readonly string $bucket,
		private readonly int $max,
		private readonly int $window_seconds,
	) {
	}

	public function allow( string $identity ): bool {
		if ( '' === $identity ) {
			// Refuse anonymous-identity calls so a misconfigured caller can't
			// bypass the limiter by passing an empty string.
			return false;
		}
		$key   = $this->key( $identity );
		$state = get_transient( $key );

		if ( ! is_array( $state ) ) {
			set_transient(
				$key,
				array(
					'count' => 1,
					'reset' => time() + $this->window_seconds,
				),
				$this->window_seconds
			);
			return true;
		}

		if ( $state['count'] >= $this->max ) {
			return false;
		}

		++$state['count'];
		// Preserve the original reset time — recompute TTL from it so the
		// window doesn't slide on each write.
		$ttl = max( 1, (int) $state['reset'] - time() );
		set_transient( $key, $state, $ttl );
		return true;
	}

	public function retry_after( string $identity ): int {
		$state = get_transient( $this->key( $identity ) );
		if ( ! is_array( $state ) ) {
			return 0;
		}
		return max( 0, (int) $state['reset'] - time() );
	}

	private function key( string $identity ): string {
		return 'pr_rl_' . $this->bucket . '_' . substr( md5( $identity ), 0, 20 );
	}
}
