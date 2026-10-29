<?php

namespace InsightsReceiver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * wp-admin screens under the Insights menu. Read-only views take the Query
 * filters from the URL (GET), so any view can be bookmarked or shared.
 */
class Admin
{
    const PAGES = [
        'insights'               => 'Dashboard',
        'insights-sites'         => 'Sites',
        'insights-features'      => 'Features',
        'insights-opportunities' => 'Opportunities',
        'insights-products'      => 'Products',
        'insights-settings'      => 'Settings & API',
    ];

    public static function register(): void
    {
        add_action( 'admin_menu', [ self::class, 'menu' ] );
        add_action( 'admin_init', [ self::class, 'settings' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'assets' ] );
        foreach ( [ 'product_create', 'product_rename', 'product_rotate', 'product_delete', 'site_delete', 'export_sites' ] as $action ) {
            add_action( 'admin_post_insr_' . $action, [ self::class, 'action_' . $action ] );
        }
    }

    public static function menu(): void
    {
        $cap = Plugin::capability();
        add_menu_page( 'Insights', 'Insights', $cap, 'insights', [ self::class, 'page_dashboard' ], 'dashicons-chart-bar', 58 );
        foreach ( self::PAGES as $slug => $title ) {
            $callback = 'page_' . str_replace( '-', '_', 'insights' === $slug ? 'dashboard' : substr( $slug, 9 ) );
            add_submenu_page( 'insights', $title . ' ‹ Insights', $title, in_array( $slug, [ 'insights-products', 'insights-settings' ], true ) ? 'manage_options' : $cap, $slug, [ self::class, $callback ] );
        }
    }

    public static function settings(): void
    {
        register_setting( 'insr_settings', Settings::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [ Settings::class, 'sanitize' ],
        ] );
    }

    public static function assets( string $hook ): void
    {
        if ( false !== strpos( $hook, 'insights' ) ) {
            wp_enqueue_style( 'insr-admin', INSR_URL . 'assets/admin.css', [], INSR_VERSION );
        }
    }

    // ── Dashboard ────────────────────────────────────────────────────────────

    public static function page_dashboard(): void
    {
        $f = self::filters();
        self::header( 'Insights', $f );
        if ( ! $f['product'] ) {
            self::no_products();
            return;
        }
        self::filter_bar( 'insights', $f );

        $o      = Query::overview( $f );
        $series = Query::timeseries( $f );
        $days   = array_column( $series, 'day' );
        $stats  = Query::feature_stats( $f );

        echo '<div class="insr-tiles">';
        self::tile( 'Live sites', $o['sites_live'], 'Reporting within the last ' . (int) Settings::get( 'quiet_days' ) . ' days', self::url( 'insights-sites', [ 'status' => 'live' ] + self::keep( $f ) ) );
        self::tile( 'Quiet', $o['sites_quiet'], 'Active but silent: possibly removed without deactivating', self::url( 'insights-sites', [ 'status' => 'quiet' ] + self::keep( $f ) ) );
        self::tile( 'Deactivated', $o['sites_deactivated'], 'Sent the deactivation ping', self::url( 'insights-sites', [ 'status' => 'deactivated' ] + self::keep( $f ) ) );
        self::tile( 'New sites', $o['sites_new'], 'First seen in the last ' . $f['days'] . ' days' );
        self::tile( 'Features running', $o['features_enabled'], number_format_i18n( $o['features_disabled'] ) . ' switched off, across ' . self::status_text( $f['status'] ) . ' sites' );
        self::tile( 'Uses', $o['uses'], 'Feature uses in the last ' . $f['days'] . ' days' );
        echo '</div>';

        echo '<div class="insr-grid">';

        self::card( 'What’s running', 'Sites with each feature switched on vs off right now (' . self::status_text( $f['status'] ) . ' sites).', Charts::bars( array_map( static function ( $s ) use ( $f ) {
            return [ 'label' => $s['feature'], 'on' => $s['enabled'], 'off' => $s['disabled'], 'note' => $s['tier'], 'url' => self::url( 'insights-features', [ 'feature' => $s['feature'] ] + self::keep( $f ) ) ];
        }, $stats ) ), 'wide' );

        self::card( 'Sites reporting per day', 'Distinct sites that sent anything that day.', Charts::line( $days, [
            [ 'name' => 'Sites', 'values' => array_column( $series, 'active_sites' ), 'color' => Charts::SERIES[0] ],
        ] ) );

        self::card( 'Feature uses per day', 'Uses reported in snapshots, by the day they were received.', Charts::columns( $days, array_column( $series, 'uses' ), 'Uses' ) );

        self::card( 'Features switched on vs off', 'Feature switch events per day.', Charts::line( $days, [
            [ 'name' => 'Switched on', 'values' => array_column( $series, 'enables' ), 'color' => Charts::SERIES[0] ],
            [ 'name' => 'Switched off', 'values' => array_column( $series, 'disables' ), 'color' => Charts::SERIES[1] ],
        ] ) );

        self::card( 'New sites per day', 'Sites seen for the first time.', Charts::columns( $days, array_column( $series, 'new_sites' ), 'New sites' ) );

        self::card( 'Plans', '', Charts::donut( self::slices( Query::breakdown( $f, 'plan' ) ) ) );
        self::card( 'Product versions', '', Charts::donut( self::slices( Query::breakdown( $f, 'product_version' ) ) ) );
        self::card( 'WordPress versions', '', Charts::bars( self::bar_rows( Query::breakdown( $f, 'wp_version' ) ) ) );
        self::card( 'PHP versions', '', Charts::bars( self::bar_rows( Query::breakdown( $f, 'php_version' ) ) ) );

        $opps = Query::opportunities( [ 'per_page' => 8, 'page' => 1 ] + $f );
        ob_start();
        self::opportunity_table( $opps['items'], $f );
        echo '<p><a href="' . esc_url( self::url( 'insights-opportunities', self::keep( $f ) ) ) . '">All ' . esc_html( number_format_i18n( $opps['total'] ) ) . ' →</a></p>';
        self::card( 'Money on the table', 'Paid sites with fewer than half their paid features switched on.', (string) ob_get_clean(), 'wide' );

        echo '</div></div>';
    }

    // ── Sites ────────────────────────────────────────────────────────────────

    public static function page_sites(): void
    {
        if ( ! empty( $_GET['site'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- Read-only view.
            self::page_site( absint( $_GET['site'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }

        $f = self::filters();
        self::header( 'Sites', $f );
        if ( ! $f['product'] ) {
            self::no_products();
            return;
        }
        self::filter_bar( 'insights-sites', $f, true );

        $res = Query::sites( $f );
        echo '<p class="insr-count">' . esc_html( number_format_i18n( $res['total'] ) ) . ' sites · <a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=insr_export_sites&' . http_build_query( self::keep( $f, true ) ) ), 'insr_export_sites' ) ) . '">Export CSV</a></p>';

        echo '<table class="widefat striped insr-table"><thead><tr>';
        foreach ( [ 'site_id' => 'Site', 'status' => 'Status', 'plan' => 'Plan', 'product_version' => 'Version', 'features_on' => 'Features on', 'uses' => 'Uses (' . $f['days'] . 'd)', 'first_seen' => 'First seen', 'last_seen' => 'Last seen' ] as $key => $label ) {
            echo '<th>' . self::sort_link( $key, $label, $f ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Escaped in sort_link().
        }
        echo '</tr></thead><tbody>';
        if ( ! $res['items'] ) {
            echo '<tr><td colspan="8">' . Charts::empty_state() . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
        }
        foreach ( $res['items'] as $s ) {
            echo '<tr><td><a href="' . esc_url( self::url( 'insights-sites', [ 'site' => $s['id'], 'days' => $f['days'] ] ) ) . '"><strong>' . esc_html( $s['site_id'] ) . '</strong></a></td>'
                . '<td>' . self::status_chip( $s['status'] ) . '</td>' // phpcs:ignore WordPress.Security.EscapeOutput
                . '<td>' . esc_html( $s['plan'] ?: '—' ) . '</td>'
                . '<td>' . esc_html( $s['product_version'] ) . '</td>'
                . '<td>' . esc_html( $s['features_on'] . ' / ' . $s['features_total'] ) . '</td>'
                . '<td>' . esc_html( number_format_i18n( $s['uses'] ) ) . '</td>'
                . '<td>' . esc_html( self::date( $s['first_seen'] ) ) . '</td>'
                . '<td>' . esc_html( self::ago( $s['last_seen'] ) ) . '</td></tr>';
        }
        echo '</tbody></table>';
        self::pagination( $res['total'], $f, 'insights-sites' );
        echo '</div>';
    }

    private static function page_site( int $id ): void
    {
        $days = self::filters()['days'];
        $site = Query::site( $id, $days );
        echo '<div class="wrap insr">';
        if ( ! $site ) {
            echo '<h1>Site not found</h1></div>';
            return;
        }
        $product = Products::get( $site['product_id'] );
        $back    = [ 'product' => $site['product_id'], 'days' => $days ];

        echo '<p><a href="' . esc_url( self::url( 'insights-sites', $back ) ) . '">← Sites</a></p>';
        echo '<h1 class="insr-site-title">' . esc_html( $site['site_id'] ) . ' ' . self::status_chip( $site['status'] ) . '</h1>'; // phpcs:ignore WordPress.Security.EscapeOutput
        echo '<p class="insr-muted">' . esc_html( $product ? $product->name : '' ) . '</p>';

        echo '<div class="insr-tiles">';
        self::tile( 'Features on', $site['features_on'], 'of ' . $site['features_total'] . ' declared' );
        self::tile( 'Uses', $site['uses'], 'in the last ' . $days . ' days' );
        self::tile_text( 'Plan', $site['plan'] ?: '—' );
        self::tile_text( 'First seen', self::date( $site['first_seen'] ) );
        self::tile_text( 'Last seen', self::ago( $site['last_seen'] ) );
        echo '</div>';

        echo '<div class="insr-grid">';
        ob_start();
        echo '<table class="widefat striped insr-table"><thead><tr><th>Feature</th><th>Tier</th><th>State</th><th>Since</th><th>Total uses</th><th>Last used</th></tr></thead><tbody>';
        foreach ( $site['features'] as $feat ) {
            echo '<tr><td><a href="' . esc_url( self::url( 'insights-features', [ 'feature' => $feat['feature'] ] + $back ) ) . '">' . esc_html( $feat['feature'] ) . '</a></td>'
                . '<td>' . esc_html( $feat['tier'] ) . '</td>'
                . '<td>' . ( $feat['enabled'] ? '<span class="insr-on">● On</span>' : '<span class="insr-off">○ Off</span>' ) . '</td>'
                . '<td>' . esc_html( $feat['enabled_since'] ? self::ago( $feat['enabled_since'] ) : 'before first report' ) . '</td>'
                . '<td>' . esc_html( number_format_i18n( $feat['uses_total'] ) ) . '</td>'
                . '<td>' . esc_html( $feat['last_used'] ? self::ago( $feat['last_used'] ) : 'never' ) . '</td></tr>';
        }
        echo '</tbody></table>';
        self::card( 'Features', '', (string) ob_get_clean(), 'wide' );

        self::card( 'Uses per day', '', Charts::columns( array_column( $site['usage_by_day'], 'day' ), array_column( $site['usage_by_day'], 'uses' ), 'Uses' ) );
        self::card( 'Uses by action', 'In the last ' . $days . ' days.', Charts::bars( array_map( static function ( $a ) {
            return [ 'label' => $a['action'], 'on' => $a['uses'], 'off' => null ];
        }, $site['usage_by_action'] ) ) );

        ob_start();
        echo '<table class="widefat striped insr-table"><thead><tr><th>When</th><th>What</th><th>Detail</th></tr></thead><tbody>';
        foreach ( $site['timeline'] as $t ) {
            echo '<tr><td title="' . esc_attr( self::datetime( $t['at'] ) ) . '">' . esc_html( self::ago( $t['at'] ) ) . '</td><td>'
                . ( 'event' === $t['type'] ? '<code>' . esc_html( $t['name'] ) . '</code>' : esc_html( ucfirst( $t['name'] ) . ' received' ) )
                . '</td><td>' . esc_html( $t['value'] ) . '</td></tr>';
        }
        echo '</tbody></table>';
        self::card( 'Activity', 'What this site sent, newest first.', (string) ob_get_clean(), 'wide' );

        self::card( 'Environment', '', '<table class="widefat striped insr-table"><tbody>'
            . self::kv( 'Product version', $site['product_version'] )
            . self::kv( 'WordPress', $site['wp_version'] )
            . self::kv( 'PHP', $site['php_version'] )
            . self::kv( 'Multisite', $site['multisite'] ? 'Yes' : 'No' )
            . self::kv( 'Locale', $site['locale'] )
            . self::kv( 'SDK version', $site['sdk_version'] )
            . self::kv( 'Site secret', $site['has_secret'] ? 'Set: only this install can update or erase it' : 'Not sent (SDK before 1.1)' )
            . self::kv( 'Internal ID', (string) $site['id'] )
            . '</tbody></table>' );

        if ( current_user_can( 'manage_options' ) ) {
            self::card( 'Erase', 'Delete this site and everything recorded for it, including its stored secret (use this if the site is refused after its WordPress salts changed). If it is still opted in, it reappears on its next report.', self::post_button( 'site_delete', 'Erase site data', [ 'site' => $site['id'] ], 'button button-link-delete', 'Erase all data for this site?' ) );
        }
        echo '</div></div>';
    }

    // ── Features ─────────────────────────────────────────────────────────────

    public static function page_features(): void
    {
        $f = self::filters();
        if ( $f['feature'] && empty( $_GET['list'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- Read-only view.
            self::page_feature( $f );
            return;
        }
        self::header( 'Features', $f );
        if ( ! $f['product'] ) {
            self::no_products();
            return;
        }
        self::filter_bar( 'insights-features', $f );

        $stats = Query::feature_stats( $f );
        echo '<table class="widefat striped insr-table"><thead><tr>'
            . '<th>Feature</th><th>Tier</th><th title="Sites with it on now">On</th><th title="Sites with it off now">Off</th><th title="Sites that have had it on at some point">Ever on</th>'
            . '<th title="Of sites that ever had it on, the share that still do">Still on</th><th title="On for 30+ days with no use in the last 30">On but unused 30d</th>'
            . '<th>Uses (' . esc_html( $f['days'] ) . 'd)</th><th>Sites using</th><th>Switched on / off (' . esc_html( $f['days'] ) . 'd)</th><th title="Median time between switching on and off">Median days to off</th></tr></thead><tbody>';
        if ( ! $stats ) {
            echo '<tr><td colspan="11">' . Charts::empty_state() . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
        }
        foreach ( $stats as $s ) {
            echo '<tr><td><a href="' . esc_url( self::url( 'insights-features', [ 'feature' => $s['feature'] ] + self::keep( $f ) ) ) . '"><strong>' . esc_html( $s['feature'] ) . '</strong></a></td>'
                . '<td>' . esc_html( $s['tier'] ) . '</td>'
                . '<td>' . esc_html( number_format_i18n( $s['enabled'] ) ) . '</td>'
                . '<td>' . esc_html( number_format_i18n( $s['disabled'] ) ) . '</td>'
                . '<td>' . esc_html( number_format_i18n( $s['ever_enabled'] ) ) . '</td>'
                . '<td>' . esc_html( null === $s['retention_pct'] ? '—' : $s['retention_pct'] . '%' ) . '</td>'
                . '<td>' . esc_html( number_format_i18n( $s['unused_30d'] ) ) . '</td>'
                . '<td>' . esc_html( number_format_i18n( $s['uses'] ) ) . '</td>'
                . '<td>' . esc_html( number_format_i18n( $s['sites_using'] ) ) . '</td>'
                . '<td>' . esc_html( $s['enables'] . ' / ' . $s['disables'] ) . '</td>'
                . '<td>' . esc_html( null === $s['median_days_to_disable'] ? '—' : $s['median_days_to_disable'] ) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p class="description">Counts cover ' . esc_html( self::status_text( $f['status'] ) ) . ' sites. A feature on at a site’s first report counts as on from that report.</p></div>';
    }

    private static function page_feature( array $f ): void
    {
        self::header( 'Feature: ' . $f['feature'], $f, self::url( 'insights-features', self::keep( $f ) + [ 'list' => 1 ] ) );
        self::filter_bar( 'insights-features', $f, false, true );

        $stats  = array_values( array_filter( Query::feature_stats( [ 'feature' => '' ] + $f ), static function ( $s ) use ( $f ) {
            return $s['feature'] === $f['feature'];
        } ) )[0] ?? null;
        $series = Query::timeseries( $f );
        $days   = array_column( $series, 'day' );

        if ( $stats ) {
            echo '<div class="insr-tiles">';
            self::tile( 'On', $stats['enabled'], 'sites with it switched on' );
            self::tile( 'Off', $stats['disabled'], 'sites with it switched off' );
            self::tile_text( 'Still on', null === $stats['retention_pct'] ? '—' : $stats['retention_pct'] . '%', 'of ' . $stats['ever_enabled'] . ' sites that ever had it on' );
            self::tile( 'On but unused', $stats['unused_30d'], 'on 30+ days, no use in 30' );
            self::tile_text( 'Median days to off', null === $stats['median_days_to_disable'] ? '—' : (string) $stats['median_days_to_disable'] );
            echo '</div>';
        }

        // Usage and switches for this feature only.
        global $wpdb;
        $uses = array_fill_keys( $days, 0 );
        $sw   = [ 'on' => array_fill_keys( $days, 0 ), 'off' => array_fill_keys( $days, 0 ) ];
        $sites_t = Schema::table( 'sites' );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Own tables.
        foreach ( $wpdb->get_results( $wpdb->prepare(
            'SELECT u.day, SUM(u.uses) AS n FROM ' . Schema::table( 'usage' ) . " u JOIN $sites_t s ON s.id = u.site_pk WHERE s.product_id = %d AND u.feature = %s AND u.day >= %s GROUP BY u.day",
            $f['product'], $f['feature'], $days[0]
        ), ARRAY_A ) as $r ) {
            if ( isset( $uses[ $r['day'] ] ) ) {
                $uses[ $r['day'] ] = (int) $r['n'];
            }
        }
        foreach ( $wpdb->get_results( $wpdb->prepare(
            'SELECT e.day, e.name, COUNT(*) AS n FROM ' . Schema::table( 'events' ) . " e JOIN $sites_t s ON s.id = e.site_pk WHERE s.product_id = %d AND e.value = %s AND e.name IN ('feature_enabled','feature_disabled') AND e.day >= %s GROUP BY e.day, e.name",
            $f['product'], $f['feature'], $days[0]
        ), ARRAY_A ) as $r ) {
            $k = 'feature_enabled' === $r['name'] ? 'on' : 'off';
            if ( isset( $sw[ $k ][ $r['day'] ] ) ) {
                $sw[ $k ][ $r['day'] ] = (int) $r['n'];
            }
        }
        // phpcs:enable

        echo '<div class="insr-grid">';
        self::card( 'Uses per day', '', Charts::columns( $days, array_values( $uses ), 'Uses' ) );
        self::card( 'Switched on vs off', '', Charts::line( $days, [
            [ 'name' => 'Switched on', 'values' => array_values( $sw['on'] ), 'color' => Charts::SERIES[0] ],
            [ 'name' => 'Switched off', 'values' => array_values( $sw['off'] ), 'color' => Charts::SERIES[1] ],
        ] ) );

        $res = Query::feature_sites( $f, $f['feature'] );
        ob_start();
        echo '<p>';
        foreach ( [ '' => 'All', 'enabled' => 'On', 'disabled' => 'Off' ] as $state => $label ) {
            $cls = $f['state'] === $state ? 'button button-primary' : 'button';
            echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( self::url( 'insights-features', [ 'feature' => $f['feature'], 'state' => $state ] + self::keep( $f ) ) ) . '">' . esc_html( $label ) . '</a> ';
        }
        echo '</p><table class="widefat striped insr-table"><thead><tr><th>Site</th><th>Status</th><th>Plan</th><th>State</th><th>Since</th><th>Total uses</th><th>Last used</th></tr></thead><tbody>';
        foreach ( $res['items'] as $s ) {
            echo '<tr><td><a href="' . esc_url( self::url( 'insights-sites', [ 'site' => $s['id'], 'days' => $f['days'] ] ) ) . '">' . esc_html( $s['site_id'] ) . '</a></td>'
                . '<td>' . self::status_chip( $s['status'] ) . '</td>' // phpcs:ignore WordPress.Security.EscapeOutput
                . '<td>' . esc_html( $s['plan'] ?: '—' ) . '</td>'
                . '<td>' . ( $s['enabled'] ? '<span class="insr-on">● On</span>' : '<span class="insr-off">○ Off</span>' ) . '</td>'
                . '<td>' . esc_html( $s['enabled_since'] ? self::ago( $s['enabled_since'] ) : 'before first report' ) . '</td>'
                . '<td>' . esc_html( number_format_i18n( $s['uses_total'] ) ) . '</td>'
                . '<td>' . esc_html( $s['last_used'] ? self::ago( $s['last_used'] ) : 'never' ) . '</td></tr>';
        }
        echo '</tbody></table>';
        self::pagination( $res['total'], $f, 'insights-features' );
        self::card( 'Sites (' . number_format_i18n( $res['total'] ) . ')', '', (string) ob_get_clean(), 'wide' );
        echo '</div></div>';
    }

    // ── Opportunities ────────────────────────────────────────────────────────

    public static function page_opportunities(): void
    {
        $f = self::filters();
        self::header( 'Opportunities', $f );
        if ( ! $f['product'] ) {
            self::no_products();
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification -- Read-only view.
        $threshold = isset( $_GET['threshold'] ) ? max( 0, min( 1, (float) $_GET['threshold'] ) ) : 0.5;
        self::filter_bar( 'insights-opportunities', $f, false, false, $threshold );

        $res = Query::opportunities( $f, $threshold );
        echo '<p class="insr-count">' . esc_html( number_format_i18n( $res['total'] ) ) . ' sites on ' . esc_html( $f['plan'] ?: 'paid' ) . ' plans with fewer than ' . esc_html( round( $threshold * 100 ) ) . '% of their paid features switched on.</p>';
        self::opportunity_table( $res['items'], $f );
        self::pagination( $res['total'], $f, 'insights-opportunities' );
        echo '</div>';
    }

    private static function opportunity_table( array $items, array $f ): void
    {
        if ( ! $items ) {
            echo Charts::empty_state(); // phpcs:ignore WordPress.Security.EscapeOutput
            return;
        }
        echo '<table class="widefat striped insr-table"><thead><tr><th>Site</th><th>Plan</th><th>Paid features on</th><th>Used in 30d</th><th>Paid features off</th><th>Last seen</th></tr></thead><tbody>';
        foreach ( $items as $s ) {
            echo '<tr><td><a href="' . esc_url( self::url( 'insights-sites', [ 'site' => $s['id'], 'days' => $f['days'] ] ) ) . '">' . esc_html( $s['site_id'] ) . '</a></td>'
                . '<td>' . esc_html( $s['plan'] ) . '</td>'
                . '<td>' . esc_html( $s['paid_enabled'] . ' / ' . $s['paid_total'] . ' (' . $s['enabled_pct'] . '%)' ) . '</td>'
                . '<td>' . esc_html( (string) $s['paid_used_30d'] ) . '</td>'
                . '<td>' . esc_html( implode( ', ', $s['paid_features_off'] ) ) . '</td>'
                . '<td>' . esc_html( self::ago( $s['last_seen'] ) ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    // ── Products ─────────────────────────────────────────────────────────────

    public static function page_products(): void
    {
        echo '<div class="wrap insr"><h1>Products</h1>';
        self::notice();
        echo '<p>Each plugin or theme that reports in needs a product here. Put its key and this site’s endpoint in the product’s <code>insights_sdk_init()</code> call.</p>';

        foreach ( Products::all() as $p ) {
            echo '<div class="insr-card insr-product"><h2>' . esc_html( $p->name ) . ' <span class="insr-muted">#' . (int) $p->id . '</span></h2>';
            echo '<p>Public key: <code>' . esc_html( $p->public_key ) . '</code></p>';
            echo '<pre class="insr-code">' . esc_html( self::snippet( $p ) ) . '</pre>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="insr-inline">';
            wp_nonce_field( 'insr_product_rename' );
            echo '<input type="hidden" name="action" value="insr_product_rename"><input type="hidden" name="product" value="' . (int) $p->id . '">'
                . '<input type="text" name="name" value="' . esc_attr( $p->name ) . '" required> <button class="button">Rename</button></form> ';
            echo self::post_button( 'product_rotate', 'New key', [ 'product' => $p->id ], 'button', 'Sites sending the old key will be refused until their SDK config is updated. Continue?' ); // phpcs:ignore WordPress.Security.EscapeOutput
            echo ' ' . self::post_button( 'product_delete', 'Delete product and data', [ 'product' => $p->id ], 'button button-link-delete', 'Delete this product and every site and record it has received?' ); // phpcs:ignore WordPress.Security.EscapeOutput
            echo '</div>';
        }

        echo '<div class="insr-card"><h2>Add a product</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'insr_product_create' );
        echo '<input type="hidden" name="action" value="insr_product_create"><input type="text" name="name" class="regular-text" placeholder="Plugin or theme name" required> <button class="button button-primary">Add product</button></form></div></div>';
    }

    private static function snippet( $p ): string
    {
        return "require_once __DIR__ . '/vendor/insights-sdk/start.php';\n\n"
            . "insights_sdk_init( [\n"
            . "    'slug'     => 'myplugin',\n"
            . "    'name'     => '" . str_replace( "'", "\\'", $p->name ) . "',\n"
            . "    'key'      => '" . $p->public_key . "',\n"
            . "    'endpoint' => '" . untrailingslashit( Products::endpoint() ) . "',\n"
            . "    'site_id'  => fn() => home_url(),\n"
            . "    'version'  => '1.0.0',\n"
            . "    'file'     => __FILE__,\n"
            . "    'features' => fn() => [ /* 'feature' => [ 'tier' => 'pro', 'enabled' => true ] */ ],\n"
            . "] );";
    }

    // ── Settings & API ───────────────────────────────────────────────────────

    public static function page_settings(): void
    {
        $s = Settings::all();
        echo '<div class="wrap insr"><h1>Settings &amp; API</h1><form method="post" action="options.php">';
        settings_fields( 'insr_settings' );
        $o = Settings::OPTION;
        echo '<table class="form-table" role="presentation">'
            . '<tr><th><label for="insr_ret">Keep data for</label></th><td><input id="insr_ret" type="number" min="30" max="3650" name="' . esc_attr( $o ) . '[retention_days]" value="' . esc_attr( $s['retention_days'] ) . '" class="small-text"> days'
            . '<p class="description">Events, usage and activity older than this are deleted daily, as are sites not heard from for this long.</p></td></tr>'
            . '<tr><th><label for="insr_quiet">Quiet after</label></th><td><input id="insr_quiet" type="number" min="8" max="365" name="' . esc_attr( $o ) . '[quiet_days]" value="' . esc_attr( $s['quiet_days'] ) . '" class="small-text"> days'
            . '<p class="description">An active site silent for longer than this counts as quiet rather than live. The SDK reports weekly, so keep this above 7.</p></td></tr>'
            . '<tr><th><label for="insr_rate">Rate limit</label></th><td><input id="insr_rate" type="number" min="10" name="' . esc_attr( $o ) . '[rate_limit]" value="' . esc_attr( $s['rate_limit'] ) . '" class="small-text"> requests per hour per IP address and product key'
            . '<p class="description">Behind a proxy or CDN, use the <code>insights_receiver_client_ip</code> filter to supply the visitor IP; otherwise every site shares the proxy’s limit.</p></td></tr>'
            . '<tr><th>Uninstall</th><td><label><input type="checkbox" name="' . esc_attr( $o ) . '[delete_on_uninstall]" value="1" ' . checked( $s['delete_on_uninstall'], 1, false ) . '> Delete all products and data when the plugin is deleted</label></td></tr>'
            . '</table>';
        submit_button();
        echo '</form>';

        $base = untrailingslashit( rest_url( 'insights/v1' ) );
        echo '<h2>Receiving</h2><p>The SDK posts to <code>' . esc_html( $base ) . '/ingest</code> and <code>/erase</code>, identified by the product key. Requests are capped at 256 KB. SDK 1.1+ also sends a per-install secret: once a site has sent one, requests for that site without it are refused, so the public key alone can’t overwrite or erase it.</p>';

        echo '<h2>Query API</h2><p>Read-only JSON endpoints. Authenticate as a user who can view Insights. Over HTTPS the simplest way is an <a href="' . esc_url( admin_url( 'profile.php#application-passwords-section' ) ) . '">application password</a>:</p>';
        echo '<pre class="insr-code">' . esc_html( 'curl -u "USERNAME:APPLICATION PASSWORD" "' . $base . '/features?product=1&days=30"' ) . '</pre>';
        echo '<table class="widefat striped insr-table"><thead><tr><th>Endpoint</th><th>Returns</th></tr></thead><tbody>';
        foreach ( [
            'GET /products'                     => 'Products and their IDs.',
            'GET /overview'                     => 'Sites live/quiet/deactivated/new, features on/off, uses, events.',
            'GET /features'                     => 'Per-feature stats: on, off, ever on, still on %, unused, uses, switches, median days to off.',
            'GET /features/{feature}'           => 'One feature’s stats plus its sites (filter with state=enabled|disabled).',
            'GET /sites'                        => 'Sites list (filters below; orderby last_seen|first_seen|site_id|plan|features_on|uses).',
            'GET /sites/{id}'                   => 'One site: details, features, usage by day/action, timeline.',
            'GET /site?site_id=…'               => 'The same, by the site ID the product sends.',
            'GET /events'                       => 'Events newest first (site, name and feature filters).',
            'GET /timeseries'                   => 'Per day: sites reporting, uses, switches on/off, new sites.',
            'GET /breakdown/{dimension}'        => 'Site counts by plan, product_version, wp_version, php_version, locale or sdk_version.',
            'GET /opportunities?threshold=0.5'  => 'Paid sites with few paid features on, and which ones are off.',
        ] as $route => $desc ) {
            echo '<tr><td><code>' . esc_html( $route ) . '</code></td><td>' . esc_html( $desc ) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p>Filters on every endpoint: <code>product</code>, <code>days</code> (1–730), <code>status</code> (live, quiet, deactivated, all), <code>plan</code> (paid, free, trial or a plan name), <code>search</code>, <code>feature</code> + <code>state</code>, <code>page</code>, <code>per_page</code>.</p>';

        echo '<h2>MCP</h2><p>The same queries are registered as WordPress abilities in the <code>insights</code> category: '
            . '<code>insights/list-products</code>, <code>get-overview</code>, <code>get-feature-stats</code>, <code>list-feature-sites</code>, <code>list-sites</code>, <code>get-site</code>, <code>list-events</code>, <code>get-timeseries</code>, <code>get-breakdown</code>, <code>get-opportunities</code>. '
            . 'Install the WordPress MCP adapter plugin and they appear as MCP tools for any client connected to this site.</p></div>';
    }

    // ── Actions ──────────────────────────────────────────────────────────────

    public static function action_product_create(): void
    {
        self::guard( 'insr_product_create' );
        $id = Products::create( wp_unslash( (string) ( $_POST['name'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Sanitized in create().
        self::done( 'insights-products', $id ? 'Product added.' : 'Could not add the product.' );
    }

    public static function action_product_rename(): void
    {
        self::guard( 'insr_product_rename' );
        Products::rename( absint( $_POST['product'] ?? 0 ), wp_unslash( (string) ( $_POST['name'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Sanitized in rename().
        self::done( 'insights-products', 'Product renamed.' );
    }

    public static function action_product_rotate(): void
    {
        self::guard( 'insr_product_rotate' );
        Products::rotate_key( absint( $_POST['product'] ?? 0 ) );
        self::done( 'insights-products', 'New key created. Update the product’s SDK config.' );
    }

    public static function action_product_delete(): void
    {
        self::guard( 'insr_product_delete' );
        Products::delete( absint( $_POST['product'] ?? 0 ) );
        self::done( 'insights-products', 'Product and its data deleted.' );
    }

    public static function action_site_delete(): void
    {
        self::guard( 'insr_site_delete' );
        Data::delete_sites( [ absint( $_POST['site'] ?? 0 ) ] );
        self::done( 'insights-sites', 'Site data erased.' );
    }

    public static function action_export_sites(): void
    {
        check_admin_referer( 'insr_export_sites' );
        if ( ! Plugin::can_view() ) {
            wp_die( '', '', 403 );
        }
        $f             = self::filters();
        $f['per_page'] = 200;
        $cols          = [ 'site_id', 'status', 'plan', 'product_version', 'wp_version', 'php_version', 'locale', 'features_on', 'features_total', 'uses', 'first_seen', 'last_seen' ];

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=insights-sites-' . gmdate( 'Y-m-d' ) . '.csv' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, $cols );
        for ( $f['page'] = 1; ; $f['page']++ ) {
            $res = Query::sites( $f );
            foreach ( $res['items'] as $s ) {
                $s['first_seen'] = gmdate( 'c', $s['first_seen'] );
                $s['last_seen']  = gmdate( 'c', $s['last_seen'] );
                // Neutralise spreadsheet formulas in values that came from remote sites.
                fputcsv( $out, array_map( static function ( $c ) use ( $s ) {
                    $v = (string) $s[ $c ];
                    return preg_match( '/^[=+\-@]/', $v ) ? "'" . $v : $v;
                }, $cols ) );
            }
            if ( $f['page'] * $f['per_page'] >= $res['total'] ) {
                break;
            }
        }
        fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        exit;
    }

    private static function guard( string $action ): void
    {
        check_admin_referer( $action );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '', '', 403 );
        }
    }

    private static function done( string $page, string $message ): void
    {
        set_transient( 'insr_notice_' . get_current_user_id(), $message, 60 );
        wp_safe_redirect( self::url( $page ) );
        exit;
    }

    // ── Pieces ───────────────────────────────────────────────────────────────

    private static function filters(): array
    {
        $in         = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- Read-only view; sanitized in filters().
        $in['page'] = $in['p'] ?? 1; // wp-admin uses "page" for the screen.
        return Query::filters( $in );
    }

    /** Filter values to carry into links. */
    private static function keep( array $f, bool $all = false ): array
    {
        $keep = [ 'product' => $f['product'], 'days' => $f['days'], 'status' => $f['status'], 'plan' => $f['plan'] ];
        if ( $all ) {
            $keep += [ 'search' => $f['search'], 'feature' => $f['feature'], 'state' => $f['state'], 'orderby' => $f['orderby'], 'order' => strtolower( $f['order'] ) ];
        }
        return array_filter( $keep, static function ( $v ) {
            return '' !== $v && null !== $v;
        } );
    }

    private static function url( string $page, array $args = [] ): string
    {
        return add_query_arg( array_merge( [ 'page' => $page ], $args ), admin_url( 'admin.php' ) );
    }

    private static function header( string $title, array $f, string $back = '' ): void
    {
        echo '<div class="wrap insr">';
        if ( $back ) {
            echo '<p><a href="' . esc_url( $back ) . '">← Features</a></p>';
        }
        echo '<h1>' . esc_html( $title ) . '</h1>';
        self::notice();
    }

    private static function notice(): void
    {
        $key = 'insr_notice_' . get_current_user_id();
        $msg = get_transient( $key );
        if ( $msg ) {
            delete_transient( $key );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
        }
    }

    private static function no_products(): void
    {
        echo '<div class="insr-card"><p>No products yet. <a href="' . esc_url( self::url( 'insights-products' ) ) . '">Add your first product</a> to get its key and SDK snippet.</p></div></div>';
    }

    private static function filter_bar( string $page, array $f, bool $sites = false, bool $feature_page = false, ?float $threshold = null ): void
    {
        $products = Products::all();
        echo '<form method="get" class="insr-filters"><input type="hidden" name="page" value="' . esc_attr( $page ) . '">';
        if ( $feature_page ) {
            echo '<input type="hidden" name="feature" value="' . esc_attr( $f['feature'] ) . '">';
        }

        echo '<label>Product <select name="product">';
        foreach ( $products as $p ) {
            echo '<option value="' . (int) $p->id . '" ' . selected( $f['product'], (int) $p->id, false ) . '>' . esc_html( $p->name ) . '</option>';
        }
        echo '</select></label>';

        echo '<label>Period <select name="days">';
        foreach ( [ 7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last 12 months' ] as $d => $label ) {
            echo '<option value="' . (int) $d . '" ' . selected( $f['days'], $d, false ) . '>' . esc_html( $label ) . '</option>';
        }
        if ( ! in_array( $f['days'], [ 7, 30, 90, 365 ], true ) ) {
            echo '<option value="' . (int) $f['days'] . '" selected>Last ' . (int) $f['days'] . ' days</option>';
        }
        echo '</select></label>';

        echo '<label>Sites <select name="status">';
        foreach ( [ 'live' => 'Live', 'quiet' => 'Quiet', 'deactivated' => 'Deactivated', 'all' => 'All' ] as $v => $label ) {
            echo '<option value="' . esc_attr( $v ) . '" ' . selected( $f['status'], $v, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></label>';

        $plans = Query::plans( $f['product'] );
        echo '<label>Plan <select name="plan"><option value="">Any</option>';
        foreach ( array_merge( [ 'paid' => 'Paid', 'free' => 'Free', 'trial' => 'Trial' ], array_combine( $plans, $plans ) ?: [] ) as $v => $label ) {
            echo '<option value="' . esc_attr( $v ) . '" ' . selected( $f['plan'], (string) $v, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></label>';

        if ( $sites ) {
            echo '<label>Feature <select name="feature"><option value="">Any</option>';
            foreach ( Query::feature_keys( $f['product'] ) as $key ) {
                echo '<option value="' . esc_attr( $key ) . '" ' . selected( $f['feature'], $key, false ) . '>' . esc_html( $key ) . '</option>';
            }
            echo '</select></label><label>is <select name="state"><option value="">either</option>'
                . '<option value="enabled" ' . selected( $f['state'], 'enabled', false ) . '>on</option>'
                . '<option value="disabled" ' . selected( $f['state'], 'disabled', false ) . '>off</option></select></label>'
                . '<label>Search <input type="search" name="search" value="' . esc_attr( $f['search'] ) . '" placeholder="Site ID contains…"></label>'
                . '<input type="hidden" name="orderby" value="' . esc_attr( $f['orderby'] ) . '"><input type="hidden" name="order" value="' . esc_attr( strtolower( $f['order'] ) ) . '">';
        }
        if ( null !== $threshold ) {
            echo '<label>Paid features on below <select name="threshold">';
            foreach ( [ 0.25, 0.5, 0.75, 1.0 ] as $t ) {
                echo '<option value="' . esc_attr( $t ) . '" ' . selected( abs( $threshold - $t ) < 0.001, true, false ) . '>' . esc_html( $t * 100 ) . '%</option>';
            }
            echo '</select></label>';
        }
        echo '<button class="button">Apply</button></form>';
    }

    private static function tile( string $label, int $value, string $sub = '', string $url = '' ): void
    {
        self::tile_text( $label, number_format_i18n( $value ), $sub, $url );
    }

    private static function tile_text( string $label, string $value, string $sub = '', string $url = '' ): void
    {
        $inner = '<div class="insr-tile-label">' . esc_html( $label ) . '</div><div class="insr-tile-value">' . esc_html( $value ) . '</div>'
            . ( $sub ? '<div class="insr-tile-sub">' . esc_html( $sub ) . '</div>' : '' );
        echo $url ? '<a class="insr-tile" href="' . esc_url( $url ) . '">' . $inner . '</a>' : '<div class="insr-tile">' . $inner . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- Escaped above.
    }

    private static function card( string $title, string $desc, string $body, string $size = '' ): void
    {
        echo '<section class="insr-card' . ( $size ? ' insr-' . esc_attr( $size ) : '' ) . '"><h2>' . esc_html( $title ) . '</h2>'
            . ( $desc ? '<p class="insr-muted">' . esc_html( $desc ) . '</p>' : '' )
            . $body // phpcs:ignore WordPress.Security.EscapeOutput -- Built from escaped parts by Charts and this class.
            . '</section>';
    }

    private static function slices( array $rows ): array
    {
        return array_map( static function ( $r ) {
            return [ 'label' => $r['label'], 'value' => $r['sites'] ];
        }, $rows );
    }

    private static function bar_rows( array $rows ): array
    {
        return array_map( static function ( $r ) {
            return [ 'label' => $r['label'], 'on' => $r['sites'], 'off' => null ];
        }, array_slice( $rows, 0, 10 ) );
    }

    private static function status_chip( string $status ): string
    {
        $labels = [ 'live' => '● Live', 'quiet' => '◐ Quiet', 'deactivated' => '○ Deactivated' ];
        return '<span class="insr-status insr-status-' . esc_attr( $status ) . '">' . esc_html( $labels[ $status ] ?? $status ) . '</span>';
    }

    private static function status_text( string $status ): string
    {
        return 'all' === $status ? 'all' : $status;
    }

    private static function sort_link( string $key, string $label, array $f ): string
    {
        if ( 'status' === $key || 'product_version' === $key ) {
            return esc_html( $label );
        }
        $current = $f['orderby'] === $key;
        $order   = $current && 'DESC' === $f['order'] ? 'asc' : 'desc';
        return '<a href="' . esc_url( self::url( 'insights-sites', [ 'orderby' => $key, 'order' => $order ] + self::keep( $f, true ) ) ) . '">' . esc_html( $label ) . ( $current ? ( 'DESC' === $f['order'] ? ' ↓' : ' ↑' ) : '' ) . '</a>';
    }

    private static function pagination( int $total, array $f, string $page ): void
    {
        $pages = (int) ceil( $total / $f['per_page'] );
        if ( $pages < 2 ) {
            return;
        }
        $args = self::keep( $f, true );
        if ( 'insights-features' === $page ) {
            $args['feature'] = $f['feature'];
        }
        // The "page" query var is taken by wp-admin, so list pages use "p" (see filters()).
        echo '<p class="insr-pages">Page ' . (int) $f['page'] . ' of ' . (int) $pages . ' ';
        if ( $f['page'] > 1 ) {
            echo '<a class="button" href="' . esc_url( add_query_arg( 'p', $f['page'] - 1, self::url( $page, $args ) ) ) . '">← Previous</a> ';
        }
        if ( $f['page'] < $pages ) {
            echo '<a class="button" href="' . esc_url( add_query_arg( 'p', $f['page'] + 1, self::url( $page, $args ) ) ) . '">Next →</a>';
        }
        echo '</p>';
    }

    private static function post_button( string $action, string $label, array $fields, string $class, string $confirm = '' ): string
    {
        $html = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="insr-inline"' . ( $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' : '' ) . '>'
            . wp_nonce_field( 'insr_' . $action, '_wpnonce', true, false )
            . '<input type="hidden" name="action" value="insr_' . esc_attr( $action ) . '">';
        foreach ( $fields as $k => $v ) {
            $html .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
        }
        return $html . '<button class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
    }

    private static function kv( string $k, string $v ): string
    {
        return '<tr><th>' . esc_html( $k ) . '</th><td>' . esc_html( '' === $v ? '—' : $v ) . '</td></tr>';
    }

    private static function ago( int $ts ): string
    {
        if ( ! $ts ) {
            return '—';
        }
        return $ts > time() - 60 ? 'just now' : sprintf( '%s ago', human_time_diff( $ts ) );
    }

    private static function date( int $ts ): string
    {
        return $ts ? wp_date( get_option( 'date_format' ), $ts ) : '—';
    }

    private static function datetime( int $ts ): string
    {
        return $ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '';
    }
}
