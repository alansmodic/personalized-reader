<?php
/**
 * Runtime dependency check for the Agents API + AI client.
 *
 * @package PersonalizedReader
 */

declare( strict_types=1 );

namespace PersonalizedReader\Compat;

defined( 'ABSPATH' ) || exit;

final class Dependencies {

	public static function satisfied(): bool {
		return self::agents_api_available()
			&& self::abilities_api_available()
			&& self::ai_client_available();
	}

	public static function agents_api_available(): bool {
		return function_exists( 'wp_register_agent' );
	}

	public static function abilities_api_available(): bool {
		return function_exists( 'wp_register_ability' );
	}

	public static function ai_client_available(): bool {
		return function_exists( 'wp_ai_client_prompt' );
	}

	public static function render_admin_notice(): void {
		add_action(
			'admin_notices',
			static function (): void {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}

				$missing = array();
				if ( ! self::agents_api_available() ) {
					$missing[] = 'Agents API';
				}
				if ( ! self::abilities_api_available() ) {
					$missing[] = 'Abilities API';
				}
				if ( ! self::ai_client_available() ) {
					$missing[] = 'wp_ai_client_prompt (WordPress 7.0+)';
				}

				printf(
					'<div class="notice notice-error"><p><strong>%s</strong> %s %s</p></div>',
					esc_html__( 'Personalized Reader:', 'personalized-reader' ),
					esc_html__( 'missing required dependencies:', 'personalized-reader' ),
					esc_html( implode( ', ', $missing ) )
				);
			}
		);
	}
}
