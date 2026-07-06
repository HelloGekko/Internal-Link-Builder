<?php
/**
 * Plugin Name:       Internal Link Builder
 * Plugin URI:        https://hellogekko.nl/internal-link-builder
 * Description:        Automatically generates internal links in the front-end based on keywords configured on target posts, pages and terms.
 * Version:           0.13.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            HelloGekko
 * Author URI:        https://hellogekko.nl
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       internal-link-builder
 * Domain Path:       /languages
 * Update URI:        https://hellogekko.nl/internal-link-builder
 *
 * @package InternalLinkBuilder
 */

defined( 'ABSPATH' ) || exit;

/*
 * -----------------------------------------------------------------------------
 * Constants
 * -----------------------------------------------------------------------------
 */
define( 'ILB_VERSION', '0.13.0' );
define( 'ILB_PLUGIN_FILE', __FILE__ );
define( 'ILB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ILB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ILB_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * The single option key under which all plugin settings are stored.
 */
define( 'ILB_SETTINGS_OPTION', 'ilb_settings' );

/**
 * Admin page / menu slug. Defined here so front-end components (e.g. the admin
 * bar) can reference it without loading the admin-only classes.
 */
define( 'ILB_PAGE_SLUG', 'internal-link-builder' );

/*
 * -----------------------------------------------------------------------------
 * Bootstrap
 * -----------------------------------------------------------------------------
 */
require_once ILB_PLUGIN_DIR . 'includes/class-ilb-plugin.php';

/**
 * Converts one of the plugin's tables to the database's default character set
 * and collation. Used by the "Fix collations" maintenance tool.
 *
 * The table name is built from $wpdb->prefix and the charset/collation come
 * from the database configuration, so no user input reaches the statement.
 *
 * @param string $table Fully-qualified table name.
 * @return bool True when the conversion ran without error.
 */
function ilb_convert_table_collation( $table ) {
	global $wpdb;

	// Defensive: charset/collation come from config, but validate the format
	// before interpolating them into DDL.
	if ( empty( $wpdb->charset ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $wpdb->charset ) ) {
		return false;
	}

	$sql = "ALTER TABLE `{$table}` CONVERT TO CHARACTER SET {$wpdb->charset}";
	if ( ! empty( $wpdb->collate ) && preg_match( '/^[A-Za-z0-9_]+$/', $wpdb->collate ) ) {
		$sql .= " COLLATE {$wpdb->collate}";
	}

	return false !== $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

/**
 * Returns the main plugin instance.
 *
 * @return ILB_Plugin
 */
function ilb() {
	return ILB_Plugin::instance();
}

// Kick things off.
ilb();
