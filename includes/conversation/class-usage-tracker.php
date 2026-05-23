<?php
/**
 * Cumulative token-usage accounting.
 *
 * Reads per-turn TokenUsage out of GenerativeAiResult (per turn, inside the
 * turn_runner) and lets the substrate sum it into the loop's final result.
 * After each Loop::run() we accumulate that final usage into a single
 * persistent option keyed by month, so the admin status panel can show
 * "this month" + "all-time" totals without scanning transcripts.
 *
 * Two storage rows:
 *   personalized_reader_usage_totals       — all-time { prompt, completion, total, sessions }
 *   personalized_reader_usage_month_YYYYMM — per-month rolling counter
 *
 * Both are non-autoloaded options — cheap to read on demand, no impact
 * on every-page bootstrap.
 *
 * @package PersonalizedReader
 */

declare( strict_types=1 );

namespace PersonalizedReader\Conversation;

defined( 'ABSPATH' ) || exit;

final class Usage_Tracker {

	public const OPTION_TOTALS      = 'personalized_reader_usage_totals';
	private const OPTION_MONTH_PREF = 'personalized_reader_usage_month_';

	/**
	 * Record a single Loop::run() result. Safe to call with a partial or
	 * empty result — missing usage just no-ops.
	 *
	 * @param array<string, mixed> $loop_result Output of WP_Agent_Conversation_Loop::run().
	 */
	public function record( array $loop_result ): void {
		$usage = $loop_result['usage'] ?? null;
		if ( ! is_array( $usage ) ) {
			return;
		}

		$prompt     = (int) ( $usage['prompt_tokens'] ?? 0 );
		$completion = (int) ( $usage['completion_tokens'] ?? 0 );
		$total      = (int) ( $usage['total_tokens'] ?? ( $prompt + $completion ) );
		if ( 0 === $total ) {
			return;
		}

		$this->bump(
			self::OPTION_TOTALS,
			array(
				'prompt_tokens'     => $prompt,
				'completion_tokens' => $completion,
				'total_tokens'      => $total,
				'sessions'          => 1,
			)
		);

		$this->bump(
			self::OPTION_MONTH_PREF . gmdate( 'Ym' ),
			array(
				'prompt_tokens'     => $prompt,
				'completion_tokens' => $completion,
				'total_tokens'      => $total,
				'sessions'          => 1,
			)
		);
	}

	/**
	 * @return array{prompt_tokens:int, completion_tokens:int, total_tokens:int, sessions:int}
	 */
	public static function totals(): array {
		return self::read( self::OPTION_TOTALS );
	}

	/**
	 * @return array{prompt_tokens:int, completion_tokens:int, total_tokens:int, sessions:int}
	 */
	public static function this_month(): array {
		return self::read( self::OPTION_MONTH_PREF . gmdate( 'Ym' ) );
	}

	private static function read( string $option ): array {
		$raw      = get_option( $option, array() );
		$defaults = array(
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
			'total_tokens'      => 0,
			'sessions'          => 0,
		);
		if ( ! is_array( $raw ) ) {
			return $defaults;
		}
		return array_merge( $defaults, $raw );
	}

	/**
	 * @param array{prompt_tokens:int, completion_tokens:int, total_tokens:int, sessions:int} $delta
	 */
	private function bump( string $option, array $delta ): void {
		$current = self::read( $option );
		$updated = array(
			'prompt_tokens'     => $current['prompt_tokens']     + $delta['prompt_tokens'],
			'completion_tokens' => $current['completion_tokens'] + $delta['completion_tokens'],
			'total_tokens'      => $current['total_tokens']      + $delta['total_tokens'],
			'sessions'          => $current['sessions']          + $delta['sessions'],
		);

		// Use add_option for the first write (sets autoload=no) and
		// update_option afterwards.
		if ( get_option( $option, null ) === null ) {
			add_option( $option, $updated, '', 'no' );
		} else {
			update_option( $option, $updated );
		}
	}
}
