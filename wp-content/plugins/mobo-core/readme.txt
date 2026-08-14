=== Mobo Core ===
Contributors: pedramdev
Tags: woocommerce, iran, product sync, mobomobo, order automation
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Requires Plugins: woocommerce, persian-woocommerce
WC requires at least: 8.2
WC tested up to: 10.9
Stable tag: 10.33.18
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
= 10.33.17.2 =
* Hardened Mobo wallet/order SMS delivery through Persian WooCommerce SMS: third-party bootstrap, shortcode rendering, recipient validation and gateway send exceptions are now converted to bounded WP_Error diagnostics instead of escaping checkout/order hooks.
* Iranian recipient numbers are normalized from Persian/Arabic digits and common +98/0098/98/9xxxxxxxxx forms before PWSMS validation, reducing silent recipient rejection caused by formatting differences.
* Added compatible success-result handling for boolean/integer and structured success/status responses while preserving arbitrary strings as failures so gateway error text cannot be misclassified as success.
* Added a manager-only direct wallet-alert SMS transport test that does not consume the one-shot alert flag, plus persistent last-attempt/last-success/last-error diagnostics in the SMS tab and dashboard.
* No database schema or Portal migration is required; diagnostics use normal bounded WordPress options and the actual gateway remains fully owned by Persian WooCommerce SMS.

= 10.33.17.1 =
* Fixed Image Refresh Stage 7 so enabling persistent safe old-attachment deletion automatically drains all remaining bounded batches without one admin click per batch.
* Manual Stage 6 completion and the first manual Stage 7 batch now hand off safely to Cron/Self Runner while preserving cursors and every replacement/subsize/reference safety guard.
* Added upgrade recovery for stores already parked at a safe actionable Stage 7 with delete-old enabled.

= 10.33.17.0 =
* Added adaptive time-budget allocation across foreground and background worker stages using one request-initial queue-pressure snapshot, bounded stage weights and a single-stage monopolization cap.
* Replaced the static background escape policy with a weighted fair scheduler that limits background concurrency under webhook pressure and applies starvation recovery without delaying Webhook, Order Submission or Product Sync foreground lanes.
* Runtime intelligence now persists bounded EWMA latency/failure signals, latency trend, success/failure streaks and p50/p95 run latency inside the existing 24-hour diagnostics payload.
* Added per-stage circuit breakers with exponential cooldown and half-open probes; non-critical failing stages are isolated while Webhook and Order Submission degrade capacity instead of being disabled.
* Removed the repeated end-of-round webhook due-work database probe and reused processor/scheduler state; queue-pressure summaries are captured once and existing request-local queue summary caches are reused.
* Health v2 now exposes adaptive budget, fair-scheduler selection/defer history, circuit state, EWMA/p50/p95 latency, failure streaks and automatic starvation/circuit recommendations.
* Final hardening keeps all configured settings as source-of-truth baselines, preserves upgrade barriers and queue durability, adds no schema migration, and keeps all new cross-request state inside the existing bounded runtime diagnostics option.

= 10.33.16.19 =
* Adaptive Self-Tuning now applies per-stage confidence thresholds, a 20% target-time hysteresis band and a 10-minute per-stage cooldown so normal sample jitter cannot make worker capacities oscillate between cron runs.
* Capacity increases require stronger confidence and are ramped by at most 50% of the previous stable limit per accepted decision; configured settings remain the immutable baseline and existing hard caps still apply.
* Memory pressure, frequent time-budget pressure, recent failures and severe measured slowdowns may still downshift immediately, bypassing cooldown when safety requires it.
* Stability state is carried only inside the existing bounded runtimeDiagnostics adaptive profile; no new option, table, migration or per-stage write is introduced.
* Adaptive diagnostics and the Health tab now expose per-stage anchor/ideal/applied limits, confidence, target/predicted time, cooldown state and decision reason for operational debugging.

= 10.33.16.18 =
* Reconciliation now recovers missing GUID map rows from indexed mobo_sync_health data before touching the legacy wp_postmeta fallback, and caches the health-table existence check for the request.
* Product Sync now records exact per-step runtime samples in the existing request-local diagnostics buffer; no extra per-step database writes are introduced.
* Adaptive Self-Tuning can now safely scale productStepsPerRound from 1 up to 2x its configured baseline (hard cap 20) after at least four measured Product Sync steps, while failures, memory pressure and time-budget pressure retain conservative caps.
* No database schema migration is required; configured settings remain immutable baselines and all adaptive changes remain request-local.

= 10.33.16.17 =
* Added adaptive self-tuning for bounded worker batches. Configured settings remain the baseline and are never overwritten; tuning applies only to the current runner invocation.
* Runtime diagnostics now maintain a lightweight recent milliseconds-per-item EWMA and recent failure ratio for each stage, allowing decisions to react to current host speed rather than only lifetime averages.
* Webhook adaptive ceiling, Parent Finalize, Image Queue, Reprice, Recategorize, Cache Warmup and product steps can automatically downshift under memory/time pressure; fast stage samples may raise safe batch ceilings within strict limits.
* Recent memory-pressure stops immediately force a conservative profile; frequent time-budget exhaustion selects a cautious profile. Product steps only downshift and are never speculatively increased.
* Adaptive batch sizing uses the existing configured values as the source-of-truth baseline and is capped between 50% and 200% of that baseline (plus each worker's existing hard limit).
* The latest adaptive mode, reason, baseline, limits, stage samples and memory headroom are persisted inside the existing bounded runtimeDiagnostics payload and exposed through Site Health and the authenticated /health endpoint.
* Added an admin toggle under Real Cron to disable adaptive execution and restore exact configured batch values immediately.
* Site Health now shows the current adaptive profile and recent milliseconds-per-item beside per-stage runtime metrics.

= 10.33.16.16 =
* Added lightweight 24-hour runtime diagnostics with one bounded aggregate option write per worker/request instead of per-event telemetry rows.
* Real Cron now records per-stage run count, total/maximum duration, processed/updated/failed counters, runner stop reasons and the latest priority-scheduler decision.
* Product and variation convergence fast paths now expose No-op versus real WooCommerce CRUD save counters without changing synchronization semantics.
* Slow runner stages above the default 2-second threshold are retained in a bounded recent list for operational diagnosis.
* Site Health and the authenticated /health endpoint now expose runtimeDiagnostics, including stage timing, queue health, slow operations, scheduler decisions and environment recommendations.
* Webhook and image health now use existing aggregate queue summaries; image summary also exposes the oldest pending timestamp. Parent Finalize health exposes its oldest queued item.
* Health recommendations detect missing persistent object cache, disabled OPcache, HPOS state, high due-webhook backlog, image failures and recent runner memory-pressure stops.
* The WordPress Health tab now shows Product/Variation No-op vs Save counts, runner averages, queue pressure, per-stage timing and recent slow operations.

= 10.33.16.15 =
* Real Cron now uses explicit priority lanes: due desired-state webhooks and queued customer order submissions run before background media/reprice/recategorize work.
* Queued Mobo order submission moved directly after the webhook pass and can process two orders when the remaining runner budget is large enough, reducing order latency under mixed workloads.
* Background queues yield during due webhook pressure but receive rotating starvation-escape slots every four rounds (image, reprice, recategorize, image refresh) so sustained source traffic cannot permanently starve lower-priority work.
* Variable-parent finalization receives its own bounded starvation escape every three pressure rounds (one parent / two-second budget), preventing parent recalculation from being postponed forever during continuous variation bursts.
* Reconciliation and maintenance are deferred while immediately runnable webhook work remains, preserving desired-state throughput and HTTP/DB budget.
* Idle option-backed lanes now use cheap state/queue preflights before class autoload: completed Manual Sync, inactive Reprice/Recategorize, empty Parent Finalize/Warmup, disabled Order Submission and disabled Image Refresh no longer load their heavy worker classes just to discover there is no work.
* Runner health now exposes the latest priority-scheduler pressure/escape state for diagnostics.

= 10.33.16.14 =
* Queue/table housekeeping now runs in short indexed chunks with a hard three-second budget and automatically switches to five-minute catch-up cadence only while old terminal backlog is still likely present.
* Sync-event cleanup can drain up to 3,500 expired done rows and 1,500 expired failed rows per maintenance pass instead of a single 500-row cap, preventing long-lived high-volume sites from accumulating unbounded terminal events.
* Added status+updated_at cleanup indexes to sync-event, image, and image-refresh queues plus an updated_at index for product-map orphan cleanup; all index definitions remain compatible with older utf8mb4 InnoDB key limits.
* Completed/skipped image-refresh bookkeeping is retained for 45 days then pruned; failed/pending/processing rows and media attachments are never removed by this retention pass.
* Product-map rows are pruned only when their local WordPress post has been physically missing for at least 30 days; trashed posts remain mapped.
* Image queue orphan cleanup and Action Scheduler log cleanup now delete selected IDs in bulk instead of issuing one DELETE statement per row.
* Legacy Mobo WP-Cron hook cleanup now rewrites the cron option at most once per maintenance pass instead of calling wp_clear_scheduled_hook repeatedly for every historical hook name.
* Recategorize recovery now uses an indexed ProductUpdated event_type/entity_guid lookup first; the expensive payload_json LIKE scan is reserved only for legacy rows with no entity_guid.

= 10.33.16.13 =
* Settings defaults are now constructed once per PHP request instead of rebuilding the several-hundred-entry defaults array on every settings read.
* Real Cron bulk-primes its hot non-autoloaded Mobo settings through the WordPress option cache, reducing cold-run one-row option SELECTs across queue stages.
* Plugin upgrade/default seeding bulk-primes every defined Mobo setting before existence checks, avoiding hundreds of independent get_option database lookups on cold migrations.
* Portal settings export primes the complete settings set before snapshot generation so health/config retrieval does not fan out into per-option database reads.
* Legacy mobo_core_* options left autoloaded by old installations are normalized to autoload=no once on upgrade, keeping queue/state/history payloads out of ordinary storefront alloptions memory.
* Repeated self-runner throttle/lock/deferred diagnostics are write-coalesced to at most once per 30 seconds for an unchanged status; actual dispatches and failures remain immediately durable.
* Removed a legacy duplicate update_option call in default-adjustment migration.

= 10.33.16.12 =
* Replaces the eager all-class bootstrap with a PHP 7.4-compatible Mobo Core autoloader; heavy sync, image, migration, health, remote-upgrade and admin components are parsed only when their request path actually needs them.
* Ordinary read-only storefront GET/HEAD requests no longer register the full product cache mutation listener stack; a lightweight WP Rocket product-category URL-validation filter preserves the existing hierarchical-category compatibility behavior.
* REST routes are registered lazily on rest_api_init, while SMS and wallet classes are instantiated only when their real WooCommerce/Mobo order hooks fire.
* Migration code is loaded only after an actual plugin DB-version change or while an init-dependent deferred repair flag remains pending; completed one-time shipping cleanup is no longer bootstrapped on every request.
* Variation editor and sync-settings guard components are restricted to admin/REST/AJAX/CLI contexts where their hooks can actually be used; shared-media code is loaded only when server configuration explicitly enables it.
* Product named locks are now fail-fast after the atomic runtime lease instead of waiting up to five seconds inside MySQL GET_LOCK during contention.
* Table-backed ProductUpdated/parent-identified UpdateVariant events preflight product contention before remote payload pulls and defer briefly when Manual/Repair/another writer owns that product, preserving HTTP/runner budget for unrelated work.
* Direct mobo-cron.php invocations now define cron context before WordPress bootstrap so lazy loading still enables the full mutation/worker runtime for both CLI and direct HTTP cron execution.
* Persian WooCommerce dependency detection now prefers already-loaded runtime signals before loading wp-admin plugin APIs on normal storefront requests.

= 10.33.16.11 =
* Real-cron Product Sync now coalesces durable mobo_core_sync_state checkpoints across up to 3 idempotent steps or 2 seconds, while manual/admin one-step execution keeps immediate persistence.
* Buffered Product Sync state is always flushed at the cron-round boundary and re-checks the persisted state before flushing so a concurrent admin cancellation cannot be overwritten.
* Reprice and Recategorize workers checkpoint cursor/state every 5 items or 2 seconds instead of writing the full wp_options state after every product; an abrupt crash replays at most a few idempotent items.
* Reprice and Recategorize now accept cooperative per-stage time budgets and return budget-exhausted cleanly so the real cron runner can continue them in the next bounded slice.
* Real cron now stops safely on PHP memory pressure before OOM, reports memory usage/peak/limit/reserve in runner health, and continues only when the previous slice actually made progress.
* Image worker batch size now contracts automatically to 1-2 images when little cron time remains, reducing deadline overruns from media HTTP/import work.
* Image-refresh automation tick telemetry writes are throttled to once per 5 seconds during tight self-runner continuation loops; workflow checkpoints/results remain durable.
* Normal cron invocations now persist the final runner result once instead of writing the same result twice; health-report runs retain the deliberate pre-report + post-report persistence sequence.

= 10.33.16.9 =
* New products and new variations are assembled fully in memory and persisted with one WooCommerce CRUD save instead of an initial incomplete save followed by a second final save.
* Crash recovery is preserved: new objects are saved with mobo_sync_incomplete=1 and the marker is cleared only after the single CRUD save succeeds.
* Product/variation price, stock, slug, published-date, identity, source-hash, and attribute setters now skip identical values before marking WooCommerce data dirty.
* Repeated Mobo bookkeeping metadata is queued only when its value changes; diagnostic timestamps advance only when the corresponding source/policy/stock state actually changes.
* Variation attributes are no longer set again during price/stock-only mutations when the normalized attribute signature is unchanged.
* Reprice Queue now skips WC_Product::save(), transient invalidation, and parent variable sync when the calculated storefront price is already correct; bookkeeping-only changes use lightweight postmeta updates.
* Reprice parent synchronization is now requested only for variations whose rendered price actually changed.

= 10.33.16.8 =
* Product category assignment is now exact-set diff aware: unchanged product_cat assignments skip wp_set_object_terms(), avoiding taxonomy hooks, term counts, and cache churn on price/stock-only updates.
* Category assignment/source and missing-GUID metadata now use update-if-changed semantics instead of unconditional postmeta writes.
* Category GUID-to-term resolution and category-map table/term existence checks are request-cached, including safe negative lookup caching.
* Existing products no longer reconstruct/set identical WC_Product attributes during unrelated field updates, avoiding unnecessary _product_attributes serialization.
* Attribute comparisons normalize directly from source payload without constructing temporary WC_Product_Attribute objects.
* Attribute GUID compatibility metadata uses update-if-changed/delete-if-present semantics.

= 10.33.16.7 =
* Coalesces successful image queue linkage so WooCommerce featured/gallery state is saved once per touched product per worker batch instead of once per image.
* Keeps imported attachments in the durable attaching state until product linkage succeeds, then completes all linked queue rows with one bulk status update.
* Adds a short attaching retry grace window so a second worker/request does not race the current batch; interrupted requests still self-recover without re-downloading files.
* Adds request-local attachment GUID/source lookup caching (including misses) and caches repeated attachment meta-query results.
* Primes product/attachment post and meta caches for claimed image batches and existing queue attachments.
* Attachment identity metadata is now written only when values actually change, removing duplicate postmeta writes after sideload/import.
* WooCommerce image linkage is diff-aware: if featured/gallery IDs already match the desired queue order, no WC_Product::save() or cache invalidation is generated.
* A third-party hook failure during one product image-link step leaves rows safely attachable for retry instead of losing the imported attachment or blocking unrelated queue work.

= 10.33.16.6 =
* Bulk-primes Variation GUID mappings per UpdateVariant page, replacing per-item map SELECTs with one bounded lookup when mappings already exist.
* Primes WordPress post/meta caches for mapped variations before no-op and identity checks.
* Authoritative seen-Variant tracking now writes the growing seen set once per page instead of once per Variant.
* Attribute-signature recovery now builds one request-local parent index directly from primed Variation meta, avoiding repeated full-child hydration/scans when remote Variant GUIDs are recreated.
* A valid Product Map post status is authoritative for trash/auto-draft checks, eliminating redundant legacy meta queries for mapped objects.
* Added request-local parent Portal identity and blocked-status caches, bulk meta prefetch before destructive Variant sweeps, and removed a duplicate compatibility meta write.

= 10.33.16.5 =
* Added bulk claim for database-backed webhook events: one bounded ID lookup + one bulk lock update + one row fetch replaces the old per-event lock UPDATE hot path.
* Added the same bulk-claim/release safety model to the image worker, including safe release on upgrade/time boundaries so claimed rows cannot remain stuck in processing.
* Replaced repeated webhook/image queue COUNT queries with request-cached single aggregate summaries and fast LIMIT 1 due-work checks for runner decisions.
* Added compact status/retry/id indexes to webhook and image queue tables so due scans and stale-lock recovery remain efficient as queue history grows.
* Throttled the expensive legacy waiting-for-parent progress_json safety sweep to at most once every five minutes; due deferred rows still self-retire normally.
* Product-map writes now use one atomic INSERT ... ON DUPLICATE KEY UPDATE instead of SELECT-then-INSERT/UPDATE, and repeated remote GUID lookups are cached for the current request.
* Obsolete variation-map rows are deleted in bounded bulk chunks instead of one DELETE per stale variation.
* Product image enqueue resolves all existing queue keys for the desired image set in one lookup rather than one SELECT per image, while preserving attachment/source compatibility safeguards.
* Queue table-existence checks are request-cached, removing repeated SHOW TABLES probes from sync/runner hot paths.

= 10.33.16.4 =
* Added conservative last-desired-state event coalescing for safe single-product ProductUpdated and single-variant UpdateVariant events; older still-pending rows are superseded without touching processing or authoritative multi-entity snapshots.
* When comparable numeric or ISO event/entity versions are supplied, coalescing rejects a late stale delivery instead of allowing arrival order to overwrite a newer pending desired state.
* Added frontend-aware no-op convergence so source/hash or internal identity metadata changes do not force WooCommerce CRUD saves, page-cache invalidation, or warmup when the actual storefront state is already correct.
* Delta variation bursts now defer variable-parent recalculation into a persistent coalescing queue; each touched parent is finalized once after immediately-due webhook pressure drains.
* Parent finalization now has bounded retry backoff, avoiding tight runner loops when WooCommerce or a third-party hook throws temporarily.
* Product image synchronization is truly asynchronous in non-blocking mode: product sync only persists desired image work and never waits for remote image download/media import.
* Added adaptive webhook batch sizing (bounded 1-10) based on immediately-due queue pressure and available runner budget.
* Added queue-pressure protection so due desired-state webhook work takes priority over image side effects and cache warmup; delayed retries do not unnecessarily starve lower-priority queues.
* Exact-product cache warmup now has a 15-second debounce by default, so rapid successive updates of one product converge to one final anonymous same-origin warmup.

= 10.33.16.3 =
* Added parent-product Smart Diff with deterministic source hashes and local drift verification; converged products now skip WooCommerce CRUD saves entirely.
* Existing-product sync no longer performs the old preliminary WC_Product::save() just to mark sync incomplete; crash-safety is preserved with direct incomplete metadata and one final CRUD save only when storefront data changed.
* Added independent image source hashes so unchanged product image sets bypass the image pipeline while missing featured images still trigger recovery.
* Strengthened variation Fast Path: unchanged variations repair only missing identity metadata and avoid redundant map writes/CRUD saves.
* Simple-product storefront variants now use the same hash-based no-op Fast Path.
* Authoritative variation snapshots no longer rewrite identical parent attributes on every page.
* Variable-parent recalculation is coalesced across multi-page variant snapshots and runs once on the final page when any variation/type/attribute/deletion actually changed.
* Intermediate authoritative variant pages suppress product page-cache purge/warmup; the converged final page performs one targeted purge and exact-product warmup.
* Avoided repeated product-type writes and simple-variant metadata cleanup when the parent is already in the required WooCommerce type.

= 10.33.16.2 =
* Added deferred exact-product cache warmup after successful targeted cache invalidation.
* Only the current product permalink is warmed; category/tag archives, Shop, Home, and old permalinks are never added to the warmup queue.
* Warmup runs through the existing Real Cron/Self Runner engine instead of blocking product synchronization or shutdown; queueing also wakes the Self Runner once when available.
* Added deduplicated persistent warmup queue, bounded batch/time budget, retry backoff, same-origin URL enforcement, and Site Health telemetry.
* Works with the targeted cache integrations already supported by Mobo Core: WP Rocket, LiteSpeed Cache, W3 Total Cache, and WP Super Cache.

= 10.33.16.1 =
* Fixed installation/migration fatal caused by calling Self Runner before WordPress rewrite initialization.
* Deferred Stage 7 auto-resume kick until init.
* Reduced the sync-event entity lookup index width for MySQL/MariaDB installations with a 1000-byte key limit.

= 10.33.16 =
* Stage 7 now runs as an automatic convergence loop under Cron/Self Runner; no repeated manual clicking is required.
* A full Stage 7 pass that migrates references or deletes attachments automatically schedules another pass.
* Stage 7 is considered finished only after a complete pass makes zero new progress; remaining Safety-Blocked items are retained and reported.
* Upgrading from 10.33.15 preserves existing image-refresh progress and automatically resumes Stage 7 when an installation was falsely marked complete.
* Fixed duplicate Stage 7 failed-counter increment and corrected workflow/UI state so the full cycle is not shown as complete while Stage 7 still has automatic work.


= 10.33.15 =
* Stage 7 no longer stops the entire Image Refresh automation when one legacy attachment cannot be deleted safely.
* Safety-blocked attachments and real operational errors are tracked separately while the cursor continues across the remaining attachments.
* A completed Stage 7 is authoritative for the current refresh cycle, preventing blocked legacy attachments from causing an immediate Stage 6/7 retry loop.
* Final metadata/options/content reference checks now structurally verify SQL candidates, reducing false blocks from unrelated generic JSON IDs.
* Stage 7 issue rows now include the detected reference location when available.
* Upgrades from 10.33.14 automatically resume automation only when it had been stopped by the old delete-old-failed Stage 7 behavior.

= 10.33.14 =
* Image Refresh live status now has a direct authenticated rescue path when Self Runner loopback dispatches stop producing real worker runs.
* Active Image Refresh no longer sits for minutes on "active without progress" while its admin page is open; a stale idle automation batch is executed directly under the existing workflow lock.
* Stage 7 reference migration/delete slices use a shorter internal time budget so the real cron runner can make more than one bounded pass within a normal runner budget.
* Waiting states such as retry, active processor, delete-old setting, and orphan approval are never force-run by the rescue path.


= 10.33.13 =
* Made «حذف پیوست قدیمی بعد از جایگزینی امن» a persistent administrator preference instead of a one-time Stage 7 approval.
* Starting, pausing, error handling, queue verification invalidation, workflow reset, and cycle completion no longer switch the delete-old setting off.
* Stage 7 now proceeds automatically whenever the persistent delete-old setting is enabled; no repeated approval button is required.
* The delete-old setting remains editable while automation is active, and saving it can immediately kick the Self Runner when Stage 7 is waiting.
* Upgrade from 10.33.12 preserves the current delete-old setting and removes only obsolete one-time approval state.

= 10.33.12 =
* Added an authenticated Mobo wallet balance check after every successful automatic Mobo wallet purchase.
* Added a one-shot low-balance SMS alert with its own threshold, recipients, and template through Persian WooCommerce SMS.
* Added wallet placeholders for balance, threshold, site name, and site URL while retaining order shortcodes for the triggering purchase.
* Added dashboard state for last balance, last check time, alert-sent state, and last wallet/SMS error.
* Added an explicit dashboard button to re-arm the wallet reminder; topping up the Mobo wallet never re-arms notifications automatically.
* Wallet/API/SMS failures are best-effort diagnostics and never convert an already successful Mobo order into a failed WooCommerce order.

= 10.33.11 =
* Made replaced-old-attachment Stage 6 bounded and progress-aware on large WordPress databases.
* Fixed Stage 6/7 cursor advancement so interrupted batches cannot skip unprocessed attachments.
* Moved the expensive global reference audit out of Stage 6; Stage 7 migrates references first and performs one final authoritative audit before deletion.
* Limited Stage 7 to small batches and replaced broad numeric post-content scans with structured attachment reference tokens.
* Upgrade from 10.33.10 resets only Stage 6/7 verification state and destructive approval; existing WebP files and completed replacements are preserved.

= 10.33.10 =
* Image reference migration now decodes and rewrites Elementor/page-builder JSON structurally instead of relying on regex-only replacement.
* PHP-serialized arrays, public object properties, nested serialized values, gallery/media containers, and JSON image IDs are recursively migrated and reserialized with valid length markers.
* Generic JSON `id` fields are changed only inside locally verified image/media structures, preventing one old image URL from authorizing unrelated IDs elsewhere in a large builder document.
* Metadata JSON strings are re-slashed before WordPress metadata updates so encoded builder data is stored safely.
* Upgrade from 10.33.9 preserves downloaded WebP files and completed replacements, but reopens cleanup Stages 6/7 and revokes destructive approval so retained legacy images are retried with structured migration.

= 10.33.9 =
* Replaced-image cleanup can now migrate safe references from legacy JPG/JPEG/PNG attachments to their verified WebP replacements before deletion.
* Reference migration covers post content, registered image URLs/subsizes, product thumbnails and galleries, post/term/user metadata, and site icon/theme/widget options.
* Stage 6 remains read-only and now reports migration candidates; Stage 7 performs reference migration, re-audits all references, and deletes the legacy attachment only when no reference remains.
* Unknown or unserializable references remain protected and block deletion instead of being changed blindly.
* Upgrade from 10.33.8 preserves downloaded WebP files and queue work, but reopens Stages 6/7 and revokes destructive approval so existing retained JPEGs are re-audited safely.

= 10.33.8 =
* Legacy image refresh now discovers old Mobo JPG/JPEG/PNG attachments even when very old imports do not contain attachment-level Mobo image markers.
* Product-linked legacy images can recover their current image identity from the local Mobo image queue using GUID, exact source basename, or an unambiguous product image position before the WebP replacement is downloaded.
* Detached registered legacy images can be matched by a unique exact basename to an existing valid WebP replacement and are then included in the existing safe old-attachment deletion audit.
* Unrelated site JPG/PNG media remains ignored; scan mode is read-only and destructive deletion still requires the existing explicit administrator approval stage.
* Upgrading from an older version invalidates completed image-refresh verification state so the new discovery pass runs instead of reusing stale scan results.

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
