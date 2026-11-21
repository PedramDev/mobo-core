=== Mobo Core ===
Contributors: pedramdev
Tags: woocommerce, iran, product sync, provisioned-source, order automation
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Requires Plugins: woocommerce, persian-woocommerce
WC requires at least: 8.2
WC tested up to: 10.9
Stable tag: 10.34.77
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WooCommerce to MoboCore for product sync, webhook queues, shipping mapping, checkout validation, and optional order automation.

== Description ==

Mobo Core is a WooCommerce integration plugin built for stores operating in Iran and using the specific `the provisioned Source` workflow as their Mobo/provisioned-source product and order source. This plugin is not presented as the official plugin of the provisioned Source unless such authorization is explicitly stated by the service owner.

The plugin connects WooCommerce to the MoboCore service for product synchronization, webhook processing, shipping method mapping, checkout validation, automatic order submission, and operational health checks.

Required plugins: WooCommerce and Persian WooCommerce (`persian-woocommerce`). Mobo Core cannot be activated or bootstrapped without both dependencies.

Main features:

* Step-based product, variation, category, price, and image synchronization.
* Targeted WooCommerce object-cache and page-cache invalidation for updated Mobo products, including LiteSpeed Cache and WP Rocket integrations without Purge All.
* Queue-based webhook processing to avoid timeout in WordPress requests.
* Complete Mobo shipping-method details, WooCommerce mapping, and optional shipping-only class/API-price context for Mobo products.
* WooCommerce shipping mappings are used only for eligible all-Mobo, single-Connection order submission; mixed and multi-Connection orders fail closed.
* Optional automatic order submission for `processing` orders that are entirely Mobo-owned by one Connection; no partial Mobo submission is attempted for mixed orders.
* Central Location Resolver for order country/state/city values, with optional local city-cache compatibility.
* Image refresh workflow for legacy images after a full Repair run.
* Always-on operational health reporting for cron, queue, PHP/image capabilities, memory, disk, and debug status.
* Optional order SMS notifications through the Persian WooCommerce SMS plugin.

This plugin requires an active MoboCore account/license for the external synchronization and order automation features. You can buy or manage access at:

the provisioned Backend

Sales and activation contact:

* Phone: +989124508218
* Telegram: https://t.me/yazdan_ghadiri
* WhatsApp: https://wa.me/989124508218
* Tel link: tel:+989124508218

Technical support contact:

* Phone: +989367362228
* Telegram: https://t.me/Codeya

== External services ==

This plugin is designed for Iranian WooCommerce stores and a specific external Mobo/provisioned-source source: `the provisioned Source`.

The plugin may connect to these external services depending on administrator settings:

1. MoboCore service at `the provisioned Backend`

Used for license/account access, token-based connection, product synchronization orchestration, webhook processing, queue status, repair/sync workflows, health reporting, and order automation support.

2. Mobo/provisioned-source source at `the provisioned Source`

Used when checkout validation, cart checking, shipping method retrieval, or automatic order submission is enabled. This is the specific source this plugin is built for.

The plugin may send or receive the following data depending on enabled settings:

* Site domain and license/token information.
* Product, variation, category, price, stock, and image synchronization data.
* Webhook payload references and processing status.
* WooCommerce order data needed for Mobo order submission, including customer name, phone, shipping address, selected shipping method, Mobo product/variation identifiers, and order item quantities.
* Technical health data such as queue counts, cron state, PHP memory, disk space, and debug status.

After the site administrator enters a Token, Mobo Core can communicate with the central service for licensing, synchronization, webhook processing, and always-on operational health reporting. Optional customer-facing workflows such as checkout validation, automatic order submission, central Location Resolution, and shipping mapping remain controlled separately. Legacy image refresh no longer requires a redundant enable switch: the safe workflow becomes active when its automatic cycle is started, while destructive old-attachment deletion remains separately guarded and opt-in.

Service website:

the provisioned Backend

Terms of Service:

the provisioned Backendterms

Privacy Policy:

the provisioned Backendprivacy

== Installation ==

1. Upload the `mobo-core` folder to `/wp-content/plugins/`, or install the plugin through the WordPress plugins screen.
2. Install and activate both WooCommerce and Persian WooCommerce (`persian-woocommerce`).
3. Activate Mobo Core through the Plugins screen in WordPress.
4. Go to **Mobo > خرید و فعال سازی** to buy or manage your MoboCore license.
5. Go to **Mobo > اتصال** and enter the Token and Webhook Security Code from MoboCore.
6. Before enabling automatic order submission, configure the Mobo login/sender fields and map every active WooCommerce shipping method to a current Mobo shipping ID. Order addresses are resolved centrally from the native WooCommerce country/state/city values.
7. If upgrading from old versions such as version 7, run one full Repair from the dashboard before using image refresh.

== Frequently Asked Questions ==

= Is this plugin for all countries? =

No. This plugin is intended for WooCommerce stores operating in Iran and using the specific `the provisioned Source` source/workflow.

= Why does Mobo Core refuse to activate? =

Mobo Core requires both WooCommerce and Persian WooCommerce (`persian-woocommerce`). Install and activate both plugins first. On older WordPress versions, Mobo Core shows an activation error; on newer versions, WordPress enforces the `Requires Plugins` header.

= Does this plugin work without MoboCore? =

The admin screens can be opened, but synchronization, license status, webhook processing, health reporting, checkout validation, and Mobo order automation require an active MoboCore account and token.

= Does this plugin connect to the provisioned Source? =

Yes, when checkout validation, cart checking, shipping method retrieval, or automatic order submission is enabled. The plugin is built for that specific source.

= Does it create WooCommerce shipping methods? =

Yes, after the store manager explicitly runs the one-click shipping installer. Mobo Core then creates or repairs the Mobo product shipping class, Iran-capable zone configuration, one managed WooCommerce method instance per active Mobo shipping method, rule/static/free rate calculation, order mappings, and the shipping-only Mobo price context. Unrelated WooCommerce zones and methods are not deleted. Operational methods that need a previous invoice, warehouse hold, or in-person handling remain disabled until the manager reviews them.

= Does the Mobo API price replace the storefront product price? =

No. When the shipping-package option is enabled, `mobo_api_price` is used only in a cloned package passed to WooCommerce shipping methods. Catalog, cart, checkout, discount, payment, and order item prices remain unchanged.

= Does it send SMS directly? =

No. Mobo Core delegates SMS transport to the Persian WooCommerce SMS plugin. Order-SMS rules are store-wide and run when Checkout creates/processes the order, not after payment success. Wallet low-balance alerts are Connection-scoped, one-shot, and evaluated only after a durable successful automatic Mobo purchase when the active Provider supports wallet balance. Ambiguous remote/commit outcomes are fenced against automatic replay; gateway acceptance does not prove final handset delivery.

= Is Repair required after upgrading from version 7? =

Yes. Legacy installations should run one full Repair so product maps, image queues, and synchronization state match the current structure.

== Screenshots ==

1. Mobo Core dashboard and sync status.
2. Purchase and activation screen.
3. Connection and license information.
4. WooCommerce to Mobo shipping method mapping.
5. Queue, cron, and image refresh settings.


= Does product synchronization clear page caches? =

Mobo Core always clears WooCommerce product transients, WordPress post/object caches, and the changed product URL. The site administrator can configure deferred archive cache invalidation for product-category/tag archives, Shop, and Home from the Product settings tab. New installations default to a 15-minute batching window; archive invalidation can still be disabled explicitly. LiteSpeed Cache, WP Rocket, W3 Total Cache, and WP Super Cache are handled through their targeted APIs when available; trusted integrations can only decline warmup through the warmup-enabled filter. Mobo Core normally uses targeted invalidation and does not call wp_cache_flush(), rocket_clean_domain(), or litespeed_purge_all. The one explicit exception in UX52 is WP Super Cache archive overflow (>5000 distinct product IDs in a deferred batch), where wp_cache_clear_cache() is used to avoid silently leaving omitted archive entries stale. Following successful targeted page-cache purge, the currently changed product permalink is eligible for an anonymous GET through the real Mobo cron: UX53 defaults to selective admission (COLD products are not prewarmed), and the previous all-products policy remains available. Category, tag, Shop, Home, and old permalinks are not preloaded by this warmup queue.

== Changelog ==

= 10.34.30 (UX67 — cumulative UX66+UX67 final-source audit) =
* Checkout specialized shared-cart HTTP checks fresh durable Connection owner and complete route/credential fingerprint before Login and every outbound cart request; changed/disabled/reprovisioned owner fails closed without adopting Default.
* Portal-origin external validation uses fresh owner Token/X-SEC and rechecks the route after request signing. Portal RSA signer uses a freshly read durable Token instead of an old request-local Token.
* Targeted redaction of raw transport/exception text in checkout/admin login, cache warmup/purge, maintenance/storage, Webhook, Health/cPanel, image/media/image-refresh, shipping diagnostics, SMS gateway, upgrade, reprice and supporting workers; stable bounded codes retained. Ambiguous Wallet/SMS is not automatically replayed.
* No schema/DDL changes, Unicode conversion, Portal payload model rename or customer-data purge. Candidate only: real dual-site/Woo/HPOS/MySQL/Portal and 30K+30K runtime gates remain required.

= 10.34.28 (UX65 — cumulative UX64+UX65) =
* UX64: Shared global Woo Product Map lock around cross-Source owner SELECT/write/legacy seeding, binary-exact opaque GUIDs, guarded Product/Variant desired and applied revision state.
* UX65: Fresh credential and route fence per outbound general API GET/POST/redirect; admin/signed provisioning SQL preimage CAS and maintenance gating; redacted generic API Client transport and HTTP errors.
* Candidate source/mock audit. No new tables or schema changes; live two-process MySQL/WAMP/Portal, external Wallet idempotency and 60K soak remain open in BH/BI. Frozen UX51 Suite unchanged.

= 10.34.26 (UX63 — cumulative UX62+UX63) =
* UX62: Fresh binary-exact REST owner/Token authentication; strict WebHookId validation; fix failed webhook-test authentication diagnostic; Source Context reset; installation-scope Default revalidation; CAS signed provisioning and replay-nonce retirement.
* UX63: Redacted new/legacy public upgrade failures, durable hold after unverified filesystem rollback, verified-only hold retirement, per-owner barrier-state CAS and preservation of UX61 importer/order evidence during uninstall.
* No schema/Swagger changes. Source/mock audit only; live WAMP/Portal/HPOS integration remains pending in backlog BF/BG. Frozen UX51 Suite unchanged.


= 10.34.24 (UX61 — cumulative UX60+UX61) =
* UX60 revalidates Unicode fail-closed after atomic lock insertion, renews site-wide runner ownership before each additional Connection and before archive purge, keeps multi-Connection continuation visible when only one Source fits, and persists the warmup fairness cursor only at actual stage entry.
* UX61 imports legacy Parent/Order queues in bounded additive 100-row slices with verified checkpoints and source-byte CAS retirement, without overwriting newer table revisions. Invalid legacy identity/payload stops cutover without deletion.
* Map reads validate serialized payload/hash and latch failure to block destructive replacement; replace_map changes/deletes use expected-hash CAS and protect live claims. No table DDL or business-data conversion; real MySQL/two-process evidence remains pending (BD/BE).

= 10.34.22 (UX59 — cumulative UX58+UX59) =
* UX58 serializes image desired-state updates, prune rechecks and Woo gallery linkage under one explicit Connection+product lease without holding locks during downloads; a stale explicit-empty snapshot cannot erase reintroduced images.
* UX59 adds category API request-start generation, owner-scoped snapshot apply leases, durable committed watermark, and compare-and-clear retirement that cannot overwrite a concurrently changed synced term.
* Recheck shared-term owner and active marker during retirement/resurrection; manual mapping is preserved. All runtime 2-process/MySQL/Portal tests are recorded in BB/BC and remain pending, not claimed PASS.
* No schema change. Cumulative artifact includes UX58 internal checkpoint 10.34.21.

= 10.34.20 (UX57 — cumulative UX56+UX57) =
* Shipping snapshot GET-start and stored generation now use microseconds. A newer webhook beats an older in-flight GET even when both commit in one second and regardless of explicit changedAt on the old response.
* Wallet Alert rechecks uncached durable Connection and cached route agreement before post-order balance requests. Delivery and explicit re-arm share the existing owner-scoped SMS lease; blocked re-arm never clears a live replay fence.
* Remote wallet, SMS, address-mapping and shipping diagnostics persist stable error codes instead of raw upstream error messages. Missing Connection owner blocks Address Mapping / City Assets generation.
* No database schema change and no Wallet automatic replay. UX56 order route/quarantine corrections are included in this cumulative archive. Carry BA regression contracts to the final Suite; runtime WAMP/Portal proof remains pending.

= 10.34.19 (UX56) =
* Full-order pipeline audit: uncached Connection route fingerprint guards disabled/deleted/reprovisioned owners between remote stages and immediately before Wallet. Never reuse Default or cross-route remote checkout/payment.
* Stale running-order quarantine now requires the same purchase lease, fresh Woo CRUD read and verified uncertainty commit; a live request or durable success cannot be overwritten by another worker.
* Failed/aborted orders with leftover recovery queue rows are not automatically resubmitted. No schema change; real Portal/HPOS chaos gate is pending.

= 10.34.18 (UX55) =
* Full/Repair Sync snapshots now capture the watermark before HTTP GET; a later foreground webhook causes exact-product rebase before image/variation stages, with under-lock Simple and paged Variant checks.
* Reprice persists pending variable-parent recalculation with its cursor and safely retries it under Connection ownership and a shared product lock after crash.
* Added permanent UX55 audit contracts. Live WAMP/Portal multi-process proof still pending; UX51 Plugin Gate remains on its frozen artifact.

= 10.34.17 (UX54) =
* First full plugin pipeline audit tranche: fail-closed durable Webhook owner validation for both table and file paths, fresh enabled-owner probe at process time, non-destructive owner-unavailable deferral, corrected uninstall DB scope, and bounded REST/Cron exception diagnostics. No schema change. Pending real WordPress/MySQL/Portal tests are recorded in docs/internal/full-plugin-pipeline-audit.md and admin-ux-test-backlog.md.

= 10.34.16 (UX53) =
* Hardened large-catalog warmup: bounded additive legacy import with a checkpoint and no destination-map replacement; 18K WARM admission reserve for 2K HOT rows within the 20K Connection cap.
* Use indexed existence probes in Cron/due checks rather than recounting queue payloads. Multi-Connection dispatch uses a durable separate warmup cursor, selecting one due Connection with at most one GET/stage per dispatch.
* Warm only canonical published parent products, without visitor cookies, and reject session/cart/action/private/non-HTML/Set-Cookie/bypass responses. Cache HIT requires explicit provider evidence; an ordinary HTTP 200 alone never proves cached output.
* Scoped to single-language/single-currency sites; no automatic 30K crawl or new database table. Integration and 30K/60K MySQL stress tests remain pending.

= 10.34.15 (UX52) =
* Cache warmup for large catalogs: selective HOT/WARM/COLD admission (default), indexed row-based per-Connection queue, stable parent-product deduplication, and CAS claim/commit fencing. COLD products are purged but not prewarmed automatically; previously warmed pages can regenerate on normal customer visits.
* An installation-wide bounded GET rate budget, HOT-first scheduling with WARM fairness, and no unbounded queue reads during Cron snapshot. Includes explicit capacity rejection and HIT/MISS/bypass evidence in Health.
* Deferred WP Super Cache archive purge records product-ID overflow instead of silently dropping coverage; the documented overflow fallback may purge its entire page cache.
* Operators can select legacy all-products warmup when necessary. No new DB table is required; existing work_queue indexes are reused. See docs/internal/cache-warmup-30k-policy.md.



= 10.34.14 =
* Finalized the Pricing admin UX/content audit: clearer policy hierarchy, safer dynamic-range editing, Reprice lifecycle states, and technical diagnostics moved out of the primary view.
* Restored the pricing contract that a per-Variation `mobo_additional_price` is the highest-priority valid pricing override; parent Product percentage overrides apply only when the Variation has no dedicated profit.
* Fixed decimal Dynamic percentage benefits (for example `12.5`) so they are no longer interpreted as `125`, and preserved decimal fixed benefits through the central calculator.
* Reprice generations now fingerprint calculator-affecting global settings. Running policy edits are blocked in Admin, and a stopped/running generation whose policy changes cannot be resumed across mixed formulas.
* Finalized the Product Exclusions admin audit: clarified that exclusions stop source-driven mutations without removing Mobo identity/order behavior, documented queued-work and re-enable lifecycle, canonicalized/deduplicated saved paths, rejected unusable URL entries, and verified Connection-scoped persistence before reporting success.

= 10.34.13 =
* Added the central Source Registry and durable `source_key` on Connections. The built-in `provisioned-source` source now owns Backend URL resolution; stored `api_base_url` remains only for migration/rollback compatibility and is not Connection identity.
* Added `mobo_connection_settings` and idempotently migrated Source-dependent Product Update, Pricing, Category, Filter, and related business options into the Default Connection without changing License architecture.
* The legacy single-source admin settings remain mirrored into Default Connection during the staged migration, but customer edits no longer change the API base URL. Multiple enabled Connections remain fail-closed until ownership/state isolation is complete.
* WebHookId is internal Connection routing state: the admin UI shows only binding status, cannot edit/inject the raw identifier, and first-time binding is allowed only when X-SEC uniquely identifies one enabled unbound Connection.

= 10.34.12 =
* Added the first fail-closed Multi-Connection foundation: durable `mobo_connections` storage, automatic adoption of existing installs as one Default Connection, and per-request connection context.
* Portal-to-WordPress requests resolve Connections through `X-Mobo-Webhook-Id`; an unbound Connection adopts a new WebHookId only when the supplied `X-SEC` uniquely identifies that Connection.
* Durable webhook events now retain `connection_id`, and asynchronous payload processing restores the owning Connection before Portal API, image, health, signing, and remote-update calls.
* Existing single-connection URL/Token/X-SEC options remain mirrored for backward-compatible UI and rollback. Multiple enabled Connections intentionally fail closed until product/category/repair/cleanup ownership is connection-scoped in the next migration stage.

= 10.34.11 =
* Fixed the terminal completion handoff so a clean Product/Variation Map cannot overwrite an explicit `mobo_sync_incomplete=1` marker with a contradictory aggregate-complete Custom Storage state before the final verified 1→0 mutation.
* Product and Variation Storage Bridge snapshots now remain fail-closed while the explicit completion marker is still incomplete, then converge normally when the verified marker mutation succeeds.
* No schema, API, Provider, Portal, or migration-fence contract changes.

= 10.34.10 =
* Fixed terminal Product Map mirroring so Operational metadata is read from the current Custom-authoritative WordPress meta view after the durable operational fence instead of from physically retired Legacy postmeta.
* Product and Variation completion-marker presence/value, revisions, and source hashes are therefore preserved across Product Map mirrors after terminal retirement.
* Pre-terminal behavior is unchanged: before the operational fence, Storage Bridge continues to snapshot authoritative Legacy postmeta exactly as before.

= 10.34.09 =
* Fixed WooCommerce terminal metadata namespace collision: generic Entity Meta synthetic IDs are now strictly bounded to the 4e18..5e18 range.
* Specialized Operational (5e18..) and Identity (6e18..) synthetic rows can no longer be stripped by the priority-20 Entity Meta read bridge.
* WC_Product::get_meta()/save_meta_data() therefore retains terminal typed metadata after physical postmeta retirement without relaxing Custom-only authority.

= 10.34.08 =
* Fixed authoritative Legacy identity snapshots after the terminal identity fence: Storage Bridge now scopes a bypass for both Custom-primary reads and terminal identity-read interception.
* Parent-first identity materialization can therefore preserve legacy Portal Product ID and identity presence while still keeping normal runtime reads Custom-only.
* The bypass is depth-counted and restored in finally blocks; it is migration-snapshot-only and does not relax terminal mutation or runtime ownership rules.

= 10.34.07 =
* Identity proof now materializes and validates a Variation's parent Product State before requiring full Variation custom-storage parity.
* This fixes post-ID ordering where a live Variation can be proved before its higher-ID parent Product: exact parent GUID/Map/legacy evidence is resolved first, then the normal Product bridge creates the parent state.
* Parent disagreement, malformed/missing durable identity, Product bridge failure, or post-write parity mismatch still fails closed; no GUID is generated and no parity exception is introduced.

= 10.34.06 =
* Fixed terminal Entity Meta migration readiness on Storage Schema v7: the retirement worker now trusts the current validated Storage Schema instead of requiring the obsolete exact schema-generation literal `6`.
* This removes the post-Identity `pending` / null-residue deadlock that could otherwise loop until the terminal migration timeout even after Operational and Identity retirement were complete.
* Entity Meta still fails closed unless the current Storage Schema validates successfully and the dedicated entity-meta table resolves normally.

= 10.34.05 =
* Trashed parent-only Variation residue can now be proven against either the exact Parent Product State row or a singular exact Parent Product Map binding, removing a false dependency on Product State backfill ordering.
* All available parent Product GUID and Portal Product ID evidence must collapse to one identity; malformed, duplicate or conflicting State/Map/postmeta evidence still blocks cutover.
* No Variant GUID is generated and no legacy row is deleted before the terminal fence.

= 10.34.04 =
* Identity proof recognizes trashed Woo variations that have no Variation identity of their own and only retain an exact stale copy of parent Product identity.
* This bypass is fail-closed: any Variant GUID/Portal Variant ID, Variant State row, Variation Product Map binding, malformed value, or disagreement with Parent Product State still blocks cutover.
* No GUID is generated and no legacy row is deleted before the terminal fence; recognized residue is removed only by the existing bounded physical cleanup.

= 10.34.03 =
* Terminal identity proof can conservatively recover a missing legacy Product/Variation GUID from a singular exact Product Map binding when Custom State is absent.
* Variation recovery requires parent identity agreement across Woo parent, Product Map, Custom State and surviving legacy evidence.
* Multiple reverse map bindings, malformed GUIDs and identity disagreements remain fail-closed; no GUID is invented.

= 10.34.02 =
* Identity-postmeta retirement can now recover a missing legacy Product/Variation GUID from the exact Custom Storage row bound to the same Woo object.
* Recovery is fail-closed: legacy/custom disagreement, parent mismatch, malformed identity, missing canonical identity, or storage read errors still block cutover.
* No synthetic GUIDs are generated and no conflicting identity is overwritten.

= 10.34.01 =
* Reconcile legacy operational Product/Variation metadata that has no Product Map row before terminal cutover.
* Preserve recoverable trashed Mobo objects by safely rebuilding an unambiguous map row; prune only provably unowned auto-draft/trash operational residue.
* Keep live objects with missing, duplicate or conflicting identity fail-closed instead of discarding state.
* Continue the exact proof from newly synthesized map rows so Custom Storage receives the full legacy operational snapshot before physical cleanup.

= 10.34.00 =
* Shared-host migration hardening: normal WordPress requests now advance upgrades under an option-backed single-request mutex; no WP-CLI, SSH, shell cron, advisory DB locks or information_schema access is required.
* Reduced Product/Category-map, terminal metadata, large-runtime recovery and cleanup batch sizes so upgrades remain resumable under conservative shared-host PHP execution limits.
* Schema inspection continues to use SHOW COLUMNS / SHOW INDEX only on Mobo-owned tables; failed CREATE/ALTER/INDEX work remains fail-closed and retryable instead of stamping the DB version.

= 10.33.99 =
* Upgrades Custom Storage to Schema v7 with complete index postcondition validation and atomic same-name index repair.
* Adds `mobo_entity_meta.object_order (woo_object_id,sort_order,id)` for all-meta reads and `mobo_image_queue.product_position (product_id,position_index,id)` for deterministic product image ordering.
* Retires exact left-prefix duplicate `status_retry` indexes from image queue, image refresh queue, and sync event store after verifying the covering `status_retry_id` index.
* Aligns Seen-Variant migration verification with the existing scope/id index instead of adding a high-churn GUID sort index.

= 10.33.98 =
* Makes the 10.33.94 operational-retirement bootstrap path safe at plugins_loaded by removing wc_get_product()/WC_Product dependencies from legacy Product-state mirroring. Simple-product Variant identity is mirrored directly from explicit legacy snapshot fields into Custom Storage.
* Keeps Storage Schema v6 and all retirement scopes unchanged; this release is a migration-bootstrap/runtime safety fix on top of 10.33.97.

= 10.33.97 =
* Completes the terminal metadata compatibility surface after physical Product/Variation retirement: false-y values remain present for metadata_exists(), absent typed identity remains truly absent, and unrelated Woo products no longer receive a synthetic mobo_sync_incomplete fail-safe.
* Adds a unified WordPress all-meta view so get_post_meta($id) and get_post_custom() include terminal operational, identity and generic Mobo metadata while ordinary native metadata stays in wp_postmeta.
* Adds a specialized WooCommerce raw/by-meta-ID bridge for typed operational and identity metadata, keeping WC_Product get_meta/update_meta_data/delete_meta_data coherent with Custom Storage after physical cleanup.
* Makes post-terminal generic late-write/delete cleanup durable: failed mirror/delete work sets a persistent repair marker, init performs bounded retry/drain, and the retirement finalizer blocks completion while repair remains pending.
* Hardens explicit-key scoping and the final stale-mirror convergence proof without changing Storage Schema v6 or the 10.33.96 entity-meta migration target. 64-bit PHP is now explicitly enforced because stable WooCommerce terminal synthetic meta IDs require 64-bit integers.

= 10.33.96 =
* Adds Storage Schema v6 and the plugin-owned mobo_entity_meta table for the remaining Mobo-owned Product/Variation metadata while preserving WordPress multi-row ordering and exact source meta_id parity during migration.
* Physically retires remaining Product/Variation _mobo_*/mobo_* metadata plus the legacy published_at key for identified Mobo objects. Typed operational and identity families remain in their dedicated Custom tables; WooCommerce-native metadata, attachments, orders and order items remain outside this scope.
* Uses exact copy/parity proof, a durable transition marker, a 120-second in-flight-request grace, post-commit late-write mirroring, post-grace stale-mirror drain, re-mirror-before-delete cleanup, and final SQL zero-residue proof before the permanent fence and DB-version stamp.
* Bridges WooCommerce raw metadata reads and synthetic by-meta-ID update/delete operations so WC_Product get_meta/update_meta_data/delete_meta_data continue to work after physical wp_postmeta cleanup.
* Converts remaining generic-Mobo direct lookup paths, including image/reprice/repair flows and Repair generation-marker scanning, to terminal Custom Storage while retaining explicit pre-fence upgrade branches.
* Upgrades retirement health to Finalizer gate v4: operational, identity and generic entity-meta fences must all be complete with exact zero Product/Variation residue.

= 10.33.95 =
* Physically retires Mobo Product/Variation identity postmeta after Storage Schema v5 backfill and exact parity proof. Product GUID, Variant GUID aliases, Portal Product ID aliases, and Portal Variant ID aliases become Custom-table authoritative.
* Adds explicit Simple-product purchasable-Variant identity columns so Simple Products no longer need Variant GUID/Portal Variant ID identity in wp_postmeta.
* Publishes a separate irreversible identity fence, waits a 120-second in-flight-request grace, cleans legacy identity rows in bounded batches, and blocks DB-version stamping until Product/Variation identity residue is exactly zero.
* Keeps Product GUID ownership non-rebindable, allows a narrowly scoped same-Woo-Variation/same-parent Variant GUID rebind when the new GUID is unowned, and fails closed on collisions, malformed identity, alias conflicts, missing parent authority, or Custom-write failure.
* Converts Repair, Sync, Concurrency, Missing Image Recovery, Recategorize, Product Ledger and other identity lookups to Custom Storage after the terminal fence while retaining recoverable Trash identity.
* Upgrades the retirement finalizer to gate v3: release health is terminal only when both operational and identity retirement fences are complete with zero physical Product/Variation residue. Attachments, order-item identity snapshots, forensic markers, and WooCommerce/native metadata remain outside this scope.

= 10.33.94 =
* Physically retires seven high-churn Mobo operational metadata keys from wp_postmeta for WooCommerce Products and Variations after strict Custom Storage backfill and exact verification.
* Publishes an irreversible terminal Custom-only fence before cleanup, keeps a redundant fail-closed fence marker, and blocks DB-version stamping until Product/Variation residue is exactly zero.
* Intercepts managed add/update/delete metadata operations before WordPress can recreate retired rows; terminal mutations use Custom-state CAS and fail closed on missing rows, invalid values, conflicts, or revision regression.
* Preserves attachment-level mobo_sync_incomplete and all WooCommerce/native, identity, media, pricing, and other out-of-scope metadata.
* Adds bounded/resumable physical cleanup, a 120-second production grace for in-flight pre-fence requests, and terminal retirement health evidence.

= 10.33.93 =
* Makes the 10.33.92 large-runtime migration a strict, resumable cutover: the plugin DB version cannot advance while any retired fixed or prefixed heavy-state option remains.
* Prevents post-cutover runtime fallback to retired heavy wp_options. Before the cutover stamp, legacy reads/writes remain available only as an upgrade safety path; after 10.33.93, missing custom tables fail closed instead of silently recreating legacy state.
* Fixes authoritative variation finalization after Seen-Variant migration: finalization now loads the dedicated mobo_seen_variants set instead of reading the deleted mobo_seen_variants_* option.
* Covers Cron scheduler preflight, Sync Settings Guard, Product Concurrency, City Assets, Cache Warm-up, Parent Finalize, Archive Purge, Product Sync, Checkout, Address Mapping, and Health snapshot consumers with the same strict storage policy.
* Hardens migration verification itself: State/Queue read-back fails closed on database errors, Checkout debug-event migration verifies the bounded visible window, and legacy rows are retired only after verified destination persistence.
* Keeps Storage Schema v4 unchanged. Small settings/version markers and the separately controlled Product/Variation operational-postmeta retirement remain independent storage phases.

= 10.33.92 =
* Moves high-churn/large runtime state out of wp_options into plugin-owned durable tables: row-based work queues, large state snapshots/checkpoints, authoritative seen-Variant sets, and bounded event history.
* Migrates Cache Warm-up, Parent Finalize, Order Submission/Recovery, Cache Archive Purge state, manual Product Sync checkpoint, Address Mapping, Health last-good snapshots, Checkout debug history, and legacy seen-Variant generations with verified read-back before deleting legacy options.
* Adds schema v4 tables mobo_state, mobo_work_queue, mobo_seen_variants, and mobo_events with indexed queue/dedupe access suitable for large catalogs.
* Preserves fail-safe legacy option fallback only when the new schema is genuinely unavailable; successful schema creation/migration keeps normal runtime reads and writes off the retired heavy options.
* Keeps small settings, version markers, feature flags, locks, and bounded last-result records in wp_options where WordPress option storage remains the correct abstraction.

= 10.33.91 =
* Hardens Repair liveness: category semantic defects use a bounded retry budget and degrade without blocking product Repair forever; stalled Product/Variation cursors fall back safely; repeated product-lock contention is bounded and quarantined diagnostically.
* Adds non-destructive authoritative retirement for source categories absent from a complete category snapshot while preserving customer taxonomy/manual mappings and supporting in-place resurrection. Category retirement fails closed on mapping-read errors and retired parents cannot satisfy new child relationships.
* Splits webhook queue metrics into exact pending, processing, due, actionable failed, and historical/superseded failed counts; Retry Failed re-arms only the latest unresolved failure for each identity. Queue read failures are reported as unavailable instead of synthetic zero counts.
* Adds real-Cron interval measurement with an inclusive ±5 second on-time window; admin/manual Cron attempts can no longer forge the real scheduler hit timestamp.
* Separates verified current Sync Health gaps from Health-only uncertainty so stale raw history is not reported as proven operational breakage or exposed as a Clean-Gate mutation target. Product Map, ownership and Health-history DB read failures now fail closed as unavailable.
* Bounds main Product Repair lock contention and persists explicit incomplete/Product Map/Sync Health evidence when an object must be quarantined so one permanently locked object cannot block the entire site.
* Prevents Variant Repair from advancing its page/cursor when payload handling reports waitingForProduct; retry budgets clear only after durable progress.
* Makes Repair Integrity phase reads fail closed: duplicate, trash, signature, post-sync, legacy/map candidate, live-variation and shipping cleanup DB read failures retry without being mistaken for empty/completed phases or allowing cleanup mutation.

= 10.33.90 =
* Fix Real-Cron runner aggregation crash in storage reconciliation by supplying the required fourth merge_queue_counters() argument.
* No Portal change. Custom Storage retirement behavior remains unchanged.

= 10.33.89 =
* Graduates `mobo_sync_incomplete` into controlled Custom-first suppression as the seventh operational postmeta key after a fresh exact-key readiness lease.
* Adds authority-aware commit-group guards so a later hash/completion write does not require retired sibling postmeta to reconverge after those siblings have already become Custom-authoritative.
* Product and Variation completion writes use bounded Custom CAS, preserve explicit metadata presence, recompute derived incomplete/status/dirty/revision/hash boundaries atomically, and fall back to Legacy immediately on drift, duplicate meta, identity mismatch, parent mismatch, race, or Custom failure.
* Advances postmeta suppression readiness to v4 and exposes the currently runtime-eligible authority set in one bounded evaluation.
* Removes Product Repair SQL dependence on retained `mobo_sync_incomplete=0`; Repair completion proof now resolves through the storage-mode-aware metadata API, so intentionally stale rollback postmeta cannot corrupt repair candidate selection.
* Direct REST/add/delete metadata compatibility paths remain Legacy-first; attachment `mobo_sync_incomplete` is outside Product/Variation suppression.
* Rejects malformed present retained completion markers during sibling parity checks; only exact `0`/`1` values may satisfy the Legacy side of the commit group.

= 10.33.87 =
* Fixes the Custom Storage source-hash width contract: Product Sync source hashes are canonical 32-character MD5 hex values, and all Custom mirror/read/parity/repository layers now use that same format.
* Prevents valid `_mobo_product_source_hash` and `_mobo_variant_source_hash` values from being normalized to empty Custom state.
* Keeps commit-group source-hash suppression fail-closed with immediate Legacy fallback on mismatch, race, missing row, or Custom failure.
* `mobo_sync_incomplete`, REST mutations, add/delete metadata, and non-opted-in keys remain Legacy-authoritative.

= 10.33.86 =
* Graduated Product/Variation source-hash writes into controlled per-key suppression.
* Hash suppression requires an exact retained-Legacy/Custom revision, presence, and incomplete-state commit-group match before Custom-first CAS may skip postmeta.
* Any hash commit-group mismatch, CAS race, missing row, or Custom failure restores Legacy immediately and trips the request-local suppression breaker.
* `mobo_sync_incomplete`, REST mutations, add/delete metadata, and non-opted-in keys remain Legacy-authoritative.
* Per-key readiness moved to policy v3 so source hashes require fresh qualification before suppression.

= 10.33.85 =
* Adds controlled per-key operational-postmeta write suppression for the four monotonic Product/Variation revision watermarks.
* Suppression is opt-in through `mobo_core_storage_postmeta_write_suppression_keys` and requires Custom-Only plus a fresh per-key readiness lease.
* Custom state is committed first with bounded compare-and-swap protection; any Custom miss/race/failure restores Legacy `update_post_meta()` immediately and revokes suppression for the request.
* Product/Variation source hashes, `mobo_sync_incomplete`, REST metadata writes, add-postmeta, and delete-postmeta remain Legacy-authoritative.
* Postmeta suppression readiness policy v2 can sustain a previously qualified key using fresh successful suppression telemetry without mislabeling those writes as Legacy writes.

= 10.33.84 =
* Adds evidence-only per-key readiness telemetry for future operational-postmeta write suppression; no operational postmeta write is suppressed or deleted.
* Tracks Custom reads, Legacy read dependencies, and retained Legacy writes independently for each revision/hash/incomplete key with request deduplication and set-based runtime persistence.
* Adds a per-key 72-hour readiness gate requiring 250 fresh Custom reads, 50 exercised Legacy writes, recent evidence, zero Legacy read dependencies, and a valid global Legacy-retirement lease.
* Keeps `mobo_sync_incomplete` independent from revision/hash keys so stricter presence/REST compatibility can remain blocked without delaying safer keys.
* Exposes per-key eligibility in Site Health and the runner while leaving the existing Product Map no-op suppression option unchanged.

= 10.33.83 =
* Adds explicitly opted-in, retirement-gated suppression for redundant Product Map no-op writes emitted by the Product Sync commit boundary; default remains off.
* Only already-canonical Product Map rows whose identity, parent, hash, incomplete flag, and reverse-map cardinality already match may be skipped after a successful Custom mirror; changed/new mappings still write Legacy normally.
* If the Custom mirror fails, the same commit immediately writes the Product Map and disables suppression for the rest of the request.
* Advances the Legacy Retirement Gate to v2 with a 15-minute runtime lease and same-request read-dependency checks before any write may be suppressed.
* Adds suppression/fallback telemetry to Site Health; no existing Legacy data is deleted and rollback through retained identity postmeta remains available.

= 10.33.82 =
* Adds evidence-only Legacy retirement readiness telemetry while leaving all Product Map and operational postmeta writes enabled.
* Counts Custom-Only reads that still require Legacy compatibility, including exact-meta absence and guarded bypass scopes; any new dependency resets retirement confidence.
* Inventories retained Legacy Product Map/postmeta write volume separately so write cost can be measured without treating normal writes as readiness failures.
* Adds a 72-hour retirement gate requiring 2,000 fresh Custom-Only identity hits, 500 fresh state hits, recent traffic evidence, zero new Legacy read dependencies, zero primary fallbacks, and zero Custom write failures.
* Exposes Legacy dependency/write inventory and retirement readiness in Site Health; no storage mode or write behavior changes automatically.

= 10.33.81 =
* Treats fully dominated stale Product/Variation Custom Storage mirrors as successful idempotent no-ops instead of false repository failures.
* Keeps same-revision/different-state and incomparable concurrent snapshots fail-closed so real drift is still surfaced to reconciliation and promotion gates.
* Prevents benign stale delivery/replay from incrementing Custom write failure telemetry or revoking an otherwise healthy Custom-Primary/Custom-Only confidence window.

= 10.33.80 =
* Adds controlled Custom-Only cutover behind the independent short-lived readiness lease.
* Healthy Custom-Only identity/state reads no longer depend on retained Mobo identity postmeta; Woo object existence/type/parent guards remain.
* Any real Custom miss/invalid row or dual-write failure immediately revokes the request and degrades safely to guarded Custom-Primary/Legacy fallback.
* Custom-Only readiness policy advances to v2 so prior readiness evidence must be re-established after upgrade.


= 10.33.79 =
* Adds bounded, request-deduplicated successful Custom-Primary identity/state usage telemetry stored only in `mobo_runtime`.
* Adds a separate Custom-Only readiness gate requiring 1,000 identity hits, 250 exact-state hits, 24 clean hours, recent traffic evidence, zero new primary fallbacks, and zero new Custom write failures.
* Custom-Only readiness is evidence-only: this release never removes Legacy fallback and never changes the requested storage mode automatically.
* Adds Custom-Only readiness and primary-usage diagnostics to Site Health and evaluates the gate only as a low-priority runner stage after the existing promotion gate.

= 10.33.78 =
* Adds schema v3 metadata-presence fidelity so operational state preserves absent vs explicit zero/one semantics instead of collapsing them.
* Enables promotion-gated Custom-Primary reads for exact Product/Variation revision/hash/incomplete state with mandatory Legacy fallback and retained Woo identity guards.
* Pins operational metadata mutations to Legacy reads before WordPress write/no-op decisions; pending Metadata Commit Bridge objects also bypass Custom state reads.
* Keeps REST operational metadata Legacy-primary in this rollout so REST duplicate/delete preflight cannot be influenced by Custom mirrors.
* Present Custom values preserve `metadata_exists()` semantics, while exact Custom absence safely falls through to Legacy without poisoning primary fallback telemetry.
* Advances State Parity to the v3 evidence namespace and Promotion Gate to v5; older leases fail closed.
= 10.33.77 =
* Adds lossless Custom Storage state watermarks for Product/Variation operational-state fidelity while Legacy remains authoritative.
* Storage schema v2 preserves separate product/variant seen/applied revisions, source hashes, and exact sync-incomplete state.

= 10.33.76 =
* Adds bounded read-only parity for direct operational Product/Variation state reads that do not pass through Product Map identity lookup.
* Revision, source-hash and completion reads remain exact Legacy postmeta values; Custom Storage is compared only as a side-channel observer.
* Promotion Gate v3 requires a separate 100-comparison clean state-read window in addition to the existing identity parity window, and older promotion leases are invalidated fail-closed.
* State parity is capped at 32 entities per request, stores at most five mismatch samples, and is exposed through Site Health without wp_options telemetry writes.

= 10.33.75 =
* Adds a bounded operational-metadata commit bridge so direct Product/Variation identity, Portal ID, revision, source-hash and `mobo_sync_incomplete` postmeta writes also mirror to Custom Storage.
* Relevant changes dedupe by Woo object and flush at 100 unique entities or request shutdown; unrelated WordPress/WooCommerce metadata is rejected before the bridge class autoloads.
* Legacy writes and operational state reads remain authoritative. Custom-Primary stays limited to promotion-gated Product/Variation identity lookup with Legacy fallback.
* Custom-only remains capability-capped to Custom-Primary with Legacy fallback.

= 10.33.73 =
* Adds bounded Legacy-to-Custom Product/Variation reconciliation before promotion eligibility can start.
* Reconciliation refreshes operational drift from authoritative Product Map/postmeta, cleans Custom rows tied to deleted/trashed Woo objects, and reports active identity collisions as ambiguous instead of rewriting them destructively.
* Trash/delete/untrash lifecycle hooks keep Custom identity mirrors aligned without changing Legacy/Woo authority.
* Promotion Gate now requires the latest reconciliation checkpoint to be clean; custom-primary/custom-only remain capped to shadow-read and are never auto-enabled.

= 10.33.72 =
* Adds a strict evidence-only promotion gate for future custom-primary Product/Variation reads; runtime remains capped at shadow-read.
* Promotion requires gap-free backfill, exact active Legacy/Custom identity coverage, 500 clean shadow comparisons, six clean hours, fresh parity evidence, and no new dual-write failures.
* Historical mismatch/write counters are baselined when a clean window starts; any new mismatch or custom write failure resets the window instead of permanently poisoning future eligibility.
* Promotion evidence is stored in mobo_runtime and exposed through Site Health; this release never changes mobo_core_storage_mode automatically.

= 10.33.71 =
* Adds bounded read-only shadow parity for Product/Variation custom storage while Legacy remains authoritative.
* Shadow comparisons cover Product Map single lookups, status lookups, and bulk Variation priming without changing returned Woo IDs/status.
* Parity is capped per request, de-duplicated, aggregated once into mobo_runtime, and exposed through Site Health with mismatch samples/reasons.
* custom-primary/custom-only remain disabled and capability-capped to shadow-read.

= 10.33.70 =
* Adds bounded, resumable Product/Variation custom-storage backfill sourced from the indexed legacy Product Map instead of catalog-wide postmeta scans.
* Backfill runs only in dual-write-capable mode, at lowest real-runner priority, one batch per invocation, with a durable cursor in mobo_migrations.
* Live mirror failures stop cursor advancement for retry; stale/deleted map rows are skipped safely and live unmappable gaps remain visible in migration status.
* Adds custom-storage rollout/backfill observability to Site Health; runtime reads remain legacy-only.

= 10.33.69 =
* Adds capability-capped legacy-first dual write for Product/Variation operational state.
* Custom-storage mirror failures are observable but never fail a successful legacy sync.
* Read paths remain legacy-only; shadow/custom-primary/custom-only cannot become effective yet.

= 10.33.68 =
* Added phase-1 custom storage foundation with seven prefixed operational tables.
* Added independent storage schema version/error boundary; legacy runtime remains available if custom DDL fails.
* Added fail-closed rollout modes (legacy, dual-write, shadow-read, custom-primary, custom-only), defaulting to legacy.
* Added Product/Variant state repositories without wiring existing sync behavior to custom storage yet.

= 10.33.67 =
* Added a WooCommerce Products-list filter with three states: all products (default), Mobo products, and non-Mobo products.
* Mobo/non-Mobo filtering uses the shared durable product identity policy, including legacy/Portal identity aliases, instead of relying only on `product_guid`.
* The existing Products-list Mobo badge now uses the same identity policy so the visual marker and the filter cannot disagree.
* No schema migration, Repair, reset, or manual SQL is required.

= 10.33.66 =
* Fixed ProductUpdated handoff for a currently-Simple product whose source ProductUpdated payload is variation-capable and whose authoritative Variant lookup spans additional pages.
* Additional Variant pages now prove “not safely Simple”; ProductUpdated returns its normal terminal checkpoint while preserving `mobo_sync_incomplete=1`, allowing UpdateVariant to perform the authoritative Simple-to-Variable transition.
* Transport/schema failures in the Variant probe still defer/fail closed. Only the explicit pagination-ambiguity condition is treated as evidence that the product must continue through UpdateVariant.
* Retains the 10.33.65 WooCommerce wildcard/slug Sync Health fix and the one/no-selection Variant Simple completion fix.

= 10.33.65 =
* Fixed Sync Health variable-structure validation to use WooCommerce attribute slugs when available and to accept empty Variation attribute values as WooCommerce wildcard/“Any” semantics.
* Fixed ProductUpdated completion for a WC_Product_Simple that carries product attributes: Mobo now probes the authoritative Variant endpoint and completes the Simple product when the source proves one no-selection purchasable Variant (or authoritative zero-Variant state), instead of leaving `mobo_sync_incomplete=1` solely because product attributes exist.
* Variable candidates remain fail-closed/incomplete until UpdateVariant convergence when the Variant snapshot is ambiguous or contains selection attributes.
* Added postcondition-safe compatibility fallbacks for older WooCommerce API generations; no exact WooCommerce version pin is required by these fixes.

= 10.33.64 =
* Compare-price safety: auto-compare only creates a WooCommerce sale pair when source comparePrice is strictly greater than source price. Equal/lower comparePrice is treated as no-sale, preventing impossible sale >= regular states.
* Final compatibility-filter output is validated against WooCommerce's sale invariant and fails closed if a filter produces sale >= regular.
* Reprice now requests variable-parent price sync for every successfully processed variation, even when that child required no price mutation, so stale parent child-derived `_price` sets converge during a full Reprice pass.
* Variable-parent singleton-collapse protection from 10.33.63 remains unchanged.
* No schema migration, Full Repair, reset, or manual SQL is required.

= 10.33.63 =
* Woo variable-parent price safety: Repair and the shared singleton price-meta helper no longer classify variable-parent `_price` rows as duplicate singleton corruption.
* Variable-parent prices remain owned by WooCommerce's child-derived sync semantics (`WC_Product_Variable::sync()`), which intentionally stores one `_price` row per distinct visible variation price.
* Reprice behavior for purchasable simple products and variations is unchanged.
* No schema migration, Full Repair, reset, or manual SQL is required.

= 10.33.62 =
* Price metadata integrity: Reprice collapses duplicate `_price`, `_regular_price`, and `_sale_price` rows to the current pricing-policy result while holding the existing product lock.
* Repair price-meta cleanup no longer trusts a WooCommerce object whose current price may already be poisoned by duplicate meta; it derives the canonical value from stored Mobo API price + the current Mobo pricing policy for purchasable Mobo objects.
* Added a shared verified singleton helper for duplicate WooCommerce price metadata.
* No schema migration, Full Repair, reset, or manual SQL is required.

= 10.33.61 =
* Image prune safety: the durable stale-image cleanup queue now refuses final attachment deletion when a non-empty attached-file path is outside WordPress uploads or resolves outside uploads through traversal/symlink boundaries.
* Missing-file cleanup remains supported: stale attachment database rows can still be removed when their registered path is safely rooted under uploads but the physical file is already gone.
* No Reprice, pricing formula, webhook priority, queue schema, product identity, or Repair cursor behavior changed.

= 10.33.60 =
* Runtime-lock hygiene: each real Cron Runner slice safely scans Mobo runtime leases and compare-deletes only leases that are provably malformed or expired; live leases are preserved.
* Diagnostics: the runner result exposes bounded stale-lock recovery counts (`scanned`, `recovered`, `active`, `raced`) without lock tokens or payload secrets.
* Reprice liveness behavior from 10.33.59 is unchanged; this release only hardens cleanup of stale product/runtime lock residue after interrupted requests.

= 10.33.59 =
* Reprice liveness: a never-selected administrator Reprice/Recategorize generation now uses its durable `startedAt` as the starvation-age anchor, so sustained webhook pressure cannot keep `lastSelectedAt=0` queues deferred forever.
* Reprice admin rescue: the authenticated Pricing status poll advances one bounded Reprice item after eight seconds without a checkpoint, while retaining the existing queue worker lease and product-level concurrency locks.
* No pricing formula, product identity, webhook priority, batch checkpoint, or database schema behavior changed.

= 10.33.58 =
* Repair image cleanup: stale Mobo attachments are detached and durably queued; physical deletion and reference proof no longer run inside the Product/Repair manual_sync lock.
* GUID-first deletion policy: image_guid/img_guid are the destructive ownership identity, conflicting/invalid/missing identities fail closed, and mobo_source_url alone never authorizes deletion.
* Bounded cleanup: legacy and current prune debt is processed in two resumable primary-key proof passes by the Image worker instead of unbounded wildcard scans across wp_postmeta/wp_options/wp_posts.
* Concurrency: admin Repair fallback now claims the site-wide worker dispatcher; Product Sync lock contention is deferred for the invocation instead of busy-spinning and is reported separately from failures. Prune debt can still drain when remote Image Queue processing is disabled or circuit-open.
* Diagnostics: rejected Manual Cron dispatcher claims are persisted as the latest attempt instead of leaving stale results from an older plugin version.
* Progress: non-terminal Repair accounts for the current product and local product queue, so it cannot report 100% / 0 remaining before completion.

= 10.33.57 =
* Repair upgrade recovery: a legacy Repair checkpoint stopped by the known Simple→Variable durable-save failure is preserved but rewound to variant page/cursor zero for the current product, then allowed to continue under the new product-type policy.
* Cursor durability: `currentVariantCursor` advances only after the fetched authoritative Variant page is successfully applied, preventing a failed page from being skipped after retry or upgrade.
* Scope safety: unrelated Variant validation failures remain fail-closed and are not auto-retried by this migration.


= 10.33.56 =
* Image desired-state ordering is explicit and durable: positioned Portal payloads are validated and sorted by position before queueing, hashing, featured-image selection, and gallery reconciliation.
* Backward compatible with older Portal payloads that provide only array order.


= 10.33.55 =
* Fix: route Simple↔Variable product-type changes through WooCommerce CRUD instead of pre-writing the product_type taxonomy.
* Durability: verify raw taxonomy, WooCommerce factory type, and fresh product class before acknowledging a type transition.
* Safety: make the existing Variable→Simple variation-preservation filter run around the real WooCommerce type-change hook; Mobo-owned retirement remains quarantine/Trash based and manual Variations remain preserved.
* Policy: align the internal Desired-State topology document with current delta-vs-authoritative Variant semantics and Repair/lifecycle boundaries.
* Package: remove historical PHP backup source files from the distributable artifact.

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
