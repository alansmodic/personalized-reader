<?php
/**
 * Registers the personalized-reader/reader-chat dynamic block.
 *
 * The block is a thin wrapper around the same markup the shortcode emits:
 * the front-end JS handles rendering, so all PHP has to do is print the
 * empty `.pr-widget` mount point with the right data attributes. That
 * keeps shortcode and block paths in lockstep — no risk of one growing
 * a feature the other doesn't.
 *
 * @package PersonalizedReader
 */

declare( strict_types=1 );

namespace PersonalizedReader\Frontend;

use PersonalizedReader\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class Block {

	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	public function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		register_block_type(
			\PersonalizedReader\PLUGIN_DIR . '/blocks/reader-chat',
			array(
				'render_callback' => array( $this, 'render' ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public function render( array $attributes ): string {
		// Block render runs on the front-end page that contains it, so
		// flip the enqueue flag — the Widget class' filter-driven check
		// picks this up.
		add_filter( 'personalized_reader_enqueue_assets', '__return_true' );

		// In case the block renders after wp_enqueue_scripts has already
		// fired (REST preview / late template rendering), force an enqueue
		// pass right now via the shared Widget instance.
		( new Widget() )->force_enqueue();

		$mode = (string) ( $attributes['mode'] ?? 'default' );
		if ( 'default' === $mode ) {
			$mode = (string) Settings::get( 'default_mode' );
		}

		$inner = Widget::render_markup(
			$mode,
			(string) ( $attributes['placeholder'] ?? '' )
		);

		// Wrap with block attributes so alignment (alignwide/alignfull),
		// custom className, and anchor flow through to the frontend.
		$wrapper_attrs = function_exists( 'get_block_wrapper_attributes' )
			? get_block_wrapper_attributes()
			: '';

		return '<div ' . $wrapper_attrs . '>' . $inner . '</div>';
	}
}
