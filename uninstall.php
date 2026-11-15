<?php
/**
 * WPForgeHub Customer Portal — Uninstall Script
 *
 * Cleans up options and transients upon complete deletion.
 *
 * @package WPForgeHub\CustomerPortal
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'wpfh_cp_settings' );

// Clean up magic link transients
global $wpdb;
$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_wpfh_cp_magic_%' OR option_name LIKE '_transient_timeout_wpfh_cp_magic_%'" );
