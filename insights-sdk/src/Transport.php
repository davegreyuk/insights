<?php

namespace InsightsSDK;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * JSON POSTs to the backend. Snapshots are sent blocking so the caller knows
 * whether to keep its counters; events and pings are fire-and-forget.
 */
class Transport
{
    private $endpoint;
    private $key;

    public function __construct( string $endpoint, string $key )
    {
        $this->endpoint = untrailingslashit( $endpoint );
        $this->key      = $key;
    }

    /**
     * @param string $path    Path under the endpoint, e.g. 'ingest'.
     * @param array  $payload Body; the product key is added.
     * @param bool   $blocking Wait for the response.
     * @return bool True on a 2xx response (always true when not blocking).
     */
    public function post( string $path, array $payload, bool $blocking = true ): bool
    {
        if ( '' === $this->endpoint || '' === $this->key ) {
            return false;
        }

        $response = wp_remote_post( $this->endpoint . '/' . ltrim( $path, '/' ), [
            'headers'  => [
                'Content-Type'   => 'application/json',
                'X-Insights-Key' => $this->key,
            ],
            'body'     => wp_json_encode( [ 'key' => $this->key ] + $payload ),
            'timeout'  => $blocking ? 15 : 3,
            'blocking' => $blocking,
        ] );

        if ( ! $blocking ) {
            return true;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        return ! is_wp_error( $response ) && $code >= 200 && $code < 300;
    }
}
