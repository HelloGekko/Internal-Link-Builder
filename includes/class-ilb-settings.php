<?php
/**
 * Settings storage, schema and sanitization.
 *
 * All plugin settings live in a single option (ILB_SETTINGS_OPTION) as an
 * associative array. The field schema returned by {@see ILB_Settings::fields()}
 * is the single source of truth: defaults, sanitization and admin rendering all
 * derive from it.
 *
 * @package InternalLinkBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ILB_Settings
 */
class ILB_Settings {

	/**
	 * Settings API option group.
	 */
	const OPTION_GROUP = 'ilb_settings_group';

	/**
	 * Cached merged settings (defaults + stored).
	 *
	 * @var array|null
	 */
	private $cache = null;

	/**
	 * Registers the WordPress hooks for this component.
	 *
	 * Kept out of the constructor so the schema/defaults helpers can be
	 * instantiated cheaply (e.g. from {@see ILB_Settings::defaults()}) without
	 * attaching duplicate hooks.
	 */
	public function hooks() {
		add_action( 'admin_init', array( $this, 'register' ) );

		// Keep the in-memory cache in sync when the option changes.
		add_action( 'add_option_' . ILB_SETTINGS_OPTION, array( $this, 'flush_cache' ) );
		add_action( 'update_option_' . ILB_SETTINGS_OPTION, array( $this, 'flush_cache' ) );
	}

	/**
	 * Clears the in-memory settings cache.
	 */
	public function flush_cache() {
		$this->cache = null;
	}

	/*
	 * -------------------------------------------------------------------------
	 * Schema
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Returns the available admin tabs.
	 *
	 * @return array<string,string> Tab slug => label.
	 */
	public static function tabs() {
		return array(
			'general' => __( 'General', 'internal-link-builder' ),
			'content' => __( 'Content', 'internal-link-builder' ),
			'links'   => __( 'Links', 'internal-link-builder' ),
			'actions' => __( 'Actions', 'internal-link-builder' ),
		);
	}

	/**
	 * Returns the full field schema, grouped by tab.
	 *
	 * Each field is an associative array describing how it is stored, validated
	 * and rendered. Recognised keys:
	 *  - type:        toggle|number|select|multiselect|multicheck|text|textarea|repeatable
	 *  - label:       human readable label
	 *  - description: help text shown below the control
	 *  - default:     default value
	 *  - options:     array (value => label) or a callable returning one
	 *  - min/max/step: number constraints
	 *  - depends_on:  key of a toggle that must be enabled for this field to apply
	 *  - placeholder: optional input placeholder
	 *
	 * @return array<string,array> Tab slug => list of fields.
	 */
	public function fields() {
		return array(
			'general' => $this->general_fields(),
			'content' => $this->content_fields(),
			'links'   => $this->links_fields(),
		);
	}

	/**
	 * General tab fields.
	 *
	 * @return array
	 */
	private function general_fields() {
		return array(
			'keep_data_on_uninstall' => array(
				'type'        => 'toggle',
				'label'       => __( 'Keep configured keywords and plugin settings after plugin uninstall', 'internal-link-builder' ),
				'description' => __( 'If activated, all your configured keywords and your plugin settings will remain saved. If not, everything from Internal Link Builder gets deleted when you uninstall the plugin.', 'internal-link-builder' ),
				'default'     => 0,
			),
			'hide_admin_bar'         => array(
				'type'        => 'toggle',
				'label'       => __( 'Hide the link index indicator from the WordPress admin bar', 'internal-link-builder' ),
				'description' => __( 'If activated, our admin bar entry will be disabled.', 'internal-link-builder' ),
				'default'     => 0,
			),
			'batch_size'             => array(
				'type'        => 'number',
				'label'       => __( 'Action Scheduler batch size', 'internal-link-builder' ),
				'description' => __( 'Configure according to your environment CPU and RAM availability. Small value: for resource-limited servers set a value close to 1. Large value: with plenty of RAM and CPU you can use up to 250.', 'internal-link-builder' ),
				'default'     => 30,
				'min'         => 1,
				'max'         => 250,
				'step'        => 1,
			),
			'min_role_edit_keywords' => array(
				'type'        => 'select',
				'label'       => __( 'Minimum required user role for editing keywords', 'internal-link-builder' ),
				'description' => __( 'The minimum required capability to edit keywords.', 'internal-link-builder' ),
				'default'     => 'editor',
				'options'     => array( $this, 'role_options' ),
			),
			'index_generation_mode'  => array(
				'type'        => 'select',
				'label'       => __( 'Index generation mode', 'internal-link-builder' ),
				'description' => __( 'Choose your preferred approach for generating the index. None: the index is not created by the plugin (you should set up a cronjob). Automatic: any change affecting the index automatically updates the index.', 'internal-link-builder' ),
				'default'     => 'automatic',
				'options'     => array(
					'none'      => __( 'None', 'internal-link-builder' ),
					'automatic' => __( 'Automatic', 'internal-link-builder' ),
				),
			),
		);
	}

	/**
	 * Content tab fields.
	 *
	 * @return array
	 */
	private function content_fields() {
		return array(
			'universal_selector'        => array(
				'type'        => 'text',
				'label'       => __( 'Content region', 'internal-link-builder' ),
				'description' => __( 'Optional. The plugin links keywords inside the rendered content region of each page. Comma-separated list of simple selectors (tag, #id or .class), e.g. "main, #content, .entry-content". Leave empty for automatic detection (main, [role=main], #main, #content, #primary, then body). Navigation, header, footer and form elements are never linked.', 'internal-link-builder' ),
				'default'     => '',
				'placeholder' => 'main, #content, .entry-content',
			),
			'whitelist_post_types'      => array(
				'type'        => 'token',
				'token_mode'  => 'static',
				'token_value' => 'slug',
				'label'       => __( 'Whitelist of post types that should be used for linking', 'internal-link-builder' ),
				'description' => __( 'All posts within the allowed post types can link to other posts automatically.', 'internal-link-builder' ),
				'default'     => array( 'post', 'page' ),
				'options'     => array( $this, 'post_type_options' ),
				'placeholder' => __( 'Type a post type…', 'internal-link-builder' ),
			),
			'whitelist_taxonomies'      => array(
				'type'        => 'token',
				'token_mode'  => 'static',
				'token_value' => 'slug',
				'label'       => __( 'Whitelist of taxonomies that should be used for linking', 'internal-link-builder' ),
				'description' => __( 'All terms within the allowed taxonomies can link to other posts or terms automatically.', 'internal-link-builder' ),
				'default'     => array(),
				'options'     => array( $this, 'taxonomy_options' ),
				'placeholder' => __( 'Type a taxonomy…', 'internal-link-builder' ),
			),
			'blacklist_posts'           => array(
				'type'         => 'token',
				'token_mode'   => 'ajax',
				'token_source' => 'post',
				'token_value'  => 'int',
				'label'        => __( 'Blacklist of posts that should not be used for linking', 'internal-link-builder' ),
				'description'  => __( 'Posts that get configured here do not link to others automatically.', 'internal-link-builder' ),
				'default'      => array(),
				'placeholder'  => __( 'Type to search posts…', 'internal-link-builder' ),
			),
			'blacklist_child_pages'     => array(
				'type'        => 'toggle',
				'label'       => __( 'Blacklist also child pages of blacklisted pages', 'internal-link-builder' ),
				'description' => '',
				'default'     => 0,
			),
			'blacklist_terms'           => array(
				'type'         => 'token',
				'token_mode'   => 'ajax',
				'token_source' => 'term',
				'token_value'  => 'int',
				'label'        => __( 'Blacklist of terms that should not be used for linking', 'internal-link-builder' ),
				'description'  => __( 'Terms that get configured here do not link to others automatically.', 'internal-link-builder' ),
				'default'      => array(),
				'placeholder'  => __( 'Type to search terms…', 'internal-link-builder' ),
			),
			'keyword_order'             => array(
				'type'        => 'select',
				'label'       => __( 'Order for configured keywords while linking', 'internal-link-builder' ),
				'description' => __( 'Set the order of how your set keywords get used for building links.', 'internal-link-builder' ),
				'default'     => 'first_configured',
				'options'     => array(
					'first_configured'   => __( 'First configured keyword gets linked first', 'internal-link-builder' ),
					'highest_word_count' => __( 'Highest word count gets linked first', 'internal-link-builder' ),
					'lowest_word_count'  => __( 'Lowest word count gets linked first', 'internal-link-builder' ),
					'highest_char_count' => __( 'Highest character count gets linked first', 'internal-link-builder' ),
					'lowest_char_count'  => __( 'Lowest character count gets linked first', 'internal-link-builder' ),
				),
			),
			'max_links_per_post'        => array(
				'type'        => 'number',
				'label'       => __( 'Maximum amount of links per post', 'internal-link-builder' ),
				'description' => __( 'For an unlimited number of links, set this value to 0.', 'internal-link-builder' ),
				'default'     => 5,
				'min'         => 0,
				'step'        => 1,
			),
			'limit_links_per_paragraph' => array(
				'type'        => 'toggle',
				'label'       => __( 'Limit links per paragraph', 'internal-link-builder' ),
				'description' => __( 'Limit the links created per paragraph.', 'internal-link-builder' ),
				'default'     => 0,
			),
			'max_links_per_paragraph'   => array(
				'type'        => 'number',
				'label'       => __( 'Maximum amount of links per paragraph', 'internal-link-builder' ),
				'description' => __( 'Set the maximum links per paragraph.', 'internal-link-builder' ),
				'default'     => 1,
				'min'         => 1,
				'step'        => 1,
				'depends_on'  => 'limit_links_per_paragraph',
			),
			'max_link_frequency'        => array(
				'type'        => 'number',
				'label'       => __( 'Maximum frequency of how often a post gets linked within another one', 'internal-link-builder' ),
				'description' => __( 'For an unlimited number of links, set this value to 0.', 'internal-link-builder' ),
				'default'     => 1,
				'min'         => 0,
				'step'        => 1,
			),
			'limit_incoming_links'      => array(
				'type'        => 'toggle',
				'label'       => __( 'Limit incoming links', 'internal-link-builder' ),
				'description' => __( 'Globally set a limit for all posts/pages/terms on the number of incoming links each can have.', 'internal-link-builder' ),
				'default'     => 0,
			),
			'max_incoming_links'        => array(
				'type'        => 'number',
				'label'       => __( 'Maximum incoming links', 'internal-link-builder' ),
				'description' => __( 'The maximum number of links each post/page/term can have from other posts/pages/terms.', 'internal-link-builder' ),
				'default'     => 1,
				'min'         => 0,
				'step'        => 1,
				'depends_on'  => 'limit_incoming_links',
			),
			'link_as_often_as_possible' => array(
				'type'        => 'toggle',
				'label'       => __( 'Link as often as possible', 'internal-link-builder' ),
				'description' => __( 'Allows posts and keywords to get linked as often as possible. Deactivates all other restrictions.', 'internal-link-builder' ),
				'default'     => 0,
			),
			'case_sensitive'            => array(
				'type'        => 'toggle',
				'label'       => __( 'Case sensitive mode', 'internal-link-builder' ),
				'description' => __( 'When this mode is on, keywords will be matched considering their case.', 'internal-link-builder' ),
				'default'     => 0,
			),
			'exclude_html_areas'        => array(
				'type'        => 'multicheck',
				'label'       => __( 'Exclude HTML areas from linking', 'internal-link-builder' ),
				'description' => __( 'Content within the HTML tags configured here does not get used for linking.', 'internal-link-builder' ),
				'default'     => array( 'headlines', 'strong' ),
				'options'     => self::html_area_options(),
			),
			'consider_existing_links'   => array(
				'type'        => 'toggle',
				'label'       => __( 'Consideration of existing or manually created links', 'internal-link-builder' ),
				'description' => __( 'Do not link already manually built link targets. Prevents links to URLs that are already linked in the content.', 'internal-link-builder' ),
				'default'     => 1,
			),
			'limiting_taxonomies'       => array(
				'type'        => 'token',
				'token_mode'  => 'static',
				'token_value' => 'slug',
				'label'       => __( 'Taxonomies that limit linking within their terms', 'internal-link-builder' ),
				'description' => __( 'Articles within these hierarchical taxonomies link only to articles which have the same category term.', 'internal-link-builder' ),
				'default'     => array(),
				'options'     => array( $this, 'hierarchical_taxonomy_options' ),
				'placeholder' => __( 'Type a taxonomy…', 'internal-link-builder' ),
			),
		);
	}

	/**
	 * Links tab fields.
	 *
	 * @return array
	 */
	private function links_fields() {
		return array(
			'link_template' => array(
				'type'        => 'textarea',
				'label'       => __( 'Template for the link output (keyword links)', 'internal-link-builder' ),
				'description' => __( 'Markup for the output of generated internal links. Placeholders: {{url}} for the target, {{anchor}} for the generated anchor text, {{excerpt}} for the excerpt, and {{title}} for the post/tax title.', 'internal-link-builder' ),
				'default'     => '<a href="{{url}}">{{anchor}}</a>',
			),
			'nofollow'      => array(
				'type'        => 'toggle',
				'label'       => __( 'NoFollow for internal keyword links', 'internal-link-builder' ),
				'description' => __( 'Sets the rel="nofollow" attribute for keyword links (not recommended).', 'internal-link-builder' ),
				'default'     => 0,
			),
		);
	}

	/**
	 * Returns a flat map of every field key => definition across all tabs.
	 *
	 * @return array<string,array>
	 */
	public function all_fields() {
		$flat = array();
		foreach ( $this->fields() as $tab_fields ) {
			$flat += $tab_fields;
		}

		return $flat;
	}

	/**
	 * Returns the fields belonging to a single settings tab.
	 *
	 * Falls back to every field when the tab is unknown, preserving the
	 * previous full-overwrite behaviour as a safe default.
	 *
	 * @param string $tab Tab slug.
	 * @return array<string,array>
	 */
	public function fields_for_tab( $tab ) {
		switch ( $tab ) {
			case 'general':
				return $this->general_fields();
			case 'content':
				return $this->content_fields();
			case 'links':
				return $this->links_fields();
			default:
				return $this->all_fields();
		}
	}

	/*
	 * -------------------------------------------------------------------------
	 * Option access
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Returns the default value for every setting.
	 *
	 * @return array
	 */
	public static function defaults() {
		$instance = new self();
		$defaults = array();
		foreach ( $instance->all_fields() as $key => $field ) {
			$defaults[ $key ] = isset( $field['default'] ) ? $field['default'] : '';
		}

		return $defaults;
	}

	/**
	 * Returns all settings merged with their defaults.
	 *
	 * @return array
	 */
	public function all() {
		if ( null === $this->cache ) {
			$stored      = get_option( ILB_SETTINGS_OPTION, array() );
			$stored      = is_array( $stored ) ? $stored : array();
			$this->cache = array_merge( self::defaults(), $stored );
		}

		return $this->cache;
	}

	/**
	 * Returns a single setting value.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Fallback when the key is unknown.
	 * @return mixed
	 */
	public function get( $key, $fallback = null ) {
		$all = $this->all();

		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}

		return $fallback;
	}

	/*
	 * -------------------------------------------------------------------------
	 * Settings API registration & sanitization
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Registers the setting and its sanitization callback.
	 */
	public function register() {
		register_setting(
			self::OPTION_GROUP,
			ILB_SETTINGS_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitizes the full settings array before it is stored.
	 *
	 * @param mixed $input Raw submitted values.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();

		// Each settings tab is its own form but all settings live in a single
		// option. Start from the existing stored values so saving one tab never
		// wipes the others, then overlay only the fields of the submitted tab.
		$existing = get_option( ILB_SETTINGS_OPTION, array() );
		$existing = is_array( $existing ) ? $existing : array();
		$clean    = array_merge( self::defaults(), $existing );

		$tab    = isset( $input['_tab'] ) ? sanitize_key( $input['_tab'] ) : '';
		$fields = $this->fields_for_tab( $tab );

		foreach ( $fields as $key => $field ) {
			$type  = isset( $field['type'] ) ? $field['type'] : 'text';
			$value = isset( $input[ $key ] ) ? $input[ $key ] : null;

			switch ( $type ) {
				case 'toggle':
					$clean[ $key ] = empty( $value ) ? 0 : 1;
					break;

				case 'number':
					$clean[ $key ] = $this->sanitize_number( $value, $field );
					break;

				case 'select':
					$clean[ $key ] = $this->sanitize_choice( $value, $field );
					break;

				case 'multiselect':
				case 'multicheck':
					$clean[ $key ] = $this->sanitize_choices( $value, $field );
					break;

				case 'token':
					$clean[ $key ] = $this->sanitize_token( $value, $field );
					break;

				case 'repeatable':
					$clean[ $key ] = $this->sanitize_repeatable( $value );
					break;

				case 'textarea':
					$clean[ $key ] = $this->sanitize_template( (string) $value );
					break;

				case 'text':
				default:
					$clean[ $key ] = sanitize_text_field( (string) $value );
					break;
			}
		}

		// Bust the in-request cache so subsequent reads are fresh.
		$this->cache = null;

		return $clean;
	}

	/**
	 * Clamps a numeric value to a field's min/max.
	 *
	 * @param mixed $value Raw value.
	 * @param array $field Field definition.
	 * @return int
	 */
	private function sanitize_number( $value, array $field ) {
		$value = (int) $value;

		if ( isset( $field['min'] ) && $value < $field['min'] ) {
			$value = (int) $field['min'];
		}
		if ( isset( $field['max'] ) && $value > $field['max'] ) {
			$value = (int) $field['max'];
		}

		return $value;
	}

	/**
	 * Validates a single choice against the field's options.
	 *
	 * @param mixed $value Raw value.
	 * @param array $field Field definition.
	 * @return string
	 */
	private function sanitize_choice( $value, array $field ) {
		$value   = sanitize_text_field( (string) $value );
		$options = $this->resolve_options( $field );

		if ( array_key_exists( $value, $options ) ) {
			return $value;
		}

		return isset( $field['default'] ) ? $field['default'] : '';
	}

	/**
	 * Validates a list of choices against the field's options.
	 *
	 * @param mixed $value Raw values.
	 * @param array $field Field definition.
	 * @return array
	 */
	private function sanitize_choices( $value, array $field ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$options = $this->resolve_options( $field );
		$clean   = array();

		foreach ( $value as $candidate ) {
			$candidate = sanitize_text_field( (string) $candidate );
			if ( array_key_exists( $candidate, $options ) ) {
				$clean[] = $candidate;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Sanitizes a token field's array value.
	 *
	 * The expected value type is set per field via 'token_value':
	 *  - int:  list of positive integers (post/term IDs)
	 *  - slug: list of slugs validated against the field's options
	 *  - text: list of arbitrary text tokens (e.g. meta keys)
	 *
	 * @param mixed $value Raw values.
	 * @param array $field Field definition.
	 * @return array
	 */
	private function sanitize_token( $value, array $field ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$value_type = isset( $field['token_value'] ) ? $field['token_value'] : 'text';

		if ( 'int' === $value_type ) {
			$clean = array();
			foreach ( $value as $candidate ) {
				$id = (int) $candidate;
				if ( $id > 0 ) {
					$clean[] = $id;
				}
			}
			return array_values( array_unique( $clean ) );
		}

		if ( 'slug' === $value_type ) {
			return $this->sanitize_choices( $value, $field );
		}

		// Free text.
		$clean = array();
		foreach ( $value as $candidate ) {
			$candidate = sanitize_text_field( (string) $candidate );
			if ( '' !== $candidate ) {
				$clean[] = $candidate;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Sanitizes a repeatable list of free-text lines, dropping empties.
	 *
	 * @param mixed $value Raw values (array or newline string).
	 * @return array
	 */
	private function sanitize_repeatable( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\r\n|\r|\n/', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();
		foreach ( $value as $line ) {
			$line = sanitize_text_field( (string) $line );
			if ( '' !== $line ) {
				$clean[] = $line;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Sanitizes the link output template, allowing safe markup and placeholders.
	 *
	 * @param string $value Raw template.
	 * @return string
	 */
	private function sanitize_template( $value ) {
		$value = wp_kses_post( $value );

		if ( '' === trim( $value ) ) {
			return '<a href="{{url}}">{{anchor}}</a>';
		}

		return $value;
	}

	/*
	 * -------------------------------------------------------------------------
	 * Option sources
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Resolves a field's options array, calling it when it is a callable.
	 *
	 * @param array $field Field definition.
	 * @return array<string,string>
	 */
	public function resolve_options( array $field ) {
		if ( empty( $field['options'] ) ) {
			return array();
		}

		$options = $field['options'];
		if ( is_callable( $options ) ) {
			$options = call_user_func( $options );
		}

		return is_array( $options ) ? $options : array();
	}

	/**
	 * Returns the configurable HTML areas that can be excluded from linking.
	 *
	 * @return array<string,string>
	 */
	public static function html_area_options() {
		return array(
			'headlines'  => __( 'Headlines (<h1>-<h6>)', 'internal-link-builder' ),
			'strong'     => __( 'Strong text (<strong>, <b>)', 'internal-link-builder' ),
			'div'        => __( 'Div container (<div>)', 'internal-link-builder' ),
			'table'      => __( 'Tables (<table>)', 'internal-link-builder' ),
			'figcaption' => __( 'Image captions (<figcaption>)', 'internal-link-builder' ),
			'ol'         => __( 'Ordered lists (<ol>)', 'internal-link-builder' ),
			'ul'         => __( 'Unordered lists (<ul>)', 'internal-link-builder' ),
			'blockquote' => __( 'Blockquotes (<blockquote>)', 'internal-link-builder' ),
			'em'         => __( 'Italic text (<em>, <i>)', 'internal-link-builder' ),
			'cite'       => __( 'Inline quotes (<cite>)', 'internal-link-builder' ),
			'code'       => __( 'Sourcecode (<code>)', 'internal-link-builder' ),
			'excerpt'    => __( 'Excerpts (.entry-summary, .excerpt)', 'internal-link-builder' ),
		);
	}

	/**
	 * Returns selectable user roles.
	 *
	 * @return array<string,string>
	 */
	public function role_options() {
		$roles = array();
		if ( function_exists( 'wp_roles' ) ) {
			foreach ( wp_roles()->get_names() as $slug => $label ) {
				$roles[ $slug ] = translate_user_role( $label );
			}
		}

		return $roles;
	}

	/**
	 * Returns public post types as options.
	 *
	 * @return array<string,string>
	 */
	public function post_type_options() {
		$options = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $slug => $object ) {
			$options[ $slug ] = $object->labels->singular_name . ' (' . $slug . ')';
		}

		return $options;
	}

	/**
	 * Returns public taxonomies as options.
	 *
	 * @return array<string,string>
	 */
	public function taxonomy_options() {
		$options = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $slug => $object ) {
			$options[ $slug ] = $object->labels->singular_name . ' (' . $slug . ')';
		}

		return $options;
	}

	/**
	 * Returns hierarchical taxonomies as options.
	 *
	 * @return array<string,string>
	 */
	public function hierarchical_taxonomy_options() {
		$options = array();
		foreach ( get_taxonomies( array(), 'objects' ) as $slug => $object ) {
			if ( ! empty( $object->hierarchical ) ) {
				$options[ $slug ] = $object->labels->singular_name . ' (' . $slug . ')';
			}
		}

		return $options;
	}
}
