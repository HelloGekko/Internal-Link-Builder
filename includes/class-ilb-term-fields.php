<?php
/**
 * Per-term keyword fields.
 *
 * Adds the same keyword configuration as the post metabox to the add/edit
 * screens of every whitelisted taxonomy.
 *
 * @package InternalLinkBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ILB_Term_Fields
 */
class ILB_Term_Fields {

	/**
	 * Nonce action.
	 */
	const NONCE_ACTION = 'ilb_save_term_keywords';

	/**
	 * Nonce request field.
	 */
	const NONCE_FIELD = 'ilb_term_keywords_nonce';

	/**
	 * Settings handler.
	 *
	 * @var ILB_Settings
	 */
	private $settings;

	/**
	 * Keyword storage handler.
	 *
	 * @var ILB_Keywords
	 */
	private $keywords;

	/**
	 * Constructor.
	 *
	 * @param ILB_Settings $settings Settings handler.
	 * @param ILB_Keywords $keywords Keyword storage handler.
	 */
	public function __construct( ILB_Settings $settings, ILB_Keywords $keywords ) {
		$this->settings = $settings;
		$this->keywords = $keywords;

		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Returns the taxonomies the fields should appear on.
	 *
	 * @return string[]
	 */
	private function enabled_taxonomies() {
		$taxonomies = $this->settings->get( 'whitelist_taxonomies' );

		return is_array( $taxonomies ) ? $taxonomies : array();
	}

	/**
	 * Hooks the add/edit/save callbacks for each whitelisted taxonomy.
	 */
	public function register() {
		if ( ! $this->keywords->current_user_can_edit() ) {
			return;
		}

		foreach ( $this->enabled_taxonomies() as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			add_action( $taxonomy . '_add_form_fields', array( $this, 'render_add_fields' ) );
			add_action( $taxonomy . '_edit_form_fields', array( $this, 'render_edit_fields' ), 10, 2 );
			add_action( 'created_' . $taxonomy, array( $this, 'save' ) );
			add_action( 'edited_' . $taxonomy, array( $this, 'save' ) );
		}
	}

	/**
	 * Enqueues the shared metabox assets on taxonomy screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'edit-tags.php', 'term.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->taxonomy, $this->enabled_taxonomies(), true ) ) {
			return;
		}

		wp_enqueue_style( 'ilb-metabox', ILB_PLUGIN_URL . 'assets/css/metabox.css', array(), ILB_VERSION );
		wp_enqueue_script( 'ilb-metabox', ILB_PLUGIN_URL . 'assets/js/metabox.js', array( 'jquery' ), ILB_VERSION, true );
		wp_localize_script(
			'ilb-metabox',
			'ilbMetabox',
			array(
				'i18n' => array(
					'remove'    => __( 'Remove', 'internal-link-builder' ),
					'noBlocked' => __( 'No keywords are blocked in this content.', 'internal-link-builder' ),
				),
			)
		);
	}

	/**
	 * Renders the fields on the "add new term" screen.
	 */
	public function render_add_fields() {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<div class="form-field ilb-metabox">
			<label><?php esc_html_e( 'Internal Link Builder keywords', 'internal-link-builder' ); ?></label>
			<?php $this->render_repeatable( 'ilb_meta[keywords]', array(), __( 'Keyword', 'internal-link-builder' ) ); ?>
			<p class="description"><?php esc_html_e( 'Other content will link to this term when these keywords appear in it.', 'internal-link-builder' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Renders the fields on the "edit term" screen.
	 *
	 * @param WP_Term $term Current term.
	 */
	public function render_edit_fields( $term ) {
		$keywords          = $this->keywords->get_keywords( $term->term_id, 'term' );
		$overrides         = $this->keywords->get_target_settings( $term->term_id, 'term' );
		$content_blacklist = $this->keywords->get_content_blacklist( $term->term_id, 'term' );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<tr class="form-field">
			<th scope="row"><label><?php esc_html_e( 'Link Builder keywords', 'internal-link-builder' ); ?></label></th>
			<td class="ilb-metabox">
				<p class="description"><?php esc_html_e( 'Other content will link to this term when these keywords appear in it. The found keyword is used as the anchor text.', 'internal-link-builder' ); ?></p>
				<?php $this->render_repeatable( 'ilb_meta[keywords]', $keywords, __( 'Keyword', 'internal-link-builder' ) ); ?>

				<h4 class="ilb-mb-subheading"><?php esc_html_e( 'Keywords that don\'t get linked in this term\'s description', 'internal-link-builder' ); ?></h4>
				<?php $this->render_repeatable( 'ilb_meta[content_blacklist]', $content_blacklist, __( 'Keyword', 'internal-link-builder' ), 'ilb-content-blacklist' ); ?>

				<h4 class="ilb-mb-subheading"><?php esc_html_e( 'Settings', 'internal-link-builder' ); ?></h4>
				<?php
				$this->render_toggle( 'on_global_blacklist', __( 'Is on global blacklist', 'internal-link-builder' ), $overrides['on_global_blacklist'] );
				$this->render_toggle( 'limit_links_per_paragraph', __( 'Limit links per paragraph', 'internal-link-builder' ), $overrides['limit_links_per_paragraph'] );
				$this->render_toggle( 'limit_incoming_links', __( 'Limit incoming links', 'internal-link-builder' ), $overrides['limit_incoming_links'] );
				$this->render_toggle( 'limit_outgoing_links', __( 'Limit outgoing links', 'internal-link-builder' ), $overrides['limit_outgoing_links'] );
				?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders a repeatable list of single-line inputs.
	 *
	 * @param string   $name        Field name (without [] suffix).
	 * @param string[] $values      Current values.
	 * @param string   $placeholder Placeholder.
	 * @param string   $extra_class Optional wrapper class.
	 */
	private function render_repeatable( $name, array $values, $placeholder, $extra_class = '' ) {
		if ( empty( $values ) ) {
			$values = array( '' );
		}
		?>
		<div class="ilb-repeatable <?php echo esc_attr( $extra_class ); ?>" data-name="<?php echo esc_attr( $name ); ?>" data-placeholder="<?php echo esc_attr( $placeholder ); ?>">
			<div class="ilb-repeatable-rows">
				<?php foreach ( $values as $value ) : ?>
					<div class="ilb-repeatable-row">
						<input type="text" name="<?php echo esc_attr( $name ); ?>[]" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" />
						<button type="button" class="button-link ilb-repeatable-remove" aria-label="<?php esc_attr_e( 'Remove', 'internal-link-builder' ); ?>">&times;</button>
					</div>
				<?php endforeach; ?>
			</div>
			<button type="button" class="button ilb-repeatable-add"><?php esc_html_e( 'Add line', 'internal-link-builder' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Renders an override toggle.
	 *
	 * @param string $key   Override key.
	 * @param string $label Label.
	 * @param int    $value Current value.
	 */
	private function render_toggle( $key, $label, $value ) {
		$name = 'ilb_meta[settings][' . $key . ']';
		$id   = 'ilb-term-' . $key;
		?>
		<p class="ilb-mb-toggle-row">
			<label class="ilb-switch">
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0" />
				<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( ! empty( $value ) ); ?> />
				<span class="ilb-switch-slider" aria-hidden="true"></span>
			</label>
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
		</p>
		<?php
	}

	/**
	 * Saves the term keyword configuration.
	 *
	 * @param int $term_id Term ID.
	 */
	public function save( $term_id ) {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_categories' ) || ! $this->keywords->current_user_can_edit() ) {
			return;
		}

		$raw = isset( $_POST['ilb_meta'] ) ? wp_unslash( $_POST['ilb_meta'] ) : array(); // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized -- sanitised in ILB_Keywords::save().

		$this->keywords->save(
			$term_id,
			'term',
			array(
				'keywords'          => isset( $raw['keywords'] ) ? (array) $raw['keywords'] : array(),
				'settings'          => isset( $raw['settings'] ) ? (array) $raw['settings'] : array(),
				'content_blacklist' => isset( $raw['content_blacklist'] ) ? (array) $raw['content_blacklist'] : array(),
			)
		);
	}
}
