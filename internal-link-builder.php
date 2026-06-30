<?php
/**
 * Plugin Name:       Internal Link Builder
 * Plugin URI:        https://hellogekko.nl/internal-link-builder
 * Description:        Automatically generates internal links in the front-end based on keywords configured on target posts, pages and terms.
 * Version:           0.3.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            HelloGekko
 * Author URI:        https://hellogekko.nl
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       internal-link-builder
 * Domain Path:       /languages
 *
 * @package InternalLinkBuilder
 */

defined( 'ABSPATH' ) || exit;

/*
 * -----------------------------------------------------------------------------
 * Constants
 * -----------------------------------------------------------------------------
 */
define( 'ILB_VERSION', '0.3.0' );
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
 * Returns the main plugin instance.
 *
 * @return ILB_Plugin
 */
function ilb() {
	return ILB_Plugin::instance();
}

// Kick things off.
ilb();
