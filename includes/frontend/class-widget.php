<?php
/**
 * Frontend widget: enqueues assets and exposes a `[personalized_reader]`
 * shortcode for embedding the chat UI.
 *
 * Assets only enqueue on pages that actually render the shortcode — we
 * scan post content during `wp_enqueue_scripts` and skip the load on
 * pages that don't need it. Saves a request on the vast majority of
 * public pageviews.
 *
 * The nonce printed here is reader-facing, so it has the limited lifetime
 * any nonce has. Cached pages will eventually serve a stale nonce; the
 * widget transparently re-mints via /session when that happens.
 *
 * @package PersonalizedReader
 */

declare( strict_types=1 );

namespace PersonalizedReader\Frontend;

use PersonalizedReader\Rest\Chat_Controller;
use PersonalizedReader\Settings\Settings;
use PersonalizedReader\Streaming\Chat_Stream_Endpoint;

defined( 'ABSPATH' ) || exit;

final class Widget {

	private const HANDLE_JS  = 'personalized-reader-widget';
	private const HANDLE_CSS = 'personalized-reader-widget';
	private const SHORTCODE  = 'personalized_reader';

	private bool $needs_assets = false;

	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	public function maybe_enqueue(): void {
		// Sniff the queried object for the shortcode so we don't load the
		// widget bundle on every page of the site.
		if ( is_singular() ) {
			$post = get_post();
			if ( $post && has_shortcode( (string) $post->post_content, self::SHORTCODE ) ) {
				$this->needs_assets = true;
			}
		}

		/**
		 * Filter: force-enqueue the widget assets even when the shortcode
		 * isn't found on the current post. Themes that render the widget
		 * via template tag or a custom location should return true here.
		 */
		$this->needs_assets = (bool) apply_filters( 'personalized_reader_enqueue_assets', $this->needs_assets );

		if ( ! $this->needs_assets ) {
			return;
		}

		$this->enqueue();
	}

	public function render_shortcode( $atts ): string {
		// Shortcode attributes fall back to admin-configured defaults
		// before the hardcoded ones.
		$atts = shortcode_atts(
			array(
				'placeholder' => (string) Settings::get( 'placeholder' ),
				'mode'        => (string) Settings::get( 'default_mode' ),
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		$this->needs_assets = true;
		// If the shortcode renders after wp_enqueue_scripts has already
		// fired (e.g. via do_shortcode in a template), enqueue right now.
		if ( did_action( 'wp_enqueue_scripts' ) ) {
			$this->enqueue();
		}

		return self::render_markup( (string) $atts['mode'], (string) $atts['placeholder'] );
	}

	/**
	 * Shared markup used by both the shortcode and the Gutenberg block.
	 */
	public static function render_markup( string $mode, string $placeholder ): string {
		$mode  = 'floating' === $mode ? 'floating' : 'inline';
		$attrs = ' data-mode="' . esc_attr( $mode ) . '"';
		if ( '' !== $placeholder ) {
			$attrs .= ' data-placeholder="' . esc_attr( $placeholder ) . '"';
		}
		return '<div class="pr-widget"' . $attrs . '></div>';
	}

	/**
	 * Force the asset enqueue. Used by the Gutenberg block render callback
	 * which may run after wp_enqueue_scripts has fired.
	 */
	public function force_enqueue(): void {
		$this->enqueue();
	}

	private static function pick( string $a, string $b ): string {
		return '' !== trim( $a ) ? $a : $b;
	}

	private function enqueue(): void {
		if ( wp_script_is( self::HANDLE_JS, 'enqueued' ) ) {
			return;
		}

		$base = plugins_url( '', \PersonalizedReader\PLUGIN_FILE );

		wp_enqueue_style(
			self::HANDLE_CSS,
			$base . '/assets/css/widget.css',
			array(),
			\PersonalizedReader\VERSION
		);

		wp_enqueue_script(
			self::HANDLE_JS,
			$base . '/assets/js/widget.js',
			array(),
			\PersonalizedReader\VERSION,
			true
		);

		wp_localize_script(
			self::HANDLE_JS,
			'PersonalizedReader',
			array(
				'sessionUrl'    => rest_url( Chat_Controller::NAMESPACE_ROOT . '/session' ),
				'transcriptUrl' => rest_url( Chat_Controller::NAMESPACE_ROOT . '/transcript' ),
				'sendUrl'       => rest_url( Chat_Controller::NAMESPACE_ROOT . '/send' ),
				'streamUrl'     => home_url( '/personalized-reader/chat-stream' ),
				'nonce'         => wp_create_nonce( Chat_Stream_Endpoint::NONCE_ACTION ),
				'restNonce'     => wp_create_nonce( 'wp_rest' ),
				'strings'       => array(
					'placeholder' => self::pick(
						(string) Settings::get( 'placeholder' ),
						__( 'Ask about our coverage…', 'personalized-reader' )
					),
					'send'        => __( 'Send', 'personalized-reader' ),
					'error'       => __( 'Something went wrong. Please try again.', 'personalized-reader' ),
					'thinking'    => __( 'Thinking…', 'personalized-reader' ),
					'title'       => self::pick(
						(string) Settings::get( 'widget_title' ),
						__( 'Ask the newsroom', 'personalized-reader' )
					),
					'openChat'    => __( 'Open reader chat', 'personalized-reader' ),
					'closeChat'   => __( 'Close chat', 'personalized-reader' ),
					'dialogLabel' => __( 'Reader chat', 'personalized-reader' ),
				),
			)
		);
	}
}
