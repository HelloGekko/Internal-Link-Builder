<?php
/**
 * Per-post keyword metabox.
 *
 * Adds the Internal Link Builder configuration box to the edit screen of every
 * whitelisted post type, with tabs for keywords and per-target settings.
 *
 * @package InternalLinkBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ILB_Metabox
 */
class ILB_Metabox {

	/**
	 * Nonce action.
	 */
	const NONCE_ACTION = 'ilb_save_keywords';

	/**
	 * Nonce request field.
	 */
	const NONCE_FIELD = 'ilb_keywords_nonce';

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

		add_action( 'add_meta_boxes', array( $this, 'register' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Returns the post types the metabox should appear on.
	 *
	 * @return string[]
	 */
	private function enabled_post_types() {
		$types = $this->settings->get( 'whitelist_post_types' );

		return is_array( $types ) ? $types : array();
	}

	/**
	 * Registers the metabox on every whitelisted post type.
	 */
	public function register() {
		if ( ! $this->keywords->current_user_can_edit() ) {
			return;
		}

		foreach ( $this->enabled_post_types() as $post_type ) {
			add_meta_box(
				'ilb-keywords',
				__( 'Internal Link Builder', 'internal-link-builder' ),
				array( $this, 'render' ),
				$post_type,
				'normal',
				'default'
			);
		}
	}

	/**
	 * Enqueues metabox assets on edit screens for whitelisted post types.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, $this->enabled_post_types(), true ) ) {
			return;
		}

		wp_enqueue_style(
			'ilb-metabox',
			ILB_PLUGIN_URL . 'assets/css/metabox.css',
			array(),
			ILB_VERSION
		);

		wp_enqueue_script(
			'ilb-metabox',
			ILB_PLUGIN_URL . 'assets/js/metabox.js',
			array( 'jquery' ),
			ILB_VERSION,
			true
		);

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
	 * Renders the metabox.
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render( $post ) {
		$keywords          = $this->keywords->get_keywords( $post->ID, 'post' );
		$overrides         = $this->keywords->get_target_settings( $post->ID, 'post' );
		$content_blacklist = $this->keywords->get_content_blacklist( $post->ID, 'post' );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<div class="ilb-metabox">
			<ul class="ilb-mb-tabs">
				<li><a href="#ilb-mb-keywords" class="ilb-mb-tab is-active"><?php esc_html_e( 'Keywords', 'internal-link-builder' ); ?></a></li>
				<li><a href="#ilb-mb-settings" class="ilb-mb-tab"><?php esc_html_e( 'Settings', 'internal-link-builder' ); ?></a></li>
			</ul>

			<div id="ilb-mb-keywords" class="ilb-mb-panel is-active">
				<p class="description">
					<?php esc_html_e( 'Keywords configured here cause other posts to link to this one whenever the keyword appears in their content. The found keyword is used as the anchor text.', 'internal-link-builder' ); ?>
				</p>
				<?php
				$this->render_repeatable(
					'ilb_meta[keywords]',
					$keywords,
					__( 'Keyword', 'internal-link-builder' )
				);
				?>

				<h4 class="ilb-mb-subheading"><?php esc_html_e( 'Keywords that don\'t get linked in the current content', 'internal-link-builder' ); ?></h4>
				<p class="description"><?php esc_html_e( 'These keywords will not be turned into links within this post\'s content, even if other posts have configured them.', 'internal-link-builder' ); ?></p>
				<?php
				$this->render_repeatable(
					'ilb_meta[content_blacklist]',
					$content_blacklist,
					__( 'Keyword', 'internal-link-builder' ),
					'ilb-content-blacklist'
				);
				?>

				<h4 class="ilb-mb-subheading"><?php esc_html_e( 'Configured keyword blacklist', 'internal-link-builder' ); ?></h4>
				<p class="description"><?php esc_html_e( 'Overview of the keywords currently blocked from linking in this content.', 'internal-link-builder' ); ?></p>
				<div class="ilb-mb-overview" id="ilb-mb-overview" data-source="ilb-content-blacklist">
					<?php if ( empty( $content_blacklist ) ) : ?>
						<em><?php esc_html_e( 'No keywords are blocked in this content.', 'internal-link-builder' ); ?></em>
					<?php else : ?>
						<ul>
							<?php foreach ( $content_blacklist as $blocked ) : ?>
								<li><code><?php echo esc_html( $blocked ); ?></code></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			</div>

			<div id="ilb-mb-settings" class="ilb-mb-panel">
				<?php
				$this->render_toggle( 'on_global_blacklist', __( 'Is on global blacklist', 'internal-link-builder' ), $overrides['on_global_blacklist'] );
				$this->render_toggle( 'limit_links_per_paragraph', __( 'Limit links per paragraph', 'internal-link-builder' ), $overrides['limit_links_per_paragraph'] );
				$this->render_toggle( 'limit_incoming_links', __( 'Limit incoming links', 'internal-link-builder' ), $overrides['limit_incoming_links'] );
				$this->render_toggle( 'limit_outgoing_links', __( 'Limit outgoing links', 'internal-link-builder' ), $overrides['limit_outgoing_links'] );
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders a repeatable list of single-line inputs.
	 *
	 * @param string   $name        Field name (without [] suffix).
	 * @param string[] $values      Current values.
	 * @param string   $placeholder Input placeholder.
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
	 * Renders a per-target override toggle.
	 *
	 * @param string $key   Override key.
	 * @param string $label Label.
	 * @param int    $value Current value.
	 */
	private function render_toggle( $key, $label, $value ) {
		$name = 'ilb_meta[settings][' . $key . ']';
		$id   = 'ilb-mb-' . $key;
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
	 * Saves the metabox values on post save.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save( $post_id, $post ) {
		// Bail on autosave / revisions.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Only act when our metabox was actually rendered.
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		// Capability: both the post and the keyword-editing minimum role.
		if ( ! current_user_can( 'edit_post', $post_id ) || ! $this->keywords->current_user_can_edit() ) {
			return;
		}

		if ( ! in_array( $post->post_type, $this->enabled_post_types(), true ) ) {
			return;
		}

		$raw = isset( $_POST['ilb_meta'] ) ? wp_unslash( $_POST['ilb_meta'] ) : array(); // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized -- sanitised in ILB_Keywords::save().

		$this->keywords->save(
			$post_id,
			'post',
			array(
				'keywords'          => isset( $raw['keywords'] ) ? (array) $raw['keywords'] : array(),
				'settings'          => isset( $raw['settings'] ) ? (array) $raw['settings'] : array(),
				'content_blacklist' => isset( $raw['content_blacklist'] ) ? (array) $raw['content_blacklist'] : array(),
			)
		);
	}
}
