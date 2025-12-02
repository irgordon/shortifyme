<?php
/**
 * Uninstall Plugin
 *
 * Fired when the plugin is deleted.
 */

// 1. Security Check
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// 2. Define Table Name (reconstruct as class is not loaded)
$shortifyme_table_name = $wpdb->prefix . 'shortifyme_urls';

// 3. Drop Table (Data Cleanup)
$wpdb->query( "DROP TABLE IF EXISTS $shortifyme_table_name" );

// 4. Delete Option (Configuration Cleanup)
delete_option( 'shortifyme_custom_domain' );

// 5. Delete Transient (Cache Cleanup)
delete_transient( 'shortifyme_dns_cache' );
