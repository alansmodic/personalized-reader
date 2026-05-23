<?php
/**
 * Admin settings + status page.
 *
 * Settings → Personalized Reader. Three sections:
 *
 *   1. Status — dependency checks and registration counts.
 *   2. Settings — Settings API-driven form covering every option in
 *      Settings::DEFAULTS. Stored values are read back by the runtime;
 *      code-level filters still get the final word.
 *   3. Quick test — fires the buffered REST endpoint so an admin can
 *      verify a turn without leaving wp-admin.
 *
 * @package PersonalizedReader
 */

declare( strict_types=1 );

namespace PersonalizedReader\Admin;

use PersonalizedReader\Abilities\Abilities;
use PersonalizedReader\Compat\Dependencies;
use PersonalizedReader\Rest\Chat_Controller;
use PersonalizedReader\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class Admin_Page {

	private const MENU_SLUG = 'personalized-reader';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_fields' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	public function add_menu(): void {
		add_options_page(
			__( 'Personalized Reader', 'personalized-reader' ),
			__( 'Personalized Reader', 'personalized-reader' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	public function maybe_enqueue( string $hook ): void {
		if ( 'settings_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_script( 'wp-api-fetch' );
		wp_add_inline_script(
			'wp-api-fetch',
			sprintf(
				'window.PersonalizedReaderAdmin = %s;',
				(string) wp_json_encode( array(
					'sendUrl' => rest_url( Chat_Controller::NAMESPACE_ROOT . '/send' ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
				) )
			),
			'before'
		);
	}

	// -- Settings API field registration -----------------------------

	public function register_fields(): void {
		add_settings_section(
			'pr_section_voice',
			__( 'Editorial voice', 'personalized-reader' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Override the built-in system prompt and tune what the reader sees as the conversation starts.', 'personalized-reader' ) . '</p>';
			},
			self::MENU_SLUG
		);

		$this->add_field(
			'system_prompt',
			__( 'System prompt override', 'personalized-reader' ),
			'pr_section_voice',
			array( $this, 'field_textarea' ),
			__( 'Leave blank to use the built-in prompt (recommended). Use <code>{publication}</code> as a placeholder for the site name.', 'personalized-reader' )
		);

		$this->add_field(
			'widget_title',
			__( 'Widget title (floating mode)', 'personalized-reader' ),
			'pr_section_voice',
			array( $this, 'field_text' ),
			__( 'Shown in the header of the floating chat panel. Leave blank for the default ("Ask the newsroom").', 'personalized-reader' )
		);

		$this->add_field(
			'placeholder',
			__( 'Input placeholder', 'personalized-reader' ),
			'pr_section_voice',
			array( $this, 'field_text' ),
			__( 'Default text in the chat input field. Leave blank for the default ("Ask about our coverage…").', 'personalized-reader' )
		);

		add_settings_section(
			'pr_section_layout',
			__( 'Layout & display', 'personalized-reader' ),
			static function (): void {
				echo '<p>' . esc_html__( 'How the chat widget is presented on the site by default. Block instances and shortcodes can still override these.', 'personalized-reader' ) . '</p>';
			},
			self::MENU_SLUG
		);

		$this->add_field(
			'default_mode',
			__( 'Default display mode', 'personalized-reader' ),
			'pr_section_layout',
			array( $this, 'field_default_mode' ),
			__( 'Inline embeds the chat in-place; Floating shows a launcher button anchored to the bottom-right.', 'personalized-reader' )
		);

		add_settings_section(
			'pr_section_runtime',
			__( 'Runtime limits', 'personalized-reader' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Bounds on per-request cost and per-visitor abuse.', 'personalized-reader' ) . '</p>';
			},
			self::MENU_SLUG
		);

		$this->add_field(
			'max_tool_rounds',
			__( 'Max tool rounds per message', 'personalized-reader' ),
			'pr_section_runtime',
			array( $this, 'field_number' ),
			__( 'How many search/retrieval rounds the agent can run before answering. 1–8. Default 4.', 'personalized-reader' )
		);

		$this->add_field(
			'rate_limit_per_minute',
			__( 'Rate limit (requests/minute)', 'personalized-reader' ),
			'pr_section_runtime',
			array( $this, 'field_number' ),
			__( 'Per-session ceiling. Hit it and the visitor gets a 429 with a Retry-After header.', 'personalized-reader' )
		);

		$this->add_field(
			'free_articles',
			__( 'Free articles before paywall', 'personalized-reader' ),
			'pr_section_runtime',
			array( $this, 'field_number' ),
			__( 'Reported to the agent by the check-subscription tool when no custom filter is registered.', 'personalized-reader' )
		);

		add_settings_section(
			'pr_section_authority',
			__( 'Authority tier classification', 'personalized-reader' ),
			static function (): void {
				echo '<p>' . esc_html__( 'How posts are classified into "original-reporting", "wire", and "opinion" tiers — the agent uses these to phrase citations correctly (e.g. "our columnist argues" vs "according to AP").', 'personalized-reader' ) . '</p>';
			},
			self::MENU_SLUG
		);

		$this->add_field(
			'authority_opinion_cat',
			__( 'Opinion category slug', 'personalized-reader' ),
			'pr_section_authority',
			array( $this, 'field_text' ),
			__( 'Posts in this category are treated as opinion.', 'personalized-reader' )
		);

		$this->add_field(
			'authority_wire_tags',
			__( 'Wire tag slugs', 'personalized-reader' ),
			'pr_section_authority',
			array( $this, 'field_text' ),
			__( 'Comma-separated tag slugs. Posts carrying any of these are treated as wire content.', 'personalized-reader' )
		);
	}

	private function add_field( string $key, string $label, string $section, callable $render, string $help = '' ): void {
		add_settings_field(
			'pr_field_' . $key,
			$label,
			static function () use ( $render, $key, $help ): void {
				call_user_func( $render, $key );
				if ( '' !== $help ) {
					echo '<p class="description">' . wp_kses_post( $help ) . '</p>';
				}
			},
			self::MENU_SLUG,
			$section
		);
	}

	// -- Field renderers ---------------------------------------------

	public function field_text( string $key ): void {
		$value = (string) Settings::get( $key );
		printf(
			'<input type="text" class="regular-text" name="%s[%s]" value="%s" />',
			esc_attr( Settings::OPTION_NAME ),
			esc_attr( $key ),
			esc_attr( $value )
		);
	}

	public function field_textarea( string $key ): void {
		$value = (string) Settings::get( $key );
		printf(
			'<textarea class="large-text code" rows="10" name="%s[%s]">%s</textarea>',
			esc_attr( Settings::OPTION_NAME ),
			esc_attr( $key ),
			esc_textarea( $value )
		);
	}

	public function field_number( string $key ): void {
		$value = (int) Settings::get( $key );
		printf(
			'<input type="number" min="0" step="1" name="%s[%s]" value="%d" />',
			esc_attr( Settings::OPTION_NAME ),
			esc_attr( $key ),
			$value
		);
	}

	public function field_default_mode( string $key ): void {
		$value = (string) Settings::get( $key );
		$opts  = array(
			'inline'   => __( 'Inline (embedded in the page)', 'personalized-reader' ),
			'floating' => __( 'Floating launcher button', 'personalized-reader' ),
		);
		echo '<select name="' . esc_attr( Settings::OPTION_NAME ) . '[' . esc_attr( $key ) . ']">';
		foreach ( $opts as $v => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $v ),
				selected( $v, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	// -- Page render -------------------------------------------------

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Personalized Reader', 'personalized-reader' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Conversational guide to the publication archive for anonymous visitors. Built on the Agents API + Abilities API.', 'personalized-reader' ); ?>
			</p>

			<h2><?php esc_html_e( 'Status', 'personalized-reader' ); ?></h2>
			<table class="widefat striped" style="max-width: 720px;">
				<tbody>
					<?php
					foreach ( $this->status_checks() as $check ) :
						$icon = $check['ok'] ? '✅' : '❌';
						?>
						<tr>
							<td style="width: 32px;"><?php echo esc_html( $icon ); ?></td>
							<td><strong><?php echo esc_html( $check['label'] ); ?></strong></td>
							<td><?php echo wp_kses_post( $check['detail'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Settings', 'personalized-reader' ); ?></h2>
			<form method="post" action="options.php">
				<?php
				settings_fields( Settings::OPTION_GROUP );
				do_settings_sections( self::MENU_SLUG );
				submit_button();
				?>
			</form>

			<h2><?php esc_html_e( 'How to use', 'personalized-reader' ); ?></h2>
			<ol>
				<li>
					<strong><?php esc_html_e( 'Block', 'personalized-reader' ); ?>:</strong>
					<?php esc_html_e( 'In the editor, insert the "Reader Chat" block.', 'personalized-reader' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Shortcode', 'personalized-reader' ); ?>:</strong>
					<code>[personalized_reader]</code>
					<?php esc_html_e( 'or', 'personalized-reader' ); ?>
					<code>[personalized_reader mode="floating"]</code>
				</li>
				<li>
					<strong><?php esc_html_e( 'WP-CLI', 'personalized-reader' ); ?>:</strong>
					<code>wp personalized-reader chat "&lt;message&gt;"</code>
				</li>
			</ol>

			<h2><?php esc_html_e( 'Quick test', 'personalized-reader' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Send a message through the same buffered endpoint the widget uses.', 'personalized-reader' ); ?>
			</p>

			<form id="pr-admin-test" style="max-width: 720px;">
				<p>
					<label for="pr-admin-test-input"><?php esc_html_e( 'Message', 'personalized-reader' ); ?></label><br />
					<input type="text" id="pr-admin-test-input" class="regular-text"
						placeholder="<?php esc_attr_e( 'What have you written about housing?', 'personalized-reader' ); ?>"
						style="width: 100%;" />
				</p>
				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Send', 'personalized-reader' ); ?></button>
					<span id="pr-admin-test-status" style="margin-left: 12px; color: #666;"></span>
				</p>
				<pre id="pr-admin-test-output" style="background: #f6f7f7; border: 1px solid #c3c4c7; padding: 12px; min-height: 80px; white-space: pre-wrap; max-width: 100%;"></pre>
			</form>

			<script>
			( function () {
				var cfg  = window.PersonalizedReaderAdmin || {};
				var form = document.getElementById( 'pr-admin-test' );
				var input = document.getElementById( 'pr-admin-test-input' );
				var out  = document.getElementById( 'pr-admin-test-output' );
				var stat = document.getElementById( 'pr-admin-test-status' );

				form.addEventListener( 'submit', function ( ev ) {
					ev.preventDefault();
					var msg = ( input.value || '' ).trim();
					if ( ! msg ) return;

					out.textContent = '';
					stat.textContent = '<?php echo esc_js( __( 'Thinking…', 'personalized-reader' ) ); ?>';

					fetch( cfg.sendUrl, {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
						body: JSON.stringify( { message: msg } ),
					} )
						.then( function ( r ) { return r.json(); } )
						.then( function ( data ) {
							stat.textContent = '';
							var lines = [];
							( data.events || [] ).forEach( function ( ev ) {
								if ( ev.event === 'assistant_chunk' ) {
									lines.push( ( ev.data && ev.data.text ) || '' );
								} else if ( ev.event === 'tool_call' ) {
									lines.push( '→ tool_call ' + ( ev.data && ev.data.name ) );
								} else if ( ev.event === 'tool_result' ) {
									lines.push( '← tool_result ' + ( ev.data && ev.data.name ) );
								} else if ( ev.event === 'error' ) {
									lines.push( '[error] ' + ( ev.data && ev.data.message ) );
								}
							} );
							out.textContent = lines.join( '\n\n' );
						} )
						.catch( function ( err ) {
							stat.textContent = '';
							out.textContent = String( err );
						} );
				} );
			} )();
			</script>
		</div>
		<?php
	}

	/**
	 * @return array<int, array{ok:bool, label:string, detail:string}>
	 */
	private function status_checks(): array {
		$ability_count = 0;
		if ( function_exists( 'wp_get_ability' ) ) {
			foreach ( Abilities::ABILITY_SLUGS as $slug ) {
				if ( wp_get_ability( $slug ) ) {
					$ability_count++;
				}
			}
		}

		$agent_ok = function_exists( 'wp_get_agent' ) && wp_get_agent( 'personalized-reader' );

		return array(
			array(
				'ok'     => Dependencies::ai_client_available(),
				'label'  => __( 'AI client', 'personalized-reader' ),
				'detail' => Dependencies::ai_client_available()
					? esc_html__( 'wp_ai_client_prompt() is available.', 'personalized-reader' )
					: esc_html__( 'wp_ai_client_prompt() is missing. Requires WordPress 7.0+ or the wp-ai-client plugin.', 'personalized-reader' ),
			),
			array(
				'ok'     => Dependencies::abilities_api_available(),
				'label'  => __( 'Abilities API', 'personalized-reader' ),
				'detail' => Dependencies::abilities_api_available()
					? esc_html__( 'wp_register_ability() is available.', 'personalized-reader' )
					: esc_html__( 'Abilities API not detected.', 'personalized-reader' ),
			),
			array(
				'ok'     => Dependencies::agents_api_available(),
				'label'  => __( 'Agents API', 'personalized-reader' ),
				'detail' => Dependencies::agents_api_available()
					? esc_html__( 'wp_register_agent() is available.', 'personalized-reader' )
					: esc_html__( 'Agents API plugin is not active.', 'personalized-reader' ),
			),
			array(
				'ok'     => 4 === $ability_count,
				'label'  => __( 'Abilities registered', 'personalized-reader' ),
				'detail' => sprintf(
					/* translators: %d: count */
					esc_html__( '%d of 4 reader abilities reachable via wp_get_ability().', 'personalized-reader' ),
					$ability_count
				),
			),
			array(
				'ok'     => (bool) $agent_ok,
				'label'  => __( 'Agent registered', 'personalized-reader' ),
				'detail' => $agent_ok
					? esc_html__( 'personalized-reader agent is registered.', 'personalized-reader' )
					: esc_html__( 'Agent not registered (Agents API likely unavailable).', 'personalized-reader' ),
			),
		);
	}
}
