<?php
/**
 * Settings screen and form handlers.
 *
 * @package AIVisibilityDashboard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings → AI Visibility screen.
 */
class AIVD_Admin {

	const PAGE = 'ai-visibility-dashboard';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_aivd_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_aivd_refresh', array( __CLASS__, 'handle_refresh' ) );
	}

	/**
	 * Registers the screen.
	 */
	public static function menu() {
		add_options_page(
			__( 'AI Visibility', 'ai-visibility-dashboard' ),
			__( 'AI Visibility', 'ai-visibility-dashboard' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Loads the stylesheet on the dashboard and on the settings screen only.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function assets( $hook ) {
		if ( 'index.php' !== $hook && 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'aivd-admin', AIVD_URL . 'assets/admin.css', array(), AIVD_VERSION );
	}

	/**
	 * Human names for the AI engines.
	 *
	 * @return array
	 */
	public static function engine_labels() {
		return array(
			'chatgpt'            => 'ChatGPT',
			'claude'             => 'Claude',
			'perplexity'         => 'Perplexity',
			'google_ai_overview' => __( 'Google AI Overviews', 'ai-visibility-dashboard' ),
			'google_ai_mode'     => __( 'Google AI Mode', 'ai-visibility-dashboard' ),
		);
	}

	/**
	 * Saves the form, then checks the key against the API.
	 */
	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'ai-visibility-dashboard' ), 403 );
		}
		check_admin_referer( 'aivd_save' );

		$changes = array();

		if ( 'option' === AIVD_Settings::key_source() || '' === AIVD_Settings::key_source() ) {
			if ( ! empty( $_POST['aivd_remove_key'] ) ) {
				$changes['api_key']         = '';
				$changes['organization_id'] = '';
				$changes['project_id']      = '';
			} else {
				// Keys are opaque tokens: keep them as typed, minus whitespace and control characters.
				$key = isset( $_POST['aivd_api_key'] ) ? preg_replace( '/[\s\x00-\x1F\x7F]+/', '', wp_unslash( $_POST['aivd_api_key'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by the regular expression.
				if ( '' !== $key ) {
					$changes['api_key'] = substr( $key, 0, 255 );
				}
			}
		}

		if ( isset( $_POST['aivd_project'] ) ) {
			$project = sanitize_text_field( wp_unslash( $_POST['aivd_project'] ) );
			$parts   = explode( '|', $project );
			if ( 2 === count( $parts ) && self::is_uuid( $parts[0] ) && self::is_uuid( $parts[1] ) ) {
				$changes['organization_id'] = $parts[0];
				$changes['project_id']      = $parts[1];
			} elseif ( '' === $project ) {
				$changes['organization_id'] = '';
				$changes['project_id']      = '';
			}
		}

		if ( isset( $_POST['aivd_range'] ) ) {
			$changes['range'] = AIVD_Settings::range( absint( $_POST['aivd_range'] ) );
		}

		$engines = isset( $_POST['aivd_engines'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['aivd_engines'] ) ) : array();
		$engines = array_values( array_intersect( AIVD_Settings::AI_ENGINES, $engines ) );
		// Every engine ticked is stored as "all", so engines added later are included too.
		$changes['engines'] = count( $engines ) === count( AIVD_Settings::AI_ENGINES ) ? array() : $engines;

		$audience            = isset( $_POST['aivd_audience'] ) ? sanitize_key( wp_unslash( $_POST['aivd_audience'] ) ) : '';
		$changes['audience'] = 'editors' === $audience ? 'editors' : 'administrators';

		AIVD_Settings::update( $changes );
		AIVD_Settings::flush_cache();

		if ( '' === AIVD_Settings::api_key() ) {
			self::notice( 'success', __( 'Settings saved.', 'ai-visibility-dashboard' ) );
		} else {
			$me = ( new AIVD_API() )->me();
			if ( is_wp_error( $me ) ) {
				self::notice( 'error', $me->get_error_message() );
			} elseif ( ! AIVD_Settings::is_configured() ) {
				self::notice( 'success', __( 'Key saved and working. Now choose the project to show.', 'ai-visibility-dashboard' ) );
			} else {
				self::notice( 'success', __( 'Settings saved. The widget is ready on your Dashboard.', 'ai-visibility-dashboard' ) );
			}
		}

		wp_safe_redirect( AIVD_Settings::page_url() );
		exit;
	}

	/**
	 * Clears the cache from the widget's "Refresh" link.
	 */
	public static function handle_refresh() {
		if ( ! current_user_can( AIVD_Settings::widget_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ai-visibility-dashboard' ), 403 );
		}
		check_admin_referer( 'aivd_refresh' );

		AIVD_Settings::flush_cache();

		$range = isset( $_GET['aivd_range'] ) ? AIVD_Settings::range( absint( $_GET['aivd_range'] ) ) : AIVD_Settings::range();
		wp_safe_redirect( add_query_arg( 'aivd_range', $range, admin_url( 'index.php' ) ) . '#aivd_widget' );
		exit;
	}

	/**
	 * Whether a string looks like a UUID.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function is_uuid( $value ) {
		return (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value );
	}

	/**
	 * Stores a one-time notice for the current user.
	 *
	 * @param string $type    'success' or 'error'.
	 * @param string $message Message.
	 */
	private static function notice( $type, $message ) {
		set_transient(
			'aivd_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Projects the key can read, cached for a few minutes per key.
	 *
	 * @return array|WP_Error
	 */
	private static function projects() {
		$cache_key = 'aivd_projects_' . md5( AIVD_Settings::cache_version() . '|' . AIVD_Settings::api_key() );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$projects = ( new AIVD_API() )->all_projects();
		if ( ! is_wp_error( $projects ) ) {
			set_transient( $cache_key, $projects, 10 * MINUTE_IN_SECONDS );
		}
		return $projects;
	}

	/**
	 * Renders the screen.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings   = AIVD_Settings::all();
		$key_source = AIVD_Settings::key_source();
		$hint       = AIVD_Settings::key_hint();
		$notice     = get_transient( 'aivd_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'aivd_notice_' . get_current_user_id() );
		}

		$projects = '' !== $key_source ? self::projects() : array();
		$selected = $settings['organization_id'] && $settings['project_id'] ? $settings['organization_id'] . '|' . $settings['project_id'] : '';
		if ( '' === $selected && is_array( $projects ) && 1 === count( $projects ) ) {
			$selected = $projects[0]['organization_id'] . '|' . $projects[0]['project_id'];
		}
		$engines = empty( $settings['engines'] ) ? AIVD_Settings::AI_ENGINES : (array) $settings['engines'];
		?>
		<div class="wrap aivd-settings">
			<h1><?php esc_html_e( 'AI Visibility', 'ai-visibility-dashboard' ); ?></h1>
			<p class="aivd-lead">
				<?php esc_html_e( 'See on your Dashboard how ChatGPT, Claude, Perplexity and Google AI mention your brand: Share of Voice, mention rate, sentiment, competitors and the sources they cite. The data comes from your Mencoro project.', 'ai-visibility-dashboard' ); ?>
			</p>

			<?php if ( is_array( $notice ) && ! empty( $notice['message'] ) ) : ?>
				<div class="notice notice-<?php echo 'error' === $notice['type'] ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="aivd_save">
				<?php wp_nonce_field( 'aivd_save' ); ?>

				<h2><?php esc_html_e( '1. Mencoro API key', 'ai-visibility-dashboard' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="aivd_api_key"><?php esc_html_e( 'API key', 'ai-visibility-dashboard' ); ?></label></th>
						<td>
							<?php if ( 'constant' === $key_source || 'env' === $key_source ) : ?>
								<p>
									<?php
									echo esc_html(
										'constant' === $key_source
											/* translators: %s: last four characters of the API key. */
											? sprintf( __( 'Defined in wp-config.php with the MENCORO_API_KEY constant (ends in %s).', 'ai-visibility-dashboard' ), $hint )
											/* translators: %s: last four characters of the API key. */
											: sprintf( __( 'Read from the MENCORO_API_KEY environment variable (ends in %s).', 'ai-visibility-dashboard' ), $hint )
									);
									?>
								</p>
							<?php else : ?>
								<input type="password" id="aivd_api_key" name="aivd_api_key" class="regular-text code" autocomplete="off" spellcheck="false"
									placeholder="<?php echo esc_attr( $hint ? '•••• ' . $hint : 'mencoro_sk_…' ); ?>">
								<?php if ( 'option' === $key_source ) : ?>
									<p class="description">
										<?php
										/* translators: %s: last four characters of the API key. */
										echo esc_html( sprintf( __( 'A key ending in %s is saved. Leave the field empty to keep it.', 'ai-visibility-dashboard' ), $hint ) );
										?>
									</p>
									<p><label><input type="checkbox" name="aivd_remove_key" value="1"> <?php esc_html_e( 'Remove the saved key', 'ai-visibility-dashboard' ); ?></label></p>
								<?php else : ?>
									<p class="description">
										<?php
										printf(
											/* translators: %s: link to Mencoro. */
											esc_html__( 'Create a key in your %s account, under API keys in the user menu. The "read" capability is enough: the plugin never changes anything in Mencoro.', 'ai-visibility-dashboard' ),
											'<a href="https://mencoro.com/" target="_blank" rel="noopener noreferrer">Mencoro</a>'
										);
										?>
									</p>
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'Tip: on shared or client sites, define it in wp-config.php instead: define( \'MENCORO_API_KEY\', \'…\' );', 'ai-visibility-dashboard' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( '2. What to show', 'ai-visibility-dashboard' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="aivd_project"><?php esc_html_e( 'Project', 'ai-visibility-dashboard' ); ?></label></th>
						<td>
							<?php if ( '' === $key_source ) : ?>
								<p class="description"><?php esc_html_e( 'Save your API key first to list your projects.', 'ai-visibility-dashboard' ); ?></p>
							<?php elseif ( is_wp_error( $projects ) ) : ?>
								<p class="aivd-error"><?php echo esc_html( $projects->get_error_message() ); ?></p>
							<?php elseif ( empty( $projects ) ) : ?>
								<p class="description"><?php esc_html_e( 'This key cannot see any active project yet. Create one in Mencoro and come back.', 'ai-visibility-dashboard' ); ?></p>
							<?php else : ?>
								<select id="aivd_project" name="aivd_project">
									<option value=""><?php esc_html_e( '— Choose a project —', 'ai-visibility-dashboard' ); ?></option>
									<?php
									$current_org = null;
									foreach ( $projects as $project ) {
										if ( $project['organization_id'] !== $current_org ) {
											if ( null !== $current_org ) {
												echo '</optgroup>';
											}
											echo '<optgroup label="' . esc_attr( $project['organization_name'] ) . '">';
											$current_org = $project['organization_id'];
										}
										$value = $project['organization_id'] . '|' . $project['project_id'];
										echo '<option value="' . esc_attr( $value ) . '"' . selected( $selected, $value, false ) . '>' . esc_html( $project['project_name'] ) . '</option>';
									}
									echo '</optgroup>';
									?>
								</select>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'AI engines', 'ai-visibility-dashboard' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'AI engines', 'ai-visibility-dashboard' ); ?></legend>
								<?php foreach ( self::engine_labels() as $code => $label ) : ?>
									<label class="aivd-check"><input type="checkbox" name="aivd_engines[]" value="<?php echo esc_attr( $code ); ?>" <?php checked( in_array( $code, $engines, true ) ); ?>> <?php echo esc_html( $label ); ?></label>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( 'Only the engines your project tracks in Mencoro are used. Google classic results and Shopping are left out on purpose.', 'ai-visibility-dashboard' ); ?></p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aivd_range"><?php esc_html_e( 'Default period', 'ai-visibility-dashboard' ); ?></label></th>
						<td>
							<select id="aivd_range" name="aivd_range">
								<?php foreach ( AIVD_Settings::RANGES as $days ) : ?>
									<option value="<?php echo esc_attr( (string) $days ); ?>" <?php selected( AIVD_Settings::range(), $days ); ?>>
										<?php
										/* translators: %d: number of days. */
										echo esc_html( sprintf( _n( 'Last %d day', 'Last %d days', $days, 'ai-visibility-dashboard' ), $days ) );
										?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Trends compare with the previous period of the same length.', 'ai-visibility-dashboard' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Who sees the widget', 'ai-visibility-dashboard' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Who sees the widget', 'ai-visibility-dashboard' ); ?></legend>
								<label class="aivd-check"><input type="radio" name="aivd_audience" value="administrators" <?php checked( 'editors' !== $settings['audience'] ); ?>> <?php esc_html_e( 'Administrators', 'ai-visibility-dashboard' ); ?></label>
								<label class="aivd-check"><input type="radio" name="aivd_audience" value="editors" <?php checked( 'editors' === $settings['audience'] ); ?>> <?php esc_html_e( 'Editors and administrators', 'ai-visibility-dashboard' ); ?></label>
								<p class="description"><?php esc_html_e( 'Handy for agencies: let the client\'s editors see their AI visibility without access to the settings or the key.', 'ai-visibility-dashboard' ); ?></p>
							</fieldset>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save and check connection', 'ai-visibility-dashboard' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Privacy', 'ai-visibility-dashboard' ); ?></h2>
			<p class="aivd-privacy">
				<?php
				printf(
					/* translators: %s: API host name. */
					esc_html__( 'The plugin only talks to %s, with your key and in read-only mode. It sends nothing about your site or your visitors, adds nothing to the front end and loads no external scripts.', 'ai-visibility-dashboard' ),
					'<code>' . esc_html( (string) wp_parse_url( AIVD_API::base_url(), PHP_URL_HOST ) ) . '</code>'
				);
				?>
			</p>
		</div>
		<?php
	}
}
