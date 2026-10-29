/**
 * Insights Receiver in a throwaway WordPress Playground site (SQLite, so the
 * SQL is checked for portability too): ingest validation, aggregation, the
 * query API and abilities, every admin screen rendering without PHP errors,
 * erase, and an end-to-end run where the real SDK (via the Insights SDK
 * Example plugin) reports into the receiver.
 *
 * Run: npm test
 */
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:net';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCLI } from '@wp-playground/cli';

const here = dirname(fileURLToPath(import.meta.url));
const PLUGINS = resolve(here, '../..');
let cli;
let ids = {};

const PHP_ERROR = /(Fatal error|Warning|Notice|Deprecated)\s*:\s*[^\n]{0,500}? in \/[^\s]+ on line \d+/g;

function freePort() {
  return new Promise((ok) => { const s = createServer().listen(0, () => { const { port } = s.address(); s.close(() => ok(port)); }); });
}

/** Run PHP in WordPress as user 1; `code` returns a JSON-encodable value. Fails on displayed PHP errors. */
async function php(code) {
  const r = await cli.playground.run({ code: `<?php
    ini_set( 'html_errors', '0' );
    require '/wordpress/wp-load.php';
    wp_set_current_user( 1 );
    if ( ! function_exists( 'insr_post' ) ) {
      /** POST to an ingest route the way the SDK does. */
      function insr_post( $path, $body, $key = null ) {
        $r = new WP_REST_Request( 'POST', '/insights/v1/' . $path );
        $r->set_header( 'content-type', 'application/json' );
        if ( $key ) $r->set_header( 'x-insights-key', $key );
        $r->set_body( wp_json_encode( $body ) );
        $res = rest_do_request( $r );
        return [ 'status' => $res->get_status(), 'data' => $res->get_data() ];
      }
      function insr_get( $route, $params = [] ) {
        $r = new WP_REST_Request( 'GET', '/insights/v1' . $route );
        $r->set_query_params( $params );
        $res = rest_do_request( $r );
        return [ 'status' => $res->get_status(), 'data' => $res->get_data() ];
      }
    }
    $__v = ( function () { ${code} } )();
    echo "\\n@@RESULT@@" . wp_json_encode( $__v );
  ` });
  const text = r.text ?? '';
  const at = text.lastIndexOf('@@RESULT@@');
  assert.ok(at >= 0, `PHP failed:\n${text.slice(0, 3000)}`);
  assert.deepEqual(text.slice(0, at).match(PHP_ERROR) ?? [], [], 'PHP errors');
  return JSON.parse(text.slice(at + '@@RESULT@@'.length));
}

async function adminCookies() {
  return php(`$exp = time() + DAY_IN_SECONDS; return [
    [ AUTH_COOKIE, wp_generate_auth_cookie( 1, $exp, 'auth' ) ],
    [ LOGGED_IN_COOKIE, wp_generate_auth_cookie( 1, $exp, 'logged_in' ) ] ];`);
}

before(async () => {
  cli = await runCLI({
    command: 'server', php: '8.2', wp: 'latest', port: await freePort(), workers: 1, quiet: true,
    mount: [
      { hostPath: resolve(PLUGINS, 'insights-receiver'), vfsPath: '/wordpress/wp-content/plugins/insights-receiver' },
      { hostPath: resolve(PLUGINS, 'insights-sdk-example'), vfsPath: '/wordpress/wp-content/plugins/insights-sdk-example' },
    ],
    blueprint: { steps: [
      { step: 'defineWpConfigConsts', consts: { WP_DEBUG: true, WP_DEBUG_DISPLAY: true, WP_DEBUG_LOG: false, DISABLE_WP_CRON: true } },
      { step: 'activatePlugin', pluginPath: 'insights-receiver/insights-receiver.php' },
    ] },
  });
  await cli.playground.request({ url: '/' }).catch(() => {});
});

after(async () => { await cli?.[Symbol.asyncDispose](); });

test('activation creates the tables; products get keys', async () => {
  const v = await php(String.raw`
    global $wpdb;
    $tables = [];
    foreach ( [ 'products', 'sites', 'site_features', 'usage', 'events', 'activity' ] as $t ) {
      $tables[ $t ] = null !== $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \InsightsReceiver\Schema::table( $t ) );
    }
    $a = \InsightsReceiver\Products::create( 'Plugin A' );
    $b = \InsightsReceiver\Products::create( 'SDK Example' );
    return [ 'tables' => $tables, 'a' => \InsightsReceiver\Products::get( $a ), 'b' => \InsightsReceiver\Products::get( $b ) ];`);
  assert.ok(Object.values(v.tables).every(Boolean), JSON.stringify(v.tables));
  assert.match(v.a.public_key, /^pk_[A-Za-z0-9]{32}$/);
  ids = { a: +v.a.id, b: +v.b.id, keyA: v.a.public_key, keyB: v.b.public_key };
});

test('ingest refuses bad requests', async () => {
  const v = await php(String.raw`return [
    'nokey'  => insr_post( 'ingest', [ 'site_id' => 'x' ] )['status'],
    'badkey' => insr_post( 'ingest', [ 'site_id' => 'x' ], 'pk_nope' )['status'],
    'nosite' => insr_post( 'ingest', [ 'features' => [] ], ${JSON.stringify(ids.keyA)} )['status'],
    'bodykey'=> insr_post( 'ingest', [ 'key' => ${JSON.stringify(ids.keyA)}, 'site_id' => 'https://probe.test', 'events' => [] ] )['status'],
  ];`);
  assert.deepEqual(v, { nokey: 401, badkey: 401, nosite: 400, bodykey: 200 });
});

test('snapshots, events, usage and deactivation are aggregated', async () => {
  const v = await php(String.raw`
    $k   = ${JSON.stringify(ids.keyA)};
    $now = time();
    $snap = function ( $site, $plan, $features, $usage = [], $events = [] ) use ( $k ) {
      return insr_post( 'ingest', [ 'site_id' => $site, 'plan' => $plan, 'product_version' => '2.0.1', 'sdk_version' => '1.0.0',
        'env' => [ 'wp' => '6.9', 'php' => '8.2.1', 'multisite' => false, 'locale' => 'en_GB' ],
        'active' => true, 'features' => $features, 'usage' => $usage, 'events' => $events ], $k )['status'];
    };
    $f = function ( $tier, $on, $uses = 0 ) { return [ 'tier' => $tier, 'enabled' => $on, 'enabled_since' => null, 'uses' => $uses, 'last_used' => $uses ? time() : null ]; };
    $st = [];
    $st[] = $snap( 'https://s1.test', 'pro', [ 'exports' => $f( 'pro', true, 3 ), 'reports' => $f( 'pro', false ), 'dark' => $f( 'free', true ) ],
      [ 'exports.run' => [ 'uses' => 3, 'last_used' => $now ] ],
      [ [ 'name' => 'feature_enabled', 'value' => 'reports', 'at' => $now - 10 * DAY_IN_SECONDS ], [ 'name' => 'feature_disabled', 'value' => 'reports', 'at' => $now - 4 * DAY_IN_SECONDS ] ] );
    $st[] = $snap( 'https://s2.test', 'pro', [ 'exports' => $f( 'pro', false ), 'reports' => $f( 'pro', false ), 'dark' => $f( 'free', true ) ] );
    $st[] = $snap( 'https://s3.test', 'free', [ 'exports' => $f( 'pro', false ), 'reports' => $f( 'pro', false ), 'dark' => $f( 'free', true ) ] );
    $st[] = $snap( 'https://s4.test', 'pro', [ 'exports' => $f( 'pro', true ), 'dark' => $f( 'free', true ) ] );
    $st[] = insr_post( 'ingest', [ 'site_id' => 'https://s4.test', 'active' => false ], $k )['status'];
    // A second snapshot from s1 adds to its totals and drops a feature it no longer declares.
    $st[] = $snap( 'https://s1.test', 'pro', [ 'exports' => $f( 'pro', true, 2 ), 'reports' => $f( 'pro', false ), 'dark' => $f( 'free', true ) ], [ 'exports.run' => [ 'uses' => 2, 'last_used' => $now ] ] );

    $F = \InsightsReceiver\Query::filters( [ 'product' => ${ids.a}, 'days' => 30 ] );
    $stats = [];
    foreach ( \InsightsReceiver\Query::feature_stats( $F ) as $s ) $stats[ $s['feature'] ] = $s;
    return [
      'statuses' => $st,
      'overview' => \InsightsReceiver\Query::overview( $F ),
      'stats'    => $stats,
      'opps'     => \InsightsReceiver\Query::opportunities( $F ),
      'plans'    => \InsightsReceiver\Query::breakdown( $F, 'plan' ),
      'exports_on' => array_column( \InsightsReceiver\Query::sites( [ 'feature' => 'exports', 'state' => 'enabled' ] + $F )['items'], 'site_id' ),
      'series'   => \InsightsReceiver\Query::timeseries( $F ),
    ];`);

  assert.deepEqual(v.statuses, [200, 200, 200, 200, 200, 200]);
  const o = v.overview;
  assert.deepEqual([o.sites_total, o.sites_live, o.sites_quiet, o.sites_deactivated], [5, 4, 0, 1], 'probe + s1–s3 live, s4 deactivated');
  assert.equal(o.features_enabled, 4, 's1: exports+dark, s2: dark, s3: dark');
  assert.equal(o.uses, 5);

  const { exports: ex, reports: rp, dark } = v.stats;
  assert.deepEqual([ex.enabled, ex.disabled, ex.uses, ex.sites_using], [1, 2, 5, 1]);
  assert.deepEqual([rp.enabled, rp.disabled, rp.ever_enabled, rp.retention_pct, rp.median_days_to_disable, rp.enables, rp.disables], [0, 3, 1, 0, 6, 1, 1]);
  assert.equal(dark.enabled, 3);

  assert.equal(v.opps.total, 1, 'only s2 (pro, 0 of 2 paid features on); s1 is at 50%');
  assert.equal(v.opps.items[0].site_id, 'https://s2.test');
  assert.deepEqual(v.opps.items[0].paid_features_off, ['exports', 'reports']);

  assert.equal(v.plans.find(p => p.label === 'pro').sites, 2);
  assert.deepEqual(v.exports_on, ['https://s1.test']);
  const today = v.series.at(-1);
  assert.equal(today.uses, 5);
  assert.equal(today.active_sites, 5);
});

test('site detail: features, usage by action, timeline', async () => {
  const v = await php(String.raw`
    $id = \InsightsReceiver\Data::find_site( ${ids.a}, 'https://s1.test' );
    return \InsightsReceiver\Query::site( $id, 30 );`);
  assert.equal(v.features.length, 3);
  assert.deepEqual(v.usage_by_action, [{ action: 'exports.run', uses: 5 }]);
  assert.equal(v.features.find(f => f.feature === 'exports').uses_total, 5);
  assert.ok(v.timeline.some(t => t.name === 'feature_disabled' && t.value === 'reports'));
  assert.equal(v.timeline.filter(t => t.type === 'received').length, 2);
  ids.s1 = v.id;
});

test('query API: needs the capability; returns the same data', async () => {
  const v = await php(String.raw`
    wp_set_current_user( 0 );
    $anon = insr_get( '/features', [ 'product' => ${ids.a} ] )['status'];
    wp_set_current_user( 1 );
    return [
      'anon'     => $anon,
      'products' => insr_get( '/products' ),
      'features' => insr_get( '/features', [ 'product' => ${ids.a} ] ),
      'feature'  => insr_get( '/features/reports', [ 'product' => ${ids.a}, 'state' => 'disabled' ] ),
      'site'     => insr_get( '/site', [ 'product' => ${ids.a}, 'site_id' => 'https://s1.test' ] )['status'],
      'site404'  => insr_get( '/sites/999999' )['status'],
      'sites'    => insr_get( '/sites', [ 'product' => ${ids.a}, 'status' => 'all', 'orderby' => 'site_id', 'order' => 'asc' ] ),
      'events'   => insr_get( '/events', [ 'product' => ${ids.a}, 'feature' => 'reports' ] ),
      'bd'       => insr_get( '/breakdown/php_version', [ 'product' => ${ids.a} ] ),
      'bdbad'    => insr_get( '/breakdown/nope', [ 'product' => ${ids.a} ] )['status'],
      'opps'     => insr_get( '/opportunities', [ 'product' => ${ids.a}, 'threshold' => 0.75 ] ),
      'ts'       => count( insr_get( '/timeseries', [ 'product' => ${ids.a}, 'days' => 7 ] )['data'] ),
    ];`);
  assert.equal(v.anon, 401);
  assert.equal(v.products.data.length, 2);
  assert.equal(v.features.status, 200);
  assert.equal(v.features.data.find(f => f.feature === 'reports').ever_enabled, 1);
  assert.equal(v.feature.data.sites.total, 3);
  assert.equal(v.site, 200);
  assert.equal(v.site404, 404);
  assert.deepEqual(v.sites.data.items.map(s => s.site_id), ['https://probe.test', 'https://s1.test', 'https://s2.test', 'https://s3.test', 'https://s4.test']);
  assert.equal(v.sites.data.items.find(s => s.site_id === 'https://s4.test').status, 'deactivated');
  assert.equal(v.events.data.total, 2);
  assert.deepEqual(v.bd.data, [{ label: '8.2.1', sites: 3 }, { label: '(none)', sites: 1 }].sort((a, b) => b.sites - a.sites));
  assert.equal(v.bdbad, 400);
  assert.equal(v.opps.data.total, 2, 'at 75%, s1 (50%) is listed too');
  assert.equal(v.ts, 7);
});

test('abilities are registered for MCP and run', async () => {
  const v = await php(String.raw`
    $names = array_keys( array_filter( wp_get_abilities(), fn( $a ) => 0 === strpos( $a->get_name(), 'insights/' ) ) );
    $ov = wp_get_ability( 'insights/get-overview' )->execute( [ 'product' => ${ids.a} ] );
    $lp = wp_get_ability( 'insights/list-products' )->execute();
    $noin = wp_get_ability( 'insights/get-feature-stats' )->execute();
    $fs = wp_get_ability( 'insights/list-feature-sites' )->execute( [ 'product' => ${ids.a}, 'feature' => 'exports', 'state' => 'enabled' ] );
    $gs = wp_get_ability( 'insights/get-site' )->execute( [ 'product' => ${ids.a}, 'site_id' => 'https://s2.test' ] );
    return [ 'names' => $names, 'live' => is_wp_error( $ov ) ? $ov->get_error_message() : $ov['sites_live'],
      'products' => is_wp_error( $lp ) ? $lp->get_error_message() : count( $lp ),
      'fs' => is_wp_error( $fs ) ? $fs->get_error_message() : $fs['total'],
      'site' => is_wp_error( $gs ) ? $gs->get_error_message() : $gs['site_id'],
      'noinput' => is_wp_error( $noin ) ? $noin->get_error_message() : count( $noin ) ];`);
  assert.equal(v.names.length, 10, v.names.join(', '));
  assert.equal(v.live, 4);
  assert.equal(v.products, 2);
  assert.equal(v.fs, 1);
  assert.equal(v.site, 'https://s2.test');
  assert.equal(v.noinput, 3, 'callable with no input (defaults to the first product)');
});

test('every admin screen renders without PHP errors', async () => {
  const cookies = (await adminCookies()).map(([n, v]) => `${n}=${encodeURIComponent(v)}`).join('; ');
  const pages = [
    'admin.php?page=insights', `admin.php?page=insights&product=${ids.a}&days=7&status=all&plan=paid`,
    `admin.php?page=insights-sites&product=${ids.a}&status=all&feature=exports&state=enabled&orderby=uses&order=asc`,
    `admin.php?page=insights-sites&site=${ids.s1}`, `admin.php?page=insights-features&product=${ids.a}`,
    `admin.php?page=insights-features&product=${ids.a}&feature=reports`, `admin.php?page=insights-opportunities&product=${ids.a}&threshold=0.75`,
    'admin.php?page=insights-products', 'admin.php?page=insights-settings',
  ];
  const bad = [];
  for (const url of pages) {
    const r = await cli.playground.request({ url: `/wp-admin/${url}`, headers: { Cookie: cookies } });
    const errors = r.text.match(PHP_ERROR) ?? [];
    if (r.httpStatusCode !== 200 || errors.length || !r.text.includes('class="wrap insr"')) bad.push(`${r.httpStatusCode} ${url} ${errors.join(' | ')}`);
  }
  assert.deepEqual(bad, []);
});

test('rate limit and erase', async () => {
  const v = await php(String.raw`
    update_option( 'insr_settings', [ 'rate_limit' => 10 ] + get_option( 'insr_settings', [] ) );
    $codes = [];
    for ( $i = 0; $i < 12; $i++ ) $codes[] = insr_post( 'ingest', [ 'site_id' => 'https://flood.test', 'events' => [] ], ${JSON.stringify(ids.keyB)} )['status'];
    delete_option( 'insr_settings' );
    global $wpdb;
    $wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '%insr_rl_%'" );
    $erase = insr_post( 'erase', [ 'site_id' => 'https://s3.test' ], ${JSON.stringify(ids.keyA)} )['status'];
    return [ 'codes' => array_count_values( $codes ), 'erase' => $erase, 'gone' => 0 === \InsightsReceiver\Data::find_site( ${ids.a}, 'https://s3.test' ),
      'left' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \InsightsReceiver\Schema::table( 'site_features' ) . ' sf LEFT JOIN ' . \InsightsReceiver\Schema::table( 'sites' ) . ' s ON s.id = sf.site_pk WHERE s.id IS NULL' ) ];`);
  assert.equal(v.codes['429'], 2);
  assert.equal(v.erase, 200);
  assert.equal(v.gone, true);
  assert.equal(v.left, 0, 'no orphaned feature rows');
});

test('end to end: the real SDK reports into the receiver, and opting out erases', async () => {
  // Point the example plugin at this receiver, and deliver its HTTP calls to the ingest handlers directly.
  await php(String.raw`wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' ); file_put_contents( WP_CONTENT_DIR . '/mu-plugins/insr-e2e.php', '<?php
    add_filter( "insights_sdk_config", function ( $c ) {
      return "iex" === $c["slug"] ? [ "key" => ${JSON.stringify(ids.keyB)}, "endpoint" => "https://receiver.test/v1" ] + $c : $c;
    } );
    add_filter( "pre_http_request", function ( $pre, $args, $url ) {
      if ( 0 !== strpos( $url, "https://receiver.test/v1/" ) ) return $pre;
      $req = new WP_REST_Request( "POST", "/insights/v1/" . basename( $url ) );
      foreach ( $args["headers"] as $k => $v ) $req->set_header( $k, $v );
      $req->set_body( $args["body"] );
      $req->set_header( "content-type", "application/json" );
      $res = rest_do_request( $req );
      return [ "headers" => [], "body" => wp_json_encode( $res->get_data() ), "response" => [ "code" => $res->get_status(), "message" => "" ], "cookies" => [], "filename" => null ];
    }, 10, 3 );' ); return true;`);
  await php(`activate_plugin( 'insights-sdk-example/insights-sdk-example.php' ); return true;`);

  const v = await php(String.raw`
    $sdk = insights_sdk( 'iex' );
    $sdk->opt_in();
    update_option( 'iex_plan', 'pro' );
    update_option( 'iex_settings', [ 'dark_mode' => 1, 'exports' => 1, 'scheduled_reports' => 0 ] );
    $sdk->track( 'exports.run' );
    $sdk->track( 'exports.run' );
    $sent = $sdk->send_snapshot();
    $id   = \InsightsReceiver\Data::find_site( ${ids.b}, home_url() );
    return [ 'sent' => $sent, 'site' => $id ? \InsightsReceiver\Query::site( $id, 30 ) : null ];`);
  assert.equal(v.sent, true, 'snapshot accepted');
  assert.ok(v.site, 'site recorded under the example product');
  assert.equal(v.site.plan, 'pro');
  assert.deepEqual(v.site.features.filter(f => f.enabled).map(f => f.feature).sort(), ['dark_mode', 'exports']);
  assert.equal(v.site.features.find(f => f.feature === 'exports').uses_total, 2);
  assert.equal(v.site.features.find(f => f.feature === 'exports').tier, 'pro');

  const after = await php(String.raw`insights_sdk( 'iex' )->opt_out(); return \InsightsReceiver\Data::find_site( ${ids.b}, home_url() );`);
  assert.equal(after, 0, 'erased on opt-out');
});
