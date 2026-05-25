<?php
/**
 * Plugin Name:       Personalized Reader
 * Plugin URI:        https://github.com/alansmodic/personalized-reader
 * Description:       Conversational reader agent that helps anonymous visitors discover and navigate the publication archive. Built on the Agents API + Abilities API.
 * Version:           0.2.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Alan Smodic
 * Author URI:        https://github.com/alansmodic
 * License:           GPL-2.0-or-later
 * Text Domain:       personalized-reader
 * Requires Plugins:  agents-api
 *
 * @package PersonalizedReader
 */

declare( strict_types=1 );

namespace PersonalizedReader;

defined( 'ABSPATH' ) || exit;

const VERSION     = '0.2.0';
const PLUGIN_FILE = __FILE__;
const PLUGIN_DIR  = __DIR__;
const TEXT_DOMAIN = 'personalized-reader';

require_once __DIR__ . '/includes/class-autoloader.php';
Autoloader::register();

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! Compat\Dependencies::satisfied() ) {
			Compat\Dependencies::render_admin_notice();
			return;
		}
		Plugin::instance()->boot();
	}
);

register_activation_hook(
	__FILE__,
	static function (): void {
		// Register the SSE rewrite then flush so /personalized-reader/chat-stream
		// resolves immediately after activation, without forcing the admin to
		// visit Settings → Permalinks.
		Streaming\Chat_Stream_Endpoint::add_rewrite();
		flush_rewrite_rules();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		// Drop our rewrite rule so a stale `/personalized-reader/chat-stream`
		// doesn't 500 after the plugin is gone.
		flush_rewrite_rules();
	}
);
