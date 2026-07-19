=== Mobo Core ===
Contributors: pedramdev
Tags: woocommerce, iran, product sync, mobomobo, order automation
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Requires Plugins: woocommerce, persian-woocommerce
WC requires at least: 8.2
WC tested up to: 10.9
Stable tag: 10.31.75
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
* Shipping method mapping between WooCommerce shipping zones/methods and Mobo shipping methods.
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

No. WooCommerce shipping methods are still managed in WooCommerce shipping zones. Mobo Core maps the selected WooCommerce shipping method to a Mobo shipping method for automatic order submission.

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

Yes. Mobo Core batches changed Mobo product/variation IDs during the request and performs a targeted purge at shutdown. It clears WooCommerce product transients and WordPress post/object caches, then purges the product URL, current and removed product-category/tag archives, Shop, and Home through supported cache integrations. LiteSpeed Cache and WP Rocket are handled directly. W3 Total Cache and WP Super Cache are handled when their targeted APIs are available. Mobo Core does not call wp_cache_flush(), rocket_clean_domain(), litespeed_purge_all, or another full-site purge.

== Changelog ==

= 10.31.75 =
* Real Cron now drains webhook, image, image-refresh, product-sync, reprice, recategorize, and queued-order work in repeated fair rounds within one safe runtime slice.
* Added configurable round limits and PHP execution safety margin; product-sync step count is now the per-round share rather than the whole-run queue ceiling.
* Replaced fixed runner ownership with a finite atomic token lease renewed by heartbeat before every major stage.
* Concurrent cron invocations exit safely as locked, while crashed/killed workers stop renewing and their leases expire automatically.
* The effective lock TTL automatically expands for the runtime budget and longest blocking network timeout, remains capped, and is never permanent.
* A runner that loses lease ownership stops protected work immediately.
* Queue-stage exceptions are isolated and reported while independent queues continue in the same slice.
* Direct cPanel/REST cron runs now dispatch a non-blocking continuation when progress was made and immediately runnable work remains.
* Added token-free `cronRunner` telemetry to health reports and the local cron status UI, including rounds, stop reason, elapsed time, lock heartbeat/expiry, renewals, failed stages, queue passes, and continuation state.

= 10.31.74 =
* Added structured targeted-cache purge telemetry to the health report under `cachePurge`.
* Reports the last purge status, Mobo Core version, attempt/success times, duration, affected product/object/URL counts, consecutive failures, and bounded last error.
* Reports per-integration detection, tested/current version, version changes since the last test, duration, and `success`, `failed`, `not_detected`, or `not_tested` status.
* Cache integration exceptions remain non-blocking for product synchronization and are also logged with Mobo Core and cache-plugin versions.
* Added the same cache-purge health details to the local Mobo health administration screen and REST health response.

= 10.31.73 =
* Added a central deferred cache purger for Mobo-linked WooCommerce products and variations.
* Deduplicates repeated saves during one sync/worker request and performs one targeted purge at shutdown.
* Clears WooCommerce product transients and targeted WordPress post/object cache entries without flushing the complete persistent object cache.
* Purges the product page, current and removed product category/tag archives, Shop, and Home through LiteSpeed Cache and WP Rocket targeted APIs.
* Added targeted adapters for W3 Total Cache and WP Super Cache when their per-post/per-URL APIs are available.
* Prevented full-site purge calls such as `wp_cache_flush()`, `rocket_clean_domain()`, and `litespeed_purge_all`.
* Added `mobo_core_cache_purge_urls`, `mobo_core_cache_purge_home_enabled`, and `mobo_core_cache_purger_after_flush` extension points for custom listing pages and cache integrations.

= 10.31.72 =
* Corrected Webhook Security Code validation from alphanumeric-only to visible ASCII suitable for the `X-SEC` HTTP header.
* Symbols such as `@`, `#`, `$`, `%`, `&`, `*`, `_`, `[`, `]` and `-` are accepted; whitespace, control characters, Persian text, emoji and other Unicode remain rejected.
* Invalid security codes are blocked before saving and the previous stored value remains unchanged.
* Added browser-side validation, clearer diagnostics, and runtime guards for API pulls, health reports, checkout validation and inbound REST authentication.
* Security codes are stored as opaque secrets without `sanitize_text_field()` mutation, preventing valid percent-like sequences from being altered.

= 10.31.71 =
* Replaced transient runtime locks with atomic database leases that store token and expiry in one non-autoloaded option row.
* Expired or malformed locks are recovered automatically, and the real cron lock is also released through a token-safe shutdown callback.
* Plugin activation and upgrade now remove all legacy Mobo lock transients, including stale value rows that have no timeout row.
* Category sync no longer creates visible WooCommerce categories named `Mobo Category <GUID>` when the remote title is missing.
* Added support for category titles and metadata wrapped inside nested `category` payloads.
* Existing generated placeholder category names are repaired only when a real remote name is available; customer-edited category names remain untouched.

= 10.31.70 =
* Added one authoritative image-refresh command center at the top of the tab instead of relying on scattered status boxes.
* Clearly distinguishes an actively running batch, an enabled workflow waiting for the next runner, a stalled workflow, a paused workflow, approval gates, terminal errors, and a completed cycle.
* Added current-stage progress, estimated whole-cycle progress, a nine-stage timeline, last real worker activity, Cron/Self Runner heartbeat, and the last batch summary.
* Added tick start/finish diagnostics and runtime-lock visibility so timeout or abandoned batch conditions are visible to the administrator.
* Clarified that the live AJAX timestamp is only the time the page display was refreshed, not proof that the background worker ran.

= 10.31.69 =
* Added automatic live status refresh to the image-refresh tab without reloading the WordPress admin page.
* Refreshes automation stage, progress counters, queues, errors, button locks, deletion approvals, and the recommended next step.
* Uses adaptive polling: every four seconds while automation is active and every twelve seconds while idle.
* Pauses polling when the tab is hidden or the administrator has unsaved form changes, preventing lost settings and unnecessary server load.
* Added capability and nonce protected AJAX status rendering with retry backoff and non-blocking Self Runner wake-up.

= 10.31.68 =
* Added safe one-click automation for the complete legacy-image refresh workflow using bounded Cron/Self Runner batches.
* Automated legacy scanning, queue construction, image replacement, WebP subsize audit/repair, and all verification rescans without repeated administrator clicks.
* Kept destructive work behind two explicit one-time approvals: replaced old attachments and orphan raster families.
* Automation now pauses safely on terminal queue failures, missing WebP support, unwritable uploads, incomplete subsize repair, or deletion errors.
* Added start/resume, pause, run-one-batch, current-stage, last-run, and approval controls to the Persian image-refresh dashboard.
* Locked manual workflow, reset, retry, and destructive switches while automation is active, with matching server-side guards.
* Added automation state to operational Health Check reporting for Portal diagnostics.

= 10.31.67 =
* Rebuilt the image-refresh tab around one strict server-side workflow state machine shared by buttons, recommendations, settings, cron processing, and direct-request guards.
* Legacy-image scanning must finish before queue construction; queue construction now shows an estimated remaining run count and must reach 100 percent before processing can start.
* Added scan-cycle identifiers so a completed queue can only be processed when it belongs to the currently completed legacy-image scan.
* Locked every image-maintenance action until its prerequisites are complete and added clearer Persian next-step instructions for stages 1 through 9.
* Corrected retry and reset behavior: retries affect failed rows only, queue reset preserves stage 1, full reset restarts from stage 1, and all destructive switches are disabled after reset or upgrade.
* Invalidated downstream WebP health and deletion audits whenever queue output changes, preventing an old audit from certifying newly processed media.
* Corrected orphan-family deletion so stage 9 remains available until all current candidates are handled, then unlocks the final verification scan.

= 10.31.66 =
* Added a Mobo product marker beside WooCommerce products that contain `product_guid` metadata.
* Added complete Mobo submenu navigation, a WordPress admin-toolbar Mobo menu, and plugin-screen shortcuts for settings and required plugins.
* Added runtime, plugin-header, database, and packaged-file integrity checks with a dashboard warning when the installed files do not match the release manifest.
* Made operational health reporting centrally configured and always active; added protected administrator-only phpinfo, PHP/image capability diagnostics, and bounded log containers.
* Added separate cPanel and DirectAdmin commands for both `mobo-cron.php` and `wp-cron.php`, plus a visible `DISABLE_WP_CRON` configuration check.
* Added automatic JavaScript-assisted matching for similar Mobo and WooCommerce categories without overwriting existing manual mappings.
* Locked the Mobo checkout source URL, improved checkout-validation explanations, and added Webhook Security Code format warnings.
* Added server image-engine readiness checks and clearer estimated progress/completion indicators for all image-maintenance scans.

= 10.31.65 =
* Added a dedicated read-only WebP subsize health scan and a separate controlled repair action with independent bounded cursors.
* Subsize verification now checks attachment metadata, all currently required WordPress sizes, physical files, WebP output format, and GD/Imagick editor capability.
* Regeneration is verified after execution; incomplete replacements are not assigned to products and legacy attachments are not deleted.
* Added cumulative full-cycle scan reports, Persian status/error labels, manager-facing guidance, numbered operation order, conservative deletion defaults, and explicit fallback instructions.
* Added detection and repair of stale metadata entries, missing physical cuts, incomplete metadata, and non-WebP derivative formats.
* Added a separate full-cycle scan and safe deletion path for registered legacy attachments retained during a deletion-disabled dry run.

= 10.31.64 =
* Rebuilt legacy image cleanup around complete image families instead of one row per WordPress crop.
* Registered Media Library originals and derivatives are now skipped before persistence, so normal 150x150, 768x1024, scaled, rotated, and edited files no longer flood the cleanup table.
* Added bounded cursor traversal for legacy-image scans, queue construction, and orphan-family scans so repeated runs eventually cover the full library.
* Added controlled generation/repair of WordPress WebP subsizes and safe cleanup of unregistered legacy derivatives after replacement.
* Added revalidation of attachment, product, content, metadata, taxonomy, option, and physical-file references before destructive cleanup.
* Building the refresh queue no longer starts immediate processing; execution remains explicit or cron-driven.

= 10.31.63 =
* Replaced generated city-asset file operations with the WordPress filesystem abstraction.
* Replaced uninstall directory cleanup with WP_Filesystem methods.
* Removed the obsolete direct database fallback that read Persian WooCommerce city tables; current city assets remain sourced from Mobo data and the bundled legacy code map.
* Resolved the Plugin Check filesystem errors and direct-database warnings reported against 10.31.62.

= 10.31.62 =
* Prevented mixed WooCommerce orders from being auto-completed after their Mobo line items are submitted successfully.
* Kept mixed orders in processing and added an order note/log explaining that non-Mobo items still require fulfilment.
* Limited the auto-complete option to orders whose line items are all Mobo products.

= 10.31.61 =
* Removed “جابجایی فیلد استان و شهر” from the Persian WooCommerce requirements notice because it is not mandatory.
* Kept only “فعالسازی شهرهای ایران” as the required Persian WooCommerce setting for automatic order submission.

= 10.31.60 =
* Replaced the technical Mobo cart HTTP 400 message with a customer-facing unavailable-product message.
* Reworded the Persian WooCommerce city requirements as one concise user-facing sentence.

= 10.31.59 =
* Replaced the technical option-enforcement notice with a user-facing reminder for “فعالسازی شهرهای ایران”.
* Removed all automatic reads, writes, save interception, restoration, checkout blocking, order-submission blocking, admin enforcement notices, and cron verification for these Persian WooCommerce settings.
* Kept Persian WooCommerce as a required plugin and retained the generated Mobo city-script integration.

= 10.31.58 =
* Locked the complete Queue and Processing settings tab while a manual product Sync or Repair run is active or waiting for MoboCore.
* Prevented server-side saves even when the form was opened before the run started or a stale browser tab submits changes.
* Protected direct `update_option()` writes for pagination, cursor, image, webhook retry, and missing-variant behavior settings during active runs.
* Added a clear Persian warning explaining that changing page size or cursor strategy can move counters/indexes and cause skipped or duplicate processing.

= 10.31.56 =
* Moved generated checkout city JavaScript from the private `wp-content/uploads/mobo-core/` tree to the public sibling path `wp-content/uploads/mobo-core-public/assets/`.
* Kept `wp-content/uploads/mobo-core/` and all webhook fallback JSON files protected by the existing deny-all rule.
* Added a dedicated public-assets `.htaccess` that disables directory listing and executable script extensions without blocking JavaScript delivery.
* Increased the generated city asset schema to version 3 so existing installations automatically regenerate the files at the new public URL.
* Removed stale `iran_cities.js` and `iran_cities.min.js` files from the old private path during migration.

= 10.31.54 =
* Removed manual city-to-city mapping from the automatic-order workflow.
* Added generation of `iran_cities.js` and `iran_cities.min.js` from the authoritative Mobo country/state/city cache.
* Replaced Persian WooCommerce's `pw-iran-cities` asset on checkout and Edit Address pages when the generated Mobo asset is valid.
* Stored the real Mobo `city_id` as the WooCommerce city field value and validated that the selected city belongs to the resolved Mobo state.
* Retained manual country/state mapping and added automatic province-name matching plus old/new province aliases.
* Added a safe fallback to the original Persian WooCommerce city script when generated files are unavailable, while blocking automatic submission with a precise error.
* Added legacy resolution for old Persian WooCommerce numeric city codes and fixed plural lookup of the `cities` mapping bucket.

= 10.31.53 =
* Bundled an independent Iranian city dataset generated from `iran_cities.js` with 31 provinces and more than 2,700 city records.
* Removed the runtime dependency on Persian WooCommerce city tables, options, globals, and frontend JavaScript for address mapping.
* Added old/new Persian WooCommerce province-code alias resolution such as `TE` to `THR`.
* Changed the city-mapping UI to load and save one province at a time, preventing `max_input_vars` truncation.
* Preserved mappings for all other provinces when saving the currently selected province.
* Kept Persian WooCommerce city providers as compatibility fallbacks and retained the public city-candidate filter.

= 10.31.52 =
* Read Persian WooCommerce city candidates from its actual city provider and `Woo_Iran_Cities_By_HANNANStd` table.
* Populated the manual city-mapping table when Persian WooCommerce city dropdowns are active.
* Added canonical fallback matching for existing city mappings saved with province labels or legacy state keys.
* Avoided loading the complete city list during normal order resolution when WooCommerce already stores the visible city name.

= 10.31.51 =
* Fixed simple-product synchronization by resolving the single purchasable Mobo Variant and storing portal_variant_id on WC_Product_Simple.
* Preserved simple product type for one no-attribute UpdateVariant payload instead of converting the product to variable.
* Marked simple products unavailable and sync-incomplete when their Mobo Variant is missing or ambiguous.
* Made authenticated Mobo cart addability validation mandatory before checkout whenever automatic order submission is enabled.
* Validated POST /cart response semantics, refreshed the authoritative cart with update=true, and enforced remote min/max quantities.

= 10.31.50 =
* Added hard plugin dependencies for WooCommerce and Persian WooCommerce through the WordPress `Requires Plugins` header.
* Added an activation guard for WordPress versions that do not enforce plugin dependencies.
* Added a persistent administrator error when a required dependency is removed or inactive.
* Prevented Mobo Core bootstrap and automatic order workflows while Persian WooCommerce is unavailable.

= 10.31.49 =
* Fixed automatic Mobo order submission when WooCommerce had only partial shipping fields and a complete billing address.
* Added Checkout Block / Store API address-mapping persistence hooks.
* Added address preflight validation before login, remote cart clearing, and cart item insertion.
* Improved country/state/city alias resolution, including numeric local city values from Persian WooCommerce city sources.
* Added precise missing-address diagnostics and required country/state/city mapping checks before enabling automatic submission.

= 10.31.47 =
* Removed the final dynamic placeholder patterns reported by Plugin Check.
* Replaced dynamic-column batch deletion with allowlisted WordPress database deletion calls.
* Sanitized the selected variation price input before use.

= 10.31.46 =
* Hardened SQL identifier handling and documented intentional direct access to internal queue/map tables.
* Added explicit nonce verification for variation saves and documented verified admin/checkout request boundaries.
* Replaced direct file deletion and rename calls with WordPress filesystem APIs.
* Reworked the local PHP cron runner with token authentication, scoped execution, JSON-only output, and direct-access protection.
* Replaced direct PHP error logging with structured WooCommerce logging.
* Removed hidden development files and non-distribution notes from the release package.
* Updated WordPress compatibility metadata and plugin documentation.

= 10.31.45 =
* Added sales and technical contact information to the purchase/activation screen and documentation.
* Kept GitHub links aligned with https://github.com/PedramDev/mobo-core.
* Changed the default MoboCore API URL to HTTPS.
* Enabled SSL verification by default for outbound HTTP requests.
* Disabled sensitive external workflows by default on fresh installs: automatic order submission, health reporting, address mapping, and legacy image refresh.
* Added a developer-only opt-in filter for unsafe local/private image downloads used in local test environments.
* Clarified that this is an integration for a specific mobomobo.ir workflow and not presented as an official mobomobo.ir plugin unless separately authorized.

= 10.31.43 =
* Added ready-to-publish Terms and Privacy pages for mobo.codeya.ir.
* Clarified that the plugin is intended for Iranian stores and the specific mobomobo.ir source.
* Updated external service disclosure with mobo.codeya.ir and mobomobo.ir.
* Updated purchase/activation UI text for the Iran-only and mobomobo.ir workflow.

= 10.31.42 =
* Added purchase and activation screen linked to mobo.codeya.ir.
* Added WordPress.org-ready readme.txt and external service disclosure.
* Updated plugin metadata, license headers, and GitHub URL.

== Upgrade Notice ==

= 10.31.75 =
Queue-heavy sites now keep draining work in fair rounds during each safe cron slice and chain a local continuation when useful work remains. The runner uses a finite renewable lease: concurrent invocations exit safely, and a crashed process cannot leave a permanent lock because the lease expires automatically.


= 10.31.74 =
* Added structured targeted-cache purge telemetry to the health report under `cachePurge`.
* Reports the last purge status, Mobo Core version, attempt/success times, duration, affected product/object/URL counts, consecutive failures, and bounded last error.
* Reports per-integration detection, tested/current version, version changes since the last test, duration, and `success`, `failed`, `not_detected`, or `not_tested` status.
* Cache integration exceptions remain non-blocking for product synchronization and are also logged with Mobo Core and cache-plugin versions.
* Added the same cache-purge health details to the local Mobo health administration screen and REST health response.

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
