=== Mobo Core ===
Contributors: pedramdev
Tags: woocommerce, iran, product sync, mobomobo, order automation
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Requires Plugins: woocommerce, persian-woocommerce
WC requires at least: 8.2
WC tested up to: 10.9
Stable tag: 10.31.62
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WooCommerce to MoboCore for product sync, webhook queues, shipping mapping, checkout validation, and optional order automation.

== Description ==

Mobo Core is a WooCommerce integration plugin built for stores operating in Iran and using the specific `mobomobo.ir` workflow as their Mobo/Mobomobo product and order source. This plugin is not presented as the official plugin of mobomobo.ir unless such authorization is explicitly stated by the service owner.

The plugin connects WooCommerce to the MoboCore service for product synchronization, webhook processing, shipping method mapping, checkout validation, automatic order submission, and operational health checks.

Required plugins: WooCommerce and Persian WooCommerce (`persian-woocommerce`). Mobo Core cannot be activated or bootstrapped without both dependencies.

Main features:

* Step-based product, variation, category, price, and image synchronization.
* Queue-based webhook processing to avoid timeout in WordPress requests.
* Shipping method mapping between WooCommerce shipping zones/methods and Mobo shipping methods.
* Separate shipping mapping for Mobo-only orders and mixed Mobo/non-Mobo orders.
* Optional automatic order submission for Mobo-only and mixed WooCommerce orders.
* Address mapping for checkout country, state, and city values used in Iran.
* Image refresh workflow for legacy images after a full Repair run.
* Optional health reporting for cron, queue, memory, disk, and debug status.
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

This communication happens only after the site administrator enters a Token or explicitly uses/enables related features such as synchronization, Repair/sync, webhook processing, checkout validation/order automation, image refresh, or health reporting. Sensitive external workflows such as order submission, health reporting, address mapping, and legacy image refresh are disabled by default on fresh installations.

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

== Changelog ==

= 10.31.62 =
* Prevented mixed WooCommerce orders from being auto-completed after their Mobo line items are submitted successfully.
* Kept mixed orders in processing and added an order note/log explaining that non-Mobo items still require fulfilment.
* Limited the auto-complete option to orders whose line items are all Mobo products.

= 10.31.62 =
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

= 10.31.62 =
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
