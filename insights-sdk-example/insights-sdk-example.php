<?php
/**
 * Plugin Name: Insights SDK Example
 * Description: Demo product for the Insights SDK: a settings screen whose features are reported, a built-in mock backend, and a debug panel showing what the SDK stores and sends. For local and development sites only: the mock backend accepts public requests.
 * Version: 1.0.0
 * Requires PHP: 7.4
 * Author: Dave Grey @ Friendly Web Guy Plugins
 * License: GPLv2 or later
 * Text Domain: insights-sdk-example
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*
 * The integration a real product needs is just this block: bundle the SDK,
 * describe the features, and call track() where a feature is used.
 *
 * The endpoint here is this plugin's own mock backend (further down), reached
 * with ?rest_route= so it works whatever the permalink setting. A real product
 * puts its Insights backend URL and public key here.
 */
require_once __DIR__ . '/vendor/insights-sdk/start.php';

insights_sdk_init( [
    'slug'     => 'iex',
    'name'     => 'Insights SDK Example',
    'key'      => 'pk_example',
    'endpoint' => home_url( '/?rest_route=/insights-example/v1' ),
    'version'  => '1.0.0',
    'file'     => __FILE__,
    'features' => 'iex_features',
    'plan'     => static function () {
        return get_option( 'iex_plan', 'free' );
    },
    'site_id'  => static function () {
        return home_url(); // A licensed product could pass its licence ID instead.
    },
    'notice'   => true, // Show the SDK's own consent notice on the Dashboard and Plugins screens.
] );

/** Each setting is a feature. Tiers decide what counts as "money on the table". */
const IEX_FEATURES = [
    'dark_mode'         => [ 'label' => 'Dark mode',         'tier' => 'free', 'help' => 'Free feature.' ],
    'search_boost'      => [ 'label' => 'Search boost',      'tier' => 'free', 'help' => 'Free feature.' ],
    'exports'           => [ 'label' => 'CSV exports',       'tier' => 'pro',  'help' => 'Pro feature. Use "Run export" to count a use.' ],
    'scheduled_reports' => [ 'label' => 'Scheduled reports', 'tier' => 'pro',  'help' => 'Pro feature. Enable it and never use it to look like an abandoned feature.' ],
];

function iex_features(): array
{
    $settings = (array) get_option( 'iex_settings', [] );
    $features = [];
    foreach ( IEX_FEATURES as $key => $f ) {
        $features[ $key ] = [ 'tier' => $f['tier'], 'enabled' => ! empty( $settings[ $key ] ) ];
    }
    return $features;
}

// A setting changed: record exact enabled/disabled times instead of waiting for the weekly snapshot.
add_action( 'update_option_iex_settings', static function ( $old, $new ) {
    $sdk = insights_sdk( 'iex' );
    if ( ! $sdk ) {
        return;
    }
    $sdk->sync_features();
    // Changing a feature's options (not just its switch) counts as using it.
    foreach ( array_keys( IEX_FEATURES ) as $key ) {
        if ( ! empty( $new[ $key ] ) && ( $old[ $key . '_option' ] ?? '' ) !== ( $new[ $key . '_option' ] ?? '' ) ) {
            $sdk->track( $key . '.configured' );
        }
    }
}, 10, 2 );
// The first save creates the option rather than updating it.
add_action( 'add_option_iex_settings', static function () {
    if ( $sdk = insights_sdk( 'iex' ) ) {
        $sdk->sync_features();
    }
} );

// ── Mock backend ─────────────────────────────────────────────────────────────
// Stands in for the real service: accepts ingest/erase, keeps the last 20
// requests for the debug panel, and can be told to fail with a 500. The routes
// are public, so bodies are capped at 64 KB to bound what can be stored.

add_action( 'rest_api_init', static function () {
    foreach ( [ 'ingest', 'erase' ] as $route ) {
        register_rest_route( 'insights-example/v1', '/' . $route, [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => static function ( WP_REST_Request $request ) use ( $route ) {
                if ( 'pk_example' !== $request->get_header( 'x_insights_key' ) ) {
                    return new WP_Error( 'bad_key', 'Unknown product key', [ 'status' => 401 ] );
                }
                if ( strlen( (string) $request->get_body() ) > 65536 ) {
                    return new WP_Error( 'too_large', 'Payload too large', [ 'status' => 413 ] );
                }
                $fail = (bool) get_option( 'iex_mock_fail' );
                $log  = (array) get_option( 'iex_received', [] );
                array_unshift( $log, [
                    'at'     => time(),
                    'route'  => $route,
                    'status' => $fail ? 500 : 200,
                    'body'   => $request->get_json_params(),
                ] );
                update_option( 'iex_received', array_slice( $log, 0, 20 ), false );
                return $fail
                    ? new WP_Error( 'mock_fail', 'Mock backend set to fail', [ 'status' => 500 ] )
                    : [ 'ok' => true ];
            },
        ] );
    }
} );

// Loopback to this site: skip certificate checks (Local's self-signed HTTPS).
add_filter( 'http_request_args', static function ( $args, $url ) {
    if ( false !== strpos( $url, 'rest_route=/insights-example/v1' )
        && wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST )
    ) {
        $args['sslverify'] = false;
    }
    return $args;
}, 10, 2 );

// ── Admin: Settings → Insights Example ──────────────────────────────────────

add_action( 'admin_menu', static function () {
    add_options_page( 'Insights SDK Example', 'Insights Example', 'manage_options', 'insights-sdk-example', 'iex_render_page' );
} );

add_action( 'admin_init', static function () {
    register_setting( 'iex', 'iex_settings', [
        'type'              => 'array',
        'sanitize_callback' => static function ( $input ) {
            $out = [];
            foreach ( array_keys( IEX_FEATURES ) as $key ) {
                $out[ $key ]             = empty( $input[ $key ] ) ? 0 : 1;
                $out[ $key . '_option' ] = sanitize_text_field( $input[ $key . '_option' ] ?? '' );
            }
            return $out;
        },
    ] );
    register_setting( 'iex', 'iex_plan', [
        'type'              => 'string',
        'sanitize_callback' => static function ( $plan ) {
            return in_array( $plan, [ 'free', 'trial:pro', 'pro' ], true ) ? $plan : 'free';
        },
    ] );
} );

/** Debug panel buttons, all through admin-post.php with one nonce. */
add_action( 'admin_post_iex_debug', static function () {
    check_admin_referer( 'iex_debug' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( '', '', 403 );
    }
    $sdk = insights_sdk( 'iex' );
    $do  = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
    $msg = '';

    // Only the mock backend buttons work without a running SDK.
    if ( ! $sdk && ! in_array( $do, [ 'toggle_fail', 'clear_log' ], true ) ) {
        $do  = '';
        $msg = 'The SDK isn\'t running (missing key, endpoint or site ID).';
    }

    switch ( $do ) {
        case 'opt_in':
            $sdk->opt_in();
            $msg = 'Opted in. The first snapshot is scheduled a minute from now; use "Send snapshot now" to skip the wait.';
            break;
        case 'opt_out':
            $sdk->opt_out();
            $msg = 'Opted out: erase request sent, local data deleted.';
            break;
        case 'reset':
            $sdk->uninstall();
            $msg = 'SDK data removed (as on uninstall). The consent notice will show again.';
            break;
        case 'send':
            $msg = $sdk->send_snapshot() ? 'Snapshot accepted (2xx). Counters were reset.' : 'Snapshot not sent or rejected. Counters kept for next time.';
            break;
        case 'sync':
            $sdk->sync_features();
            $msg = 'Features compared with the last snapshot. Any changes are queued as events and sent at the end of this request.';
            break;
        case 'run_export':
            $sdk->track( 'exports.run' );
            $msg = 'Counted one use of exports.run.';
            break;
        case 'track':
            $feature = sanitize_key( wp_unslash( $_POST['feature'] ?? '' ) );
            if ( isset( IEX_FEATURES[ $feature ] ) ) {
                $sdk->track( $feature . '.used' );
                $msg = "Counted one use of $feature.used.";
            }
            break;
        case 'upgrade_click':
            $sdk->event( 'upgrade_clicked', 'debug_panel' );
            $msg = 'Queued an upgrade_clicked event (sent at the end of this request).';
            break;
        case 'toggle_fail':
            update_option( 'iex_mock_fail', get_option( 'iex_mock_fail' ) ? 0 : 1 );
            $msg = get_option( 'iex_mock_fail' ) ? 'Mock backend now returns 500.' : 'Mock backend now returns 200.';
            break;
        case 'clear_log':
            delete_option( 'iex_received' );
            $msg = 'Received log cleared.';
            break;
    }

    set_transient( 'iex_msg_' . get_current_user_id(), $msg, 60 );
    wp_safe_redirect( admin_url( 'options-general.php?page=insights-sdk-example' ) );
    exit;
} );

function iex_button( string $do, string $label, array $extra = [], string $class = 'button' ): void
{
    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 6px 6px 0">';
    wp_nonce_field( 'iex_debug' );
    echo '<input type="hidden" name="action" value="iex_debug"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
    foreach ( $extra as $k => $v ) {
        echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
    }
    echo '<button class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
}

function iex_json( $value ): void
{
    echo '<pre style="background:#f6f7f7;border:1px solid #dcdcde;padding:10px;max-height:320px;overflow:auto;font-size:12px">'
        . esc_html( wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</pre>';
}

function iex_time( $ts ): string
{
    return $ts ? wp_date( 'Y-m-d H:i:s', (int) $ts ) . ' (' . human_time_diff( (int) $ts ) . ( $ts > time() ? ' from now' : ' ago' ) . ')' : '—';
}

function iex_render_page(): void
{
    $sdk      = insights_sdk( 'iex' );
    $settings = (array) get_option( 'iex_settings', [] );
    $plan     = get_option( 'iex_plan', 'free' );
    $msg      = get_transient( 'iex_msg_' . get_current_user_id() );
    delete_transient( 'iex_msg_' . get_current_user_id() );
    ?>
    <div class="wrap">
        <h1>Insights SDK Example</h1>
        <?php if ( $msg ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:minmax(320px,1fr) minmax(420px,1.4fr);gap:24px;align-items:start">

        <div>
            <h2>Settings</h2>
            <p>Each switch is a reported feature. Saving with a feature on and its option changed counts as a use (<code>feature.configured</code>).</p>
            <form method="post" action="options.php">
                <?php settings_fields( 'iex' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="iex_plan">Plan (simulated licence)</label></th>
                        <td>
                            <select name="iex_plan" id="iex_plan">
                                <?php foreach ( [ 'free' => 'Free', 'trial:pro' => 'Pro trial', 'pro' => 'Pro' ] as $value => $label ) : ?>
                                    <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $plan, $value ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Sent as <code>plan</code>. A Pro install with Pro features off is a "money on the table" case.</p>
                        </td>
                    </tr>
                    <?php foreach ( IEX_FEATURES as $key => $f ) : ?>
                        <tr>
                            <th scope="row"><?php echo esc_html( $f['label'] ); ?> <span style="font-weight:normal;color:#646970">(<?php echo esc_html( $f['tier'] ); ?>)</span></th>
                            <td>
                                <label><input type="checkbox" name="iex_settings[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $settings[ $key ] ) ); ?>> Enabled</label><br>
                                <input type="text" class="regular-text" placeholder="An option for this feature" name="iex_settings[<?php echo esc_attr( $key ); ?>_option]" value="<?php echo esc_attr( $settings[ $key . '_option' ] ?? '' ); ?>">
                                <p class="description"><?php echo esc_html( $f['help'] ); ?></p>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <?php submit_button(); ?>
            </form>

            <h3>Use the features</h3>
            <p>
                <?php
                iex_button( 'run_export', 'Run export (exports.run)' );
                foreach ( IEX_FEATURES as $key => $f ) {
                    iex_button( 'track', 'Use ' . $f['label'], [ 'feature' => $key ] );
                }
                iex_button( 'upgrade_click', 'Click "Upgrade" (event)' );
                ?>
            </p>
            <p class="description">Uses are only counted while opted in.</p>
        </div>

        <div>
            <h2>Debug panel</h2>
            <?php if ( ! $sdk ) : ?>
                <p>The SDK didn't start (missing key or endpoint).</p>
            <?php else : ?>
                <table class="widefat striped" style="margin-bottom:12px">
                    <tr><td style="width:180px">Consent</td><td><strong><?php echo esc_html( $sdk->choice() ?: 'not asked yet' ); ?></strong></td></tr>
                    <tr><td>Next snapshot (cron)</td><td><?php echo esc_html( iex_time( wp_next_scheduled( 'iex_insights_snapshot' ) ) ); ?></td></tr>
                    <tr><td>Endpoint</td><td><code><?php echo esc_html( home_url( '/?rest_route=/insights-example/v1' ) ); ?></code></td></tr>
                    <tr><td>Mock backend</td><td><?php echo get_option( 'iex_mock_fail' ) ? '<strong style="color:#b32d2e">returns 500</strong>' : 'returns 200'; ?></td></tr>
                    <tr><td>Client::SDK_VERSION</td><td><?php echo esc_html( \InsightsSDK\Client::SDK_VERSION ); ?></td></tr>
                </table>
                <p>
                    <?php
                    if ( $sdk->is_opted_in() ) {
                        iex_button( 'send', 'Send snapshot now', [], 'button button-primary' );
                        iex_button( 'sync', 'Sync features' );
                        iex_button( 'opt_out', 'Opt out' );
                    } else {
                        iex_button( 'opt_in', 'Opt in', [], 'button button-primary' );
                    }
                    iex_button( 'toggle_fail', get_option( 'iex_mock_fail' ) ? 'Make backend succeed' : 'Make backend fail' );
                    iex_button( 'reset', 'Reset SDK (uninstall)' );
                    ?>
                </p>

                <h3>Next snapshot (preview, not sent)</h3>
                <?php $sdk->is_opted_in() ? iex_json( $sdk->preview() ) : print( '<p>Opt in to see it.</p>' ); ?>

                <h3>Stored by the SDK</h3>
                <p><code>iex_insights_state</code>: each feature's on/off state and since when (null = already like that at the first snapshot)</p>
                <?php iex_json( get_option( 'iex_insights_state', null ) ); ?>
                <p><code>iex_insights_usage</code>: uses since the last accepted snapshot</p>
                <?php iex_json( get_option( 'iex_insights_usage', null ) ); ?>
            <?php endif; ?>

            <h3>Received by the mock backend <span style="font-weight:normal">(newest first)</span></h3>
            <p><?php iex_button( 'clear_log', 'Clear log' ); ?></p>
            <?php
            $log = (array) get_option( 'iex_received', [] );
            if ( ! $log ) {
                echo '<p>Nothing yet.</p>';
            }
            foreach ( $log as $entry ) {
                $kind = 'erase' === $entry['route'] ? 'erase'
                    : ( isset( $entry['body']['features'] ) ? 'snapshot' : ( isset( $entry['body']['active'] ) ? 'deactivation ping' : 'events' ) );
                printf(
                    '<details style="margin-bottom:6px"><summary><code>%s</code> %s · <strong>%s</strong> · HTTP %d</summary>',
                    esc_html( '/' . $entry['route'] ),
                    esc_html( iex_time( $entry['at'] ) ),
                    esc_html( $kind ),
                    (int) $entry['status']
                );
                iex_json( $entry['body'] );
                echo '</details>';
            }
            ?>
        </div>
        </div>
    </div>
    <?php
}

register_uninstall_hook( __FILE__, 'iex_uninstall' );

function iex_uninstall(): void
{
    foreach ( [ 'iex_settings', 'iex_plan', 'iex_received', 'iex_mock_fail' ] as $option ) {
        delete_option( $option );
    }
    if ( function_exists( 'insights_sdk_boot' ) ) {
        insights_sdk_boot();
        if ( $sdk = insights_sdk( 'iex' ) ) {
            $sdk->uninstall();
        }
    }
}
