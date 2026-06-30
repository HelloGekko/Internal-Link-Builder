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

// Remove the settings option and the schema version marker.
delete_option( $ilb_option_key );
delete_option( 'ilb_db_version' );
delete_option( 'ilb_index_token' );

// Remove per-post keyword configuration and cached link output.
delete_post_meta_by_key( '_ilb_keywords' );
delete_post_meta_by_key( '_ilb_settings' );
delete_post_meta_by_key( '_ilb_content_blacklist' );
delete_post_meta_by_key( '_ilb_link_cache' );

// Remove per-term keyword configuration.
$ilb_term_meta_keys = array( '_ilb_keywords', '_ilb_settings', '_ilb_content_blacklist' );
foreach ( $ilb_term_meta_keys as $ilb_meta_key ) {
	$ilb_term_ids = $GLOBALS['wpdb']->get_col(
		$GLOBALS['wpdb']->prepare(
			"SELECT term_id FROM {$GLOBALS['wpdb']->termmeta} WHERE meta_key = %s",
			$ilb_meta_key
		)
	);
	foreach ( $ilb_term_ids as $ilb_term_id ) {
		delete_term_meta( (int) $ilb_term_id, $ilb_meta_key );
	}
}

// Drop the keyword index table.
$ilb_index_table = $GLOBALS['wpdb']->prefix . 'ilb_index';
$GLOBALS['wpdb']->query( "DROP TABLE IF EXISTS {$ilb_index_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

// Clean up scheduled actions if Action Scheduler is present.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'internal-link-builder' );
}
