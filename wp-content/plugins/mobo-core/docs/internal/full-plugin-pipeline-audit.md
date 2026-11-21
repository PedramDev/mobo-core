# Mobo Core — Full Plugin Pipeline Audit

Baseline: UX53 / 10.34.16 (separate from the frozen UX51 Plugin Gate).
First implementation tranche: UX54 / 10.34.17, 2026-09-30.
Second implementation tranche: UX55 / 10.34.18, 2026-09-30.
Third and fourth tranches: UX56 / 10.34.19 and UX57 / 10.34.20, 2026-09-30; distributed together in cumulative UX57 artifact.
Fifth and sixth tranches: UX58 / 10.34.21 and UX59 / 10.34.22, cumulative UX59 artifact.
Seventh and eighth tranches: UX60 / 10.34.23 and UX61 / 10.34.24, cumulative UX61 artifact.
Ninth and tenth tranches: UX62 / 10.34.25 and UX63 / 10.34.26, cumulative UX63 artifact.
Eleventh and twelfth tranches: UX64 / 10.34.27 and UX65 / 10.34.28, cumulative UX65 artifact.
Thirteenth and fourteenth tranches: UX66 / 10.34.29 and UX67 / 10.34.30, cumulative UX67 candidate.

This is a **progress ledger**, not a claim that every pipeline has passed a real
WordPress/WooCommerce/Portal run. An item is CLOSED only for the precise
contract described below. Source inspection and isolated PHP stubs cannot prove
cross-process or production MySQL behavior. No modifications were made to UX51
or the frozen r8.3.177 test Suite.

## Audit standard

For each pipeline inspect (1) inbound authority and durable Connection owner,
(2) ordering/idempotency and atomic commit, (3) concurrency/lease/revision,
(4) crash/restart and bounded retry, (5) memory/SQL cost at 30K per Source,
(6) Health diagnostics and sensitive-data exposure. Test both Source A and B;
include mixed orders and a disabled/reprovisioned Source. Never silently map an
unknown durable owner to Default.

## UX54: confirmed source issues addressed in this candidate

| Finding | Before | UX54 intervention | Proof obtained | Live proof pending |
|---|---|---|---|---|
| Uninstall / DB access | `$wpdb` first accessed inside the function before `global $wpdb` | Declare global before first query; remove late declaration | Standalone PHP execution with mocked WP filesystem/DB | Disposable WP uninstall and business-data preservation |
| REST exception disclosure | `error_log` interpolated raw exception message, file and line | Woo logger gets a generic operation label and exception class only, with no raw exception string or PHP global `error_log()` | Reflection test with embedded token/path | Authenticated heartbeat and queue failure, inspect persisted logs |
| Cron diagnostic disclosure | Raw exception text/file/line entered `last_result`, `stageErrors`, stage fallback, stale-lock diagnostics | Stable generic messages and exception class; raw exception and absolute source path not persisted by these paths | Reflection tests with embedded token/path | Real stage failure and Health/REST output |
| Webhook ingress orphan | A positive invalid/disabled Connection ID could be written to event table/file fallback; ID 0 fallback file could be stranded | Before ANY new persistence require positive enabled durable owner; table store repeats guard independently | Isolated owner mocks across table/file/Default cases | Actual MySQL, failed DB write to file fallback, dual-site |
| Connection disable during claim | Worker could continue using cached owner data | Fresh DB `is_enabled` check immediately before processing; if unavailable, preserve owner, defer table/file event 120s without increasing attempts; never reassign Default | Fresh-read vs stale-cache and direct processor tests | Real concurrent disable/claim, DB failure during defer, no stronger atomicity claimed |

**Boundary:** Checking `is_enabled` before the side effect reduces cache-staleness
but is not transactional with an admin disabling a Connection milliseconds later.
A shared disable/drain barrier and true cross-process chaos test are still needed.
Existing legacy files without an owner continue to be considered Default-only;
new invalid `connectionId=0` files are no longer created. Pre-existing orphaned
rows/files are NOT secretly moved to Default or deleted. An explicit audit and
operator-controlled disposition are pending.

## Pipeline-by-pipeline status and next audit scope

| Priority | Pipeline | Scope / files | Status after tranche 1 | Next non-mock proof |
|---|---|---|---|---|
| P0 | Connection, provisioning, authentication | `connection-repository`, `connection-context`, `rest-controller` | Read for owner boundaries; fresh owner probe added. End-to-end provisioning NOT signed off. | Concurrent HMAC replay; re-provision/disable while two Sources run; invalid/missing WebHookId vs X-SEC |
| P0 | Webhook / event queue | `webhook-queue`, `sync-event-store`, `event-type-policy` | New ingress owner validation and deferral fixed. Full ordering/recovery still open. | 2-process claim/retry, failure fallback to JSON, replay/ordering with A/B, orphan inventory |
| P0 | Full / incremental sync | `product-sync`, `reconciliation`, `product-ledger`, `sync-health` | UX55 snapshot-time and late-stage webhook fence; still not a full behavioural sign-off. | Real webhook during GET/parent checkpoint/image stages, crash/restart and stale revision |
| P0 | Product / variation identity | `product-map`, `variant-state-repository`, `variation-lifecycle-policy`, `product-concurrency` | Pending deep inspection. | Simple-to-variable transition, missing variation, duplicate remote ID, cross-Source shared Woo product |
| P0 | Price, stock and reprice | `price-calculator`, `product-pricing-policy`, `reprice-queue` | UX55 pending variable-parent recalculation durably linked to Reprice cursor; stock/overrides still open. | Real kill between cursor and parent sync, simultaneous policy/stock webhook, stale claims |
| P0 | Cart, checkout and order | `checkout-validator`, `order-submission-policy`, `revenue-ledger` | UX56 fresh route fencing, stale-order quarantine and no orphan-marker revival. Runtime Wallet proof still open. | Mixed order rejection; multi-Source order rejection; remote success then lost HTTP response; exactly-once retry |
| P1 | Cron / worker | `cron-runner`, `fair-scheduler`, `lock`, `self-runner` | Raw exception leakage paths above repaired. Scheduler fairness not declared PASS. | Two-site leases, shutdown/timeout, Source starvation, disabling Source during claim |
| P1 | Custom storage and migration | `storage-*`, `database-unicode-migration` | Source/DDL contracts inventoried; no DDL changed in UX54. | utf8mb3 to mb4 checkpoint/rollback, concurrent row mutation and schema readiness |
| P1 | Image / media | `image-*`, `shared-media`, `orphan-image-cleanup` | UX58 shared Connection/product desired-state lease and stale-prune/empty-gallery safety; physical deletion and runtime chaos not signed off. | Image replacement interrupted between upload/reference/prune; A/B shared attachment |
| P1 | Category / mapping | `category-map`, `category-sync`, `recategorize-queue` | UX59 pre-GET fenced snapshot, CAS map retirement and term-marker reconciliation; runtime concurrency still open. | Removed remote category, manual mapping overwrite, stale update after full snapshot |
| P1 | Shipping / address / wallet | `automatic-shipping`, `address-mapping`, `wallet-alert` | UX57 microsecond shipping GET fence, scoped existing mutex, uncached Wallet route and redacted upstream diagnostics; Woo/Portal integration not signed off. | Mixed eligibility, invalid mapping, remote timeout, one-shot SMS/wallet alerts per Source |
| P1 | Cache / purge / warmup | `cache-purger`, `cache-warmer`, `work-queue-store` | UX52/UX53 source hardening retained, no changes here in UX54. | Real 30K+30K MySQL, private-cache and provider integration, crash after GET |
| P1 | REST / diagnostics security | `rest-controller`, `health-reporter`, `runtime-diagnostics` | REST/Cron exception redaction repaired only. | Token-in-Exception injection across *all* error/health paths; authority of status routes |
| P2 | Upgrade / install / uninstall | `remote-updater`, `upgrade-coordinator`, `uninstall.php` | Confirmed Uninstall bug repaired; remote-updater prior UX47–50 not rerun in this tranche. | Disposable WordPress uninstall, interrupted upgrade backup/rollback and ownership barriers |

## Explicitly open risks (do not infer a PASS)

1. **Existing orphans:** discover `connection_id=0`, deleted/disabled owner rows,
   and explicit-zero fallback JSON files; report separately, do not auto-adopt.
2. **Cross-request disable race:** the final freshness check is not an atomic
   disable/worker transition; a shared lease or drain contract needs design.
3. **Remaining exception surfaces:** e.g. shipping/admin `WP_Error` strings,
   webhook `last_error`, remote payload failures and third-party HTTP exceptions
   may still contain raw text. Central redaction must preserve actionable stable
   codes; do not blindly suppress errors without targeted evidence.
4. **Order idempotency:** exact side-effect/ack crash boundary requires Portal
   correlation and real dual-site order fixtures, not source-only assumptions.
5. **Performance:** 30K/60K SQL/HTTP/CPU and long-running soak not executed.
6. **Current gate:** UX51 r8.3.177 Plugin Gate is separate; UX54 needs a newly
   re-pinned Suite and full runtime proof before release.

See `admin-ux-test-backlog.md`, section AX for permanent test contracts.

## UX55 — Tranche 2: Full Sync/Webhook and Reprice/Stock boundaries

| Confirmed failure mode | UX55 correction | Isolated proof | Remaining cross-process proof |
|---|---|---|---|
| A page/exact GET timestamp captured after the response could label a stale payload newer than a webhook committed during the request. | Capture local watermark **before** each request. For legacy queued items with no watermark, require an exact fresh GET instead of blessing old data with the current clock. | Source-order assertions; manual rebase mock. | Pause API response after webhook, verify parent/variation and source revision in real Woo + Portal. |
| Parent sync Step 3 finishes but images / Simple / paged Variants continue in future requests after a newer foreground webhook. | Durable `currentProductSnapshotUs`, pre-Step4 rebase, reset old stage cursors and seen-set, requeue exact fresh parent without double-counting; Step5/6 recheck watermark under shared parent lock before mutation. | Isolated exact-GET success/failure/rebase and stale Simple commit; code-level under-lock variant ordering. | Parallel Webhook inside Step4 image-queue operation and post-finalization; actual image desired-state revision ordering is separately open. |
| Reprice checkpoints `lastPostId` before re-syncing variable parent, keeping `parents_to_sync` only in request memory. | Persist bounded `pendingParentIds` with the cursor, settle on subsequent run and before `start()` resets generation; do not remove debt on error, owner mismatch, missing lock GUID or lock contention. | Isolated forced exception/retry/owner/lock mock; source contract for cursor and debt in checkpoint. | Kill PHP after persisted cursor and before/after actual `WC_Product_Variable::sync()`; test Woo lookup-index/caches. |
| Pricing policy changes mid-batch while old generation still processes rows. | Recheck policy fingerprint per iteration and after parent reconciliation; invalidated generation cannot be marked done after a successful checkpoint. | Source contract. | Real concurrent setting update, stock webhook and variation override with A/B. |

UX55 does **not** claim complete linearizability of Webhook vs asynchronous media application,
or that Source disabling and an in-flight mutation is atomically serialized. These are
separate AUD54/55 cross-process tests. No schema migration or new table is included.
UX51 + frozen Deep Test Suite r8.3.177 must remain unchanged until its Plugin Gate closes.
Permanent contracts are listed in `admin-ux-test-backlog.md` section AY.

## UX56 — Tranche 3: Checkout, remote order and wallet one-shot boundaries

Implementation: `10.34.19`, September 30, 2026. This is a targeted P0 source-and-mock audit, **not** real dual-site payment validation. No new schema or automatic retry after ambiguous Wallet outcome.

| Confirmed failure mode | UX56 intervention | Local proof / remaining proof |
|---|---|---|
| Request-local cached Connection might outlive disable/reprovision during a multi-request remote purchase. | Add `get_by_id_fresh()` and a request-local digest of admitted Owner/Provider/Source/URLs/credentials; compare before each outbound request and at the last reversible pre-Wallet boundary. Unknown/disabled/unprovisioned owner keeps its original durable queue and never routes to Default. A route change causes full cart rebuild instead of cross-route Wallet. | Reflection/mock: stable, re-provisioned, missing and disabled owner; pre-send HTTP refusal. Real concurrent admin provisioning + Portal API still required. |
| Worker B could mark a timed-out `running` order uncertain using an old Woo object without acquiring Worker A's per-order purchase lock; another status-change branch could mark it aborted while A was paying. | Stale-attempt quarantine takes the *same* `order_submission_<id>` lock, reloads WC_Order, checks durable remote success, persists uncertainty, re-reads status and only then removes exact queue token. Recent/live attempt stays queued without mutation, even if Woo status changed. | Reflection/mock busy / recent / committed-success / abandoned-stale / lock release. Cross-process Wallet acknowledgement and HPOS pending. |
| A stale recovery marker for an already `failed` / `aborted` order could route into the ordinary submit entry again. | Queue processor explicitly removes such orphan markers without auto-starting another purchase. Admin manual retry remains a separate authorized action. | Source contract; runtime marker injection pending. |
| Route changed in the tiny window after independent `payment_inflight` fence but before `wp_remote_request`. | Request rejects before network and returns a stable `mobo_core_order_connection_changed`; verified removal of the just-written guard enables safe deferral. If cleanup cannot be verified, keep uncertain. **Never** classify a possibly-sent Wallet as safe merely because transport returned an error. | Source contract + mock pre-send refusal. Exact injected pre-send race and dropped-response Portal proof pending. |

Existing protections confirmed in source and retained: captured item identities; mixed/non-Mobo and multi-Connection orders never partially submitted; per-order lock; verified Woo `running` checkpoint before remote side effects; Clear -> Add All -> snapshot verification; pre-Wallet Woo business snapshot; independent filesystem payment fence; explicit manual verified-absent retry; post-payment status divergence flagged for review. These observations are **not** an end-to-end PASS.

**Remaining limitation:** A fresh row check is not an atomic disable/HTTP operation. Fully serialized Connection draining requires a shared protocol with provisioning/admin, and exactly-once external Wallet semantics require Portal-side correlation or an idempotency contract. Keep this on the UX65 cross-pipeline/real-gate list. Permanent test contracts AZ / 001–018.

## UX57 — Tranche 4: Cart/Shipping/Address/Wallet boundaries

| Confirmed source-level risk | UX57 correction | Isolated proof | Remaining real proof |
|---|---|---|---|
| `Remote_Shipping_Methods` used second-precision start/synced times; GET and webhook in one second could allow older GET to overwrite newer snapshot, especially with explicit `changedAt`. | Store microsecond `syncedAtUs` and compare to start of HTTP on every API-origin snapshot inside existing per-owner `GET_LOCK`. Keep second-based callers compatible. | Synthetic same-second delayed-GET and explicit `changedAt` mock. | WAMP two-process delayed HTTP/webhook; Provider clock skew. |
| One-shot Wallet SMS send used a lock, while explicit `rearm()` cleared its fence without taking that lock. | `rearm` acquires same existing logical `wallet_alert_sms` lease and verifies the resulting state; never clears fence while sender owns it. `Mobo_Core_Lock::option_key` already scopes this by Connection, so **no ad-hoc new lock namespace**. | Busy lock leaves fence unchanged, then owner re-arm succeeds; A/B state independence mocked. | Real gateway ack/crash and admin concurrency. |
| Post-purchase wallet hook used request-local Connection state, which can outlive admin reprovision/disable; stored raw upstream error messages could expose credentials/path text. | Check fresh durable owner and route fields against cached runtime row before balance request, suppressing stale callback; persist bounded stable error labels instead of upstream message text in Wallet, shipping and address API. | Stubbed fresh/cached drift, no HTTP counter increment, injected secret WP_Error and source-code review. | Disable/reprovision during real paid disposable order and inspect Woo meta/Health. |
| Address Mapping and City Assets generation previously allowed a best-effort legacy-unlocked mode without positive ownership. | Require durable positive owner before mutation; use their existing logical Connection-scoped mutexes. Files/status were already per-Connection; preserve that behavior and original barrier interoperability. | Static source assertions and PHP lint; actual filesystem/upgrade-drain test remains queued. | A/B parallel generation, lock takeover, upgrade/Unicode barrier drain. |

Cart policy was checked at source level: order-level mixed/non-Mobo and multiple-Source rejection belongs to UX56; Cart validation has source-partitioned preflight, and mapping-only Shipping does not inject a new Woo rate. Actual Classic + Store API checkout, shipping selection and automatic-order integration remain BA pending and must not be declared PASS. No new table/schema, automatic Wallet retry, multilingual or multicurrency behavior is introduced.

Finalized artifact **10.34.20 / UX57** contains both UX56 and UX57. Standalone 10.34.19 was an internal audit checkpoint and is not a separately advertised release. Remaining known systemic limits: atomic Connection disable-versus-outbound HTTP barrier, Portal-side payment idempotency/correlation, and genuine real two-site fault injection.

## UX58 — Tranche 5: Image desired-state / media-linkage concurrency

Internal checkpoint `10.34.21`, shipped together with UX59 as `10.34.22`. Source and isolated PHP stub audit only: no WordPress + MySQL + image-server chaos test is claimed.

| Verified source-level gap | UX58 intervention | Remaining real proof |
|---|---|---|
| New image desired-state rows, prune candidates and Woo gallery linkage previously mutated the same product independently; a delayed worker could save an older gallery after the queue was replaced. | `Mobo_Core_Image_Queue::desired_state_lock_name()` gives one lease per **Connection + Woo product**. Queue replacement, pending prune/recheck, gallery linkage and explicit-empty Woo clearing use the same lease. Download/physical image processing stays outside it. Busy owner returns retry/defer instead of partial mutation. | Parallel Webhook/Full Sync/worker during attachment download and gallery save; contention at lock expiry, upgrade barrier, multi-Source separate products. |
| Old pending prune candidates could detach an attachment just reintroduced by a newer remote desired set. | Re-filter all pending IDs against current durable desired-state **inside the lease** immediately before detaching from Woo and recording pending cleanup. Final physical removal still belongs to the existing bounded cleanup/reuse verifier. | Kill/restart before/after detach; shared attachment used by other Woo products/Connections; verify zero unintended delete. |
| An explicit empty older image payload previously cleared featured/gallery after enqueue had released its mutex. | A separate locked empty-set commit rereads current queue; when any newer desired row exists, refuse destructive clearing. Save/readback Woo while holding the desired lease. | Two-process delayed empty API response + new Webhook, plus gallery/single-featured variants. |
| Non-empty async images can legitimately lack downloaded attachments at first pass. | Preserve non-blocking queue semantics: a false gallery rebuild before download does not become a new hard failure; due worker later retries linkage. | Async cron throughput on 30K and storage-readiness repair. |

No schema change; lock owner is explicit, not inferred from Default. Physical remote image download does not hold the shared product lease. Permanent test plan: UX-AUD58-001 through UX-AUD58-014 (section BB).

## UX59 — Tranche 6: Category snapshot ordering / retirement / mapping

Final combined artifact `10.34.22`, 2026-09-30. Direct payload callers without HTTP-start time retain compatibility but do **not** receive the authoritative generation fence; all current real category API call sites in Product Sync were migrated to supply the pre-request timestamp.

| Verified source-level gap | UX59 intervention | Remaining real proof |
|---|---|---|
| An older category GET could finish after newer GET/webhook and retire or replace categories from the new snapshot. | Capture microsecond start **before** every known category HTTP GET, serialize authoritative apply by Connection-scoped category snapshot lease, reject start time at/before durable committed watermark; persist new watermark only after complete apply and lease verification. No successful mark on invalid/partial payload, missing category table (including empty lists), or lost lock. | Two-process out-of-order API responses on disposable real MySQL/Woo; foreign Provider `changedAt` skew and timeout/retry. |
| Applying remote snapshot absence could run concurrently with category upsert and clear newer mapping from a stale SELECT. | Retirement now compare-and-clears `(connection_id, row id, old synced_term_id)`; `0 rows affected` means competing writer won and is not counted retired. Manual mapping stays untouched. | Concurrent map update, deletion/re-creation, duplicate/parent category GUID; SQL failure injection. |
| `mobo_category_retired` on a shared Woo term could be set after new ownership reappeared. | Retired term is marked only after successful CAS and an all-Connection live-owner probe; after marker write, re-probe and unretire if an owner reappeared. The category upsert path reasserts active marker after committing a mapped term. | Interleave CAS/owner probe/mark/upsert at each boundary on Classic/HPOS sites; third-party term metadata hooks. |
| Long category passes could outlive a lease and retire entries after a lock takeover. | Renew/check lease during reads and passes, and before the destructive retirement step and durable generation write. Abort incomplete on lost lease. | Kill between row updates/retirement, upgrade barrier activation during apply, connection disable. |

**Open cross-pipeline limits for UX65 and runtime gate:** direct ad-hoc category payload writes without pre-request generation are not serialized with this HTTP fence; product-specific category mutations may still race snapshot retirement and require process-level probes. Category `retired` marker and mapping row changes are separate WordPress/MySQL writes, not a global cross-table transaction. Multi-Connection shared-term behaviour must be demonstrated with real DB before release. No schema migration, no automatic purge of manual mappings, and no customer data conversion is introduced by UX58/UX59.

## UX60 — Tranche 7: Scheduler / worker / lease ownership

Internal checkpoint `10.34.23`, shipped in the cumulative `10.34.24` artifact. Source-inspected failure modes and isolated proof:

| Finding | Intervention | Obtained proof | Outstanding live proof |
|---|---|---|---|
| Unicode partial/paused/failed state can start between initial lock acquisition preflight and atomic option INSERT; only the temporary upgrade barrier was rechecked after INSERT. | Recheck `Database_Unicode_Migration::should_block_runtime_lock()` after INSERT; token-owner release only, no force unlock. | Mock flips Unicode barrier exactly after first check: 3/3, normal acquire/release 4/4. | Two-process DB migration start against real `wp_options`, inspect no post-barrier mutation. |
| Site-wide dispatcher may enter Source B after Source A's long execution/diagnostic flush, without another explicit outer-lock renewal. | Renew `real_cron_runner` and optional self-dispatch handoff token before every next Connection. An inner `lock-lost` breaks the remaining Source loop. | Source-stage guard contract and lint. | Time injection into real Source A followed by competing runner for Source B. |
| Multi-Connection run processes only one Source before deadline and `count(results)===1` incorrectly returns the single-Source result, hiding aggregate `needsContinuation`. | Single-Source fast path requires actual configured total=1 and intact lease; partial multi-Source slices keep aggregate status/cursor. | Source structural contract; full WAMP time-budget fixture pending. | Force budget after Source A, confirm B remains pending and continuation evidence visible. |
| Warmup RR cursor advanced during initial due probing even when higher-priority queues exhausted the budget before selected GET. | Persist the cursor inside the selected Connection's prepared cache-Warmup stage, before its GET; persistence failure suppresses GET and returns stable stage error. | Source structure check. | Repeated A/B due, stop before selected stage, verify next run does not skip its turn. |
| Archive purge can run after queue-pressure snapshot without explicit lease revalidation in this inner method. | Renew before starting the first mutation stage. | Source structure check. | Expire lease during snapshot and verify no purge runs under stale owner. |

**Still open:** long blocking PHP/HTTP operations can outlive a finite lease even after a valid pre-stage renewal. Cross-process proof must establish per-stage bounded timeout and DB claim fencing; full atomic Connection-disable-versus-running-worker semantics are reserved for UX65. No new lock name/namespace or DDL in UX60.

## UX61 — Tranche 8: Custom Storage / durable queue data integrity

| Finding | Intervention | Obtained proof | Outstanding live proof |
|---|---|---|---|
| Legacy order/parent queue migration used `replace_map()` and could erase a newer destination row. | Additive `insert_missing_direct` in 100-item slices, installation-global per-queue importer mutex, renewed lease, fingerprint+offset checkpoint. Default-only legacy ownership; invalid/truncated identities fail closed. | In-memory database stub: 225 legacy items in three slices, earlier new revision survives; 24/24 assertions combined. | Real MySQL worker/importer contention, crash at each cursor commit and import reentry; Classic/HPOS order markers. |
| Old `delete_option()` performed after an in-process option check could remove concurrent source changes. | Fresh SQL source-byte comparison plus atomic binary option-value CAS delete; source remains on mismatch. Options caches invalidated, checkpoint cleared only after verified source absence. | Inject source edit between fresh SQL read and CAS deletion: source survives. | WP object cache, real database collation, hooks relying on retired option. |
| A failed `get_results()` or corrupt serialized row could be interpreted as an empty map by callers. | Validate every row's SHA-256 and array payload, latch read failure per owner/queue and reject `replace_map()` until a clean full re-read. Expose non-secret `map_read_failed()` signal. Never silently discard corrupt evidence. | Inject incorrect payload hash: destructive replacement blocked, DB row preserved; clean reread clears latch. | SQL outages/corrupt row on real MySQL and Health exposure / operator recovery. |
| `replace_map()` had unconditional upsert and deletion after its initial snapshot, potentially overwriting a newer revision or active claim. | Snapshot diff uses CAS UPDATE/DELETE with expected `payload_hash`, queue owner and dedupe identity, and refuses changes to actively claimed entries; absent keys insert without replacing conflicting rows. Final full-map readback remains mandatory. | Inject concurrent new payload during DELETE and UPDATE: both return failure without deleting/updating new row. | Two-process same-key ABA, crash between multi-row changes, retry convergence; no cross-row transaction is claimed. |
| Fresh site has no Connection yet and no legacy source to import. | Empty-source fast path returns success before requiring positive owner; actual legacy data without owner remains fail-closed. | Isolated 0-owner mock: both cases verified. | Fresh install / activation on empty and migrated test databases. |

**Scope:** no schema/index change, bulk table conversion, forced deletion or fallback of orphan rows to Default. General `replace_map()` is deliberately NOT advertised as an atomic multi-row transaction. Re-pin Deep Test Suite after UX65; keep the running UX51/UX55 test artifacts independent. UX60/61 source+mock audit does not replace live WAMP/MySQL/Portal concurrency evidence.

## UX62 — Tranche 9: REST/API, security and Multi-Connection authority

Implementation checkpoint `10.34.25` (distributed with UX63 in cumulative `10.34.26`), 2026-09-30. Inspection covers `rest-controller`, Connection repository/context, signed provisioning and route permission declarations. Source/mock evidence below is **not** live WordPress or concurrent MySQL evidence.

| Confirmed source-level risk | UX62 change | Isolated evidence / still open |
|---|---|---|
| A failed X-SEC `/portal/webhook-test` recording path called `Connection_Repository::normalize_webhook_id()` even though it was `private`, producing a PHP fatal instead of normal 401 diagnostics. | Expose the existing normalizer for REST status recording. Refuse inbound WebHookId over 191 bytes rather than truncating it into a potentially valid different owner. Invalid oversized admin updates also fail without silently clearing the saved WebHookId. | Actual REST permission rejection and persisted diagnostic on two WordPress/Portal Connections pending. |
| Request-local `get_by_id()` returned cached X-SEC/enable state after another request disabled/rotated credentials, and `get_by_license_token()` could authorize stale cached Token or rely on case-insensitive collation. | Inbound WebHookId selects one enabled row by fresh binary-equality SQL; legacy no-ID uses uncached Default; final owner/ID/enabled recheck before comparing X-SEC. Provisioning Token reads fresh, binary-exact rows with `LIMIT 2` and refuses ambiguous duplicate owners. Clear prior Connection Context before each new REST authentication attempt. | PHP mocks for cached credential/Token rotation, invalid owner/Default fallback, Source A/B scope. There remains a final-read-versus-disable race until UX65 atomic drain. |
| Installation-global REST permission could use cached Default information after row mutation. | `/health`, `/upgrade/*`, `/heartbeat`, site-wide worker status/report require the authenticated fresh enabled owner to equal the canonical freshly read Default ID (including corrupt duplicate-Default flags) before action. Source-scoped `/portal/*`, sync and webhook retain their existing Connection context. | Auth mock: B denied installation surface and Default accepted. Audit `license-installation/challenge` independently as its contract may intentionally authorize every registered Source for the *same site's* challenge; do not silently break that license flow. |
| Two valid signed provisioning POSTs could both see an unbound row and later overwrite each other's Source/Provider, or disable Default through server payload. | One SQL compare-and-update binds immutable Source/Provider and exact Token at mutation time; fresh readback checks all routing fields. Signed payload cannot disable Default. A losing write returns explicit concurrent-change instead of inheriting/rewriting Default. | Mock competing Source A/B write; verify real 2-process SQL, signed retry, rotation and Object Cache. |
| An expired provisioning nonce was `delete_option()`-ed following an old cached observation, potentially deleting a newer reservation. | Read current `wp_options` row and CAS-delete only its exact expired bytes, invalidate option caches, then reserve via unique `add_option`. A competing renewed nonce wins and replay is denied. | Isolated mock same-nonce CAS race and 409 replay. Actual DB collation/autoload/cache and concurrent HMAC clients pending. |
| Older failed-upgrade diagnostics and manifest errors may have stored raw file paths/third-party exception text readable through site status. | New upgrade errors are stable/bounded, manifest/rollback inspection returns stable `errorCode` plus generic text, and historical `lastError` is suppressed on public status read while preserving local evidence. | Static/source validation; inject signed URL, X-SEC and path into actual failed Portal upgrade and inspect REST, Woo logs, persisted status and Health on both Sources. |

**Authority limit:** These changes restrict which Connection can enter a request and how its durable credential is read. They do not claim an atomic disable/HTTP or re-provision/ongoing mutation transaction; those boundaries remain UX65. No Swagger model rename, schema/table/index change, or Provider hard-code was introduced.

## UX63 — Tranche 10: Installation, Upgrade, Rollback and Uninstall

Cumulative candidate `10.34.26`, 2026-09-30. Includes UX62 and UX63; UX51 and frozen r8.3.177 Suite remain unchanged.

| Confirmed source-level risk | UX63 change | Isolated evidence / still open |
|---|---|---|
| An interrupted install could throw after plugin directory replacement, while rollback itself could throw or return WP_Error. The old failure handling persisted raw exception strings and released the finite barrier even when filesystem recovery was not verified. | Catch both failed and thrown restore attempts. If active destination was touched and backup restore is not verified `true`, persist `mobo_core_upgrade_manual_recovery_hold`. Block ordinary worker and Unicode/DDL locks after normal barrier TTL; exempt only existing updater and barrier leases for verified recovery. Do not self-kick workers under hold. Clear hold only after on-disk target version/manifest/fingerprint verification and verified option readback. | Source-level inspection + lock mock; run disposable real filesystem fault injection, verify WP option persistence under SQL failure, manual recovery and no old-code execution during partial replacement. |
| An old lease's post-release `delete_option(barrier_state)` or read-only expired lease check could remove a newer updater's diagnostics after lock takeover. | Store non-secret `barrierTokenHash`; remove the state only if token matches and fresh SQL binary byte CAS confirms exact same serialized record. `is_active()` never destructively deletes status. | Inject new owner at CAS boundary in isolated mock. Verify real object-cache and 2-process takeover/renew/release. |
| Uninstall removed old Parent/Order source options while UX61 additive importer could be between 100-row checkpoints; it also removed ambiguous Wallet recovery markers and all active runtime locks. | Preserve transitional legacy Parent/Order sources until importer CAS retires them; preserve ambiguous payment recovery marker options, in-flight lease rows, and manual recovery hold/status. Only prune expired exact runtime locks with byte CAS. Business mapping and transaction tables remain retained as before. | Static source assertions; disposable WordPress uninstall during import, live paid order and active lease are required. This is not permission to delete customer/legacy data. |
| A previous version's stored raw `lastError` could be echoed back after the source was upgraded. | Public Upgrade status filters legacy errors; Upgrade Coordinator filters historical barrier lastAudit text. Newly generated errors use stable stages only. | Source review; live REST/Health/log injection pending. |

**Open release blockers:** live fresh install with zero Connections; activation and schema readiness on pre-`utf8mb4` database; interrupted Unicode conversion/upgrade; WAMP/Portal two-process lease handoff; recovery hold if durable `update_option()` itself fails; full Classic/HPOS order evidence on uninstall; real MySQL 30K+30K performance; UX65 cross-pipeline atomic Connection drain and remote Wallet idempotency. Do not advertise this cumulative candidate as production runtime PASS. Permanent suites: backlog BF (UX62) and BG (UX63), to be re-pinned after UX65.


## UX64 — Tranche 11: Identity ownership and durable revision fences

Candidate `10.34.27`; packaged only together with UX65 as `10.34.28` (September 30, 2026).

| Source-inspected risk | UX64 intervention | Local evidence | Live proof still required |
|---|---|---|---|
| Two different Connections could both pass the foreign-owner SELECT before writing one Woo ID to Product Map; the map schema has no installation-global unique constraint on wp_post_id. | For the ordinary Product Map upsert, hold an installation-global `product_map_woo_<postId>` runtime lease **and a database-wide named GET_LOCK keyed by DB/prefix/Woo ID** across owner SELECT, conditional INSERT/UPDATE, exact readback and variation reverse-map pruning. Busy/error fails closed; only the owner releases. The same guard now protects Default-only legacy map seeding as well. | Isolated fake database/lock: success ordering, foreign owner, unavailable MySQL lock, unavailable runtime lease and release in rejected cases. | Real two-process MySQL/WAMP with A/B, competing Parent/Variation, lease takeover, DB reconnect and upgrade drain. The GET_LOCK is cross-Source; `product_map_woo_*` is an installation-global lock name because it is not in the Source-scoped list. |
| An unavailable foreign-owner SELECT could look like an unowned Woo object. | Owner check fails closed on `$wpdb->last_error`. Product Map opaque remote identity comparisons in hot lookup/upsert/delete paths use binary exact match; a collation/prefix collision cannot silently satisfy full identity readback. | Structural source checks and fake database guard case. | Real utf8mb4 MySQL collation/index-prefix test with same-prefix and case-distinct remote GUIDs. |
| Late desired-state writes can regress `desired_revision` or admit equal-watermark conflicting hashes. | Product and Variant State Repository `mark_desired()` use monotone revision/hash gated ON DUPLICATE assignments with revision written last and exact subsequent readback. Variant also binds `product_guid`. | PHP source/isolated readback assertions. | Real MySQL assignment ordering/affected-row semantics, conflicting hashes, stale parent rebind and retry after SQL fault. |
| Late `mark_applied()` could clear dirty/retry/claim fields of newer state. | Update requires exact current desired revision, nonnewer applied revision, unclaimed row and compatible desired/applied hash. Verify the applied row by fresh readback. Stale or competing Ack returns false. | Mock with newer desired revision and active claim for both repositories; source assertions for `WHERE` fence. | Actual two-process claim/ack, ABA, crash at each SQL boundary. These repository helpers currently have no direct active call sites found in `includes`; do **not** mistake helper-level hardening for full sync proof. |
| Legacy Variation mirror had initial identity assignments that could be overwritten by an older watermark. | Initial `product_guid`, `woo_variation_id`, `woo_product_id` and Portal variant ID assignments require nondecreasing seen/applied watermark; existing hash/readback checks retained. | Source contract and PHP syntax lint. | MySQL equal-watermark/hash conflict; bridge called during Source A/B concurrently. |

**Scope note:** the existing Custom Storage schema already has global `UNIQUE KEY woo_product_id` and `UNIQUE KEY woo_variation_id`, with owner-conditional ON DUPLICATE assignments. The new Product Map guard supplements a *different* table lacking such global uniqueness. This is not a claim of an all-table, all-process transaction. No table DDL added.

## UX65 — Tranche 12: Cross-pipeline Connection route and mutation authority

Final cumulative candidate `10.34.28` (September 30, 2026). This tranche addresses specific confirmed source-level contracts; **UX65's broader live cross-pipeline gate remains OPEN**.

| Source-inspected risk | UX65 intervention | Local evidence | Live proof still required |
|---|---|---|---|
| General API Client cached routing credentials for an entire PHP request, including manually followed redirects; a Source might be disabled/reprovisioned/rotated after a request started. | Build GET/POST context from a fresh positive enabled/provisioned owner, bind Token, X-SEC and URL to a digest of all routing/credential fields. Re-read the durable row and verify the URL fingerprint **before each outbound request and redirect hop**; return stable `mobo_core_api_connection_changed` on drift. Pre-Connection-table upgrade compatibility is retained only when no table exists. | Fake HTTP: initial GET, disable, Token rotation, endpoint change, refreshed context and disable between 302 and follow-up (all passed). | Two PHP workers, HTTP latency, DB disconnect at exact send boundary, Portal source A/B; this fence **cannot cancel an already-sent HTTP request**. Other specialized HTTP consumers need their own runtime proofs. |
| Admin metadata update used ordinary UPDATE by ID after a fresh read. An admin could overwrite a concurrent signed reprovision/disable/credential edit; Token uniqueness check could also race. | Compare-and-update all mutable identity/credential/enable preimage fields in the same SQL statement, verify final exact readback, detect zero-affected concurrent mismatch. Token rotation has one installation-wide named lock and binary-exact foreign-owner lookup. Signed provisioning UPDATE also binds fresh enable/API/security/Connection-key preimage and retains immutable Source/Provider ownership. Admin and provisioning refuse new mutations while upgrade/Unicode maintenance blocks are active. | Fake database: successful update, query-preimage assertions, injected competing disable, successful Token rotation, foreign Token rejection, busy Token lock. | Real MySQL compare evaluation, simultaneous independent Admin and signed Provisioning, WordPress object caches, real maintenance/disable drain. |
| Portal GET/POST transport or HTTP errors could include a raw upstream `WP_Error` message, request URL or response body with Token, signed paths or other sensitive text, which callers might persist. | API Client replaces untrusted error text/body with bounded generic operation messages. Retain numeric `status`, sanitized `portal_status` and sanitized transport error **code** required for endpoint discovery/diagnostics; request-local circuit now propagates sanitized error. No endpoint/payload shape rename on successful requests. | Inject secret-like transport and 503/401 response bodies into GET/POST; no raw text retained; success GET/POST works. | Scan persisted logs, Health, remote callbacks and all specialized transport consumers on real WP/Woo/Portal. This closes the **general API Client surfaces only**, not every plugin diagnostic path. |

### UX65 unresolved cross-pipeline/real gates

1. **Atomic Connection drain:** fresh owner checks and CAS reject stale new operations but cannot serialize a disable/reprovision with a pre-sent Wallet/payment or other in-flight HTTP side effect. A shared administrative drain/worker protocol and Portal-side correlation are still needed; do not auto-retry ambiguous purchase.
2. **Global end-to-end Identity:** two-process Woo Product/Variation/Custom State/legacy-map transaction gaps, SQL reconnect/lease expiry and 30K+30K contention/throughput remain to be demonstrated. All Source-owned records must keep their original positive Connection; no fallback to Default or automatic orphan re-adoption.
3. **Webhook/Full/Media/Category/Price/Stock interleavings:** previously documented watermark/lease defenses require actual out-of-order and crash/restart injection; source-level correction is not linearizability proof.
4. **Upgrade+Unicode:** prove the maintenance hold against concurrent provisioning/admin, remote GET, webhook/order and background migration, including failed rollback and manual recovery on both test sites.
5. **Security inventory:** other paths besides general API Client (specialized Wallet/shipping/admin/Health/images/third-party exceptions) require stable diagnostics tests, and historical raw data needs an operator-controlled retention/disposition policy.
6. **External Order Idempotency:** remote success plus lost HTTP ack and ambiguous Wallet remain blocked from automatic replay; exactly-once cannot be guaranteed from WordPress alone without Portal correlation/idempotency acceptance.
7. **Release gate:** the UX51 frozen r8.3.177 Plugin Gate was not altered or rerun. Build a newly pinned Deep Test Suite for the cumulative 10.34.28 candidate, plus two disposable WordPress sites (Classic + HPOS), MySQL utf8mb4 and live Portal/fault injection before production approval. No multilingual/multicurrency requirements are introduced.

Permanent contracts are in backlog sections BH (UX64) and BI (UX65). Isolated harnesses are included only in the external validation report, not in the runtime plugin payload.

## UX66 — Tranche 13: Specialized Checkout / Portal request authority

Internal 10.34.29 checkpoint (distributed cumulatively with UX67 as 10.34.30), September 30, 2026.

| Verified source-level gap | Correction | Isolated evidence | Runtime proof still open |
|---|---|---|---|
| Shared-cart, Login and direct Checkout HTTP used a request-cached Source even after disable/credential rotation/reprovision. | Fresh positive enabled/provisioned durable owner check against all cached route/credential fields before sensitive operations and immediately before transport. Active-order fingerprint also checked; unknown owner never becomes Default. | Reflection: normal, disabled, changed token/URL, unknown/zero owner, pre-send no HTTP, legacy absent-table only. | Real two-worker disable at precise send boundary, shared cookie isolation and Store API/Classic checkout. |
| External validation sent Portal credentials using cached runtime options; signing might use a stale Token. | For Portal-origin only, load fresh X-SEC/Token, verify fresh API origin, sign with current durable Token and recheck fingerprint before POST. `redirection=0`; third-party validation does not receive Portal credentials. | Signer token rotation/disable/owner tests, exact scheme/host/port comparison; source order assertion. | Portal signed integration and third-party HTTP plugins/filters; challenge/nonce accepted end to end. |
| Direct Mobo transport error surfaced raw WP_Error and uncertain Wallet might accidentally be treated as a clean failure. | Stable generic transport error and bounded code; preserve `requestMayHaveReachedServer=true`. | Inject token/path into fake transport error, no raw data; false pre-send fence remains false. | Portal remote success with dropped ack and manual reconciliation; no automatic ambiguous Wallet retry. |

## UX67 — Tranche 14: Remaining specialized diagnostics / security surfaces

Cumulative candidate 10.34.30. The confirmed paths include maintenance, storage bridge, self-runner, cache warmer/purger, Health/cPanel, Webhook queue, recategorization, parent finalize, Unicode/schema migration diagnostics, SMS gateway and order notes, remote updater, reprice, direct image/media download and image-refresh automation/service, address mapping and shipping diagnostics, and Checkout admin login test status. Raw exception/WP_Error text is replaced by generic operation labels, sanitized bounded error code or exception class where useful; unchanged business safety and uncertain-delivery handling remain.

**Evidence:** New PHP Reflection and fake transport/SMS tests plus targeted source redaction assertions; the prior UX64/UX65 isolated regression harnesses are rerun against 10.34.30. These do not exercise real WP/Woo/HPOS/Portal/MySQL or existing persisted logs.

**Boundary:** Do not claim universal all-plugin redaction. Additional third-party hook return values, historic stored diagnostic content, Image Refresh Queue `last_error` passed from other callers, Checkout remote response decoding and unenumerated WP_Error surfaces require a dedicated live secret-injection inventory and operator-reviewed cleanup. No automated history purge or schema/index work was introduced.

**Shared unresolved release gate:** Atomic Connection drain/disable with in-flight network, Portal payment correlation/idempotency, cross-table two-process Product/Variation ownership, Webhook vs Full/Media/Price/Category ordering, failed Upgrade/Unicode rollback with runtime blockers, 30K+30K performance, and freshly pinned Deep Test Suite. UX51 r8.3.177 remains frozen and unmodified. Backlog BJ/BK supplements BH/BI.


## PERF01/PERF02 (separate post-UX67 source-performance candidate 10.34.32)

Source-only performance work branched from frozen 10.34.30. `Work_Queue_Store::put()` verifies one strict persisted row, Parent Finalize enqueue uses that primitive below MAX_ITEMS with old cap eviction retained, and Variation Signature has a reverse winner-only index. This is not a replacement for UX67's r8.3.179 plugin gate and does not claim real WAMP/Portal/MySQL speedup; see `docs/releases/RELEASE_10.34.32.md` and backlog BL/BM.


## PERF03 / PERF04 (separate cumulative performance candidate 10.34.34)

PERF03 groups only the contiguous price-source fast-path metadata write/read-back and retains its final per-key durable comparison; ordering/payment/crash fences remain individually verified. PERF04 changes Order Recovery to an owner-scoped, bounded, fully validated first-page read and per-row payload-hash CAS cleanup; unreadable pages stop the order processor instead of pretending to be empty. No installed/real WAMP runtime sign-off, speed percentage, schema change or modifications to the 10.34.30 r8.3.179 Plugin Gate. See backlog BN/BO and `docs/releases/RELEASE_10.34.34.md`.


## PERF05 / PERF06 (separate cumulative source-performance candidate 10.34.36)

PERF05 keeps historical payload `queuedAt` selection while reducing Parent Finalize's repeated map reads/writes to one start snapshot, one final status snapshot and row-hash/token-fenced CAS mutations (drop/claim/finish). Batch-selected due list is sorted once; a newer enqueue is deferred safely. PERF06 moves ordinary Primary Order Submission enqueue, cleanup, listing and continuation off full-map operations: exact row upsert, bounded 16-row validated FIFO head, conservative Recovery deferral if hidden primary work may exist, token+payload-hash cleanup and strict end count. An unreadable primary queue blocks manual Retry instead of reporting a missing queue. No new schema, automatic Wallet retry, measured throughput claim or live 2-process MySQL/Portal approval. Backlog BP/BQ and `docs/releases/RELEASE_10.34.36.md`; frozen 10.34.30 r8.3.179 suite unchanged.


## PERF07 / PERF08 (separate cumulative source-performance candidate 10.34.38)

PERF07 removes the second Parent Finalize map materialization at the end of every bounded batch and the ordinary `has_due_work()` payload scan. A strict row-store aggregate reports pending/due from `available_at` and claim expiry columns; SQL failure stays fail-closed. The one start-of-batch payload map is deliberately retained because `queuedAt` ordering has no indexed SQL column and substituting `id/created_at/available_at` would be a semantic change. `get_status()` also retains its payload read for exact `oldestPendingAt`. PERF08 replaces Reprice's wide `LEFT JOIN` + `COUNT/SELECT DISTINCT` shape with direct Connection-owned product/variation branches, `EXISTS` metadata filters and `UNION ALL`, preserving global post-ID cursor semantics under existing unique Woo-object indexes. No DDL, measured speed claim or live optimizer proof. Backlog BR/BS and `docs/releases/RELEASE_10.34.38.md`; frozen 10.34.30 r8.3.179 gate remains independent.


## PERF09 / PERF10 (WordPress-only cross-source performance candidate 10.34.40)

Portal source was inspected as read-only evidence. PERF09 adds a scalar-safe client for the already-existing licensed `get-variants-count` route and uses it only as a one-way proof that a ProductUpdated Simple parent has **multiple** remote Variants; only `count > 1` skips the heavier Simple Variant snapshot and hands off to UpdateVariant. Count zero/one/error/malformed responses keep the old full authoritative path, so no destructive topology state is accepted from count alone. PERF10 closes an asymmetry in lightweight webhook retries: ProductUpdated already persisted the pulled payload on progress, while deferred UpdateVariant retained only the notification and could re-download immutable `EventWebhook.Data`. UpdateVariant now returns the normalized pulled payload to the existing DB/file progress persistence, so retries can remain local. No .NET code/API/schema change, no DDL and no measured speed claim. Backlog BT/BU and `docs/releases/RELEASE_10.34.40.md`; frozen 10.34.30/r8.3.179 gate remains independent.

## PERF11 / PERF12 (WordPress-only indexed queue-order candidate 10.34.42)

PERF11 advances Custom Storage schema to v13 with `mobo_work_queue.queued_at_us` and `queue_order(connection_id,queue_type,queued_at_us,id)`. Existing Parent Finalize rows are converted lazily in bounded slices. The payload `queuedAt` remains authoritative until a Connection's backfill is complete; every converted row validates dedupe identity and payload SHA-256, and CAS binds the write to the observed generation. A concurrent enqueue is accepted only after a fresh read proves payload/hash/index mirror consistency. No corrupt or unresolved row is treated as migrated.

PERF12 uses that mirror only after complete backfill, selecting a bounded due snapshot with `ORDER BY queued_at_us,id`. Before completion it retains the historical full-map/payload-order path, preserving crash/restart semantics during upgrade. Existing per-parent claim/hash/token CAS and Woo synchronization fences remain in place. The 5,000-item capacity edge intentionally keeps its old map/eviction path. Current payload `queuedAt` is second-resolution; `_us` is storage normalization, not newly invented timing precision.

Source/isolated evidence: cumulative PERF01–12 236 positive assertions, 26 deliberate unsafe mutations rejected, and 84 historical UX64–UX67 assertions pass on the cumulative source. Real dbDelta/index creation, MySQL query plans, interrupted v12→v13 migration, two-process enqueue during backfill and net throughput remain open. Backlog BV/BW and `docs/releases/RELEASE_10.34.42.md`; the frozen 10.34.30/r8.3.179 gate remains independent.



## REL01 — terminal Sync-Health evidence survives event retention (10.34.44)

Release Gate evidence from 2026-10-01 proved a cross-pipeline retention gap: the first run deleted old failed sync events, and the next baseline reclassified 12 still-incomplete products from terminal upstream-invalid to unowned convergence gaps. 10.34.44 mirrors qualifying terminal UpdateVariant version/error evidence into durable Sync Health before retention deletion and allows operational health to consume that current-boundary durable evidence after the event row is gone. No .NET or schema change. Runtime dual-Connection retention proof remains required.
