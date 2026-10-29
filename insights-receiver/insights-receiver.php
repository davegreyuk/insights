<?php
/**
 * Plugin Name: Insights Receiver
 * Description: Self-hosted backend for the Insights SDK. Receives opt-in feature usage from your plugins and themes, with dashboards, per-site activity, and a query API for integrations (REST, and MCP through the Abilities API).
 * Version: 1.1.0
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Author: Dave Grey @ Friendly Web Guy Plugins
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: insights-receiver
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'INSR_VERSION', '1.1.0' );
define( 'INSR_FILE', __FILE__ );
define( 'INSR_DIR', plugin_dir_path( __FILE__ ) );
define( 'INSR_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register( static function ( $class ) {
    $prefix = 'InsightsReceiver\\';
    if ( 0 !== strpos( $class, $prefix ) ) {
        return;
    }
    $file = INSR_DIR . 'includes/' . substr( $class, strlen( $prefix ) ) . '.php';
    if ( is_readable( $file ) ) {
        require $file;
    }
} );

register_activation_hook( __FILE__, [ \InsightsReceiver\Schema::class, 'install' ] );
register_deactivation_hook( __FILE__, [ \InsightsReceiver\Retention::class, 'unschedule' ] );

add_action( 'plugins_loaded', [ \InsightsReceiver\Plugin::class, 'init' ] );
