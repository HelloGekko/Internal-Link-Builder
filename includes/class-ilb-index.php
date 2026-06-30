<?php
/**
 * Keyword index storage.
 *
 * The index is a denormalised lookup table that maps each configured keyword to
 * its target (a post or a term). The linking engine queries this table at
 * render time instead of scanning every post, and it is (re)built from the
 * keywords stored on each target.
 *
 * @package InternalLinkBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ILB_Index
 */
class ILB_Index {

	/**
	 * Schema version, bumped when the table structure changes.
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Option key tracking the installed schema version.
	 */
	const DB_VERSION_OPTION = 'ilb_db_version';

	/**
	 * Option key holding a token that changes whenever the index changes. The
	 * front-end cache is keyed partly on this token, so any index update
	 * transparently invalidates cached link output.
	 */
	const TOKEN_OPTION = 'ilb_index_token';

	/**
	 * Returns the current index token.
	 *
	 * @return int
	 */
	public static function token() {
		return (int) get_option( self::TOKEN_OPTION, 0 );
	}

	/**
	 * Bumps the index token, invalidating front-end caches.
	 */
	public static function bump_token() {
		update_option( self::TOKEN_OPTION, self::token() + 1 );
	}

	/**
	 * Returns the fully-qualified index table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'ilb_index';
	}

	/**
	 * Creates or updates the index table. Safe to call repeatedly.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			keyword VARCHAR(255) NOT NULL,
			keyword_lower VARCHAR(255) NOT NULL,
			target_id BIGINT UNSIGNED NOT NULL,
			target_type VARCHAR(20) NOT NULL,
			PRIMARY KEY  (id),
			KEY keyword_lower (keyword_lower(191)),
			KEY target (target_type, target_id)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Converts the index table to the database's default character set and
	 * collation. Returns true when the statement ran without error.
	 *
	 * @return bool
	 */
	public static function fix_collation() {
		return ilb_convert_table_collation( self::table_name() );
	}

	/**
	 * Drops the index table. Used on uninstall when data is not retained.
	 */
	public static function drop() {
		global $wpdb;

		$table = self::table_name();
		// Table name cannot be parameterised; it is built from $wpdb->prefix.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Replaces all index rows for a single target with the given keywords.
	 *
	 * @param int      $target_id   Post or term ID.
	 * @param string   $target_type 'post' or 'term'.
	 * @param string[] $keywords    Keyword strings.
	 * @return void
	 */
	public function rebuild_for_target( $target_id, $target_type, array $keywords ) {
		global $wpdb;

		$this->remove_target( $target_id, $target_type );

		$target_id   = (int) $target_id;
		$target_type = sanitize_key( $target_type );
		$table       = self::table_name();
		$seen        = array();

		foreach ( $keywords as $keyword ) {
			$keyword = trim( (string) $keyword );
			if ( '' === $keyword ) {
				continue;
			}

			$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $keyword ) : strtolower( $keyword );

			// Avoid duplicate (keyword, target) rows.
			if ( isset( $seen[ $lower ] ) ) {
				continue;
			}
			$seen[ $lower ] = true;

			$wpdb->insert(
				$table,
				array(
					'keyword'       => $keyword,
					'keyword_lower' => $lower,
					'target_id'     => $target_id,
					'target_type'   => $target_type,
				),
				array( '%s', '%s', '%d', '%s' )
			);
		}

		self::bump_token();
	}

	/**
	 * Removes every index row for a target.
	 *
	 * @param int    $target_id   Post or term ID.
	 * @param string $target_type 'post' or 'term'.
	 * @return void
	 */
	public function remove_target( $target_id, $target_type ) {
		global $wpdb;

		$wpdb->delete(
			self::table_name(),
			array(
				'target_id'   => (int) $target_id,
				'target_type' => sanitize_key( $target_type ),
			),
			array( '%d', '%s' )
		);

		self::bump_token();
	}

	/**
	 * Returns the index rows matching a keyword (case-insensitive lookup).
	 *
	 * Provided for the linking engine; the engine applies case sensitivity and
	 * the other configured constraints on top of these candidates.
	 *
	 * @param string $keyword Keyword to look up.
	 * @return array[] List of rows (keyword, target_id, target_type).
	 */
	public function get_targets_for_keyword( $keyword ) {
		global $wpdb;

		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $keyword ) : strtolower( $keyword );
		$table = self::table_name();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT keyword, target_id, target_type FROM {$table} WHERE keyword_lower = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$lower
			),
			ARRAY_A
		);
	}

	/**
	 * Returns every index row, ordered by insertion (configuration) order.
	 *
	 * @return array[] List of rows (keyword, keyword_lower, target_id, target_type).
	 */
	public function all_rows() {
		global $wpdb;

		$table = self::table_name();

		return $wpdb->get_results(
			"SELECT keyword, keyword_lower, target_id, target_type FROM {$table} ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
	}

	/**
	 * Returns the total number of indexed keywords.
	 *
	 * @return int
	 */
	public function count() {
		global $wpdb;

		$table = self::table_name();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
