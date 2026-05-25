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
use PersonalizedReader\Conversation\Usage_Tracker;
use PersonalizedReader\Integrations\WPVDB_Backend;
use PersonalizedReader\Rest\Chat_Controller;
use PersonalizedReader\Settings\Settings;
use PersonalizedReader\Streaming\Chat_Stream_Endpoint;

defined( 'ABSPATH' ) || exit;

final class Admin_Page {

	private const MENU_SLUG = 'personalized-reader';

	/**
	 * Cache key for the SSE-reachable probe. Cached briefly even on
	 * failure so repeated admin page loads don't hammer the loopback.
	 */
	private const SSE_HEALTH_TRANSIENT = 'pr_sse_health';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_fields' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		add_action( 'admin_post_personalized_reader_flush_rewrites', array( $this, 'handle_flush_rewrites' ) );
	}

	/**
	 * Admin-post target for the "Flush rewrites" button on the status panel.
	 * Verifies the nonce + capability, re-registers our rewrite rule, then
	 * calls flush_rewrite_rules() and redirects back with a success notice.
	 *
	 * Flushing rewrites is one of the few things WP recommends doing rarely
	 * (it rewrites .htaccess on apache and rebuilds rules from scratch),
	 * so the button is the only path — there's no automatic retry.
	 */
	public function handle_flush_rewrites(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to flush rewrites.', 'personalized-reader' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'personalized_reader_flush_rewrites' );

		Chat_Stream_Endpoint::add_rewrite();
		flush_rewrite_rules();
		delete_transient( self::SSE_HEALTH_TRANSIENT );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::MENU_SLUG,
					'pr_notice' => 'rewrites_flushed',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
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
				(string) wp_json_encode(
					array(
						'sendUrl' => rest_url( Chat_Controller::NAMESPACE_ROOT . '/send' ),
						'nonce'   => wp_create_nonce( 'wp_rest' ),
					)
				)
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

		add_settings_section(
			'pr_section_backends',
			__( 'Search backend', 'personalized-reader' ),
			static function (): void {
				$installed = WPVDB_Backend::is_available();
				printf(
					'<p>%s</p>',
					$installed
						? esc_html__( 'WPVDB is installed and active. When enabled below, semantic vector search replaces the default WP_Query keyword search.', 'personalized-reader' )
						: esc_html__( 'WPVDB is not installed. Without it the agent falls back to keyword-only WP_Query search. Install Automattic/wpvdb to get semantic similarity ranking.', 'personalized-reader' )
				);
			},
			self::MENU_SLUG
		);

		$this->add_field(
			'wpvdb_integration',
			__( 'Use WPVDB when available', 'personalized-reader' ),
			'pr_section_backends',
			array( $this, 'field_checkbox' ),
			__( 'When WPVDB is active, route search and recommendation queries through its vector index. Has no effect when WPVDB is not installed.', 'personalized-reader' )
		);

		add_settings_section(
			'pr_section_cost',
			__( 'Cost estimation', 'personalized-reader' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Prices per million tokens, in USD. Used only to compute the estimated-cost column on this page — not billed by us. Edit when you switch models.', 'personalized-reader' ) . '</p>';
				echo '<p class="description">' . esc_html__( 'Common Anthropic prices (as of mid-2026): Claude Sonnet 4.5 — $3 in / $15 out · Claude Opus 4.7 — $15 in / $75 out · Claude Haiku 4.5 — $1 in / $5 out.', 'personalized-reader' ) . '</p>';
			},
			self::MENU_SLUG
		);

		$this->add_field(
			'cost_prompt_per_million',
			__( 'Price per million prompt tokens ($)', 'personalized-reader' ),
			'pr_section_cost',
			array( $this, 'field_decimal' )
		);

		$this->add_field(
			'cost_completion_per_million',
			__( 'Price per million completion tokens ($)', 'personalized-reader' ),
			'pr_section_cost',
			array( $this, 'field_decimal' )
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
			'<input type="number" min="0" step="1" name="%s[%s]" value="%s" />',
			esc_attr( Settings::OPTION_NAME ),
			esc_attr( $key ),
			esc_attr( (string) $value )
		);
	}

	public function field_decimal( string $key ): void {
		$value = (float) Settings::get( $key );
		printf(
			'<input type="number" min="0" step="0.01" name="%s[%s]" value="%s" />',
			esc_attr( Settings::OPTION_NAME ),
			esc_attr( $key ),
			esc_attr( number_format( $value, 2, '.', '' ) )
		);
	}

	public function field_checkbox( string $key ): void {
		$value = (bool) Settings::get( $key );
		printf(
			'<label><input type="checkbox" name="%s[%s]" value="1"%s /> %s</label>',
			esc_attr( Settings::OPTION_NAME ),
			esc_attr( $key ),
			checked( true, $value, false ),
			esc_html__( 'Enabled', 'personalized-reader' )
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
		$this->maybe_render_notice();
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

			<h2><?php esc_html_e( 'Usage', 'personalized-reader' ); ?></h2>
			<?php $this->render_usage(); ?>

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

	private function render_usage(): void {
		$totals     = Usage_Tracker::totals();
		$this_month = Usage_Tracker::this_month();

		$cost_in  = (float) Settings::get( 'cost_prompt_per_million' );
		$cost_out = (float) Settings::get( 'cost_completion_per_million' );

		$estimate = static function ( array $bucket ) use ( $cost_in, $cost_out ): string {
			$usd = ( (int) $bucket['prompt_tokens'] / 1_000_000 ) * $cost_in
				+ ( (int) $bucket['completion_tokens'] / 1_000_000 ) * $cost_out;
			// Show 4 decimals so single-turn pennies don't read as $0.00.
			return '$' . number_format( $usd, 4, '.', ',' );
		};
		?>
		<table class="widefat striped" style="max-width: 720px;">
			<thead>
				<tr>
					<th></th>
					<th><?php esc_html_e( 'Sessions', 'personalized-reader' ); ?></th>
					<th><?php esc_html_e( 'Prompt tokens', 'personalized-reader' ); ?></th>
					<th><?php esc_html_e( 'Completion tokens', 'personalized-reader' ); ?></th>
					<th><?php esc_html_e( 'Total tokens', 'personalized-reader' ); ?></th>
					<th><?php esc_html_e( 'Est. cost', 'personalized-reader' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><strong><?php esc_html_e( 'This month', 'personalized-reader' ); ?></strong></td>
					<td><?php echo esc_html( number_format_i18n( (int) $this_month['sessions'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( (int) $this_month['prompt_tokens'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( (int) $this_month['completion_tokens'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( (int) $this_month['total_tokens'] ) ); ?></td>
					<td><?php echo esc_html( $estimate( $this_month ) ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'All time', 'personalized-reader' ); ?></strong></td>
					<td><?php echo esc_html( number_format_i18n( (int) $totals['sessions'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( (int) $totals['prompt_tokens'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( (int) $totals['completion_tokens'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( (int) $totals['total_tokens'] ) ); ?></td>
					<td><?php echo esc_html( $estimate( $totals ) ); ?></td>
				</tr>
			</tbody>
		</table>
		<p class="description">
			<?php
			printf(
				/* translators: %1$s prompt price, %2$s completion price */
				esc_html__( 'Estimated cost is computed from your configured prices below (currently $%1$s per million prompt tokens, $%2$s per million completion tokens). Update those when you switch models. Token counts are summed across every conversation turn; "sessions" counts each completed Loop::run, not unique visitors.', 'personalized-reader' ),
				number_format( $cost_in, 2 ),
				number_format( $cost_out, 2 )
			);
			?>
		</p>
		<?php
	}

	private function maybe_render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag set by our own admin-post redirect.
		if ( ! isset( $_GET['pr_notice'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- ditto.
		$notice = sanitize_key( wp_unslash( $_GET['pr_notice'] ) );
		if ( 'rewrites_flushed' !== $notice ) {
			return;
		}
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Rewrite rules flushed. The SSE chat endpoint should now resolve.', 'personalized-reader' )
		);
	}

	/**
	 * @return array<int, array{ok:bool, label:string, detail:string}>
	 */
	private function status_checks(): array {
		$ability_count = 0;
		if ( function_exists( 'wp_get_ability' ) ) {
			foreach ( Abilities::ABILITY_SLUGS as $slug ) {
				if ( wp_get_ability( $slug ) ) {
					++$ability_count;
				}
			}
		}

		$agent_ok    = function_exists( 'wp_get_agent' ) && wp_get_agent( 'personalized-reader' );
		$provider_ok = $this->is_ai_provider_ready();
		$wpvdb_state = $this->wpvdb_state();
		$sse_state   = $this->sse_health();

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
			array(
				'ok'     => $sse_state['ok'],
				'label'  => __( 'SSE endpoint reachable', 'personalized-reader' ),
				'detail' => $sse_state['detail'],
			),
			array(
				'ok'     => $provider_ok,
				'label'  => __( 'AI provider configured', 'personalized-reader' ),
				'detail' => $provider_ok
					? esc_html__( 'A provider plugin has working credentials — text generation is supported.', 'personalized-reader' )
					: wp_kses_post(
						sprintf(
						/* translators: %s: plugins.php URL */
							__( 'No AI provider has credentials yet. Install <a href="https://wordpress.org/plugins/ai-provider-for-anthropic/" target="_blank" rel="noopener">ai-provider-for-anthropic</a> (or another provider for the WP AI client) and add your API key under <a href="%s">Plugins</a> → the provider\'s settings.', 'personalized-reader' ),
							esc_url( admin_url( 'plugins.php' ) )
						)
					),
			),
			array(
				// Informational only — the agent works fine without WPVDB.
				// Mark as "ok" when present, "ok" (with a different detail
				// string) when absent, so this never lights up red.
				'ok'     => true,
				'label'  => __( 'WPVDB (semantic search)', 'personalized-reader' ),
				'detail' => WPVDB_Backend::is_available()
					? ( (bool) Settings::get( 'wpvdb_integration' )
						? esc_html__( 'Detected and routed: search runs through the vector index.', 'personalized-reader' )
						: esc_html__( 'Detected but disabled in settings. Search falls back to keyword WP_Query.', 'personalized-reader' )
					)
					: esc_html__( 'Not installed. Search uses keyword WP_Query. Install Automattic/wpvdb for semantic ranking.', 'personalized-reader' ),
			),
			array(
				'ok'     => $wpvdb_state['key_ok'],
				'label'  => __( 'WPVDB embedding key', 'personalized-reader' ),
				'detail' => $wpvdb_state['key_detail'],
			),
			array(
				'ok'     => $wpvdb_state['embedded_ok'],
				'label'  => __( 'Archive embedded', 'personalized-reader' ),
				'detail' => $wpvdb_state['embedded_detail'],
			),
		);
	}

	/**
	 * Probe the SSE chat-stream endpoint via a HEAD request.
	 *
	 * Decision matrix:
	 *
	 *   - 405 Method Not Allowed → rewrite resolves, endpoint rejects HEAD
	 *     (which is what its handler does for anything but POST). Healthy.
	 *   - 404 Not Found          → rewrite rule isn't registered. The
	 *     plugin's activation hook runs flush_rewrite_rules() but it can
	 *     get out of sync after a wp-cli copy, a multisite move, or a
	 *     permalink-structure change. Surface a button.
	 *   - anything else / network error → ambiguous (loopback disabled,
	 *     reverse proxy intercept, etc.) — report as unknown rather than
	 *     unhealthy so we don't false-positive.
	 *
	 * Result cached briefly in a transient — even on failure, since a
	 * settings page reload shouldn't issue a fresh loopback every time.
	 *
	 * @return array{ok:bool, detail:string}
	 */
	private function sse_health(): array {
		$flush_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=personalized_reader_flush_rewrites' ),
			'personalized_reader_flush_rewrites'
		);

		$cached = get_transient( self::SSE_HEALTH_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['ok'], $cached['detail'] ) ) {
			return array(
				'ok'     => (bool) $cached['ok'],
				'detail' => (string) $cached['detail'],
			);
		}

		$url      = home_url( '/personalized-reader/chat-stream' );
		$response = wp_remote_request(
			$url,
			array(
				'method'      => 'HEAD',
				'timeout'     => 4,
				'redirection' => 0,
				'sslverify'   => false,
			)
		);

		$ok          = false;
		$status_code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$cache_ttl   = 5 * MINUTE_IN_SECONDS;

		if ( 405 === $status_code ) {
			$ok        = true;
			$detail    = esc_html__( 'HEAD probe returned 405 (Method Not Allowed). Rewrite is registered and the endpoint is rejecting HEAD as designed.', 'personalized-reader' );
			$cache_ttl = HOUR_IN_SECONDS;
		} elseif ( 404 === $status_code ) {
			$detail = wp_kses_post(
				sprintf(
				/* translators: %s: flush-rewrites action URL */
					__( 'HEAD probe returned 404. The rewrite rule for <code>/personalized-reader/chat-stream</code> is not active — readers will fall back to the buffered REST endpoint, losing live streaming. <a href="%s">Flush rewrites</a> to fix.', 'personalized-reader' ),
					esc_url( $flush_url )
				)
			);
		} elseif ( is_wp_error( $response ) ) {
			$detail = wp_kses_post(
				sprintf(
				/* translators: 1: wp_error message, 2: flush-rewrites action URL */
					__( 'Loopback probe failed: %1$s. This often means WP cannot reach itself (firewall, reverse-proxy, or HTTPS cert issue) — it does not necessarily mean the endpoint is broken. If readers see stalled chats, <a href="%2$s">flush rewrites</a> and retry.', 'personalized-reader' ),
					esc_html( $response->get_error_message() ),
					esc_url( $flush_url )
				)
			);
		} else {
			$detail = wp_kses_post(
				sprintf(
				/* translators: 1: HTTP status code, 2: flush-rewrites action URL */
					__( 'Unexpected HEAD response: %1$d. Expected 405 from the SSE handler. If chats are stalling, <a href="%2$s">flush rewrites</a>.', 'personalized-reader' ),
					$status_code,
					esc_url( $flush_url )
				)
			);
		}

		$result = array(
			'ok'     => $ok,
			'detail' => $detail,
		);
		set_transient( self::SSE_HEALTH_TRANSIENT, $result, $cache_ttl );
		return $result;
	}

	/**
	 * Probe the AI client for at least one usable text-generation provider.
	 *
	 * This is provider-agnostic: any provider plugin (ai-provider-for-anthropic,
	 * OpenAI, etc.) that successfully registers with credentials will satisfy
	 * isSupportedForTextGeneration(). When it returns false, the user has
	 * either no provider installed or a provider installed but no API key.
	 */
	private function is_ai_provider_ready(): bool {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}
		try {
			$builder = wp_ai_client_prompt();
			return (bool) $builder->isSupportedForTextGeneration();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * WPVDB-specific state: is a key configured, has the archive been
	 * embedded yet, and what should we tell the admin to do about it.
	 *
	 * All three rows skip cleanly when WPVDB isn't installed — both key
	 * and embedded states return ok=true with a "not applicable" detail
	 * so the panel never lights up red just because the user opted out
	 * of semantic search.
	 *
	 * @return array{key_ok:bool, key_detail:string, embedded_ok:bool, embedded_detail:string}
	 */
	private function wpvdb_state(): array {
		$wpvdb_available = WPVDB_Backend::is_available();
		$wpvdb_admin_url = admin_url( 'admin.php?page=wpvdb' );

		if ( ! $wpvdb_available ) {
			$na = esc_html__( 'Not applicable — WPVDB is not installed.', 'personalized-reader' );
			return array(
				'key_ok'          => true,
				'key_detail'      => $na,
				'embedded_ok'     => true,
				'embedded_detail' => $na,
			);
		}

		// Embedding key: either set via define() in wp-config or stored in
		// WPVDB's settings option. The constant wins at WPVDB's runtime, so
		// check both.
		$key_ok = defined( 'WPVDB_OPENAI_API_KEY' )
			|| defined( 'WPVDB_AUTOMATTIC_API_KEY' );
		if ( ! $key_ok && class_exists( '\\WPVDB\\Settings' ) && method_exists( '\\WPVDB\\Settings', 'get_api_key' ) ) {
			$resolved = (string) \WPVDB\Settings::get_api_key();
			$key_ok   = '' !== $resolved;
		}

		// Embedded archive: count rows. Cheap COUNT(*) — single index hit.
		// Cached for 60s under a transient so reloading the settings page
		// doesn't issue a fresh COUNT(*) each time.
		global $wpdb;
		$embedded_count = get_transient( 'pr_wpvdb_embedded_count' );
		if ( false === $embedded_count ) {
			$embedded_count = 0;
			$table          = $wpdb->prefix . 'wpvdb_embeddings';
			$table_exists   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot existence check, cheap.
			if ( $table_exists === $table ) {
				$embedded_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpvdb_embeddings" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- aggregate count over our own table; result cached via transient below.
			}
			set_transient( 'pr_wpvdb_embedded_count', (int) $embedded_count, MINUTE_IN_SECONDS );
		}
		$embedded_count = (int) $embedded_count;

		return array(
			'key_ok'          => $key_ok,
			'key_detail'      => $key_ok
				? esc_html__( 'Embedding API key is configured.', 'personalized-reader' )
				: wp_kses_post(
					sprintf(
					/* translators: %s: WPVDB settings URL */
						__( 'No embedding API key found. Add one in <a href="%s">WPVDB settings</a>, or define <code>WPVDB_OPENAI_API_KEY</code> in <code>wp-config.php</code>.', 'personalized-reader' ),
						esc_url( $wpvdb_admin_url )
					)
				),
			'embedded_ok'     => $embedded_count > 0,
			'embedded_detail' => $embedded_count > 0
				? sprintf(
					/* translators: %d: embedding row count */
					esc_html__( '%d embedding rows in wp_wpvdb_embeddings.', 'personalized-reader' ),
					$embedded_count
				)
				: wp_kses_post(
					sprintf(
					/* translators: %s: WPVDB settings URL */
						__( 'Archive has not been embedded yet. Trigger a re-embed job in <a href="%s">WPVDB settings</a> so semantic search has data to find.', 'personalized-reader' ),
						esc_url( $wpvdb_admin_url )
					)
				),
		);
	}
}
