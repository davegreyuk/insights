<?php

namespace InsightsReceiver;

use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The endpoints the SDK posts to: {rest}/insights/v1/ingest and /erase.
 * Public, identified by the product key, size-capped and rate-limited per IP.
 */
class Ingest
{
    const NS        = 'insights/v1';
    const MAX_BYTES = 262144;

    public static function register(): void
    {
        add_action( 'rest_api_init', [ self::class, 'routes' ] );
    }

    public static function routes(): void
    {
        register_rest_route( self::NS, '/ingest', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'ingest' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( self::NS, '/erase', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'erase' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public static function ingest( WP_REST_Request $request )
    {
        $req = self::authenticate( $request );
        if ( is_wp_error( $req ) ) {
            return $req;
        }
        [ $product, $body, $site_id ] = $req;
        $now = time();

        $site_pk = Data::upsert_site( (int) $product->id, $site_id, $body, $now );

        if ( isset( $body['features'] ) && is_array( $body['features'] ) ) {
            Data::apply_snapshot( $site_pk, $body['features'], (array) ( $body['usage'] ?? [] ), $now );
            $kind = 'snapshot';
        } elseif ( array_key_exists( 'active', $body ) && false === $body['active'] ) {
            $kind = 'deactivation';
        } else {
            $kind = 'events';
        }
        if ( ! empty( $body['events'] ) && is_array( $body['events'] ) ) {
            Data::add_events( $site_pk, $body['events'], $now );
        }
        Data::log_activity( $site_pk, $kind, $now );

        return [ 'ok' => true ];
    }

    public static function erase( WP_REST_Request $request )
    {
        $req = self::authenticate( $request );
        if ( is_wp_error( $req ) ) {
            return $req;
        }
        [ $product, , $site_id ] = $req;

        $site_pk = Data::find_site( (int) $product->id, $site_id );
        if ( $site_pk ) {
            Data::delete_sites( [ $site_pk ] );
        }
        return [ 'ok' => true ];
    }

    /**
     * @return array|WP_Error [ product, body, site_id ]
     */
    private static function authenticate( WP_REST_Request $request )
    {
        if ( strlen( (string) $request->get_body() ) > self::MAX_BYTES ) {
            return new WP_Error( 'insr_too_large', 'Payload too large.', [ 'status' => 413 ] );
        }
        $body = $request->get_json_params();
        if ( ! is_array( $body ) ) {
            return new WP_Error( 'insr_bad_json', 'Expected a JSON object.', [ 'status' => 400 ] );
        }

        $key     = (string) ( $request->get_header( 'x_insights_key' ) ?: ( $body['key'] ?? '' ) );
        $product = Products::by_key( $key );
        if ( ! $product ) {
            return new WP_Error( 'insr_bad_key', 'Unknown product key.', [ 'status' => 401 ] );
        }

        if ( ! self::within_rate_limit( (int) $product->id ) ) {
            return new WP_Error( 'insr_rate_limited', 'Too many requests.', [ 'status' => 429 ] );
        }

        $site_id = Data::text( $body['site_id'] ?? '', 191 );
        if ( '' === $site_id ) {
            return new WP_Error( 'insr_no_site', 'site_id is required.', [ 'status' => 400 ] );
        }

        return [ $product, $body, $site_id ];
    }

    private static function within_rate_limit( int $product_id ): bool
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Only hashed into a transient name.
        $ip    = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
        $name  = 'insr_rl_' . md5( $product_id . '|' . $ip . '|' . gmdate( 'YmdH' ) );
        $count = (int) get_transient( $name );
        if ( $count >= (int) Settings::get( 'rate_limit' ) ) {
            return false;
        }
        set_transient( $name, $count + 1, HOUR_IN_SECONDS );
        return true;
    }
}
