<?php
/**
 * Uninstall: removes ONLY data created by this plugin (its two tables and its options).
 *
 * @package ImprezaCompatibilityScanner
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ics_occurrences" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ics_tags" );
// phpcs:enable

foreach ( array( 'ics_scan_state', 'ics_last_scan', 'ics_batch_lock', 'ics_frontend_registry', 'ics_db_version' ) as $option ) {
	delete_option( $option );
}

delete_metadata( 'user', 0, 'ics_per_page', '', true );
