<?php
/**
 * Advanced Custom Fields integration.
 *
 * Links keywords inside ACF field values by hooking ACF's own
 * `acf/format_value/type={type}` filters, which run when a field value is
 * formatted for display (get_field/the_field). Because the hook is based on
 * the field TYPE rather than the raw meta key, fields inside repeaters, groups
 * and flexible content are covered automatically.
 *
 * The filters are only registered when ACF is active (acf/init) and the
 * feature is enabled in the plugin settings.
 *
 * @package InternalLinkBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ILB_ACF
 */
class ILB_ACF {

	/**
	 * Settings handler.
	 *
	 * @var ILB_Settings
	 */
	private $settings;

	/**
	 * Linking engine.
	 *
	 * @var ILB_Engine
	 */
	private $engine;

	/**
	 * Constructor.
	 *
	 * @param ILB_Settings $settings Settings handler.
	 * @param ILB_Engine   $engine   Linking engine.
	 */
	public function __construct( ILB_Settings $settings, ILB_Engine $engine ) {
		$this->settings = $settings;
		$this->engine   = $engine;
	}

	/**
	 * Registers the ACF bootstrap hook. Does nothing when ACF never loads.
	 */
	public function hooks() {
		add_action( 'acf/init', array( $this, 'register_filters' ) );
	}

	/**
	 * Returns the ACF field types that can be linked.
	 *
	 * @return array<string,string> Type slug => label.
	 */
	public static function field_type_options() {
		return array(
			'text'     => __( 'Text', 'internal-link-builder' ),
			'textarea' => __( 'Textarea', 'internal-link-builder' ),
			'wysiwyg'  => __( 'WYSIWYG editor', 'internal-link-builder' ),
		);
	}

	/**
	 * Hooks the format_value filters for the configured field types.
	 *
	 * Runs on acf/init, so this only ever executes when ACF is active.
	 */
	public function register_filters() {
		if ( ! $this->settings->get( 'enable_acf_linking' ) ) {
			return;
		}

		// Universal mode already processes the whole rendered page.
		if ( 'universal' === $this->settings->get( 'processing_mode' ) ) {
			return;
		}

		$allowed = array_keys( self::field_type_options() );
		$types   = (array) $this->settings->get( 'acf_field_types' );

		foreach ( $types as $type ) {
			if ( ! in_array( $type, $allowed, true ) ) {
				continue;
			}

			// Priority 20: after ACF's own formatting (wpautop for wysiwyg,
			// new_lines handling for textarea) so we operate on the final HTML.
			add_filter( 'acf/format_value/type=' . $type, array( $this, 'link_value' ), 20, 3 );
		}
	}

	/**
	 * Links keywords inside a formatted ACF field value.
	 *
	 * @param mixed      $value   Formatted field value.
	 * @param int|string $post_id ACF object ID (post ID, "term_123", "user_5", "option", ...).
	 * @param array      $field   ACF field definition.
	 * @return mixed
	 */
	public function link_value( $value, $post_id, $field ) {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $value;
		}

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return $value;
		}

		$source = self::parse_source( $post_id );
		if ( ! $source ) {
			return $value;
		}

		/**
		 * Filters whether a specific ACF field should get automatic links.
		 *
		 * @param bool       $should_link Whether to link this field. Default true.
		 * @param array      $field       ACF field definition.
		 * @param int|string $post_id     ACF object ID.
		 */
		if ( ! apply_filters( 'ilb_acf_link_field', true, $field, $post_id ) ) {
			return $value;
		}

		return $this->engine->link_source_content( $value, $source['id'], $source['type'] );
	}

	/**
	 * Maps an ACF object ID to a plugin source descriptor.
	 *
	 * ACF passes posts as numeric IDs, terms as "term_{id}" (or the legacy
	 * "{taxonomy}_{id}" form) and other objects as "user_{id}", "option" etc.
	 * Only posts and terms participate in linking.
	 *
	 * @param int|string $post_id ACF object ID.
	 * @return array|null Source descriptor (id, type) or null when unsupported.
	 */
	public static function parse_source( $post_id ) {
		if ( is_numeric( $post_id ) ) {
			$id = (int) $post_id;

			return $id > 0 ? array(
				'id'   => $id,
				'type' => 'post',
			) : null;
		}

		if ( ! is_string( $post_id ) || ! preg_match( '/^([a-z0-9_-]+)_(\d+)$/i', $post_id, $matches ) ) {
			return null;
		}

		$prefix = strtolower( $matches[1] );
		$id     = (int) $matches[2];

		if ( $id <= 0 ) {
			return null;
		}

		// Modern term form ("term_123") or legacy "{taxonomy}_{id}" form.
		if ( 'term' === $prefix || taxonomy_exists( $prefix ) ) {
			return array(
				'id'   => $id,
				'type' => 'term',
			);
		}

		// Users, options pages, blocks, comments etc. are not link sources.
		return null;
	}
}
