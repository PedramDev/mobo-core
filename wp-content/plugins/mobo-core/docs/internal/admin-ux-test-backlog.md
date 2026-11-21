# MoboCore Admin UX Regression Test Backlog

Baseline: Admin UX redesign through UX21, WooCommerce Products-list integration UX22, plus the cross-system Database Charset policy; this file is the authoritative backlog for regression tests that must be added before the final Release Gate.

Purpose:
- Record every behavior/bug contract discovered during the Admin UX/content audit.
- Do **not** treat `static verified` or `contract verified` during redesign as a substitute for permanent Test Suite coverage.
- Remove an item from this backlog only after an equivalent permanent automated test exists in the release Test Suite and its test identifier is recorded here.
- Runtime-only items stay pending until they are proven on the real WordPress/WooCommerce/Portal test environment.

Test kinds:
- **STATIC**: source/markup/manifest/CSS/JS contract.
- **CONTRACT**: isolated PHP/logic/data-state contract that does not require a full live deployment.
- **RUNTIME**: real WordPress/WooCommerce/DB/queue/Portal behavior.

---

## A. Dashboard / Overview — CLOSED

### UX-DASH-001 — Not-started Sync must not show fake progress
- Kind: STATIC + CONTRACT
- Regression contract: a Sync that has never started must render a not-started/idle state, not a numeric progress bar that implies work has begun.

### UX-DASH-002 — One authoritative Sync status resolver
- Kind: CONTRACT + RUNTIME
- Regression contract: Dashboard summary and detailed Sync status must resolve the same canonical state for idle/running/waiting/completed/failed/cancelled flows.

### UX-DASH-003 — Last-check timestamps remain source-correct
- Kind: CONTRACT
- Regression contract: central-service, license and webhook "last checked" values must come from their own authoritative timestamps and must not borrow another subsystem's timestamp.

### UX-DASH-004 — Duplicate product lifecycle
- Kind: CONTRACT + RUNTIME
- Regression contract: a real active duplicate is reported; quarantine/draft resolution removes the active warning; republishing a conflicting product makes the duplicate active again.

### UX-DASH-005 — Duplicate links identify actual Woo products
- Kind: STATIC + CONTRACT
- Regression contract: user-facing duplicate output links to WooCommerce product edit screens and does not expose two raw IDs as the primary UI.

### UX-DASH-006 — Action buttons lock during submit
- Kind: STATIC + browser/RUNTIME
- Regression contract: destructive/long-running dashboard actions disable their submit control and show an in-progress state to prevent duplicate submission.

### UX-DASH-007 — Clean state is explicit
- Kind: STATIC
- Regression contract: when there is no actionable warning, the page displays a clear "nothing needs attention" state rather than an empty warning area.

### UX-DASH-008 — Integrity checker filenames
- Kind: CONTRACT
- Regression contract: manifest/integrity errors identify the affected filename and never render numeric array keys such as `0|1|2` in place of filenames.

### UX-DASH-009 — Friendly license error vs raw diagnostics
- Kind: STATIC + CONTRACT
- Regression contract: the primary UI shows a manager-readable license/backend error while raw cURL/transport details remain inside technical diagnostics.

### UX-DASH-010 — Secondary diagnostics remain secondary
- Kind: STATIC
- Regression contract: Wallet/version/raw diagnostics are collapsed/secondary and do not displace actionable status in the primary view.

---

## B. Connection & License — CLOSED

### UX-CONN-001 — Connection states are distinct
- Kind: CONTRACT + STATIC
- Regression contract: `pending provisioning`, `active`, and `ready but disabled` are distinct states; pending must never be labelled simply disabled.

### UX-CONN-002 — Pending Connection token comes from the selected row
- Kind: CONTRACT + RUNTIME
- Regression contract: a pending durable Connection that owns a token displays/uses that row's token state and must not fall back to "not registered" because provisioning metadata is incomplete.

### UX-CONN-003 — Server-owned fields are read-only
- Kind: STATIC + request CONTRACT
- Regression contract: Provider, Source, Backend/API URL, Source Site and server-provisioned Display Name cannot be changed through the customer admin form or a forged settings POST.

### UX-CONN-004 — Customer-owned credentials stay Connection-scoped
- Kind: CONTRACT + RUNTIME
- Regression contract: license token and webhook security code are saved only to the selected Connection; one Connection must not inherit another Connection's credential.

### UX-CONN-005 — Backend failure classification
- Kind: CONTRACT
- Regression contract: connected, unavailable/transport, timeout, TLS failure, DNS failure, auth rejected (401/403), and unknown remain distinct; 401/403 must never be labelled "backend unavailable".

### UX-CONN-006 — License state parsing
- Kind: CONTRACT
- Regression contract: Active, Expired, Suspended, Revoked, Disabled, Unknown and explicit `isActive=false` map to the correct user-facing state.

### UX-CONN-007 — Pending/disabled Connection avoids unnecessary License API call
- Kind: CONTRACT + RUNTIME
- Regression contract: a Connection that is not runtime-ready does not make a misleading license request that can create a false backend/license error.

### UX-CONN-008 — Provisioning lifecycle
- Kind: RUNTIME
- Regression contract: token saved -> pending provisioning -> .NET metadata push -> ready/active state, preserving Connection identity throughout.

### UX-CONN-009 — Pending cancellation guard
- Kind: CONTRACT + RUNTIME
- Regression contract: only a non-default, disabled, unprovisioned pending Connection can be cancelled; default, enabled, provisioned or active rows are rejected server-side.

### UX-CONN-010 — Default Connection badge/ownership
- Kind: STATIC + CONTRACT
- Regression contract: the durable default row is visibly identified and default-specific compatibility mirrors do not cause another row to appear default.

### UX-CONN-011 — HTTP backend warning is advisory, not a block
- Kind: STATIC + CONTRACT
- Regression contract: HTTP Backend produces a security warning but remains usable for explicitly provisioned dev/local environments.

### UX-CONN-012 — Provisioning scheme fallback security
- Kind: CONTRACT + .NET RUNTIME
- Regression contract: HTTPS<->HTTP fallback occurs only for transport/TLS failure; HTTP application responses such as 401/409/500 do not trigger protocol fallback; SSL validation stays enabled; retry nonce/HMAC is regenerated.

---

## C. Product Settings — CLOSED

### UX-PROD-001 — Only-in-stock scope
- Kind: STATIC + CONTRACT
- Regression contract: `OnlyInStock` is described and applied as catalog retrieval filtering, not as a generic WooCommerce visibility/stock mutation switch.

### UX-PROD-002 — Source discount price semantics
- Kind: CONTRACT
- Regression contract: source `price/comparePrice` can create the expected regular/sale pair and the UI wording does not imply an unrelated Mobo discount engine.

### UX-PROD-003 — Source price vs Pricing Rules boundary
- Kind: STATIC + CONTRACT
- Regression contract: source price ingestion and subsequent pricing-rule calculation remain separate, documented stages.

### UX-PROD-004 — Safe Title/Slug defaults only for new installs
- Kind: CONTRACT + migration RUNTIME
- Regression contract: new installs default Title Auto Update and Slug Auto Update to OFF; an existing installation is not forcibly migrated to new values.

### UX-PROD-005 — New Connection inherits safe Product defaults
- Kind: CONTRACT
- Regression contract: a newly created Connection receives the intended safe title/slug defaults without changing existing Connection settings.

### UX-PROD-006 — Pending Connection cleanup leaves no orphan settings
- Kind: CONTRACT + RUNTIME
- Regression contract: cancelling/removing an eligible pending Connection removes its Connection-owned settings and does not leave rows that can later be inherited by another Connection.

### UX-PROD-007 — Cache interval backward compatibility
- Kind: CONTRACT
- Regression contract: legacy supported interval values continue to read/save correctly even though the new UI presents the simplified cache/warmup model.

---

## D. Categories — CLOSED

### UX-CAT-001 — Required Mapping dependency
- Kind: STATIC + CONTRACT
- Regression contract: Required Mapping is meaningful only when Mapping is enabled; turning Mapping off also disables/clears the required flag server-side.

### UX-CAT-002 — Required Mapping fail-closed
- Kind: CONTRACT + RUNTIME
- Regression contract: when required mapping is enabled, an unresolved source category does not silently fall through to an unintended Woo category.

### UX-CAT-003 — Fallback category semantics
- Kind: CONTRACT + STATIC
- Regression contract: the "fallback category" description matches the active policy and is not presented as an unconditional default when another mapping path wins.

### UX-CAT-004 — Current result is computed from real policy
- Kind: CONTRACT
- Regression contract: each row's "current result" shows where a product would actually be categorized now, considering manual mapping/fallback/current policy.

### UX-CAT-005 — Full source hierarchy disambiguates duplicate names
- Kind: CONTRACT + STATIC
- Regression contract: source categories render full `Parent › Child › ...` hierarchy so same-name children under different parents remain distinguishable.

### UX-CAT-006 — Search covers the complete active snapshot
- Kind: CONTRACT + browser/RUNTIME
- Regression contract: category search is not restricted to the current 50-row page and can find an active category anywhere in the current snapshot.

### UX-CAT-007 — Auto suggestion is non-destructive
- Kind: STATIC + CONTRACT
- Regression contract: automatic suggestion affects only the visible/current UI candidate and never saves a mapping without an explicit save.

### UX-CAT-008 — Source category retirement
- Kind: CONTRACT + RUNTIME
- Regression contract: a category removed from a complete valid source snapshot disappears from the active mapping list but its historical mapping is retained.

### UX-CAT-009 — Source category return
- Kind: CONTRACT + RUNTIME
- Regression contract: a previously retired source category that returns can recover/reuse its historical mapping.

### UX-CAT-010 — Partial snapshot must not retire categories
- Kind: CONTRACT + RUNTIME
- Regression contract: only a complete/valid replacement snapshot can retire missing categories; partial/invalid fetches preserve the previous active snapshot.

### UX-CAT-011 — Mapping save does not silently recategorize existing products
- Kind: CONTRACT + RUNTIME
- Regression contract: saving a mapping changes future assignment policy; existing products change only through the explicit apply/recategorize operation.

### UX-CAT-012 — Apply mapping to existing products
- Kind: RUNTIME
- Regression contract: the explicit action applies the current mapping policy to existing eligible products and records/report failures without pretending an incomplete run succeeded.

### UX-CAT-013 — Duplicate Woo category names under different parents
- Kind: CONTRACT + RUNTIME
- Regression contract: mapping resolution uses identity/hierarchy correctly when multiple Woo categories share the same display name under different parents.

---

## E. Pricing — CLOSED (UX12)

### UX-PRICE-001 — Variation-specific profit is highest pricing override
- Kind: CONTRACT + RUNTIME
- Regression contract: after valid identity/ownership resolution, Variation `mobo_additional_price` wins over Product/Parent percentage override and global pricing policy.

### UX-PRICE-002 — Parent/Product override fallback
- Kind: CONTRACT
- Regression contract: when no valid Variation-specific profit exists, the Product/Parent percentage override wins over the global policy.

### UX-PRICE-003 — Global policy fallback
- Kind: CONTRACT
- Regression contract: global pricing policy is used only when no higher-priority valid Variation or Product/Parent override exists.

### UX-PRICE-004 — Pricing precedence and policy-type resolver stay identical
- Kind: CONTRACT
- Regression contract: `Mobo_Core_Price_Calculator` and `effective_policy_type()` return the same precedence outcome for the same product/variation state.

### UX-PRICE-005 — Decimal Dynamic profit
- Kind: CONTRACT
- Regression contract: decimal values such as `12.5` remain 12.5 percent and are never digit-normalized into `125`.

### UX-PRICE-006 — Dynamic lower bounds are calculated, not user-owned
- Kind: STATIC + CONTRACT
- Regression contract: each Dynamic range lower bound derives from the prior range boundary; forged POST values cannot redefine a calculated lower bound.

### UX-PRICE-007 — Dynamic range chooses by raw source price
- Kind: CONTRACT
- Regression contract: range selection uses the authoritative raw source `price`, not `comparePrice` or an already transformed WooCommerce value.

### UX-PRICE-008 — Running Reprice locks policy save
- Kind: request CONTRACT + RUNTIME
- Regression contract: pricing policy cannot be saved while Reprice is running, even from a stale browser/forged POST.

### UX-PRICE-009 — Reprice generation has policy fingerprint
- Kind: CONTRACT
- Regression contract: each generation records enough policy identity to detect a policy change before continuing.

### UX-PRICE-010 — Paused/stopped checkpoint cannot resume under a new policy
- Kind: CONTRACT + RUNTIME
- Regression contract: changing pricing policy invalidates an old resumable generation/checkpoint so one generation cannot contain mixed formulas.

### UX-PRICE-011 — Candidate-count failure is unavailable, not zero
- Kind: CONTRACT
- Regression contract: failure to count Reprice candidates is represented as unavailable/error state and must not render `0 candidates` as factual data.

### UX-PRICE-012 — Historical failures are not active errors
- Kind: CONTRACT + STATIC
- Regression contract: cumulative failed-attempt counters remain diagnostic history and do not create an unresolved active-error banner after the current operation is healthy.

### UX-PRICE-013 — Idle Reprice does not poll continuously
- Kind: STATIC/browser
- Regression contract: idle/completed states do not keep unnecessary status polling active; active operations still refresh.

---

## F. Product Exclusions — CLOSED (UX13)

### UX-EXCL-001 — UI describes source-mutation exclusion, not product identity removal
- Kind: STATIC
- Regression contract: excluding a product is never described as making it non-Mobo; checkout/order identity semantics remain explicitly separate.

### UX-EXCL-002 — Canonical path normalization
- Kind: CONTRACT
- Regression contract: a supported absolute source URL and its equivalent path normalize to the same canonical exclusion key; query and trailing slash do not create a different key.

### UX-EXCL-003 — Leading-zero path identity is preserved
- Kind: CONTRACT
- Regression contract: `/0338` and `/338` remain distinct exact-path identities.

### UX-EXCL-004 — Duplicate exclusions collapse durably
- Kind: CONTRACT + RUNTIME
- Regression contract: duplicate/canonically-equivalent entries are stored once and the saved count matches runtime-consumed unique paths.

### UX-EXCL-005 — Invalid/non-product URL is rejected
- Kind: CONTRACT + request RUNTIME
- Regression contract: a non-empty entry that cannot produce a usable product path (including origin-only URL) fails the save instead of becoming a silent never-matching exclusion.

### UX-EXCL-006 — Connection-scoped durable save is verified
- Kind: CONTRACT + RUNTIME
- Regression contract: save failure/read-back mismatch reports an error; success is reported only when the selected Connection actually contains the normalized list.

### UX-EXCL-007 — Already-queued work rechecks exclusion at execution
- Kind: RUNTIME
- Regression contract: work queued before exclusion does not mutate the product if the execution path reaches it after the product becomes excluded.

### UX-EXCL-008 — Removing exclusion only re-enables future mutation
- Kind: CONTRACT + RUNTIME
- Regression contract: removing an exclusion permits future Sync/Repair/Reprice/etc. but does not claim to automatically replay changes missed during the excluded interval.

### UX-EXCL-009 — Exclusion covers the intended mutation surfaces
- Kind: CONTRACT + RUNTIME
- Regression contract: product sync, variant mutation, repair/reprice/recategorize/image mutation paths obey the exclusion policy while checkout/order identity logic remains available.

---

## G. Sync Health — CLOSED (UX14)

### UX-SHEALTH-001 — Unavailable audit can never become healthy
- Kind: CONTRACT + STATIC
- Regression contract: `operationalStatus=unavailable` is rendered as data unavailable/needs review and zeroed fallback counters are never interpreted as a healthy audit.

### UX-SHEALTH-002 — Failed/unavailable refresh does not replace last-good snapshot
- Kind: CONTRACT + RUNTIME
- Regression contract: only a complete usable operational result may become the last-good snapshot; query/audit failure preserves the prior last-good data and records a refresh error separately.

### UX-SHEALTH-003 — Invalid historical snapshot rejected on read
- Kind: CONTRACT
- Regression contract: a previously persisted snapshot that itself represents unavailable/invalid data is not trusted as last-good merely because it exists.

### UX-SHEALTH-004 — Snapshot age is visible and stale is distinct
- Kind: CONTRACT + STATIC
- Regression contract: the UI exposes when the last complete audit was taken and a stale snapshot is not presented as fresh/current data.

### UX-SHEALTH-005 — Excluded products are outside monitored denominator
- Kind: CONTRACT + RUNTIME
- Regression contract: intentionally excluded products are counted in their own `excluded from monitoring` bucket and cannot create active behind/failed health gaps.

### UX-SHEALTH-006 — Sync Health exclusion lookup is read-only
- Kind: CONTRACT
- Regression contract: observational Health scanning may check exclusion using existing identity evidence but must not write GUID->URL evidence or otherwise mutate product state merely by opening/running Health.

### UX-SHEALTH-007 — Normal runtime exclusion lookup still records evidence where intended
- Kind: CONTRACT + RUNTIME
- Regression contract: adding the read-only Health path must not disable evidence persistence used by normal mutation/runtime flows.

### UX-SHEALTH-008 — Recovery-only state is not manual-attention failure
- Kind: CONTRACT + STATIC
- Regression contract: when the only active condition is a valid automatic recovery owner/retry, the page reports recovering/in progress rather than "needs attention".

### UX-SHEALTH-009 — Empty monitored set is not healthy
- Kind: CONTRACT + STATIC
- Regression contract: zero current Mobo products, or all current products intentionally excluded, renders an explicit no-items-to-monitor state instead of a green health conclusion.

### UX-SHEALTH-010 — Operational accounting must balance
- Kind: CONTRACT + RUNTIME
- Regression contract: operational buckets plus excluded/current accounting reconcile with the Product Map denominator; non-zero delta is an internal health error.

### UX-SHEALTH-011 — Actionable samples link to Woo products
- Kind: STATIC + CONTRACT
- Regression contract: actionable health buckets expose bounded product samples with direct WooCommerce edit links while raw GUID/error details remain technical diagnostics.

---

## H. Site Health — CLOSED (UX15)

### UX-HEALTH-001 — DISABLE_WP_CRON is not proof of a healthy real Cron
- Kind: CONTRACT + RUNTIME
- Regression contract: `DISABLE_WP_CRON=true` only proves the internal WP-Cron path is disabled. The primary Site Health state must also consider real Cron hit/success/overdue evidence and must not report the scheduler healthy when no real run has ever been observed.

### UX-HEALTH-002 — Health local authentication readiness is Connection/X-SEC aware
- Kind: CONTRACT + REST RUNTIME
- Regression contract: the UI must not hard-code `/health` authentication as ready. A disabled/unresolved Connection, missing X-SEC, or malformed X-SEC is not locally authentication-ready; a valid selected Connection credential is required.

### UX-HEALTH-003 — Multi-Connection Health authentication remains fail-closed
- Kind: REST RUNTIME
- Regression contract: Health uses the same inbound Connection ownership rules as other protected REST routes. When multiple Connections are enabled, ambiguous/missing WebHookId ownership must not authenticate against another Connection's X-SEC.

### UX-HEALTH-004 — Health and Heartbeat have separate semantics
- Kind: STATIC + CONTRACT + REST RUNTIME
- Regression contract: GET `/health` is read-only reporting and does not run the worker; POST `/heartbeat` is the separate no-cache recovery path that executes a bounded slice of the shared runner. UI/documentation must never claim Health polling itself performs Recovery.

### UX-HEALTH-005 — Heartbeat result does not define Health reachability
- Kind: CONTRACT + REST RUNTIME
- Regression contract: a worker warning/failure returned by Heartbeat does not make the authenticated Health endpoint unreachable; Health and Heartbeat status/lifecycle remain independently observable.

### UX-HEALTH-006 — Database schema readiness affects primary health
- Kind: CONTRACT + STATIC
- Regression contract: `databaseSchemaReady=false` prevents the primary Site Health badge from being green and produces an actionable schema/upgrade warning. Raw schema error text stays in technical diagnostics.

### UX-HEALTH-007 — Supported runtime versions affect primary health
- Kind: CONTRACT
- Regression contract: the health classification enforces the plugin-declared minimums WordPress 5.8+, PHP 7.4+ and WooCommerce 8.2+; an unknown/missing WooCommerce version is not presented as compatible.

### UX-HEALTH-008 — Current failed queues affect primary health
- Kind: CONTRACT + RUNTIME
- Regression contract: active failed Webhook jobs and active failed Image jobs prevent a green Site Health state. Pending Webhooks alone remain informational and do not equal failure.

### UX-HEALTH-009 — Image environment readiness affects primary health
- Kind: CONTRACT + RUNTIME
- Regression contract: unwritable uploads or absence of both supported image engines (GD/Imagick) is actionable and prevents a green Site Health state.

### UX-HEALTH-010 — Storage hard failures cannot be hidden by non-blocking mode
- Kind: CONTRACT + RUNTIME
- Regression contract: when account quota/inode data explicitly proves exhaustion, or a cached real write probe explicitly failed, primary Site Health reports a storage failure even though the normal admin report is non-blocking. Unknown/deferred probe data must remain unknown rather than being converted to success.

### UX-HEALTH-011 — Account quota and filesystem capacity stay distinct
- Kind: STATIC + CONTRACT
- Regression contract: `accountDiskFreePercent` is labelled as hosting-account quota; `filesystemDiskFreePercent` is labelled separately as server filesystem capacity and must not be represented as the customer's hosting quota.

### UX-HEALTH-012 — Read-only Site Health has no fake Save action
- Kind: STATIC + request CONTRACT
- Regression contract: the Site Health diagnostics surface contains no settings Save button/form. Legacy/stale POST compatibility may remain server-side, but opening the current Health page must not imply there are editable Health settings.

### UX-HEALTH-013 — Clean and unavailable states remain explicit
- Kind: STATIC + CONTRACT
- Regression contract: when all checked prerequisites are healthy, a clear no-action-needed message is shown; unavailable account-quota data is separately described and must not be silently presented as a measured healthy percentage.

### UX-HEALTH-014 — Local Health readiness is not remote network reachability
- Kind: STATIC + CONTRACT + REST RUNTIME
- Regression contract: the admin badge reports only local Connection/authentication readiness. It must not claim Portal network reachability without an actual remote request. When the durable Connection table exists, a missing/disabled selected row or a not-yet-ready multi-Connection ownership migration prevents local Health-auth readiness even when an X-SEC string is present.

---


## I. Image Refresh — CLOSED (UX16)

### UX-IMGREF-001 — Repair prerequisite is explicit and independent
- Kind: STATIC + CONTRACT + RUNTIME
- Regression contract: Image Refresh never starts, retries or cancels Product Repair. The page clearly states that a completed Repair is a prerequisite and directs the administrator to the Dashboard when Repair is still required.

### UX-IMGREF-002 — Rejected start before Repair has no workflow-generation side effects
- Kind: CONTRACT + RUNTIME
- Regression contract: a Start request while Repair is incomplete is rejected before generation creation, queue-failure retry, preflight reset, orphan-cleanup reset, destructive-switch changes or any other Image Refresh workflow mutation. Diagnostic last-result recording is allowed.

### UX-IMGREF-003 — Rejected start is not reported as success
- Kind: CONTRACT + request RUNTIME
- Regression contract: when the prerequisite Repair is incomplete, `start()`/Admin POST reports a warning/failure outcome and must not show a success notice implying that Image Refresh began.

### UX-IMGREF-004 — Start is fail-closed for unavailable Connection/runtime ownership
- Kind: CONTRACT + request RUNTIME
- Regression contract: with the durable Connection table present, a disabled, unprovisioned, unresolved or multi-Connection-runtime-not-ready selected Connection cannot start Image Refresh; a forged/stale form is rejected server-side.

### UX-IMGREF-005 — Missing Image Refresh schema/components are unavailable, not zero/ready
- Kind: STATIC + CONTRACT + RUNTIME
- Regression contract: missing Image Refresh/Orphan Cleanup classes or tables cannot render as an empty healthy queue or an available Start action; the page reports unavailable schema/components and the POST is blocked.

### UX-IMGREF-006 — Completed with retained/quarantined items is not green success
- Kind: CONTRACT + STATIC + RUNTIME
- Regression contract: `completed` with queue failures/quarantine, subsize quarantine, Stage-7 blocked/errors or orphan-cleanup failures renders a warning-style stable-with-retained-items state rather than a clean-success state.

### UX-IMGREF-007 — Clean completion is distinct from stable completion with exceptions
- Kind: CONTRACT
- Regression contract: a clean completed cycle has no current queue quarantine, subsize quarantine, Stage-7 blocked/errors or orphan failures; only that state may render `complete and stable`/no-known-issues messaging.

### UX-IMGREF-008 — Orphan cleanup failures participate in completion quality
- Kind: CONTRACT + RUNTIME
- Regression contract: failed orphan families are retained safely, remain visible in technical outcome counters, and are included in the final completion message instead of disappearing behind `completed`.

### UX-IMGREF-009 — Possibly-stuck worker is surfaced
- Kind: CONTRACT + STATIC + RUNTIME
- Regression contract: `batchPossiblyStuck=true` must override the ordinary running label and produce an actionable Worker/Cron warning; the UI must not present a stale open batch as normal active progress.

### UX-IMGREF-010 — Waiting/backoff and blocking failure are separate from normal running
- Kind: CONTRACT + STATIC
- Regression contract: waiting-retry/worker/pipeline states render as waiting for automatic continuation, while environment/component/automation failures render as blocked/retrying warning states; neither is silently labelled simply healthy/running.

### UX-IMGREF-011 — Byte-size delta can show both reduction and increase
- Kind: CONTRACT + STATIC
- Regression contract: generation `bytesBefore`/`bytesAfter` produces `reduction`, `increase`, `no change`, or `not measured`. A larger refreshed generation must never be clamped to `0 B savings`.

### UX-IMGREF-012 — Current-stage progress is not fake overall workflow progress
- Kind: STATIC + CONTRACT
- Regression contract: the progress percentage describes only the current bounded stage. Idle/completed states do not show misleading `step 0 of 9` or a fake numeric progress bar; active stages without a trustworthy denominator render a non-numeric in-progress state.

### UX-IMGREF-013 — Queue labels match queue counter semantics
- Kind: CONTRACT + STATIC
- Regression contract: the primary remaining-queue value is explicitly `pending/processing` because `get_status().pending` includes both; queue `failed` is labelled queue quarantine/terminal failure rather than a generic failure counter.

### UX-IMGREF-014 — Live polling runs only while useful
- Kind: STATIC + browser/RUNTIME
- Regression contract: Admin live-status polling runs while Image Refresh is active or its prerequisite Repair is actively progressing/waiting. Idle and completed pages do not poll indefinitely.

### UX-IMGREF-015 — Live-status rescue remains bounded and lock-safe
- Kind: CONTRACT + RUNTIME/concurrency
- Regression contract: an authenticated stale Admin status poll may run at most one bounded rescue tick only for an active non-passive workflow, and the authoritative Image Refresh + shared mutation-pipeline locks prevent overlap with Cron/Self Runner/Recovery.

### UX-IMGREF-016 — Destructive cleanup is disclosed before Start
- Kind: STATIC + browser/RUNTIME
- Regression contract: before a Start action the page clearly states that, after Safety Audit, proven replaced attachments/orphan families can be deleted automatically, recommends a recoverable DB/uploads backup, and requires an explicit submit confirmation.

### UX-IMGREF-017 — Safety audit still fail-closes deletion
- Kind: CONTRACT + RUNTIME
- Regression contract: automatic cleanup does not weaken per-attachment/per-family safety checks. Ambiguous, referenced, invalid or otherwise unsafe media is retained rather than deleted merely because the one-click workflow is active.

### UX-IMGREF-018 — Full-source refresh generation semantics
- Kind: CONTRACT + RUNTIME
- Regression contract: a new Image Refresh generation re-evaluates Mobo images even when source URL is unchanged and an existing WebP appears healthy, using generation/no-cache identity while preserving duplicate-download reuse inside the same generation.

### UX-IMGREF-019 — One-file failure does not strand independent images
- Kind: CONTRACT + RUNTIME
- Regression contract: retry/backoff is row-scoped; exhausted rows become quarantined/failed while independent image jobs and later workflow stages continue. A future explicit refresh cycle can give retained failed rows a new retry budget.

### UX-IMGREF-020 — Generation statistics are generation-scoped
- Kind: CONTRACT
- Regression contract: fresh-download count, reuse count and byte totals belong to the current/last displayed generation and are reset only when a new verified cycle is actually started; a rejected prerequisite Start must not replace those statistics.

### UX-IMGREF-021 — Shared Media ownership/fallback safety remains unchanged
- Kind: CONTRACT + RUNTIME
- Regression contract: when Shared Media is configured without download fallback, Image Refresh does not silently write a private local fallback because the mount/worker is unavailable; storage ownership remains with the central media path.

### UX-IMGREF-022 — Current documentation matches one-click runtime policy
- Kind: STATIC
- Regression contract: current README/admin documentation describes one-click autonomous Image Refresh with independent pre-completed Product Repair, automatic retry/backoff and automatic safety-gated cleanup; it must not expose obsolete manual 1-9 stage buttons/approval workflow as current instructions.

---

## J. Cron / Worker — CLOSED (UX17)

### UX-CRON-001 — Scheduler hit is distinct from Worker execution
- Kind: CONTRACT + RUNTIME
- Regression contract: an authenticated real Cron invocation records the external scheduler hit before attempting the shared dispatcher lease. A busy dispatcher may skip that slice, but must not make an actually-firing host Cron appear overdue.

### UX-CRON-002 — Scheduler hit is not proof of healthy Runner
- Kind: CONTRACT + STATIC
- Regression contract: a fresh `lastHitAt` cannot by itself produce a green Cron/Worker state. The primary UI also requires a fully healthy scheduled Runner result and must surface partial/failed Runner outcomes separately.

### UX-CRON-003 — Scheduled result provenance is not overwritten by other Runner sources
- Kind: CONTRACT + RUNTIME
- Regression contract: Self Runner, Heartbeat and administrator manual slices may update shared Runner diagnostics, but cannot overwrite the authoritative `schedulerLastResult` used to assess real Cron execution.

### UX-CRON-004 — Last Cron success means `status=ok`
- Kind: CONTRACT
- Regression contract: `mobo_core_real_cron_last_success_at` advances only after an externally scheduled Mobo Cron slice completes with `status=ok`. `partial`, lock loss, exception, admin-manual and Self Runner slices must not advance it.

### UX-CRON-005 — Partial Runner is not success
- Kind: CONTRACT + STATIC + RUNTIME
- Regression contract: a slice containing failed stages may retain transport/backward-compatible `success=true`, but `status=partial` is rendered as an active warning/error and never as a fully successful Cron execution.

### UX-CRON-006 — Site Health inherits partial-Runner semantics
- Kind: CONTRACT
- Regression contract: the Site Health page consumes scheduled Runner provenance and treats a scheduled `partial` result as unhealthy evidence rather than relying only on the legacy boolean `success`.

### UX-CRON-007 — Self Runner enabled is not Self Runner healthy
- Kind: CONTRACT + STATIC
- Regression contract: the Cron summary distinguishes configuration enabled from observed execution health. Missing token, request failure, dispatch-timeout backoff or non-OK last Self Runner result must remain visible.

### UX-CRON-008 — Self Runner healthy timestamp requires `status=ok`
- Kind: CONTRACT
- Regression contract: `mobo_core_self_runner_last_run_success_at` advances only for a genuinely healthy Self Runner result with `status=ok`; partial results do not overwrite healthy history.

### UX-CRON-009 — Dispatch/backoff state remains actionable
- Kind: CONTRACT + RUNTIME
- Regression contract: pending dispatch, bounded backoff and loopback/dispatch failure are distinguishable states. A configured Self Runner in backoff is not presented simply as `active`.

### UX-CRON-010 — Manual Worker action is explicitly destructive
- Kind: STATIC + browser/RUNTIME
- Regression contract: the admin support action is labelled as a real Worker slice, warns that it can mutate live Webhook/Product/Variation/Image/Repair queues, and requires explicit confirmation.

### UX-CRON-011 — Manual Worker action does not prove host Cron
- Kind: STATIC + CONTRACT
- Regression contract: an administrator-triggered slice does not update real-scheduler hit/success provenance and the resulting notice explicitly states that it does not prove the cPanel/DirectAdmin Cron Job is configured.

### UX-CRON-012 — Manual partial result produces warning
- Kind: CONTRACT + browser/RUNTIME
- Regression contract: admin manual execution reports the authoritative result status/rounds/failed stages; `partial` produces a warning notice and hard failures produce an error rather than generic success based only on the legacy boolean.

### UX-CRON-013 — Performance presets do not change scheduler expectation
- Kind: STATIC + browser/CONTRACT
- Regression contract: VPS/strong/medium/weak presets tune Runner capacity/timeouts only. They do not change `mobo_core_real_cron_expected_interval_seconds` and cannot silently pretend the host schedule changed.

### UX-CRON-014 — Expected interval is monitoring configuration
- Kind: STATIC + CONTRACT + RUNTIME
- Regression contract: the page states that expected interval does not create/change a host Cron Job and must match the actual external Mobo Cron schedule (for example 60/120/300 seconds) for overdue detection to be meaningful.

### UX-CRON-015 — `DISABLE_WP_CRON` does not prove external wp-cron.php
- Kind: STATIC + CONTRACT
- Regression contract: `DISABLE_WP_CRON=true` is described only as disabling request-triggered WP-Cron. The UI must not claim it proves an external `wp-cron.php` task exists or is running.

### UX-CRON-016 — Cron/Worker token ownership is described correctly
- Kind: STATIC
- Regression contract: the Cron secret help states that both Self Runner/HTTP worker dispatch and HTTP Cron fallback use the Cron/Worker token through `X-SEC`, while direct PHP CLI `mobo-cron.php` does not require it.

### UX-CRON-017 — Admin UI never renders token-bearing Cron URL
- Kind: STATIC + security CONTRACT
- Regression contract: the Cron page displays only the token-free endpoint. It recommends `X-SEC` and does not place a query-token URL into a copyable field, browser history or normal page source.

### UX-CRON-018 — Remote Cron status does not disclose Cron token
- Kind: security CONTRACT + RUNTIME
- Regression contract: `/cron/status`, authenticated by the separate Connection security credential, never returns the legacy `cronUrl` containing the Cron/Worker token. Token-free `cronEndpoint` remains available.

### UX-CRON-019 — Legacy query-token Cron compatibility remains bounded
- Kind: CONTRACT + RUNTIME
- Regression contract: existing authenticated Cron run integrations that still send the legacy query/body `token` continue to work, while new UI/documentation prefers `X-SEC`; no SSL verification weakening is introduced.

### UX-CRON-020 — CLI Cron remains token-independent
- Kind: CONTRACT + RUNTIME
- Regression contract: direct PHP CLI execution of `mobo-cron.php` remains the primary host-Cron path, requires no HTTP token, records scheduler hits and uses the same shared dispatcher/Runner without creating a parallel processing implementation.

### UX-CRON-021 — Legacy last-success cannot bootstrap scheduled health
- Kind: CONTRACT + migration RUNTIME
- Regression contract: pre-UX17 `mobo_core_real_cron_last_success_at` is not trusted as proof of a healthy scheduled run until the corrected scheduler-provenance model has observed a real scheduler result. On the first corrected scheduled result, a partial/error result clears legacy green evidence; an `ok` result establishes the new healthy timestamp. Site Health and Cron/Worker must remain non-green while scheduler provenance is still unverified.

### UX-CRON-022 — Primary Cron summary stays operator-facing
- Kind: STATIC UX
- Regression contract: the first-level Cron/Worker summary explains scheduler arrival, processing health, automatic delay and required action in user-facing language. Internal terms such as raw stage names, dispatcher internals and backoff diagnostics remain in the technical details rather than being required to understand the primary state.

---

## K. Order Submission / Checkout Validation — CLOSED (UX18)

### UX-ORDER-001 — Primary readiness does not depend on legacy `checkoutActive`
- Kind: STATIC + CONTRACT
- Regression contract: automatic-order readiness must not use `Mobo_Core_Address_Mapping::checkoutActive` as an address prerequisite. Checkout field ownership stays native WooCommerce; readiness uses the central Location Resolver contract instead.

### UX-ORDER-002 — Disabled automatic submission is neutral, not green
- Kind: STATIC UX
- Regression contract: when automatic Mobo order submission is disabled, the primary badge is `غیرفعال`/neutral and must not imply that a live remote purchase path has been proven healthy.

### UX-ORDER-003 — Only all-Mobo single-Connection `processing` orders are eligible
- Kind: CONTRACT + WooCommerce RUNTIME
- Regression contract: automatic submission is queued only when the WooCommerce order is in `processing`, every line item is Mobo-owned, and all Mobo lines resolve to exactly one Connection.

### UX-ORDER-004 — Mixed orders fail closed with no partial Mobo submission
- Kind: CONTRACT + RUNTIME
- Regression contract: an order containing both Mobo and non-Mobo line items never queues or submits only its Mobo subset. No remote cart/payment side effect starts for a mixed order.

### UX-ORDER-005 — Multi-Connection orders fail closed
- Kind: CONTRACT + RUNTIME
- Regression contract: an order whose Mobo lines belong to more than one Connection is not submitted to any Source and is marked/annotated as non-submittable instead of choosing Default/first Connection.

### UX-ORDER-006 — Shipping resolver independently rejects mixed orders
- Kind: CONTRACT
- Regression contract: `Mobo_Core_Remote_Shipping_Methods::resolve_shipping_id_for_order()` returns a blocking error for `mixed` even if it is called outside the normal higher-level order-scope guard.

### UX-ORDER-007 — Central Location Resolver is the authoritative order-address path
- Kind: CONTRACT + RUNTIME
- Regression contract: order submission sends native WooCommerce country/state/city to the Backend/provider Location Resolver. Local manual country/state/city mapping and city-cache counts are not prerequisites for remote submission.

### UX-ORDER-008 — Persian WooCommerce city integration remains optional
- Kind: STATIC + browser/RUNTIME
- Regression contract: the admin page does not claim Persian WooCommerce or “فعال‌سازی شهرهای ایران” is mandatory for automatic order submission. Optional city-cache compatibility may remain without taking ownership of Checkout fields.

### UX-ORDER-009 — One readiness contract drives both UI and save validation
- Kind: CONTRACT
- Regression contract: the first-level automatic-order readiness and `validate_mobo_order_submission_required_config()` consume the same authoritative checks so the UI cannot show ready while Save rejects the same configuration, or vice versa.

### UX-ORDER-010 — Connection must be enabled, provisioned and runtime-owned
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: automatic-order readiness requires the selected Connection to be enabled and provisioned with Source/Provider/API metadata; when multiple Connections are enabled, durable ownership proof must be ready before the path is considered locally ready.

### UX-ORDER-011 — Provider must support deterministic replace-cart synchronization
- Kind: CONTRACT
- Regression contract: automatic order submission is not locally ready unless the provisioned Provider adapter exists in the build and reports the deterministic `replace` cart-sync strategy required by the checkout implementation.

### UX-ORDER-012 — Mobo login credentials are required for automatic submission
- Kind: CONTRACT + RUNTIME
- Regression contract: automatic-order readiness fails when the Connection-scoped Mobo username or stored password is absent; a blank password field on Save preserves an existing stored secret rather than erasing it.

### UX-ORDER-013 — Sender identity is required
- Kind: CONTRACT
- Regression contract: sender name and sender mobile are required by readiness because the runtime order-contact payload rejects submission when either value is empty.

### UX-ORDER-014 — Current remote shipping catalog must exist
- Kind: CONTRACT + RUNTIME
- Regression contract: a configuration with no current cached Mobo shipping methods cannot be shown as locally ready for automatic submission. A fresh remote sync may satisfy this requirement; unavailable/empty data is not treated as factual readiness.

### UX-ORDER-015 — Every active WooCommerce shipping method must be mapped
- Kind: CONTRACT + WooCommerce RUNTIME
- Regression contract: each active method instance in every effective WooCommerce Shipping Zone, including fallback zone 0 when it has active methods, has exactly one Mobo shipping mapping before automatic submission is locally ready.

### UX-ORDER-016 — Retired/stale shipping IDs invalidate readiness
- Kind: CONTRACT
- Regression contract: a saved WooCommerce→Mobo mapping whose selected `shipping_id` is absent from the current authoritative Mobo shipping snapshot is reported as stale and does not count as mapped/ready.

### UX-ORDER-017 — Invalid automatic-order configuration fails closed on Save
- Kind: CONTRACT + admin RUNTIME
- Regression contract: if Save leaves automatic order submission enabled while the authoritative readiness contract fails, Mobo Core persists the other editable configuration but forces `mobo_core_mobo_order_submission_enabled=0` and auto-complete off, and returns an explicit error notice. An invalid enable must never remain live after the error redirect.

### UX-ORDER-018 — External Checkout Validation is visible and reversible
- Kind: STATIC + browser/RUNTIME
- Regression contract: the legacy/runtime-supported external validation toggle, URL, timeout and failure behavior are visible in the Checkout settings when the master validation group is enabled; an already-enabled legacy setting can be discovered and disabled from the UI.

### UX-ORDER-019 — External validation discloses its data-sharing boundary
- Kind: STATIC + security CONTRACT
- Regression contract: the UI explains that cart/site payload is POSTed to the configured validation URL. Portal Token/X-SEC/signature headers are attached only when the destination exactly matches the provisioned Portal origin; arbitrary third-party validation URLs receive no Portal credentials.

### UX-ORDER-020 — External validation failure mode is explicit
- Kind: CONTRACT + RUNTIME
- Regression contract: `allow` means transport/invalid-response failures fail open, while `block` means they can stop Checkout. The selected behavior is editable and preserved without silently changing semantics.

### UX-ORDER-021 — Checkout master switch scopes optional validators only
- Kind: CONTRACT
- Regression contract: disabling the master pre-payment validation switch disables local-stock, optional Mobo-cart and external validators, but does not disable the independent mandatory cart rebuild/verification executed by automatic Mobo order submission.

### UX-ORDER-022 — Historical validation result is labelled as Mobo-cart-specific
- Kind: STATIC + CONTRACT
- Regression contract: `mobo_core_checkout_last_validation_*` and `lastResult` are presented as history of the Mobo shared-cart validation path, not as a generic success/failure record for Local Stock, External Validation or all Checkout logic.

### UX-ORDER-023 — Checkout page does not fabricate Health endpoint readiness
- Kind: STATIC
- Regression contract: the Order/Checkout page contains no hard-coded “Endpoint سلامت: فعال و آماده پاسخ” claim. Health/Heartbeat reachability belongs to Site Health and must be measured there.

### UX-ORDER-024 — Local readiness does not claim live Source reachability
- Kind: STATIC + RUNTIME proof
- Regression contract: “پیکربندی آماده” means local configuration prerequisites are satisfied. The UI explicitly does not claim that live Source login/cart/checkout/payment will succeed; production reachability remains a runtime proof.

### UX-ORDER-025 — Automatic submission trigger is `processing`
- Kind: STATIC + CONTRACT
- Regression contract: the page/documentation states that automatic submission is triggered for an eligible WooCommerce order entering `processing`; non-processing queued remnants are terminally aborted before remote side effects start.

### UX-ORDER-026 — Automatic submission performs its own mandatory cart verification
- Kind: CONTRACT + RUNTIME
- Regression contract: the optional “بررسی سبد موبو” Checkout toggle may remain off, but an eligible automatic order still uses the hard order-submission cart clear/rebuild/snapshot comparison before Checkout/Wallet operations.

### UX-ORDER-027 — Uncertain payment state cannot be blindly retried
- Kind: CONTRACT + RUNTIME
- Regression contract: stale-running/uncertain orders and independent payment-guard evidence block normal retry. Administrator retry is allowed only after explicit confirmation that no Source order/payment exists, and clearing the uncertainty guard occurs only while the per-order lease is owned.

### UX-ORDER-028 — Already-submitted order is idempotently suppressed
- Kind: CONTRACT + RUNTIME
- Regression contract: durable `_mobo_order_submitted`/Mobo order identity prevents a second purchase attempt from queue, worker or admin retry paths.

### UX-ORDER-029 — Auto-complete is bounded to safe post-payment state
- Kind: CONTRACT + RUNTIME
- Regression contract: WooCommerce auto-complete happens only after a successful all-Mobo submission while the local order still has the expected `processing` state and no post-payment business divergence. Concurrent cancellation/on-hold/change suppresses the status transition and marks review instead.

### UX-ORDER-030 — Queue processing re-checks policy and Connection ownership
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: before every queued order, the worker re-checks the feature enable flag, order status, item scope and effective Connection ownership. Work owned by another Connection is not mutated under Default/current credentials.

### UX-ORDER-031 — Current documentation matches fail-closed order policy
- Kind: STATIC
- Regression contract: current README, shipping guide, privacy/terms and administrator copy do not describe the retired policy that partially submitted Mobo lines from mixed orders or required local address mapping/Persian-Woo city activation for submission.

### UX-ORDER-032 — Conditional Checkout UI reads the current radio-toggle contract
- Kind: STATIC + browser/RUNTIME
- Regression contract: dependency JavaScript reads the checked radio by `name` for Design-System boolean controls, not a retired direct element ID. Master Checkout, Mobo Cart, External Validation and Auto Order groups render correctly on initial load and react immediately when either radio option changes.

---

## L. SMS / Wallet Alerts — CLOSED (UX19)

### UX-SMS-001 — Order SMS is store-wide; Wallet Alert is Connection-scoped
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: `mobo_core_sms_notifications_enabled` and all order scenario enable/recipient/template settings remain global installation settings, while wallet configuration/history/test/one-shot keys resolve only through the selected runtime Connection. Switching Connection changes Wallet state but not Order-SMS rules.

### UX-SMS-002 — Primary readiness is configuration-based, not master-toggle-based
- Kind: STATIC + CONTRACT
- Regression contract: an enabled Order-SMS or Wallet master switch alone cannot produce a green “ready” state. Local SMS API availability, enabled scenario completeness, wallet Provider support, recipient/template readiness and one-shot/uncertain state participate in classification.

### UX-SMS-003 — Missing Persian WooCommerce SMS API is unavailable, not silently ready
- Kind: CONTRACT + WordPress RUNTIME
- Regression contract: missing/throwing `PWSMS()` or a client without `send_sms()` never fatals, never reports transport ready, and clearly states that saved configuration cannot currently send.

### UX-SMS-004 — Gateway discovery is informational only
- Kind: CONTRACT
- Regression contract: reading a gateway label may fail without breaking the page; the presence/name of a gateway must never be treated as proof of handset delivery.

### UX-SMS-005 — Wallet threshold zero is valid
- Kind: STATIC + CONTRACT
- Regression contract: threshold `0` is stored, rendered and compared as a real configured threshold and is never relabelled as “not set”.

### UX-SMS-006 — Simple SMS booleans use the Design-System radio contract
- Kind: STATIC + browser/RUNTIME
- Regression contract: Order-SMS master, scenario toggles and Wallet Alert toggle use the normal `bool_field` radio behavior and do not instantiate the searchable Mobo picker/Select2 for binary choices.

### UX-SMS-007 — Order SMS timing is Checkout order creation, not payment success
- Kind: STATIC + WooCommerce RUNTIME
- Regression contract: Classic and Blocks hooks send the configured order-type notification when Checkout processes/creates the WooCommerce order; Admin copy/documentation must not imply that it waits for successful payment.

### UX-SMS-008 — Order scenario classification uses current Mobo identity
- Kind: CONTRACT + WooCommerce RUNTIME
- Regression contract: `non_mobo`, `mobo_only` and `mixed` are derived from actual line-item Mobo identity through the canonical product-identity policy; a mixed order remains a distinct SMS scenario even though automatic Mobo submission itself is fail-closed for mixed orders.

### UX-SMS-009 — Order-SMS master and scenario switches compose correctly
- Kind: CONTRACT
- Regression contract: the global master OFF suppresses all MoboCore order-SMS scenarios; master ON with a scenario OFF suppresses that scenario only; Wallet Alert remains independent from this master.

### UX-SMS-010 — Enabled Order-SMS scenario requires recipients and template
- Kind: CONTRACT + admin RUNTIME
- Regression contract: an enabled scenario with blank normalized recipients or blank template is not counted ready; Save returns an explicit warning and runtime refuses the send without fabricating success.

### UX-SMS-011 — Order recipient normalization and customer placeholder
- Kind: CONTRACT
- Regression contract: comma/newline lists, Persian/Arabic digits, Iranian mobile prefixes and `{customer_mobile}` resolve through the existing PWSMS/customer normalization contract; invalid/empty results fail before remote dispatch.

### UX-SMS-012 — Order template rendering preserves PWSMS + Mobo placeholders
- Kind: CONTRACT
- Regression contract: Persian WooCommerce SMS shortcodes and Mobo placeholders (`mobo_order_type`, label and item counts) are rendered in the intended order and an empty/exceptional render fails before gateway dispatch.

### UX-SMS-013 — Order SMS persists replay fence before gateway side effect
- Kind: CONTRACT + WooCommerce RUNTIME
- Regression contract: immediately before `send_sms()`, a per-order/per-scenario durable fence is saved and read back. If that fence cannot be proven, no gateway call is allowed.

### UX-SMS-014 — Existing Order-SMS fence suppresses automatic replay
- Kind: CONTRACT + RUNTIME
- Regression contract: if a per-order/scenario replay fence already exists without a final sent marker, later Checkout/hook invocations do not resend automatically.

### UX-SMS-015 — Successful Order SMS commits sent marker and clears fence
- Kind: CONTRACT + WooCommerce RUNTIME
- Regression contract: only after explicit gateway success is the durable sent marker written and replay fence cleared; a fresh order read sees the final sent marker and all later hooks are idempotently suppressed.

### UX-SMS-016 — Order post-send commit failure remains fail-closed
- Kind: failure-injection RUNTIME
- Regression contract: if the gateway accepts an Order SMS but final Woo order-meta persistence throws/fails, the pre-existing durable replay fence remains, an uncertainty error is returned, and automatic replay cannot occur.

### UX-SMS-017 — Gateway/error result does not create an automatic Order-SMS retry queue
- Kind: CONTRACT + RUNTIME
- Regression contract: gateway failure/ambiguity is recorded in an Order Note and the replay fence prevents a later automatic duplicate. MoboCore does not enqueue an independent Order-SMS retry job merely because the gateway later recovers.

### UX-SMS-018 — Classic/Blocks duplicate hooks remain serialized
- Kind: CONTRACT + concurrent WooCommerce RUNTIME
- Regression contract: per-order runtime locking plus durable sent/fence evidence prevents simultaneous Classic/Store-API paths from issuing duplicate SMS requests for the same order/scenario.

### UX-SMS-019 — Wallet Provider capability gate
- Kind: CONTRACT + multi-provider RUNTIME
- Regression contract: Wallet monitoring runs only when the current runtime Provider exposes a non-empty `wallet_balance` endpoint. Unsupported Providers are reported as unsupported and do not accumulate false wallet-balance operational errors.

### UX-SMS-041 — Wallet monitoring requires runtime-ready Connection
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: a Provider may expose `wallet_balance`, but Wallet monitoring is still not ready unless the selected/runtime Connection is enabled, provisioned, and multi-Connection ownership-ready when more than one Connection is enabled. Pending/disabled/not-ownership-ready Connections do not fetch balance or present a green Wallet-ready state.

### UX-SMS-020 — Wallet check runs only after durable successful automatic Mobo purchase
- Kind: CONTRACT + order RUNTIME
- Regression contract: Wallet balance monitoring is invoked from `mobo_core_mobo_order_submission_success` after durable automatic Mobo purchase success; failed/uncertain/rejected purchase paths do not trigger the low-balance alert workflow.

### UX-SMS-021 — Wallet Alert is independent from Order-SMS master
- Kind: CONTRACT
- Regression contract: Wallet Alert can operate while global Order SMS is disabled, and disabling Wallet Alert does not disable configured Order-SMS scenarios.

### UX-SMS-022 — Wallet threshold comparison is inclusive
- Kind: CONTRACT
- Regression contract: alert eligibility is `balance <= threshold`; `balance > threshold` performs the check/history update but sends no alert.

### UX-SMS-023 — Wallet one-shot never auto-rearms on balance recovery/config edits
- Kind: CONTRACT + RUNTIME
- Regression contract: after a committed one-shot notification, a later higher balance, threshold change, recipient/template change or next successful purchase does not reset `notified`; only explicit administrator re-arm may do so.

### UX-SMS-024 — Wallet one-shot decision is concurrency-serialized
- Kind: CONTRACT + concurrent RUNTIME
- Regression contract: near-simultaneous successful Mobo purchases cannot both pass the low-balance send decision; the wallet-alert lock, notified state and replay fence yield at most one automatic side-effect attempt.

### UX-SMS-025 — Wallet replay fence is durable before remote send
- Kind: CONTRACT + failure-injection RUNTIME
- Regression contract: before any live Wallet Alert gateway call, a Connection-scoped replay fence is written and read back. If durable local storage cannot prove the fence, the SMS is not sent.

### UX-SMS-026 — Pre-dispatch Wallet configuration failures clear replay fence
- Kind: CONTRACT
- Regression contract: known validation/template failures that occur before `PWSMS()->send_sms()` (empty recipients/template/message or template-render exception) are safe to retry later and clear the pre-send fence rather than creating a false uncertain-delivery state.

### UX-SMS-027 — Ambiguous Wallet transport outcome remains fenced
- Kind: CONTRACT + failure-injection RUNTIME
- Regression contract: an exception/unrecognized/failed gateway result after entering the dispatch boundary is marked `uncertain`; the fence stays durable and all later purchases suppress automatic resend until explicit re-arm.

### UX-SMS-028 — Wallet post-send commit failure remains fenced
- Kind: CONTRACT + failure-injection RUNTIME
- Regression contract: if the gateway explicitly accepts the Wallet Alert but `notified/notifiedAt/notifiedBalance` cannot all be read-back verified, the state is marked uncertain, the replay fence remains, and no later order automatically resends.

### UX-SMS-029 — Successful Wallet Alert commits one-shot before clearing fence
- Kind: CONTRACT + RUNTIME
- Regression contract: explicit gateway acceptance updates live transport history, all durable notified fields are verified, and only then is the replay fence cleared. The resulting status is notified, not uncertain.

### UX-SMS-030 — Explicit Wallet re-arm clears notified/fence for selected Connection only
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: administrator re-arm clears the selected Connection's notified timestamp/balance and replay fence, read-back verifies the cleared state before reporting success, acknowledges an uncertain live result without mutating another Connection, and permits a new future low-balance send.

### UX-SMS-031 — Wallet transport-test history is isolated from live alert health
- Kind: CONTRACT + admin RUNTIME
- Regression contract: manual test uses `wallet_test_sms_last_*` keys only; it cannot create/clear `wallet_sms_last_*` operational errors, cannot set/clear `notified`, and cannot consume/clear the live replay fence.

### UX-SMS-032 — Wallet transport test does not fetch Provider balance
- Kind: CONTRACT
- Regression contract: manual test uses the last recorded balance when available and threshold as fallback; it never calls the wallet-balance API merely to send a transport/configuration test.

### UX-SMS-033 — Gateway acceptance is not labelled handset delivery
- Kind: STATIC + CONTRACT
- Regression contract: live/test success wording is “accepted by gateway/API” (or equivalent) and never asserts final handset delivery, which MoboCore cannot prove from the PWSMS return contract.

### UX-SMS-034 — SMS/Wallet Save verifies mixed ownership read-back
- Kind: CONTRACT + admin RUNTIME
- Regression contract: global Order-SMS and Connection-scoped Wallet settings are saved through `Mobo_Core_Settings`, then read back through the same authoritative ownership path. An unchanged value must not be mistaken for a failed save merely because WordPress `update_option()` returns false.

### UX-SMS-035 — Incomplete active configuration returns warning, not false ready
- Kind: CONTRACT + STATIC
- Regression contract: Save may preserve intentionally incomplete configuration for later editing, but an enabled scenario missing recipients/template, Wallet Alert without required config/Provider capability, or active feature without PWSMS API produces an explicit warning and primary readiness remains non-green.

### UX-SMS-036 — Unsupported/disabled Wallet historical errors are not active Dashboard incidents
- Kind: CONTRACT
- Regression contract: a Provider that does not support wallet balance, or a Wallet Alert that is currently disabled, does not promote stale historical Wallet/SMS errors into the active Dashboard issue state; diagnostics may retain history.

### UX-SMS-037 — Successful Wallet balance read clears prior active balance error
- Kind: CONTRACT + RUNTIME
- Regression contract: a later valid wallet-balance response deletes the previous active balance-fetch error and updates current balance/check time; recovered history must not remain an active warning.

### UX-SMS-038 — Wallet state/test/history remains isolated across Connections
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: threshold, recipient/template, notified/fence, last balance/error, live SMS history and manual-test history for Connection A never appear as Connection B's state when the admin/runtime context switches.

### UX-SMS-039 — Legacy live Wallet history is preserved; new tests never rewrite it
- Kind: migration CONTRACT
- Regression contract: UX19 does not destructively guess whether pre-UX19 `wallet_sms_last_*` history came from a live alert or old manual test. Existing history is preserved, while every new manual test writes only the separated test-history keys.

### UX-SMS-040 — Current documentation matches SMS/Wallet lifecycle
- Kind: STATIC
- Regression contract: current Admin copy, `README_FULL.MD`, `README.MD`, `readme.txt` and release notes consistently describe store-wide Order SMS, Connection-scoped Wallet Alert, Checkout-time Order SMS, no independent retry queue, one-shot/manual re-arm, replay fencing, Provider capability, and gateway-acceptance-vs-delivery semantics.

---

## M. Speed & Queues — CLOSED (UX20)

### UX-QUEUE-001 — Primary queue state comes from durable runtime evidence
- Kind: CONTRACT + admin RUNTIME
- Regression contract: the primary Speed & Queues status is derived from the selected Connection's durable Webhook/Image/Parent-Finalize queue evidence and runtime readiness, not merely from configured batch sizes/toggles.

### UX-QUEUE-002 — Unready selected Connection is never green
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: a disabled, unprovisioned or ownership-unready selected Connection cannot be presented as operationally healthy merely because queue counters are zero.

### UX-QUEUE-003 — Unavailable queue data is not factual zero
- Kind: CONTRACT
- Regression contract: missing queue tables, failed summary queries or unavailable Connection-scoped sources are surfaced as unavailable/read-failed state; they are never silently normalized into a healthy `0` backlog.

### UX-QUEUE-004 — Webhook backlog combines table store and legacy fallback correctly
- Kind: CONTRACT + RUNTIME
- Regression contract: the table-backed Sync Event Store remains authoritative for current Webhook events while legacy JSON files remain compatibility/storage-fallback evidence; the Admin page must not equate “number of files” with the complete Webhook backlog.

### UX-QUEUE-005 — Actionable and historical Webhook failures stay distinct
- Kind: CONTRACT
- Regression contract: currently actionable failed Webhook events are separated from historical/retained failures; historical evidence alone must not make the primary queue state look actively broken.

### UX-QUEUE-006 — Pending/due work is not automatically an error
- Kind: CONTRACT
- Regression contract: pending/active/due queue work is reported as workload/progress evidence and becomes warning/error only when supported by failure/stuck/availability evidence; ordinary backlog alone is not labelled failure.

### UX-QUEUE-007 — Image queue summary exposes availability/read failure
- Kind: CONTRACT
- Regression contract: `Mobo_Core_Image_Queue::get_summary()` distinguishes an available zero queue from table-unavailable/query-failed states through explicit availability/read-failure evidence while preserving existing numeric summary keys for compatibility.

### UX-QUEUE-008 — Images-per-run can never be zero
- Kind: STATIC + CONTRACT + migration RUNTIME
- Regression contract: Admin Save, generic Settings Save, Image Sync queue execution, Cron Runner, Adaptive Tuner and Admin polling all enforce an effective minimum of one image per slice. A legacy stored `0` must behave safely as at least one and must never mark image desired state converged without enqueue/download evidence.

### UX-QUEUE-009 — Disabling image queue means synchronous/direct processing, not disabling images
- Kind: STATIC + CONTRACT + RUNTIME
- Regression contract: when the independent image queue is disabled, current image-sync behavior falls back to direct/synchronous processing. Admin copy must warn about potential Sync latency and must never describe the toggle as “disable image synchronization”.

### UX-QUEUE-010 — Active Product/Missing-Image paths remain non-blocking when queue is enabled
- Kind: STATIC + CONTRACT
- Regression contract: current Product Sync and Missing Image Recovery entry points explicitly use non-blocking image handling/queueing; the redesigned page must not expose the retired “block Sync until images finish” behavior as an active tuning control.

### UX-QUEUE-011 — Legacy image-blocking option is backward-compatible but not an active Admin control
- Kind: STATIC
- Regression contract: `mobo_core_image_queue_blocking` may remain in defaults/legacy compatibility paths, but UX20 does not render, preset or save it from the Speed & Queues page and does not imply it controls the current primary Product Sync path.

### UX-QUEUE-012 — Missing-parent desired-state evidence is never retired by age alone
- Kind: CONTRACT + RUNTIME
- Regression contract: `UpdateVariant` waiting for its Product parent remains desired-state evidence regardless of wall-clock age; `should_retire_waiting_for_parent()` stays fail-safe and does not retire solely because a timeout elapsed.

### UX-QUEUE-013 — Legacy parent-wait timeout is not an active performance setting
- Kind: STATIC
- Regression contract: `mobo_core_variant_parent_wait_timeout_seconds` may remain for backward compatibility, but UX20 does not expose/preset/save it as a Speed setting and documentation does not claim it currently retires waiting variants by age.

### UX-QUEUE-014 — Sync-time-budget label matches Webhook runtime ownership
- Kind: STATIC + CONTRACT
- Regression contract: `mobo_core_sync_time_budget_seconds` is described as the Webhook queue processing time budget used by current runtime, not as a total Product Sync execution deadline.

### UX-QUEUE-015 — Webhook retention changes only newly created event expiry
- Kind: CONTRACT + STATIC
- Regression contract: changing `mobo_core_webhook_expire_days` affects expiry assigned to newly created Webhook events; existing persisted event expiry is not retroactively rewritten, and Admin copy states this limitation.

### UX-QUEUE-016 — Performance presets change throughput baselines only
- Kind: STATIC + browser RUNTIME
- Regression contract: VPS/strong/medium/weak presets alter only bounded throughput numeric baselines (Webhook time budget, Product page size, Variant page size, Image slice limit and Webhook event slice limit).

### UX-QUEUE-017 — Performance presets never change correctness/policy semantics
- Kind: STATIC + browser RUNTIME
- Regression contract: presets do not mutate Cursor toggles, queue enablement, retry policy, retention, Missing Variant policy, legacy image-blocking, parent-wait timeout or other correctness/lifecycle settings.

### UX-QUEUE-018 — Presets are safe with radio-based Design System controls
- Kind: browser RUNTIME
- Regression contract: because UX20 presets only target numeric inputs, applying a preset cannot corrupt Design-System `_on/_off` radio state through obsolete select-style `.val()` behavior.

### UX-QUEUE-019 — Adaptive Execution makes saved values baselines, not guaranteed effective limits
- Kind: CONTRACT + admin RUNTIME
- Regression contract: when Adaptive Execution is enabled, the Admin page explicitly identifies saved batch sizes as baselines and surfaces effective/adaptive limits in technical diagnostics without claiming baseline equals current runtime capacity.

### UX-QUEUE-020 — Queue Save is authoritative/read-back verified
- Kind: CONTRACT + admin RUNTIME
- Regression contract: every editable UX20 queue setting is sanitized to its documented bounds, saved through the authoritative setting ownership path and read back before success is reported. An unchanged value is not treated as failed merely because the underlying update API returns false.

### UX-QUEUE-021 — Queue changes stay blocked during protected Sync/Repair windows
- Kind: CONTRACT + RUNTIME
- Regression contract: server-side Sync Settings Guard continues to reject Speed & Queues mutations while protected manual Sync/Repair work is active; client-side disabled controls are not the only protection.

### UX-QUEUE-022 — Missing Variants remains a catalog policy, not a speed preset
- Kind: STATIC + CONTRACT
- Regression contract: Missing Variant behavior remains independently editable as catalog/snapshot policy and is never changed by throughput presets or described as a hardware-performance tuning parameter.

### UX-QUEUE-023 — Webhook Store wording matches table-primary/file-fallback architecture
- Kind: STATIC
- Regression contract: current Admin/help text describes Sync Event Store as the primary durable Webhook store and JSON files as fallback/compatibility storage; no current documentation on this page treats the file queue as the sole source of truth.

### UX-QUEUE-024 — Technical queue internals remain secondary
- Kind: STATIC
- Regression contract: raw adaptive limits, detailed queue counters, historical failures and Parent-Finalize internals stay in technical/secondary disclosure; the primary surface shows status, meaning and required action.

### UX-QUEUE-025 — Queue status remains Connection-isolated
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: Webhook/Image/Parent-Finalize status and readiness shown on the page belong to the selected active Admin Connection context and never leak counters/ownership state from another Connection.

---

## N. Cross-page Admin UX contracts — ALL REDESIGNED PAGES

### UX-GLOBAL-001 — CSS isolation
- Kind: STATIC
- Regression contract: redesign CSS is scoped under `#mobo-core-admin`; no unsafe generic selector introduced by the redesign may style unrelated wp-admin/plugin screens.

### UX-GLOBAL-002 — Assets load only on MoboCore admin screens
- Kind: STATIC + WordPress RUNTIME
- Regression contract: redesign CSS/JS is enqueued only on MoboCore pages and does not globally attach to wp-admin.

### UX-GLOBAL-003 — No new Select2/SelectWoo dependency for MoboCore-owned UI
- Kind: STATIC
- Regression contract: searchable MoboCore selectors use the namespaced Mobo picker/native mobile select; simple boolean/on-off selects stay native. No redesigned MoboCore UI may require another plugin's Select2/SelectWoo instance.

### UX-GLOBAL-004 — Search picker keyboard/RTL contract
- Kind: browser/RUNTIME
- Regression contract: Mobo picker supports RTL, Persian/Arabic Yeh/Kaf normalization, keyboard navigation, Escape, scroll/resize repositioning and emits the normal `change` event.

### UX-GLOBAL-005 — Mobile/no-horizontal-overflow
- Kind: browser/RUNTIME
- Regression contract: redesigned pages remain usable at mobile wp-admin widths, technical tables/logs do not force the whole page wider than the viewport, and primary actions/status remain reachable.

### UX-GLOBAL-006 — Technical data stays secondary
- Kind: STATIC
- Regression contract: GUIDs, cursors, internal IDs, nonce/HMAC/schema internals, raw JSON/cURL and worker internals are not promoted into the ordinary primary view unless a specific actionable contract requires them.

### UX-GLOBAL-007 — Data unavailable is not healthy/zero
- Kind: CONTRACT
- Regression contract: when a status/counter source fails or has never been measured, the UI distinguishes unavailable/not-yet-measured/stale/disabled from a factual zero or healthy state.

### UX-GLOBAL-008 — Active errors vs historical errors
- Kind: CONTRACT
- Regression contract: historical counters/last errors do not remain primary active warnings after the authoritative current state proves recovery; historical evidence remains available in diagnostics.

---


## O. Database Charset / Full-Unicode Storage — REQUIRED BEFORE FINAL RELEASE

Architecture record: `docs/internal/database-charset-policy.md`

Product requirement: MoboCore storage must be **full-Unicode / 4-byte UTF-8 capable**. The current concrete minimum is `utf8mb4`. `utf8mb3`, legacy 3-byte MySQL `utf8`, and any weaker charset are unsupported for MoboCore readiness.

### UX-DBCHAR-001 — utf8mb4 is the current minimum supported database charset
- Kind: STATIC + CONTRACT
- Regression contract: MoboCore never treats `utf8mb3`, legacy three-byte `utf8`, latin/non-Unicode, or another charset incapable of full 4-byte Unicode as supported database readiness. `utf8mb4` is the current concrete minimum; a future stronger/equivalent charset requires an explicit compatibility decision/test rather than accidental acceptance.

### UX-DBCHAR-002 — `DB_CHARSET` three-byte configuration is a hard readiness failure
- Kind: CONTRACT + WordPress RUNTIME
- Regression contract: `DB_CHARSET=utf8mb3` and legacy `DB_CHARSET=utf8` resolving to the 3-byte charset are detected as unsupported. The Admin/Site Health state must not call the database ready merely because schema tables exist.

### UX-DBCHAR-003 — live connection charset must also be full-Unicode
- Kind: CONTRACT + DB RUNTIME
- Regression contract: readiness proves the effective `$wpdb` client/connection character set is utf8mb4-or-better. utf8mb4 tables behind an utf8mb3 connection remain unhealthy because 4-byte values can still fail before reaching storage.

### UX-DBCHAR-004 — database default charset/collation cannot silently regress new objects
- Kind: CONTRACT + DB RUNTIME
- Regression contract: the selected WordPress database default resolves to a supported full-Unicode charset/collation. A 3-byte database default is reported separately from table state and cannot be represented as healthy.

### UX-DBCHAR-005 — every MoboCore-owned textual table/column is audited by actual schema
- Kind: CONTRACT + DB RUNTIME
- Regression contract: charset readiness is derived from actual MoboCore table/column metadata, not only from `wp-config.php` or `$wpdb->get_charset_collate()`. A mixed schema with even one required 3-byte textual MoboCore storage path is not green.

### UX-DBCHAR-006 — WordPress/WooCommerce destination tables used by MoboCore are also compatible
- Kind: CONTRACT + WordPress/WooCommerce RUNTIME
- Regression contract: readiness covers the core/Woo storage MoboCore actually writes textual product/category/order/meta data into (including the applicable classic-order or HPOS path). Mobo-owned tables being utf8mb4 is insufficient when a required destination table remains 3-byte.

### UX-DBCHAR-007 — unrelated third-party tables do not create a false global blocker
- Kind: CONTRACT
- Regression contract: unrelated third-party tables may be reported diagnostically, but a charset difference in a table MoboCore never touches does not by itself block MoboCore runtime. The blocking scope is database/connection defaults plus Mobo-required storage paths.

### UX-DBCHAR-008 — real 4-byte Unicode round-trip is mandatory
- Kind: DB RUNTIME
- Regression contract: representative supplementary Unicode data (for example an emoji/non-BMP code point) survives write/read through selected Mobo custom storage and WordPress/Woo destination paths exactly, without SQL error, replacement characters, truncation or lossy stripping.

### UX-DBCHAR-009 — no lossy fallback to make utf8mb3 appear compatible
- Kind: STATIC + CONTRACT + DB RUNTIME
- Regression contract: MoboCore must not silently remove, replace, transliterate or truncate 4-byte characters as a compatibility workaround. Incompatible storage fails closed with an actionable charset error.

### UX-DBCHAR-010 — new schema creation must not blindly inherit utf8mb3
- Kind: STATIC + CONTRACT + migration RUNTIME
- Regression contract: MoboCore schema creation/repair does not call `$wpdb->get_charset_collate()` sufficient evidence when it resolves to a 3-byte charset. New/rebuilt Mobo tables are created only under a proven full-Unicode charset/collation contract, or schema readiness remains blocked.

### UX-DBCHAR-011 — mixed utf8mb3/utf8mb4 schema is detected explicitly
- Kind: CONTRACT + DB RUNTIME
- Regression contract: a site with utf8mb4 config/default but one or more legacy utf8mb3 required tables/columns is reported as mixed/incomplete, not healthy. The diagnostic identifies the affected object(s).

### UX-DBCHAR-012 — conversion is explicit maintenance, never a silent whole-database side effect
- Kind: STATIC + migration RUNTIME
- Regression contract: routine activation, page load and ordinary plugin update never silently run broad whole-database charset conversion. Any Mobo-provided conversion path requires an explicit administrator maintenance action/confirmation and a recoverable backup warning.

### UX-DBCHAR-013 — conversion scope is deterministic and limited to required storage
- Kind: CONTRACT + migration RUNTIME
- Regression contract: a future Database Unicode Upgrade converts MoboCore-owned storage and the WordPress/WooCommerce tables required by supported Mobo runtime paths. Unrelated third-party tables are not ALTERed merely because they use another charset.

### UX-DBCHAR-014 — conversion preflight protects server/collation/index compatibility
- Kind: CONTRACT + migration RUNTIME
- Regression contract: before ALTER/CONVERT work starts, MoboCore verifies utf8mb4 server support, chooses a live-server-supported WordPress-compatible utf8mb4 collation, checks index/key constraints and preserves existing safe index-prefix contracts. MySQL-8-only collations are never forced onto incompatible MariaDB/older MySQL hosts.

### UX-DBCHAR-015 — conversion is locked, checkpointed and idempotently resumable
- Kind: CONTRACT + migration RUNTIME
- Regression contract: charset conversion cannot race Sync/Repair/Webhook/Image/Order mutations, records durable progress by table/phase, tolerates re-entry after interruption, and does not assume DDL is transactionally rollbackable.

### UX-DBCHAR-016 — partial/failed conversion remains fail-closed
- Kind: CONTRACT + migration RUNTIME
- Regression contract: if conversion fails after some objects changed, the site is explicitly `conversion incomplete / MoboCore not ready`; no generic success is emitted and normal mutation does not resume until authoritative re-audit passes.

### UX-DBCHAR-017 — conversion success requires schema re-audit plus 4-byte proof
- Kind: CONTRACT + DB RUNTIME
- Regression contract: conversion is not considered complete merely because ALTER statements returned success. Configuration, connection, database default, required tables/columns and a real 4-byte round-trip are re-verified before readiness becomes green.

### UX-DBCHAR-018 — Site Health exposes exact charset failure layer and action
- Kind: STATIC + CONTRACT + WordPress RUNTIME
- Regression contract: Site Health/Admin identifies whether the blocker is WordPress configuration, live connection, database default, a Mobo table/column, or a required WordPress/WooCommerce table, and points to the explicit Unicode-upgrade/remediation path instead of reporting a generic SQL/schema error.

### UX-DBCHAR-019 — `DB_COLLATE=''` is allowed only when effective collation is supported utf8mb4
- Kind: CONTRACT + DB RUNTIME
- Regression contract: an empty `DB_COLLATE` is acceptable when WordPress/server resolution produces a supported utf8mb4 collation. An explicitly configured or effectively resolved 3-byte collation remains unsupported.

### UX-DBCHAR-020 — Release Gate contains both failing utf8mb3 and passing utf8mb4 fixtures
- Kind: Test-Suite STATIC + DB RUNTIME
- Regression contract: the final release suite intentionally exercises an utf8mb3 site/database state and proves the expected fail-closed diagnosis, then exercises utf8mb4 and proves schema/readiness plus supplementary-Unicode round-trip success.

## Release Gate integration checklist

Before the final release:
1. UX41 readiness/enforcement plus UX42–UX46 locked/checkpointed remediation are implemented. Remaining Database Charset release work is to turn the recorded `UX-DBCHAR-*` / `UX-DBMIG-*` contracts into permanent suite fixtures, including intentionally failing utf8mb3 and passing utf8mb4 environments.
2. Convert every backlog item whose implementation is complete into permanent Test Suite coverage where technically feasible.
3. Record permanent test IDs/names next to the corresponding backlog entries.
4. Keep genuine live-environment-only items as explicit RUNTIME proofs and execute them on the canonical dual-site WordPress/WooCommerce/Portal environment.
5. Include both an intentionally unsupported utf8mb3 fixture and a supported utf8mb4 fixture with a real supplementary-Unicode round-trip proof.
6. Update plugin fingerprint and manifest expectations only after the final Admin UX artifact is frozen.
7. Run CSS namespace/isolation tests, JS syntax, PHP lint, Plugin Gate, ALL-GAPS, Portal/.NET frozen tests, then Full Release Gate.

---

## P. Purchase & Activation — UX21

### UX-PURCHASE-001 — Pending Connection shows its stored Token in Admin status
- Kind: CONTRACT + admin RUNTIME
- Regression contract: when the selected durable Connection is still waiting for provisioning/disabled but its `license_token` is stored, Purchase & Activation reports «توکن اتصال: ثبت شده». It must read the selected row for administrative evidence rather than the runtime credential helper that intentionally hides disabled-owner secrets.

### UX-PURCHASE-002 — Pending Connection shows its stored Webhook security-code state
- Kind: CONTRACT + admin RUNTIME
- Regression contract: a stored security code on a Pending/disabled selected Connection is visible as stored-format state in Purchase & Activation. Runtime credential ownership may remain unavailable without turning the administrative setup state into «ثبت نشده».

### UX-PURCHASE-003 — Runtime credential helpers stay fail-closed for disabled owners
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: fixing Purchase/Admin display must not make `current_admin_license_token()` / runtime credential routing return usable Token/X-SEC for a disabled Connection. Administrative evidence and runtime credential authority remain separate contracts.

### UX-PURCHASE-004 — Provisioned-but-disabled is not presented as an active runtime Connection
- Kind: CONTRACT + admin RUNTIME
- Regression contract: a Connection with complete server-owned provisioning metadata but `is_enabled=0` is shown as «آماده ولی غیرفعال», not as active/fully ready merely because provisioning is complete.

### UX-PURCHASE-005 — Pending, active, and provisioned-disabled states remain distinct
- Kind: CONTRACT
- Regression contract: Purchase setup state preserves the same three-state Connection lifecycle as the authoritative Connection page: waiting for provisioning, active, and ready-but-disabled.

### UX-PURCHASE-006 — Source/Provider do not turn green from partial provisioning residue
- Kind: CONTRACT + corruption-fixture RUNTIME
- Regression contract: a raw `source_key` or `provider_key` value left by an incomplete/corrupt provisioning state does not independently produce a green setup result. Source/Provider readiness requires the selected Connection to satisfy the complete `is_provisioned()` contract.

### UX-PURCHASE-007 — Provisioning still requires a supported compiled Provider
- Kind: CONTRACT
- Regression contract: Purchase readiness follows `Mobo_Core_Connection_Repository::is_provisioned()`, including Provider registry support; a non-empty unknown Provider key is not represented as completed provisioning.

### UX-PURCHASE-008 — Connection credential terminology stays Connection-scoped
- Kind: STATIC
- Regression contract: current Purchase copy uses «توکن اتصال»/Connection-scoped terminology and does not regress to a generic shared «API Token» label that could imply one credential is reusable across Sources/Connections.

### UX-PURCHASE-009 — Security-code format validity is not live Webhook verification
- Kind: STATIC + CONTRACT
- Regression contract: Purchase may report that X-SEC is stored and format-valid, but it must not claim the Webhook was authenticated/reachable from that fact alone. Live Webhook/auth status remains owned by Connection & License diagnostics.

### UX-PURCHASE-010 — Invalid non-empty security code remains actionable
- Kind: CONTRACT + admin RUNTIME
- Regression contract: a stored but format-invalid Webhook security code is shown as needing correction, distinct from both missing and stored-format-valid states.

### UX-PURCHASE-011 — Local setup tiles do not infer a live/active License
- Kind: STATIC + CONTRACT
- Regression contract: Token presence, provisioning completion or a valid X-SEC never imply the remote License is Active. Current copy directs live service/license status to the authoritative Connection & License page unless an actual license response is evaluated.

### UX-PURCHASE-012 — Token-first server provisioning remains the setup contract
- Kind: STATIC + CONTRACT + provisioning RUNTIME
- Regression contract: setup guidance matches the architecture: the browser stores the Connection Token first; authorized .NET provisioning then writes server-owned Source/Provider/API/source-site metadata using the signed provisioning contract.

### UX-PURCHASE-013 — Provider/Source/API metadata stays server-owned and non-editable here
- Kind: STATIC
- Regression contract: Purchase & Activation exposes no customer-editable Provider, Source, Backend/API URL, Source Site URL or Display Name controls. Those values remain server-provisioned metadata.

### UX-PURCHASE-014 — Feature toggles are not a prerequisite for signed provisioning
- Kind: STATIC + CONTRACT
- Regression contract: current Purchase technical copy does not claim Product/Order/SMS or another feature toggle must be enabled before .NET can provision a Token-backed Pending Connection. Provisioning and feature-runtime activation remain separate concepts.

### UX-PURCHASE-015 — Runtime exchange requires the correct active Connection/action
- Kind: STATIC + multi-Connection RUNTIME
- Regression contract: after provisioning, operational data exchange is attributed to the selected/runtime Connection and the feature/action being used; a disabled Connection is not described as live merely because its setup metadata exists.

### UX-PURCHASE-016 — Manual Address Mapping is not an order-submission prerequisite
- Kind: STATIC
- Regression contract: the final setup step does not tell administrators to complete legacy/manual address mapping for automatic order submission. Central Location Resolver remains authoritative; sender details and shipping-method mapping are the relevant order setup items.

### UX-PURCHASE-017 — Selected Connection context never falls through to another owner
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: all Purchase status tiles (stored credentials, provisioning, Source, Provider, enabled state) describe the explicitly selected Connection row. An unavailable/disabled explicit positive ID never inherits Default Connection setup evidence.

### UX-PURCHASE-018 — Purchase page remains a read-only guide and never exposes secret values
- Kind: STATIC + admin RUNTIME
- Regression contract: Purchase & Activation may show only presence/format/readiness summaries for Token/X-SEC; it renders neither secret value and has no mutation form for credentials. Credential changes remain on Connection & License.

---

## Q. WooCommerce Products list integration — UX22

### UX-PRODLIST-001 — Mobo filter is installation-wide across all Connections
- Kind: STATIC + CONTRACT + multi-Connection RUNTIME
- Regression contract: `edit.php?post_type=product` keeps the default All/Mobo/Non-Mobo filter, and Mobo classification includes products owned by any durable Connection rather than only the current/default Connection.

### UX-PRODLIST-002 — terminal identity authority never falls back to retired postmeta
- Kind: CONTRACT + storage-failure RUNTIME
- Regression contract: after the terminal identity fence, temporary Custom Storage/schema unavailability disables Mobo/Non-Mobo filtering and leaves the WooCommerce query unclassified; it never reuses retired legacy postmeta and silently mislabels products.

### UX-PRODLIST-003 — unavailable identity filter is visible and non-deceptive
- Kind: STATIC + admin RUNTIME
- Regression contract: when terminal identity authority is unavailable, the Products-list Mobo selector is disabled with an explicit storage-health explanation and an existing URL filter does not continue to alter the query invisibly.

### UX-PRODLIST-004 — row badge uses global durable owner, not request Default
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: a Mobo product owned by a non-default Connection still receives the Mobo row badge on the WooCommerce Products list even when the admin request's ambient Connection is different.

### UX-PRODLIST-005 — filter and badge cannot disagree under healthy terminal storage
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: with terminal Custom Storage healthy, any product returned by the Mobo ownership filter is evaluated from the same durable product table/ownership evidence used for row integration; Default-Connection context cannot hide its badge.

### UX-PRODLIST-006 — pricing action routes to the product's durable owner
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: the per-product «قیمت‌گذاری موبو» action on a non-default-owned product executes inside that product's durable owning Connection and is not rejected merely because the wp-admin request default belongs to another Connection.

### UX-PRODLIST-007 — AJAX derives owner server-side
- Kind: STATIC + CONTRACT
- Regression contract: pricing AJAX does not trust a browser-supplied `mobo_connection_id` to choose ownership. It resolves the Woo Product's durable owner server-side before mutation and executes the pricing request in that context.

### UX-PRODLIST-008 — unknown/ambiguous owner fails closed
- Kind: CONTRACT + corruption-fixture RUNTIME
- Regression contract: if durable Mobo identity is present but the owning Connection cannot be proven, product-specific pricing returns an actionable failure and does not inherit the Default Connection.

### UX-PRODLIST-009 — owner Connection must be enabled and provisioned for mutation
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: product-specific pricing is not offered as an active mutation when the owning Connection is Pending, disabled, unprovisioned, deleted/unavailable, or otherwise not runtime-ready.

### UX-PRODLIST-010 — multi-Connection topology readiness gates pricing mutation
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: when multiple Connections are enabled but ownership migration/runtime readiness is not proven, the Products-list pricing action remains fail-closed instead of mutating under an ambiguous topology.

### UX-PRODLIST-011 — excluded state is evaluated in the product owner's Connection
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: Connection-scoped exclusion paths/evidence are read in the durable owner's context. An excluded product owned by Connection B cannot become price-editable merely because the admin request ambient context is Connection A.

### UX-PRODLIST-012 — Products-list exclusion check remains read-only
- Kind: CONTRACT
- Regression contract: rendering the WooCommerce Products list checks exclusion with the non-writing/read-only path and does not create GUID→URL evidence simply because an administrator viewed the list.

### UX-PRODLIST-013 — excluded Mobo product remains visibly Mobo but not price-editable
- Kind: STATIC + CONTRACT + admin RUNTIME
- Regression contract: an excluded product keeps the Mobo ownership badge, gains an explicit «مستثنا از همگام‌سازی» state, and the row action is a non-mutating explanation rather than an active pricing link.

### UX-PRODLIST-014 — inactive owner is visible instead of silently losing affordance
- Kind: STATIC + admin RUNTIME
- Regression contract: a Mobo product whose owning Connection is not mutation-ready is still identified as Mobo and displays an explicit «اتصال آماده نیست» state; the pricing action is non-mutating and explains why.

### UX-PRODLIST-015 — modal shows the owning Connection's global pricing policy
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: choosing Global in the per-product pricing modal describes and uses `mobo_price_type` / fixed / percentage / dynamic settings belonging to that product's owner Connection, never the request/default Connection.

### UX-PRODLIST-016 — modal identifies the owner without exposing credentials
- Kind: STATIC + admin RUNTIME
- Regression contract: the pricing modal identifies the owning Connection by safe display/source label for operator clarity but never renders Token, X-SEC, backend secret, or other credential material.

### UX-PRODLIST-017 — per-product pricing precedence copy stays aligned with runtime
- Kind: STATIC + CONTRACT
- Regression contract: modal copy preserves the current precedence contract: variation-specific `mobo_additional_price` remains above the parent/product percentage override, and the parent override applies only where a variation-specific value is absent.

### UX-PRODLIST-018 — pricing state/pending/error are read in owner context
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: custom pricing, pending generation and pricing-error badges for a non-default product are resolved in the durable owner's Connection context and cannot borrow another Connection's global/runtime state.

### UX-PRODLIST-019 — pricing modal keyboard lifecycle
- Kind: STATIC + browser RUNTIME
- Regression contract: the modal exposes an `aria-live` result region, closes with Escape, and returns focus to the row action that opened it; these behaviors do not submit or mutate state.

### UX-PRODLIST-020 — Products-list integration stays scoped to Woo Products admin
- Kind: STATIC + WordPress RUNTIME
- Regression contract: Mobo Products-list CSS/JS/modal/filter hooks activate only for the WooCommerce `product` list surface, use `mobo-*` namespaced selectors/classes, and do not style or inject controls into unrelated wp-admin list tables.

## R. WooCommerce Orders admin integration — UX23

### UX-ORDERADMIN-001 — Legacy and HPOS order lists use the same authoritative state
- Kind: CONTRACT + WooCommerce RUNTIME
- Regression contract: both legacy `shop_order` and HPOS order-list columns render from the same Mobo submission-state resolver; switching storage mode cannot change the operator-facing meaning of the same order lifecycle.

### UX-ORDERADMIN-002 — submitted-but-review-required overrides green success
- Kind: CONTRACT + RUNTIME
- Regression contract: `_mobo_order_requires_review=yes` or post-payment divergence on an already submitted order renders «ثبت شد؛ نیازمند بررسی», never a plain successful «ثبت شد» label.

### UX-ORDERADMIN-003 — post-payment divergence explicitly forbids Retry
- Kind: STATIC + CONTRACT
- Regression contract: when remote purchase succeeded but Woo business state diverged after the payment boundary, Admin copy tells the operator to reconcile order contents/address/shipping and never offers Retry.

### UX-ORDERADMIN-004 — non-Mobo orders expose no Mobo submission action
- Kind: CONTRACT + admin RUNTIME
- Regression contract: an order with no Mobo items is labelled informationally and the Order Detail meta box never shows «ارسال/ارسال مجدد به موبو».

### UX-ORDERADMIN-005 — mixed orders expose no partial-send action
- Kind: CONTRACT + admin RUNTIME
- Regression contract: a Mobo/non-Mobo mixed order is visibly fail-closed and no manual Retry/submit action is rendered; the current all-or-nothing policy remains identical to backend scope enforcement.

### UX-ORDERADMIN-006 — multi-Connection orders expose no split/retry action
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: an order containing Mobo items from more than one Connection is labelled «چند اتصال؛ ارسال نمی‌شود» and no manual action can imply that split purchasing is supported.

### UX-ORDERADMIN-007 — invalid/legacy ownership is a review state
- Kind: CONTRACT + legacy-order RUNTIME
- Regression contract: an order containing an uncaptured/deleted legacy item whose ownership cannot be proven is labelled as identity-unknown/review-required and never receives a submit action.

### UX-ORDERADMIN-008 — only processing orders may expose manual submission
- Kind: CONTRACT + WooCommerce RUNTIME
- Regression contract: even an all-Mobo single-Connection order has no manual Mobo submit/retry action unless the WooCommerce order status is exactly `processing`; backend rechecks the same rule.

### UX-ORDERADMIN-009 — queued state suppresses Retry
- Kind: CONTRACT + queue RUNTIME
- Regression contract: `_mobo_order_submit_status=queued` or durable option-backed queue membership renders «در صف ارسال» and suppresses manual Retry until the queue state resolves.

### UX-ORDERADMIN-010 — recent running attempt suppresses Retry
- Kind: CONTRACT + concurrency RUNTIME
- Regression contract: a recent durable running attempt is rendered «در حال ارسال» and cannot expose a second manual submission while the first attempt lease/state is still current.

### UX-ORDERADMIN-011 — uncertain/stale attempt requires explicit remote verification
- Kind: CONTRACT + RUNTIME
- Regression contract: `uncertain` or stale-running state exposes only the verified-absence Retry path, with copy requiring the administrator to prove in Mobo that no order/payment was created.

### UX-ORDERADMIN-012 — ordinary failed/aborted attempts remain retryable only when otherwise eligible
- Kind: CONTRACT + RUNTIME
- Regression contract: failed or pre-payment-aborted attempts may expose Retry only after scope, Connection readiness, Woo status, queue and concurrency guards all pass.

### UX-ORDERADMIN-013 — owner Connection must exist, be enabled and be provisioned
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: manual Order Admin submission is not offered when the durable owner Connection is deleted/unavailable, disabled or unprovisioned; the server-side admin handler independently rechecks the same gate.

### UX-ORDERADMIN-014 — multi-Connection runtime topology readiness gates manual submission
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: when more than one Connection is enabled but ownership/runtime readiness is not proven, Order Admin submission remains fail-closed even if the order line snapshots name one owner.

### UX-ORDERADMIN-015 — manual retry derives owner from immutable order-line scope
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: Admin Retry does not use an ambient/default Connection selector; `submit_order_to_mobo()` resolves the one durable order owner and executes inside that Connection context.

### UX-ORDERADMIN-016 — Order List and Meta Box cannot disagree about retry eligibility
- Kind: CONTRACT + admin RUNTIME
- Regression contract: the column label and detail Meta Box derive from one state resolver so queued/running/uncertain/mixed/multi/review states cannot show contradictory operator meaning.

### UX-ORDERADMIN-017 — Retry mutation is POST-only
- Kind: STATIC + security RUNTIME
- Regression contract: `mobo_core_retry_mobo_order_submission` rejects non-POST requests with HTTP 405; the Admin UI uses a POST form rather than a side-effecting GET link.

### UX-ORDERADMIN-018 — Clear-log mutation is POST-only
- Kind: STATIC + security RUNTIME
- Regression contract: `mobo_core_clear_mobo_order_log` rejects non-POST requests with HTTP 405 and the Meta Box uses a nonce-protected POST form.

### UX-ORDERADMIN-019 — admin actions require both capability and per-order nonce
- Kind: CONTRACT + security RUNTIME
- Regression contract: Retry and Clear Log refuse invalid order ids, users without `edit_shop_order` capability, and missing/invalid per-order nonces before any state mutation or remote work.

### UX-ORDERADMIN-020 — server rechecks lifecycle instead of trusting rendered UI
- Kind: CONTRACT + race RUNTIME
- Regression contract: a POST captured from an older page cannot bypass fresh submitted/already-running/queued/order-status/scope/Connection guards after the order changes concurrently.

### UX-ORDERADMIN-021 — first-level Meta Box contains status, meaning and required action only
- Kind: STATIC + admin RUNTIME
- Regression contract: Mobo Order ID, masked token, raw status/error code, HTTP step and JSON log stay inside collapsed «جزئیات فنی»; normal view exposes only actionable operator state.

### UX-ORDERADMIN-022 — order token is never rendered in full
- Kind: STATIC + admin RUNTIME
- Regression contract: Order Detail may show only a bounded masked suffix of `_mobo_order_token`; full token/credentials never appear in HTML, Order List labels or copyable diagnostics.

### UX-ORDERADMIN-023 — support log redaction contract remains intact
- Kind: CONTRACT + privacy RUNTIME
- Regression contract: known credential/PII keys remain masked, non-JSON remote bodies are stored only as bounded metadata/hash, and moving diagnostics into `<details>` does not weaken sanitizer behavior.

### UX-ORDERADMIN-024 — clearing diagnostic log does not clear submission safety state
- Kind: CONTRACT + RUNTIME
- Regression contract: Clear Log deletes only `_mobo_order_submission_log`; submitted/order-id/attempt/uncertainty/payment guards/requires-review state remain unchanged and the UI states this explicitly.

### UX-ORDERADMIN-025 — empty technical log has a clean empty state
- Kind: STATIC + admin RUNTIME
- Regression contract: an order with no Mobo technical log shows a concise empty diagnostic message instead of a blank textarea or meaningless Clear button.

### UX-ORDERADMIN-026 — HPOS order object resolution remains compatible
- Kind: CONTRACT + HPOS RUNTIME
- Regression contract: Meta Box and list rendering resolve WC_Order correctly from HPOS objects/ids and legacy post ids without reading order business state directly from `wp_posts/wp_postmeta`.


## S. WooCommerce Product Edit / Variation pricing integration — UX24

### UX-VARPRICE-001 — variation profit field appears only on proven Mobo-owned variations
- Kind: CONTRACT + WooCommerce admin RUNTIME
- Regression contract: `mobo_additional_price` is rendered only when the Woo variation has authoritative Mobo ownership; ordinary/manual Woo variations never receive a Mobo pricing control merely because their parent is variable.

### UX-VARPRICE-002 — terminal Custom Storage is authoritative for variation ownership
- Kind: CONTRACT + storage RUNTIME
- Regression contract: after the terminal identity fence, Variation/Product owner rows in Custom Storage decide whether the field exists; retired identity postmeta is never used as a fallback when the authority is unavailable.

### UX-VARPRICE-003 — unavailable terminal identity authority fails closed
- Kind: CONTRACT + failure-injection RUNTIME
- Regression contract: when terminal Custom Storage/schema cannot be read, the Mobo variation-profit field is not rendered or mutated and existing local override data is left untouched.

### UX-VARPRICE-004 — variation owner and parent owner must agree
- Kind: CONTRACT + corruption RUNTIME
- Regression contract: a variation row whose `connection_id` or `woo_product_id` conflicts with the durable parent Product owner is treated as ownership-invalid and cannot expose/save the override.

### UX-VARPRICE-005 — non-default Connection variations are owner-aware
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: a Mobo variation owned by a non-default Connection still exposes its local override and is never classified from the ambient/default Admin Connection.

### UX-VARPRICE-006 — WooCommerce save-variations AJAX nonce is supported
- Kind: STATIC + WooCommerce RUNTIME
- Regression contract: the `woocommerce_save_product_variation` callback accepts the nonce used by WooCommerce's `woocommerce_save_variations` AJAX route (`security` / `save-variations`) so the visible field actually persists during normal AJAX variation saves.

### UX-VARPRICE-007 — normal product-form nonce remains supported
- Kind: STATIC + WooCommerce RUNTIME
- Regression contract: non-AJAX product saves continue to require and accept the standard `woocommerce_meta_nonce` / `woocommerce_save_data` authorization path.

### UX-VARPRICE-008 — invalid/missing nonce never mutates the override
- Kind: CONTRACT + security RUNTIME
- Regression contract: direct or forged calls to the variation-save hook without the route-appropriate valid nonce leave `mobo_additional_price` unchanged.

### UX-VARPRICE-009 — edit capability is required independently of nonce
- Kind: CONTRACT + security RUNTIME
- Regression contract: a valid WooCommerce nonce is insufficient without product-edit capability; unauthorized users cannot set or clear variation profit.

### UX-VARPRICE-010 — missing field payload is not a delete instruction
- Kind: CONTRACT + pagination/AJAX RUNTIME
- Regression contract: when a variation save payload omits `mobo_additional_price[loop]` (partial page, plugin interaction, hidden field or future Woo payload change), the existing override is preserved rather than silently deleted.

### UX-VARPRICE-011 — blank value explicitly clears the override
- Kind: CONTRACT + WooCommerce RUNTIME
- Regression contract: an explicitly submitted empty value removes `mobo_additional_price`, verifies the read-back is empty, and returns pricing precedence to the parent/product override or global policy.

### UX-VARPRICE-012 — zero means no variation-specific override
- Kind: CONTRACT + pricing RUNTIME
- Regression contract: the current business contract remains explicit: posted `0` clears/means no variation-specific fixed-profit override; effective pricing falls through to the next policy layer.

### UX-VARPRICE-013 — negative/decimal/junk values are rejected, not coerced
- Kind: CONTRACT + WooCommerce RUNTIME
- Regression contract: values such as `-5`, `12.5`, `12abc` or formatted/non-integer strings never become a different positive integer through `absint`; the prior durable override remains unchanged and an Admin error is returned.

### UX-VARPRICE-014 — positive integer validation is bounded by PHP integer validity
- Kind: CONTRACT + boundary RUNTIME
- Regression contract: only a valid positive integer representable by the runtime is persisted; oversized numeric strings are rejected without changing prior state.

### UX-VARPRICE-015 — successful set is read-back verified
- Kind: CONTRACT + storage failure RUNTIME
- Regression contract: after `update_post_meta`, MoboCore re-reads the exact override and reports failure instead of assuming success when persistence cannot be verified.

### UX-VARPRICE-016 — successful clear is read-back verified
- Kind: CONTRACT + storage failure RUNTIME
- Regression contract: clearing the override is considered successful only when subsequent read-back is empty; unverifiable deletion produces an Admin error rather than false success.

### UX-VARPRICE-017 — unchanged value is idempotent
- Kind: CONTRACT + RUNTIME
- Regression contract: submitting the same positive override is a no-op and does not create unnecessary cache-purge work or change pricing state.

### UX-VARPRICE-018 — cache invalidation follows only verified override changes
- Kind: CONTRACT + cache RUNTIME
- Regression contract: targeted product cache purge is queued only after a verified set/clear and is not queued after invalid input, ownership failure, nonce failure or unverifiable persistence.

### UX-VARPRICE-019 — variation override remains highest pricing priority
- Kind: CONTRACT + pricing RUNTIME
- Regression contract: after valid identity/ownership checks, a positive `mobo_additional_price` continues to override the parent Product percentage override and the owning Connection's global pricing policy.

### UX-VARPRICE-020 — UI copy states the full precedence chain
- Kind: STATIC + admin RUNTIME
- Regression contract: the field description explicitly tells operators that variation-specific fixed profit wins over Product/Parent percentage override and global pricing, rather than vaguely referring only to «سود عمومی».

### UX-VARPRICE-021 — editing the policy does not claim immediate repricing
- Kind: STATIC + WooCommerce RUNTIME
- Regression contract: Product Edit copy states that saving the override does not immediately recompute existing Woo prices; the value is consumed by a subsequent pricing mutation such as Sync/Reprice/Repair.

### UX-VARPRICE-022 — exclusion state is informational, not false immediate effect
- Kind: CONTRACT + admin RUNTIME
- Regression contract: when the parent Product is intentionally excluded, the local override may be configured but the UI states it will not take effect while source pricing mutations remain excluded.

### UX-VARPRICE-023 — disabled/unready owner does not erase local configuration
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: an owned variation on a disabled/unprovisioned/topology-unready Connection can retain/configure its local override, while copy makes clear that pricing mutation waits for runtime readiness; the setting is not silently cleared.

### UX-VARPRICE-024 — local pricing override storage is distinct from identity authority
- Kind: ARCHITECTURE + CONTRACT
- Regression contract: `mobo_additional_price` remains a local Woo pricing preference in postmeta for compatibility, while Mobo identity/Connection ownership is proven from the authoritative Custom-Storage rows after terminal migration; tests must not conflate the two storage responsibilities.


## T. Global WordPress Admin surface — notices / toolbar (UX25)

### UX-GLOBALADMIN-001 — city-asset notice evaluates all runtime-eligible Connections
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: the global city-file warning scans every enabled, provisioned Connection with automatic Mobo order submission enabled; a broken non-default owner cannot be hidden by a healthy Default Connection.

### UX-GLOBALADMIN-002 — disabled Connections do not create active city-file warnings
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: a disabled Connection with stale `mobo_core_mobo_order_submission_enabled=1` is not presented as an active Checkout problem merely because its historical city asset is missing.

### UX-GLOBALADMIN-003 — unprovisioned Connections do not create city-file warnings
- Kind: CONTRACT + RUNTIME
- Regression contract: Pending/incompletely provisioned Connections are excluded from the actionable city-asset notice until server-owned routing metadata is complete and the Connection is runtime eligible.

### UX-GLOBALADMIN-004 — city-file warning respects multi-Connection topology readiness
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: when multi-Connection ownership/runtime topology is not ready, the secondary city-asset warning is suppressed so the more fundamental Connection/Site Health blocker remains authoritative.

### UX-GLOBALADMIN-005 — healthy city assets produce no global warning
- Kind: CONTRACT + admin RUNTIME
- Regression contract: when every runtime-eligible Auto Order Connection has a readable generated city asset, no Mobo city-file notice is rendered on unrelated wp-admin screens.

### UX-GLOBALADMIN-006 — raw city generator/filesystem errors stay out of global notices
- Kind: STATIC + privacy RUNTIME
- Regression contract: `lastError`, filesystem paths and low-level generator diagnostics are never echoed into the global notice; technical details remain on Connection-scoped diagnostics.

### UX-GLOBALADMIN-007 — city warning action targets the failing Connection
- Kind: STATIC + multi-Connection RUNTIME
- Regression contract: the remediation link opens Mobo Order/Checkout settings with `mobo_connection_id` for the first failing owner and never silently redirects the operator to the Default Connection.

### UX-GLOBALADMIN-008 — multiple city failures are aggregated, not spammed
- Kind: STATIC + admin RUNTIME
- Regression contract: several failing Connections generate one bounded notice that names at most the first few owners plus a remaining count; MoboCore does not emit one global notice per Connection.

### UX-GLOBALADMIN-009 — city warning is shown only to users who can fix it
- Kind: CONTRACT + capability RUNTIME
- Regression contract: the global city-file notice requires `manage_options`, matching the capability required by the Mobo remediation page; Shop Managers without that capability are not shown an inaccessible action.

### UX-GLOBALADMIN-010 — pre-Connection single-owner compatibility remains available
- Kind: CONTRACT + upgrade RUNTIME
- Regression contract: before durable Connection storage exists, the legacy single-owner installation can still receive one generic city-asset warning without inventing multi-Connection ownership.

### UX-GLOBALADMIN-011 — Sync/Repair lock notice records Connection identity
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: a protected queue-setting write blocked during Sync/Repair stores the effective Connection id/label with the user-scoped transient so the later notice identifies which Source was locked.

### UX-GLOBALADMIN-012 — Sync/Repair lock notice remains user-scoped and one-shot
- Kind: CONTRACT + admin RUNTIME
- Regression contract: the lock notice is keyed by current user, is consumed after rendering, and a blocked write for one administrator cannot leak into another administrator's session.

### UX-GLOBALADMIN-013 — Sync/Repair lock notice requires Mobo admin capability
- Kind: CONTRACT + capability RUNTIME
- Regression contract: the global queue-settings lock notice is rendered only to `manage_options` users; lower-privilege accounts do not receive internal Mobo settings warnings they cannot act on.

### UX-GLOBALADMIN-014 — dependency notice distinguishes missing from inactive
- Kind: STATIC + dependency RUNTIME
- Regression contract: required plugins that are installed-but-disabled are labelled differently from plugins that are not installed, so the remediation step is not ambiguous.

### UX-GLOBALADMIN-015 — dependency notice supports network plugin administrators
- Kind: CONTRACT + multisite RUNTIME
- Regression contract: on Multisite/network admin, a user with `manage_network_plugins` can see the dependency/platform remediation notice even if the site-level `activate_plugins` capability is not the relevant control.

### UX-GLOBALADMIN-016 — dependency/platform notices remain capability-gated
- Kind: CONTRACT + capability RUNTIME
- Regression contract: users without site or network plugin-management capability never receive dependency/platform notices that expose internal installation requirements but cannot be acted upon.

### UX-GLOBALADMIN-017 — Mobo admin toolbar remains manage-options only
- Kind: STATIC + admin-bar RUNTIME
- Regression contract: the Mobo toolbar node and its navigation are visible only when the admin bar is showing and the user has `manage_options`; UX25 notice changes must not broaden this capability boundary.

### UX-GLOBALADMIN-018 — toolbar navigation preserves explicit Connection context
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: when an administrator is already working in an explicit Mobo Connection context, toolbar links preserve `mobo_connection_id`; an arbitrary unrelated wp-admin page may fall back to the enabled Default but never forges another owner.


## U. WordPress Plugins screen / Mobo Core action links (UX26)

### UX-PLUGINROW-001 — healthy site-admin row exposes only usable Mobo destinations
- Kind: STATIC + admin RUNTIME
- Regression contract: when platform requirements and both required plugins are ready, a site administrator with `manage_options` sees «تنظیمات موبو» and «سلامت سایت» links and both destinations are registered/usable.

### UX-PLUGINROW-002 — dependency failure hides unavailable Mobo menu destinations
- Kind: CONTRACT + dependency RUNTIME
- Regression contract: when WooCommerce or Persian WooCommerce is missing/inactive and Mobo bootstrap stops before `Mobo_Core_Admin::init()`, the Plugins row does not expose Settings/Health links to pages that were never registered.

### UX-PLUGINROW-003 — active dependencies do not create prerequisite-link clutter
- Kind: STATIC + dependency RUNTIME
- Regression contract: WooCommerce/Persian WooCommerce links are omitted when the dependency is already active; the row never invites an operator to reinstall a healthy prerequisite.

### UX-PLUGINROW-004 — installed-but-inactive dependency points to plugin management
- Kind: CONTRACT + dependency RUNTIME
- Regression contract: an installed but inactive required plugin is labelled as a management/remediation action and links to the current Plugins screen rather than incorrectly opening WordPress.org installation search.

### UX-PLUGINROW-005 — missing installable dependency points to installer search
- Kind: CONTRACT + dependency RUNTIME
- Regression contract: a required plugin that is not installed links to the current admin context's plugin installer search only when the current user has `install_plugins`.

### UX-PLUGINROW-006 — users without install capability are not sent to inaccessible installer UI
- Kind: CONTRACT + capability RUNTIME
- Regression contract: if a dependency is missing but the current plugin manager cannot install plugins, the action falls back to the Plugins management screen instead of exposing an inaccessible plugin-install destination.

### UX-PLUGINROW-007 — site Mobo settings links require manage_options
- Kind: CONTRACT + capability RUNTIME
- Regression contract: users who can see/manage plugins but do not have `manage_options` never receive Settings/Health action links that would end in an authorization error.

### UX-PLUGINROW-008 — platform blocker is visible without pretending settings are available
- Kind: STATIC + 32-bit fixture RUNTIME
- Regression contract: on unsupported 32-bit PHP, the row surfaces the PHP 64-bit requirement while withholding Mobo Settings/Health destinations that cannot bootstrap safely.

### UX-PLUGINROW-009 — Network Admin uses the network-specific action-link hook
- Kind: STATIC + multisite RUNTIME
- Regression contract: Mobo registers both `plugin_action_links_{plugin}` and `network_admin_plugin_action_links_{plugin}` so the row behavior is defined on both site and Network Admin Plugins tables.

### UX-PLUGINROW-010 — Network Admin does not forge site-only Mobo settings links
- Kind: CONTRACT + multisite RUNTIME
- Regression contract: because the Mobo menu is registered on `admin_menu` rather than `network_admin_menu`, Network Admin plugin rows do not expose site Settings/Health links as though network-wide settings pages existed.

### UX-PLUGINROW-011 — dependency remediation URL follows current admin context
- Kind: CONTRACT + multisite RUNTIME
- Regression contract: dependency management/install links use `self_admin_url()` so site admin remains site-scoped and Network Admin remains network-scoped.

### UX-PLUGINROW-012 — network plugin managers can see dependency remediation
- Kind: CONTRACT + multisite capability RUNTIME
- Regression contract: on Multisite, `manage_network_plugins` is accepted for dependency remediation even when site-level `activate_plugins` is not the relevant capability.

### UX-PLUGINROW-013 — plugin-row remediation never mutates dependency state automatically
- Kind: STATIC + security RUNTIME
- Regression contract: action links only navigate to WordPress plugin management/installation UI; rendering or clicking the generic management link never silently activates, network-activates, installs, deletes or upgrades another plugin.

### UX-PLUGINROW-014 — existing WordPress core plugin actions are preserved
- Kind: CONTRACT + admin RUNTIME
- Regression contract: adding Mobo contextual links preserves the existing Activate/Deactivate/Delete/Network action array supplied by WordPress and does not replace or reorder away core safety controls.

### UX-PLUGINROW-015 — dependency state is evaluated at render time
- Kind: CONTRACT + lifecycle RUNTIME
- Regression contract: deactivating a required plugin after Mobo was already active changes the Mobo row from Settings/Health to dependency remediation on the next Plugins-screen render without relying on stale cached status.

### UX-PLUGINROW-016 — plugin-row links never expose Mobo credentials or runtime internals
- Kind: STATIC + privacy RUNTIME
- Regression contract: Plugins-row labels/URLs contain no Connection token, X-SEC, source/backend URL, internal Connection id or raw dependency diagnostic payload.


## W. WooCommerce Shipping Zones / retired Mobo shipping method surface (UX27)

### UX-SHIPPINGZONE-001 — Mapping-only policy removes `mobo_core_shipping` from the `woocommerce_shipping_methods` registry
- Kind: STATIC + WooCommerce RUNTIME
- Regression contract: Mapping-only policy removes `mobo_core_shipping` from the `woocommerce_shipping_methods` registry; current MoboCore never exposes its retired custom rate as a selectable/checkout method.

### UX-SHIPPINGZONE-002 — the retained `register_shipping_method()` compatibility callback cannot re-register the retired Mobo method even when stale integration code calls it directly
- Kind: CONTRACT + backward-compat RUNTIME
- Regression contract: the retained `register_shipping_method()` compatibility callback cannot re-register the retired Mobo method even when stale integration code calls it directly.

### UX-SHIPPINGZONE-003 — direct instantiation of `WC_Mobo_Core_Shipping_Method` can never publish a checkout rate
- Kind: CONTRACT + checkout RUNTIME
- Regression contract: direct instantiation of `WC_Mobo_Core_Shipping_Method` can never publish a checkout rate; `calculate_shipping()` is an unconditional no-op under mapping-only policy.

### UX-SHIPPINGZONE-004 — the legacy compatibility class exposes no editable shipping-zone/instance-settings support and does not register a `process_admin_options` mutation hook
- Kind: STATIC + admin RUNTIME
- Regression contract: the legacy compatibility class exposes no editable shipping-zone/instance-settings support and does not register a `process_admin_options` mutation hook.

### UX-SHIPPINGZONE-005 — legacy class title/description identify the method as retired and mapping-only rather than claiming it is still automatically managed for customer checkout
- Kind: STATIC + admin RUNTIME
- Regression contract: legacy class title/description identify the method as retired and mapping-only rather than claiming it is still automatically managed for customer checkout.

### UX-SHIPPINGZONE-006 — a previously stored mapping-only policy checkpoint is treated as a migration checkpoint, not proof that a legacy Mobo zone row can never be re-enabled later
- Kind: CONTRACT + upgrade RUNTIME
- Regression contract: a previously stored mapping-only policy checkpoint is treated as a migration checkpoint, not proof that a legacy Mobo zone row can never be re-enabled later.

### UX-SHIPPINGZONE-007 — on wp-admin requests, a re-enabled legacy `mobo_core_shipping` residue is re-proved and disabled even when the policy checkpoint was already written
- Kind: CONTRACT + admin RUNTIME
- Regression contract: on wp-admin requests, a re-enabled legacy `mobo_core_shipping` residue is re-proved and disabled even when the policy checkpoint was already written.

### UX-SHIPPINGZONE-008 — ordinary storefront requests do not add a new shipping-zone residue query merely to re-prove an already-checkpointed cleanup
- Kind: PERFORMANCE + storefront RUNTIME
- Regression contract: ordinary storefront requests do not add a new shipping-zone residue query merely to re-prove an already-checkpointed cleanup; checkout safety comes from registry removal while admin/repair performs residue proof.

### UX-SHIPPINGZONE-009 — legacy `mobo_core_shipping` rows are discovered by the plugin-owned method id itself, so an orphan/untracked instance missing from old managed-option maps cannot remain active
- Kind: CONTRACT + migration RUNTIME
- Regression contract: legacy `mobo_core_shipping` rows are discovered by the plugin-owned method id itself, so an orphan/untracked instance missing from old managed-option maps cannot remain active.

### UX-SHIPPINGZONE-010 — retirement writes and verifies both the WooCommerce zone-table enabled state and the instance option `enabled=no`
- Kind: CONTRACT + storage RUNTIME
- Regression contract: retirement writes and verifies both the WooCommerce zone-table enabled state and the instance option `enabled=no`; disagreement is a cleanup failure rather than success.

### UX-SHIPPINGZONE-011 — if a legacy Mobo instance cannot be durably disabled, cleanup remains failed and a successful mapping-only checkpoint is not newly asserted from the attempted mutation
- Kind: CONTRACT + failure-injection RUNTIME
- Regression contract: if a legacy Mobo instance cannot be durably disabled, cleanup remains failed and a successful mapping-only checkpoint is not newly asserted from the attempted mutation.

### UX-SHIPPINGZONE-012 — unrelated merchant-owned WooCommerce shipping methods are never disabled or deleted by mapping-only cleanup
- Kind: CONTRACT + merchant-safety RUNTIME
- Regression contract: unrelated merchant-owned WooCommerce shipping methods are never disabled or deleted by mapping-only cleanup.

### UX-SHIPPINGZONE-013 — legacy fallback `flat_rate` instances are touched only when their instance ids are present in MoboCore's durable historical ownership map
- Kind: CONTRACT + merchant-safety RUNTIME
- Regression contract: legacy fallback `flat_rate` instances are touched only when their instance ids are present in MoboCore's durable historical ownership map; arbitrary Flat Rate methods are out of scope.

### UX-SHIPPINGZONE-014 — legacy mirrored merchant-method instances are touched only when their recorded MoboCore ownership map proves the mirror
- Kind: CONTRACT + merchant-safety RUNTIME
- Regression contract: legacy mirrored merchant-method instances are touched only when their recorded MoboCore ownership map proves the mirror; same-type merchant methods outside that map are preserved.

### UX-SHIPPINGZONE-015 — the old default Mobo zone is deleted only when it is the known Mobo legacy zone and contains no merchant-owned method
- Kind: CONTRACT + zone-cleanup RUNTIME
- Regression contract: the old default Mobo zone is deleted only when it is the known Mobo legacy zone and contains no merchant-owned method; a mixed/renamed merchant zone is preserved.

### UX-SHIPPINGZONE-016 — current checkout shipping uses mapping from merchant WooCommerce rates to Source `shipping_id`
- Kind: ARCHITECTURE + CONTRACT
- Regression contract: current checkout shipping uses mapping from merchant WooCommerce rates to Source `shipping_id`; MoboCore does not create/inject a dedicated customer-facing Mobo shipping rate.

### UX-SHIPPINGZONE-017 — legacy `mobo_shipping_id` data, if retained for diagnosis/cleanup, is not presented as a live editable routing control and never exposes Connection credentials
- Kind: STATIC + privacy RUNTIME
- Regression contract: legacy `mobo_shipping_id` data, if retained for diagnosis/cleanup, is not presented as a live editable routing control and never exposes Connection credentials.

### UX-SHIPPINGZONE-018 — mapping-only retirement continues to execute inside each Connection context for Connection-scoped legacy fallback/mirror ownership maps while the plugin-owned custom method id remains globally retired
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: mapping-only retirement continues to execute inside each Connection context for Connection-scoped legacy fallback/mirror ownership maps while the plugin-owned custom method id remains globally retired.

### UX-SHIPPINGZONE-019 — explicit Repair/admin mapping cleanup forces a fresh retirement pass and does not trust the prior checkpoint when verifying legacy shipping residue
- Kind: CONTRACT + Repair RUNTIME
- Regression contract: explicit Repair/admin mapping cleanup forces a fresh retirement pass and does not trust the prior checkpoint when verifying legacy shipping residue.

### UX-SHIPPINGZONE-020 — `install_or_repair()` remains mapping-only: it may retire old configuration but never creates a Mobo shipping class, zone or custom shipping-method instance in current runtime
- Kind: CONTRACT + RUNTIME
- Regression contract: `install_or_repair()` remains mapping-only: it may retire old configuration but never creates a Mobo shipping class, zone or custom shipping-method instance in current runtime.

## X. WooCommerce Order Item Details / Revenue Ledger isolation (UX28)

### UX-REVENUE-001 — line-item Connection identity is never customer-facing metadata
- Kind: STATIC + WooCommerce RUNTIME
- Regression contract: `_mobo_identity_connection_id` is hidden from WooCommerce admin line-item metadata and from display-oriented formatted item metadata used by customer order details, emails, and compatible invoice/PDF integrations.

### UX-REVENUE-002 — every immutable line-item identity key shares one hidden-meta contract
- Kind: STATIC + CONTRACT
- Regression contract: the Checkout Validator exposes the complete immutable identity-key set and the Revenue Ledger consumes that central set instead of maintaining a drifting partial duplicate list.

### UX-REVENUE-003 — revenue source-cost metadata remains private
- Kind: STATIC + WooCommerce RUNTIME
- Regression contract: `_mobo_revenue_source_unit_cost` and `_mobo_revenue_source_snapshot_at` stay stored for immutable accounting but never appear in normal order-item display surfaces.

### UX-REVENUE-004 — checkout source-cost capture prefers immutable line ownership
- Kind: CONTRACT + checkout RUNTIME
- Regression contract: when Checkout Validator has already captured line-item identity, Revenue Ledger uses that immutable `isMobo`/Connection evidence instead of reclassifying the item from ambient live catalogue state.

### UX-REVENUE-005 — non-default Connection items snapshot source cost in their owner context
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: a line item owned by a non-default Connection can capture its source-unit-cost snapshot without inheriting the Default/admin Connection.

### UX-REVENUE-006 — live fallback ownership is installation-global, not ambient-Connection scoped
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: the limited checkout-time fallback resolves Product/Variation ownership through global Custom Storage owner lookups and rejects Product/Variation owner disagreement.

### UX-REVENUE-007 — unavailable terminal ownership fails closed
- Kind: CONTRACT + failure-injection RUNTIME
- Regression contract: when authoritative Custom Storage/schema is unavailable, Revenue Ledger does not resurrect retired identity postmeta or guess Mobo ownership for a new source-cost snapshot.

### UX-REVENUE-008 — ledger creation requires immutable all-Mobo scope
- Kind: CONTRACT + order RUNTIME
- Regression contract: a completed order is eligible for a new Revenue Ledger record only when every Woo line item has immutable captured identity and is Mobo-owned.

### UX-REVENUE-009 — mixed orders cannot create new revenue records
- Kind: CONTRACT + order RUNTIME
- Regression contract: any completed order containing both Mobo and non-Mobo line items is rejected by Revenue Ledger even if malformed/manual metadata claims `_mobo_order_submitted=yes`.

### UX-REVENUE-010 — multi-Connection orders cannot create new revenue records
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: immutable Mobo line items owned by different Connections cause fail-closed ledger rejection; the financial ledger never aggregates a multi-Source order into one owner.

### UX-REVENUE-011 — missing immutable Connection ownership blocks new ledger creation
- Kind: CONTRACT + failure-injection RUNTIME
- Regression contract: a captured Mobo line without a positive immutable Connection id cannot create a Revenue Ledger record.

### UX-REVENUE-012 — new revenue records persist owning Connection id
- Kind: CONTRACT + HPOS/CPT RUNTIME
- Regression contract: every newly created ledger payload includes `connectionId` and the order stores `_mobo_revenue_connection_id` through WooCommerce CRUD.

### UX-REVENUE-013 — revenue Connection ownership is read-back verified
- Kind: CONTRACT + storage-failure RUNTIME
- Regression contract: ledger success is not published, cache is not invalidated, and `mobo_core_revenue_ledger_recorded` is not fired unless the reloaded order proves both ledger payload `connectionId` and `_mobo_revenue_connection_id` match the intended owner.

### UX-REVENUE-014 — record iteration rechecks immutable owner consistency
- Kind: CONTRACT + order RUNTIME
- Regression contract: while calculating a record, every included line item must still match the resolved immutable Connection owner; an ownership mismatch aborts rather than producing partial financial data.

### UX-REVENUE-015 — authenticated Portal revenue summary is Connection-scoped
- Kind: CONTRACT + REST multi-Connection RUNTIME
- Regression contract: after `check_security()` authenticates a Connection, `/portal/revenue-summary` passes that exact Connection id into the ledger summary and returns no installation-global aggregate for that request.

### UX-REVENUE-016 — REST revenue endpoint fails closed when Connection scope is missing
- Kind: CONTRACT + REST failure RUNTIME
- Regression contract: once durable Connection storage exists, `/portal/revenue-summary` returns an error if authentication did not establish a positive Connection context instead of falling back to global financial data.

### UX-REVENUE-017 — Connection A cannot observe Connection B revenue
- Kind: security CONTRACT + multi-Connection RUNTIME
- Regression contract: totals, recent orders, Mobo order ids and monetary values belonging to another Connection are absent from an authenticated Connection-scoped response.

### UX-REVENUE-018 — revenue summary cache is scoped by Connection
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: cached summary data uses independent scope keys (`connection:<id>` vs installation) so a cache warmed by one Source can never satisfy another Source's request.

### UX-REVENUE-019 — ledger revision invalidates all scoped summary caches
- Kind: CONTRACT + RUNTIME
- Regression contract: recording a verified new immutable ledger record increments the shared revision/deletes the summary cache so every Connection scope is rebuilt from current immutable records.

### UX-REVENUE-020 — legacy records are scoped only from immutable evidence
- Kind: CONTRACT + upgrade RUNTIME
- Regression contract: an old ledger payload without `connectionId` may join a Connection-scoped summary only when `_mobo_revenue_connection_id` or complete immutable line-item identity proves that owner; live catalogue ownership is not used to guess historical financial ownership.

### UX-REVENUE-021 — unscoped legacy revenue never leaks across Connections
- Kind: security CONTRACT + upgrade RUNTIME
- Regression contract: a legacy record whose Connection cannot be proven from immutable evidence is omitted from Connection-scoped Portal summaries rather than being assigned to the current/default Connection.

### UX-REVENUE-022 — installation-wide helper remains explicit and separate
- Kind: CONTRACT
- Regression contract: internal/backward-compatible `get_summary(0)` may retain installation-wide semantics, but external Connection-authenticated Portal revenue calls must always request a positive owner scope when durable Connection storage is present.

### UX-REVENUE-023 — HPOS and legacy order storage preserve private metadata behavior
- Kind: HPOS + legacy CPT RUNTIME
- Regression contract: hidden item metadata, Connection ownership meta, immutable ledger persistence and scoped summary behavior are identical under WooCommerce HPOS and legacy order storage.

### UX-REVENUE-024 — immutable cost snapshot survives later catalogue price changes
- Kind: CONTRACT + order RUNTIME
- Regression contract: after a verified source-cost snapshot is stored on the order item, later `mobo_api_price` changes do not rewrite historical ledger cost/profit for that order.

## Y. Checkout / Cart frontend + Store API ownership and validation isolation (UX29)

### UX-CHECKOUT-001 — terminal Custom Storage ownership is the sole Mobo cart ownership authority
- Kind: CONTRACT + checkout RUNTIME
- Regression contract: a Woo cart item is treated as Mobo-owned only when Product/Variation Custom Storage resolves a positive owning Connection; legacy GUID/URL/id compatibility meta alone cannot resurrect Mobo ownership.

### UX-CHECKOUT-002 — Product/Variation owner disagreement fails closed
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: when durable Product and Variation ownership disagree, the item resolves to no valid Connection and cannot enter Mobo validation/submission as a guessed owner.

### UX-CHECKOUT-003 — immutable checkout line snapshot uses durable owner before identity values
- Kind: CONTRACT + checkout RUNTIME
- Regression contract: order-line `isMobo`/`connectionId` are frozen from durable ownership first; GUID/Portal identifiers are copied only after that ownership is proven.

### UX-CHECKOUT-004 — stale retired identity meta cannot mark a new order line as Mobo
- Kind: upgrade CONTRACT + RUNTIME
- Regression contract: stale `mobo_url`, `product_guid`, `variant_guid` or Portal-id compatibility values without durable owner evidence do not create a Mobo immutable order-line snapshot.

### UX-CHECKOUT-005 — live fallback order identity follows the same terminal ownership contract
- Kind: CONTRACT + legacy-order RUNTIME
- Regression contract: an old order item without immutable capture may derive a live compatibility view, but `isMobo` is true only after durable Connection ownership is proven.

### UX-CHECKOUT-006 — single-Connection cart validation runs inside its actual owner context
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: a cart whose Mobo items belong to one non-default Connection runs local/remote/external checks inside that Connection and never inherits Default/admin settings or credentials.

### UX-CHECKOUT-007 — multi-Connection cart no longer skips configured validation
- Kind: CONTRACT + multi-Connection checkout RUNTIME
- Regression contract: a cart containing Mobo products from multiple Connections does not return unconditional validation success merely because no single shared Source account exists.

### UX-CHECKOUT-008 — multi-Connection validation is partitioned by durable owner
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: each owning Connection validates only its own Mobo cart subset; another Connection's items are skipped rather than reported as context mismatch inside that partition.

### UX-CHECKOUT-009 — per-Connection Mobo shared-cart validation never mixes Sources
- Kind: security CONTRACT + checkout RUNTIME
- Regression contract: if Mobo cart validation is enabled on multiple Connections, clear/add/snapshot operations run independently inside each owner context and never build one shared remote cart from cross-Source items.

### UX-CHECKOUT-010 — per-Connection External Validation receives only that Connection's Mobo subset
- Kind: privacy/security CONTRACT + RUNTIME
- Regression contract: an External Validation URL configured for Connection A never receives cart payload rows owned by Connection B.

### UX-CHECKOUT-011 — per-Connection validation settings remain independent
- Kind: CONTRACT + multi-Connection RUNTIME
- Regression contract: Local Stock, Mobo Cart Validation and External Validation switches are evaluated inside each owner Connection; enabling a check on A neither enables nor suppresses B's policy.

### UX-CHECKOUT-012 — merged multi-Connection result fails when any owner subset fails
- Kind: CONTRACT + checkout RUNTIME
- Regression contract: combined validation succeeds only when every evaluated Connection subset succeeds; errors are sanitized, deduplicated and returned without silently discarding another Source's failure.

### UX-CHECKOUT-013 — multi-Connection checkout validation does not weaken order-submission fail-closed policy
- Kind: ARCHITECTURE + RUNTIME
- Regression contract: successfully validating each Source subset does not make a final multi-Connection Woo order eligible for automatic Mobo submission; Order Submission remains all-Mobo plus exactly-one-Connection.

### UX-CHECKOUT-014 — non-Mobo items remain outside Mobo remote payloads in mixed carts
- Kind: CONTRACT + checkout RUNTIME
- Regression contract: ordinary Woo items in a mixed cart are not sent to Mobo cart validation or Connection-scoped External Validation payloads, while the Mobo subset can still be validated according to its owner policy.

### UX-CHECKOUT-015 — core checkout failures cannot be erased by third-party validation filters
- Kind: security CONTRACT + RUNTIME
- Regression contract: `mobo_core_checkout_validation_errors` may add stricter diagnostics/errors but cannot remove built-in identity, stock, Mobo-cart or External Validation failures after the merchant enabled those checks.

### UX-CHECKOUT-016 — filter hardening applies regardless of Auto Order state
- Kind: CONTRACT + RUNTIME
- Regression contract: core checkout validation remains non-bypassable both when automatic Mobo order submission is enabled and when it is disabled; disabling Auto Order does not restore replacement semantics for core safety errors.

### UX-CHECKOUT-017 — cart-page validation remains local-only for remote shared-cart operations
- Kind: CONTRACT + classic-checkout RUNTIME
- Regression contract: cart-page notices do not mutate the shared Mobo remote cart; clear/rebuild remote validation occurs only at the explicit checkout validation boundary.

### UX-CHECKOUT-018 — order-review Ajax does not inject stale Mobo error notices
- Kind: CONTRACT + classic-checkout RUNTIME
- Regression contract: `update_order_review` requests do not add persistent Mobo cart error notices that can suppress otherwise valid WooCommerce shipping rates.

### UX-CHECKOUT-019 — Classic Checkout and Checkout Block preserve equivalent fail-closed validation outcomes
- Kind: classic + Store API RUNTIME
- Regression contract: the same ownership and validation failure blocks payment in classic `woocommerce_after_checkout_validation` and Checkout Block/Store API flows without one frontend silently bypassing enabled checks.

### UX-CHECKOUT-020 — checkout ownership/validation remains correct when terminal storage is unavailable
- Kind: failure-injection RUNTIME
- Regression contract: Custom Storage/schema unavailability must never cause legacy postmeta ownership resurrection, cross-Connection validation, or credential fallback; the final behavior must remain explicit and fail-safe for Mobo-owned purchase boundaries.

## Z. REST runtime authentication + exception disclosure hardening (UX30)

### UX-RESTAUTH-001 — runtime REST requires durable Connection storage
- Kind: security CONTRACT + failure-injection RUNTIME
- Regression contract: once the multi-Connection architecture is active, every route using `check_security()` fails closed with 503 when the Connection repository/table is unavailable; no legacy installation-wide X-SEC fallback authenticates the request.

### UX-RESTAUTH-002 — missing Connection table cannot authenticate with legacy global X-SEC
- Kind: security CONTRACT
- Regression contract: a valid historical `mobo_core_security_code` option alone is insufficient to authenticate runtime REST while durable Connection storage is absent.

### UX-RESTAUTH-003 — provisioning keeps its independent signed-token auth path
- Kind: security CONTRACT + RUNTIME
- Regression contract: `/connection/provision` remains governed exclusively by `check_provisioning_signature()` and does not depend on the runtime X-SEC fail-closed change.

### UX-RESTAUTH-004 — valid Connection X-SEC still establishes exact Connection context
- Kind: security CONTRACT + multi-Connection RUNTIME
- Regression contract: when the repository is healthy, WebHookId/X-SEC authentication resolves exactly one Connection and establishes that owner context before callback execution.

### UX-RESTAUTH-005 — ambiguous/missing WebHookId never falls back across Sources
- Kind: security CONTRACT + multi-Connection RUNTIME
- Regression contract: requests that cannot unambiguously bind an enabled Connection are rejected rather than inheriting the default/legacy installation credential.

### UX-RESTAUTH-006 — auth failure diagnostics cannot turn into authentication
- Kind: security CONTRACT
- Regression contract: candidate-scoped webhook-auth status writes remain diagnostics only and never authorize a request whose X-SEC/owner resolution failed.

### UX-RESTAUTH-007 — heartbeat exception response hides raw exception text
- Kind: security CONTRACT + failure-injection RUNTIME
- Regression contract: a thrown heartbeat worker exception returns a stable public status/message without exposing the raw exception message or PHP class.

### UX-RESTAUTH-008 — webhook queue exception response hides implementation details
- Kind: security CONTRACT + failure-injection RUNTIME
- Regression contract: `/webhook/run` exception responses contain no exception class, source file, line number or raw exception text.

### UX-RESTAUTH-009 — full REST exception diagnostics remain local
- Kind: CONTRACT + failure-injection RUNTIME
- Regression contract: hidden exception class/message/file/line evidence is written only to local server diagnostics/logging and is not serialized into the authenticated REST response.

### UX-RESTAUTH-010 — persisted webhook-queue last result is safe for Admin/UI reuse
- Kind: CONTRACT + RUNTIME
- Regression contract: exception results stored in `mobo_core_webhook_queue_last_result` contain only operator-safe status/message fields and cannot later leak raw filesystem/PHP details through Admin health surfaces.

### UX-RESTAUTH-011 — normal non-exception queue results remain unchanged
- Kind: CONTRACT + RUNTIME
- Regression contract: removing exception disclosure does not alter successful/ordinary queue counters, continuation semantics or processing behavior.

### UX-RESTAUTH-012 — runtime auth outage is distinguishable from bad credentials
- Kind: CONTRACT + RUNTIME
- Regression contract: missing/unavailable Connection auth storage returns a service-unavailable failure distinct from an invalid X-SEC/WebHookId authentication failure.

### UX-RESTAUTH-013 — no runtime route silently reintroduces legacy global auth
- Kind: STATIC + CONTRACT
- Regression contract: all routes registered with `check_security()` share the durable Connection-only authentication boundary and no callback performs a secondary `mobo_core_security_code` authorization fallback.

### UX-RESTAUTH-014 — terminal migration failure cannot resurrect retired auth authority
- Kind: upgrade CONTRACT + failure-injection RUNTIME
- Regression contract: schema/table failure after terminal migration never causes a request that would fail under durable Connection ownership to succeed through retired legacy auth state.

## AA. Installation-global REST authority isolation (UX31)

### UX-INSTALLREST-001 — installation-global routes use a distinct permission boundary
- Kind: security STATIC + CONTRACT
- Regression contract: `/health`, `/heartbeat`, `/health/report-now`, `/cron/status`, `/worker/status`, `/upgrade/status` and `/upgrade/apply` use `check_installation_security()` rather than the ordinary Source-scoped `check_security()` callback.

### UX-INSTALLREST-002 — Source-scoped routes remain Source-scoped
- Kind: security CONTRACT
- Regression contract: webhook, sync, category, Portal operation, revenue and Portal-settings routes continue to authenticate their owning Connection and are not accidentally restricted to the Default Connection.

### UX-INSTALLREST-003 — Default Connection is the Installation Controller
- Kind: ARCHITECTURE + CONTRACT
- Regression contract: the enabled Default Connection is the sole X-SEC authority for site-wide Health/Worker visibility and remote plugin upgrade operations.

### UX-INSTALLREST-004 — non-default Connection cannot read global Health
- Kind: security multi-Connection RUNTIME
- Regression contract: valid Connection B credentials receive HTTP 403 from `/health` when Connection A is the enabled Default/Installation Controller; installation-wide paths, queue counts and diagnostics are not disclosed to B.

### UX-INSTALLREST-005 — non-default Connection cannot run shared Heartbeat
- Kind: security multi-Connection RUNTIME
- Regression contract: a valid non-default Source credential cannot invoke the shared site-wide bounded worker through `/heartbeat`.

### UX-INSTALLREST-006 — non-default Connection cannot read Cron/Self-Worker global status
- Kind: security multi-Connection RUNTIME
- Regression contract: `/cron/status` and `/worker/status` reject non-default Source credentials rather than exposing installation-global scheduler/worker state.

### UX-INSTALLREST-007 — non-default Connection cannot inspect remote-upgrade state
- Kind: security multi-Connection RUNTIME
- Regression contract: `/upgrade/status` rejects valid non-default Source credentials and does not disclose plugin-wide deployment/barrier/backup status.

### UX-INSTALLREST-008 — non-default Connection cannot apply a plugin-wide upgrade
- Kind: security multi-Connection RUNTIME
- Regression contract: `/upgrade/apply` is rejected before package/signature/install work when the authenticated Connection is not the Installation Controller.

### UX-INSTALLREST-009 — upgrade signature key is the controller Connection credential
- Kind: security CONTRACT + RUNTIME
- Regression contract: after installation-scope authorization, Remote Updater HMAC verification resolves the current/default controller Connection security code and never signs a global upgrade under another Source credential.

### UX-INSTALLREST-010 — missing enabled Default controller fails closed
- Kind: security failure-injection RUNTIME
- Regression contract: if no enabled Default Connection can be resolved, installation-global X-SEC routes fail with service-unavailable instead of promoting another enabled Source implicitly.

### UX-INSTALLREST-011 — installation controller check runs after exact Connection authentication
- Kind: security CONTRACT
- Regression contract: `check_installation_security()` first executes ordinary WebHookId/X-SEC owner authentication and only then compares the authenticated owner with the enabled Default; it never trusts a claimed controller id from request parameters.

### UX-INSTALLREST-012 — local Cron/Worker execution keeps its separate credential boundary
- Kind: ARCHITECTURE + RUNTIME
- Regression contract: `/cron/run` and `/worker/run` remain protected by the dedicated Cron/Worker token path used by local/cPanel/self-runner execution and do not require a Portal Source credential.

### UX-INSTALLREST-013 — Health and Heartbeat cannot be CDN/page-cache substituted
- Kind: CONTRACT + RUNTIME
- Regression contract: restricting Heartbeat to the controller does not remove its POST-only no-store/no-cache headers or the `X-Mobo-Heartbeat: 1` evidence.

### UX-INSTALLREST-014 — controller Health remains read-only
- Kind: CONTRACT + RUNTIME
- Regression contract: controller authorization does not change GET `/health` semantics; it remains reporting-only and never executes the worker.

### UX-INSTALLREST-015 — legacy health-report-now follows the same installation scope
- Kind: security CONTRACT + RUNTIME
- Regression contract: compatibility `/health/report-now` cannot be used by a non-default Source to retrieve the same installation-wide health snapshot indirectly.

### UX-INSTALLREST-016 — single-Connection installations remain compatible
- Kind: upgrade CONTRACT + RUNTIME
- Regression contract: with exactly one enabled Default Connection, its existing X-SEC/WebHookId continues to authorize Health, Heartbeat, status and remote-upgrade routes without additional user configuration.

### UX-INSTALLREST-017 — adding a second Source does not widen installation authority
- Kind: multi-Connection RUNTIME
- Regression contract: provisioning/enabling a second Connection leaves installation-global control on the Default Connection; the new Source receives only its Source-scoped API authority.

### UX-INSTALLREST-018 — documentation identifies installation-global vs Source-scoped credentials
- Kind: STATIC documentation CONTRACT
- Regression contract: README/FUNCTIONS/Heartbeat/Remote Deployment documentation consistently identifies Default Connection X-SEC as Installation Controller authority and does not imply any Source credential can control site-wide worker/upgrade state.


## AB. Portal remote-control Connection isolation (UX32)

### UX-PORTALCTRL-001 — last operation identity is Connection-scoped
- Regression contract: `requestId`, operation type, timestamps and terminal status written by `/portal/operations/*` are stored under the authenticated Connection and cannot be read by another Connection.

### UX-PORTALCTRL-002 — operation status executes in authenticated Connection context
- Regression contract: Source A status reflects Source A durable manual-sync state; Source B state cannot be substituted by an installation-global auxiliary option.

### UX-PORTALCTRL-003 — cancel cannot rewrite another Connection's history
- Regression contract: cancelling an operation updates only the authenticated Connection's `lastOperation` record.

### UX-PORTALCTRL-004 — idempotency is scoped to the owner
- Regression contract: an identical `requestId` on another Connection is not treated as the same Portal operation solely because another Source used it.

### UX-PORTALCTRL-005 — legacy installation-global operation metadata is not authoritative in multi-Connection runtime
- Regression contract: Source-scoped REST does not fall back to the old global `mobo_core_portal_last_operation` record when a concrete Connection context exists.

## AC. Provisioning signature / replay hardening (UX33)

### UX-PROVISION-001 — signature is verified before replay reservation
- Regression contract: malformed or incorrectly signed requests cannot consume a valid nonce reservation.

### UX-PROVISION-002 — replay reservation is atomic
- Regression contract: two concurrent requests with the same valid signed nonce have a single winner; the second returns replay/conflict rather than executing provisioning twice.

### UX-PROVISION-003 — replay identity is token-bound
- Regression contract: nonce identity includes the License Token so equal nonce text on independent customer Connections does not collide.

### UX-PROVISION-004 — replay reservation expires safely
- Regression contract: an expired durable reservation can be removed lazily and does not permanently block a later legitimate request after the allowed replay window.

### UX-PROVISION-005 — object-cache semantics cannot weaken replay protection
- Regression contract: correctness does not depend on a persistent external object cache or a raceable `get_transient()` + `set_transient()` pair.

## AD. Mobo REST cache-boundary hardening (UX34)

### UX-RESTCACHE-001 — every Mobo REST response is no-store
- Regression contract: responses under `/mobo-core/v1/` include `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` plus compatible `Pragma`/`Expires` headers.

### UX-RESTCACHE-002 — Source-scoped responses cannot be replayed from a shared cache
- Regression contract: Portal settings, operation status, revenue summary, webhook-test and sync/status responses are never cacheable by a CDN/reverse proxy across Connection credentials.

### UX-RESTCACHE-003 — installation-global status is also non-cacheable
- Regression contract: Health, Cron/Worker status and upgrade status preserve the no-store boundary even when the Installation Controller is valid.

### UX-RESTCACHE-004 — mutating acknowledgements are non-cacheable
- Regression contract: webhook, provisioning, sync, operation, heartbeat, worker and upgrade acknowledgements carry the same cache prohibition.

### UX-RESTCACHE-005 — non-Mobo REST namespaces are untouched
- Regression contract: the response-cache filter applies only when the request route begins with `/mobo-core/v1/`.


## AE. Source-owned Webhook queue execution isolation (UX35)

### UX-WEBHOOKISO-001 — authenticated `/webhook/run` drains only its owning Connection
- Kind: security multi-Connection RUNTIME
- Regression contract: Source A credentials may claim/process only table-backed events whose `connection_id` is A; due events owned by Source B remain untouched.

### UX-WEBHOOKISO-002 — foreground process-on-receive cannot cross Source ownership
- Kind: multi-Connection RUNTIME
- Regression contract: when `mobo_core_process_webhook_on_receive=1`, accepting a valid Source A webhook may immediately process A work but never claims or mutates Source B events.

### UX-WEBHOOKISO-003 — file fallback queue enforces durable owner
- Kind: multi-Connection RUNTIME + legacy compatibility
- Regression contract: JSON envelopes with `connectionId=B` are invisible to Source A; pre-Connection/raw legacy files are processable only by the enabled Default Connection.

### UX-WEBHOOKISO-004 — queue result/attempt diagnostics are Connection-scoped
- Kind: CONTRACT
- Regression contract: `mobo_core_webhook_queue_last_*` state written while processing Source A does not overwrite Source B diagnostics.

### UX-WEBHOOKISO-005 — shared Cron dispatcher remains fair across enabled Connections
- Kind: multi-Connection RUNTIME
- Regression contract: installation worker iterates enabled Connections under explicit contexts with the round-robin cursor, so a busy Default Connection cannot permanently starve a later Source.

### UX-WEBHOOKISO-006 — queue ownership never falls back to Connection 0
- Kind: failure-injection CONTRACT
- Regression contract: a new table-backed webhook cannot be persisted or processed as durable `connection_id=0`; unresolved ownership fails closed.

## AF. Installation-wide Worker/Webhook observability (UX36)

### UX-WORKEROBS-001 — `/worker/status` aggregates all enabled Connection webhook queues
- Kind: multi-Connection RUNTIME
- Regression contract: Installation Controller worker status reports aggregate pending/due/failed counts across every enabled Connection rather than only the Default Connection.

### UX-WORKEROBS-002 — aggregate status preserves Source-scoped `get_status()`
- Kind: CONTRACT
- Regression contract: ordinary `Mobo_Core_Webhook_Queue::get_status()` remains Connection-scoped; only explicit installation-global callers use `get_installation_status()`.

### UX-WORKEROBS-003 — aggregate timing uses oldest backlog / newest activity
- Kind: RUNTIME
- Regression contract: installation timing reports the earliest pending item, earliest future retry, newest queue update and newest last-event diagnostic across enabled Sources.

### UX-WORKEROBS-004 — aggregate last-result evidence comes from the latest attempted Source
- Kind: RUNTIME
- Regression contract: installation `lastResult` is selected from the Connection with the newest queue attempt timestamp and does not accidentally reuse the Default Connection result.

### UX-WORKEROBS-005 — Health webhook counters include non-default Sources
- Kind: multi-Connection RUNTIME
- Regression contract: `pendingWebhookJobs`, `failedWebhookJobs` and oldest pending evidence in installation Health include table-backed queue state from all enabled Connections.

### UX-WORKEROBS-006 — legacy webhook file health count is not multiplied by Connection count
- Kind: CONTRACT
- Regression contract: global cached legacy-file counts are added once after Connection table summaries are aggregated.

## AG. Installation Health image-queue aggregation (UX37)

### UX-HEALTHIMG-001 — Health image counters include every enabled Connection
- Kind: multi-Connection RUNTIME
- Regression contract: pending, attaching, due and failed Image Queue counts are summed across all enabled Connections.

### UX-HEALTHIMG-002 — image queue reads execute under explicit owners
- Kind: CONTRACT
- Regression contract: each per-Connection Image Queue summary is read inside `Mobo_Core_Connection_Context::run()` and no Source can substitute the Default owner's rows.

### UX-HEALTHIMG-003 — oldest image backlog is installation-wide
- Kind: RUNTIME
- Regression contract: `oldestImagePendingAt` is the earliest non-empty pending timestamp across enabled Sources.

### UX-HEALTHIMG-004 — next image retry is installation-wide
- Kind: RUNTIME
- Regression contract: `nextImageRetryAt` is the earliest non-empty retry time across enabled Sources.

### UX-HEALTHIMG-005 — single-Connection behavior remains compatible
- Kind: RUNTIME
- Regression contract: a site with one enabled Connection produces the same queue counters/timing as that Connection's direct Image Queue summary.

## AH. Multi-Connection runner/Heartbeat evidence (UX38)

### UX-RUNNEREVIDENCE-001 — Health can resolve webhook success from dispatcher results
- Kind: multi-Connection RUNTIME
- Regression contract: `lastWebhookSuccessAt` recursively inspects `connections[].result` from a multi-Connection Cron result and returns the latest successful webhook execution timestamp.

### UX-RUNNEREVIDENCE-002 — single-Connection webhook success remains compatible
- Kind: RUNTIME
- Regression contract: legacy/single result shapes with top-level `webhookQueue` still produce the existing success timestamp semantics.

### UX-RUNNEREVIDENCE-003 — Heartbeat compact result identifies multi-Connection execution
- Kind: CONTRACT
- Regression contract: Heartbeat worker evidence exposes bounded `multiConnection`, `connectionCount`, successful-Connection and failed-Connection counts without returning credentials.

### UX-RUNNEREVIDENCE-004 — Heartbeat compaction remains bounded
- Kind: security/privacy CONTRACT
- Regression contract: compact Heartbeat results do not embed complete per-Connection runner payloads, Source credentials, License Tokens or security codes.

### UX-RUNNEREVIDENCE-005 — continuation evidence survives multi-Connection dispatch
- Kind: RUNTIME
- Regression contract: top-level `needsContinuation` from the dispatcher remains visible in Heartbeat compact output and can still trigger a bounded follow-up worker kick.

## AI. Remote Update manifest format / build fingerprint (UX39)

### UX-UPDMANIFEST-001 — release manifest uses Remote Updater canonical schema
- Kind: release STATIC
- Regression contract: `mobo-core-manifest.json` contains `algorithm: sha256`, the exact plugin `version`, and `files` as a `relative/path => 64-hex-sha256` map.

### UX-UPDMANIFEST-002 — manifest does not self-hash
- Kind: release STATIC
- Regression contract: `mobo-core-manifest.json` itself is excluded from the tracked file map so regenerating metadata cannot invalidate its own digest.

### UX-UPDMANIFEST-003 — every tracked hash matches packaged bytes
- Kind: release STATIC
- Regression contract: recomputing SHA-256 for every manifest path in the final ZIP matches exactly and no tracked file is missing.

### UX-UPDMANIFEST-004 — package contains no untracked release file
- Kind: release STATIC
- Regression contract: every regular file under `mobo-core/` except the manifest itself appears exactly once in the manifest map; hidden build/work copies are rejected.

### UX-UPDMANIFEST-005 — build fingerprint is non-empty and stable
- Kind: release CONTRACT
- Regression contract: `Mobo_Core_Remote_Updater::get_build_fingerprint()` can canonicalize the manifest map and returns a lowercase SHA-256 that is unchanged by `generatedAt`.

### UX-UPDMANIFEST-006 — staged Remote Updater validation accepts the final artifact
- Kind: release RUNTIME
- Regression contract: a staged copy of the final plugin passes `validate_manifest()` expectations for algorithm, version, file hashes and tracked-file completeness.

## AJ. Remote Update HTTPS transport boundary (UX40)

### UX-UPDHTTPS-001 — production package URL requires HTTPS
- Kind: security RUNTIME
- Regression contract: with `WP_DEBUG=false`, a valid allowed-host package URL using `https://` passes the scheme boundary and the equivalent `http://` URL is rejected.

### UX-UPDHTTPS-002 — debug-only HTTP exception is explicit
- Kind: development CONTRACT
- Regression contract: `http://` may pass the scheme boundary only when `WP_DEBUG=true`; this exception never applies to production.

### UX-UPDHTTPS-003 — userinfo URLs are always rejected
- Kind: security RUNTIME
- Regression contract: package URLs containing username/password authority components fail regardless of HTTPS or allowlist membership.

### UX-UPDHTTPS-004 — required package API path remains enforced
- Kind: security RUNTIME
- Regression contract: HTTPS alone is insufficient; the URL path must still contain `/api/plugin-packages/`.

### UX-UPDHTTPS-005 — default host policy pins exact Portal origin
- Kind: security CONTRACT
- Regression contract: without an explicit allowed-host list, package downloads require the same scheme/host/effective-port origin as the active Connection API base URL.

### UX-UPDHTTPS-006 — explicit allowed-host mode never weakens HTTPS production rule
- Kind: security RUNTIME
- Regression contract: adding a hostname to `mobo_core_remote_update_allowed_hosts` cannot make plain HTTP acceptable in production.

## AK. Database Unicode enforcement foundation (UX41)

### UX-DBENFORCE-001 — Mobo schema creation is utf8mb4-only
- Kind: DATABASE CONTRACT
- Regression contract: every Mobo-owned `CREATE TABLE`/`dbDelta` path uses `Mobo_Core_Database_Charset::schema_charset_collate()` and cannot inherit `utf8`/`utf8mb3` from `$wpdb->get_charset_collate()`.

### UX-DBENFORCE-002 — utf8mb3 WordPress config fails readiness
- Kind: RUNTIME + RELEASE FIXTURE
- Regression contract: `DB_CHARSET=utf8` or `utf8mb3` keeps `databaseUnicode.ready=false` and therefore `databaseSchemaReady=false`; no normalization may reinterpret three-byte UTF-8 as full Unicode.

### UX-DBENFORCE-003 — live connection must be utf8mb4 end-to-end
- Kind: DATABASE RUNTIME
- Regression contract: client, connection and results character-set variables are each checked; one non-utf8mb4 leg fails readiness even when tables themselves are utf8mb4.

### UX-DBENFORCE-004 — database default must be full Unicode
- Kind: DATABASE RUNTIME
- Regression contract: the selected WordPress schema default charset/collation is read from `information_schema.SCHEMATA`; a three-byte default is reported as a distinct failure layer.

### UX-DBENFORCE-005 — actual Mobo text columns are audited
- Kind: DATABASE SCHEMA
- Regression contract: every existing `<prefix>mobo_%` textual column is inspected through `information_schema.COLUMNS`; mixed utf8mb3/utf8mb4 Mobo schemas fail closed with bounded table/column evidence.

### UX-DBENFORCE-006 — required WordPress/WooCommerce destination columns are audited
- Kind: DATABASE SCHEMA
- Regression contract: existing core/Woo tables Mobo writes textual data into are audited and incompatible columns block readiness without making unrelated third-party tables global blockers.

### UX-DBENFORCE-007 — custom-storage readiness cannot bypass Unicode policy
- Kind: STORAGE GATE
- Regression contract: `Mobo_Core_Storage_Schema::validate()` rejects required custom-storage tables whose textual columns are below utf8mb4 even if table/version checks otherwise pass.

### UX-DBENFORCE-008 — Health exposes exact Unicode layer and fails Schema readiness
- Kind: ADMIN UI + HEALTH CONTRACT
- Regression contract: Health includes bounded `databaseUnicode` diagnostics, renders a manager-visible Unicode status/message, and never reports Schema ready while Unicode readiness is false.

### UX-DBENFORCE-009 — supplementary Unicode round-trip is byte-exact and ephemeral
- Kind: DATABASE RUNTIME
- Regression contract: the explicit probe writes and reads a real 4-byte value through a temporary utf8mb4 table, compares bytes, always drops the probe table, and fails on SQL error/truncation/replacement.

### UX-DBENFORCE-010 — no silent broad conversion
- Kind: SAFETY CONTRACT
- Regression contract: activation, page rendering, routine schema ensure, Health and normal updates never execute whole-database/table conversion DDL; existing incompatible installations remain fail-closed until the explicit maintenance conversion workflow is invoked.


## AL. Explicit utf8mb4 conversion planner / checkpointing (UX42)

### UX-DBMIG-001 — conversion never starts from read-only Health/schema paths
- Kind: SAFETY CONTRACT
- Regression contract: activation, Health, schema ensure, REST health and ordinary admin page rendering never call `Mobo_Core_Database_Unicode_Migration::start()` or issue broad `ALTER DATABASE` / `ALTER TABLE ... CONVERT` DDL.

### UX-DBMIG-002 — plan is bounded to the active WordPress site prefix
- Kind: DATABASE SECURITY
- Regression contract: the migration planner selects only base tables whose names begin with the exact active `$wpdb->prefix`; an injected/checkpointed table outside that prefix is rejected before DDL.

### UX-DBMIG-003 — database default is a separate first checkpoint
- Kind: DATABASE RUNTIME
- Regression contract: when the database default is below utf8mb4, one slice converts/verifies only the database default, persists `databasePending=false`, and returns before any table conversion.

### UX-DBMIG-004 — one table per slice
- Kind: DATABASE RUNTIME
- Regression contract: after the database-default checkpoint, a single `process_slice()` invocation converts/verifies at most one planned table and advances the cursor exactly once.

### UX-DBMIG-005 — checkpoint retry is idempotent after lost PHP response
- Kind: FAILURE-INJECTION RUNTIME
- Regression contract: if ALTER DATABASE/TABLE completed but the request dies before the cursor is persisted, the next slice verifies the already-utf8mb4 object and checkpoints it without requiring a second destructive conversion.

### UX-DBMIG-006 — plan size is bounded
- Kind: SAFETY CONTRACT
- Regression contract: an installation exceeding the explicit migration-plan table limit fails closed before DDL rather than persisting an unbounded option payload.

### UX-DBMIG-007 — utf8mb4 index-width preflight fails before DDL
- Kind: DATABASE COMPATIBILITY + SAFETY
- Regression contract: before a conversion plan is accepted, indexed columns on planned tables are conservatively estimated under four-byte utf8mb4 semantics against the detected engine/row-format key limit; a provably oversized key fails closed before `ALTER DATABASE` or `ALTER TABLE`, and the index inspection itself is bounded.

## AM. Unicode migration runtime barrier / DDL isolation (UX43)

### UX-DBMIG-BARRIER-001 — migration acquires the global runtime barrier before DDL
- Kind: CONCURRENCY RUNTIME
- Regression contract: no ALTER DATABASE/TABLE is issued until the migration owns `plugin_upgrade_barrier` and `get_blocking_locks()` is empty.

### UX-DBMIG-BARRIER-002 — existing Mobo workers drain naturally
- Kind: CONCURRENCY RUNTIME
- Regression contract: activation never force-releases active queue/sync leases; the migration remains `draining` until existing non-exempt locks expire/release.

### UX-DBMIG-BARRIER-003 — ordinary workers cannot start during conversion
- Kind: CONCURRENCY RUNTIME
- Regression contract: while the Unicode migration barrier is live, ordinary Mobo locks are rejected and Cron/Sync/Queue paths report their protected paused semantics.

### UX-DBMIG-BARRIER-004 — DDL step lease is barrier-exempt but ownership-scoped
- Kind: CONCURRENCY CONTRACT
- Regression contract: only `database_unicode_migration_step` is additionally exempt from the global barrier; concurrent migration requests cannot execute two DDL steps because only one can own that lease.

### UX-DBMIG-BARRIER-005 — long DDL has a bounded extended barrier lease
- Kind: FAILURE-INJECTION CONTRACT
- Regression contract: the coordinator accepts the migration's extended but finite barrier TTL while ordinary remote-upgrade calls retain their existing 900-second request; expiry still self-recovers stale ownership.

### UX-DBMIG-BARRIER-006 — stage marking cannot shorten the DDL barrier lease
- Kind: CONCURRENCY CONTRACT
- Regression contract: after the migration marks the coordinator stage, barrier ownership is renewed back to the migration's extended TTL; the generic 900-second `mark_stage()` renewal must not silently reduce the protected DDL boundary.

## AN. Administrator-controlled remediation UI / wp-config boundary (UX44)

### UX-DBMIG-ADMIN-001 — only manage_options + valid nonce can mutate migration state
- Kind: SECURITY RUNTIME
- Regression contract: Start/Resume/Pause admin-post handlers all require `manage_options` and their exact nonce action before invoking migration methods.

### UX-DBMIG-ADMIN-002 — no automatic first start
- Kind: SAFETY CONTRACT
- Regression contract: Cron may continue an already-started run but cannot create the initial migration state; only the explicit administrator Start action can do so.

### UX-DBMIG-ADMIN-003 — Health exposes bounded progress without secret barrier token
- Kind: ADMIN UI + PRIVACY
- Regression contract: Health/Portal exposes status, counts, last table/error and last audit, but never returns the persisted barrier ownership token or the full internal table plan.

### UX-DBMIG-ADMIN-004 — manager sees irreversible-operation warning
- Kind: ADMIN UI
- Regression contract: the Health remediation card states that database backup is recommended and that large ALTER TABLE operations may be expensive before the Start control is available.

### UX-DBMIG-ADMIN-005 — wp-config.php is never edited
- Kind: FILESYSTEM SAFETY STATIC
- Regression contract: the Unicode migration contains no file write/rewrite path for wp-config.php; DB_CHARSET/DB_COLLATE remediation remains an explicit operator action.

### UX-DBMIG-ADMIN-006 — physical completion can stop at awaiting-config
- Kind: RUNTIME
- Regression contract: after all DB/table DDL verifies, a legacy DB_CHARSET/wpdb/connection leaves the run in `awaiting-config`, releases the runtime barrier and does not falsely mark completion.

## AO. Cron continuation / safe Pause boundary (UX45)

### UX-DBMIG-CRON-001 — Cron advances only an already-active Unicode run
- Kind: CRON RUNTIME
- Regression contract: when the global barrier belongs to an active Unicode run, Cron executes one migration slice and returns without running ordinary Source workers; otherwise existing upgrade-pause behavior is unchanged.

### UX-DBMIG-CRON-002 — one Cron hit cannot convert multiple tables
- Kind: CRON + DATABASE RUNTIME
- Regression contract: a Cron invocation under Unicode maintenance performs at most one database/table DDL checkpoint.

### UX-DBMIG-PAUSE-001 — Pause outside DDL releases barrier immediately
- Kind: CONCURRENCY RUNTIME
- Regression contract: when no step lease is active, Pause persists the checkpoint, sets `paused` and clears the long global barrier; the migration-state lock guard still keeps ordinary Mobo mutation workers fail-closed until terminal verification.

### UX-DBMIG-PAUSE-002 — Pause during DDL never releases the barrier early
- Kind: RACE / FAILURE-INJECTION
- Regression contract: while `database_unicode_migration_step` is active, Pause only persists `pauseRequested=true`; the global barrier stays live until the DDL request verifies and checkpoints its step.

### UX-DBMIG-PAUSE-003 — requested Pause is honored immediately after checkpoint
- Kind: RACE RUNTIME
- Regression contract: the DDL owner re-reads migration state after SQL completion, sees a concurrent pause request, persists the completed cursor first, then releases the barrier and enters `paused`.

### UX-DBMIG-PAUSE-004 — Resume rebuilds remaining plan after pause/failure
- Kind: RECOVERY RUNTIME
- Regression contract: Resume does not trust an old cursor blindly; it rebuilds the current remaining physical plan before reacquiring the barrier.

### UX-DBMIG-PAUSE-005 — stale pre-step request cannot cross a concurrent Pause
- Kind: RACE / FAILURE-INJECTION
- Regression contract: if Pause lands after the initial drain check but before a request acquires the DDL step lease, the DDL owner must re-read durable migration state after acquiring that lease and abort/pause before SQL; stale request-local `migrating` state cannot authorize an ALTER.

## AP. Terminal Unicode verification / release readiness (UX46)

### UX-DBMIG-FINAL-001 — terminal boundary re-plans before declaring physical completion
- Kind: DATABASE RUNTIME
- Regression contract: after the stored cursor is exhausted, the workflow performs a fresh current-prefix plan; newly created/incompatible tables are added as remaining work instead of being ignored.

### UX-DBMIG-FINAL-002 — final readiness includes real four-byte round-trip
- Kind: DATABASE RUNTIME
- Regression contract: `completed` is persisted only after `Mobo_Core_Database_Charset::get_status(true, true)` succeeds, including the byte-exact supplementary-Unicode temporary-table probe.

### UX-DBMIG-FINAL-003 — failed verification releases barrier but preserves evidence
- Kind: FAILURE-INJECTION
- Regression contract: SQL/verification failure stores a bounded error code/message, cursor and last-completed table in durable audit state, releases the runtime barrier and requires explicit Resume.

### UX-DBMIG-FINAL-003B — partial/paused/failed states remain mutation fail-closed
- Kind: CONCURRENCY + SAFETY RUNTIME
- Regression contract: after an explicit migration has started, `paused`, `failed` and `awaiting-config` may release the long global barrier, but central Mobo lock acquisition still rejects ordinary mutation-worker leases until terminal verification succeeds; updater and bounded Health observation remain available.

### UX-DBMIG-FINAL-004 — awaiting-config re-check also re-plans physical state
- Kind: RECOVERY RUNTIME
- Regression contract: after the operator changes wp-config.php, Resume first verifies no new physical utf8mb3 work appeared; if it did, the workflow returns to protected DDL conversion instead of finalizing early.

### UX-DBMIG-FINAL-005 — completed state contains no live barrier ownership
- Kind: SECURITY/CONCURRENCY CONTRACT
- Regression contract: terminal completion clears the migration's barrier token, reports `active=false`, and ordinary Cron/queue workers can acquire runtime leases again.

### UX-DBMIG-FINAL-006 — release artifact contains migration tests as permanent backlog
- Kind: RELEASE STATIC
- Regression contract: the packaged `docs/internal/admin-ux-test-backlog.md` contains UX42 through UX46 contracts (`UX-DBMIG-*`) for later Deep Test Suite implementation.


## AQ. Installed plugin byte-integrity verification (UX47)

### UX-UPGRADE-INTEGRITY-001 — installed build identity hashes actual files
- Kind: RELEASE + FILESYSTEM RUNTIME
- Regression contract: `Mobo_Core_Remote_Updater::get_build_integrity_status()` hashes every manifest-tracked file in the active plugin directory and reports ready only when the on-disk bytes match the packaged SHA-256 map; computing a fingerprint from manifest declarations alone is insufficient.

### UX-UPGRADE-INTEGRITY-002 — missing/modified/untracked files fail closed
- Kind: FAILURE-INJECTION FILESYSTEM
- Regression contract: deleting or modifying one tracked file, adding one untracked file, or introducing a symlink makes installed build integrity false with bounded operator-safe evidence.

### UX-UPGRADE-INTEGRITY-003 — same-version deployment can repair a damaged build
- Kind: REMOTE UPDATE RUNTIME
- Regression contract: the `already-current-build` shortcut is allowed only when the installed tree itself verifies and its verified fingerprint equals the signed target fingerprint. A damaged same-version tree must not be skipped merely because its manifest declares the target fingerprint.

### UX-UPGRADE-INTEGRITY-004 — post-install verification uses targetVersion, not the request-loaded constant
- Kind: UPGRADE BOUNDARY RUNTIME
- Regression contract: after `Plugin_Upgrader` replaces files in the same PHP request, verification explicitly checks the new `targetVersion`; the old in-memory `MOBO_CORE_VERSION` constant cannot make a valid new package fail or make an old package pass.

### UX-UPGRADE-INTEGRITY-005 — post-install success requires full tree verification
- Kind: FAILURE-INJECTION RELEASE
- Regression contract: an upgrader result that returns success while one installed file is missing/corrupt cannot reach deployment `completed`; rollback is attempted and the response remains failed.

### UX-UPGRADE-INTEGRITY-006 — status exposes bounded build-integrity evidence
- Kind: REST + OBSERVABILITY
- Regression contract: installation-controller upgrade status exposes verified fingerprint/readiness/file-count and bounded error code/message without exposing secrets; a false integrity state never carries a trusted fingerprint.


## AR. Verified rollback snapshot / restore proof (UX48)

### UX-UPGRADE-ROLLBACK-001 — backup is built in pending storage before promotion
- Kind: FILESYSTEM SAFETY
- Regression contract: the currently trusted `latest` rollback snapshot is not deleted before a new pending copy has completed manifest and whole-tree verification.

### UX-UPGRADE-ROLLBACK-002 — backup copy must equal source bytes
- Kind: FAILURE-INJECTION FILESYSTEM
- Regression contract: source and pending backup use deterministic whole-tree SHA-256 fingerprints including the manifest itself; a dropped/changed file or file-count mismatch rejects backup creation before active plugin replacement.

### UX-UPGRADE-ROLLBACK-003 — rotation failure preserves the previous usable backup
- Kind: FAILURE-INJECTION FILESYSTEM
- Regression contract: failure while rotating/promoting pending backup does not silently destroy the previous rollback snapshot; promotion returns a hard error before plugin installation.

### UX-UPGRADE-ROLLBACK-004 — backup is re-verified before deleting active plugin
- Kind: ROLLBACK SAFETY
- Regression contract: rollback validates the backup manifest and whole-tree snapshot before `MOBO_CORE_PLUGIN_DIR` is removed. A backup modified after creation cannot authorize destructive restore.

### UX-UPGRADE-ROLLBACK-005 — restored tree is verified byte-for-byte
- Kind: FAILURE-INJECTION ROLLBACK
- Regression contract: after rollback copy, the destination manifest and complete tree fingerprint/file-count must equal the verified backup. Partial restore never reports rollback success.

### UX-UPGRADE-ROLLBACK-006 — rollback status is evidence-based
- Kind: REST + OBSERVABILITY
- Regression contract: `rollbackReady` is true only for a manifest-valid, hash-verifiable snapshot; directory existence alone is not sufficient proof.


## AS. Interrupted remote deployment recovery (UX49)

### UX-UPGRADE-RECOVERY-001 — stale transient stage is detected after lease/barrier loss
- Kind: FAILURE-INJECTION RUNTIME
- Regression contract: stored `downloading|validating|draining|backing-up|installing|verifying` with neither a live `remote_plugin_upgrade` lease nor live upgrade barrier is surfaced as interrupted rather than indefinitely presented as active work.

### UX-UPGRADE-RECOVERY-002 — completed filesystem replacement can be recovered observationally
- Kind: CRASH RECOVERY
- Regression contract: if PHP dies after target files are fully installed but before terminal status is persisted, a later status read may report `completed-recovered` only when targetVersion matches the loaded installation and actual installed bytes verify; signed target build identity, when recorded, must also match.

### UX-UPGRADE-RECOVERY-003 — partial active tree never overwrites a good rollback snapshot
- Kind: CRASH RECOVERY + FILESYSTEM SAFETY
- Regression contract: when the active plugin tree no longer verifies, a retry cannot create/promote a new backup from those damaged bytes. A previously verified backup for the same source version is preserved and reused instead.

### UX-UPGRADE-RECOVERY-004 — damaged active tree without matching verified backup fails before install
- Kind: FAIL-CLOSED RECOVERY
- Regression contract: if installed integrity is false and no verified rollback snapshot for the expected current version exists, signed retry stops before destination replacement rather than proceeding without a recovery boundary.

### UX-UPGRADE-RECOVERY-005 — recovery status is bounded and non-secret
- Kind: REST + SECURITY
- Regression contract: status may expose `interrupted`, `storedStatus`, `recoveryMode`, build/rollback readiness and non-secret lock timing, but never package download tokens, X-SEC, license Token, HMAC material or lock ownership tokens.


## AT. utf8mb4 textual foreign-key preflight (UX50)

### UX-DBMIG-FK-001 — incompatible text FK fails before first DDL
- Kind: DATABASE COMPATIBILITY + SAFETY
- Regression contract: if a planned table has a textual foreign-key column whose charset/collation would change under the selected utf8mb4 target, planning fails with `utf8mb4-foreign-key-preflight-failed` before ALTER DATABASE or ALTER TABLE executes.

### UX-DBMIG-FK-002 — either FK side is protected
- Kind: DATABASE COMPATIBILITY
- Regression contract: the preflight checks both child and referenced tables; a planned change on either side of a textual relationship is detected even when the other table is outside the remaining conversion set.

### UX-DBMIG-FK-003 — numeric/binary FK does not create a false blocker
- Kind: DATABASE COMPATIBILITY
- Regression contract: ordinary integer/binary foreign keys whose columns have no character set remain eligible for one-table-at-a-time conversion and are not rejected merely because their tables are planned.

### UX-DBMIG-FK-004 — already-target text FK columns do not false-fail
- Kind: DATABASE COMPATIBILITY
- Regression contract: when a planned table requires conversion for other columns/default state but the FK text column on that side is already `utf8mb4` with the exact target collation, that FK side is not treated as changing.

### UX-DBMIG-FK-005 — inspection is bounded
- Kind: DATABASE SAFETY
- Regression contract: foreign-key inspection is capped; more than the supported bounded relationship count fails closed before DDL rather than building an unbounded migration plan.

### UX-DBMIG-FK-006 — safe preflight evidence survives in migration state
- Kind: ADMIN UI + OBSERVABILITY
- Regression contract: successful planning persists bounded `foreignKeyPreflight` counts/readiness alongside index preflight evidence; internal SQL or unbounded schema dumps are never exposed.


## AU. Terminal Portal operation state remains Connection-scoped (UX51)

### UX-REMOTE-CONTROL-SCOPE-001 — terminal status writes use the Connection-scoped helper
- Kind: MULTI-CONNECTION RUNTIME + STATIC
- Regression contract: when `Mobo_Core_Remote_Control::get_status()` observes `done`, `cancelled`, or `failed`, it must persist `finishedAt` through the same Connection-scoped last-operation helper used by start/cancel paths; no direct write to the installation-global `mobo_core_portal_last_operation` option is allowed.

### UX-REMOTE-CONTROL-SCOPE-002 — Source A terminal transition cannot overwrite Source B operation evidence
- Kind: MULTI-CONNECTION RUNTIME
- Regression contract: create two Connections with distinct last-operation records, enter each Connection context, force a terminal manual-sync status, and prove the terminal status/finished timestamp changes only that Connection's `mobo_core_portal_last_operation_c<ID>` record.

### UX-REMOTE-CONTROL-SCOPE-003 — legacy global key is read/written only as the no-Connection fallback
- Kind: STATIC + COMPATIBILITY
- Regression contract: the literal base option key remains available only when no valid Connection context exists; normal Source-scoped Portal control endpoints must not touch it.

## AV. Large-catalog selective page-cache warmup (UX52, Plugin 10.34.15)

**Do not run UX52 tests against the frozen r8.3.177 UX51 Suite.** Keep UX51 Gate unchanged; pin its successor to `10.34.15` and regenerate critical-file SHA and frozen runner hashes. WAMP/MySQL/actual cache-provider checks remain runtime proof pending until their execution logs are provided.

### UX-CACHE52-001 — direct row operations and existing indexes
- Kind: STATIC + DATABASE INTEGRITY
- Contract: Cache_Warmer ordinary enqueue, due, claim, commit and Health never call `load_map/replace_map`; use Connection-owned indexed row primitives. Confirm `queue_dedupe`, `queue_due`, `queue_object` indexes; WordPress version upgrade creates no new table and never reads unbounded queue payloads for Cron snapshot.

### UX-CACHE52-002 — bulk 30K COLD event storm
- Kind: PERFORMANCE + RUNTIME
- Contract: with 30,000 valid never-sold products and default `selective`, successful targeted purge does not generate 30K front-end GETs/permalink renders/queue writes. Cold products still undergo correct targeted purge, with aggregated `skippedColdTotal` evidence.

### UX-CACHE52-003 — server-side tier and admin policy
- Kind: SETTINGS + SECURITY
- Contract: selective default is COLD without positive sales history; lifetime sales only WARM, never asserted HOT. Trusted `mobo_core_cache_warmup_product_tier` can explicitly promote or demote; invalid filter response fails COLD. Admin only accepts `selective/all`; `all` preserves legacy enqueue eligibility and does not enable unsolicited sitewide crawling.

### UX-CACHE52-004 — parent variation, URL rotation and unique generation
- Kind: WOO COMMERCE + MULTI-CONNECTION
- Contract: parent and variation lead to one stable `product:<parent>` row in the current Connection. Repeated updates replace URL and refresh `revision`/debounce/attempts; old URL-keyed duplicate is removed only after new-row persistence. Same product ID in another Connection produces independent row.

### UX-CACHE52-005 — HOT priority and WARM starvation control
- Kind: SCHEDULER + FAIRNESS
- Contract: HOT is claimed first; after four HOT successes or attempts, a due WARM receives the next opportunity, including across separate process_batch invocations. If no WARM is due, HOT continues. Both lanes respect existing background pressure/circuit decisions.

### UX-CACHE52-006 — atomic claim and request race
- Kind: CONCURRENCY + FAILURE INJECTION
- Contract: concurrent workers cannot both claim a live revision; a re-enqueue during an in-flight GET invalidates old payload_hash/token. Slow HTTP completion cannot delete/overwrite the newer revision. An abandoned claim can be recovered only after expiry; failed durable commit remains diagnosable.

### UX-CACHE52-007 — bounded admission without silent eviction
- Kind: CAPACITY + FAILURE INJECTION
- Contract: under 20K combined HOT+WARM rows per Connection admits new keys. Re-enqueue existing key while full remains allowed. New unique key at/over cap returns explicit `queue-capacity` and increments capacity metrics. 60s count hint must re-count actual indexed DB when saturated. No pending rows are silently trimmed.

### UX-CACHE52-008 — installation-wide rate and concurrency
- Kind: REAL CRON + TWO-SOURCE RUNTIME
- Contract: simultaneous Connection A/B calls never bypass serialized recovery-pipeline lease or default >=1s gap between GET starts. If durable global rate reservation fails, no outbound GET is made. Defaults: batch=2, max batch=10, 1 request at a time; disable/recovery/barriers still respected.

### UX-CACHE52-009 — cache response evidence semantics
- Kind: PAGE CACHE INTEGRATIONS
- Contract: 2xx with explicit HIT increments `verifiedHit`; MISS or unknown is `unverified`, not a claimed cache HIT. `private/no-store/no-cache`, BYPASS or DYNAMIC fails cacheability; 4xx and repeated 5xx use correct terminal/retry paths. Redirect chain stays same origin and never tunnels through arbitrary ports; no duplicate verification GET is launched.

### UX-CACHE52-010 — legacy queue migration after quiet upgrade
- Kind: STORAGE MIGRATION + MULTI-CONNECTION
- Contract: Default UX51 option-map backlog is picked up by Cron even when no new mutation has happened in UX52; per-Connection migration tracking cannot let first Source B invocation suppress Default import. Strict cutover forbids legacy reads; failed import leaves legacy option retryable rather than deleting it.

### UX-CACHE52-011 — Health and Source-scoped non-secret aggregates
- Kind: ADMIN UI + MULTI-CONNECTION
- Contract: display per-Connection HOT/WARM/pending/due/claimed/retry/oldest, admission skips and rejects, last HIT/unverified/bypass. Installation summary sums active and disabled Sources independently, caps at 100, and flags truncation. No queued URL/Token/order payload appears in status.

### UX-CACHE52-012 — deferred WP Super Cache >5K archived products
- Kind: PAGE CACHE CORRECTNESS + FAILURE INJECTION
- Contract: overflow marker remains sticky after 5001 distinct IDs and is not erased by subsequent enqueue; WP Super Cache calls explicit `wp_cache_clear_cache()` fallback exactly when needed. If fallback unavailable, do not claim complete purge; retain retryable queue and publish `requiresSitewideFallback`. Other providers keep targeted archive URL handling.

### UX-CACHE52-013 — real fixture throughput and compatibility
- Kind: LOCAL/DISPOSABLE DB + CACHE PLUGIN RUNTIME
- Contract: 30K and 60K mixed HOT/WARM/COLD event fixtures using real MySQL/MariaDB + `mobo_work_queue`, measure enqueue p50/p95, DB query count, due claim, expired reclaim, bounded Cron snapshot memory, two-Connection isolation and integration-specific HIT/MISS semantics; do not mutate production DB and never substitute mock smoke PASS for this evidence.

### UX-CACHE52-014 — tier change / legacy cleanup errors are not disguised as success
- Kind: STORAGE FAILURE INJECTION
- Contract: when target row upsert succeeds but opposite HOT/WARM deletion or legacy object-key cleanup fails, enqueue returns explicit `dedupe-cleanup-failed`/`legacy-dedupe-cleanup-failed`, not `queued=true`; the durable target row remains available for retry and the old row is never removed before new-row persistence.

## AW. Cache/Warmup large-catalog hardening (UX53, Plugin 10.34.16)

**Release handling:** keep UX51 plugin 10.34.14 and its r8.3.177 frozen Gate untouched. Re-pin the successor Suite to this exact UX53 manifest/fingerprint before any runtime Gate. The PHP model smoke tests do not constitute real WordPress/MySQL/WAMP proof. Assume single language/currency; do not add unsupported cache variants.

### UX-CACHE53-001 — migration is additive, not replace_map
- Kind: STORAGE MIGRATION + CONCURRENCY
- Contract: with an existing 225-item UX51 option backlog, import into `cache_warmup` in bounded 100-row slices. Interleave a concurrent `product:115` revision and an unrelated `product:500` row. After the final import, both newly written rows remain byte-identical, exactly the old 225 product identities have survived and source option is retired only after durable insert-if-absent verification. No `replace_map()` or unrelated DELETE on the destination.

### UX-CACHE53-002 — interrupted import replays safely
- Kind: FAILURE INJECTION
- Contract: inject SQL write failure mid-slice and a process restart just before/after checkpoint. Source option remains available, cursor cannot skip unwritten entries and the import can restart idempotently. Strict legacy retirement reports incomplete until the last slice succeeds. Per-Connection capacity count hint is invalidated even after failed/partial import.

### UX-CACHE53-003 — Default-only legacy import and shared mutex
- Kind: MULTI-CONNECTION + CONCURRENCY
- Contract: a Source B read before Default cannot read/import/delete the unscoped UX51 backlog. Both importer and new enqueue/claim hold the identical scoped `cache_warmup_queue` mutex. Existing HOT product row must outrank legacy WARM import. After an upgrade with no new mutations, real Cron still advances the Default backlog.

### UX-CACHE53-004 — indexed hot-path due existence
- Kind: DATABASE + PERFORMANCE
- Contract: `Cache_Warmer::has_due_work()` and Cron preflight use `has_due_one()` with due/claim predicates and `LIMIT 1`, without loading all payloads or calling `combined_stats()`/repeated COUNT; inspect MySQL/MariaDB EXPLAIN on `queue_due` for 30K/60K. SQL failure does not cause a speculative HTTP GET; full aggregate counts remain permitted on bounded Health/snapshot routes.

### UX-CACHE53-005 — 18K WARM limit reserves 2K HOT
- Kind: CAPACITY + FAILURE INJECTION
- Contract: for one Connection, fill WARM to 18,000; new WARM must fail explicitly but new HOT can be admitted until combined HOT+WARM reaches 20,000. Re-enqueueing an existing row at cap remains valid. No item is evicted silently. Errors in count_strict or option-hint persistence reject new admission; refresh counts after migration.

### UX-CACHE53-006 — independent fair multi-Connection cursor
- Kind: MULTI-CONNECTION SCHEDULER
- Contract: when A and B both have due warmups but A has a far larger backlog, repeated real-Cron dispatcher invocations select A and B using a separate durable `mobo_core_cache_warmup_dispatch_cursor_id`, not the foreground Connection cursor. Source with no due warmup is skipped. Failed cursor persistence fails closed for that Warmup turn. Every Source's webhook/order/sync stages retain existing execution and independent connection ownership.

### UX-CACHE53-007 — only one multi-Connection Warmup stage per dispatch
- Kind: INSTALLATION-WIDE BUDGET
- Contract: a multi-Connection dispatch authorizes only the selected due Connection, caps its process_batch limit to one, and disallows a second warmup stage on later rounds in the same run (including after a failed/bypass GET). In single-Connection mode preserve existing configured batch ceiling. Global lease and >=1s default rate apply to every authorized GET.

### UX-CACHE53-008 — parent-only canonical public permalink
- Kind: WOO + URL SECURITY
- Contract: parent/variation dedupe to a current published parent permalink. Reject arbitrary URLs, same-host cart/checkout/account/login/wp-admin/wp-json routes, unsafe query/action parameters, user-info, fragments, off-origin/custom-port redirects and HTTP->HTTPS scheme downgrades; old permalinks are checked again immediately before GET. Plain WordPress canonical product query URLs remain supported.

### UX-CACHE53-009 — no browser session or personalized cache
- Kind: CACHE SECURITY + HTTP FIXTURE
- Contract: anonymous GET sends an empty cookie collection and no logged-in/session headers. Response `Set-Cookie`, `Vary: Cookie`, `Vary: Authorization`, or `Vary: *` fails cacheability even if a cache proxy claims HIT; non-HTML, private, no-cache, no-store, BYPASS/DYNAMIC also fails. Checkout/cart/account is never a warmup target.

### UX-CACHE53-010 — only provider evidence is verified HIT
- Kind: CACHE INTEGRATION
- Contract: HTTP 200 with recognized explicit HIT increments verifiedHit. A first MISS/unknown HTML 200 is at most a successful unverified attempt, never a fabricated HIT. Error/redirect responses follow bounded retry/drop policy; no secondary verifier GET should accidentally double outbound traffic. Exercise WP Rocket/LiteSpeed/WP Super Cache headers and CDN combinations on disposable WAMP.

### UX-CACHE53-011 — successful targeted purge is non-bypassable
- Kind: PAGE CACHE + SAFETY
- Contract: `mobo_core_cache_warmup_product_enabled` may opt out but cannot force GET when no supported integration is detected or targeted product purge did not report success. On a 5,001+ deferred Product-ID archive wave, WP Super Cache requires an explicit successful sitewide fallback or retains retryable failed purge evidence; no false success.

### UX-CACHE53-012 — revision/claim CAS across HTTP window
- Kind: CONCURRENCY + FAILURE INJECTION
- Contract: while old GET is in flight, enqueue same parent under the same Connection (including HOT/WARM lane switch). Old finish_claim must not delete a newer revision; claim expiry after crashed worker allows bounded replay. B's identical numerical product ID must remain untouched.

### UX-CACHE53-013 — 30K/60K real throughput proof
- Kind: LOCAL/DISPOSABLE MYSQL/MARIADB BENCHMARK (PENDING)
- Contract: use 30K products for Source A and 30K for Source B. Measure p50/p95 enqueue latency, SQL count per mutation, peak PHP memory, queue_dedupe/queue_due EXPLAIN, queue depth and oldest age, targeted purge/warmup under simultaneous price/stock waves and long HTTP responses. Verify WARM admission 18K, HOT reserve, no foreground queue regression and no production table ALTER/destructive fixtures.

### UX-CACHE53-014 — migration and admission option failure isolation
- Kind: DATABASE OPTION FAILURE INJECTION
- Contract: inject get_option/update_option/delete_option and SQL errors at every migration boundary, including source deletion and cursor finalization. Never claim complete while source remains or while a batch was skipped; after invalidating/rebuilding the admission hint, the cap must not be underestimated by imported records. Show an actionable failure state rather than silently dropping rows.

### UX-CACHE53-015 — real page-cache and CDN integration matrix
- Kind: DISPOSABLE WORDPRESS RUNTIME (PENDING)
- Contract: validate WP Rocket, LiteSpeed Cache, W3 Total Cache and WP Super Cache combinations on a published single-language/single-currency product page. Prove purge eligibility and cache headers, anonymous second-visit HIT when supported, no session leaks, correct no-provider/no-purge fail-closed behavior and no mass crawl on initial install. Never infer actual cache generation from HTTP 200 alone.

### UX-CACHE53-016 — release identity, no UX51 gate contamination
- Kind: RELEASE INTEGRITY
- Contract: UX53 must be independently versioned 10.34.16, manifest path=>SHA map exact, ZIP single mobo-core root, all PHP lint/JS syntax clean, no new table/DDL; every integration regression explicitly marked unexecuted until WAMP logs. Frozen UX51/plugin-r8.3.177 fingerprints must not be overwritten silently.

## AX. Full Plugin Pipeline Audit — first tranche (UX54 / 10.34.17)

**Scope:** independent candidate based on UX53; never overwrite UX51/r8.3.177
frozen runtime evidence. Isolated QA stubs are implementation smoke only.

### UX-AUD54-001 — uninstall globals and data preservation
- Kind: RUNTIME / DISPOSABLE WP
- Contract: real WordPress uninstall reaches the first warmup-admission SQL
  query without a PHP undefined `$wpdb` fatal, completes other runtime cleanup,
  retains Woo products/categories, mapping data and customer business settings.

### UX-AUD54-002 — REST exception secrets never reach global PHP log
- Kind: CONTRACT + FAILURE INJECTION
- Contract: throw an exception containing a fake Token, raw URL query and
  absolute path from heartbeat and webhook endpoint; REST returns a stable
  generic error, logs only safe scope/exception class through Woo logger; no
  raw message/file/line and no `error_log()` call in the REST handler.

### UX-AUD54-003 — Cron exception output redaction
- Kind: CONTRACT + RUNTIME
- Contract: failures in lock recovery, top-level run, and isolated stages do
  not persist fake secrets/paths in `last_result`, `stageErrors`, runtime
  diagnostics, Health or remote responses. Stable status/stage/type retained.

### UX-AUD54-004 — no new unowned webhook persistence
- Kind: CONTRACT + DISPOSABLE MYSQL
- Contract: connectionId=0 with no enabled Default, nonexistent positive ID
  and disabled positive ID are all rejected before table or JSON-file write.
  Enabled A and B persist with their own durable owner only.

### UX-AUD54-005 — Event Store independent ingress authority
- Kind: CONTRACT + DISPOSABLE MYSQL
- Contract: directly invoking `Sync_Event_Store::enqueue()` cannot bypass the
  owner validation in `Webhook_Queue::store()`; explicit missing/disabled/zero
  owners create no durable rows and cannot fall back to Default.

### UX-AUD54-006 — table outage/file fallback keeps source ownership
- Kind: FAILURE INJECTION
- Contract: force table enqueue to fail after authenticated A; resulting
  fallback JSON keeps `connectionId=A`; B never selects it. On filesystem
  failure report error rather than return accepted with lost payload.

### UX-AUD54-007 — legacy payloads are Default-only
- Kind: MULTI-CONNECTION RUNTIME
- Contract: old raw file without `connectionId` is visible only to the
  enabled Default and is never re-owned by B. Explicit-zero envelope must be
  reported as a legacy orphan, not adopted or processed by Default silently.

### UX-AUD54-008 — fresh owner status before execution
- Kind: CONCURRENCY + MYSQL
- Contract: cache A as enabled, disable A using a second DB session, then
  attempt to execute an A-owned event in the first process; fresh DB probe must
  fail closed even though request-local cached row is still enabled.

### UX-AUD54-009 — disable during table claim is non-destructive
- Kind: FAILURE INJECTION
- Contract: A disabled/removed after claim yields a bounded pending defer with
  unchanged Connection ID, payload, and try_count; releases CAS claim; neither
  applies A mutation under B/Default nor turns administrative disable into
  max-try terminal failure. Failed DB defer is surfaced explicitly.

### UX-AUD54-010 — disable during legacy-file process is non-destructive
- Kind: FAILURE INJECTION
- Contract: A disabled after file selection updates only A file's retry time
  without increasing `try`; failed atomic write keeps old file safely durable.
  Re-enable A and prove bounded resume without B processing the item.

### UX-AUD54-011 — disabled-owner / active-mutation atomicity study
- Kind: CROSS-PROCESS CHAOS / PENDING DESIGN
- Contract: reproduce disable milliseconds after the final fresh owner probe;
  design and test shared barrier/drain so no *new* Source side-effect can begin
  once disable is committed. UX54 freshness probe alone is not this guarantee.

### UX-AUD54-012 — orphan inventory, not silent reassignment
- Kind: MIGRATION + HEALTH
- Contract: list preexisting zero/nonexistent/disabled-owner table rows and
  explicit-zero JSON envelopes with bounded counts and separate recovery
  decisions. No automatic Default adoption or deletion of business events.

### UX-AUD54-013 — full vs incremental event overlap
- Kind: FULL SYNC + EVENT ORDERING
- Contract: ProductUpdated/UpdateVariant arrives during snapshot/finalize,
  full cursor resumes after crash, and late old revisions cannot overwrite the
  latest applied product/variation desired state in either Connection.

### UX-AUD54-014 — product, reprice and stock concurrency
- Kind: PRODUCT + PRICE/STOCK
- Contract: parent policy changes while variation-specific fixed price,
  product stock and webhook revision change concurrently; final Woo state
  matches the latest authorized policy/revision, not stale worker claims.

### UX-AUD54-015 — order remote success / lost acknowledgement
- Kind: ORDER + PORTAL INTEGRATION
- Contract: remote order accepted, worker dies before local dispatch status
  commits, restart/retry cannot create a second remote order; mixed/non-Mobo
  and multiple-Connection carts remain fail-closed.

### UX-AUD54-016 — media/category/storage/upgrade interference
- Kind: CROSS-PIPELINE CHAOS
- Contract: concurrent image replacement, category reassignment, storage
  retirement and remote update/unicode maintenance observe correct barriers;
  no attachment prematurely pruned and no third-party category mapped silently.

### UX-AUD54-017 — long-running 30K/60K two-Connection soak
- Kind: LOCAL/DISPOSABLE PERFORMANCE + MYSQL
- Contract: measure p50/p95 event admission, claim rate, query/memory usage,
  lock renewal, Connection fairness and queue age for 30K A + 30K B while
  changing product/price/stock/image; include HTTP failure and restarts.

### UX-AUD54-018 — broader sensitive diagnostics inventory
- Kind: SECURITY REVIEW + FAILURE INJECTION
- Contract: enumerate and inject fake URL/secret into remaining shipping,
  webhook, HTTP and third-party exception paths, including WP_Error/Health;
  redact raw payloads without erasing stable actionable failure codes.

### UX-AUD54-019 — new build / old gate separation
- Kind: RELEASE INTEGRITY
- Contract: UX54 version, readme, manifest and ZIP agree, single root,
  no new schema/DDL; UX51 fingerprint and r8.3.177 gate artifacts unchanged.
  UX54 receives its own newly pinned Deep Test Suite before live gate.


## AY. UX55 — Full Sync/Webhook generation fence + durable Reprice parent debt

Do not count isolated PHP mocks/static-contract PASS as the actual two-site WAMP/Portal/MySQL runtime gate. Carry these into a newly pinned Deep Test Suite; UX51 r8.3.177 remains frozen.

### UX-AUD55-001 — page snapshot anchored before API request
- Kind: INJECTED HTTP OVERLAP / P0
- Contract: pause `get_products_page` after request starts, commit newer foreground webhook, then release older page. `_moboManualSnapshotUs` must precede the webhook watermark. Step3 must exact-refresh instead of applying old page.

### UX-AUD55-002 — exact refresh also pre-request fenced
- Kind: RACE / P0
- Contract: inject webhook during `get_product_by_guid`. The retry snapshot uses pre-GET time; second under-lock watermark check prevents stale exact response from being applied.

### UX-AUD55-003 — pre-UX55 legacy checkpoint without timestamp
- Kind: UPGRADE / CRASH / P0
- Contract: queue an old product with no `_moboManualSnapshotUs`, restart on UX55, require exact fresh GET; never fabricate a timestamp on old content. If Portal is down retain old queued data without advancing `processedProducts`.

### UX-AUD55-004 — parent Step3-to-Step4 webhook rebase
- Kind: FULL-SYNC + WEBHOOK / P0
- Contract: apply foreground Webhook just after parent Step3 checkpoint; next worker step marks previous parent incomplete, requeues exact parent, resets images/variant page/cursor/seen generation, preserves owner and does not count same product twice. API error keeps old checkpoint retryable.

### UX-AUD55-005 — Simple commit lock-time revalidation
- Kind: PRODUCT TYPE + CONCURRENCY / P0
- Contract: Webhook wins shared parent lock after Manual has fetched Simple variant; Manual later acquires lock and returns superseded without touching Portal variant identity or topology. Next step exact-rebases.

### UX-AUD55-006 — paged Variation lock-time revalidation
- Kind: VARIABLE + CONCURRENCY / P0
- Contract: after manual variant page HTTP GET, commit foreground webhook before lock admission; stale `_moboManualParentSnapshotUs` returns `manualSnapshotStale` before seen-set, pagination checkpoint, variation CRUD or missing-variant deletion; manual caller exact-rebases.

### UX-AUD55-007 — Source A/B ordering and identity
- Kind: DUAL CONNECTION / P0
- Contract: same opaque external product GUID under A and B must read only its own product map and webhook watermark; A's rebase cannot mark B's product incomplete or change B's manual queue.

### UX-AUD55-008 — Reprice parent-debt checkpoint follows cursor
- Kind: FAILURE INJECTION / P0
- Contract: for a variation parent, persisted checkpoint with advanced `lastPostId` must also carry `pendingParentIds` for derived-price recompute; deliberate kill after checkpoint but before parent sync must not skip parent after restart.

### UX-AUD55-009 — parent-sync failure and idempotent retry
- Kind: FAILURE INJECTION / P0
- Contract: exception, transient lock failure and failure to invalidate product transients all retain pending parent without advancing another page; retry syncs parent exactly once to convergence (replay permitted), removes debt only after success; no raw exception token in log/state.

### UX-AUD55-010 — parent ownership / shared lock
- Kind: DUAL CONNECTION + LOCK / P0
- Contract: parent no longer owned by current Connection, missing GUID or missing lock service cannot trigger unprotected `WC_Product_Variable::sync`; leave debt surfaced, never mutate other Source.

### UX-AUD55-011 — policy change mid-Reprice generation
- Kind: CONCURRENCY / P0
- Contract: settings change after worker begins, with variation-specific fixed override plus concurrent stock webhook. Previously priced items retain recoverable source raw price; old generation does not claim `done`, and a fresh generation converges under latest approved policy.

### UX-AUD55-012 — no false terminal state with outstanding parent
- Kind: RECOVERY + HEALTH / P0
- Contract: `pendingParents>0` produces retryable pending/blocked status and keeps cursor state durable, including during `start()`/restart. A failed checkpoint cannot claim successful `invalidated` or `done`.

### UX-AUD55-013 — asynchronous media boundary still open
- Kind: CROSS-PIPELINE CHAOS / P1
- Contract: Webhook changes parent image URL during Manual Stage4 image admission, while queued old image is downloaded and a newer desired-state hash is applied. Verify generation CAS prevents old image from becoming final gallery; this is not signed off by the pre-Step4 check alone.

### UX-AUD55-014 — proof against actual two-site runtime
- Kind: REAL MYSQL / PORTAL / WAMP / P0
- Contract: execute Full/Repair/ProductUpdated/UpdateVariant/Reprice with crash, retry and A/B isolation on Classic/HPOS where relevant. Baseline UX51 fingerprint/gates must be run separately; repin new Suite for 10.34.18 before install.

## AZ. UX56 — Checkout / Order idempotency, route fencing and stale-attempt recovery

The isolated mock proofs shipped with this candidate are not a live Portal/payment proof. Carry these into the eventual UX65-pinned Suite. Use *disposable* Portal/Woo test orders; never perform wallet-charge failure injection against a customer account. Classic checkout and HPOS/Store API must both be represented.

### UX-AUD56-001 — immutable order line identity and mixed cart
- Kind: Woo CRUD / P0. Capture all-Mobo, Mobo+non-Mobo and Mobo(A)+Mobo(B) at checkout creation; only all-Mobo with one positive Connection ID may be queued or submitted. Deleting a product after order creation must not silently reclassify the captured line item.

### UX-AUD56-002 — fresh owner routing, no Default adoption
- Kind: MySQL + OWNER / P0. An order owned by B uses a fresh, enabled, provisioned B row before starting; disabled/deleted/unprovisioned B keeps the durable queue item and issues zero storefront HTTP requests, even if A is Default and healthy.

### UX-AUD56-003 — provisioning change between remote stages
- Kind: two-process HTTP barrier / P0. Begin Clear/Add with route R1, update B's source URL, API base URL, Provider, Source, Connection key or credentials to R2 from another PHP process, then release worker. Its pre-request uncached fingerprint must reject subsequent HTTP and must never combine R1 checkout with R2 Wallet.

### UX-AUD56-004 — disable immediately before Wallet
- Kind: two-process payment / P0. After Shipping response but before Wallet, disable the owner's Connection. Fresh fingerprint rejects Wallet, defers the order for a complete new cart rebuild after re-enable, and never writes `payment_inflight` for a request already stopped at this boundary.

### UX-AUD56-005 — change after guard but before HTTP dispatch
- Kind: exact pre-send race / P0. Insert route change between filesystem payment guard write and `wp_remote_request`. `mobo_core_order_connection_changed` proves request was not sent; a verified guard cleanup permits safe pre-payment deferral. Simulate inability to clear guard: state is uncertain, never auto-retried.

### UX-AUD56-006 — live purchase versus stale worker
- Kind: two-worker contention / P0. Worker A holds `order_submission_<id>` and waits for delayed remote Wallet acknowledgement; Worker B sees `attempted_at` older than uncertainty threshold. B cannot mark `uncertain`, cannot abort even if Woo status was cancelled, and does not cleanup the durable queue while A still owns the lease.

### UX-AUD56-007 — double-check under recovered lock
- Kind: crash / P0. Kill worker A, let its lock expire, acquire that same lock from B and reload Woo through CRUD. If A committed `_mobo_order_submitted=yes` or `_mobo_order_id>0` before dying, B must never overwrite success with uncertain.

### UX-AUD56-008 — stale abandoned attempt quarantine
- Kind: failure injection / P0. With no active owner, persisted `running + attempted=yes + old timestamp` becomes `uncertain` under the per-order lock; filesystem payment guard is present, queue item is cleaned only after a *durable readback* of uncertain status. Failed DB commit preserves queue/recovery marker and forbids automatic second Wallet request.

### UX-AUD56-009 — cancelled order during active purchase
- Kind: Woo status overlap / P0. Order leaves processing while the current purchase lock is held. Queue processor must not save `aborted` from its stale object over a live in-flight result. After completion, local cancellation remains visible and `requires_review` is sticky; no forced return to completed.

### UX-AUD56-010 — failed/aborted orphan marker cannot revive purchase
- Kind: stale recovery row / P0. Queue contains order in durable `failed` or `aborted` status; worker removes exact queue token but performs no automatic purchase. A separately authorized manual Retry is a distinct operation.

### UX-AUD56-011 — current queue token cleanup
- Kind: two-worker RMW / P0. A reads token T1, another hook re-enqueues same order as T2; cleanup of T1 must not erase T2, including independent recovery marker imports. Re-test on Custom Storage and fallback-eligible single-Connection fixture.

### UX-AUD56-012 — one-shot Wallet and lost acknowledgement
- Kind: disposable Portal fault / P0. Send Wallet once, let remote persist success, drop HTTP acknowledgement and restart PHP. Local `payment_inflight/uncertain` guard forbids automatic replay; explicit verified-absent admin action is required to retry when appropriate. If remote reports definitive paid=false, follow the documented safe failure path only.

### UX-AUD56-013 — payment success commit failure
- Kind: HPOS/Classic persistence failure / P0. After unambiguous paid=true, fail the Woo success save/readback. Independent payment fence remains and order requires review; queue cleanup must never cause a second charge.

### UX-AUD56-014 — no secret in owner fingerprint/diagnostics
- Kind: security / P1. Provision with synthetic token, X-SEC and URLs, change each field, and inspect Woo meta, debug events, Health and PHP logs. The route digest is request-local only; neither raw secrets nor fingerprint are persisted in order state.

### UX-AUD56-015 — request cache versus fresh SQL
- Kind: MySQL / P0. Populate `Mobo_Core_Connection_Repository::get_by_id()` cache with R1, then mutate actual DB to R2 from another connection. `get_by_id_fresh()` returns R2 without overwriting/depending on the request-local cache. A DB error or missing row is fail-closed.

### UX-AUD56-016 — independent Source orders and same numeric variant IDs
- Kind: dual Connection / P0. Same provider numeric product/variant ID under A and B must not coalesce order line owners, cart Cookie Jar, order queue or payment guard. Order id is installation-unique and its per-order lock never needs a guessed Default owner.

### UX-AUD56-017 — baseline policy cannot be weakened by plugins
- Kind: Checkout filters / P1. A third-party `mobo_core_checkout_validation_errors` filter cannot remove core validation errors. Verify cart and checkout/Store API paths with validation switched on/off, including anonymous sessions.

### UX-AUD56-018 — release identity and outstanding limits
- Kind: release integrity / P1. UX56 `10.34.19` header/constant/readme/manifest and one-root ZIP agree; UX55 and its r8.3.178 Suite are not mutated. Existing Portal does not expose a universally verified idempotency key for every storefront Wallet operation: cross-system exactly-once proof is *not* claimed by these local guards; prove remote semantics separately before changing retry policy.


## BA. UX57 — Cart, Shipping, Address and Wallet cross-Connection controls

These checks must be translated to a newly pinned Suite when the Full Pipeline Audit closes. Test remote Wallet/SMS only with disposable provider fixtures; **never** inject payment retries against a real customer. Isolated proof is not a 2-site runtime PASS.

### UX-AUD57-001 — mixed cart is not an automatic remote order
- Kind: Classic + Store API / P0. Mobo+non-Mobo and Mobo(A)+Mobo(B) carts must not produce a partial remote purchase; owner resolution must not adopt unknown durable product identities as Default.

### UX-AUD57-002 — checkout address captured by owner
- Kind: Woo CRUD + HPOS / P0. Address-mapping hooks under order owner A/B preserve native billing/shipping; a failed central Resolver keeps the order queued before Wallet, and missing provider external IDs cannot be silently replaced by Woo labels.

### UX-AUD57-003 — same-second shipping API/webhook race
- Kind: two-process HTTP delay / P0. Begin shipping GET, commit a newer shipping Webhook in the same wall-clock second, then release GET. Stale response must return `stale-ignored`; stored `syncedAtUs`/methods stay on webhook version.

### UX-AUD57-004 — changedAt cannot bypass HTTP generation fence
- Kind: convergence / P0. Repeat UX-AUD57-003 with an explicit valid `changedAt` on the older GET, including an older timestamp and a Provider with unusual clock skew. The local HTTP-start fence must still block an overwrite after a newer commit.

### UX-AUD57-005 — legacy shipping call compatibility
- Kind: upgrade / P1. A pre-UX57 caller passing integer seconds to `store_snapshot` remains accepted. Old snapshots without `syncedAtUs` use second-based conservative fallback. A subsequent fresh GET eventually converges.

### UX-AUD57-006 — invalid and duplicate shipping methods
- Kind: malformed provider payload / P0. Duplicate, non-positive, malformed ID or invalid restriction must not replace the last-good snapshot. An explicit empty collection remains a valid authoritative no-methods result.

### UX-AUD57-007 — SMS/re-arm mutual exclusion
- Kind: concurrent send + admin / P0. Hold `wallet_alert_sms` sender lease between durable fence and remote gateway acknowledgement; concurrently re-arm. Rearm returns false and leaves the in-flight fence and notified state intact; admin may retry after lease release.

### UX-AUD57-008 — Wallet Source A/B independent lease and state
- Kind: dual-Connection / P0. Owner A and B use their separate Connection-scoped lock keys through `Mobo_Core_Lock::option_key`. A's active SMS must not prevent B's alert/re-arm, and B's re-arm must not delete A's fence or notification marker.

### UX-AUD57-009 — stale cached wallet route after reprovision
- Kind: route injection / P0. After Woo purchase succeeds, reprovision A before post-success listener. Fresh A row differs from request-local API/Provider/Source/credentials; zero balance HTTP or SMS request must be sent to old route or Default.

### UX-AUD57-010 — disabled/deleted wallet owner
- Kind: fail-closed / P0. After submission succeeds but before wallet listener, disable/delete the source Connection. No post-order balance HTTP is sent. The already paid Woo/Portal order remains success, not a retryable purchase.

### UX-AUD57-011 — SMS ambiguous result is one-shot
- Kind: crash + SMS gateway / P0. Kill worker after outbound gateway accepts but before verified notified save; preserve replay fence and surface `uncertain`; never automatically resend. Explicit re-arm while sender lease held is rejected.

### UX-AUD57-012 — diagnostics redaction
- Kind: injected WP_Error / P1. Inject `https://...token=SECRET`, filesystem path and arbitrary provider error text into Wallet/SMS, shipping and address-mapping API failures. No raw secret leaks into option status, Woo order meta or Health; generic error/category remains actionable.

### UX-AUD57-013 — address mapping has positive durable owner
- Kind: multi-Connection + unavailable DB / P1. `sync_now` with no resolvable Connection performs zero API/mapping writes. Valid A/B mappings use the existing logical `address_mapping_sync` lock scoped by the lock helper; do not invent `*_c<id>` locks that bypass old in-flight lease/upgrade drain.

### UX-AUD57-014 — generated city assets lock and isolation
- Kind: two-process filesystem / P1. City Asset generation requires positive owner; A and B write distinct public uploads paths and hold the existing `city_assets_generate` logical lock, automatically owner-scoped by `Mobo_Core_Lock`. Verify upgrade/Unicode barrier still blocks new work and drains both locks.

### UX-AUD57-015 — mapping-only Shipping does not inject native rates
- Kind: Woo Shipping / P1. Verify existing merchant zones are preserved, retired legacy Mobo shipping methods are suppressed, and the remote shipping catalog only resolves the method ID during one-owner order submission; mixed order never selects a remote method.

### UX-AUD57-016 — wallet rearm persistence failure
- Kind: MySQL fault / P0. Simulate `OPTION_NOTIFIED` write/readback failure while holding the owner lease; the function cannot claim success or inadvertently clear another Source state. Preserve any uncertain send evidence; remediation requires admin verification.

### UX-AUD57-017 — real shipping/address/Wallet two-site gate
- Kind: WAMP/Portal + Classic/HPOS / P0. Run overlapping address and shipping reads, isolated Source A/B order shipping resolution and disposable success/uncertain Wallet callbacks with a newly pinned build; prove timestamps and owner context with real databases.

### UX-AUD57-018 — release and soak constraints
- Kind: release/performance / P1. UX57=10.34.20 bundles UX56, excludes additional DDL and any auto Wallet replay; confirm single-root ZIP, complete SHA manifest, frozen UX55/r8.3.178 artifacts untouched. Measure order queue, shipping GET and alert behavior under extended two-Connection schedule.

## BB. UX58 — Image/media desired-state, prune and Woo linkage audit

The tests below belong in the newly pinned **final** Suite after the Full Pipeline Audit. Run destructive media tests only against disposable WordPress/Woo fixtures, never on customers' uploads.

### UX-AUD58-001 — one desired-state lease per Connection/product
- Kind: two-process / P0. Enqueue, prune and Woo linkage for A/product 42 contend on exactly `image_desired_cA_p42`; B/product 42 and A/product 43 remain independent; invalid/nonpositive owner must fail closed.

### UX-AUD58-002 — no partial image enqueue under contention
- Kind: lock injection / P0. Hold the shared lease while a competing Full Sync attempts new desired images; verify it returns retryable error and does not alter any queue row, removed IDs or gallery.

### UX-AUD58-003 — attachment download outside desired lease
- Kind: delayed remote HTTP / P1. Pause media download; an authorized newer Webhook can still replace desired rows. Only final Woo linkage/prune, not download, serializes under lease.

### UX-AUD58-004 — old worker cannot save superseded gallery
- Kind: two-process / P0. Pause old worker after attachment readiness; update desired GUID/URL; release old worker. Woo featured/gallery must reflect current queue, never old set.

### UX-AUD58-005 — prune reintroduction before Woo detach
- Kind: crash/interleave / P0. Old pending prune ID is reintroduced into current queue before the unlink step. Re-filter under shared lease; keep both Woo reference and attachment, clear stale candidate.

### UX-AUD58-006 — explicit empty snapshot cannot erase new images
- Kind: two-process / P0. Empty payload clears old queue, then new webhook enqueues an image before old empty Woo-save. Old empty commit observes new queue rows and does not clear featured/gallery.

### UX-AUD58-007 — authoritative empty set is actually committed
- Kind: Woo CRUD + readback / P0. With zero desired queue rows and no conflicting writer, clear featured and gallery under shared lease, re-read and confirm both empty; failed save must keep sync incomplete.

### UX-AUD58-008 — queue unavailable or lock lost
- Kind: MySQL and lease injection / P0. Unavailable queue/invalid owner/expired or blocked desired lease cannot be interpreted as successful prune/linkage; pending work remains retryable without adopting Default Connection.

### UX-AUD58-009 — async nonempty queue is not falsely failed
- Kind: queue-only / P1. A newly enqueued nonempty desired set with images pending download does not fail product sync merely because gallery linkage cannot yet use attachment IDs. Runner attaches after actual media readiness.

### UX-AUD58-010 — orphan physical files and shared attachment safety
- Kind: filesystem + dual-site / P0. Interleave stale prune and Reference-Reuse with same media GUID/attachment; never physically delete while another product/Connection references it. Prove pending cleanup survives crash.

### UX-AUD58-011 — Woo product type/revision transition
- Kind: lifecycle / P1. Simple-to-variable transition and deletion/re-creation between enqueue and image import cannot attach to wrong Woo product or source owner.

### UX-AUD58-012 — upgrade/Unicode barrier drains media lease
- Kind: maintenance / P0. During Image Desired-State lease and upgrade barrier, no new lease starts, live owner finishes/renews, no forced release of active writer.

### UX-AUD58-013 — high-volume image queue evidence
- Kind: 30K / P1. Measure p95 enqueue, query count, lease contention, retries, pending prune size and PHP peak memory with Source A/B and shared media artifacts.

### UX-AUD58-014 — complete image failure-injection gate
- Kind: real WAMP/Portal / P0. Parallel ProductUpdated, full/repair sync and worker at each queue replacement/linkage/prune checkpoint. Verify final Woo and queue converge to newest authorized source snapshot.

## BC. UX59 — Category snapshot, mapping and term-retirement audit

### UX-AUD59-001 — timestamp before category HTTP request
- Kind: source and delayed HTTP / P0. Manual sync, admin preload and scheduled category refresh capture microsecond start before API call, then pass it into the category apply method.

### UX-AUD59-002 — older GET returned last is stale
- Kind: two-process / P0. Start A then B for one Connection, commit B, return A later; A must not upsert, retire, change active GUID setting or advance watermark.

### UX-AUD59-003 — Connection-owned category watermark
- Kind: A/B state / P0. Source A watermark never suppresses Source B's independent snapshot. Invalid effective Connection owner fails closed; stale/failed apply never claims completion.

### UX-AUD59-004 — category snapshot writer lease
- Kind: lock contention / P0. A second snapshot cannot apply under an active writer. Losing lease mid-page or before retirement leaves apply incomplete and does not retire absent terms.

### UX-AUD59-005 — checkpoint persistence failure
- Kind: MySQL fault / P0. If durable `category_snapshot_committed_us` write/readback fails, return incomplete, no false success, leave future retry to converge by fresh fetch.

### UX-AUD59-006 — failed/partial API payload is not authoritative absence
- Kind: malformed HTTP / P0. Invalid data, missing mapping table (even for an authoritative empty list), unresolved parent, duplicate GUID or failed category upsert cannot trigger mass retirement or store a successful snapshot watermark.

### UX-AUD59-007 — old mapping SELECT versus new upsert CAS
- Kind: two-process MySQL / P0. Make retirement SELECT old `synced_term_id`; concurrently upsert new term; stale CAS must affect zero rows, preserving new synced mapping and manual mapping.

### UX-AUD59-008 — successful CAS is conditional on old term
- Kind: MySQL statement / P0. Retirement WHERE must include `connection_id`, row `id`, and *previous* `synced_term_id`; failure does not report retired or silently assume success.

### UX-AUD59-009 — shared Woo term remains active
- Kind: multi-Connection Woo / P0. One Source retires category while another still owns same synced term; all-Connection owner query prevents false `mobo_category_retired=1`.

### UX-AUD59-010 — resurrection after retirement marker probe
- Kind: barrier injection / P0. Concurrent upsert between owner-read and marker write must result in active term marker. Upsert after map commit also reasserts active marker; failed metadata readback is visible as incomplete.

### UX-AUD59-011 — manual mapping never retired automatically
- Kind: admin mapping / P0. Remote absent snapshot can clear synced term by CAS but preserves manual term and doesn't delete merchant-created category hierarchy.

### UX-AUD59-012 — out-of-order parent-child category snapshot
- Kind: fixture / P1. Parent arrives after child; iterative passes converge without retirement until complete; parent missing/cycle preserves existing mapping and reports unresolved.

### UX-AUD59-013 — direct legacy payload compatibility
- Kind: upgrade / P1. Calls without `snapshot_started_us` retain old signature, are explicitly flagged as not HTTP generation fenced. All current HTTP category fetch paths must pass real pre-start value.

### UX-AUD59-014 — category upgrade/disable and term cache
- Kind: process/maintenance / P0. Activate upgrade barrier during apply, disable Source during retirement and seed request-local term cache with stale IDs; final state must never be silently routed to Default.

### UX-AUD59-015 — real multi-Connection consistency and soak
- Kind: WAMP/MySQL/Portal / P0. Concurrent category deletion/reintroduction/mapping by two Connections; verify synchronized term IDs, owner mapping, `retired` marker, actual Woo category display and archive purge over repeated cycles.

### UX-AUD59-016 — release integrity and prior audit regression
- Kind: release / P1. Cumulative UX58+59 is 10.34.22, all 340 manifest-tracked paths match bytes, single plugin root, PHP/JS syntax PASS. Previous UX54–57 order/webhook/payment contracts unchanged. Pin final Suite after UX65, not during active pipeline audit.


## BD. UX60 — Scheduler / worker / lock lease (cumulative 10.34.24)

### UX-AUD60-001 — Unicode barrier closes post-INSERT acquisition race
- Kind: two-process MySQL/P0. Pause `Mobo_Core_Lock::acquire()` after successful option INSERT, set database Unicode migration to partial/paused, then resume. The caller must return false and compare-release only its token; no post-barrier queue mutation.

### UX-AUD60-002 — normal lease unaffected by double barrier probe
- Kind: contract/P1. With upgrade/Unicode inactive acquire, renew within same second, release; verify no accidental false-negative or leaked lock.

### UX-AUD60-003 — outer lease renewal before next Connection
- Kind: two-process/P0. Delay Source A stage/diagnostics until runner owner expires and Source B starts in a separate runner. Original dispatcher must never start its B slice after losing `real_cron_runner` or self-dispatch handoff.

### UX-AUD60-004 — inner lock-lost halts later Sources
- Kind: injected lock takeover/P0. A returns runner lockLost; later enabled Sources are not entered by the same invocation. Result is aggregate `lock-lost`, with `needsContinuation` and non-secret evidence.

### UX-AUD60-005 — partial multi-Source result remains aggregate
- Kind: time-budget/P0. A completes but B cannot begin before deadline. Even if results array contains only one entry, response remains multiConnection and records continuation, not single Source status.

### UX-AUD60-006 — actual one-Connection fast path still valid
- Kind: contract/P1. A real one-Source installation returns legacy single result with correct source/owner metadata when lease remains valid.

### UX-AUD60-007 — warmup cursor is not spent by a skipped stage
- Kind: A/B scheduler/P1. Selected B's cache Warmup cannot start because earlier queues consume time. Cursor retains prior completed turn; next eligible invocation still considers B.

### UX-AUD60-008 — failed warmup cursor persistence suppresses GET
- Kind: fault injection/P0. Make `update_option` fail and readback differ at selected stage; no remote GET, stable `fairness-cursor-persist-failed`, and due job remains.

### UX-AUD60-009 — one warmup turn per multi-Source dispatch
- Kind: A/B/P1. Successful selected stage consumes no more than one Warmup GET in the current dispatch. A/B round-robin survives retries and disabled/no-due Sources.

### UX-AUD60-010 — archive purge requires renewed live runner
- Kind: expiry injection/P0. Lose lease during queue snapshot. Archive Purge must not run under old token; next normal stage handles lockLost.

### UX-AUD60-011 — worker crash/stale cleanup never evicts healthy new owner
- Kind: process/real MySQL/P0. Crash after successful claim, start new owner after expiry, run stale recovery against saved old row; only exact expired payload can be deleted.

### UX-AUD60-012 — runner/diagnostic budget pressure
- Kind: 30K/60K soak/P1. Measure A/B queue fairness, stage budgets, renewal cadence and effective execution while PHP memory/time limits and multiple external cron kicks overlap.

### UX-AUD60-013 — upgrade drain & Unicode barrier integration
- Kind: WAMP/P0. Barrier activation while multi-Source runner is switching owners must prevent new mutation leases and allow only authorized existing leases to finish.

## BE. UX61 — Custom Storage / durable queue migration (cumulative 10.34.24)

### UX-AUD61-001 — additive default-owned legacy import
- Kind: real MySQL/P0. Seed 225 Parent jobs and 225 Order markers under old default-owned option. Convert in at most 100 rows/slice, leave source until final CAS, preserve newest pre-existing destination payload.

### UX-AUD61-002 — positive owner required for actual legacy data
- Kind: fixture/P0. Empty fresh installation with no Connection/no old option passes; nonempty old option with no durable owner fails closed and remains available for recovery.

### UX-AUD61-003 — nondefault Source cannot adopt global legacy map
- Kind: A/B/P0. Source B import is a no-op; it cannot delete/move old default-owned queue or create B-owned jobs from it.

### UX-AUD61-004 — importer mutex, lease renewal and checkpoint
- Kind: two-process/P0. Concurrent importers serialize; failure/crash at row 19/20, 99/100 and checkpoint write retries without dropping an item. Expired importer must not delete source after losing lease.

### UX-AUD61-005 — option changed during import
- Kind: cache+DB race/P0. Producer updates serialized source between read, SQL fresh proof and atomic binary deletion. CAS must affect zero rows, preserve new legacy contents and restart from new fingerprint.

### UX-AUD61-006 — corrupted/truncated legacy identity fails closed
- Kind: contract/P0. Nonarray payload and dedupe_key requiring truncation or normalization do not silently coalesce jobs; leave source intact with diagnostic evidence.

### UX-AUD61-007 — malformed table row prevents empty-map rewrite
- Kind: real MySQL/P0. Inject corrupt serialized payload or SHA mismatch among valid rows; `load_map()` latches read fault and `replace_map([])` must fail without deleting a row.

### UX-AUD61-008 — SQL read failure is never empty queue
- Kind: MySQL outage/P0. Fail the SELECT then invoke order/parent queue read/modify/write in same request; write is rejected and old durable rows survive. A healthy full reread clears latch.

### UX-AUD61-009 — CAS delete vs newer reenqueue
- Kind: two-process/P0. Pause map reader before delete; a second worker updates same dedupe key. DELETE constrained by old hash must affect zero rows, never remove newer revision.

### UX-AUD61-010 — CAS UPDATE vs new revision or live claim
- Kind: two-process/P0. Competing writer updates payload or starts live claim; old map replacement must refuse overwrite and preserve new payload/claim token.

### UX-AUD61-011 — absent map key inserted without clobbering competitor
- Kind: two-process/P0. Both producers insert same previously absent dedupe key; loser sees mismatch on final readback, no unconditional upsert of competing payload.

### UX-AUD61-012 — no cross-Connection queue data movement
- Kind: A/B real MySQL/P0. All operations scope by `(connection_id,queue_type,dedupe_hash)`; equal keys in A/B stay separate, SQL failure cannot switch to Default or treat corrupted owner as absent.

### UX-AUD61-013 — partial replace_map recovery semantics
- Kind: fault injection/P1. Force failure after first of multiple CAS updates; routine reports incomplete, caller retries from fresh authoritative snapshot; do not claim transactional all-or-nothing semantics.

### UX-AUD61-014 — actual order queue recovery / Classic + HPOS
- Kind: WAMP/Portal/P0. Legacy import mixed with queued order and `uncertain` marker, kill PHP at each checkpoint; no duplicate remote Wallet/Submit; no auto-revival of failed/aborted orders.

### UX-AUD61-015 — lease + schema + utf8mb4 release integration
- Kind: real MySQL/P0. 30K rows/Connection, Unicode barrier during import, schema readiness, insert-if-absent and no destructive ALTER on customer tables; prove full 4-byte round-trip after explicit migration.

### UX-AUD61-016 — package/static/retirement regression
- Kind: release/P1. Confirm no new DDL, one-root ZIP, all SHA tracked, PHP/JS lint and prior UX54–59 contracts remain covered; build final Deep Test Suite only after UX65.

---

## BF. UX62 — REST/API security and Multi-Connection authority (candidate 10.34.25)

Every item must be moved into a newly re-pinned Suite and rerun on both Sources; isolated PHP mocks are noted but do not close the runtime contracts.

### UX-AUD62-001 — invalid webhook credential is a normal permission failure
- Kind: CONTRACT + RUNTIME/P0. `/portal/webhook-test` and `/webhook` wrong X-SEC with known WebHookId yield bounded 401/diagnostic, not fatal/private-method access; ensure no raw token/path persisted.

### UX-AUD62-002 — no stale cached X-SEC or enabled owner
- Kind: two-process/RUNTIME/P0. Seed row in request A; request B rotates credential or disables Source; A's new REST authentication cannot accept old credential, route to Default, or keep stale authorization.

### UX-AUD62-003 — WebHookId strict identity and first binding
- Kind: CONTRACT + MySQL/P0. Exact binary WebHookId including case, maximum 191-byte identity, reject 192 bytes without truncation or admin-clear. Unknown IDs cannot adopt Default; unbound candidate first bind requires one unique enabled exact X-SEC.

### UX-AUD62-004 — signed license token exact and unambiguous
- Kind: real MySQL/P0. Check rotated Token, mixed-case Token under case-insensitive database collation, two duplicate Token rows; reject stale/ambiguous results. No WordPress cache allowed as authority.

### UX-AUD62-005 — REST context isolation across requests
- Kind: CONTRACT + RUNTIME/P0. Authenticate Source A then fail B permission in same PHP request/test; Connection Context must clear instead of inheriting A. Verify B-only revenue/sync/settings and no cross-Connection data.

### UX-AUD62-006 — installation versus Source authority matrix
- Kind: REST/RUNTIME/P0. B cannot call global Health, cron/worker status, heartbeat, upgrade status/apply or report-now. Default can when enabled and fresh; duplicate historical is_default flags do not grant the noncanonical row global authority. Source-level webhook/sync/Portal operation routes remain scoped. Challenge endpoint should satisfy documented installation-attestation contract, without exposing signer private key.

### UX-AUD62-007 — provisioning CAS binds immutable route
- Kind: two-process/MySQL/P0. Concurrent HMAC-signed first writes to same pending Token with competing Source/Provider: one must not overwrite newly committed immutable binding; same valid idempotent re-provision permitted; Token rotation/disable during update fails or reports conflict.

### UX-AUD62-008 — provisioning cannot disable Default
- Kind: CONTRACT + REST/P0. `enabled=false` through signed provisioning and regular admin cannot remove installation controller.

### UX-AUD62-009 — HMAC timestamps/nonces and expired cleanup
- Kind: two-process/MySQL+cache/P0. Wrong signature, 300-second window, same nonce replay, expired nonce replacement: CAS must not delete a newly written nonce and stale option cache cannot admit a replay. No nonce adopted across other Tokens.

### UX-AUD62-010 — authority survives storage/query failure
- Kind: fault injection/P0. Failed DB SELECT for owner/token/default and storage barrier returns 4xx/5xx; never authenticates old cached/default row. Include disabled and deleted owner plus utf8mb4 migration in progress.

### UX-AUD62-011 — secret injection through diagnostics
- Kind: runtime/P0. Inject signed package URL, Token, X-SEC, SQL and absolute path into WP_Error/Throwable across webhook, REST, failed upgrade, Health; public outputs/logs must expose stable codes not secret messages, including historical upgrade status.

### UX-AUD62-012 — endpoint inventory and compatibility
- Kind: STATIC + integration/P1. Assert each `register_rest_route` callback and authority class; Portal client compatibility, versioned payloads, no existing public Swagger DTO renamed; source A/B route and no-header single-Default policy explicitly tested.

---

## BG. UX63 — Installation, Upgrade, Rollback and Uninstall (candidate 10.34.26)

### UX-AUD63-001 — fresh install, 0-Connection baseline
- Kind: disposable WordPress/MySQL/P0. Tables/metadata created without requiring an existing owner; no phantom Default/no auto-adoption; customer sets Token and server provisions Source later.

### UX-AUD63-002 — migrated install and utf8mb4 gate
- Kind: real MySQL/P0. `utf8mb3` input either upgrades through explicit safe Unicode migration or blocks runtime with actionable Health, no silent low-charset operation; confirm 4-byte data round-trip, resumed checkpoints and preservation of existing rows.

### UX-AUD63-003 — verified upgrade package/manifest and allowlist
- Kind: real PHP/WP Filesystem/P0. Package origin/redirect, SHA, manifest file list and build fingerprint, plugin root and version readback must match requested signed deployment; reject tampered archive or symlink/path traversal.

### UX-AUD63-004 — upgrade drain when two Sources are live
- Kind: two-process/WAMP/P0. Source A sync, Source B webhook/order and upgrade start. Existing work reaches safe boundary; new runtime locks are rejected until release; updater renews ownership, never force-unlocks worker's token.

### UX-AUD63-005 — installed files touched then rollback fails
- Kind: filesystem fault injection/P0. Force restore_backup WP_Error and throw. Durable manual recovery hold persists beyond finite lease expiry; no webhook/cron/order/DDL mutation and no runner kick. Only signed updater/barrier path may initiate verified recovery.

### UX-AUD63-006 — verified recovery and hold cleanup
- Kind: WordPress/Filesystem/P0. Signed full replacement with verified target version, manifest/fingerprint and successful option delete clears hold, resumes staged queues from existing cursor. Failure to clear hold does not declare success.

### UX-AUD63-007 — DB outage while writing recovery hold
- Kind: injected SQL error/P0. If `update_option` fails during unverified rollback, do not claim durable fail-closed behavior; surface explicit operator action and demonstrate an alternative manual site-disabled recovery procedure before production sign-off.

### UX-AUD63-008 — old barrier state cannot delete new state
- Kind: two-process/option-cache/P0. Pause old owner between lease release, SQL source proof and CAS deletion; new owner writes state. Old cleanup may not remove new state; expired read-only `is_active` must not delete option.

### UX-AUD63-009 — never leak raw old/new exception text
- Kind: security/RUNTIME/P0. Preseed legacy Upgrade status/audit with signed URL/path/token then fail installation/backup/restore. Public REST/Health/logs contain bounded stage and recovery flag only. Retained internal evidence follows existing restricted retention policy.

### UX-AUD63-010 — uninstall during UX61 importer
- Kind: MySQL/WP Uninstall/P0. Crash at rows 19/20, 99/100 and 200/225: legacy Parent/Order sources and new custom storage remain; reinstall/import does not lose or overwrite newer revision.

### UX-AUD63-011 — uninstall with unresolved remote payment
- Kind: Classic+HPOS/Portal/P0. Preserve `uncertain` Order recovery marker, immutable revenue/payment evidence and manual retry quarantine; uninstall/reinstall cannot re-send ambiguous Wallet automatically.

### UX-AUD63-012 — uninstall must not steal an active runtime lease
- Kind: two-process/MySQL/P0. Uninstall while A owns worker and B owns migration: active exact options survive; only expired byte-matched lock rows may retire. Reinstall/cleanup respects remaining expiry/fence and object-cache consistency.

### UX-AUD63-013 — cleanup scope and recovery hold survive reinstall
- Kind: disposable WP/RUNTIME/P0. Business settings/maps/order tables and failed upgrade status/hold remain where required; generated assets and safe runtime diagnostics may be deleted. Never drop customer tables or silently remap orphan owner to Default.

### UX-AUD63-014 — package/regression gate
- Kind: STATIC + FULL TEST SUITE/P0. One-root cumulative 10.34.26 ZIP includes UX62+UX63; manifest matches every listed byte, PHP lint passes, no unexpected schema/Swagger change, previous UX54–61 cases and frozen UX51 contract preserved. Real site PASS cannot be declared from source/mocks alone.


---

## BH. UX64 — Identity ownership, revision/ack and legacy map (candidate 10.34.27)

### UX-AUD64-001 — A/B simultaneous Woo ID admission
- Kind: two-process/MySQL/P0. Source A and B race two different remote product GUIDs to the same Woo post ID. Exactly one Product Map owner may commit; the loser returns failure and cannot create a second owner. Repeat with reversed schedule and Default legacy seed.

### UX-AUD64-002 — owner guard fail-closed and upgrade drain
- Kind: lock fault injection/P0. Runtime option unavailable, named MySQL GET_LOCK busy/error/timeout, barrier enabled after preflight, lease lost during variation stale cleanup: no foreign-owner mutation. Compare-release only own token. Check MySQL lock name is installation-global; release happens after rejected insert/readback too.

### UX-AUD64-003 — case-distinct, prefix-colliding opaque GUIDs
- Kind: real utf8mb4 MySQL/P0. Probe same 140-character unique index prefix but different full remote GUIDs; case-distinct identifiers under case-insensitive collation. Readback must not accept a different identity or overwrite its Woo binding. Cover lookup, delete, upsert and reverse-map cleanup.

### UX-AUD64-004 — Product and Variant stale desired revision
- Kind: real MySQL/P0. Commit revision N+1/hash B, then inject revision N/hash A and equal revision/hash C. Neither may regress the committed desired watermark/hash; report distinct stale/conflict rather than silently declaring success.

### UX-AUD64-005 — ack exact generation and active claim
- Kind: two-process/MySQL/P0. Worker A has revision N, B claims or creates N+1. A's stale `mark_applied` must not clear B's claim, retry budget, dirty flags or desired hash. Include equal revision conflicting hash and SQL outage/readback failure.

### UX-AUD64-006 — Variation parent rebind at stale mirror
- Kind: two-process/HPOS+Classic/P0. Variant mirror older seen/applied watermark must not reparent a newer row; all identity, hash and presence markers read back coherently. Exercise storage bridge during map import.

### UX-AUD64-007 — crash at mapping owner SELECT/upsert/prune
- Kind: process-kill/P0. Kill A after acquiring runtime lease, after GET_LOCK, after map insert, before variation reverse-map cleanup, and after cleanup. B must never adopt A's Woo ID; restart must converge without deleting newer mapping.

### UX-AUD64-008 — repository schema and no DDL drift
- Kind: schema/P0. Confirm existing Custom Storage global UNIQUE Woo IDs, map lacks global Woo key but guard protects its writes. No schema/index changes introduced by UX64; utf8mb4 migration remains fail-closed.

### UX-AUD64-009 — 30K+30K identity throughput
- Kind: performance/P1. Measure extra owner SELECT, named lock and option lease cost at two 30K Sources; bound GET_LOCK contention and verify fair progress/no worker starvation under concurrent Webhook and Full Sync.

### UX-AUD64-010 — no orphan adoption
- Kind: integrity/P0. Explicit unknown/deleted/zero Connection and legacy fallback files remain unadopted and separately inventoried; neither map nor custom state may silently assign them to Default.

---

## BI. UX65 — Cross-pipeline route authority and release gate (candidate 10.34.28)

### UX-AUD65-001 — cached GET route rotation
- Kind: two-process/WAMP/P0. Source A starts a GET, Admin rotates Token/X-SEC/API URL, B remains active. Before a subsequent send, A must revalidate and fail with `mobo_core_api_connection_changed`; no old headers reach new endpoint. Repeat with a disabled/deleted owner or DB SELECT failure.

### UX-AUD65-002 — redirect-hop credential fence
- Kind: Portal HTTP/P0. Return 302 to same origin while disabling A between hops. Follow-up must not send A headers. Cross-origin redirects must still be rejected even before the new route fence; 301/302/303/307/308 variants.

### UX-AUD65-003 — fresh GET/POST context and no Default fallback
- Kind: multi-Connection/P0. Both Sources independently use their own fresh Token/X-SEC/source URL. Missing/unprovisioned/disabled explicit owner must fail closed. Fresh context replacement after authorized reprovision succeeds; no unrequested credential auto-retry on uncertain external side effect.

### UX-AUD65-004 — Admin/provisioning compare-and-update race
- Kind: two-process/real MySQL/P0. Pause Admin between fresh read and SQL UPDATE, perform signed reprovision/security rotation/disable, then resume. Old preimage must fail without overwriting newer row. Retry only after explicitly reloading state. Include Token uniqueness under binary collation and installed global named guard.

### UX-AUD65-005 — maintenance owner mutation gate
- Kind: two-process/P0. Enable upgrade barrier/Unicode hold while Admin changes license, security, enabled or WebHookId, and while signed provisioning runs. New mutation must fail until verified recovery; no implicit Default disable.

### UX-AUD65-006 — API upstream error disclosure
- Kind: security/RUNTIME/P0. Inject Token, X-SEC, signed URL and filesystem path into transport WP_Error and HTTP GET/POST JSON body. Validate no raw text in propagated error message/data, circuit, Health, REST or durable storage. Preserve numeric HTTP status for endpoint discovery.

### UX-AUD65-007 — ongoing specialized transport inventory
- Kind: security/P0. Inspect and test non-general-API-Client Wallet, Shipping, Admin status, cache, media, third-party HTTP and historical diagnostics. Do not declare all-plugin secret redaction until each is verified.

### UX-AUD65-008 — disable vs in-flight HTTP and Wallet
- Kind: shared protocol design + Portal correlation/P0. Force disable/reprovision immediately before and immediately after actual network send. Establish explicit drain acknowledgement and distinguish pre-send safe deferral from possibly committed remote purchase. No ambiguous Wallet auto-retry.

### UX-AUD65-009 — mixed and multi-Source checkout
- Kind: Classic+Store API+HPOS/P0. Mixed non-Mobo/Mobo and two-Source orders must never partially submit to a Provider; stale queue markers stay quarantined and cannot revive aborted order. Include simultaneous checkout/Connection disable.

### UX-AUD65-010 — webhook/full/media/category concurrency
- Kind: multi-process/P0. Insert out-of-order webhook after GET start but before Full Sync commit, gallery linkage, Category retirement, price/stock reconciliation. Verify bounded retries, owning Source, newest watermark and no destructive stale apply.

### UX-AUD65-011 — upgrade/Unicode with 2 Sources
- Kind: two-site fault injection/P0. Migration/upgrade starts during A API GET, B event claim and Admin provisioning. All new mutation gates block; forced failed rollback persists recovery hold and preserves business/order evidence.

### UX-AUD65-012 — 60K soak, cache and scheduler
- Kind: performance/P1. Two Sources of 30K products each, no multilingual/multicurrency assumptions. Monitor SQL round trips, named lock wait, WP options growth, memory and Work Queue fairness, starvation, HTTP timeout and cache invalidation.

### UX-AUD65-013 — orphan and recovery operator inventory
- Kind: real database/P0. Report owner=0, missing/deleted/disabled owner, legacy orphan JSON, failed Unicode checkpoint, uncertain Wallet and pending upgrade hold separately; do not auto-delete or reassign Default.

### UX-AUD65-014 — fresh cumulative Deep Test Suite and release pin
- Kind: FULL/WAMP/P0. Build a newly pinned suite for cumulative 10.34.28; preserve untouched UX51 r8.3.177 gate, run UX54–65 regressions on two disposable sites with real MySQL utf8mb4, Woo Classic+HPOS, Portal and fault injection. Syntax and isolated stubs are not production PASS.


## BJ — UX66 specialized Checkout / signer authority (P0)

### UX-AUD66-001 — durable route at each Checkout network boundary
- Kind: two-process/Classic+Store API/P0. While Login/clear/add/snapshot/payment are staged, disable and then reprovision Connection A (Token, X-SEC, Source URL and Provider). Verify no stale new HTTP after pre-send fence, no silent adoption of Default, and A/B cookie/lock isolation.

### UX-AUD66-002 — pre-send vs possibly-sent Wallet distinction
- Kind: Portal/fault-injection/P0. Drop response before/after remote Wallet acceptance; only provably pre-send route drift may clear its guard. Do not auto-retry ambiguous Wallet without server-side correlation.

### UX-AUD66-003 — external validation credential isolation
- Kind: integration/security/P0. Portal signed origin and third-party validation URL; inspect Token/X-SEC leakage on redirects, mismatched host/scheme/port, URL changes, Admin reprovision during signing and disabled owner. Test the signature challenge against real Portal.

### UX-AUD66-004 — isolated source regression
- Kind: local/CI. Replay the new Checkout route, signer and origin harnesses with the cumulative version and the prior UX64/UX65 contract fixtures; PHP7.4 compatibility syntax/run check on a compatible runner separately.

## BK — UX67 specialized diagnostics and release readyness (P0/P1)

### UX-AUD67-001 — real secret injection across specialized error sinks
- Kind: WP/Woo/Portal/security/P0. Inject token, X-SEC, URL query and filesystem path into HTTP transport errors and PHP exceptions for cPanel Health, Webhook, cache warming, shipping, images/refresh, SMS notes/gateway, updater and Checkout. Inspect REST, Event Store, Health, Woo metadata, log files and queue `last_error` for raw content.

### UX-AUD67-002 — SMS ambiguous gateway crash boundary
- Kind: Classic+HPOS/P0. Kill after durable SMS fence and before/after gateway success; one-shot marker must prevent automated duplicate. Confirm generic note plus actionable stable code.

### UX-AUD67-003 — legacy diagnostic retention / operator decision
- Kind: security/migration/P1. Inventory historical raw last_error/options/log rows created by older builds. Report counts and location without echoing secrets; define opt-in scoped retention/redaction. Never silently wipe order/recovery evidence.

### UX-AUD67-004 — combined runtime gate and re-pinned Suite
- Kind: full/WAMP/P0. Preserve frozen UX51 r8.3.177, pin new Suite to 10.34.30 manifest, two disposable sites with utf8mb4 and real MySQL, Woo Classic+HPOS and Portal, Source A/B, concurrent image/category/full/webhook/reprice/stock, maintenance/Unicode fault injection, orphan report and 30K+30K throughput. Do not elevate isolated PASS to production sign-off.


## BL — PERF01 strict row-store verification (candidate 10.34.32)

### PERF01-001 — one-key persisted identity
- Kind: isolated contract + real MySQL. `put()` must not call `load_map()`; verify one `(connection_id,queue_type,dedupe_hash,dedupe_key)` row with valid SHA-256 payload and exact serialized bytes. Distinguish missing, corrupt, SQL-error and competing revision; preserve legacy `read_one()` compatibility. Owner 0 never writes.

### PERF01-002 — order-recovery idempotency under load
- Kind: two-process MySQL/HPOS + Classic. Concurrent order recovery `put()`, `delete_key()` and migration must not adopt another Connection, revive failed/aborted orders, drop uncertain Wallet evidence or hide corrupt rows in map-wide diagnostics. Test crash between upsert and readback.

## BM — PERF02 Parent/Variation lookup (candidate 10.34.32)

### PERF02-001 — ordinary Parent enqueue
- Kind: isolated + WAMP multi-Connection. One ordinary enqueue/re-enqueue below capacity must be single-row, maintain initial `queuedAt`, GUID fallback, `processingToken` invalidation, pending marker and per-Connection count. Failed legacy importer/read/count/write must fail closed.

### PERF02-002 — Parent cap and in-flight worker
- Kind: real two-process MySQL/Woo. New item when queue has 5,000 items must preserve old `updatedAt` eviction order. Re-enqueue during `WC_Product_Variable::sync()` must survive old worker commit. Test lock-expiry takeover and forced SQL error after row upsert; do not claim `process_batch()` is row-level yet.

### PERF02-003 — Variation reverse index
- Kind: isolated + runtime. Duplicate signatures retain first-ID initial winner. Changed/new signature supersedes as before; displaced old winner cannot delete current alias. Parent/quarantine invalidation clears forward and reverse maps and never leaks between Sources.

### PERF02-004 — repin and throughput boundary
- Kind: deep suite/RUNTIME. r8.3.179 remains pinned to 10.34.30; create a new suite for this candidate after baseline Gate. Benchmark optional: structural improvements are confirmed without claiming numeric gains. Full payload-map conversion, verified metadata bulk updates and SQL EXPLAIN remain subsequent tranches.


## BN — PERF03 grouped verified price-source bookkeeping (candidate 10.34.34)

### PERF03-001 — all-no-op metadata refresh
- Kind: isolated/source + WP metadata integration. For 3 consecutive price-source keys, use one final explicit post_meta cache delete, zero UPDATE for matched values, and verify each normalized persisted value after refresh. Check missing and existing keys, scalar bool/null/number/array semantics and terminal metadata filters.

### PERF03-002 — rejected partial write and stale cache
- Kind: fault injection. Inject an update_post_meta failure after an earlier member was written, a stale apparent cache-only no-op, and a duplicate key after sanitize_key. Batch must return false without claiming durability; caller must not mark product source hash applied. Never group source revision/seen/applied, crash or payment fences with this helper.

### PERF03-003 — multi-Source/HPOS compatibility
- Kind: real WordPress/Woo. A/B product bookkeeping remains isolated; pricing policy updated_at only follows a successfully verified group. Ensure no unnecessary Woo CRUD or page-cache purge on true storefront no-op. Compare cache operations in object-cache-enabled and standard WordPress.

## BO — PERF04 bounded Order Recovery and CAS cleanup (candidate 10.34.34)

### PERF04-001 — ordered bounded owner-page
- Kind: source/isolated + real MySQL. For one Connection/queue, ORDER BY id ASC LIMIT 1/2/10 must preserve the first-page result of legacy map-order slicing; validate selected-page dedupe identity and serialized SHA-256. A corrupt off-page record must not be decoded; when fetched later it must fail closed. Connection 0 cannot map to Default.

### PERF04-002 — queue read error must block purchase
- Kind: fault injection/Classic+HPOS. SQL failure, invalid digest or malformed serialized payload in the selected page must not become an apparently empty queue or revive a legacy Woo queued order; report queue-read-failed and remaining=true before remote Cart/Wallet work. Full primary queue map corruption must be treated the same way.

### PERF04-003 — cleanup under re-enqueue and claim
- Kind: 2-process MySQL. Re-enqueue exact marker with a new payload between page read and cleanup; older snapshot cleanup must not delete the new row. Live claim, owner mismatch and corrupted expected hash reject delete; expired claim follows the documented policy. Test byte-identical ABA separately: payload hash is not a monotone generation.

### PERF04-004 — remaining full-map follow-ups
- Kind: separate design before code: Parent Finalize `process_batch()/has_due_work()/status` must preserve payload queuedAt ordering rather than assuming created_at equivalence. Primary Order Submission listing still walks its entire map for at most five orders, but cannot be arbitrarily truncated without preserving invalid-row cleanup, dedupe, order/payment state and exact queue-token cleanup. New Deep Test Suite must repin after 10.34.30 baseline Plugin Gate.


## BP — PERF05 Parent Finalize bounded batch / row CAS (candidate 10.34.36)

### PERF05-001 — payload FIFO and bounded expensive reads
- Kind: isolated + WAMP/MySQL. Compare initial selection and equal-queuedAt tie behavior with 10.34.34, including SQL created_at different from payload queuedAt. Row mode reads one complete snapshot and one final status map per batch, no per-item replace_map. Newly enqueued parents during Woo work remain pending for subsequent pass. Test time budget and two Sources.

### PERF05-002 — per-parent owner CAS and retry/backoff
- Kind: two-process MySQL. Trigger re-enqueue during WC_Product_Variable::sync(), SQL error during claim verification, concurrent claim expiry/takeover, missing/disabled owner, product exclusion, stale item deletion, lost final lock, Woo exception, crash after claim and after actual Woo sync. A stale `(hash, token)` can never delete a new row or its `_mobo_parent_sync_pending` marker; retry delays and attempt_count persist under same owner.

### PERF05-003 — remaining full-map/status economics
- Kind: source follow-up and optional query profiling, not required for current source correctness: current parent queue serializes payload queuedAt in longtext and uses map-wide start/end/status, not an indexed due queue. Future DDL/index changes require separate migration/backfill and `utf8mb4` readiness proof; do not fake order by created_at or suppress strict hash checking.

## BQ — PERF06 Primary Order Submission bounded head page (candidate 10.34.36)

### PERF06-001 — source-owned FIFO, cutover and CAS cleanup
- Kind: isolated + real two-process MySQL, Classic/HPOS. Test bounded `ORDER BY id ASC LIMIT 16`, invalid heads, corrupt first/off-page payload, failed option import, Connection 0, full vs partial page, per-owner count error, superseded queueToken and same-token changed payload. Enqueue and cleanup must not call load_map/replace_map on ordinary row-store path.

### PERF06-002 — no duplicate primary/recovery order
- Kind: real Woo/Portal fault injection. Primary queue 16+ rows plus recovery marker for an off-page order must NOT process emergency marker while primary page is full. When all primary rows are visible, known duplicates can be cleaned; remaining independent markers retain the order and exact hash cleanup. Remote Wallet success with lost ack must remain uncertain; failed/aborted markers never revive purchase. Mixed/non-Mobo and multiple-Source orders remain entirely unsent.

### PERF06-003 — manual retry is blocked when row ownership cannot be proven
- Kind: isolated + admin Classic/HPOS. A broken primary read or incomplete Default-only cutover cannot be treated as 'not queued' in the manual Retry action. Check fresh owner and Woo status, per-order purchase lock, queueToken and payload-hash CAS during simultaneous admin retry and background enqueue. No implicit Default or cross-Connection recovery.

### PERF06-004 — performance evidence and repin
- Kind: real SQL/worker gate, optional timing. Check page-size bounded reads and strict indexed count under 30K per Source, owner/queue/dedupe indexes and object cache, without claiming percent speed from source. Re-pin Deep Test Suite to cumulative PERF branch only after baseline 10.34.30 r8.3.179 Plugin Gate has completed.


## BR — PERF07 Parent Finalize aggregate tail (candidate 10.34.38)

### PERF07-001 — strict pending/due aggregate
Prove Custom Storage returns pending/due without payload materialization, excludes live claims, respects retry availability and fails closed on SQL/readiness errors.

### PERF07-002 — queuedAt ordering boundary
Prove batch selection still uses the authoritative payload `queuedAt` snapshot. Do not substitute SQL `id`, `created_at` or `available_at` until a dedicated queued-at schema migration/backfill is designed and proven.

### PERF07-003 — concurrent enqueue after batch snapshot
Two-process test: enqueue/re-enqueue while Woo parent sync releases the queue lease; the final aggregate may report new work but the old claim must never consume the newer generation.

## BS — PERF08 Reprice SQL shape (candidate 10.34.38)

### PERF08-001 — count equivalence
Against real MySQL fixtures, compare old/new logical eligibility for published simple/variable products and published variations with published parents, including duplicate legacy meta rows and empty API prices.

### PERF08-002 — cursor equivalence
Prove `UNION ALL` branches produce the same ascending global post-ID candidate sequence across mixed product/variation IDs and Connection A/B ownership.

### PERF08-003 — EXPLAIN / optimizer evidence
Capture `EXPLAIN ANALYZE` (or supported equivalent) on representative data for entity-meta and legacy-postmeta paths. Source audit proves query shape, not runtime cost.

### PERF08-004 — policy/parent debt regression
Re-run Reprice checkpoint, `pendingParentIds`, policy invalidation and kill-before-parent-sync tests; SQL candidate optimization must not alter durable cursor or parent debt semantics.


## BT — PERF09 existing Variant-count endpoint fast path (candidate 10.34.40)

### PERF09-001 — scalar endpoint contract and route security
- Kind: isolated + Portal integration. Existing `/{productId}/get-variants-count` returns a non-negative JSON integer under current Token/X-SEC authority. The WordPress scalar decoder must retain fresh Connection route fingerprint, signer, redirect-origin checks, deadline and circuit breaker; ordinary array endpoints must still reject scalar JSON.

### PERF09-002 — non-destructive topology shortcut only
- Kind: source/isolated + Woo workflow. Only `count > 1` may skip the full Simple lookup, and only when ProductUpdated is variation-capable while the current Woo parent is Simple. Count 0/1/error/malformed/missing endpoint must use the previous full authoritative `get-variants` path. Manual/Repair Step 5 must not gain a count-only mutation path.

### PERF09-003 — race/fallback
- Kind: two-process Portal/Woo. Change variants between count and subsequent webhook delivery. A stale `>1` count may only delay Simple convergence by handing off to UpdateVariant; it must never clear/make-unpurchasable or select a Variant. Endpoint unavailable/401/503 must preserve existing retry semantics.

## BU — PERF10 deferred UpdateVariant payload reuse (candidate 10.34.40)

### PERF10-001 — immutable event payload evidence
- Kind: cross-source contract. For a fixed Portal `EventWebhook.Id`, `GET api/customer-batches/{id}` returns the stored `Data`; delivery retries may update status/try/locks but not the payload bytes. If this Backend contract changes, WordPress durable payload reuse must be re-audited.

### PERF10-002 — DB and file defer persistence
- Kind: isolated + WAMP. Pull one lightweight UpdateVariant, force `waitingForParent`/lock defer, verify the normalized full payload is written by table `mark_pending_progress()` and fallback-file rewrite. Retry must execute locally with zero second payload GET while retaining event/product ordering fields.

### PERF10-003 — no caching of failed/unknown pulls
- Kind: fault injection. 401/404/5xx/transport/invalid JSON during the initial pull remains a queue failure/retry and must not replace the notification with incomplete data. Successful immutable payload reuse must remain Connection-owned and never cross Source boundaries.

## BV — PERF11 indexed queuedAt storage migration (candidate 10.34.42)

### PERF11-001 — dbDelta / readiness / index creation
- Kind: disposable WordPress + real MySQL. Upgrade schema v12→v13 with populated `mobo_work_queue`; prove `queued_at_us` and `queue_order(connection_id,queue_type,queued_at_us,id)` exist before storage readiness/version stamp succeeds. Repeat on utf8mb4 databases and after an interrupted/failed dbDelta run.

### PERF11-002 — bounded crash/restart backfill
- Kind: two-process MySQL. Populate historical Parent rows whose payload `queuedAt` order differs from SQL `id/created_at`. Kill PHP between select and CAS update and between slices; restart must converge without resetting a newer enqueue. Corrupt payload/hash/dedupe identity must fail closed and remain inspectable.

### PERF11-003 — concurrent re-enqueue / ABA
- Kind: two-process MySQL. Re-enqueue the same Parent after backfill observes its old payload. Old CAS must not overwrite the new `queued_at_us`; the post-race reread may accept only an exactly consistent payload/hash/index mirror. Test byte-identical payload ABA separately because SHA-256 is not a monotone generation.

### PERF11-004 — index write/storage cost
- Kind: SQL profiling. Measure insert/update overhead and index size across all queue types because `queue_order` is on the shared work-queue table. Compare benefit on Parent selection against global write amplification; source audit does not claim a net percentage.

## BW — PERF12 indexed Parent Finalize batch (candidate 10.34.42)

### PERF12-001 — order equivalence
- Kind: real MySQL/Woo. Compare pre/post-migration selection for queuedAt ties, retry availability, live/expired claims and differing insertion IDs. Indexed path must equal payload `queuedAt ASC, id ASC`; current second-resolution queuedAt must not gain false microsecond semantics.

### PERF12-002 — migration fallback boundary
- Kind: fault injection. While any row remains `queued_at_us=0`, Parent Finalize must keep the historical full-map ordering path. Transition to indexed selection only after backfill reports complete. SQL/read/hash errors must not be interpreted as completion or an empty queue.

### PERF12-003 — claim/commit under concurrent enqueue
- Kind: two-process Woo/MySQL. Enqueue/re-enqueue while a bounded indexed batch releases the queue lease for `WC_Product_Variable::sync()`. Existing `(payload_hash,claim_token)` CAS must prevent the old worker from consuming or clearing the newer request.

### PERF12-004 — cumulative performance-candidate repin
- Kind: Deep Test Suite + WAMP. Re-pin against exact 10.34.42 bytes only after the independent 10.34.30 release-gate line is closed. Run activation/upgrade, schema readiness, FAST, Plugin Gate and release/fault suites before replacing the frozen baseline.



## BX — REL01 terminal event retention / Sync Health ownership

- Seed a current incomplete product plus terminal `UpdateVariant` failure older than failed-event retention. Run Maintenance and prove the event row is deleted only after durable Sync Health stores its current event version and stable terminal error.
- Re-read operational Sync Health after event deletion and require `terminalUpstreamInvalid=1`, `unownedConvergenceGap=0`.
- Seed a stale older terminal event against newer applied product version and prove archival does not regress Health.
- Repeat for Source A and Source B; preservation must execute under each row's Connection context.
- Inject Health write/readback failure and prove the terminal event row remains.

## BY — Reprice handoff fixture fingerprint repin

- The WAMP Release Gate handoff helper must seed a valid UX55+ `policyFingerprint` (and normalized pending parent debt) when it manually creates a synthetic running generation.
- Negative control: missing/changed fingerprint must still produce `invalidated`; do not weaken the plugin policy fence.

## Mandatory migration customer-hosting chaos gate

Database-migration release testing must include the durable customer-hosting failure model in `docs/internal/migration-customer-hosting-chaos-contract.md`. This is a hard release gate, not an optional benchmark checklist. Future suites must cover arbitrary PHP/host termination, DB errors/reconnects, overlapping Cron/web requests, lock-loss/reclaim races, partial Entity cleanup journal replay, late writes, cursor rewind, exact terminal residue proof, and metadata preservation as specified there. The gate also includes Operational/Identity cleanup cursor crash-replay, behind-cursor rewind, byte-bounded Entity multi-chunk replay, stale set-based proof snapshots, and fresh under-lock Operational ownership revalidation (`MIG-CHAOS-032` through `MIG-CHAOS-039`).
