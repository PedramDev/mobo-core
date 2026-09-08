=== Mobo Core ===
Contributors: pedramdev
Tags: woocommerce, iran, product sync, mobomobo, order automation
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Requires Plugins: woocommerce, persian-woocommerce
WC requires at least: 8.2
WC tested up to: 10.9
Stable tag: 10.33.54
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

After the site administrator enters a Token, Mobo Core can communicate with the central service for licensing, synchronization, webhook processing, and always-on operational health reporting. Optional customer-facing workflows such as checkout validation, order submission, and address mapping remain controlled separately. Legacy image refresh no longer requires a redundant enable switch: the safe workflow becomes active when its automatic cycle is started, while destructive old-attachment deletion remains separately guarded and opt-in.

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

= 10.33.54 =
* Fix: remove retired Health and Sync Health WP-Cron events even when legacy schedules carry non-empty arguments.
* Safety: cleanup is restricted to the four retired Mobo Health hook names and preserves unrelated cron events.
* Keeps the 10.33.53 real-cron snapshot ownership and deferred convergence self-heal behavior unchanged.

= 10.33.53 =
* Moves convergence-residue self-healing out of plugins_loaded and runs it only after WooCommerce initialization.
* Re-applies Health/Sync Health real-cron ownership safely for sites partially upgraded through the 10.33.52 candidate.
* Rebuilds package integrity metadata for the corrected candidate.

= 10.33.52 =
* Moves Health and Sync Health snapshot refresh ownership to the deterministic Mobo real-cron runner; Mobo no longer schedules these caches through WP-Cron.
* Prevents cached/stale Health responses from being captured again as fresh snapshots.
* Removes legacy v1/v2 Health WP-Cron rows during migration and periodic maintenance.
* Safely self-heals only the exact pre-10.33.51 out-of-order ProductUpdated/UpdateVariant completion residue when authoritative ordering, hash, queue ownership, topology and variation-count proofs all agree.
* Rebuilds the package integrity manifest for the actual 10.33.52 files.


= 10.33.51 =
* Fixes out-of-order desired-state convergence when terminal UpdateVariant for revision R completes before ProductUpdated for the same/newer-covered boundary. A delayed ProductUpdated now preserves the already-converged variable-product completion marker only when the applied Variant boundary covers it and the local variation structure remains sane.
* Keeps failed/incomplete Sync Health escape-hatch behavior unchanged; upstream-invalid Variant payloads still remain failed/incomplete.


= 10.33.45 =
* Consolidates the deep-audit durability, ownership, concurrency, migration and retry-safety fixes validated through MOBO-4426 to MOBO-4451.
* Hardens Product/Variation Map canonical ownership, stale cleanup, cross-parent remaps, rollback restoration and reverse-map cleanup against TOCTOU races.
* Makes legacy Product/Variation/Category seed completion fail closed on database read errors and preserves retry cursors/completion semantics.
* Hardens Category Map partial metadata upserts so empty fields preserve the current durable value atomically instead of replaying stale snapshots.
* Makes database index replacement atomic so a failed schema repair does not drop an existing protective index before the replacement is ready.
* Preserves normal stale cleanup, migration, rollback and retry behavior while preventing concurrent fresh durability evidence from being overwritten or deleted.

= 10.33.44.22 =
* Adds centralized stale runtime-lock recovery with compare-and-delete safety; healthy live leases are never force-unlocked.
* Expired/malformed locks are automatically reclaimed and corrupted far-future leases require a lock-specific heartbeat safety ceiling before recovery.
* Adds bounded non-secret recovery diagnostics with lock name, reason and timestamps only.
* A running Repair/Sync with no active worker and no checkpoint progress can be safely re-awakened from the same durable syncId/cursors instead of being rejected as permanently busy.
* Stale checkpoint recovery preserves mode/generation boundaries, refuses live worker takeover and never resets or deletes durable Repair state.
* Deep Test Suite target: 10.33.44.22-r7.11.

= 10.33.44.21 =
* Speeds up Repair on sites where Self Runner/loopback cannot advance the worker reliably.
* Admin polling now performs a small time-bounded multi-step catch-up burst after one missed Repair poll instead of one step every eight seconds.
* Every catch-up iteration still uses the canonical manual_sync lease, product locks, ordering fences and durable checkpoints.
* Catch-up yields immediately to foreground Webhook pressure and stops on worker lock, error, no-progress, completion or time budget.
* Image queue I/O is deferred on the same admin request that rescues stalled Repair so product convergence gets the request budget.
* Deep Test Suite target: 10.33.44.21-r7.10.

= 10.33.44.20 =
* Adds persistent per-product Mobo pricing override from the WooCommerce Products list.
* Custom percentage is stored on the parent and applies to Simple products or every live Mobo-owned Variation.
* Product override takes precedence over legacy per-Variation fixed add-on while active; resetting to Global restores existing pricing behavior.
* Uses the shared product lock, durable generation-token retry, full-family preflight, and rollback snapshots to avoid partial price convergence.
* Webhook/Product Sync/Reprice all resolve the same centralized pricing policy; manual/non-Mobo Variations are preserved.
* Adds visible custom/pending pricing badges and nonce/capability protected Save & Reprice admin flow.
* Deep Test Suite target: 10.33.44.20-r7.9.

= 10.33.44.19 =
* Makes authoritative Variable-to-Simple topology transitions retire historical Mobo variations through Trash/quarantine instead of hard deletion, including already-Simple parents left with stale live children by older builds.
* Requires explicit valid Variant and Attribute collection presence before destructive topology changes; absent attributes preserve topology and malformed collections fail closed.
* Quarantines only unambiguously Mobo-owned missing variations during partial authoritative snapshots while preserving merchant/manual variations and blocking conflicting identity aliases.
* Adds crash-safe Variation retirement with exact Product Map snapshots, variation-only verified map deletion, forensic marker read-back, Trash failure rollback, and idempotent retry behavior.
* Clears stale parent purchase-Variant identity and forces authoritative Simple products with no purchasable source Variant to zero stock/out-of-stock.
* Hardens post-sync Repair with the shared lifecycle quarantine policy, product lock/generation fences, and conservative identity handling.
* Deep Test Suite target: 10.33.44.19-r7.8.

= 10.33.44.18 =
* Adds a bounded post-sync Repair integrity phase between authoritative Product Repair and missing-image recovery.
* Marks only products durably completed by the exact Repair syncId as eligible for final topology cleanup, and fences that marker with the captured applied revision + last-webhook watermark so products changed after Repair are never mutated by stale cleanup.
* Acquires the shared per-product concurrency lock for final Variation topology cleanup; transient lock contention retries the same parent without advancing the Repair cursor.
* Quarantines stale Mobo variations under an authoritatively repaired non-variable parent and only removes same-signature siblings under a variable parent when exactly one durable canonical identity exists; ambiguous groups remain untouched.
* Re-runs duplicate WooCommerce price-meta cleanup against final Repair state using canonical-row-first collapse and read-back verification, avoiding a delete-all/add-one crash window.
* Centralizes Product/Variation identity key families, including mobo_variant_guid aliases, and makes Portal Variant duplicate Repair recognize all shared aliases while failing closed on conflicting aliases.
* Adds a time-bounded local-only Cron Runner fence so deterministic WAMP baseline cannot be mutated by Task Scheduler, REST/Portal, self-runner or another path reaching the real cron runner.
* Deep Test Suite target: 10.33.44.18-r7.6.

= 10.33.44.17 =
* Aligns Repair price-meta ownership with the shared Product Identity Policy and recognizes all durable Portal/Mobo product and variant ID aliases.
* Prevents valid Mobo objects using non-underscored identity aliases from being skipped by duplicate price-meta cleanup.
* Deep Test Suite target: 10.33.44.17-r7.5.

= 10.33.44.16 =
* Fixes verified postmeta/termmeta persistence for integer/float values by comparing WordPress-normalized scalar read-back; revision and identity checkpoints no longer fail because an integer is read back as a string.
* Makes authoritative `categories=[]` explicitly remove `product_cat` relationships after WooCommerce product creation so the WooCommerce default category cannot survive an authoritative empty desired state.
* Makes image desired-state URLs fail closed unless the raw source explicitly contains an HTTP(S) scheme before URL normalization.
* Refreshes stale contract tests after shared-policy extraction without weakening their runtime invariants, and fixes the authoritative-Variant regression fixture to opt into authoritative snapshot semantics explicitly.
* Keeps read-only shipping diagnostics distinct from checkout shipping mutators in the shipping-hook contract.
* Adds durable-scalar, ordering-watermark, raw-image-URL and authoritative-empty regression coverage.
* Deep Test Suite target: 10.33.44.16-r7.3.

= 10.33.44.15 =
* Extends the shared excluded-product policy to every Repair mutator: Trash restore, duplicate Portal-variation identity repair and duplicate price-meta cleanup now all preserve excluded products unchanged.
* Removes the remaining Product Sync fallback copy of excluded-URL option parsing so the shared exclusion policy is the only runtime source of truth.
* Adds full cross-consumer regression coverage for exclusions, ordering aliases/stale fences, payload presence matrices, category fallback decisions, money/currency boundaries, order-submission activation and image lease ownership.
* Adds stale-worker transition tests for Image Queue and Image Refresh Queue, including release/fail/attach/skip/Done ownership gates after lease reclaim.
* Adds a full Repair state-preservation mutation test that compares price, stock, categories, image references, incomplete marker and variation identity before/after Repair on an excluded product.
* Deep Test Suite target: 10.33.44.15-r7.2.

= 10.33.44.14 =
* Centralizes source/store money conversion and canonical Mobo API-price lookup in one Money Policy used by Automatic Shipping and Remote Shipping.
* Centralizes automatic Mobo order-submission activation so bootstrap, checkout, address mapping, cron, diagnostics and remote shipping use one enabled-state truth table.
* Adds a shared payload-field presence policy that keeps absent, explicit null, explicit empty arrays and malformed values distinct across Product/Variant desired state.
* Fixes `compare_price` alias drift where presence could be detected but the value was read as null; compare-price hashing, no-op checks, source meta and mutations now use the same alias resolver.
* Makes malformed present Product/Variant price and compare-price values fail closed before WooCommerce mutation while preserving absent fields and explicit nullable state.
* Centralizes authoritative image collection validation and prevents Missing-Image Recovery from treating missing/malformed API evidence as an authoritative empty image set.
* Uses the same remote image GUID/source extractor in Product fast-path checks, Image Queue and Image Sync so identity aliases and accepted HTTP(S) sources cannot drift between image paths.
* Prevents Recategorize from converting malformed category payloads into authoritative `[]` or falling back to older stale category event evidence.
* Adds policy/presence regression coverage in Deep Test Suite 10.33.44.14-r7.1.

= 10.33.44.13 =
* Fixes excluded-product Repair recovery so a Trash product whose source URL is in «محصولات مستثنی از همگام‌سازی» can never be resurrected by Repair integrity.
* Centralizes URL exclusion across ProductUpdated, UpdateVariant, Manual/Repair fresh snapshots, Reprice, Recategorize, Missing-Image Recovery, Image Queue, Image Refresh and Parent Finalize so existing excluded products are not mutated by background work left from an older event.
* Preserves exact normalized source paths including leading-zero identities such as `/0338`; absolute URLs, trailing slashes and query strings converge to the same path key without collapsing `/0338` into `/338`.
* Adds durable GUID-to-excluded-URL evidence for URL-less Variant events while keeping the administrator URL list as the only source of truth; removing an exclusion immediately re-enables that GUID.
* Adds dashboard and Categories warnings when the default/fallback category is missing or invalid, and clarifies that this does not block product creation: unresolved new products can remain without the intended category.
* Clarifies required-manual-mapping and explicit `categories=[]` semantics so fallback cannot silently override either policy.
* Adds dedicated exclusion/Repair/category-warning contract and local mutation regressions to Deep Test Suite 10.33.44.13-r7.0.

= 10.33.44.12 =
* Hardens persisted ordering so event_version/source revision, foreground-webhook fences and Manual/Repair snapshot fences cannot let an older retry or snapshot overwrite newer WooCommerce state.
* Makes Product/Variation completion fail closed when Product Map, crash markers, parent-finalize checkpoints, Sync Health watermarks or authoritative-variation evidence cannot be persisted/read back.
* Fixes explicit-empty desired state for categories and images, while preserving existing values when those fields are omitted from a partial payload and rejecting malformed partial collections.
* Preserves canonical full-snapshot hashes across partial Product/Variation updates and backfills Product Map hash/incomplete state from canonical postmeta.
* Hardens image and image-refresh workers with exact lease ownership so an expired worker cannot commit after a newer worker reclaims the same row/source identity.
* Stops incomplete authoritative Variant snapshots from being acknowledged, persists child ordering watermarks, and prevents stale Variant deltas from mutating parent topology.
* Corrects PublishedAt handling so source publication time no longer overwrites WooCommerce date_modified, and uses full Product Ledger GUID uniqueness instead of a 150-character prefix.
* Adds crash/read-back regression coverage for queue ordering, partial-vs-empty payloads, category/image convergence, source-hash preservation, behind/synced health, Manual-vs-Webhook races and stale image leases.

= 10.33.44.11 =
* Permanently retires automatic Product Recovery and product-mutating Reconciliation from the runtime runner, scheduler, adaptive budget and circuit-breaker stages.
* Extracts observational per-product sync bookkeeping into Mobo_Core_Sync_Health; the existing mobo_sync_health table is retained without any automatic product mutation.
* Fixes per-product health convergence: terminal ProductUpdated syncs simple products, variable products remain behind until terminal UpdateVariant, partial work remains behind, and failures remain failed.
* Removes obsolete Reconciliation settings/admin actions and migrates away old recovery/reconciliation state, cursors, pending markers and locks while preserving upgrade compatibility facades.
* Keeps ordered Webhook, Product Sync, Reprice, Recategorize, images and manual Repair as the authoritative mutation paths.

= 10.33.44.10 =
* Prevents administrator-started Reprice and Recategorize queues from being starved behind large image/Product Sync backlogs by running them before heavy background lanes and reserving bounded fair-scheduler slots when webhook pressure is clear.
* Keeps webhook/order foreground priority intact; the maintenance-queue slot reservation is disabled while due webhook pressure exists.
* Fixes Recategorize so a product currently owned by manual Product Sync is deferred with a retryable product-sync-active result instead of being permanently advanced as not-allowed.
* Preserves existing product-level locks, bounded queue budgets, retry limits, circuit breakers, cache-mutation guards and upgrade barriers; no schema migration is required.

= 10.33.44.9 =
* Turns Image Refresh into a full source refresh: marked local Mobo WebP attachments are fetched again even when the canonical URL is unchanged, using a generation cache-buster and no-cache request headers.
* Downloads each source attachment only once per workflow generation, safely reuses the verified replacement across products/retries, and releases the old family immediately after the reference audit converges to avoid holding two full libraries on disk.
* Keeps worker-owned Shared Media out of site-local forced replacement and preserves all existing storage, identity-lock, WebP/subsize and reference/deletion safety gates.
* Adds a compact live stage report with current-step progress, fresh-download/reuse counts and measured attachment-family size savings.
* Automatically resets only inactive legacy refresh queue state on upgrade; no schema change, manual SQL, Repair or Reconciliation run is started.

= 10.33.44.8 =
* Runs a bounded stage-zero cleanup before image download/conversion, deleting only incomplete numeric WebP collision attachments whose Mobo source identity is proven and whose live references/worker leases are absent.
* Refuses new image sideload/subsize work when the uploads reserve, quota/inode write probe, or directory writability check fails; cleanup can still run first to recover space.
* Persists normal and refresh queue attempt counters before fatal-prone editor work, gives interrupted normal imports the same exact third-attempt quarantine escape hatch, and terminally quarantines refresh rows that exhaust their persisted budget.
* Keeps image optimization independent from product mutation: it never starts or retries Product Repair/Reconciliation and requires any Repair prerequisite to have been completed separately by an administrator.
* Caps heavy media-library scan batches at 50 during migration to preserve runner checkpoints on shared hosting.

= 10.33.44.7 =
* Quarantines one repeatedly incomplete local WebP identity on the exact third readiness failure so the next retry performs a bounded fresh import from a corrected/downsized source without deleting the current attachment first.
* Prevents disk-full or network failures after that escape hatch from creating an unbounded replacement-attachment loop; Shared Media remains manifest-controlled.
* Re-arms legacy active readiness failures already beyond the new threshold, then lets the ordinary queue apply the same guarded path.
* Disables automatic Product Recovery at build level, clears persisted payload/follow-up state and retires only its own runtime leases; manual Product Repair remains available.

= 10.33.44.6 =
* Disables product-mutating Reconciliation at build level across Cron, direct/manual execution, fair-scheduler pressure reporting, and the admin UI while keeping webhook health bookkeeping available.
* Forces the stored reconciliation toggle off and safely retires cached in-flight reconciliation snapshots during upgrade; product data and webhook rows are not changed.

= 10.33.44.5 =
* Treats negative integer stock balances as zero/out-of-stock so one oversold Variation cannot fail an otherwise valid authoritative UpdateVariant event.
* Clears stale waiting/progress diagnostics whenever a table webhook attempt is committed as done, retry, or failed, keeping `progress_json` consistent with the terminal/retry state.

= 10.33.44.4 =
* Records a self-runner HTTP handoff before dispatch, preventing a fast loopback worker from clearing the marker only for the sender to recreate it and later enter false timeout backoff.
* Keeps continuation wake-up state aligned with the worker lease so large webhook backlogs continue draining instead of waiting for the next external cron hit.

= 10.33.44.3 =
* Keeps nullable stock as unlimited (`manage_stock=false`, quantity `null`, status `instock`) while clearing stale `_mobo_stock_payload_missing` and `_mobo_last_api_stock_quantity` diagnostics on both normal and no-op convergence paths.
* Re-arms active webhook rows stranded by the legacy nullable-stock exception path and immediately schedules the queue worker after upgrade.
* Contains all webhook processor exceptions inside durable retry accounting so one poison Product/Variant event cannot remain forever in `processing` and block later Variant work.

= 10.33.44 =
* Webhook remains the foreground freshness lane while a long Sync or Repair is active: after every durable product-sync step, the runner now yields immediately when genuinely runnable webhook work has arrived, so the next round starts with webhook processing instead of consuming the remaining Repair/Sync budget.
* Repair product-page snapshots now carry an ordering watermark. If ProductUpdated or UpdateVariant applies newer state before that queued Repair product is written, Repair refreshes the exact product by GUID and refuses to replay the stale snapshot; a second check after product-lock acquisition closes the race.
* Webhook receipt remains durable during Remote Upgrade. Self-runner wake-up intent is preserved but dispatcher handoff creation is explicitly paused while the upgrade barrier is active; processing resumes automatically after barrier release.
* Sync/Repair and Remote Upgrade remain cooperative: an active Sync/Repair is paused only at a safe checkpoint, its cursor/state is retained, and upgrade does not require the entire Sync/Repair job to finish.

= 10.33.43 =
* Fixed false Remote Upgrade drain timeouts caused by a self-runner dispatch lease that had been handed off but had not reached the worker yet.
* Upgrade barrier now cancels only an unchanged, not-yet-claimed dispatcher handoff with an atomic compare-and-delete; a worker that has already claimed or renewed the lease remains protected and must drain normally.
* Effective upgrade drain time now includes safety headroom for the longest configured Mobo blocking HTTP timeout, up to the existing 300-second cap.
* Busy-upgrade errors now identify the blocking runtime lock names and remaining lease time for faster diagnosis.

= 10.33.42 =
* Fixed checkout shared-cart false failure when Mobo returns an explicitly empty cart as `cart: null`, empty cart object/array, or `items: null`.
* Empty-cart schema normalization remains fail-closed for missing `cart` fields or malformed non-empty cart payloads.
* Added diagnostic `snapshot_empty_schema_normalized` events so empty-cart compatibility decisions are visible in checkout diagnostics.

= 10.33.41 =
* Automatic Mobo order submission no longer forces the optional remote Mobo-cart validation into customer Checkout. The dedicated “بررسی موجودی لحظه‌ای در موبو” toggle now controls pre-payment shared-cart validation exactly as configured.
* The mandatory authenticated clear/rebuild/compare safety boundary remains unchanged inside automatic Mobo order submission immediately before the remote order is created.
* Updated Checkout admin status/help text so Auto Order and optional pre-payment Mobo-cart validation are shown as separate behaviors.


= 10.33.40 =
* Checkout cart preparation now verifies DELETE operations with an authoritative `update=true` cart refresh instead of a potentially stale cached snapshot.
* Shared Mobo cart clearing converges through bounded retry passes and resolves ambiguous DELETE responses by re-reading authoritative cart state, while still failing closed if rows truly remain.

= 10.33.39 =
* Extended the existing Product Repair action; no new admin button was added. Repair now runs a bounded, conservative legacy-integrity pass before the authoritative Mobo product sync.
* Repair exact duplicate Variations when PortalVariantId, parent product and normalized attribute signature all agree. The extra local Variation is moved to Trash with rollback metadata; ambiguous signature-only duplicates are preserved and reported.
* Refuse automatic duplicate quarantine when WordPress Trash retention is disabled, preventing Repair from turning a reversible quarantine into a hard delete.
* Prevent new duplicate Variations when a GUID/map entry is missing by reusing a same-parent Variation with the incoming PortalVariantId before storefront-signature fallback.
* Repair duplicate `_price`, `_regular_price`, and `_sale_price` postmeta rows on Mobo-owned products/variations and clear targeted WooCommerce caches.
* Repair stale Mobo shipping-mapping options that reference deleted WooCommerce shipping-method instances and rerun the existing legacy mapping-only cleanup policy.
* During explicit Repair only, directly verify trashed Mobo parent products by GUID against Portal and restore only a unique exact match; this also covers products omitted from an OnlyInStock list. Variation untrash still requires an exact identity in the authoritative Repair payload. Normal sync/webhook continues to respect merchant Trash actions.
* Fixed the registered `/mobo-core/v1/sync/status` REST callback that could return HTTP 500 because the callback method was missing.
* Added bounded Repair progress/statistics to the existing manual-sync status so repeated Repair runs remain idempotent and diagnosable.


= 10.33.38 =
* Hardened stock parsing to the actual Portal nullable-integer contract; fractional/scientific/boolean values are never rounded/coerced into WooCommerce stock.
* Reject duplicate or malformed raw Variant attribute rows before normalization can overwrite one concrete selection with another.
* Added a variation-only guard at the physical delete helper itself so a corrupt caller/map cannot delete a parent product.
* Hardened remote Shipping numeric strings against scientific-notation coercion while preserving finite plain decimal values.
* Canonicalize corrupted Recovery state payload/buffer/numeric fields before every bounded batch.
* Fixed nullable Mobo stock handling for simple/variation payloads: explicit JSON null is treated consistently as unspecified stock instead of an invalid payload.
* Removed the manual “بروزرسانی و ترمیم نگاشت ارسال” action from the administrator UI; automatic shipping catalog refresh/legacy cleanup remains available in background policy.
* Fixed the 10.33.35 variation-integrity recovery reason canonicalization so the intended one-time authoritative ledger refetch actually runs, including a bounded re-audit for sites that already crossed 10.33.35/10.33.36.
* Fixed system sync-event entity extraction to avoid undefined type/guid warnings on ShippingMethodsChanged/WebhookDeliveryStatusChanged.

= 10.33.36 =
* Fix Product Recovery scheduling when the state option is absent/corrupted: pending can no longer be armed without generation/cursors.
* Re-arm one bounded recovery self-heal generation on upgrades from older versions.
* Preserve one-shot Wallet protection while correcting the static regression test around cross-stage Mobo order-ID validation.
* Improve cross-process concurrency diagnostics and startup tolerance for slow Windows WP-CLI jobs.

= 10.33.35 =
* Historical variation-integrity self-heal: unchanged Portal ContentHash no longer hides a missing local product or a WooCommerce variation missing one of its parent variation attributes.
* Upgrade recovery performs one bounded exact-GUID re-audit of previously imported local products, then uses cheap local integrity skips for duplicate Portal-history evidence; OnlyInStock is never changed.
* Incomplete concrete variant payloads fail closed before WooCommerce mutation and are left behind for retry instead of silently detaching an attribute.
* Coordinates with Portal v67, whose variant-title attribute parser matches authoritative parent attribute names/values instead of blind alternating token pairs.

= 10.33.34 =
* Hardened source-price parsing: scientific/exponent notation is rejected before WooCommerce normalization, preventing values such as `1e999999` from being transformed into an unintended finite price.
* Test/runtime verification hotfixes derived from the full two-site 20260818-183024 report.

= 10.33.33 =
* Deep orchestration hardening: Image Refresh now shares the same site-wide mutation pipeline lease as Product Recovery and post-Recovery Cache Warmup, so Product/Media recovery cannot overlap cache warmup or autonomous image mutation.
* Pending Product Recovery has priority over Image Refresh; Image Refresh defers without starting a competing continuation and resumes automatically on a later worker slice.
* Test-suite hardening fixes the shared-lock TTL assertion, adds runtime pipeline exclusion/priority probes, deterministic dispatcher concurrency handshakes, and fail-closed suite-completeness checks.
* Adds `run-all.ps1` for one-command Core + Fault Injection + Strict Audits + cross-process Concurrency on the standard local test sites.
* Activation now schedules parent-product Recovery using the pre-upgrade DB version before the final version stamp; deactivate/replace/activate upgrades can no longer silently skip it.
* Adds a one-time Recovery Re-Audit for 10.33.29 through 10.33.32 installations, covering sites that may already have missed the original activation recovery scheduling path.
* Concurrency tests use deterministic ready/ack handshakes and source-assertion tests escape literal PHP variables to eliminate timing/interpolation false failures.

= 10.33.32 =
* Recovery orchestration hardening: one atomic site dispatcher, shared recovery/warmup lease, bounded retry/backoff and cursor batches.
* Cache warmup is deferred until product recovery completes and drains serially without per-product worker fan-out.
* Duplicate/stale worker requests fail fast with 423/409 and loopback timeout retries use bounded backoff.
* Includes the one-click autonomous Image Refresh workflow introduced in 10.33.31.

= 10.33.31 =
* Image Refresh is now a one-click autonomous workflow: Product Repair prerequisite, retry/backoff, safe cleanup and convergence require no administrator decisions after start.
* One failed image no longer pauses the complete workflow; repeated failures are bounded and quarantined while independent images continue.
* Replaced old attachments and orphan file families are cleaned automatically only after the existing conservative safety audits/revalidation pass.
* WebP subsize repair retries automatically and unresolved cuts are retained/quarantined instead of blocking the whole refresh cycle.


= 10.33.30 =
* Purchase safety now freezes Mobo/non-Mobo identity on WooCommerce order line items before payment (Classic Checkout and Checkout Blocks/Store API), so later catalogue deletion or metadata drift cannot change what the paid order means.
* Shared Mobo cart validation is fail-closed: duplicate Woo rows are aggregated by portal_variant_id, unexpected/extra remote items are rejected, clear operations are verified by a fresh snapshot, malformed nested cart rows/types are rejected, and auth responses can no longer be mistaken for an empty cart.
* Automatic Mobo order submission revalidates an immutable business fingerprint immediately before Wallet Payment, covering the complete Woo line-item structure (including non-Mobo lines), Mobo quantities, totals/refunds/payment method, recipient/address/location, Woo shipping lines/cost/mapping, order status, sender settings, and a one-way Mobo account/config fingerprint. Changes defer/abort before payment.
* Wallet Payment is now a strict one-shot boundary: it is never auto-replayed after 401/403 or any other non-success acknowledgement. Any HTTP/transport ambiguity or unrecognized/nullable `paid` acknowledgement after the Wallet POST is marked uncertain; only an explicitly recognized `paid=false` can be treated as definitive unpaid.
* Queue/manual-retry races are hardened: cancelled/on-hold/refunded orders are not purchased from a stale queue; post-payment Woo divergence is sticky and suppresses auto status changes; legacy orders with uncaptured deleted catalogue lines fail closed as `blocked_invalid_scope` instead of purchasing only the visible subset.
* Post-success listeners are isolated after durable Mobo success. An exception in wallet alerts, revenue bookkeeping, SMS, or third-party success hooks cannot convert a completed Mobo purchase into a retryable payment attempt.
* Shipping selection now rejects orders with multiple distinct WooCommerce shipping methods/packages because Mobo accepts one shipping_id; partial shipping addresses consistently fall back to billing; remote Cart/Variant/Shipping/Mobo Order IDs are strict positive integers and malformed/coerced IDs fail closed.
* Revenue/source-cost snapshots now follow frozen order identity and are captured before Store API payment, so deleted catalogue products do not erase the accounting identity/cost of already-created orders.
* Support logging masks customer PII in addition to credentials, strips query strings (including checkout tokens) from logged Mobo paths, omits raw non-JSON response bodies, and invalidates the shared Mobo session when username-only account changes occur. Built-in Mobo/cart validation errors also cannot be erased by a third-party filter while automatic Mobo purchase is enabled.
* Auto Order can be disabled safely while a worker is already preparing Mobo: the queue is re-checked between orders and again at the final reversible boundary before Wallet. Deferred orders remain durable and re-enabling Auto Order wakes the Self Runner immediately.
* The pre-Wallet fingerprint now includes fee, coupon, and tax line structure in addition to aggregate totals, preventing equal-and-opposite order edits from bypassing the final business-state guard.
* Authoritative Mobo shipping snapshots now fail closed on malformed method/location IDs, nested status data, non-finite numeric bounds, malformed rules, or invalid restriction collections; a bad refresh cannot replace the last known-good shipping snapshot.
* Mobo cart tokens and the legacy cart-item map are schema-hardened: arrays/objects/control-character tokens and permissively-coercible remote IDs are rejected instead of being converted into another valid cart/order identity.
* No database migration is required by 10.33.30. Parent-product append-only/recovery behavior from 10.33.29 remains unchanged.

= 10.33.29 =
* Parent-product retention is now absolute: Mobo Core never physically deletes an imported WooCommerce parent because of a remote delete revision, an empty exact-product snapshot, or absence from a complete/unfiltered Deep Integrity catalog. Authoritative stale Variation cleanup remains enabled.
* Existing installations automatically start a one-time Product Recovery after upgrade; administrators do not need to disable `OnlyInStock`, start Repair, or change customer settings.
* Added an append-only Product Ledger. Upgrade seeding records durable local proof from Product Map, legacy `product_guid` postmeta, surviving Image Queue rows, and completed local ProductUpdated sync-event rows; every future successful product upsert refreshes this ownership evidence.
* Auto Recovery scans local ledger evidence first and then a site-scoped Portal recovery manifest. Missing products are fetched exactly by GUID, rebuilt through the normal desired-state engine, and their authoritative Variations are reconciled.
* Recovery is bounded, lock-protected, retryable, and driven by the existing Real Cron/Self Runner. If the Portal recovery endpoint has not been deployed yet, the plugin backs off and continues automatically later.
* Database change: additive `wp_mobo_product_ledger` table with old-MySQL-safe unique GUID index. No existing WooCommerce product is deleted by this migration.

= 10.33.28 =
* Fixed a destructive Deep Integrity bug: when `OnlyInStock` is enabled, products that become out of stock are no longer interpreted as remotely deleted merely because the filtered catalog no longer returns them.
* Deep reconciliation captures the product-list filter for the entire scan. Absence-based product deletion is authorized only after a complete unfiltered catalog; explicit remote delete events remain authoritative.
* Ambiguous single-product `data:[]` responses no longer delete an existing local product while `OnlyInStock` is enabled.
* Hardened category identity bootstrap/map persistence, reconciliation health persistence, webhook event CAS/terminal file handling, payment uncertainty fencing, and bounded legacy Product/Variation/Category map reseeding.
* Database migration postconditions now verify correctness-critical indexes before advancing the schema version.
* No destructive database migration is performed. Existing Mobo products are not deleted by the 10.33.28 migration.

= 10.33.27 =
* Order/payment idempotency: per-Woo-order submission leases, durable pre-remote attempt checkpoints, fail-closed wallet acknowledgements, uncertain-order retry barriers, and verified local success commits prevent stale/duplicate purchase attempts.
* Shared Mobo session safety: login/wallet checks share the cart/session lease, credential changes defer cookie reset until lock ownership, and cookie persistence is verified.
* Security hardening: third-party checkout validators no longer receive Portal Token/X-SEC, default remote-upgrade packages must use the exact Portal origin, staged symlinks are rejected, and normal image sideloads no longer weaken WordPress SSRF checks.
* WooCommerce postconditions: product type/attribute/simple-variant writes, image featured/gallery linkage, reprice bookkeeping, quarantine state, and immutable Revenue Ledger writes are reloaded and verified before success.
* Database/hosting compatibility: Product Map identity index uses a safe GUID prefix for legacy utf8mb4 767-byte key limits while retaining full GUID runtime comparisons; schema readiness remains fail-closed.
* Shipping/runtime durability: legacy mapping-only shipping cleanup is checkpointed only after verified disablement, webhook fallback never overwrites a known-good file after rename failure, city-asset readiness is read back, and uninstall clears stale runtime queues/session/log state without deleting business data.

= 10.33.26 =
* Database migration durability: plugin/schema versions are committed only after required tables/columns are verified; failed dbDelta work remains retryable and Health now reports schema readiness plus the last migration error.
* Webhook/Event ingestion: duplicate remote event insertion is serialized at the dedupe identity, and JSON fallback writes use same-directory temp files plus atomic rename so crash/interrupted writes do not truncate the durable fallback queue.
* Order queue durability: option-backed queue writes are verified by read-back, failed persistence creates an independent bounded recovery marker, and Real Cron/Self Runner can recover processing Mobo orders even when DISABLE_WP_CRON is enabled.
* Sensitive logging: checkout/debug context redaction now uses canonical recursive key matching for CSRF, Token, Authorization, X-SEC, security code and related credential fields; diagnostic request URIs no longer include query strings.
* Category synchronization: child categories wait for their mapped parent, partial/malformed snapshots never advance the authoritative checkpoint, incomplete terms remain repairable, and taxonomy assignment failures leave products incomplete/retryable instead of being silently accepted.
* Address/Shipping snapshots: authoritative cache replacement is serialized, validates every row/identity/parent reference, rejects stale late responses, verifies durable option writes, and advances last-success only after the complete snapshot (including city assets) is committed.
* Queue concurrency: Cache Warmup, Parent Finalize and deferred archive purge use short claim/commit critical sections with processing tokens instead of holding queue mutexes around HTTP/WooCommerce/cache work; newer enqueues cannot be erased by stale workers.
* Manual Sync/Upgrade safety: Resume is generation-aware and only resumes a waiting-for-Portal run through the sync service; Upgrade Barrier no longer mutates the manual-sync cursor/state merely to display a pause.
* Reconciliation/Product Map: destructive sweep checks mapping DB deletions before advancing cursors, variation-map bulk cleanup distinguishes DB errors from an empty set, and a composite parent/object index accelerates authoritative variation cleanup.
* Variation identity migration: a new GUID for the same local variation is persisted on the variation and Product Map before stale reverse mappings are retired, preventing a crash window with no durable identity.
* Price/stock input safety: present-but-malformed, negative, non-finite or overflow numeric values fail closed and are retried/diagnosed instead of being coerced to zero or silently treated as converged.
* Image queues: desired-image persistence now checks every database insert/update/delete before pruning old state; Image Refresh also reports database update failures instead of returning a false successful enqueue.
* Image Refresh automation: Cron uses the current automation/workflow state as the source of truth instead of legacy enable options that could incorrectly disable refresh after upgrade/restore.
* Database change: Product Map gains only an additive `parent_object (parent_remote_guid, object_type)` index through dbDelta; no customer data is deleted by this migration.

= 10.33.25 =
* Webhook/Event Queue ownership: processing claims now carry a per-claim token and all worker completion/retry/failure transitions use compare-and-set ownership, preventing expired/stale workers from overwriting a newer claim. Stale-parent maintenance also retires only rows that are still pending.
* Reconciliation destructive safety: deep catalog sweep, variant pruning, single-product deletion, and revision watermark advancement now require explicit valid response shapes, advancing cursors/revisions, and proven terminal authoritative snapshots. Malformed HTTP-200 responses can no longer be interpreted as an empty desired state.
* Product/Variation integrity: attribute changes preserve existing children until the replacement authoritative snapshot is terminal; failed WordPress variation deletion preserves mapping/state and blocks Variable→Simple/finalization commit for safe retry.
* Manual Sync state machine: Start and Worker locks are centralized inside the sync service, sync generations are protected from stale-worker overwrite, coalesced cron steps re-check durable cancel/generation state before mutation, and Reset/Cancel no longer break live locks.
* Order submission concurrency: the shared Mobo cart uses renewable token-owned runtime locks, the option-backed submission queue mutates entries under a database lock with per-entry tokens, and stale workers cannot delete a newer enqueue.
* Payment ambiguity safety: once Wallet Payment has been sent, timeout/transport/5xx or structurally ambiguous success responses are classified as `uncertain` for administrator review instead of automatic retry, reducing duplicate Mobo payment/order risk. Explicit remote failure remains retryable.
* External-request trust boundary: webhook payload pulls require same-origin URLs relative to the configured API base and validate every redirect before sending Token/X-SEC. Remote package downloads validate every redirect and never forward package/license credentials to a different origin.
* Maintenance queues: Reprice and Recategorize no longer advance past a product that was locked or failed transiently; Start/Cancel/Reset are serialized so stale workers cannot overwrite a new queue generation.
* Shipping mapping: an explicit authoritative empty methods/shippings collection is now stored as a valid empty snapshot, so suspended/removed Mobo shipping methods do not leave stale mappings visible.
* Order SMS: order+scenario sends are serialized with a runtime lock to prevent duplicate SMS from simultaneous Classic/Blocks hooks.
* Security/diagnostics: Self Runner sends the cron credential in X-SEC instead of the URL, order submission logs redact token/security fields, and shared-cart diagnostics read the real runtime lock.
* Database: `wp_mobo_sync_events` gains an additive `claim_token` column/index through dbDelta. Upgrade migration also aborts only an unproven legacy deep sweep and does not delete products/files.

= 10.33.24 =
* Deep image integrity: Local WebP health now verifies `_wp_attached_file` against attachment metadata, physical original dimensions, each derivative's real MIME/dimensions, stored post MIME/extension, and the dimensions currently expected from registered WordPress image-size settings.
* WebP repair: stale-but-present derivatives from old theme/image-size settings are invalidated and regenerated instead of being accepted merely because their metadata row and file both exist.
* WooCommerce image linkage: the featured attachment is no longer duplicated inside `_product_image_gallery`; maintenance automatically schedules linkage-only repair for existing products with wrong/missing featured/gallery references.
* Image Fast Path and historical Refresh `done` rows now use the same deep storage-readiness rules as active synchronization, so corrupt cuts, stale metadata, duplicate featured/gallery state, and incomplete identity can no longer hide behind an unchanged source hash.
* Crash-safe Local imports: image GUID/source identity is persisted at WordPress attachment creation time with `mobo_sync_incomplete=1`; an attachment becomes complete only after physical MIME, metadata, and subsize readiness pass. This closes the post-insert/pre-identity crash window that could otherwise create `-1`, `-2`, ... collision copies on retry.
* Attachment identity convergence: durable queue rows require a persisted matching image GUID and a completed storage marker; legacy/uncommitted attachments may still be adopted by the resolver but are not treated as converged until identity/readiness metadata is repaired.
* Shared Media transactions: manifest refresh/import uses the same GUID identity lock, validates before mutation, verifies persisted post/meta after mutation, and rolls back existing attachment state or removes a newly-created virtual record when the commit fails. Site-side code never deletes shared physical files during rollback.
* Shared Media deep health now verifies GUID aliases, image format, stored MIME, attached-file path, manifest revision/profile, physical family MIME/bytes/dimensions, and persisted attachment metadata before declaring a shared attachment healthy.
* Queue concurrency: normal Image Queue and Image Refresh row leases are aligned to the 300-second storage-mutation locks to reduce row reclaim while a slow sideload/subsize operation is still active.
* Queue identity upgrade: case-insensitive canonical GUID keys adopt and migrate legacy case-sensitive Queue/Refresh rows instead of inserting duplicate durable jobs after a casing-only GUID change.
* Upgrade to 10.33.24 schedules a bounded image-storage re-audit/linkage recovery for existing installations; no files are deleted by the migration and no database schema migration is required.

= 10.33.23 =
* Image Queue concurrency: stale workers now commit `attaching`/failure state only when product + image GUID + exact source URL still match the row they claimed; superseded work is released back to pending immediately.
* Image desired-state validation: the source-hash fast path now verifies durable queue rows, attachment identity, physical image payloads, and the actual WooCommerce featured/gallery order before image sync is skipped.
* Attachment identity: a shared source URL can no longer make one image GUID adopt an attachment explicitly owned by another GUID; GUID comparisons are case-insensitive for UUID-style identities and legacy unclaimed attachments remain adoptable.
* Image Queue input safety: duplicate/malformed remote image identities no longer make a partial payload authoritative for destructive desired-state pruning.
* Image Refresh recovery: historical `done` jobs are revalidated against the exact GUID/source, real WebP storage, and current product references; stale jobs automatically return to pending.
* Image Refresh supersession: when an older refresh worker already replaced the legacy attachment before a newer source superseded it, the new job continues from the in-use attachment with the same GUID instead of being incorrectly skipped.
* WebP subsizes: normal image sync and legacy refresh share a per-attachment mutation lock, and an attachment is not linked/committed until the original plus required WebP subsize state passes final readiness checks.
* Shared Media: configured-vs-mounted state is separated, so a temporary read-only repository outage does not silently fall back to per-site downloads unless explicit fallback is enabled.
* Shared Media manifests are validated as complete physical families before database mutation; persisted WordPress attachment metadata is read back and verified after commit, and stale shared metadata is repaired from the manifest without local thumbnail generation.
* Shared Media safety: per-site refresh/orphan cleanup never deletes worker-owned shared physical files; Shared attachments are excluded from local orphan deletion and shared-to-shared refresh deletion.
* Image Queue `done` rows are retained as durable desired state instead of being removed merely because they are old, preserving future storage repair and gallery-order recovery.
* Operational Image Refresh failures remain recoverable with bounded long-term backoff instead of becoming permanently stranded after a small retry count.
* No database schema migration is required.

= 10.33.22 =
* Image storage: added per-image GUID and source-URL identity locks shared by normal Image Queue and Image Refresh imports, preventing concurrent workers from creating WordPress collision copies such as `image-1.webp`, `image-2.webp`, and higher suffixes.
* Image recovery: existing attachments are reusable only when the physical file exists, is non-empty, and contains a real image payload; WebP sources additionally require real WebP content. Missing/corrupt attachments are downloaded again instead of being treated as healthy.
* Image identity: when a previously synced attachment already records `mobo_source_url`, a changed current source URL is no longer satisfied by simply relabeling the old file; the new physical image is imported. Legacy GUID-only attachments remain recoverable.
* Missing Image Recovery now cursor-scans all known local Mobo products and validates the physical featured-image file, so stale `_wp_attached_file` metadata can no longer hide a deleted, zero-byte, or non-image file from Repair/automatic refresh.
* Image Queue now audits completed rows during bounded maintenance and automatically requeues attachments whose physical file is missing/corrupt or whose recorded source no longer matches. This covers featured and gallery images while the durable queue row exists.
* Image Queue now prunes obsolete rows when a new non-empty desired image list is received, so images removed from Mobo no longer remain indefinitely in the WooCommerce gallery because of stale queue state. Attachment files are not deleted by this pruning.
* Shared Media refresh now passes through the same GUID/source identity locks as normal image imports while preserving the requirement that legacy refresh creates/reuses a distinct shared attachment instead of converting the old local attachment in place.
* Shared Media validates complete manifest-derived attachment metadata before inserting/updating WordPress attachments, preventing half-initialized attachment records when a manifest is incomplete.
* No database schema migration is required.

= 10.33.21 =
* Revenue Ledger: source unit-cost and snapshot timestamp line-item metadata remain stored for immutable calculations but are hidden from WooCommerce order screens and display-oriented formatted item metadata.
* Prevents `_mobo_revenue_source_unit_cost` and `_mobo_revenue_source_snapshot_at` from appearing in customer order details, emails, and invoice/PDF integrations that use WooCommerce formatted order-item metadata.
* No database schema migration is required and existing revenue snapshots remain valid.

= 10.33.20 =
* Image Refresh: removed the redundant manual enable switch from the safe refresh flow; starting automation or explicitly running stage 3 is now sufficient.
* Image Refresh: standard WebP subsize generation/verification and safe leftover cleanup are always enabled.
* Orphan cleanup: detects old WordPress collision copies such as `image-1.webp` ... `image-999.webp` using the trusted Mobo source filename while protecting current/registered/referenced files.
* Orphan cleanup: partial filesystem delete failures now record the exact failed paths plus file/parent writability diagnostics and remain retryable after the filesystem issue is fixed.
* Legacy cleanup: raster collision families such as `image-1.jpg` and their WordPress cuts are included in the guarded leftover scan.

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
