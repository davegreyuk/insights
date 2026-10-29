<?php

namespace InsightsReceiver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The plugins and themes reporting in. Each has a public key that its SDK
 * config sends with every request; it identifies the product, it isn't a secret.
 */
class Products
{
    public static function all(): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Own table.
        return (array) $wpdb->get_results( 'SELECT * FROM ' . Schema::table( 'products' ) . ' ORDER BY name ASC' );
    }

    public static function get( int $id ): ?object
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Own table.
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'products' ) . ' WHERE id = %d', $id ) );
        return $row ?: null;
    }

    public static function by_key( string $key ): ?object
    {
        if ( '' === $key ) {
            return null;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Own table.
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'products' ) . ' WHERE public_key = %s', $key ) );
        return $row ?: null;
    }

    public static function create( string $name ): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
        $wpdb->insert( Schema::table( 'products' ), [
            'name'       => sanitize_text_field( $name ),
            'public_key' => self::new_key(),
            'created_at' => time(),
        ] );
        return (int) $wpdb->insert_id;
    }

    public static function rename( int $id, string $name ): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
        $wpdb->update( Schema::table( 'products' ), [ 'name' => sanitize_text_field( $name ) ], [ 'id' => $id ] );
    }

    /** New key; sites sending the old one are refused until their SDK config is updated. */
    public static function rotate_key( int $id ): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
        $wpdb->update( Schema::table( 'products' ), [ 'public_key' => self::new_key() ], [ 'id' => $id ] );
    }

    /** Delete a product and every site and record it received. */
    public static function delete( int $id ): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Own table.
        $site_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'sites' ) . ' WHERE product_id = %d', $id ) );
        Data::delete_sites( array_map( 'intval', $site_ids ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
        $wpdb->delete( Schema::table( 'products' ), [ 'id' => $id ] );
    }

    /** The base URL the SDK's 'endpoint' should be set to. */
    public static function endpoint(): string
    {
        return rest_url( 'insights/v1' );
    }

    private static function new_key(): string
    {
        return 'pk_' . wp_generate_password( 32, false );
    }
}
