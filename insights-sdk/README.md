# Insights SDK (working name)

A drop-in, opt-in feature-usage reporter for WordPress plugins and themes. It is built to answer two questions for the product owner:

1. **Who is leaving money on the table?** Paid installs that have many paid features turned off or unused.
2. **Which features don't stick?** Features that are switched off soon after being switched on, or left on but never used.

The matching self-hosted backend is the Insights Receiver plugin ([`../insights-receiver/`](../insights-receiver/)): add a product there to get the `key` and `endpoint` for the init call.

To use it, copy `start.php` and `src/` into your product's `vendor/insights-sdk/`. [`../insights-sdk-example/`](../insights-sdk-example/) is a working example.

## Using it

```php
require_once __DIR__ . '/vendor/insights-sdk/start.php';

insights_sdk_init( [
    'slug'     => 'myplugin',         // Prefix: options myplugin_insights_*, cron myplugin_insights_snapshot
    'name'     => 'My Plugin',
    'key'      => 'pk_…',
    'endpoint' => 'https://…/v1',
    'version'  => '1.2.3',            // String or callback
    'file'     => __FILE__,           // Enables the deactivation ping (plugins only)
    'features' => fn() => [
        'exports' => [ 'tier' => 'pro',  'enabled' => (bool) get_option( 'myplugin_exports' ) ],
        'search'  => [ 'tier' => 'free', 'enabled' => true ],
    ],
    'plan'     => fn() => 'pro',      // Optional: string or callback
    'site_id'  => fn() => home_url(), // Required: how the backend identifies this install (URL, licence ID…)
    'notice'   => true,               // Built-in consent notice on the Dashboard and Plugins screens
    'strings'  => [ 'disclosure' => __( '…', 'myplugin' ), 'allow' => …, 'deny' => … ],
] );

// Any time after plugins_loaded:
if ( $insights = insights_sdk( 'myplugin' ) ) {
    $insights->track( 'exports.run' );       // One use. The part before the first dot is the feature.
    $insights->event( 'upgrade_clicked' );   // Sent at the end of the request
    $insights->sync_features();              // Optional, right after a toggle, for exact timings
}
```

Consent controls (for a host's own UI, with `'notice' => false`): `opt_in()`, `opt_out()`, `is_opted_in()`, `choice()`. Call `uninstall()` from the host's uninstall routine, unless the host already deletes options and cron hooks that start with its own prefix.

`key` and `endpoint` belong in the init call itself, the same way Freemius takes its public key. If either one, or `site_id`, is empty, the product doesn't start: `insights_sdk()` returns null and no opt-in is offered. To override a config on one site (for example to point staging or a test suite at another backend), use the `insights_sdk_config` filter from a must-use plugin.

To stop sending on a particular site (for example a staging copy), define `INSIGHTS_SDK_DISABLE` as true or return false from the `insights_sdk_should_send` filter. To change a snapshot before it is sent, use the `{slug}_insights_payload` filter.

### Several products on one site

Each copy of `start.php` registers its version, and on `plugins_loaded` (priority 1) the newest copy's classes are loaded for every product. The functions in `start.php` (`insights_sdk_init`, `insights_sdk`, `insights_sdk_boot`) come from whichever copy loads first, so **their signatures must not change between versions**. Put new behaviour in `src/`.

## Backend contract

Every request is a JSON `POST`. The product key is sent both in the `X-Insights-Key` header and as `key` in the body.

### `POST {endpoint}/ingest`

There are three kinds of ingest request. All of them carry these base fields:

```json
{
  "key": "pk_…",
  "sdk_version": "1.0.0",
  "site_id": "whatever the host passes as site_id, e.g. https://example.com",
  "plan": "pro | trial:pro | null",
  "product_version": "2.0.1",
  "env": { "wp": "6.9.1", "php": "8.2.0", "multisite": false, "locale": "en_GB" },
  "sent_at": 1761700000
}
```

- **Weekly snapshot** (blocking: the site keeps its counters until it gets a 2xx). The snapshot adds:
  ```json
  {
    "active": true,
    "features": {
      "exports": { "tier": "pro", "enabled": true, "enabled_since": 1761000000, "uses": 12, "last_used": 1761690000 }
    },
    "usage":  { "exports.run": { "uses": 12, "last_used": 1761690000 } },
    "events": [ { "name": "feature_enabled", "value": "exports", "at": 1761000000 } ]
  }
  ```
  `enabled_since` is `null` when it isn't known, because the feature was already in that state at the first snapshot. `uses` and `usage` count only the uses **since the previous successful snapshot**, so the backend adds them up.
- **Events** (fire-and-forget): the base fields plus `events`.
- **Deactivation** (fire-and-forget): the base fields plus `"active": false`.

### `POST {endpoint}/erase`

`{ "key": "…", "site_id": "…" }` is sent on opt-out. The backend deletes everything it holds for that `site_id` under that key.

## Reports to build on the backend

- **Money on the table:** paid installs (`plan` set, not a trial) where the share of the plan's tier features that are enabled, or used in the last 30 days, is below a threshold. List them by `site_id`. Also: free installs making heavy use of free features that sit next to pro features.
- **Stickiness, per feature:**
  - the share of installs that enabled it and still have it on after 7, 30 and 90 days (from `feature_enabled` / `feature_disabled` events and `enabled_since`);
  - the share that are enabled but have zero uses after 30 days;
  - the median time to disable.
- **Liveness:** if an install's last snapshot is more than 2 weeks old and there was no `active: false` ping, it went quiet. With the ping, it was deactivated.

Note that "used" means whatever the host tracks. Some features, such as a security hardening module, do their job silently while enabled. For those, enabled time is the better measure.
