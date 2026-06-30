<?php
/**
 * Uninstall handler.
 *
 * Removes plugin data unless the user opted to keep it via the
 * "Keep configured keywords and plugin settings after plugin uninstall"
 * setting.
 *
 * @package InternalLinkBuilder
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$ilb_option_key = 'ilb_settings';
$ilb_settings   = get_option( $ilb_option_key, array() );

// Respect the "keep data" preference.
if ( is_array( $ilb_settings ) && ! empty( $ilb_settings['keep_data_on_uninstall'] ) ) {
	return;
}

// Remove the settings option.
delete_option( $ilb_option_key );

// Remove any per-post / per-term keyword configuration the engine will store
// under these meta keys (safe to run even before the engine exists).
delete_post_meta_by_key( '_ilb_keywords' );
delete_post_meta_by_key( '_ilb_settings' );
delete_post_meta_by_key( '_ilb_keyword_blacklist' );

// Clean up scheduled actions if Action Scheduler is present.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'internal-link-builder' );
}
