<?php
/**
 * Uninstall Plugin
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;
$shortifyme_table_name = $wpdb->prefix . 'shortifyme_urls';

// 1. Drop Table
$wpdb->query( "DROP TABLE IF EXISTS $shortifyme_table_name" );

// 2. Delete Options
delete_option( 'shortifyme_custom_domain' );
delete_option( 'shortifyme_dns_status_result' );

// 3. Clear Object Cache (if using persistent object cache)
// Although we can't iterate keys easily, they will expire naturally.
// We can clear known transients.
delete_transient( 'shortifyme_msg' );
