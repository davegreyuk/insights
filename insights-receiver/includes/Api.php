<?php

namespace InsightsReceiver;

use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Read-only query API under {rest}/insights/v1/. Authenticate as a user with
 * the view capability, e.g. with an application password over HTTPS:
 *
 *   curl -u "admin:xxxx xxxx xxxx xxxx" "https://example.com/wp-json/insights/v1/features?product=1&days=30"
 *
 * Common query parameters are the filters described in Query (product, days,
 * status, plan, search, feature, state, page, per_page, orderby, order).
 */
class Api
{
    public static function register(): void
    {
        add_action( 'rest_api_init', [ self::class, 'routes' ] );
    }

    public static function routes(): void
    {
        $get = static function ( string $route, callable $callback, array $args = [] ) {
            register_rest_route( Ingest::NS, $route, [
                'methods'             => 'GET',
                'callback'            => $callback,
                'permission_callback' => [ Plugin::class, 'can_view' ],
                'args'                => $args,
            ] );
        };

        $get( '/products', static function () {
            return array_map( [ self::class, 'product' ], Products::all() );
        } );

        $get( '/overview', static function ( WP_REST_Request $r ) {
            return Query::overview( self::f( $r ) );
        } );

        $get( '/features', static function ( WP_REST_Request $r ) {
            return Query::feature_stats( self::f( $r ) );
        } );

        $get( '/features/(?P<feature>[a-z0-9_.\-]+)', static function ( WP_REST_Request $r ) {
            $f     = self::f( $r );
            $stats = array_values( array_filter( Query::feature_stats( [ 'feature' => '' ] + $f ), static function ( $row ) use ( $r ) {
                return $row['feature'] === $r['feature'];
            } ) );
            return [ 'stats' => $stats[0] ?? null, 'sites' => Query::feature_sites( $f, (string) $r['feature'] ) ];
        } );

        $get( '/timeseries', static function ( WP_REST_Request $r ) {
            return Query::timeseries( self::f( $r ) );
        } );

        $get( '/breakdown/(?P<dimension>[a-z_]+)', static function ( WP_REST_Request $r ) {
            if ( ! isset( Query::BREAKDOWNS[ $r['dimension'] ] ) ) {
                return new WP_Error( 'insr_bad_dimension', 'Use one of: ' . implode( ', ', array_keys( Query::BREAKDOWNS ) ), [ 'status' => 400 ] );
            }
            return Query::breakdown( self::f( $r ), (string) $r['dimension'] );
        } );

        $get( '/sites', static function ( WP_REST_Request $r ) {
            return Query::sites( self::f( $r ) );
        } );

        // One site by internal ID…
        $get( '/sites/(?P<id>\d+)', static function ( WP_REST_Request $r ) {
            return self::site_response( (int) $r['id'], self::f( $r )['days'] );
        } );

        // …or by the site_id the product sends (e.g. its URL).
        $get( '/site', static function ( WP_REST_Request $r ) {
            $f = self::f( $r );
            return self::site_response( Data::find_site( $f['product'], Data::text( $r['site_id'], 191 ) ), $f['days'] );
        }, [ 'site_id' => [ 'required' => true, 'type' => 'string' ] ] );

        $get( '/events', static function ( WP_REST_Request $r ) {
            return Query::events( self::f( $r ), absint( $r['site'] ), (string) $r['name'] );
        } );

        $get( '/opportunities', static function ( WP_REST_Request $r ) {
            $threshold = null === $r['threshold'] ? 0.5 : max( 0, min( 1, (float) $r['threshold'] ) );
            return Query::opportunities( self::f( $r ), $threshold );
        } );
    }

    public static function product( $p ): array
    {
        return [
            'id'         => (int) $p->id,
            'name'       => $p->name,
            'public_key' => $p->public_key,
            'created_at' => (int) $p->created_at,
        ];
    }

    private static function f( WP_REST_Request $r ): array
    {
        return Query::filters( $r->get_query_params() );
    }

    private static function site_response( int $site_pk, int $days )
    {
        $site = $site_pk ? Query::site( $site_pk, $days ) : null;
        return $site ?: new WP_Error( 'insr_no_site', 'Site not found.', [ 'status' => 404 ] );
    }
}
