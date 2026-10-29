<?php

namespace InsightsReceiver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Every read. The admin screens, the REST query API and the abilities (MCP)
 * all call these, so they always agree.
 *
 * Filters (see filters()):
 *   product  product ID (defaults to the first product)
 *   days     reporting window for usage, events and charts (1–730, default 30)
 *   status   live | quiet | deactivated | all (default live)
 *              live: active and seen within the quiet period
 *              quiet: still marked active but silent for longer
 *              deactivated: sent the deactivation ping
 *   plan     '' (any) | paid | free | trial | an exact plan name
 *   search   part of the site ID
 *   feature  feature key; with state: enabled | disabled
 *   page, per_page, orderby, order  for lists
 *
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Own tables; values always go through prepare().
 */
class Query
{
    const STATUSES = [ 'live', 'quiet', 'deactivated', 'all' ];
    const BREAKDOWNS = [
        'plan'            => 'plan',
        'product_version' => 'product_version',
        'wp_version'      => 'wp_version',
        'php_version'     => 'php_version',
        'locale'          => 'locale',
        'sdk_version'     => 'sdk_version',
    ];

    public static function filters( array $in ): array
    {
        $product = absint( $in['product'] ?? 0 );
        if ( ! $product || ! Products::get( $product ) ) {
            $all     = Products::all();
            $product = $all ? (int) $all[0]->id : 0;
        }
        $status = sanitize_key( (string) ( $in['status'] ?? 'live' ) );
        $state  = sanitize_key( (string) ( $in['state'] ?? '' ) );
        $order  = strtolower( (string) ( $in['order'] ?? 'desc' ) );

        return [
            'product'  => $product,
            'days'     => max( 1, min( 730, absint( $in['days'] ?? 30 ) ?: 30 ) ),
            'status'   => in_array( $status, self::STATUSES, true ) ? $status : 'live',
            'plan'     => Data::text( $in['plan'] ?? '', 64 ),
            'search'   => Data::text( $in['search'] ?? '', 191 ),
            'feature'  => Data::key( (string) ( $in['feature'] ?? '' ) ),
            'state'    => in_array( $state, [ 'enabled', 'disabled' ], true ) ? $state : '',
            'page'     => max( 1, absint( $in['page'] ?? 1 ) ),
            'per_page' => max( 1, min( 200, absint( $in['per_page'] ?? 25 ) ?: 25 ) ),
            'orderby'  => sanitize_key( (string) ( $in['orderby'] ?? 'last_seen' ) ),
            'order'    => 'asc' === $order ? 'ASC' : 'DESC',
        ];
    }

    // ── Overview ─────────────────────────────────────────────────────────────

    /**
     * Headline numbers. Site counts by status ignore the status filter (so all
     * four are always shown); the rest respect it.
     */
    public static function overview( array $f ): array
    {
        global $wpdb;
        $cut = self::quiet_cutoff();
        [ $where_all, $args_all ] = self::where( [ 'status' => 'all' ] + $f );
        [ $where, $args ]         = self::where( $f );
        $since_day                = self::since_day( $f );

        $counts = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS total,
                SUM(CASE WHEN s.active = 1 AND s.last_seen >= %d THEN 1 ELSE 0 END) AS live,
                SUM(CASE WHEN s.active = 1 AND s.last_seen < %d THEN 1 ELSE 0 END) AS quiet,
                SUM(CASE WHEN s.active = 0 THEN 1 ELSE 0 END) AS deactivated,
                SUM(CASE WHEN s.first_seen >= %d THEN 1 ELSE 0 END) AS new_sites
             FROM " . Schema::table( 'sites' ) . " s WHERE $where_all",
            array_merge( [ $cut, $cut, time() - $f['days'] * DAY_IN_SECONDS ], $args_all )
        ), ARRAY_A );

        $features = $wpdb->get_row( $wpdb->prepare(
            "SELECT SUM(CASE WHEN sf.enabled = 1 THEN 1 ELSE 0 END) AS enabled, SUM(CASE WHEN sf.enabled = 0 THEN 1 ELSE 0 END) AS disabled
             FROM " . Schema::table( 'site_features' ) . ' sf JOIN ' . Schema::table( 'sites' ) . " s ON s.id = sf.site_pk WHERE $where",
            $args
        ), ARRAY_A );

        $uses = $wpdb->get_var( $wpdb->prepare(
            'SELECT SUM(u.uses) FROM ' . Schema::table( 'usage' ) . ' u JOIN ' . Schema::table( 'sites' ) . " s ON s.id = u.site_pk WHERE $where AND u.day >= %s",
            array_merge( $args, [ $since_day ] )
        ) );

        $events = $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Schema::table( 'events' ) . ' e JOIN ' . Schema::table( 'sites' ) . " s ON s.id = e.site_pk WHERE $where AND e.day >= %s",
            array_merge( $args, [ $since_day ] )
        ) );

        return [
            'sites_total'       => (int) ( $counts['total'] ?? 0 ),
            'sites_live'        => (int) ( $counts['live'] ?? 0 ),
            'sites_quiet'       => (int) ( $counts['quiet'] ?? 0 ),
            'sites_deactivated' => (int) ( $counts['deactivated'] ?? 0 ),
            'sites_new'         => (int) ( $counts['new_sites'] ?? 0 ),
            'features_enabled'  => (int) ( $features['enabled'] ?? 0 ),
            'features_disabled' => (int) ( $features['disabled'] ?? 0 ),
            'uses'              => (int) $uses,
            'events'            => (int) $events,
            'days'              => $f['days'],
            'status'            => $f['status'],
        ];
    }

    // ── Features ─────────────────────────────────────────────────────────────

    /**
     * Per feature, across the filtered sites:
     *   enabled / disabled          sites with it on / off right now
     *   ever_enabled                sites that have had it on at some point
     *   retention_pct               enabled / ever_enabled
     *   unused_30d                  on for 30+ days with no use in the last 30
     *   uses, sites_using           in the window
     *   enables, disables           switch events in the window
     *   median_days_to_disable      across all recorded enable→disable pairs
     */
    public static function feature_stats( array $f ): array
    {
        global $wpdb;
        [ $where, $args ] = self::where( $f );
        $since_day        = self::since_day( $f );
        $cut30            = time() - 30 * DAY_IN_SECONDS;
        $sites            = Schema::table( 'sites' );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT sf.feature, MAX(sf.tier) AS tier,
                SUM(CASE WHEN sf.enabled = 1 THEN 1 ELSE 0 END) AS enabled,
                SUM(CASE WHEN sf.enabled = 0 THEN 1 ELSE 0 END) AS disabled,
                SUM(CASE WHEN sf.enabled = 1 AND sf.last_used < %d
                    AND (CASE WHEN sf.enabled_since > 0 THEN sf.enabled_since ELSE sf.first_seen END) < %d THEN 1 ELSE 0 END) AS unused_30d
             FROM " . Schema::table( 'site_features' ) . " sf JOIN $sites s ON s.id = sf.site_pk
             WHERE $where GROUP BY sf.feature",
            array_merge( [ $cut30, $cut30 ], $args )
        ), ARRAY_A );

        $out = [];
        foreach ( $rows as $r ) {
            $out[ $r['feature'] ] = [
                'feature'                => $r['feature'],
                'tier'                   => $r['tier'],
                'enabled'                => (int) $r['enabled'],
                'disabled'               => (int) $r['disabled'],
                'ever_enabled'           => 0,
                'retention_pct'          => null,
                'unused_30d'             => (int) $r['unused_30d'],
                'uses'                   => 0,
                'sites_using'            => 0,
                'enables'                => 0,
                'disables'               => 0,
                'median_days_to_disable' => null,
            ];
        }

        foreach ( $wpdb->get_results( $wpdb->prepare(
            'SELECT u.feature, SUM(u.uses) AS uses, COUNT(DISTINCT u.site_pk) AS sites
             FROM ' . Schema::table( 'usage' ) . " u JOIN $sites s ON s.id = u.site_pk
             WHERE $where AND u.day >= %s GROUP BY u.feature",
            array_merge( $args, [ $since_day ] )
        ), ARRAY_A ) as $r ) {
            if ( isset( $out[ $r['feature'] ] ) ) {
                $out[ $r['feature'] ]['uses']        = (int) $r['uses'];
                $out[ $r['feature'] ]['sites_using'] = (int) $r['sites'];
            }
        }

        // All switch events for these sites, oldest first: window counts, ever-enabled sets, enable→disable durations.
        $ever      = [];
        $durations = [];
        $open      = [];
        $since_ts  = time() - $f['days'] * DAY_IN_SECONDS;
        foreach ( $wpdb->get_results( $wpdb->prepare(
            'SELECT e.site_pk, e.name, e.value, e.occurred_at FROM ' . Schema::table( 'events' ) . " e JOIN $sites s ON s.id = e.site_pk
             WHERE $where AND e.name IN ('feature_enabled', 'feature_disabled') ORDER BY e.occurred_at ASC",
            $args
        ), ARRAY_A ) as $e ) {
            $feature = $e['value'];
            if ( ! isset( $out[ $feature ] ) ) {
                continue;
            }
            $ever[ $feature ][ $e['site_pk'] ] = true;
            $is_enable                         = 'feature_enabled' === $e['name'];
            if ( (int) $e['occurred_at'] >= $since_ts ) {
                $out[ $feature ][ $is_enable ? 'enables' : 'disables' ]++;
            }
            $k = $feature . '|' . $e['site_pk'];
            if ( $is_enable ) {
                $open[ $k ] = (int) $e['occurred_at'];
            } elseif ( isset( $open[ $k ] ) ) {
                $durations[ $feature ][] = ( (int) $e['occurred_at'] - $open[ $k ] ) / DAY_IN_SECONDS;
                unset( $open[ $k ] );
            }
        }

        foreach ( $wpdb->get_results( $wpdb->prepare(
            'SELECT sf.feature, sf.site_pk FROM ' . Schema::table( 'site_features' ) . " sf JOIN $sites s ON s.id = sf.site_pk WHERE $where AND sf.enabled = 1",
            $args
        ), ARRAY_A ) as $r ) {
            $ever[ $r['feature'] ][ $r['site_pk'] ] = true;
        }

        foreach ( $out as $feature => &$row ) {
            $row['ever_enabled']  = count( $ever[ $feature ] ?? [] );
            $row['retention_pct'] = $row['ever_enabled'] ? round( 100 * $row['enabled'] / $row['ever_enabled'], 1 ) : null;
            if ( ! empty( $durations[ $feature ] ) ) {
                $row['median_days_to_disable'] = round( self::median( $durations[ $feature ] ), 1 );
            }
        }
        unset( $row );

        uasort( $out, static function ( $a, $b ) {
            return [ $b['enabled'], $a['feature'] ] <=> [ $a['enabled'], $b['feature'] ];
        } );
        return array_values( $out );
    }

    /** Sites that have a feature, on or off, with its per-site detail. */
    public static function feature_sites( array $f, string $feature ): array
    {
        global $wpdb;
        $f['feature'] = '';
        [ $where, $args ] = self::where( $f );
        $where .= ' AND sf.feature = %s';
        $args[] = Data::key( $feature );
        if ( $f['state'] ) {
            $where .= ' AND sf.enabled = %d';
            $args[] = 'enabled' === $f['state'] ? 1 : 0;
        }
        $from = Schema::table( 'site_features' ) . ' sf JOIN ' . Schema::table( 'sites' ) . ' s ON s.id = sf.site_pk';

        $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $from WHERE $where", $args ) );
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.id, s.site_id, s.plan, s.active, s.last_seen, sf.tier, sf.enabled, sf.enabled_since, sf.first_seen, sf.uses_total, sf.last_used
             FROM $from WHERE $where ORDER BY sf.enabled DESC, s.last_seen DESC LIMIT %d OFFSET %d",
            array_merge( $args, [ $f['per_page'], ( $f['page'] - 1 ) * $f['per_page'] ] )
        ), ARRAY_A );

        return [
            'total' => $total,
            'page'  => $f['page'],
            'items' => array_map( static function ( $r ) {
                $r['status'] = self::status_of( $r );
                return self::ints( $r, [ 'id', 'active', 'last_seen', 'enabled', 'enabled_since', 'first_seen', 'uses_total', 'last_used' ] );
            }, $rows ),
        ];
    }

    // ── Time series & breakdowns ─────────────────────────────────────────────

    /**
     * One row per day in the window: sites reporting, uses, feature enables and
     * disables, new sites. Uses every site status (past activity counts even if
     * the site has since gone), but respects the other filters.
     */
    public static function timeseries( array $f ): array
    {
        global $wpdb;
        [ $where, $args ] = self::where( [ 'status' => 'all' ] + $f );
        $since_day        = self::since_day( $f );
        $sites            = Schema::table( 'sites' );

        $days = [];
        for ( $i = $f['days'] - 1; $i >= 0; $i-- ) {
            $d          = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
            $days[ $d ] = [ 'day' => $d, 'active_sites' => 0, 'uses' => 0, 'enables' => 0, 'disables' => 0, 'new_sites' => 0 ];
        }

        $merge = static function ( array $rows, array $map ) use ( &$days ) {
            foreach ( $rows as $r ) {
                if ( isset( $days[ $r['day'] ] ) ) {
                    foreach ( $map as $from => $to ) {
                        $days[ $r['day'] ][ $to ] = (int) $r[ $from ];
                    }
                }
            }
        };

        $merge( $wpdb->get_results( $wpdb->prepare(
            'SELECT a.day, COUNT(DISTINCT a.site_pk) AS n FROM ' . Schema::table( 'activity' ) . " a JOIN $sites s ON s.id = a.site_pk WHERE $where AND a.day >= %s GROUP BY a.day",
            array_merge( $args, [ $since_day ] )
        ), ARRAY_A ), [ 'n' => 'active_sites' ] );

        $merge( $wpdb->get_results( $wpdb->prepare(
            'SELECT u.day, SUM(u.uses) AS n FROM ' . Schema::table( 'usage' ) . " u JOIN $sites s ON s.id = u.site_pk WHERE $where AND u.day >= %s GROUP BY u.day",
            array_merge( $args, [ $since_day ] )
        ), ARRAY_A ), [ 'n' => 'uses' ] );

        $merge( $wpdb->get_results( $wpdb->prepare(
            "SELECT e.day,
                SUM(CASE WHEN e.name = 'feature_enabled' THEN 1 ELSE 0 END) AS en,
                SUM(CASE WHEN e.name = 'feature_disabled' THEN 1 ELSE 0 END) AS dis
             FROM " . Schema::table( 'events' ) . " e JOIN $sites s ON s.id = e.site_pk WHERE $where AND e.day >= %s GROUP BY e.day",
            array_merge( $args, [ $since_day ] )
        ), ARRAY_A ), [ 'en' => 'enables', 'dis' => 'disables' ] );

        foreach ( $wpdb->get_col( $wpdb->prepare(
            "SELECT s.first_seen FROM $sites s WHERE $where AND s.first_seen >= %d",
            array_merge( $args, [ time() - $f['days'] * DAY_IN_SECONDS ] )
        ) ) as $ts ) {
            $d = gmdate( 'Y-m-d', (int) $ts );
            if ( isset( $days[ $d ] ) ) {
                $days[ $d ]['new_sites']++;
            }
        }

        return array_values( $days );
    }

    /** Site counts by one environment field. */
    public static function breakdown( array $f, string $dimension ): array
    {
        global $wpdb;
        $col = self::BREAKDOWNS[ $dimension ] ?? null;
        if ( ! $col ) {
            return [];
        }
        [ $where, $args ] = self::where( $f );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.$col AS label, COUNT(*) AS sites FROM " . Schema::table( 'sites' ) . " s WHERE $where GROUP BY s.$col ORDER BY sites DESC",
            $args
        ), ARRAY_A );
        return array_map( static function ( $r ) {
            return [ 'label' => '' === (string) $r['label'] ? '(none)' : $r['label'], 'sites' => (int) $r['sites'] ];
        }, $rows );
    }

    // ── Sites ────────────────────────────────────────────────────────────────

    public static function sites( array $f ): array
    {
        global $wpdb;
        [ $where, $args ] = self::where( $f );
        $sites            = Schema::table( 'sites' );
        $sf               = Schema::table( 'site_features' );
        $orderby          = in_array( $f['orderby'], [ 'last_seen', 'first_seen', 'site_id', 'plan', 'features_on', 'uses' ], true ) ? $f['orderby'] : 'last_seen';

        $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $sites s WHERE $where", $args ) );
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.*,
                (SELECT COUNT(*) FROM $sf x WHERE x.site_pk = s.id AND x.enabled = 1) AS features_on,
                (SELECT COUNT(*) FROM $sf x WHERE x.site_pk = s.id) AS features_total,
                (SELECT COALESCE(SUM(u.uses), 0) FROM " . Schema::table( 'usage' ) . " u WHERE u.site_pk = s.id AND u.day >= %s) AS uses
             FROM $sites s WHERE $where ORDER BY $orderby {$f['order']}, s.id DESC LIMIT %d OFFSET %d",
            array_merge( [ self::since_day( $f ) ], $args, [ $f['per_page'], ( $f['page'] - 1 ) * $f['per_page'] ] )
        ), ARRAY_A );

        return [
            'total'    => $total,
            'page'     => $f['page'],
            'per_page' => $f['per_page'],
            'items'    => array_map( [ self::class, 'site_row' ], $rows ),
        ];
    }

    /**
     * One site in full: details, features, usage by day and action in the
     * window, and a timeline of what it sent.
     *
     * @param int $site_pk Site primary key.
     */
    public static function site( int $site_pk, int $days = 30 ): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'sites' ) . ' WHERE id = %d', $site_pk ), ARRAY_A );
        if ( ! $row ) {
            return null;
        }
        $since_day = gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );

        $features = array_map( static function ( $r ) {
            return self::ints( $r, [ 'enabled', 'enabled_since', 'first_seen', 'updated_at', 'uses_total', 'last_used' ] );
        }, $wpdb->get_results( $wpdb->prepare(
            'SELECT feature, tier, enabled, enabled_since, first_seen, updated_at, uses_total, last_used FROM ' . Schema::table( 'site_features' ) . ' WHERE site_pk = %d ORDER BY enabled DESC, feature ASC',
            $site_pk
        ), ARRAY_A ) );

        $by_day = [];
        for ( $i = $days - 1; $i >= 0; $i-- ) {
            $by_day[ gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS ) ] = 0;
        }
        foreach ( $wpdb->get_results( $wpdb->prepare(
            'SELECT day, SUM(uses) AS uses FROM ' . Schema::table( 'usage' ) . ' WHERE site_pk = %d AND day >= %s GROUP BY day',
            $site_pk,
            $since_day
        ), ARRAY_A ) as $r ) {
            if ( isset( $by_day[ $r['day'] ] ) ) {
                $by_day[ $r['day'] ] = (int) $r['uses'];
            }
        }

        $by_action = array_map( static function ( $r ) {
            return [ 'action' => $r['action'], 'uses' => (int) $r['uses'] ];
        }, $wpdb->get_results( $wpdb->prepare(
            'SELECT action, SUM(uses) AS uses FROM ' . Schema::table( 'usage' ) . ' WHERE site_pk = %d AND day >= %s GROUP BY action ORDER BY uses DESC',
            $site_pk,
            $since_day
        ), ARRAY_A ) );

        $timeline = [];
        foreach ( $wpdb->get_results( $wpdb->prepare(
            'SELECT kind, received_at FROM ' . Schema::table( 'activity' ) . ' WHERE site_pk = %d ORDER BY received_at DESC LIMIT 100',
            $site_pk
        ), ARRAY_A ) as $r ) {
            $timeline[] = [ 'at' => (int) $r['received_at'], 'type' => 'received', 'name' => $r['kind'], 'value' => '' ];
        }
        foreach ( $wpdb->get_results( $wpdb->prepare(
            'SELECT name, value, occurred_at FROM ' . Schema::table( 'events' ) . ' WHERE site_pk = %d ORDER BY occurred_at DESC LIMIT 200',
            $site_pk
        ), ARRAY_A ) as $r ) {
            $timeline[] = [ 'at' => (int) $r['occurred_at'], 'type' => 'event', 'name' => $r['name'], 'value' => $r['value'] ];
        }
        usort( $timeline, static function ( $a, $b ) {
            return $b['at'] <=> $a['at'];
        } );

        $site                 = self::site_row( $row + [ 'features_on' => count( array_filter( array_column( $features, 'enabled' ) ) ), 'features_total' => count( $features ), 'uses' => array_sum( $by_day ) ] );
        $site['features']     = $features;
        $site['usage_by_day'] = array_map( static function ( $day, $uses ) {
            return [ 'day' => $day, 'uses' => $uses ];
        }, array_keys( $by_day ), $by_day );
        $site['usage_by_action'] = $by_action;
        $site['timeline']        = array_slice( $timeline, 0, 200 );
        return $site;
    }

    /** Events, newest first, optionally for one site, event name or value (feature). */
    public static function events( array $f, int $site_pk = 0, string $name = '' ): array
    {
        global $wpdb;
        [ $where, $args ] = self::where( [ 'feature' => '' ] + $f );
        $where .= ' AND e.day >= %s';
        $args[] = self::since_day( $f );
        if ( $site_pk ) {
            $where .= ' AND e.site_pk = %d';
            $args[] = $site_pk;
        }
        if ( $name ) {
            $where .= ' AND e.name = %s';
            $args[] = sanitize_key( $name );
        }
        if ( $f['feature'] ) {
            $where .= ' AND e.value = %s';
            $args[] = $f['feature'];
        }
        $from  = Schema::table( 'events' ) . ' e JOIN ' . Schema::table( 'sites' ) . ' s ON s.id = e.site_pk';
        $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $from WHERE $where", $args ) );
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.id, e.site_pk, s.site_id, e.name, e.value, e.occurred_at FROM $from WHERE $where ORDER BY e.occurred_at DESC, e.id DESC LIMIT %d OFFSET %d",
            array_merge( $args, [ $f['per_page'], ( $f['page'] - 1 ) * $f['per_page'] ] )
        ), ARRAY_A );

        return [
            'total' => $total,
            'page'  => $f['page'],
            'items' => array_map( static function ( $r ) {
                return self::ints( $r, [ 'id', 'site_pk', 'occurred_at' ] );
            }, $rows ),
        ];
    }

    /**
     * "Money on the table": sites on a paid plan using few of their paid
     * features. A paid feature is any tier other than 'free'. Defaults to
     * plan=paid unless another plan filter is given.
     *
     * @param float $threshold Share of paid features enabled below which a site is listed (0–1).
     */
    public static function opportunities( array $f, float $threshold = 0.5 ): array
    {
        global $wpdb;
        if ( '' === $f['plan'] ) {
            $f['plan'] = 'paid';
        }
        $f['feature']     = '';
        [ $where, $args ] = self::where( $f );
        $sf               = Schema::table( 'site_features' );
        $cut30            = time() - 30 * DAY_IN_SECONDS;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.id, s.site_id, s.plan, s.last_seen, s.active,
                COUNT(sf.feature) AS paid_total,
                SUM(CASE WHEN sf.enabled = 1 THEN 1 ELSE 0 END) AS paid_enabled,
                SUM(CASE WHEN sf.enabled = 1 AND sf.last_used >= %d THEN 1 ELSE 0 END) AS paid_used_30d
             FROM " . Schema::table( 'sites' ) . " s JOIN $sf sf ON sf.site_pk = s.id AND sf.tier <> 'free'
             WHERE $where GROUP BY s.id, s.site_id, s.plan, s.last_seen, s.active",
            array_merge( [ $cut30 ], $args )
        ), ARRAY_A );

        $items = [];
        foreach ( $rows as $r ) {
            $r     = self::ints( $r, [ 'id', 'last_seen', 'active', 'paid_total', 'paid_enabled', 'paid_used_30d' ] );
            $ratio = $r['paid_total'] ? $r['paid_enabled'] / $r['paid_total'] : 1;
            if ( $ratio < $threshold ) {
                $r['enabled_pct'] = round( 100 * $ratio, 1 );
                $r['status']      = self::status_of( $r );
                $items[ $r['id'] ] = $r;
            }
        }
        uasort( $items, static function ( $a, $b ) {
            return [ $a['enabled_pct'], $b['paid_total'] ] <=> [ $b['enabled_pct'], $a['paid_total'] ];
        } );

        // Which paid features each listed site is missing.
        $page = array_slice( $items, ( $f['page'] - 1 ) * $f['per_page'], $f['per_page'], true );
        if ( $page ) {
            $in = implode( ',', array_map( 'intval', array_keys( $page ) ) );
            foreach ( $wpdb->get_results( "SELECT site_pk, feature FROM $sf WHERE site_pk IN ($in) AND tier <> 'free' AND enabled = 0 ORDER BY feature", ARRAY_A ) as $r ) {
                $page[ $r['site_pk'] ]['paid_features_off'][] = $r['feature'];
            }
        }

        return [
            'total'     => count( $items ),
            'page'      => $f['page'],
            'threshold' => $threshold,
            'items'     => array_values( array_map( static function ( $r ) {
                return $r + [ 'paid_features_off' => [] ];
            }, $page ) ),
        ];
    }

    /** Distinct plan names seen for a product (for filter menus). */
    public static function plans( int $product ): array
    {
        global $wpdb;
        return $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT plan FROM " . Schema::table( 'sites' ) . " WHERE product_id = %d AND plan <> '' ORDER BY plan",
            $product
        ) );
    }

    /** Distinct feature keys for a product. */
    public static function feature_keys( int $product ): array
    {
        global $wpdb;
        return $wpdb->get_col( $wpdb->prepare(
            'SELECT DISTINCT sf.feature FROM ' . Schema::table( 'site_features' ) . ' sf JOIN ' . Schema::table( 'sites' ) . ' s ON s.id = sf.site_pk WHERE s.product_id = %d ORDER BY sf.feature',
            $product
        ) );
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * WHERE clause over sites aliased "s".
     *
     * @return array [ sql, args ]
     */
    private static function where( array $f ): array
    {
        $w = [ 's.product_id = %d' ];
        $a = [ (int) $f['product'] ];

        $cut = self::quiet_cutoff();
        switch ( $f['status'] ?? 'live' ) {
            case 'live':
                $w[] = 's.active = 1 AND s.last_seen >= %d';
                $a[] = $cut;
                break;
            case 'quiet':
                $w[] = 's.active = 1 AND s.last_seen < %d';
                $a[] = $cut;
                break;
            case 'deactivated':
                $w[] = 's.active = 0';
                break;
        }

        $plan = (string) ( $f['plan'] ?? '' );
        if ( 'paid' === $plan ) {
            $w[] = "s.plan <> '' AND s.plan <> 'free' AND s.plan NOT LIKE %s";
            $a[] = 'trial:%';
        } elseif ( 'free' === $plan ) {
            $w[] = "(s.plan = '' OR s.plan = 'free')";
        } elseif ( 'trial' === $plan ) {
            $w[] = 's.plan LIKE %s';
            $a[] = 'trial:%';
        } elseif ( '' !== $plan ) {
            $w[] = 's.plan = %s';
            $a[] = $plan;
        }

        if ( ! empty( $f['search'] ) ) {
            global $wpdb;
            $w[] = 's.site_id LIKE %s';
            $a[] = '%' . $wpdb->esc_like( $f['search'] ) . '%';
        }

        if ( ! empty( $f['feature'] ) ) {
            $sub = 'EXISTS (SELECT 1 FROM ' . Schema::table( 'site_features' ) . ' fx WHERE fx.site_pk = s.id AND fx.feature = %s';
            $a[] = $f['feature'];
            if ( ! empty( $f['state'] ) ) {
                $sub .= ' AND fx.enabled = %d';
                $a[]  = 'enabled' === $f['state'] ? 1 : 0;
            }
            $w[] = $sub . ')';
        }

        return [ implode( ' AND ', $w ), $a ];
    }

    private static function quiet_cutoff(): int
    {
        return time() - (int) Settings::get( 'quiet_days' ) * DAY_IN_SECONDS;
    }

    private static function since_day( array $f ): string
    {
        return gmdate( 'Y-m-d', time() - ( $f['days'] - 1 ) * DAY_IN_SECONDS );
    }

    public static function status_of( array $site ): string
    {
        if ( empty( $site['active'] ) ) {
            return 'deactivated';
        }
        return (int) $site['last_seen'] >= self::quiet_cutoff() ? 'live' : 'quiet';
    }

    private static function site_row( array $r ): array
    {
        $r               = self::ints( $r, [ 'id', 'product_id', 'first_seen', 'last_seen', 'last_snapshot', 'active', 'deactivated_at', 'multisite', 'features_on', 'features_total', 'uses' ] );
        $r['status']     = self::status_of( $r );
        $r['has_secret'] = ! empty( $r['secret_hash'] );
        unset( $r['secret_hash'] );
        return $r;
    }

    private static function ints( array $r, array $keys ): array
    {
        foreach ( $keys as $k ) {
            if ( array_key_exists( $k, $r ) ) {
                $r[ $k ] = (int) $r[ $k ];
            }
        }
        return $r;
    }

    private static function median( array $values ): float
    {
        sort( $values );
        $n = count( $values );
        return $n % 2 ? $values[ intdiv( $n, 2 ) ] : ( $values[ $n / 2 - 1 ] + $values[ $n / 2 ] ) / 2;
    }
}
