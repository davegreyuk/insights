<?php

namespace InsightsReceiver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Daily purge: events, usage and activity older than the retention period,
 * and sites not heard from for that long (with everything recorded for them).
 */
class Retention
{
    const HOOK = 'insr_purge';

    public static function register(): void
    {
        add_action( self::HOOK, [ self::class, 'purge' ] );
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            self::schedule();
        }
    }

    public static function schedule(): void
    {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook( self::HOOK );
    }

    public static function purge(): void
    {
        global $wpdb;
        $cutoff = time() - (int) Settings::get( 'retention_days' ) * DAY_IN_SECONDS;
        $day    = gmdate( 'Y-m-d', $cutoff );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Own tables.
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::table( 'events' ) . ' WHERE day < %s', $day ) );
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::table( 'usage' ) . ' WHERE day < %s', $day ) );
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::table( 'activity' ) . ' WHERE day < %s', $day ) );
        $stale = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'sites' ) . ' WHERE last_seen < %d', $cutoff ) );
        // phpcs:enable

        Data::delete_sites( array_map( 'intval', $stale ) );
    }
}
