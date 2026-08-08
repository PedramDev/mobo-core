# Legacy Mobo Core Changelog

= 10.32.10 =
* Fixed detection of existing WooCommerce store shipping methods in the shipping wizard.
* Enabled non-Mobo Flat Rate instances created by an earlier Mobo repair are now valid current store methods.
* Choosing current store methods no longer disables a detected managed Flat Rate and breaks the store package.
* The wizard now lists the detected non-Mobo methods with their Zone and instance ID.

= 10.32.9 =
* The shipping wizard now validates ordinary store-product shipping before enabling mixed-cart or store-only checkout flows.
* Stores can use their existing WooCommerce methods or create an idempotent managed Flat Rate fallback with a manager-defined title and cost.
* Repair now recreates or updates the saved store fallback without duplicates and refuses to report success when non-Mobo packages would have no rate.
* Mixed split carts keep Mobo rates on the Mobo package and ordinary WooCommerce rates on the store package.

= 10.32.8 =
* Repair now writes an explicit destination scope on every managed Mobo shipping instance.
* All courier (پیک) methods are enforced as Tehran-only, including free/direct courier variants.
* Mobo drop-shipping postal method 148395514 is enforced as nationwide Iran and remains available for Tehran and all provinces.
* Re-running Repair updates existing instances without duplicates and reports Tehran-only versus nationwide method counts.

= 10.32.7 =
* Replaced blind one-click shipping setup with a three-step fulfillment wizard for mixed Mobo/store carts.
* Added three safe profiles: Tehran store consolidation with internal Mobo pickup, two independent customer shipments, and mixed-cart blocking.
* Split mode creates separate Mobo and store shipping packages and restricts each package to the correct rate providers.
* Consolidated Tehran mode keeps one customer shipment, hides Mobo rates from the customer, and resolves the Mobo order with the wizard-selected internal pickup shipping ID.
* Mixed-order shipping resolution now prefers the explicit Mobo shipping line in multi-package orders.
* Added classic checkout and Checkout Block validation, operational order notes, idempotent reconfiguration, and a final review step before WooCommerce changes are applied.

= 10.32.6 =
* Added a one-click "build and repair Mobo shipping" action that synchronizes the latest Portal contract and creates or repairs the required WooCommerce shipping class, Iran zones, managed shipping instances, and Mobo shipping ID mappings.
* Added a native Mobo WooCommerce shipping method; no separate table-rate extension is required for Mobo `free`, `static`, and `rules` methods.
* Rules are calculated from Mobo-only `mobo_api_price × quantity`, with weight and destination constraints, without changing product, cart, checkout, payment, or order item prices.
* Re-running the installer is idempotent: existing Mobo-managed instances are updated, stale managed instances are disabled, and unrelated WooCommerce methods are preserved.
* Sensitive operational choices such as previous-invoice merging, warehouse holding, and in-person pickup are created disabled for store-manager review.
* Added source-Toman to store-Rial conversion, fixed method-instance order mapping, Checkout Blocks-safe fixed rate IDs, and COD restriction merging without enabling COD automatically.

= 10.32.5 =
* Portal shipping synchronization now consumes the management shipping list and each shipping detail endpoint, preserving status, position, weight/subtotal/cost bounds, geographic scope, rules, and creation metadata.
* Suspended methods remain stored in Portal history but are excluded from active customer API and webhook snapshots.
* WordPress now stores the complete active shipping contract and displays rule/location details with a store-manager setup guide.
* Added an optional shipping-only WooCommerce package context: Mobo products can use a selected virtual shipping class and `mobo_api_price` for shipping calculations without changing storefront, cart, checkout, or order prices.

= 10.32.4 =
* Repair now performs a final image-only recovery pass for existing local Mobo products that have no usable featured image, even when the remote product is excluded from the normal product list by the OnlyInStock setting.
* Image Refresh now discovers local Mobo products without a valid featured image and sends only their remote image payload to the safe Image Queue.
* Missing-image recovery uses the existing product GUID endpoint and never updates product fields, stock, price, status, categories, attributes, or variants.
* The recovery path runs only when automatic image updates are enabled and remains resumable through the existing image queue and cron retry behavior.

= 10.32.3 =
* Shared Media now rewrites all responsive `srcset` candidates to `MOBO_CORE_SHARED_MEDIA_BASE_URL` instead of the site uploads URL.
* Only attachments marked as Shared Media and validated `objects/...` files are rewritten; normal WordPress attachments are unchanged.
* Unexpected URL parsing or filesystem validation failures fall back to the original `srcset` without causing a fatal error.

= 10.32.2 =
* Image Refresh now removes local JPG/JPEG files whose base filename matches the successfully validated replacement WebP, including same-base derivative files such as `name-300x300.jpg` and `name-scaled.jpeg`.
* Cleanup checks both the new WebP directory and the previous attachment directory inside WordPress uploads. PNG, WebP, and different base filenames are not removed.
* Filesystem deletion errors are isolated and reported in the refresh result; they do not cause a fatal error or roll back the completed WebP replacement.

= 10.32.1 =
* Wrapped account-quota lookup, uploads write probing, server filesystem checks, and final storage report composition in independent Throwable boundaries. Unexpected hosting integrations now return an explicit unavailable state instead of breaking the Site Health endpoint.
* Storage fallback responses use stable generic diagnostics and never expose exception details, cPanel tokens, or internal connection values.
* Removed Portal force-refresh query parameters from address-mapping and Mobo shipping-method API requests. WordPress can refresh its local snapshot, but rebuilding Portal reference data is now restricted to the Portal administrator UI.

= 10.32.0 =
* Site Health no longer presents PHP `disk_free_space()` as the hosting account quota. Server-filesystem capacity is reported separately and is not used for account-quota scoring.
* Added a cached real-write probe in `wp-content/uploads` so exhausted byte quotas, inode quotas, and write failures are detected even when `is_writable()` and server disk capacity look healthy.
* Added optional exact cPanel account byte/inode quota reporting through cPanel UAPI constants or the `mobo_core_hosting_quota_stats` filter; credentials are never included in health output.
* Legacy Portal consumers receive null account disk values when only server filesystem capacity is known, preventing false healthy readings such as hundreds of gigabytes free on a full shared-hosting account.

= 10.31.99 =
* Image download/source-readiness failures no longer become terminal after the short retry limit. They continue with a bounded long-term retry interval controlled by the site administrator.
* Added an `attaching` queue state so an imported attachment is not marked done until WooCommerce featured/gallery linkage finishes; interrupted requests resume without downloading the image again.
* Existing recoverable `failed` image rows are reopened automatically in bounded batches after upgrade. Permanent structural failures remain terminal and are clearly prefixed in diagnostics.
* Maintenance now requeues completed rows whose attachment disappeared, schedules repair for lost featured-image linkage, and deletes only old permanent image failures.
* Health output now includes attachments waiting for linkage and the nearest scheduled image retry time.

= 10.31.98 =
* Added an administrator-controlled option for purging Shop, product-category, product-tag, and Home cache entries after Mobo product updates; it is disabled by default on new installations.
* Product transients, WordPress object/post caches, and the exact product URL continue to be invalidated even when archive purge is disabled.
* Fresh installations no longer enable automatic reconciliation by default, and legacy migration flags can no longer auto-start a full desired-state Repair; Sync and Repair require an explicit administrator or Portal action.
* Existing installations keep their saved automatic-reconciliation preference, and already-running operations are not cancelled during upgrade.

= 10.31.97 =
* Shared-media attachments now map registered WordPress and WooCommerce image sizes to the worker-generated cuts even when a size uses an unconstrained dimension or a bounding box.
* `medium_large` and `large` can resolve to the generated 768x1024 cut, while `woocommerce_single` can resolve to the generated 600x800 cut for 960x1280 source images.
* Existing shared-media attachments receive the missing aliases at runtime after the plugin update; no database migration, per-site image generation, or local uploads copy is required.
* Existing exact mappings such as thumbnail, medium, WooCommerce thumbnail, and gallery thumbnail remain unchanged.

= 10.31.96 =
* Added an opt-in private shared-media adapter configured only by server constants or environment variables; no public setting or database secret was added.
* Private sites can create virtual WordPress attachments backed by a centrally generated read-only WebP repository instead of downloading duplicate files into each uploads directory.
* Added safe conversion/replacement of legacy local attachments, shared-manifest validation, and retry behavior that waits for the single media writer without permanently failing the image queue.
* Public/shared-hosting installations keep the existing per-site image download behavior unchanged.

= 10.31.94 =

* Portal now pulls health directly from the plugin; automatic cron-based health report pushes are disabled.
* The legacy report-now route now returns a local snapshot and never performs an outbound request.
* The Health admin tab now documents the Portal pull model and the active endpoint.
* Health responses continue to expose current plugin, cron, queue, sync, WebP, settings, and credential telemetry.

= 10.31.93 =

* Standardized Portal license authentication: every Portal API consumed by the plugin now sends the Token header; only get-products-free is anonymous.
* Health reports and remote deployment package downloads now send both the license Token and their endpoint-specific security header.
* Added clear local errors for missing or malformed license GUIDs and preserved Portal 401/403 status details for diagnostics.
* Updated the developer contract to match Portal FillTokenDto and the expanded Swagger documentation.

= 10.31.92 =

* Added an X-SEC-protected Portal webhook credential test endpoint.
* Portal heartbeat requests now record whether the configured Webhook Security Code is valid, missing, mismatched, or malformed.
* Added clear webhook credential status to the plugin dashboard, connection page, and central Site Health screen.
* HTTP 401/403 credential failures are reported separately from WordPress downtime.

= 10.31.91 =

* Added X-SEC-protected Portal APIs for reading all non-secret plugin settings.
* Added Portal-triggered Sync and full Repair with idempotent request IDs.
* Added live operation status, progress, cancellation, and Health report telemetry.
* License Token, Webhook Security Code, passwords, cookies, Cron Token, and similar credentials are never returned in the settings snapshot.

= 10.31.84 =

* Added a global upgrade barrier that blocks new Sync, Repair, reconciliation, webhook, image, reprice, recategorize, maintenance and self-runner work during remote plugin replacement.
* Added graceful draining of active runtime and product-write locks without force-releasing live workers.
* Existing Sync/Repair state, product/variation cursors and queued jobs are preserved and resume after the upgrade barrier is released.
* Remote upgrades return `blocked-site-busy` with retry metadata when a site cannot reach an idle boundary; Portal can safely retry later.
* Long-running queues now stop between items when the upgrade barrier becomes active.

= 10.31.82 =

* Added secure Portal-driven remote plugin deployment endpoints.
* Verifies a short-lived Portal HMAC request, expected source version, package SHA-256 and plugin header version before install.
* Creates a local plugin backup and restores it automatically when installation validation fails.
* Exposes upgrade status for Portal verification after a deployment.

= 10.31.81 =
* Added a secure, POST-only `/wp-json/mobo-core/v1/heartbeat` endpoint that cannot be satisfied by normal page cache and records each Portal heartbeat.
* Portal heartbeat runs a bounded slice of the existing shared cron/reconciliation engine; webhook, automatic recovery, cPanel cron, and manual repair continue to use one engine.
* Added heartbeat runtime limits, continuation support, local heartbeat telemetry, and health-report visibility without creating a second sync path.
* Central Portal can now wake dormant WordPress sites and recover missed product/variation/delete changes after downtime.

= 10.31.80 =
* Added Adaptive Reconciliation so webhook delivery, automatic recovery, and manual health actions converge on the existing desired-state product/variation sync engine.
* Added per-product sync health with Portal revision/hash, last successful sync time, synced/behind/repairing/failed status, and bounded error details.
* Added configurable fast checks (15 minutes through daily), product batches, variation budgets, and daily/weekly deep integrity checks.
* Added revision endpoint support with a bounded rolling-catalog fallback for Portal versions that do not expose `/sync/changes`.
* Added a Sync Health administration tab with health counters, last/next check, Run Repair, and Run Deep Integrity Check actions.
* Deep integrity now removes missing products, extra variations through authoritative snapshots, invalid attribute structures through full rebuild, stale product/variation mappings, and orphan health rows.
* Added reconciliation telemetry to local and remote health reports.

= 10.31.77 =
* Variable products now use authoritative desired-state synchronization. Missing variations and stale mappings are permanently removed.
* Attribute structure changes trigger a full variation/attribute rebuild before the current Portal snapshot is recreated.
* Upgrade automatically enqueues one bounded, resumable Repair for existing Mobo installations; active Sync/Repair state is not overwritten.

= 10.31.76 =
* Fixed an immediate false `lock-lost` result when lock acquisition and the first lease renewal occurred within the same second.
* A byte-identical renewal now verifies that the owned database value still exists instead of treating MySQL zero affected rows as lost ownership.
* Preserved compare-and-swap ownership checks for replaced, expired, deleted, or foreign-token locks.

= 10.31.75 =
Improves heavy-queue Cron draining with fair rounds, renewable finite leases, safe concurrent-run rejection, and automatic continuation when runnable work remains.

= 10.31.74 =
Adds bounded targeted-cache purge telemetry, per-integration status/version reporting, and non-blocking cache error diagnostics in local and REST health reports.

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
