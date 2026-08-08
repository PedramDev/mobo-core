=== Mobo Core ===
Contributors: pedramdev
Tags: woocommerce, iran, product sync, mobomobo, order automation
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Requires Plugins: woocommerce, persian-woocommerce
WC requires at least: 8.2
WC tested up to: 10.9
Stable tag: 10.33.7
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

Mobo Core always clears WooCommerce product transients, WordPress post/object caches, and the changed product URL. The site administrator can configure deferred archive cache invalidation for product-category/tag archives, Shop, and Home from the Product settings tab. New installations default to a 15-minute batching window; archive invalidation can still be disabled explicitly. LiteSpeed Cache, WP Rocket, W3 Total Cache, and WP Super Cache are handled through their targeted APIs when available. Mobo Core does not call wp_cache_flush(), rocket_clean_domain(), litespeed_purge_all, or another full-site purge.

== Changelog ==

= 10.33.7 =
* Reworked the final reconciliation legacy Portal-ID fallback so it no longer relies on WP_Query meta_key/meta_value arguments flagged as slow by Plugin Check.
* Legacy product metadata remains supported through bounded prepared lookups, and a successful legacy hit now repairs the GUID product-map row for fast future reconciliation.
* Preserved the 15-minute deferred archive purge queue and all cache-mutation protections from 10.33.6.

= 10.33.6 =
* Removed the remaining direct product_cat superglobal read from the WP Rocket compatibility filter by using WordPress query vars.
* Reworked the reconciliation legacy product-ID fallback into three bounded single-key lookups, eliminating the OR meta_query slow-query warning.
* Replaced dynamic termmeta/usermeta table iteration in image-reference detection with explicit prepared queries for both core meta tables.
* Preserved the 15-minute deferred archive purge queue, cache mutation guard behavior, and all 10.33.5 hardening changes.

= 10.33.5 =
* Replaced the standalone phpinfo file with an authenticated admin-post PHP diagnostics endpoint that exposes only bounded support data.
* Sanitized WP Rocket product-category request detection without changing the archive cache purge policy.
* Hardened Plugin Check compliance for image queue SQL patterns, image-reference scans, orphan cleanup, storage write probes, and remote-upgrade cleanup.
* Moved internal release/development Markdown out of the plugin root and normalized documentation filenames for distribution compatibility.
* Preserved the 15-minute default deferred archive purge queue and all targeted cache integrations from 10.33.4.

= 10.33.4 =
* Changed the default deferred archive cache purge interval from disabled to 15 minutes for new installations and missing-option fallback.
* Existing explicit archive purge selections are preserved during upgrade.
* Legacy immediate archive-purge ON migrates to a 15-minute deferred window; legacy OFF remains disabled.
* All selectable intervals remain available: disabled, 5/10/15/20/25/30/45/60 minutes.

= 10.33.3 =
* Integrated the WP Rocket product-category URL-validation compatibility fix into Mobo Core, limited to WooCommerce product category requests.
* Replaced immediate archive-page purging with a persistent deferred queue and selectable 5/10/15/20/25/30/45/60-minute intervals.
* Product-page/object-cache invalidation remains immediate; category/tag/shop/home page-cache invalidation is batched and processed by the real Mobo cron runner.
* Added exact hierarchical product-category purge targets so nested category cache paths are invalidated alongside canonical term URLs.

= 10.33.2 =
* Replaced the WP Rocket-only mutation guard with cache-agnostic `Mobo_Core_Cache_Mutation_Guard` across Mobo-owned Sync, Repair, webhook, pricing, category, and image mutations.
* WP Rocket native per-save invalidation is deferred with `rocket_is_importing`; Mobo Core's targeted shutdown purger remains authoritative.
* LiteSpeed Cache broad/related purge tags are stripped during Mobo mutations while direct post/URL and unknown custom tags remain intact; final archive purging is performed once when enabled.
* W3 Total Cache native post/posts/all flushes are vetoed through its preflush filters during Mobo mutations; final URL/post invalidation is performed once by Mobo.
* WP Super Cache native post-cache clearing is vetoed during Mobo mutations, with the related-page filter retained as a compatibility fallback; final invalidation follows the archive-purge setting.
* Native suppression is active regardless of whether archive purge is OFF or ON, preventing per-save purge storms; the setting controls only the final Mobo purge policy.
* WordPress/WooCommerce object and transient invalidation remains active for data consistency; Redis/object cache is intentionally not treated as a full-page archive cache.
* Added LiteSpeed tag-value fallbacks, backward-compatible `Mobo_Core_WP_Rocket_Import_Guard` facade, and begin/end extension hooks for custom cache integrations.

= 10.33.1 =
* Sync, Repair, adaptive reconciliation, ProductUpdated/UpdateVariant webhooks, Reprice, Recategorize, Image Queue, and Image Refresh now run product mutations inside a scoped WP Rocket import guard.
* WP Rocket native per-save cache clearing is suppressed only during Mobo-owned mutations; normal wp-admin/WooCommerce edits are unchanged.
* Mobo Core's existing targeted shutdown purger remains authoritative, so the "archive purge on product update" setting now controls archive invalidation without being bypassed by WP Rocket CRUD hooks.
* The guard is request-local, reference-counted, exception-safe, and supports nested Sync/Repair/Webhook/image operations.

= 10.33.0 =
* Choosing current store methods no longer keeps the Mobo-created fallback visible.
* Real WooCommerce store methods from a broader or fallback Zone are connected into the Mobo Iran Zone when WooCommerce Zone precedence would otherwise hide them.
* The old managed fallback is disabled only after a real store method is available, preventing checkout gaps.
* Mirrored existing methods preserve their title, cost, and settings, synchronize on Repair, and remain idempotent.

Older detailed changelog entries are preserved in `docs/internal/changelog-legacy.md`.

== Upgrade Notice ==

= 10.31.82 =
Adds Portal-controlled, version-locked remote deployment with HMAC authentication, complete-package and per-file SHA-256 verification, local backup, rollback attempt, and post-install version confirmation. This version must be installed once before future Portal deployments can operate.

= 10.31.81 =
Adds central five-minute WordPress keep-alive, bounded shared-engine recovery, and pull-based health reporting through the authenticated heartbeat endpoint.

= 10.31.80 =
Adds bounded automatic recovery after WordPress downtime, a Sync Health dashboard, revision-aware change detection with rolling fallback, and scheduled deep integrity checks while preserving authoritative desired-state variation cleanup.

= 10.31.77 =
Adds authoritative desired-state variation synchronization, permanent stale-variation cleanup, full attribute-structure rebuilds, and an automatic bounded Repair queue for existing stores.

= 10.31.76 =
Fixes manual and server Cron runs that could stop immediately with `lock-lost`, zero rounds, and zero lease renewals when the first renewal happened in the same second as acquisition.

= 10.31.75 =
Improves heavy-queue Cron draining with fair rounds, renewable finite leases, safe concurrent-run rejection, and automatic continuation when runnable work remains.

= 10.31.74 =
Adds bounded targeted-cache purge telemetry, per-integration status/version reporting, and non-blocking cache error diagnostics in local and REST health reports.

= 10.31.73 =
Product and variation updates now perform a deferred targeted cache purge for WooCommerce/WordPress object caches and supported page-cache plugins. No full-site cache purge is performed. Add custom Elementor or block-listing URLs with the `mobo_core_cache_purge_urls` filter when needed.

= 10.31.72 =
Webhook security codes may contain printable ASCII symbols but must not contain spaces, Persian characters, emoji or other Unicode. Invalid existing values are reported and must be replaced with the exact ASCII value configured in Portal.

= 10.31.71 =
The upgrade automatically clears legacy Mobo runtime locks. Category placeholders are no longer created, and old generated placeholders are repaired when their real remote names are available.

= 10.31.70 =
The image-refresh tab now starts with a single command center that states whether work is actually running, merely enabled, stalled, paused, waiting for approval, failed, or completed. Review this panel first after upgrade.

= 10.31.69 =
The image-refresh dashboard now updates itself while it remains open. Unsaved settings are protected: automatic refresh pauses as soon as a field is edited and resumes after the normal page reload following save.

= 10.31.68 =
Image refresh can now run automatically in bounded batches. Start it once, keep real Cron or Self Runner healthy, and intervene only for errors or the two explicit deletion approvals. All destructive approvals remain off after upgrade.

= 10.31.67 =
Image refresh is now a strict ordered workflow. Complete each scan until 100 percent, repeat queue construction until its cycle is complete, and use the enabled next-step button only. Refresh and destructive cleanup switches are disabled until their required audit stage is complete.

= 10.31.66 =
The dashboard now verifies the running version and packaged file hashes. Configure both recommended server cron jobs, verify that `DISABLE_WP_CRON` is true, and review the image-engine status before running image maintenance.

= 10.31.65 =
The image refresh screen now includes a numbered safe workflow plus dedicated WebP cut health scanning and verified repair. Old-attachment and orphan deletion are switched off on upgrade. Complete a full scan cycle before enabling them again.

= 10.31.64 =
Legacy image cleanup now treats the original and all WordPress derivatives as one family. Existing per-file cleanup rows are removed automatically and the new family list is rebuilt from a bounded scan.

= 10.31.63 =
Plugin Check filesystem findings were resolved with WP_Filesystem, and the obsolete Persian WooCommerce city-table database fallback was removed. No Sync or Repair is required.

= 10.31.61 =
Only “فعالسازی شهرهای ایران” needs to be enabled in Persian WooCommerce for automatic Mobo order submission; “جابجایی فیلد استان و شهر” is no longer listed as mandatory.

= 10.31.60 =
HTTP 400 returned while adding a product to the Mobo cart is now shown to the customer as an unavailable-product message.

= 10.31.59 =
Mobo Core no longer changes or checks these Persian WooCommerce settings. Enable “فعالسازی شهرهای ایران” manually in Persian WooCommerce when automatic Mobo order submission is used.

= 10.31.58 =
Queue and processing settings cannot be changed while Sync or Repair is active. Finish or cancel the run, reload the Queue tab, and then save new values.

= 10.31.56 =
Generated city assets now use `wp-content/uploads/mobo-core-public/assets/`. Clear page, CDN, and optimization caches once if rendered checkout HTML still references the old denied `wp-content/uploads/mobo-core/assets/` URL.

= 10.31.54 =
Open Purchase Validation, refresh Mobo address data, verify country/state mapping, and save once. Mobo Core will generate both city scripts under `wp-content/uploads/mobo-core-public/assets/`; manual city mapping is no longer required.

= 10.31.53 =
Reload the purchase-validation settings page, select the required province, and save its city mappings. The bundled city source does not require Persian WooCommerce city Repair, and mappings for other provinces are preserved.

= 10.31.51 =
Run a product synchronization after upgrading so existing simple products receive their Mobo portal_variant_id. Automatic order submission now forces a real Mobo cart preflight during checkout.

= 10.31.50 =
Requires WooCommerce and Persian WooCommerce to be installed and active before Mobo Core can run.

= 10.31.49 =
Fixes checkout address mapping for classic and block checkout and prevents remote cart side effects when local address configuration is incomplete.

= 10.31.47 =
Final Plugin Check cleanup for queue counters, maintenance deletion, and variation input sanitization.

= 10.31.46 =
Security and distribution hardening for SQL, nonce validation, cron execution, filesystem operations, logging, and WordPress.org packaging. Existing synchronization data is preserved.
