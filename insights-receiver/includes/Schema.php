<?php

namespace InsightsReceiver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Custom tables. Times are unix timestamps (UTC); rows that are charted by day
 * also carry a 'Y-m-d' day column, so grouping needs no database date
 * functions and the SQL runs on MySQL, MariaDB and SQLite alike.
 *
 *  products       one per plugin/theme reporting in, with its public key
 *  sites          one per (product, site_id): latest environment and status,
 *                 and a hash of the install's secret once it has sent one
 *  site_features  each site's current features: on/off, since, total uses
 *  usage          uses per site, action and day received
 *  events         feature_enabled / feature_disabled and custom events
 *  activity       one row per request received (snapshot, events, deactivation)
 */
class Schema
{
    const VERSION = '2';

    public static function table( string $name ): string
    {
        global $wpdb;
        return $wpdb->prefix . 'insr_' . $name;
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $c = $wpdb->get_charset_collate();
        $t = [];
        foreach ( [ 'products', 'sites', 'site_features', 'usage', 'events', 'activity' ] as $name ) {
            $t[ $name ] = self::table( $name );
        }

        dbDelta( [
            "CREATE TABLE {$t['products']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                name varchar(191) NOT NULL DEFAULT '',
                public_key varchar(64) NOT NULL DEFAULT '',
                created_at bigint(20) NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                UNIQUE KEY public_key (public_key)
            ) $c;",
            "CREATE TABLE {$t['sites']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                product_id bigint(20) unsigned NOT NULL DEFAULT 0,
                site_id varchar(191) NOT NULL DEFAULT '',
                first_seen bigint(20) NOT NULL DEFAULT 0,
                last_seen bigint(20) NOT NULL DEFAULT 0,
                last_snapshot bigint(20) NOT NULL DEFAULT 0,
                active tinyint(1) NOT NULL DEFAULT 1,
                deactivated_at bigint(20) NOT NULL DEFAULT 0,
                plan varchar(64) NOT NULL DEFAULT '',
                product_version varchar(32) NOT NULL DEFAULT '',
                sdk_version varchar(16) NOT NULL DEFAULT '',
                wp_version varchar(16) NOT NULL DEFAULT '',
                php_version varchar(16) NOT NULL DEFAULT '',
                multisite tinyint(1) NOT NULL DEFAULT 0,
                locale varchar(16) NOT NULL DEFAULT '',
                secret_hash varchar(64) NOT NULL DEFAULT '',
                PRIMARY KEY  (id),
                UNIQUE KEY product_site (product_id,site_id),
                KEY product_seen (product_id,last_seen)
            ) $c;",
            "CREATE TABLE {$t['site_features']} (
                site_pk bigint(20) unsigned NOT NULL DEFAULT 0,
                feature varchar(100) NOT NULL DEFAULT '',
                tier varchar(32) NOT NULL DEFAULT '',
                enabled tinyint(1) NOT NULL DEFAULT 0,
                enabled_since bigint(20) NOT NULL DEFAULT 0,
                first_seen bigint(20) NOT NULL DEFAULT 0,
                updated_at bigint(20) NOT NULL DEFAULT 0,
                uses_total bigint(20) NOT NULL DEFAULT 0,
                last_used bigint(20) NOT NULL DEFAULT 0,
                PRIMARY KEY  (site_pk,feature),
                KEY feature (feature)
            ) $c;",
            "CREATE TABLE {$t['usage']} (
                site_pk bigint(20) unsigned NOT NULL DEFAULT 0,
                action varchar(100) NOT NULL DEFAULT '',
                feature varchar(100) NOT NULL DEFAULT '',
                day char(10) NOT NULL DEFAULT '',
                uses bigint(20) NOT NULL DEFAULT 0,
                PRIMARY KEY  (site_pk,action,day),
                KEY day (day),
                KEY feature (feature)
            ) $c;",
            "CREATE TABLE {$t['events']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                site_pk bigint(20) unsigned NOT NULL DEFAULT 0,
                name varchar(64) NOT NULL DEFAULT '',
                value varchar(191) NOT NULL DEFAULT '',
                occurred_at bigint(20) NOT NULL DEFAULT 0,
                day char(10) NOT NULL DEFAULT '',
                PRIMARY KEY  (id),
                KEY site_pk (site_pk),
                KEY day (day),
                KEY name (name)
            ) $c;",
            "CREATE TABLE {$t['activity']} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                site_pk bigint(20) unsigned NOT NULL DEFAULT 0,
                kind varchar(16) NOT NULL DEFAULT '',
                received_at bigint(20) NOT NULL DEFAULT 0,
                day char(10) NOT NULL DEFAULT '',
                PRIMARY KEY  (id),
                KEY site_pk (site_pk),
                KEY day (day)
            ) $c;",
        ] );

        update_option( 'insr_db_version', self::VERSION );
        Retention::schedule();
    }

    public static function maybe_upgrade(): void
    {
        if ( self::VERSION !== get_option( 'insr_db_version' ) ) {
            self::install();
        }
    }

    public static function drop(): void
    {
        global $wpdb;
        foreach ( [ 'products', 'sites', 'site_features', 'usage', 'events', 'activity' ] as $name ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Own table name; uninstall.
            $wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( $name ) );
        }
        delete_option( 'insr_db_version' );
    }
}
