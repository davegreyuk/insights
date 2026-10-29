<?php

namespace InsightsReceiver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Writes. Upserts are done as select-then-insert/update rather than
 * database-specific syntax, so they work on SQLite too.
 *
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Own tables throughout.
 */
class Data
{
    const MAX_FEATURES = 500;
    const MAX_USAGE    = 1000;
    const MAX_EVENTS   = 200;

    /**
     * Create or update the site row from a request's base fields. The first
     * secret a site sends is kept (see Ingest); callers check it beforehand.
     *
     * @param string $secret_hash From hash_secret(); '' when none was sent.
     * @return int Site primary key, or 0 if a concurrent first report claimed
     *             the site with a different secret.
     */
    public static function upsert_site( int $product_id, string $site_id, array $body, int $now, string $secret_hash = '' ): int
    {
        global $wpdb;
        $table = Schema::table( 'sites' );
        $env   = (array) ( $body['env'] ?? [] );

        $row = [
            'last_seen'       => $now,
            // An explicit active=false is the deactivation ping; anything else means the product is running.
            'active'          => ( array_key_exists( 'active', $body ) && false === $body['active'] ) ? 0 : 1,
            'plan'            => self::text( $body['plan'] ?? '', 64 ),
            'product_version' => self::text( $body['product_version'] ?? '', 32 ),
            'sdk_version'     => self::text( $body['sdk_version'] ?? '', 16 ),
            'wp_version'      => self::text( $env['wp'] ?? '', 16 ),
            'php_version'     => self::text( $env['php'] ?? '', 16 ),
            'multisite'       => empty( $env['multisite'] ) ? 0 : 1,
            'locale'          => self::text( $env['locale'] ?? '', 16 ),
        ];
        $row['deactivated_at'] = $row['active'] ? 0 : $now;
        if ( isset( $body['features'] ) ) {
            $row['last_snapshot'] = $now;
        }

        $site = self::site_row( $product_id, $site_id );
        if ( ! $site ) {
            $insert = $row + [ 'product_id' => $product_id, 'site_id' => $site_id, 'first_seen' => $now, 'secret_hash' => $secret_hash ];
            // Explicit formats: wpdb treats a column named site_id as an integer (core's multisite column).
            if ( $wpdb->insert( $table, $insert, array_map( static function ( $v ) {
                return is_int( $v ) ? '%d' : '%s';
            }, $insert ) ) ) {
                return (int) $wpdb->insert_id;
            }
            // Lost a race with a concurrent first report: use the row it created.
            $site = self::site_row( $product_id, $site_id );
            if ( ! $site || ! self::secret_matches( $site, $secret_hash ) ) {
                return 0;
            }
        }

        if ( '' === $site->secret_hash && '' !== $secret_hash ) {
            $row['secret_hash'] = $secret_hash;
        }
        $wpdb->update( $table, $row, [ 'id' => (int) $site->id ] );
        return (int) $site->id;
    }

    /** A site's ID and secret hash, or null if it hasn't reported. */
    public static function site_row( int $product_id, string $site_id ): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT id, secret_hash FROM ' . Schema::table( 'sites' ) . ' WHERE product_id = %d AND site_id = %s',
            $product_id,
            $site_id
        ) );
        return $row ?: null;
    }

    /** Hash of a secret sent in X-Insights-Site-Secret; '' if none or malformed. */
    public static function hash_secret( string $secret ): string
    {
        return preg_match( '/^[A-Za-z0-9_\-]{16,128}$/', $secret ) ? hash( 'sha256', $secret ) : '';
    }

    /** Sites that have never sent a secret (SDK before 1.1) accept any request. */
    public static function secret_matches( object $site, string $secret_hash ): bool
    {
        return '' === (string) $site->secret_hash || hash_equals( (string) $site->secret_hash, $secret_hash );
    }

    /**
     * Apply a snapshot: the site's full feature list (features it no longer
     * declares are removed) and the usage counted since its last snapshot.
     */
    public static function apply_snapshot( int $site_pk, array $features, array $usage, int $now ): void
    {
        global $wpdb;
        $table    = Schema::table( 'site_features' );
        $existing = [];
        foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE site_pk = %d", $site_pk ) ) as $r ) {
            $existing[ $r->feature ] = $r;
        }

        $seen = [];
        foreach ( array_slice( $features, 0, self::MAX_FEATURES, true ) as $key => $info ) {
            $key = self::key( (string) $key );
            if ( '' === $key || ! is_array( $info ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $enabled      = empty( $info['enabled'] ) ? 0 : 1;
            $uses         = min( 1000000, max( 0, (int) ( $info['uses'] ?? 0 ) ) );
            $last_used    = self::time( $info['last_used'] ?? 0, $now );
            $since        = self::time( $info['enabled_since'] ?? 0, $now );
            $old          = $existing[ $key ] ?? null;

            // The SDK sends null when the start time isn't known; keep what we had if the state hasn't changed.
            if ( ! $since && $old && (int) $old->enabled === $enabled ) {
                $since = (int) $old->enabled_since;
            }

            $row = [
                'tier'          => self::key( (string) ( $info['tier'] ?? 'free' ) ) ?: 'free',
                'enabled'       => $enabled,
                'enabled_since' => $since,
                'updated_at'    => $now,
                'uses_total'    => ( $old ? (int) $old->uses_total : 0 ) + $uses,
                'last_used'     => max( $old ? (int) $old->last_used : 0, $last_used ),
            ];
            if ( $old ) {
                $wpdb->update( $table, $row, [ 'site_pk' => $site_pk, 'feature' => $key ] );
            } else {
                $wpdb->insert( $table, $row + [ 'site_pk' => $site_pk, 'feature' => $key, 'first_seen' => $now ] );
            }
        }

        foreach ( array_diff_key( $existing, $seen ) as $key => $unused ) {
            $wpdb->delete( $table, [ 'site_pk' => $site_pk, 'feature' => $key ] );
        }

        self::add_usage( $site_pk, $usage, $now );
    }

    /** Add per-action use counts to today's rows. */
    public static function add_usage( int $site_pk, array $usage, int $now ): void
    {
        global $wpdb;
        $table = Schema::table( 'usage' );
        $day   = gmdate( 'Y-m-d', $now );

        foreach ( array_slice( $usage, 0, self::MAX_USAGE, true ) as $action => $count ) {
            $action = self::key( (string) $action );
            $uses   = min( 1000000, max( 0, (int) ( is_array( $count ) ? ( $count['uses'] ?? 0 ) : $count ) ) );
            if ( '' === $action || ! $uses ) {
                continue;
            }
            $feature = explode( '.', $action )[0];
            $current = $wpdb->get_var( $wpdb->prepare( "SELECT uses FROM $table WHERE site_pk = %d AND action = %s AND day = %s", $site_pk, $action, $day ) );
            if ( null === $current ) {
                $wpdb->insert( $table, [ 'site_pk' => $site_pk, 'action' => $action, 'feature' => $feature, 'day' => $day, 'uses' => $uses ] );
            } else {
                $wpdb->update( $table, [ 'uses' => (int) $current + $uses ], [ 'site_pk' => $site_pk, 'action' => $action, 'day' => $day ] );
            }
        }
    }

    public static function add_events( int $site_pk, array $events, int $now ): void
    {
        global $wpdb;
        foreach ( array_slice( $events, 0, self::MAX_EVENTS ) as $event ) {
            if ( ! is_array( $event ) ) {
                continue;
            }
            $name = substr( sanitize_key( (string) ( $event['name'] ?? '' ) ), 0, 64 );
            if ( '' === $name ) {
                continue;
            }
            $at = self::time( $event['at'] ?? 0, $now ) ?: $now;
            $wpdb->insert( Schema::table( 'events' ), [
                'site_pk'     => $site_pk,
                'name'        => $name,
                'value'       => self::text( $event['value'] ?? '', 191 ),
                'occurred_at' => $at,
                'day'         => gmdate( 'Y-m-d', $at ),
            ] );
        }
    }

    public static function log_activity( int $site_pk, string $kind, int $now ): void
    {
        global $wpdb;
        $wpdb->insert( Schema::table( 'activity' ), [
            'site_pk'     => $site_pk,
            'kind'        => $kind,
            'received_at' => $now,
            'day'         => gmdate( 'Y-m-d', $now ),
        ] );
    }

    /** Remove sites and everything recorded for them. */
    public static function delete_sites( array $site_pks ): void
    {
        global $wpdb;
        $site_pks = array_filter( array_map( 'intval', $site_pks ) );
        foreach ( array_chunk( $site_pks, 200 ) as $chunk ) {
            $in = implode( ',', $chunk ); // Integers only.
            foreach ( [ 'site_features', 'usage', 'events', 'activity' ] as $name ) {
                $wpdb->query( 'DELETE FROM ' . Schema::table( $name ) . " WHERE site_pk IN ($in)" );
            }
            $wpdb->query( 'DELETE FROM ' . Schema::table( 'sites' ) . " WHERE id IN ($in)" );
        }
    }

    public static function find_site( int $product_id, string $site_id ): int
    {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT id FROM ' . Schema::table( 'sites' ) . ' WHERE product_id = %d AND site_id = %s',
            $product_id,
            $site_id
        ) );
    }

    // ── Cleaning ─────────────────────────────────────────────────────────────

    /** Feature, action and tier keys: lowercase letters, digits, _ . - up to 100 chars. */
    public static function key( string $value ): string
    {
        return substr( preg_replace( '/[^a-z0-9_.\-]/', '', strtolower( $value ) ), 0, 100 );
    }

    public static function text( $value, int $max ): string
    {
        return is_scalar( $value ) ? mb_substr( sanitize_text_field( (string) $value ), 0, $max ) : '';
    }

    /** A sent timestamp, or 0 if missing or implausible (before 2020 or in the future). */
    private static function time( $value, int $now ): int
    {
        $value = is_numeric( $value ) ? (int) $value : 0;
        return ( $value > 1577836800 && $value <= $now + 300 ) ? min( $value, $now ) : 0;
    }
}
