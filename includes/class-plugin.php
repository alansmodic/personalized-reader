<?php
/**
 * Plugin bootstrap. Wires up ability + agent registration.
 *
 * @package PersonalizedReader
 */

declare( strict_types=1 );

namespace PersonalizedReader;

use PersonalizedReader\Abilities\Abilities;
use PersonalizedReader\Admin\Admin_Page;
use PersonalizedReader\Agent\Reader_Agent;
use PersonalizedReader\CLI\CLI_Command;
use PersonalizedReader\Frontend\Block;
use PersonalizedReader\Frontend\Widget;
use PersonalizedReader\Rest\Chat_Controller;
use PersonalizedReader\Settings\Settings;
use PersonalizedReader\Streaming\Chat_Stream_Endpoint;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		Settings::register();
		( new Abilities() )->register();
		( new Reader_Agent() )->register();
		( new Chat_Controller() )->register();
		Chat_Stream_Endpoint::register();
		( new Widget() )->register();
		( new Block() )->register();
		if ( is_admin() ) {
			( new Admin_Page() )->register();
		}
		CLI_Command::register();
	}
}
