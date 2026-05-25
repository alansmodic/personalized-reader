<?php
/**
 * Plugin settings: a single stored option backing the admin form.
 *
 * The keys here mirror what was previously filter-only configuration —
 * system prompt, free-article threshold, max tool rounds, rate limits,
 * authority-tier classification. Code-level filters still fire AFTER
 * the option is read so deployments can override stored values without
 * editing the admin UI (useful for CI / multi-site / VIP).
 *
 *   Filter > Option > Default
 *
 * Everything is exposed through Settings::get( $key ) so callers don't
 * need to remember the option name or the default.
 *
 * @package PersonalizedReader
 */

declare( strict_types=1 );

namespace PersonalizedReader\Settings;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION_NAME = 'personalized_reader_settings';

	public const OPTION_GROUP = 'personalized_reader_settings_group';

	/** @var array<string, mixed> */
	public const DEFAULTS = array(
		'system_prompt'               => '',          // empty → use built-in default
		'default_mode'                => 'inline',    // Allowed values: inline or floating.
		'placeholder'                 => '',          // empty → use translated default
		'widget_title'                => '',          // floating-mode header text
		'free_articles'               => 3,
		'max_tool_rounds'             => 4,
		'rate_limit_per_minute'       => 10,
		'authority_opinion_cat'       => 'opinion',   // category slug → opinion tier
		'authority_wire_tags'         => 'wire,ap',   // comma-separated tag slugs → wire tier
		'wpvdb_integration'           => true,        // auto-route search through WPVDB when present
		// Price per million tokens (USD). Defaults track Anthropic's
		// Claude Sonnet 4.5 ($3 input / $15 output). Edit these when
		// you switch models — Opus and Haiku cost very differently.
		'cost_prompt_per_million'     => 3.0,
		'cost_completion_per_million' => 15.0,
	);

	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'register_setting' ) );
	}

	public static function register_setting(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::DEFAULTS,
			)
		);
	}

	/**
	 * Get a single setting value. Falls back to the default if missing.
	 */
	public static function get( string $key ): mixed {
		$all = self::all();
		return $all[ $key ] ?? ( self::DEFAULTS[ $key ] ?? null );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		// Merge over defaults so newly-added keys appear even when the
		// stored option predates them.
		return array_merge( self::DEFAULTS, $stored );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		if ( ! is_array( $input ) ) {
			return self::DEFAULTS;
		}

		$out = self::all();

		if ( array_key_exists( 'system_prompt', $input ) ) {
			$out['system_prompt'] = sanitize_textarea_field( (string) $input['system_prompt'] );
		}

		if ( array_key_exists( 'default_mode', $input ) ) {
			$mode                = (string) $input['default_mode'];
			$out['default_mode'] = in_array( $mode, array( 'inline', 'floating' ), true ) ? $mode : 'inline';
		}

		if ( array_key_exists( 'placeholder', $input ) ) {
			$out['placeholder'] = sanitize_text_field( (string) $input['placeholder'] );
		}

		if ( array_key_exists( 'widget_title', $input ) ) {
			$out['widget_title'] = sanitize_text_field( (string) $input['widget_title'] );
		}

		if ( array_key_exists( 'free_articles', $input ) ) {
			$out['free_articles'] = max( 0, min( 100, (int) $input['free_articles'] ) );
		}

		if ( array_key_exists( 'max_tool_rounds', $input ) ) {
			$out['max_tool_rounds'] = max( 1, min( 8, (int) $input['max_tool_rounds'] ) );
		}

		if ( array_key_exists( 'rate_limit_per_minute', $input ) ) {
			$out['rate_limit_per_minute'] = max( 1, min( 600, (int) $input['rate_limit_per_minute'] ) );
		}

		if ( array_key_exists( 'authority_opinion_cat', $input ) ) {
			$out['authority_opinion_cat'] = sanitize_title( (string) $input['authority_opinion_cat'] );
		}

		if ( array_key_exists( 'authority_wire_tags', $input ) ) {
			$tags                       = array_map( 'sanitize_title', array_filter( array_map( 'trim', explode( ',', (string) $input['authority_wire_tags'] ) ) ) );
			$out['authority_wire_tags'] = implode( ',', $tags );
		}

		if ( array_key_exists( 'wpvdb_integration', $input ) ) {
			$out['wpvdb_integration'] = (bool) $input['wpvdb_integration'];
		} else {
			// Unchecked checkboxes don't submit a key — explicit false when
			// the form was submitted but the toggle is off.
			$out['wpvdb_integration'] = false;
		}

		if ( array_key_exists( 'cost_prompt_per_million', $input ) ) {
			$out['cost_prompt_per_million'] = max( 0.0, (float) $input['cost_prompt_per_million'] );
		}

		if ( array_key_exists( 'cost_completion_per_million', $input ) ) {
			$out['cost_completion_per_million'] = max( 0.0, (float) $input['cost_completion_per_million'] );
		}

		return $out;
	}
}
