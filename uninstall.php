<?php
/**
 * Uninstall ShortifyMe Plugin
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Prefixed Table Name (Matching main file)
$shortifyme_table_name = $wpdb->prefix . 'shortifyme_urls';

// Drop Table
$wpdb->query( "DROP TABLE IF EXISTS $shortifyme_table_name" );

// Delete Options (Prefixed)
delete_option( 'shortifyme_custom_domain' );
