<?php
/**
 * Insights SDK loader: a drop-in, opt-in feature-usage reporter for WordPress
 * plugins and themes.
 *
 * Bundle this folder (e.g. vendor/insights-sdk/) and call, at file load:
 *
 *   require_once __DIR__ . '/vendor/insights-sdk/start.php';
 *   insights_sdk_init( [
 *       'slug'     => 'myplugin',           // Prefix for options and cron hooks.
 *       'name'     => 'My Plugin',          // Shown in the consent notice.
 *       'key'      => 'pk_…',               // Public product key from the backend.
 *       'endpoint' => 'https://…/v1',       // Backend base URL (…/ingest, …/erase).
 *       'version'  => '1.2.3',              // The host product's version.
 *       'file'     => __FILE__,             // Plugin main file (deactivation ping). Omit for themes.
 *       'features' => fn() => [ 'exports' => [ 'tier' => 'pro', 'enabled' => true ] ],
 *       'site_id'  => fn() => home_url(),   // Required. How the backend identifies this install.
 *       'plan'     => fn() => 'pro',        // Optional.
 *       'notice'   => true,                 // Show the built-in consent notice.
 *   ] );
 *
 * 'version', 'site_id' and 'plan' take a string or a callback returning one.
 *
 * Later (any time after plugins_loaded):
 *
 *   if ( $insights = insights_sdk( 'myplugin' ) ) {
 *       $insights->track( 'exports.run' );
 *   }
 *
 * Several plugins on one site may bundle different copies. Each copy registers
 * its version here, and on plugins_loaded the newest copy's classes are loaded
 * once and used for every product.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$insights_sdk_this_version = '1.1.0';

global $insights_sdk_registry;
if ( ! is_array( $insights_sdk_registry ) ) {
    $insights_sdk_registry = [
        'versions' => [], // version => src dir
        'pending'  => [], // configs waiting for boot
        'clients'  => [], // slug => \InsightsSDK\Client
        'booted'   => false,
    ];
}
$insights_sdk_registry['versions'][ $insights_sdk_this_version ] = __DIR__ . '/src';

if ( ! function_exists( 'insights_sdk_init' ) ) {
    /**
     * Register a product. Safe to call before or after plugins_loaded.
     *
     * @param array $config See the file header.
     */
    function insights_sdk_init( array $config ): void
    {
        global $insights_sdk_registry;
        $insights_sdk_registry['pending'][] = $config;

        // Themes and late callers: boot straight away.
        if ( did_action( 'plugins_loaded' ) ) {
            insights_sdk_boot();
        }
    }

    /**
     * The client for a product, or null before boot / for an unknown slug.
     */
    function insights_sdk( string $slug ): ?\InsightsSDK\Client
    {
        global $insights_sdk_registry;
        return $insights_sdk_registry['clients'][ sanitize_key( $slug ) ] ?? null;
    }

    /**
     * Load the newest registered copy and create a client per pending config.
     */
    function insights_sdk_boot(): void
    {
        global $insights_sdk_registry;

        if ( ! $insights_sdk_registry['booted'] ) {
            $versions = array_keys( $insights_sdk_registry['versions'] );
            usort( $versions, 'version_compare' );
            $src = $insights_sdk_registry['versions'][ end( $versions ) ];

            foreach ( [ 'Store', 'Transport', 'Consent', 'Client' ] as $class ) {
                if ( ! class_exists( '\\InsightsSDK\\' . $class, false ) ) {
                    require_once $src . '/' . $class . '.php';
                }
            }
            $insights_sdk_registry['booted'] = true;
        }

        foreach ( $insights_sdk_registry['pending'] as $config ) {
            /**
             * Filters a product's config before its client is created, e.g. to
             * point a staging site or a test suite at a different backend.
             */
            $config         = (array) apply_filters( 'insights_sdk_config', $config );
            $config['slug'] = sanitize_key( (string) ( $config['slug'] ?? '' ) );

            // No backend to send to, or no site ID from the host: don't start, so no opt-in is offered.
            if ( empty( $config['slug'] ) || empty( $config['key'] ) || empty( $config['endpoint'] ) || empty( $config['site_id'] )
                || isset( $insights_sdk_registry['clients'][ $config['slug'] ] )
            ) {
                continue;
            }
            $client = new \InsightsSDK\Client( $config );
            $client->register();
            $insights_sdk_registry['clients'][ $config['slug'] ] = $client;
        }
        $insights_sdk_registry['pending'] = [];
    }

    // Early priority so hosts can use insights_sdk() from their own plugins_loaded callbacks.
    add_action( 'plugins_loaded', 'insights_sdk_boot', 1 );
}
