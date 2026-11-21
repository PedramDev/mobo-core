# Mobo Core 10.34.14 — Admin UX / pricing and product-exclusion consistency

- Pricing precedence is now explicit and centralized: after identity/ownership validation, per-Variation `mobo_additional_price` wins over parent Product percentage override, which wins over the global pricing policy.
- `Mobo_Core_Price_Calculator` and `Mobo_Core_Product_Pricing_Policy::effective_policy_type()` use the same precedence contract.
- Dynamic percentage benefits preserve decimals such as `12.5`; the previous digit-only normalization could convert `12.5` to `125`.
- The Pricing admin page distinguishes global policy from per-Product/per-Variation exceptions, makes calculated Dynamic lower bounds read-only, and explains that Dynamic ranges match the raw source `price`, not `comparePrice`.
- Reprice generations store a policy fingerprint. Admin policy saves are blocked while Reprice is running, and a changed policy invalidates a paused/running generation instead of allowing a mixed-formula resume.
- Reprice candidate-count read failures are represented as unavailable data rather than a false zero, historical failed-attempt counters are not presented as unresolved active errors, and idle Reprice status no longer polls continuously.

- Product Exclusions are now presented as source-mutation exceptions rather than as a generic product-list filter. The Admin page explicitly preserves Mobo identity/order semantics and explains queued-work and re-enable/catch-up behavior.
- Product-exclusion saves now normalize and deduplicate the exact path list used by runtime, reject non-empty entries which cannot resolve to a usable product path, verify the durable Connection-scoped write, and no longer report success after a failed storage write.
- Absolute source URLs without a product path are no longer normalized into synthetic never-matching paths. Existing path-exact behavior remains unchanged: host/query/trailing slash are ignored, lowercase canonical paths are used, and `/0338` stays distinct from `/338`.

## Admin UX — Sync Health (UX14)

- Sync Health no longer treats `operationalStatus=unavailable` or zeroed fallback counters as a healthy result.
- Background refresh now preserves the last-good operational snapshot when a new audit is unavailable/invalid, and invalid historical snapshots are rejected on read.
- Snapshot age/staleness is visible; an old snapshot is not presented as fresh current evidence.
- Product Exclusions are removed from the active Sync Health denominator and reported separately as intentionally excluded from monitoring.
- Health-side exclusion lookup is observational/read-only and does not persist GUID-to-URL evidence merely because the Health page was opened; normal mutation/runtime paths keep their existing evidence behavior.
- Automatic recovery-only state is distinguished from manual-attention failure, and an empty monitored set is distinguished from a healthy catalog.
- Actionable buckets expose bounded WooCommerce product links while raw GUID/error details remain in technical diagnostics.

## Admin UX — Site Health (UX15)

- The Site Health primary badge now evaluates actual Cron evidence instead of treating `DISABLE_WP_CRON=true` as proof that real Cron is healthy.
- Health authentication readiness is Connection/X-SEC and multi-Connection-ownership aware; the page no longer hard-codes `/health` as ready when local authentication configuration is incomplete, and it does not misrepresent local readiness as proof of Portal network reachability.
- Health and Heartbeat semantics are separated in the UI: GET `/health` is read-only reporting, while POST `/heartbeat` is the distinct no-cache bounded-recovery path using the shared runner.
- Database schema readiness, declared WordPress/PHP/WooCommerce minimum versions, failed Webhook jobs, failed Image jobs, image-environment readiness, and explicit storage/quota write failures now participate in the primary Site Health state.
- Hosting-account quota and raw server-filesystem capacity are labelled separately; unavailable quota data remains unknown rather than being presented as a measured healthy value.
- The read-only Site Health diagnostics page no longer exposes a misleading Settings Save form/button. Legacy server-side POST compatibility remains available for stale clients/forms.
- A central `docs/internal/admin-ux-test-backlog.md` now records permanent regression-test debt for all Admin UX pages closed so far, plus global CSS/JS/RTL/mobile/error-lifecycle contracts. Future page audits must append their discovered contracts before the page is marked CLOSED.

## Admin UX — Image Refresh (UX16)

- Image Refresh now distinguishes clean completion from a stable completed cycle that retained queue-quarantined images, unrepaired WebP subsizes, Stage-7 safety-blocked/error items, or failed orphan families; retained work is no longer rendered as green success.
- A missing Product Repair is a visible independent prerequisite. Image Refresh does not start/retry Repair, the Start control stays unavailable until Repair completes, and a rejected Start is reported as a warning/failure instead of success.
- The automation start path checks the Repair prerequisite before creating/resetting a generation, retrying failed image rows, or resetting cleanup/preflight state, preventing a rejected Start from mutating workflow progress.
- Admin Start is fail-closed for an unavailable/unprovisioned selected Connection, incomplete multi-Connection runtime ownership, or missing Image Refresh/Orphan Cleanup schema/components.
- `batchPossiblyStuck`, waiting/backoff states, and blocking environment/component errors now have distinct manager-facing status instead of all being labelled as ordinary running.
- Queue/schema data unavailable is no longer represented by factual zero counters. Queue labels now reflect that `pending` includes active processing rows.
- Generation byte reporting now shows reduction, increase, no-change, or not-measured; growth is no longer clamped to `0 B savings`.
- Progress is explicitly current-stage progress. Idle/completed states no longer show misleading `step 0 of 9` progress, and stages without a trustworthy denominator use a non-numeric running state.
- Live Admin polling runs only while Image Refresh or its prerequisite Repair is active; idle/completed screens no longer poll indefinitely.
- The Start surface now discloses automatic safety-gated old/orphan media deletion, recommends a recoverable DB/uploads backup, and requires explicit confirmation.
- Current `README_FULL.MD` Image Refresh instructions were updated from the obsolete manual 1-9-stage workflow to the current one-click autonomous media workflow with independent pre-completed Product Repair.
- `docs/internal/admin-ux-test-backlog.md` now contains the permanent UX16 Image Refresh regression contracts for later Test Suite implementation.

## Admin UX — Cron / Worker (UX17)

- The Cron/Worker primary state now separates external scheduler liveness from Worker health. A fresh Cron hit alone cannot produce a green state when the scheduled Runner result is partial/failed.
- Real scheduler hits are recorded before the shared dispatcher claim, so a correctly firing host Cron is not misclassified as overdue merely because another Mobo worker owns the site-wide lease at that instant.
- Scheduled Cron result provenance is now stored separately from the shared Runner result. Self Runner, Heartbeat and administrator-triggered slices can no longer overwrite the result used to evaluate real Cron health.
- `mobo_core_real_cron_last_success_at` now advances only for an externally scheduled Runner slice with `status=ok`; partial/admin/Self Runner slices no longer masquerade as a successful real Cron.
- Self Runner healthy timestamps likewise require `status=ok`; enabled/backoff/dispatch failure/last-run failure are distinct manager-facing states.
- The administrator support action is now explicitly a real, mutating Worker slice with confirmation. Its notice reports Runner status/rounds/failed stages and states that a manual run does not prove the host Cron Job is configured.
- Runner capacity presets no longer alter `mobo_core_real_cron_expected_interval_seconds`. Expected interval is documented as monitoring configuration that must match the actual host schedule.
- `DISABLE_WP_CRON=true` is no longer described as proof that an external `wp-cron.php` task exists; it only proves request-triggered WP-Cron is disabled locally.
- Cron/Worker token help now reflects actual runtime ownership: Self Runner and HTTP Cron/Worker endpoints use the secret via `X-SEC`, while direct PHP CLI `mobo-cron.php` remains token-independent.
- The Admin UI no longer renders a token-bearing Cron URL. The token-free endpoint is shown with `X-SEC` guidance; legacy query-token execution remains supported for compatibility.
- `/cron/status` now redacts the legacy token-bearing `cronUrl` even though that status endpoint is already authenticated with the separate Connection security credential.
- Site Health was aligned with the new scheduled-result provenance and treats `partial` scheduled Runner results as unhealthy evidence.
- Historical `mobo_core_real_cron_last_success_at` values from pre-UX17 semantics are fail-closed until a real scheduler result is observed. The first corrected partial/error scheduler result clears legacy green evidence; only a corrected scheduled `status=ok` establishes healthy scheduled history.
- The first-level Cron summary now uses operator-facing wording for scheduler arrival and queue-processing health; raw Runner/dispatch/backoff terminology remains in technical diagnostics.
- `docs/internal/admin-ux-test-backlog.md` now contains the permanent UX17 Cron/Worker regression contracts for later Test Suite implementation.

## Admin UX — Order Submission / Checkout Validation (UX18)

- The primary Order/Checkout summary no longer uses the retired `checkoutActive` address-field ownership flag. Local readiness is based on the actual central Location Resolver, Connection/provider, Mobo credentials, sender identity, and shipping-mapping contract.
- Current order policy is explicit and fail-closed: only `processing` orders that are entirely Mobo-owned and belong to exactly one Connection may be submitted. Mixed, non-Mobo and multi-Connection orders never partially submit Mobo lines.
- `Mobo_Core_Remote_Shipping_Methods::resolve_shipping_id_for_order()` now independently rejects mixed orders as a defense-in-depth guard.
- Automatic-order readiness requires an enabled/provisioned runtime Connection, multi-Connection ownership readiness when applicable, a compiled Provider using deterministic replace-cart synchronization, Mobo username/password, sender name/mobile, a current Mobo shipping catalog, complete mappings for every active WooCommerce shipping method, and no mappings to retired shipping IDs.
- Invalid automatic-order configuration is fail-closed on Save: other editable settings remain available for correction, but automatic submission and auto-complete are disabled instead of leaving an invalid live purchase path after an error notice.
- External Checkout Validation, which remained supported by runtime/save code, is visible again in Admin with URL, timeout and explicit fail-open/fail-closed behavior. The UI discloses cart-payload sharing and the origin-bound rule that prevents Portal Token/X-SEC from following arbitrary third-party URLs.
- Historical `lastResult`/`lastSuccessAt` data is labelled specifically as Mobo shared-cart validation history rather than generic Checkout health, and the hard-coded “Health endpoint ready” claim was removed.
- Local “configuration ready” explicitly does not claim live Source/network/login/payment success; those remain Release-Gate runtime proofs.
- Address/City documentation now reflects the current central Resolver architecture. Persian WooCommerce city assets remain compatibility helpers, not an order-submission prerequisite.
- Current README, shipping guide, privacy/terms and historical release notes were aligned/annotated so the retired mixed-order partial-submission policy is not mistaken for current behavior.
- Checkout dependency JavaScript now reads the Design-System boolean radio controls by `name`/checked state instead of looking for retired direct IDs, so dependent groups remain visible/reactive according to the actual saved toggle state.
- `docs/internal/admin-ux-test-backlog.md` now contains 32 permanent UX18 Order/Checkout regression contracts for later Test Suite implementation.

## Admin UX — SMS / Wallet Alerts (UX19)

- The SMS page now makes setting ownership explicit: order-SMS configuration is store-wide/global, while wallet-alert configuration, history, tests and one-shot state belong to the selected Connection.
- Primary readiness no longer turns green from a master toggle alone. It accounts for the local Persian WooCommerce SMS API, enabled scenario completeness, wallet Provider capability, recipient/template readiness and one-shot state.
- Wallet threshold `0` is treated as a valid threshold rather than “not configured”. Simple on/off controls use the Admin Design System radio contract instead of the searchable picker.
- Order-SMS copy now matches runtime timing: notifications run when Checkout creates/processes the order and do not wait for successful payment. Failed/uncertain sends have no independent retry queue; evidence is recorded in the WooCommerce Order Note.
- Order SMS now persists a per-order/per-scenario replay fence before calling the SMS gateway. If the fence cannot be proven, no SMS is sent. Gateway failure/ambiguity or a post-send sent-marker commit failure remains fail-closed against automatic replay.
- Wallet monitoring is capability-aware. A Provider without a `wallet_balance` endpoint is skipped without creating a persistent operational Wallet error.
- Wallet readiness also requires the selected/runtime Connection to be enabled, provisioned and multi-Connection ownership-ready; Provider capability alone is not shown as operational readiness.
- Wallet administrator transport-test state is separated from live wallet-alert state, so a manual test can no longer create/clear Dashboard operational errors or consume/re-arm the one-shot alert.
- Wallet one-shot delivery now uses a durable pre-send replay fence. Ambiguous transport results or inability to verify the final notified state block automatic resend until explicit administrator re-arm. Pre-dispatch configuration failures clear the fence safely.
- Re-arm is Connection-scoped, available from the SMS page, read-back verified before success is reported, and explicitly acknowledges that a new send is being permitted after a prior sent/uncertain state.
- A stale UX18 dependency was removed: enabling automatic Mobo order submission no longer force-enables the retired local address-mapping option; central Location Resolution remains authoritative.
- SMS/Wallet Save now verifies authoritative read-back across mixed global and Connection-scoped settings and reports configuration warnings instead of unconditional success.
- Dashboard Wallet health suppresses unsupported-Provider and disabled-feature historical errors from the active issue state while retaining live diagnostics.
- Current README/readme documentation was aligned with global-vs-Connection ownership, Checkout-time Order SMS, one-shot Wallet behavior, replay fencing, test/live separation, and the distinction between gateway acceptance and final SMS delivery.
- `docs/internal/admin-ux-test-backlog.md` contains the permanent UX19 SMS/Wallet regression contracts for later Test Suite implementation.

## Admin UX — Speed & Queues (UX20)

- The Speed & Queues primary view now reports real Connection-scoped Webhook/Image/Parent-Finalize workload and failure evidence instead of presenting configured batch sizes as operational health.
- Unavailable queue storage/query state is distinct from a factual zero backlog. Image Queue summaries now expose availability/read-failure evidence while preserving existing numeric summary fields for compatibility.
- Webhook status distinguishes actionable failures from historical retained failures and reflects the table-backed Sync Event Store plus legacy JSON fallback rather than treating file count as the complete queue.
- `mobo_core_sync_time_budget_seconds` is labelled according to current runtime ownership as the Webhook queue processing budget, not a total Product Sync deadline.
- Image processing capacity now has an effective minimum of one across Admin Save, generic settings, Image Sync, Cron Runner, Adaptive Tuner and Admin polling. Legacy stored zero values can no longer suppress queue processing or permit false image convergence.
- Disabling the independent image queue is documented as direct/synchronous image processing, with the resulting Sync-latency risk; it is not presented as disabling images.
- The retired image-blocking control and legacy Variant-parent timeout are no longer exposed as active Speed controls. Current Product/Missing-Image paths remain explicitly non-blocking, and waiting parent desired-state evidence is not retired by wall-clock age alone.
- Hardware presets now change throughput baselines only. They no longer modify Cursor, queue enablement, retry/retention, Missing Variant policy or other correctness/lifecycle semantics.
- Adaptive Execution is surfaced as an effective runtime layer above administrator baselines, with detailed effective limits kept in technical diagnostics.
- Webhook retention copy now states that changing the configured retention affects newly created event expiry and does not rewrite expiry already persisted on existing events.
- Queue settings are saved through bounded, authoritative read-back verification; generic success is not shown after an unverifiable write, and protected Sync/Repair windows remain server-side locked.
- `docs/internal/admin-ux-test-backlog.md` contains the permanent UX20 Speed & Queues regression contracts for later Test Suite implementation.


## Database Charset / Full-Unicode release debt

- A cross-system database requirement is now recorded for the final release: MoboCore requires a full-Unicode/4-byte UTF-8 storage path, with `utf8mb4` as the current concrete minimum. `utf8mb3`/legacy 3-byte `utf8` is unsupported.
- `docs/internal/database-charset-policy.md` records the required detection layers (WordPress config, live DB connection, database default, Mobo-owned schema, required WordPress/WooCommerce destinations, and 4-byte round-trip proof) and the proposed conversion architecture.
- The preferred future remediation is an explicit, locked, checkpointed **Database Unicode Upgrade** maintenance operation rather than silent whole-database `ALTER TABLE` work during activation/page load/update.
- `docs/internal/admin-ux-test-backlog.md` now includes permanent `UX-DBCHAR-*` regression debt for utf8mb3 failure, utf8mb4 readiness, mixed-schema detection, conversion safety and 4-byte round-trip validation.
- This documentation/backlog addition intentionally does **not** change runtime charset enforcement or perform conversion yet; implementation must be completed before final release readiness can be claimed.

## Admin UX — Purchase & Activation (UX21)

- Purchase & Activation now reads credential **presence from the selected durable Connection row** for administrative setup status, so a Token/X-SEC stored on a Pending/disabled Connection is no longer falsely shown as missing. Runtime credential helpers remain fail-closed for disabled owners.
- The setup summary now distinguishes Pending, Active and Provisioned-but-Disabled Connection states instead of allowing provisioning completion to imply runtime activation.
- Source/Provider setup tiles turn green only with the complete `is_provisioned()` contract; partial provisioning residue does not become false-ready status.
- The generic «توکن API» label was replaced with Connection-scoped «توکن اتصال» terminology.
- X-SEC wording now distinguishes local format validity from live Webhook verification; authoritative Webhook and License status remains on Connection & License.
- The static «service scope» success tile was replaced with actual Connection runtime state, preventing a non-operational informational fact from appearing as setup success.
- The final setup step no longer tells administrators to complete legacy/manual Address Mapping. Central Location Resolver remains authoritative; automatic-order setup focuses on sender details and shipping-method mapping.
- Provisioning copy now reflects the real token-first signed server flow and no longer claims arbitrary feature toggles are required before provisioning. Runtime exchanges remain dependent on an active Connection and the operation being used.
- The previously approved Purchase visual/CSS layout was retained; UX21 is a content/data-lifecycle correction only.
- `docs/internal/admin-ux-test-backlog.md` now contains 18 permanent UX21 Purchase & Activation regression contracts for later Test Suite implementation.

## Admin UX — WooCommerce Products list integration (UX22)

- The WooCommerce Products-list Mobo/Non-Mobo filter is explicitly installation-wide across all durable Connections. Terminal Custom Storage remains authoritative after the identity fence.
- If terminal identity storage/schema is unavailable, the filter is disabled and the list remains unclassified instead of falling back to retired postmeta and producing false Mobo/non-Mobo results.
- Product-row Mobo badges, exclusion state, pricing state and row actions now resolve from the product's installation-global durable owner rather than inheriting the request/default Connection.
- Non-default Connection products can use the per-product pricing action correctly. The AJAX handler derives the owner server-side and executes the request inside that Connection; it no longer trusts a browser Connection selector for ownership.
- Excluded products remain visibly Mobo, are marked «مستثنا از همگام‌سازی», and expose no active pricing mutation. The list-side exclusion probe is read-only and does not create evidence merely by rendering wp-admin.
- Products whose owner Connection is Pending/disabled/unprovisioned/topology-unready remain visible as Mobo but show «اتصال آماده نیست» and no active pricing mutation.
- The pricing modal identifies the owning Connection and shows that Connection's actual global fixed/percentage/dynamic policy summary, preserving the variation-specific-profit-first precedence contract.
- Modal keyboard/status behavior now supports Escape, focus return, and an `aria-live` result region while keeping integration styles/classes Mobo-namespaced on the Woo Products surface.
- `docs/internal/admin-ux-test-backlog.md` now contains 20 permanent UX22 Products-list regression contracts for later Test Suite implementation.

## Admin UX — WooCommerce Orders admin integration (UX23)

- WooCommerce Order List (legacy and HPOS) and Order Detail now share one authoritative Mobo submission-state resolver. Mixed, multi-Connection, invalid-identity, queued, running, uncertain, failed, aborted and submitted states no longer produce contradictory labels/actions.
- A remotely submitted order carrying `_mobo_order_requires_review` or post-payment divergence is no longer shown as plain green success; it is surfaced as «ثبت شد؛ نیازمند بررسی» and Retry remains unavailable.
- Non-Mobo, mixed, multi-Connection, identity-unknown and non-`processing` orders no longer expose a misleading manual Mobo submission action in the Meta Box.
- Manual Order Admin submission is additionally gated by the durable owner Connection being present, enabled, provisioned and multi-Connection-runtime-ready. The admin POST handler independently rechecks this readiness before remote work.
- Uncertain/stale attempts retain the explicit verified-absence workflow: administrators must confirm in Mobo that no order/payment exists before a manual retry can clear the uncertainty guard.
- Retry and Clear Log were migrated from side-effecting GET links to nonce-protected POST forms; both handlers reject non-POST requests with HTTP 405 while preserving the existing capability/lifecycle checks.
- The Order Detail first level now shows operator status, meaning and required action only. Mobo Order ID, masked order token, raw status/error code, HTTP/log step and JSON support log are contained in collapsed technical details.
- Technical log empty state and copy were clarified. Sensitive known fields remain masked, non-JSON bodies remain omitted/raw-hash-only, and clearing the log explicitly leaves submission/payment/review safety state intact.
- `docs/internal/admin-ux-test-backlog.md` now contains 26 permanent UX23 WooCommerce Orders admin regression contracts for later Test Suite implementation.


## Admin UX — WooCommerce Product Edit / Variation pricing integration (UX24)

- The per-Variation `mobo_additional_price` control now appears only on variations whose Mobo ownership is proven by the authoritative storage model; manual/non-Mobo child variations no longer receive a misleading Mobo pricing field.
- Terminal Custom Storage is authoritative for variation and parent ownership. A missing/unavailable terminal authority fails closed instead of falling back to retired identity postmeta.
- Multi-Connection Product Edit is owner-aware: the durable Variation owner must agree with its durable parent Product owner, independent of the ambient/default Admin Connection.
- Variation override saving now supports WooCommerce's real `save-variations` AJAX nonce path as well as the normal product-form nonce. This fixes a path where the field could render but fail to persist during AJAX variation saves.
- Missing/partial field payloads no longer delete an existing override. Clearing occurs only when the field is explicitly submitted empty or zero.
- Negative, decimal, malformed and oversized values are rejected rather than being silently coerced by `absint`; verified prior state remains intact and WooCommerce Admin receives an error.
- Set and clear operations use authoritative read-back verification. Targeted cache invalidation is queued only after a verified state change.
- Field copy now states the full pricing precedence contract (Variation fixed profit > Product/Parent percentage override > global policy), the current zero/blank fallback semantics, and the fact that saving the policy does not immediately reprice the current Woo variation.
- Excluded and runtime-unready owners retain local configuration without falsely implying immediate price mutation.
- `docs/internal/admin-ux-test-backlog.md` now contains 24 permanent UX24 Variation Pricing regression contracts for later Test Suite implementation.


## Admin UX — Global WordPress Admin notices / toolbar (UX25)

- The global city-asset warning is now multi-Connection aware: it evaluates every enabled, provisioned Connection with automatic Mobo order submission enabled instead of inspecting only the ambient/default Connection.
- Disabled/Pending Connections no longer create false active city-file warnings, and the secondary warning is suppressed while multi-Connection ownership topology itself is unavailable.
- Several failing city assets are aggregated into one bounded notice. The remediation link preserves the failing `mobo_connection_id` and opens the Order/Checkout diagnostics for that owner.
- Raw city generator/filesystem `lastError` details are no longer echoed on arbitrary wp-admin pages; the global surface contains only the operator-facing problem and action.
- The city-file warning now requires `manage_options`, matching the capability of the Mobo remediation page rather than showing Shop Managers an action they cannot open.
- Sync/Repair queue-setting lock notices now record and display the effective Connection label, preserving the existing user-scoped/one-shot behavior while making multi-Connection blocking understandable.
- Dependency notices now distinguish «installed but inactive» from «not installed» and recognize network plugin-management capability on Multisite.
- The existing Mobo Admin Bar capability and Connection-context behavior were audited and retained; no additional global toolbar state or credential data is exposed.
- `docs/internal/admin-ux-test-backlog.md` now contains 18 permanent UX25 Global Admin regression contracts for later Test Suite implementation.

## Admin UX — WordPress Plugins screen action links (UX26)

- The Mobo Core plugin-row actions are now state-aware instead of always showing two installation links for dependencies that may already be healthy.
- Site-admin Settings/Health links are shown only when PHP/platform requirements and required plugins are ready and the current user has `manage_options`; if bootstrap is dependency-blocked, links to unregistered Mobo pages are withheld.
- Missing dependencies now distinguish «not installed» from «installed but inactive»: installable missing plugins open the installer search, while installed/inactive dependencies route to Plugins management.
- Users without `install_plugins` are not sent to inaccessible installer screens; remediation falls back to plugin management.
- A PHP 64-bit platform blocker is surfaced directly on the plugin row without pretending Mobo Settings are available.
- The same state-aware callback is registered for WordPress' network-specific plugin action-link hook. Network Admin does not expose site-only Mobo Settings/Health destinations, while dependency remediation follows `self_admin_url()` and network plugin-management capability.
- Rendering the row never automatically installs/activates dependencies and preserves WordPress core plugin actions.
- `docs/internal/admin-ux-test-backlog.md` now contains 16 permanent UX26 Plugins-screen regression contracts for later Test Suite implementation.

## Admin UX — WooCommerce Shipping Zones / retired Mobo shipping method (UX27)

- The current mapping-only shipping policy is now enforced as a runtime invariant: `mobo_core_shipping` is removed from WooCommerce's shipping-method registry even if stale compatibility code attempts to register it.
- The retained `register_shipping_method()` method is compatibility-only and can no longer re-enable the retired custom rate.
- The legacy `WC_Mobo_Core_Shipping_Method` class is explicitly retired: it exposes no editable instance-settings support, registers no save hook, and its `calculate_shipping()` path is an unconditional no-op.
- Legacy copy now identifies the method as retired instead of claiming the instance is still automatically maintained for customer checkout.
- The mapping-only policy checkpoint is no longer treated as permanent proof of zone state in wp-admin. Admin requests re-prove that no legacy Mobo method was re-enabled; storefront requests avoid the extra residue query because the registry-level fail-closed filter already prevents checkout use.
- Cleanup discovers every WooCommerce zone row using the plugin-owned `mobo_core_shipping` method id, including orphan/untracked residues absent from historical managed-option maps, and verifies both zone-table and instance-option disabled state.
- Merchant-owned shipping methods remain out of scope. Legacy Flat Rate fallbacks/mirrors are still modified only when historical Mobo ownership maps identify those exact instances, and legacy-zone deletion remains conservative.
- `docs/internal/admin-ux-test-backlog.md` now contains 20 permanent UX27 Shipping Zones / mapping-only regression contracts for later Test Suite implementation.

## Admin/Order surface — WooCommerce line-item privacy and Revenue Ledger isolation (UX28)

- Internal immutable order-item identity metadata now uses one shared hidden-key contract. The previously omitted `_mobo_identity_connection_id` is hidden from WooCommerce order-item UI and formatted metadata consumers alongside the other Mobo identity/revenue fields.
- Revenue source-cost capture now prefers immutable line-item ownership captured earlier in checkout and uses installation-global Custom Storage ownership for the limited live fallback, avoiding Default-Connection classification on non-default Source orders.
- New Revenue Ledger records are fail-closed to the same current order invariant as submission: every line item must be Mobo-owned and all Mobo lines must belong to exactly one Connection. Mixed, multi-Connection, unknown-identity and owner-mismatch records are rejected.
- New immutable ledger records persist and read-back verify the owning `connectionId` both inside the ledger payload and as private WooCommerce order meta.
- `/portal/revenue-summary` is now scoped to the Connection authenticated by X-SEC/WebHookId. Durable Connection installations refuse a revenue request when no authenticated Connection scope exists rather than exposing installation-global financial data.
- Revenue summary caching is scope-aware. Connection-specific summaries cannot be reused across Sources, while the historical installation-wide helper remains explicit for internal/backward-compatible callers.
- Legacy ledger records without a stored Connection id are included in a Connection-scoped summary only when immutable order/line metadata can prove their owner; unresolved legacy financial records are never guessed from the live catalogue/default Connection.
- `docs/internal/admin-ux-test-backlog.md` now contains 24 permanent UX28 Revenue/Order-item regression contracts for later Test Suite implementation.

## Checkout / Cart frontend ownership and validation hardening (UX29)

- Checkout Mobo ownership now follows terminal Custom Storage ownership. Legacy `mobo_url`, GUID and Portal-id compatibility metadata can no longer independently resurrect Mobo ownership for cart or newly captured order-line identity.
- Immutable order-line capture and legacy live fallback now use the same owner-first contract: identity values are meaningful only after the owning Connection is proven.
- Multi-Connection carts no longer bypass all configured pre-purchase validation. Each Source-owned subset is validated independently inside its own Connection context, while final automatic order submission remains fail-closed for multi-Connection orders.
- Connection-scoped Mobo cart and External Validation payloads are partitioned by owner so one Source never receives another Source's Mobo cart rows.
- Built-in checkout validation errors can no longer be erased by `mobo_core_checkout_validation_errors`; third-party filters may add stricter diagnostics/errors but cannot remove core failures, regardless of Auto Order state.
- Permanent UX29 regression contracts were added to `docs/internal/admin-ux-test-backlog.md` for Classic Checkout, Checkout Block/Store API, multi-Connection partitioning, terminal-storage failure injection and filter-bypass prevention.

## REST runtime authentication and exception-disclosure hardening (UX30)

- Runtime REST routes protected by `check_security()` now require the durable Connection repository/table. If that authority is unavailable, authentication fails closed with HTTP 503 instead of accepting the retired installation-wide legacy X-SEC option.
- The separately signed `/connection/provision` flow remains independent and unchanged; provisioning does not require the runtime X-SEC fallback.
- Heartbeat and webhook-queue exception responses no longer expose PHP exception class, raw exception message, filesystem path or source line over REST.
- Full exception evidence is retained only in local server diagnostics/logging; persisted webhook-queue exception state is operator-safe for later Admin/health rendering.
- `docs/internal/admin-ux-test-backlog.md` now contains 14 permanent UX30 REST authentication/disclosure regression contracts.

## Installation-global REST authority isolation (UX31)

- Installation-wide REST surfaces now have an explicit authority boundary. The enabled Default Connection acts as the Installation Controller for `/health`, `/heartbeat`, `/health/report-now`, `/cron/status`, `/worker/status`, `/upgrade/status` and `/upgrade/apply`.
- A valid non-default Source credential is still valid for that Source's webhook/sync/Portal-operation/settings/revenue routes, but it can no longer inspect installation-wide health/worker state, run the shared Heartbeat or control plugin-wide remote upgrades.
- Installation authorization is derived only after exact Connection X-SEC/WebHookId authentication; no request parameter can self-designate the controller.
- If the enabled Default Connection cannot be resolved, installation-global routes fail closed with service-unavailable rather than silently promoting another Source.
- Local `/cron/run` and `/worker/run` keep their independent Cron/Worker-token boundary for cPanel/self-runner execution.
- README/FUNCTIONS/Heartbeat/Remote Deployment documentation now distinguishes Installation Controller credentials from ordinary Source-scoped credentials.
- `docs/internal/admin-ux-test-backlog.md` now contains 18 permanent UX31 installation-global REST authority contracts.
