# Insights

Opt-in feature-usage analytics for WordPress plugins and themes. You host it yourself.

Find out which of your product's features are actually switched on and used, which ones get dropped soon after being turned on, and which paying customers aren't using what they pay for.

| Folder | What it is |
|---|---|
| [`insights-sdk/`](insights-sdk/) | The drop-in SDK. Bundle it in your plugin or theme (e.g. `vendor/insights-sdk/`) and call `insights_sdk_init()`. Nothing is sent until a site administrator opts in. |
| [`insights-receiver/`](insights-receiver/) | A WordPress plugin you install on your own site to receive the data. It has dashboards, per-site activity, feature retention, upsell opportunities, a REST query API, and MCP tools (via the Abilities API). |
| [`insights-sdk-example/`](insights-sdk-example/) | A demo plugin: a settings screen whose options are reported as features, a built-in mock backend, and a debug panel showing what the SDK stores and sends. |

## Quick start

1. Install and activate `insights-receiver` on a WordPress 6.9+ site you control.
2. Go to **Insights → Products** and add your product. It shows a key and a ready-to-paste init snippet.
3. Copy `insights-sdk/start.php` and `insights-sdk/src/` into your product's `vendor/insights-sdk/`, then add the snippet:

```php
require_once __DIR__ . '/vendor/insights-sdk/start.php';

insights_sdk_init( [
    'slug'     => 'myplugin',
    'name'     => 'My Plugin',
    'key'      => 'pk_…',                              // From the receiver.
    'endpoint' => 'https://your-site.com/wp-json/insights/v1',
    'site_id'  => fn() => home_url(),
    'version'  => '1.0.0',
    'file'     => __FILE__,
    'features' => fn() => [
        'exports' => [ 'tier' => 'pro',  'enabled' => (bool) get_option( 'myplugin_exports' ) ],
        'search'  => [ 'tier' => 'free', 'enabled' => true ],
    ],
] );
```

See [`insights-sdk/README.md`](insights-sdk/README.md) for the full SDK API and the data format the receiver accepts.

## What gets sent

Only after an administrator opts in, about once a week: the `site_id` your product chooses (often the site address), each feature's tier and on/off state and since when, use counts, the plan, and the product, WordPress and PHP versions. Nothing about the site's visitors or users is sent. Opting out deletes the local data and asks the receiver to erase the site.

## Tests

The tests run in a throwaway [WordPress Playground](https://wordpress.github.io/wordpress-playground/) site (Node 20+):

```sh
cd insights-receiver/tests && npm install && npm test
```

The receiver suite includes an end-to-end run in which the real SDK (through the example plugin) reports into the receiver.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
