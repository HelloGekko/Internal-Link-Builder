<?php
/**
 * Per-target keyword and override storage.
 *
 * Keywords and their per-target settings are stored in post/term meta. When the
 * index generation mode is "automatic", saving keywords also rebuilds the index
 * rows for that target.
 *
 * @package InternalLinkBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ILB_Keywords
 */
class ILB_Keywords {

	/**
	 * Meta key holding the array of keywords for a target.
	 */
	const META_KEYWORDS = '_ilb_keywords';

	/**
	 * Meta key holding the per-target override settings.
	 */
	const META_SETTINGS = '_ilb_settings';

	/**
	 * Meta key holding keywords that must not be linked within this content.
	 */
	const META_CONTENT_BLACKLIST = '_ilb_content_blacklist';

	/**
	 * Settings handler.
	 *
	 * @var ILB_Settings
	 */
	private $settings;

	/**
	 * Index handler.
	 *
	 * @var ILB_Index
	 */
	private $index;

	/**
	 * Constructor.
	 *
	 * @param ILB_Settings $settings Settings handler.
	 * @param ILB_Index    $index    Index handler.
	 */
	public function __construct( ILB_Settings $settings, ILB_Index $index ) {
		$this->settings = $settings;
		$this->index    = $index;
	}

	/**
	 * Returns the default per-target override settings.
	 *
	 * @return array<string,int>
	 */
	public static function default_target_settings() {
		return array(
			'on_global_blacklist'       => 0,
			'limit_links_per_paragraph' => 0,
			'limit_incoming_links'      => 0,
			'limit_outgoing_links'      => 0,
		);
	}

	/*
	 * -------------------------------------------------------------------------
	 * Reading
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Returns the configured keywords for a target.
	 *
	 * @param int    $target_id   Post or term ID.
	 * @param string $target_type 'post' or 'term'.
	 * @return string[]
	 */
	public function get_keywords( $target_id, $target_type = 'post' ) {
		$value = $this->get_meta( $target_id, $target_type, self::META_KEYWORDS );

		return is_array( $value ) ? array_values( $value ) : array();
	}

	/**
	 * Returns the per-target override settings, merged with defaults.
	 *
	 * @param int    $target_id   Post or term ID.
	 * @param string $target_type 'post' or 'term'.
	 * @return array<string,int>
	 */
	public function get_target_settings( $target_id, $target_type = 'post' ) {
		$value = $this->get_meta( $target_id, $target_type, self::META_SETTINGS );
		$value = is_array( $value ) ? $value : array();

		return array_merge( self::default_target_settings(), $value );
	}

	/**
	 * Returns the keywords that must not be linked within this content.
	 *
	 * @param int    $target_id   Post or term ID.
	 * @param string $target_type 'post' or 'term'.
	 * @return string[]
	 */
	public function get_content_blacklist( $target_id, $target_type = 'post' ) {
		$value = $this->get_meta( $target_id, $target_type, self::META_CONTENT_BLACKLIST );

		return is_array( $value ) ? array_values( $value ) : array();
	}

	/*
	 * -------------------------------------------------------------------------
	 * Writing
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Persists keywords, overrides and the content blacklist for a target, and
	 * refreshes the index when in automatic mode.
	 *
	 * @param int    $target_id   Post or term ID.
	 * @param string $target_type Either 'post' or 'term'.
	 * @param array  $data        Keyword data: 'keywords' (string[]), 'settings'
	 *                            (override toggles) and 'content_blacklist'
	 *                            (string[] of keywords not linked in this content).
	 * @return void
	 */
	public function save( $target_id, $target_type, array $data ) {
		$keywords          = isset( $data['keywords'] ) ? $this->clean_lines( $data['keywords'] ) : array();
		$content_blacklist = isset( $data['content_blacklist'] ) ? $this->clean_lines( $data['content_blacklist'] ) : array();
		$overrides         = $this->clean_target_settings( isset( $data['settings'] ) ? $data['settings'] : array() );

		$this->update_meta( $target_id, $target_type, self::META_KEYWORDS, $keywords );
		$this->update_meta( $target_id, $target_type, self::META_SETTINGS, $overrides );
		$this->update_meta( $target_id, $target_type, self::META_CONTENT_BLACKLIST, $content_blacklist );

		if ( 'automatic' === $this->settings->get( 'index_generation_mode' ) ) {
			$this->index->rebuild_for_target( $target_id, $target_type, $keywords );
		}

		/**
		 * Fires after a target's keywords have been saved.
		 *
		 * @param int    $target_id   Target object ID.
		 * @param string $target_type 'post' or 'term'.
		 */
		do_action( 'ilb_keywords_saved', $target_id, $target_type );
	}

	/**
	 * Normalises a list of free-text lines: trims, drops empties and dedupes.
	 *
	 * @param mixed $lines Array or newline-delimited string.
	 * @return string[]
	 */
	private function clean_lines( $lines ) {
		if ( is_string( $lines ) ) {
			$lines = preg_split( '/\r\n|\r|\n/', $lines );
		}
		if ( ! is_array( $lines ) ) {
			return array();
		}

		$clean = array();
		foreach ( $lines as $line ) {
			$line = sanitize_text_field( (string) $line );
			if ( '' !== $line ) {
				$clean[] = $line;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Coerces submitted override settings to the known boolean keys.
	 *
	 * @param mixed $input Raw settings.
	 * @return array<string,int>
	 */
	private function clean_target_settings( $input ) {
		$input = is_array( $input ) ? $input : array();
		$clean = array();
		foreach ( self::default_target_settings() as $key => $default ) {
			$clean[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		return $clean;
	}

	/*
	 * -------------------------------------------------------------------------
	 * Capability
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Whether the current user may edit keywords, per the configured minimum
	 * role.
	 *
	 * @return bool
	 */
	public function current_user_can_edit() {
		return self::user_meets_role( $this->settings->get( 'min_role_edit_keywords' ) );
	}

	/**
	 * Determines whether the current user holds at least the required role.
	 *
	 * Roles are compared using a privilege ordering (filterable). Administrators
	 * always qualify; unknown custom roles require an exact role match.
	 *
	 * @param string $required_role Role slug from the settings.
	 * @return bool
	 */
	public static function user_meets_role( $required_role ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		/**
		 * Filters the role privilege ordering used to compare against the
		 * configured minimum role. Later entries are more privileged.
		 *
		 * @param string[] $order Ordered role slugs.
		 */
		$order = apply_filters(
			'ilb_role_hierarchy',
			array( 'subscriber', 'contributor', 'author', 'editor', 'administrator' )
		);

		$user           = wp_get_current_user();
		$user_roles     = (array) $user->roles;
		$required_index = array_search( $required_role, $order, true );

		if ( false === $required_index ) {
			// Unknown custom role: require exact membership.
			return in_array( $required_role, $user_roles, true );
		}

		foreach ( $user_roles as $role ) {
			$index = array_search( $role, $order, true );
			if ( false !== $index && $index >= $required_index ) {
				return true;
			}
		}

		return false;
	}

	/*
	 * -------------------------------------------------------------------------
	 * Meta helpers (post/term agnostic)
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Reads a meta value for either a post or a term.
	 *
	 * @param int    $id   Object ID.
	 * @param string $type 'post' or 'term'.
	 * @param string $key  Meta key.
	 * @return mixed
	 */
	private function get_meta( $id, $type, $key ) {
		if ( 'term' === $type ) {
			return get_term_meta( (int) $id, $key, true );
		}

		return get_post_meta( (int) $id, $key, true );
	}

	/**
	 * Writes a meta value for either a post or a term.
	 *
	 * @param int    $id    Object ID.
	 * @param string $type  'post' or 'term'.
	 * @param string $key   Meta key.
	 * @param mixed  $value Value to store.
	 * @return void
	 */
	private function update_meta( $id, $type, $key, $value ) {
		if ( 'term' === $type ) {
			update_term_meta( (int) $id, $key, $value );

			return;
		}

		update_post_meta( (int) $id, $key, $value );
	}
}
