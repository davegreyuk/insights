<?php
/**
 * Data is kept unless "Delete all products and data when the plugin is
 * deleted" is ticked in Insights → Settings & API.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$insr_settings = (array) get_option( 'insr_settings', [] );
wp_clear_scheduled_hook( 'insr_purge' );

if ( ! empty( $insr_settings['delete_on_uninstall'] ) ) {
    require_once __DIR__ . '/includes/Schema.php';
    \InsightsReceiver\Schema::drop();
    delete_option( 'insr_settings' );
}
