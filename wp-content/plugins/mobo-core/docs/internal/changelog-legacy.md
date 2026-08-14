# Legacy Mobo Core Changelog

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
