<?php
/**
 * PHPUnit bootstrap for the WordPress integration test suite.
 *
 * @package InternalLinkBuilder
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php — run bin/install-wp-tests.sh first." . PHP_EOL; // phpcs:ignore
	exit( 1 );
}

require_once "{$_tests_dir}/includes/functions.php";

/**
 * Loads the plugin under test.
 */
function _ilb_manually_load_plugin() {
	require dirname( __DIR__ ) . '/internal-link-builder.php';
}
tests_add_filter( 'muplugins_loaded', '_ilb_manually_load_plugin' );

require "{$_tests_dir}/includes/bootstrap.php";

// Ensure the plugin's custom tables exist for the test database.
ILB_Index::install();
ILB_Links::install();
