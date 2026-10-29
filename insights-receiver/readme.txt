=== Insights Receiver ===
Contributors: davegrey
Tags: analytics, telemetry, feature usage, plugin developers
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted backend for the Insights SDK. See which features of your plugins and themes are running, used, and kept.

== Description ==

Install this on a WordPress site you control. Your plugins and themes bundle the Insights SDK and, on sites whose administrators opt in, report which of their features are switched on and how often they're used.

* **Dashboard:** sites live, quiet and deactivated; what's running (each feature on vs off); sites reporting, uses, switches and new sites per day; plans, versions, WordPress and PHP.
* **Sites:** filter by status, plan, search, or "feature X is on/off"; sort; export CSV. Each site has its features, uses per day and action, and a timeline of everything it sent.
* **Features:** on, off, ever on, still-on %, on but unused for 30 days, uses, switches, and median days to switch off. Each feature lists its sites.
* **Opportunities:** paid sites with few of their paid features switched on, and which ones are off.
* **Query API:** read-only REST endpoints under /wp-json/insights/v1/ for integrations, authenticated with application passwords.
* **MCP:** the same queries as WordPress abilities (insights/*), which the WordPress MCP adapter exposes as MCP tools.

Charts are drawn on the server; no external scripts are loaded.

= Privacy =

You are the controller of the data you receive. It contains the site ID each product sends (often the site address), feature on/off state, usage counts, plan, and WordPress/PHP versions; nothing about the reporting sites' visitors. The SDK only sends after an administrator opts in, and opting out sends an erase request that deletes the site here. Data is deleted after the retention period you set (365 days by default).

== Setup ==

1. Activate the plugin, then go to Insights → Products and add a product.
2. Copy the snippet shown into your plugin or theme, which bundles the SDK in vendor/insights-sdk/.
3. Data appears once a site opts in. The first snapshot arrives about a minute later.

= Security =

A product's key ships inside the product, so it isn't a secret. SDK 1.1+ also sends a per-install secret (derived from the reporting site's WordPress salts). The first secret a site sends is kept, hashed; after that, ingest and erase requests for that site without it are refused. Sites on older SDKs keep working without one until they upgrade. If a site's salts change it will be refused: erase it under Insights → Sites and it is re-created on its next report.

Ingest is rate-limited per IP address and product key. Behind a reverse proxy or CDN, return the real client IP from the `insights_receiver_client_ip` filter.

== Changelog ==

= 1.1.0 =
* Per-install site secrets: the public key alone can no longer overwrite or erase a site that has sent one.
* Rate limit applies before the key lookup, per key; client IP is filterable for proxies.
* Multibyte-safe truncation; concurrent first reports from one site no longer orphan data; usage counts are 64-bit.

= 1.0.0 =
* First release.
