<?php

namespace InsightsSDK;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Per-product local state. Every option is named {slug}_insights_*, so a host
 * that already cleans up its own prefix on uninstall gets this for free.
 *
 *  - optin: 'yes' | 'no' | '' (not asked yet)
 *  - usage: [ action => [ 'uses' => int, 'last_used' => unix ] ] since the last successful send
 *  - state: [ feature => [ 'enabled' => bool, 'since' => unix ] ] from the last snapshot
 */
class Store
{
    const KEYS = [ 'optin', 'usage', 'state' ];

    private $slug;

    public function __construct( string $slug )
    {
        $this->slug = $slug;
    }

    public function name( string $key ): string
    {
        return $this->slug . '_insights_' . $key;
    }

    public function get( string $key, $default = null )
    {
        return get_option( $this->name( $key ), $default );
    }

    public function set( string $key, $value ): void
    {
        update_option( $this->name( $key ), $value, false );
    }

    public function delete( string $key ): void
    {
        delete_option( $this->name( $key ) );
    }

    /**
     * Remove everything this product stored, except the opt-in choice when
     * $keep_choice is set (an opt-out should stay an opt-out).
     */
    public function clear( bool $keep_choice = false ): void
    {
        foreach ( self::KEYS as $key ) {
            if ( $keep_choice && 'optin' === $key ) {
                continue;
            }
            $this->delete( $key );
        }
    }
}
