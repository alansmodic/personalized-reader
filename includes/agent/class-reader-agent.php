<?php
/**
 * Registers the personalized-reader agent.
 *
 * All four abilities are read-only, so action_policy is `auto` across
 * the board — no preview/approval flow is needed for the MVP. When a
 * future ability mutates state (e.g. starting a checkout), bump it to
 * `preview`.
 *
 * @package PersonalizedReader
 */

declare( strict_types=1 );

namespace PersonalizedReader\Agent;

use PersonalizedReader\Abilities\Abilities;

defined( 'ABSPATH' ) || exit;

final class Reader_Agent {

	public const AGENT_ID = 'personalized-reader';

	public function register(): void {
		add_action( 'wp_agents_api_init', array( $this, 'register_agent' ) );
	}

	public function register_agent(): void {
		if ( ! function_exists( 'wp_register_agent' ) ) {
			return;
		}

		wp_register_agent(
			self::AGENT_ID,
			array(
				'label'         => __( 'Personalized Reader', 'personalized-reader' ),
				'description'   => __( 'Conversational guide to the publication archive for anonymous visitors.', 'personalized-reader' ),
				'modes'         => array( 'chat' ),
				'abilities'     => array(
					'personalized-reader/search-archive',
					'personalized-reader/get-article',
					'personalized-reader/check-subscription',
					'personalized-reader/recommend',
				),
				'action_policy' => array(
					'tools' => array(
						'personalized-reader/search-archive' => 'auto',
						'personalized-reader/get-article' => 'auto',
						'personalized-reader/check-subscription' => 'auto',
						'personalized-reader/recommend'   => 'auto',
					),
				),
				'meta'          => array(
					'source_plugin'  => plugin_basename( \PersonalizedReader\PLUGIN_FILE ),
					'source_type'    => 'bundled-agent',
					'source_package' => 'personalized-reader',
					'source_version' => \PersonalizedReader\VERSION,
				),
			)
		);
	}
}
