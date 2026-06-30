<?php
/**
 * Link graph / statistics storage.
 *
 * Records the links that the generator computes for every source, one row per
 * committed link occurrence. This table is the source of truth for incoming and
 * outgoing link counts, which the front-end engine reads to enforce the global
 * incoming-link limit.
 *
 * @package InternalLinkBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ILB_Links
 */
class ILB_Links {

	/**
	 * Returns the fully-qualified links table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'ilb_links';
	}

	/**
	 * Creates or updates the links table. Safe to call repeatedly.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_id BIGINT UNSIGNED NOT NULL,
			source_type VARCHAR(20) NOT NULL,
			target_id BIGINT UNSIGNED NOT NULL,
			target_type VARCHAR(20) NOT NULL,
			keyword VARCHAR(255) NOT NULL,
			PRIMARY KEY  (id),
			KEY source (source_type, source_id),
			KEY target (target_type, target_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Converts the links table to the database's default character set and
	 * collation. Returns true when the statement ran without error.
	 *
	 * @return bool
	 */
	public static function fix_collation() {
		return ilb_convert_table_collation( self::table_name() );
	}

	/**
	 * Drops the links table.
	 */
	public static function drop() {
		global $wpdb;

		$table = self::table_name();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Removes every stored link.
	 */
	public function clear_all() {
		global $wpdb;

		$table = self::table_name();
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Replaces all rows for a source with the supplied links.
	 *
	 * @param int    $source_id   Source object ID.
	 * @param string $source_type 'post' or 'term'.
	 * @param array  $links       List of links: each [target_id, target_type, keyword].
	 * @return void
	 */
	public function replace_for_source( $source_id, $source_type, array $links ) {
		$this->remove_source( $source_id, $source_type );

		global $wpdb;
		$table       = self::table_name();
		$source_id   = (int) $source_id;
		$source_type = sanitize_key( $source_type );

		foreach ( $links as $link ) {
			$wpdb->insert(
				$table,
				array(
					'source_id'   => $source_id,
					'source_type' => $source_type,
					'target_id'   => (int) $link['target_id'],
					'target_type' => sanitize_key( $link['target_type'] ),
					'keyword'     => (string) $link['keyword'],
				),
				array( '%d', '%s', '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Removes every row for a source.
	 *
	 * @param int    $source_id   Source object ID.
	 * @param string $source_type 'post' or 'term'.
	 * @return void
	 */
	public function remove_source( $source_id, $source_type ) {
		global $wpdb;

		$wpdb->delete(
			self::table_name(),
			array(
				'source_id'   => (int) $source_id,
				'source_type' => sanitize_key( $source_type ),
			),
			array( '%d', '%s' )
		);
	}

	/**
	 * Removes every row pointing at a target (incoming links).
	 *
	 * @param int    $target_id   Target object ID.
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
	}

	/**
	 * Counts incoming links to a target, optionally excluding one source.
	 *
	 * @param int    $target_id          Target object ID.
	 * @param string $target_type        'post' or 'term'.
	 * @param int    $exclude_source_id  Source to exclude (0 for none).
	 * @param string $exclude_source_type Source type to exclude.
	 * @return int
	 */
	public function incoming_count( $target_id, $target_type, $exclude_source_id = 0, $exclude_source_type = '' ) {
		global $wpdb;

		$table = self::table_name();

		if ( $exclude_source_id > 0 ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE target_id = %d AND target_type = %s AND NOT ( source_id = %d AND source_type = %s )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					(int) $target_id,
					sanitize_key( $target_type ),
					(int) $exclude_source_id,
					sanitize_key( $exclude_source_type )
				)
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE target_id = %d AND target_type = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $target_id,
				sanitize_key( $target_type )
			)
		);
	}

	/**
	 * Counts outgoing links from a source.
	 *
	 * @param int    $source_id   Source object ID.
	 * @param string $source_type 'post' or 'term'.
	 * @return int
	 */
	public function outgoing_count( $source_id, $source_type ) {
		global $wpdb;

		$table = self::table_name();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE source_id = %d AND source_type = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $source_id,
				sanitize_key( $source_type )
			)
		);
	}

	/**
	 * Total number of stored links.
	 *
	 * @return int
	 */
	public function count() {
		global $wpdb;

		$table = self::table_name();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
