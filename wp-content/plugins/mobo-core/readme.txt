=== Mobo Core ===
Contributors: pedramdev
Tags: woocommerce, iran, product sync, mobomobo, order automation
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Requires Plugins: woocommerce, persian-woocommerce
WC requires at least: 8.2
WC tested up to: 10.9
Stable tag: 10.33.19
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WooCommerce to MoboCore for product sync, webhook queues, shipping mapping, checkout validation, and optional order automation.

== Description ==

Mobo Core is a WooCommerce integration plugin built for stores operating in Iran and using the specific `mobomobo.ir` workflow as their Mobo/Mobomobo product and order source. This plugin is not presented as the official plugin of mobomobo.ir unless such authorization is explicitly stated by the service owner.

The plugin connects WooCommerce to the MoboCore service for product synchronization, webhook processing, shipping method mapping, checkout validation, automatic order submission, and operational health checks.

Required plugins: WooCommerce and Persian WooCommerce (`persian-woocommerce`). Mobo Core cannot be activated or bootstrapped without both dependencies.

Main features:

* Step-based product, variation, category, price, and image synchronization.
* Targeted WooCommerce object-cache and page-cache invalidation for updated Mobo products, including LiteSpeed Cache and WP Rocket integrations without Purge All.
* Queue-based webhook processing to avoid timeout in WordPress requests.
* Complete Mobo shipping-method details, WooCommerce mapping, and optional shipping-only class/API-price context for Mobo products.
* Separate shipping mapping for Mobo-only orders and mixed Mobo/non-Mobo orders.
* Optional automatic order submission for Mobo-only and mixed WooCommerce orders.
* Address mapping for checkout country, state, and city values used in Iran.
* Image refresh workflow for legacy images after a full Repair run.
* Always-on operational health reporting for cron, queue, PHP/image capabilities, memory, disk, and debug status.
* Optional order SMS notifications through the Persian WooCommerce SMS plugin.

This plugin requires an active MoboCore account/license for the external synchronization and order automation features. You can buy or manage access at:

http://mobo.codeya.ir/

Sales and activation contact:

* Phone: +989124508218
* Telegram: https://t.me/yazdan_ghadiri
* WhatsApp: https://wa.me/989124508218
* Tel link: tel:+989124508218

Technical support contact:

* Phone: +989367362228
* Telegram: https://t.me/Codeya

== External services ==

This plugin is designed for Iranian WooCommerce stores and a specific external Mobo/Mobomobo source: `mobomobo.ir`.

The plugin may connect to these external services depending on administrator settings:

1. MoboCore service at `mobo.codeya.ir`

Used for license/account access, token-based connection, product synchronization orchestration, webhook processing, queue status, repair/sync workflows, health reporting, and order automation support.

2. Mobo/Mobomobo source at `mobomobo.ir`

Used when checkout validation, cart checking, shipping method retrieval, or automatic order submission is enabled. This is the specific source this plugin is built for.

The plugin may send or receive the following data depending on enabled settings:

* Site domain and license/token information.
* Product, variation, category, price, stock, and image synchronization data.
* Webhook payload references and processing status.
* WooCommerce order data needed for Mobo order submission, including customer name, phone, shipping address, selected shipping method, Mobo product/variation identifiers, and order item quantities.
* Technical health data such as queue counts, cron state, PHP memory, disk space, and debug status.

After the site administrator enters a Token, Mobo Core can communicate with the central service for licensing, synchronization, webhook processing, and always-on operational health reporting. Optional customer-facing workflows such as checkout validation, order submission, address mapping, and legacy image refresh remain controlled separately and are disabled by default on fresh installations.

Service website:

http://mobo.codeya.ir/

Terms of Service:

http://mobo.codeya.ir/terms

Privacy Policy:

http://mobo.codeya.ir/privacy

== Installation ==

1. Upload the `mobo-core` folder to `/wp-content/plugins/`, or install the plugin through the WordPress plugins screen.
2. Install and activate both WooCommerce and Persian WooCommerce (`persian-woocommerce`).
3. Activate Mobo Core through the Plugins screen in WordPress.
4. Go to **Mobo > خرید و فعال سازی** to buy or manage your MoboCore license.
5. Go to **Mobo > اتصال** and enter the Token and Webhook Security Code from MoboCore.
6. Complete address mapping and shipping method mapping before enabling automatic checkout/order workflows.
7. If upgrading from old versions such as version 7, run one full Repair from the dashboard before using image refresh.

== Frequently Asked Questions ==

= Is this plugin for all countries? =

No. This plugin is intended for WooCommerce stores operating in Iran and using the specific `mobomobo.ir` source/workflow.

= Why does Mobo Core refuse to activate? =

Mobo Core requires both WooCommerce and Persian WooCommerce (`persian-woocommerce`). Install and activate both plugins first. On older WordPress versions, Mobo Core shows an activation error; on newer versions, WordPress enforces the `Requires Plugins` header.

= Does this plugin work without MoboCore? =

The admin screens can be opened, but synchronization, license status, webhook processing, health reporting, checkout validation, and Mobo order automation require an active MoboCore account and token.

= Does this plugin connect to mobomobo.ir? =

Yes, when checkout validation, cart checking, shipping method retrieval, or automatic order submission is enabled. The plugin is built for that specific source.

= Does it create WooCommerce shipping methods? =

Yes, after the store manager explicitly runs the one-click shipping installer. Mobo Core then creates or repairs the Mobo product shipping class, Iran-capable zone configuration, one managed WooCommerce method instance per active Mobo shipping method, rule/static/free rate calculation, order mappings, and the shipping-only Mobo price context. Unrelated WooCommerce zones and methods are not deleted. Operational methods that need a previous invoice, warehouse hold, or in-person handling remain disabled until the manager reviews them.

= Does the Mobo API price replace the storefront product price? =

No. When the shipping-package option is enabled, `mobo_api_price` is used only in a cloned package passed to WooCommerce shipping methods. Catalog, cart, checkout, discount, payment, and order item prices remain unchanged.

= Does it send SMS directly? =

No. SMS notifications are sent through the Persian WooCommerce SMS plugin if that plugin is installed, configured, and enabled.

= Is Repair required after upgrading from version 7? =

Yes. Legacy installations should run one full Repair so product maps, image queues, and synchronization state match the current structure.

== Screenshots ==

1. Mobo Core dashboard and sync status.
2. Purchase and activation screen.
3. Connection and license information.
4. WooCommerce to Mobo shipping method mapping.
5. Queue, cron, and image refresh settings.


= Does product synchronization clear page caches? =

Mobo Core always clears WooCommerce product transients, WordPress post/object caches, and the changed product URL. The site administrator can configure deferred archive cache invalidation for product-category/tag archives, Shop, and Home from the Product settings tab. New installations default to a 15-minute batching window; archive invalidation can still be disabled explicitly. LiteSpeed Cache, WP Rocket, W3 Total Cache, and WP Super Cache are handled through their targeted APIs when available; custom integrations can opt in through the warmup filter. Mobo Core does not call wp_cache_flush(), rocket_clean_domain(), litespeed_purge_all, or another full-site purge. Since 10.33.16.2, after a successful targeted page-cache purge, only the current product permalink is queued for an anonymous GET warmup by the real Mobo cron. Category, tag, Shop, Home, and old permalinks are not preloaded by this warmup queue.

== Changelog ==

= 10.33.19 =
* Hardened Plugin Check compliance without changing the synchronization contract or database schema.
* Numeric queue/event/maintenance ID lists now use real `%d` placeholders instead of interpolated integer lists.
* Early REST request detection sanitizes `rest_route` and `REQUEST_URI` before inspection.
* WordPress 5.8 compatibility is preserved while the optional WordPress 6.4+ option-cache priming fast path remains available when the function exists.
* Added narrow, documented Plugin Check exceptions only where SQL is already prepared and the scanner cannot follow internal dynamic placeholder/table fragments.
* Kept the revenue ledger on bounded WooCommerce CRUD/meta queries for both legacy order storage and HPOS compatibility.
* Trimmed the public WordPress.org-style changelog; older release history remains in `docs/internal/changelog-legacy.md`.
* No database schema migration or Portal migration is required.

= 10.33.18 =
* Added `GET /wp-json/mobo-core/v1/portal/revenue-summary` for Portal on-demand revenue visibility.
* Added immutable completed-order Revenue Ledger metadata. Mobo/source unit cost is snapshotted before/at successful Mobo order submission so later product price syncs do not rewrite historical profit.
* Mixed WooCommerce orders include only Mobo line items in revenue calculations.
* Revenue summary includes all-time and last-30-day aggregates by currency plus the 10 most recent immutable calculations.
* Uses WooCommerce CRUD/order meta only; no database schema migration is required.
* No Portal database migration is required.

= 10.33.17.6 =
* Fixed lightweight `UpdateVariant` webhook product-context recovery. Alias lookup for `product_guid` / `productId` no longer passes `productId` as a default value to the internal getter.
* Lightweight variant notifications can now recover the real parent product GUID from pulled payload data, notification fields, or the `/{productGuid}/get-variants` URL before desired-state processing.
* Prevents deferred variant events from incorrectly waiting on a literal parent GUID named `productId`.
* No database schema or Portal migration is required.

= 10.33.17.5 =
* Fixed desired-state variation identity convergence when the source recreates a variant with a new GUID but the same attribute signature.
* The fast/no-op variation path now persists the replacement `variant_guid` before authoritative missing-variant cleanup, preventing the correctly reused WooCommerce variation from being deleted as the old GUID.
* No database schema or Portal migration is required.

= 10.33.17.4 =
* Fixed pricing-setting sanitization so negative global fixed/percentage profit values are clamped to zero consistently with the admin UI and legacy save path.
* Corrected Portal settings metadata typing: integer options that default to 0/1 are no longer misreported as booleans, and numeric-looking usernames remain text.
* No database schema or Portal migration is required.

= 10.33.17.3 =
* Shared-media manifest wait/retry is now reported as deferred work instead of an image-worker failure, preventing false image Circuit Breaker trips while the shared writer is still preparing manifests.
* Image queue results now expose a deferred counter separately from failed.
* Maintenance now treats queue rows linked to trashed/auto-draft/non-product posts as orphaned rows after the existing conservative retention window.

Older release history is preserved in `docs/internal/changelog-legacy.md`.
