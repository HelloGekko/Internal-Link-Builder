<?php
/**
 * Admin menu and settings page rendering.
 *
 * @package InternalLinkBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ILB_Admin
 */
class ILB_Admin {

	/**
	 * Admin page slug.
	 */
	const PAGE_SLUG = 'internal-link-builder';

	/**
	 * Nonce action for the token search endpoints.
	 */
	const SEARCH_NONCE = 'ilb_token_search';

	/**
	 * Settings handler.
	 *
	 * @var ILB_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param ILB_Settings $settings Settings handler.
	 */
	public function __construct( ILB_Settings $settings ) {
		$this->settings = $settings;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . ILB_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );

		add_action( 'wp_ajax_ilb_search_posts', array( $this, 'ajax_search_posts' ) );
		add_action( 'wp_ajax_ilb_search_terms', array( $this, 'ajax_search_terms' ) );
	}

	/**
	 * Verifies the token-search request, dying on failure.
	 */
	private function guard_search() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array(), 403 );
		}
		check_ajax_referer( self::SEARCH_NONCE, 'nonce' );
	}

	/**
	 * AJAX: searches posts by title within the whitelisted post types.
	 */
	public function ajax_search_posts() {
		$this->guard_search();

		$search     = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$post_types = (array) $this->settings->get( 'whitelist_post_types' );
		if ( empty( $post_types ) ) {
			$post_types = get_post_types( array( 'public' => true ) );
		}

		$query = new WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				's'                      => $search,
				'posts_per_page'         => 20,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$results = array();
		foreach ( $query->posts as $post ) {
			$results[] = array(
				'id'   => $post->ID,
				'text' => $this->token_label( get_the_title( $post ), $post->ID ),
			);
		}

		wp_send_json_success( $results );
	}

	/**
	 * AJAX: searches terms by name within the whitelisted taxonomies.
	 */
	public function ajax_search_terms() {
		$this->guard_search();

		$search     = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$taxonomies = (array) $this->settings->get( 'whitelist_taxonomies' );
		if ( empty( $taxonomies ) ) {
			$taxonomies = get_taxonomies( array( 'public' => true ) );
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomies,
				'hide_empty' => false,
				'search'     => $search,
				'number'     => 20,
			)
		);

		$results = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$results[] = array(
					'id'   => $term->term_id,
					'text' => $this->token_label( $term->name, $term->term_id ),
				);
			}
		}

		wp_send_json_success( $results );
	}

	/**
	 * Formats a token label as "Title (ID: 123)".
	 *
	 * @param string $title Object title/name.
	 * @param int    $id    Object ID.
	 * @return string
	 */
	private function token_label( $title, $id ) {
		/* translators: 1: object title, 2: object ID. */
		return sprintf( __( '%1$s (ID: %2$d)', 'internal-link-builder' ), $title, (int) $id );
	}

	/**
	 * Registers the admin menu entry.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Internal Link Builder', 'internal-link-builder' ),
			__( 'Link Builder', 'internal-link-builder' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-admin-links',
			81
		);
	}

	/**
	 * Adds a "Settings" link on the plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$url      = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$settings = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'internal-link-builder' ) . '</a>';
		array_unshift( $links, $settings );

		return $links;
	}

	/**
	 * Enqueues admin assets only on the plugin's own page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'ilb-admin',
			ILB_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			ILB_VERSION
		);

		wp_enqueue_script(
			'ilb-admin',
			ILB_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			ILB_VERSION,
			true
		);

		wp_enqueue_script(
			'ilb-token',
			ILB_PLUGIN_URL . 'assets/js/token-select.js',
			array( 'jquery' ),
			ILB_VERSION,
			true
		);

		wp_localize_script(
			'ilb-admin',
			'ilbAdmin',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( ILB_Actions::NONCE_ACTION ),
				'i18n'     => array(
					'confirmCancel' => __( 'Cancel all pending scheduled actions?', 'internal-link-builder' ),
					'working'       => __( 'Working…', 'internal-link-builder' ),
					'remove'        => __( 'Remove', 'internal-link-builder' ),
					'addLine'       => __( 'Add line', 'internal-link-builder' ),
				),
			)
		);

		wp_localize_script(
			'ilb-token',
			'ilbToken',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::SEARCH_NONCE ),
				'i18n'    => array(
					'remove'    => __( 'Remove', 'internal-link-builder' ),
					'searching' => __( 'Searching…', 'internal-link-builder' ),
					'noResults' => __( 'No results', 'internal-link-builder' ),
				),
			)
		);
	}

	/**
	 * Returns the currently active tab from the query string.
	 *
	 * @return string
	 */
	private function current_tab() {
		$tabs = ILB_Settings::tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return array_key_exists( $tab, $tabs ) ? $tab : 'general';
	}

	/**
	 * Renders the settings page shell with tabs.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs        = ILB_Settings::tabs();
		$current_tab = $this->current_tab();
		?>
		<div class="wrap ilb-wrap">
			<h1><?php esc_html_e( 'Internal Link Builder', 'internal-link-builder' ); ?></h1>

			<h2 class="nav-tab-wrapper ilb-tabs">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a
						href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . $slug ) ); ?>"
						class="nav-tab <?php echo $slug === $current_tab ? 'nav-tab-active' : ''; ?>"
					>
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<?php if ( 'actions' === $current_tab ) : ?>
				<?php $this->render_actions_tab(); ?>
			<?php else : ?>
				<form method="post" action="options.php" class="ilb-form">
					<?php
					settings_fields( ILB_Settings::OPTION_GROUP );
					$this->render_fields_tab( $current_tab );
					submit_button();
					?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders all fields for a given settings tab.
	 *
	 * @param string $tab Tab slug.
	 */
	private function render_fields_tab( $tab ) {
		$fields = $this->settings->fields();
		if ( empty( $fields[ $tab ] ) ) {
			return;
		}

		$values = $this->settings->all();

		echo '<table class="form-table ilb-form-table" role="presentation"><tbody>';
		foreach ( $fields[ $tab ] as $key => $field ) {
			$this->render_field_row( $key, $field, $values );
		}
		echo '</tbody></table>';
	}

	/**
	 * Renders a single settings field row.
	 *
	 * @param string $key    Field key.
	 * @param array  $field  Field definition.
	 * @param array  $values Current values.
	 */
	private function render_field_row( $key, array $field, array $values ) {
		$value     = isset( $values[ $key ] ) ? $values[ $key ] : ( isset( $field['default'] ) ? $field['default'] : '' );
		$row_attrs = '';
		if ( ! empty( $field['depends_on'] ) ) {
			$row_attrs = ' data-depends-on="' . esc_attr( $field['depends_on'] ) . '"';
		}
		?>
		<tr<?php echo $row_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<th scope="row">
				<label for="ilb-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
			</th>
			<td>
				<?php $this->render_control( $key, $field, $value ); ?>
				<?php if ( ! empty( $field['description'] ) ) : ?>
					<p class="description"><?php echo wp_kses( $field['description'], $this->allowed_description_html() ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders the input control for a field, dispatching on type.
	 *
	 * @param string $key   Field key.
	 * @param array  $field Field definition.
	 * @param mixed  $value Current value.
	 */
	private function render_control( $key, array $field, $value ) {
		$name = ILB_SETTINGS_OPTION . '[' . $key . ']';
		$id   = 'ilb-' . $key;
		$type = isset( $field['type'] ) ? $field['type'] : 'text';

		switch ( $type ) {
			case 'toggle':
				$this->render_toggle( $name, $id, $value );
				break;

			case 'number':
				$this->render_number( $name, $id, $field, $value );
				break;

			case 'select':
				$this->render_select( $name, $id, $field, $value );
				break;

			case 'multiselect':
				$this->render_multiselect( $name, $id, $field, $value );
				break;

			case 'multicheck':
				$this->render_multicheck( $name, $field, $value );
				break;

			case 'token':
				$this->render_token( $name, $id, $field, $value );
				break;

			case 'repeatable':
				$this->render_repeatable( $name, $id, $field, $value );
				break;

			case 'textarea':
				$this->render_textarea( $name, $id, $value );
				break;

			case 'text':
			default:
				printf(
					'<input type="text" class="regular-text" id="%1$s" name="%2$s" value="%3$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				break;
		}
	}

	/**
	 * Renders a yes/no toggle switch.
	 *
	 * @param string $name  Field name.
	 * @param string $id    Field id.
	 * @param mixed  $value Current value.
	 */
	private function render_toggle( $name, $id, $value ) {
		?>
		<label class="ilb-switch">
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0" />
			<input
				type="checkbox"
				id="<?php echo esc_attr( $id ); ?>"
				name="<?php echo esc_attr( $name ); ?>"
				value="1"
				data-toggle-key="<?php echo esc_attr( str_replace( array( ILB_SETTINGS_OPTION . '[', ']' ), '', $name ) ); ?>"
				<?php checked( ! empty( $value ) ); ?>
			/>
			<span class="ilb-switch-slider" aria-hidden="true"></span>
		</label>
		<?php
	}

	/**
	 * Renders a number input.
	 *
	 * @param string $name  Field name.
	 * @param string $id    Field id.
	 * @param array  $field Field definition.
	 * @param mixed  $value Current value.
	 */
	private function render_number( $name, $id, array $field, $value ) {
		printf(
			'<input type="number" class="small-text" id="%1$s" name="%2$s" value="%3$s"%4$s%5$s%6$s />',
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( (string) $value ),
			isset( $field['min'] ) ? ' min="' . esc_attr( $field['min'] ) . '"' : '',
			isset( $field['max'] ) ? ' max="' . esc_attr( $field['max'] ) . '"' : '',
			isset( $field['step'] ) ? ' step="' . esc_attr( $field['step'] ) . '"' : ''
		);
	}

	/**
	 * Renders a single-select dropdown.
	 *
	 * @param string $name  Field name.
	 * @param string $id    Field id.
	 * @param array  $field Field definition.
	 * @param mixed  $value Current value.
	 */
	private function render_select( $name, $id, array $field, $value ) {
		$options = $this->settings->resolve_options( $field );
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $options as $opt_value => $opt_label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $opt_value ),
				selected( (string) $value, (string) $opt_value, false ),
				esc_html( $opt_label )
			);
		}
		echo '</select>';
	}

	/**
	 * Renders a multi-select list.
	 *
	 * @param string $name  Field name.
	 * @param string $id    Field id.
	 * @param array  $field Field definition.
	 * @param mixed  $value Current values.
	 */
	private function render_multiselect( $name, $id, array $field, $value ) {
		$options  = $this->settings->resolve_options( $field );
		$selected = is_array( $value ) ? $value : array();
		echo '<select multiple class="ilb-multiselect" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '[]" size="' . esc_attr( min( 8, max( 4, count( $options ) ) ) ) . '">';
		foreach ( $options as $opt_value => $opt_label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $opt_value ),
				in_array( (string) $opt_value, array_map( 'strval', $selected ), true ) ? ' selected="selected"' : '',
				esc_html( $opt_label )
			);
		}
		echo '</select>';
	}

	/**
	 * Renders a list of checkboxes.
	 *
	 * @param string $name  Field name.
	 * @param array  $field Field definition.
	 * @param mixed  $value Current values.
	 */
	private function render_multicheck( $name, array $field, $value ) {
		$options  = $this->settings->resolve_options( $field );
		$selected = is_array( $value ) ? array_map( 'strval', $value ) : array();
		echo '<fieldset class="ilb-multicheck">';
		foreach ( $options as $opt_value => $opt_label ) {
			printf(
				'<label><input type="checkbox" name="%1$s[]" value="%2$s"%3$s /> %4$s</label>',
				esc_attr( $name ),
				esc_attr( $opt_value ),
				in_array( (string) $opt_value, $selected, true ) ? ' checked="checked"' : '',
				esc_html( $opt_label )
			);
		}
		echo '</fieldset>';
	}

	/**
	 * Renders a token / chip multi-select with typeahead.
	 *
	 * Emits a container the JS component (token-select.js) enhances. The current
	 * selection is seeded as JSON so labels appear without an extra round-trip.
	 *
	 * @param string $name  Field name.
	 * @param string $id    Field id.
	 * @param array  $field Field definition.
	 * @param mixed  $value Current values.
	 */
	private function render_token( $name, $id, array $field, $value ) {
		$mode        = isset( $field['token_mode'] ) ? $field['token_mode'] : 'freeform';
		$source      = isset( $field['token_source'] ) ? $field['token_source'] : '';
		$placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : '';
		$selected    = is_array( $value ) ? $value : array();

		$selected_data = $this->token_selected_data( $field, $selected );
		$options_data  = ( 'static' === $mode ) ? $this->token_options_data( $field ) : array();
		?>
		<div
			class="ilb-token"
			id="<?php echo esc_attr( $id ); ?>"
			data-name="<?php echo esc_attr( $name ); ?>"
			data-mode="<?php echo esc_attr( $mode ); ?>"
			data-source="<?php echo esc_attr( $source ); ?>"
			data-placeholder="<?php echo esc_attr( $placeholder ); ?>"
			data-selected="<?php echo esc_attr( wp_json_encode( $selected_data ) ); ?>"
			data-options="<?php echo esc_attr( wp_json_encode( $options_data ) ); ?>"
		>
			<noscript>
				<?php
				// Fallback for no-JS: a plain comma-separated readout.
				$labels = wp_list_pluck( $selected_data, 'text' );
				echo esc_html( implode( ', ', $labels ) );
				?>
			</noscript>
		</div>
		<?php
	}

	/**
	 * Builds the [{id,text}] list for the currently selected token values.
	 *
	 * @param array $field    Field definition.
	 * @param array $selected Stored values.
	 * @return array[]
	 */
	private function token_selected_data( array $field, array $selected ) {
		$source = isset( $field['token_source'] ) ? $field['token_source'] : '';
		$mode   = isset( $field['token_mode'] ) ? $field['token_mode'] : 'freeform';
		$data   = array();

		if ( 'ajax' === $mode && 'post' === $source ) {
			foreach ( $selected as $id ) {
				$post = get_post( (int) $id );
				if ( $post instanceof WP_Post ) {
					$data[] = array(
						'id'   => (int) $id,
						'text' => $this->token_label( get_the_title( $post ), (int) $id ),
					);
				}
			}
			return $data;
		}

		if ( 'ajax' === $mode && 'term' === $source ) {
			foreach ( $selected as $id ) {
				$term = get_term( (int) $id );
				if ( $term instanceof WP_Term ) {
					$data[] = array(
						'id'   => (int) $id,
						'text' => $this->token_label( $term->name, (int) $id ),
					);
				}
			}
			return $data;
		}

		if ( 'static' === $mode ) {
			$options = $this->settings->resolve_options( $field );
			foreach ( $selected as $slug ) {
				if ( isset( $options[ $slug ] ) ) {
					$data[] = array(
						'id'   => (string) $slug,
						'text' => $options[ $slug ],
					);
				}
			}
			return $data;
		}

		// Freeform: the value is its own label.
		foreach ( $selected as $text ) {
			$data[] = array(
				'id'   => (string) $text,
				'text' => (string) $text,
			);
		}

		return $data;
	}

	/**
	 * Builds the [{id,text}] option list for a static token field.
	 *
	 * @param array $field Field definition.
	 * @return array[]
	 */
	private function token_options_data( array $field ) {
		$options = $this->settings->resolve_options( $field );
		$data    = array();
		foreach ( $options as $slug => $label ) {
			$data[] = array(
				'id'   => (string) $slug,
				'text' => $label,
			);
		}

		return $data;
	}

	/**
	 * Renders a repeatable list of single-line text inputs.
	 *
	 * @param string $name  Field name.
	 * @param string $id    Field id.
	 * @param array  $field Field definition.
	 * @param mixed  $value Current values.
	 */
	private function render_repeatable( $name, $id, array $field, $value ) {
		$lines       = is_array( $value ) ? $value : array();
		$placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : '';
		?>
		<div class="ilb-repeatable" id="<?php echo esc_attr( $id ); ?>" data-name="<?php echo esc_attr( $name ); ?>" data-placeholder="<?php echo esc_attr( $placeholder ); ?>">
			<div class="ilb-repeatable-rows">
				<?php if ( empty( $lines ) ) : ?>
					<div class="ilb-repeatable-row">
						<input type="text" name="<?php echo esc_attr( $name ); ?>[]" value="" placeholder="<?php echo esc_attr( $placeholder ); ?>" />
						<button type="button" class="button-link ilb-repeatable-remove" aria-label="<?php esc_attr_e( 'Remove', 'internal-link-builder' ); ?>">&times;</button>
					</div>
				<?php else : ?>
					<?php foreach ( $lines as $line ) : ?>
						<div class="ilb-repeatable-row">
							<input type="text" name="<?php echo esc_attr( $name ); ?>[]" value="<?php echo esc_attr( $line ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" />
							<button type="button" class="button-link ilb-repeatable-remove" aria-label="<?php esc_attr_e( 'Remove', 'internal-link-builder' ); ?>">&times;</button>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
			<button type="button" class="button ilb-repeatable-add"><?php esc_html_e( 'Add line', 'internal-link-builder' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Renders a textarea.
	 *
	 * @param string $name  Field name.
	 * @param string $id    Field id.
	 * @param mixed  $value Current value.
	 */
	private function render_textarea( $name, $id, $value ) {
		printf(
			'<textarea class="large-text code" rows="3" id="%1$s" name="%2$s">%3$s</textarea>',
			esc_attr( $id ),
			esc_attr( $name ),
			esc_textarea( (string) $value )
		);
	}

	/**
	 * Renders the Actions tab (maintenance tools, not stored fields).
	 */
	private function render_actions_tab() {
		$cache_field = $this->settings->action_fields()['cache'];
		$cache_value = $this->settings->get( 'cache' );
		?>
		<form method="post" action="options.php" class="ilb-form">
			<?php settings_fields( ILB_Settings::OPTION_GROUP ); ?>
			<table class="form-table ilb-form-table" role="presentation"><tbody>
				<?php $this->render_field_row( 'cache', $cache_field, array( 'cache' => $cache_value ) ); ?>
			</tbody></table>
			<?php submit_button(); ?>
		</form>

		<hr />

		<table class="form-table ilb-form-table ilb-actions-table" role="presentation"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Cancel schedules', 'internal-link-builder' ); ?></th>
				<td>
					<button type="button" class="button ilb-action-button" data-action="ilb_cancel_schedules">
						<?php esc_html_e( 'Cancel all pending actions', 'internal-link-builder' ); ?>
					</button>
					<span class="ilb-action-result" aria-live="polite"></span>
					<p class="description"><?php esc_html_e( 'Cancels all pending scheduled actions for the index generation.', 'internal-link-builder' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Fix collations', 'internal-link-builder' ); ?></th>
				<td>
					<button type="button" class="button ilb-action-button" data-action="ilb_fix_collations">
						<?php esc_html_e( 'Fix collations', 'internal-link-builder' ); ?>
					</button>
					<span class="ilb-action-result" aria-live="polite"></span>
					<p class="description"><?php esc_html_e( 'In some cases the statistics tables show empty and database columns do not match with each other. This tool can fix the issue.', 'internal-link-builder' ); ?></p>
				</td>
			</tr>
		</tbody></table>
		<?php
	}

	/**
	 * Allowed HTML in field descriptions.
	 *
	 * @return array
	 */
	private function allowed_description_html() {
		return array(
			'code'   => array(),
			'strong' => array(),
			'em'     => array(),
			'a'      => array(
				'href'   => array(),
				'target' => array(),
				'rel'    => array(),
			),
		);
	}
}
