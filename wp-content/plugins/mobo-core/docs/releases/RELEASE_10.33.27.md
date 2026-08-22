# Mobo Core 10.33.27

## Ten-axis integrity audit: financial idempotency, durable WooCommerce writes, and trust boundaries

10.33.27 is a deeper system-wide failure audit of Mobo Core. The review was split across database atomicity, queue ownership, retry/idempotency, destructive synchronization, order/payment safety, remote trust boundaries, cron/upgrade races, WooCommerce/HPOS persistence, runtime cleanup, and hidden false-success paths.

The release tightens one rule across the plugin: an operation is not considered successful merely because a setter or remote request returned. Critical WooCommerce/database state must be durable and observable after the write, and irreversible remote operations must have a local idempotency boundary before they begin.

## 1. WooCommerce order submission has a per-order lease

The shared Mobo cart lock serializes the one remote cart, but by itself it did not stop two stale workers from submitting the same WooCommerce order sequentially. Every Mobo order submission now owns an independent per-Woo-order lease in addition to the shared-cart lease.

After acquiring that lease, the order is reloaded from durable storage and its submission generation is checked again. A stale caller cannot become a second purchase attempt after another worker has already changed the order state.

The per-order lease is renewed together with the shared-cart lease during remote calls.

## 2. The pre-payment attempt checkpoint is durable before any remote side effect

Before login/cart/checkout/payment begins, `_mobo_order_submit_attempted`, `_mobo_order_submit_attempted_at`, and the `running` status are saved and then reloaded from WooCommerce.

If that checkpoint cannot be proven durable, no Mobo cart or payment request starts. This removes the database-failure window where a remote purchase could occur without local evidence that an attempt was already in flight.

## 3. Wallet payment success/failure is classified fail-closed

After `/cart/payment/wallet`, automatic retry is allowed only when Mobo explicitly proves the payment did not happen. Transport errors, unreadable 2xx responses, contradictory/partial acknowledgements, or a paid response without a valid Mobo order ID become `uncertain` rather than retryable `failed`.

A successful local commit also requires read-back verification of `_mobo_order_submitted=yes` and the exact Mobo order ID. If the remote order exists but WooCommerce cannot prove the success checkpoint was stored, the order remains `uncertain` and must not be automatically purchased again.

Admin retry of an uncertain order is blocked by default and requires the explicit operator path that confirms the remote order is absent.

Mixed Mobo/non-Mobo orders are not auto-completed after a successful manual retry; they remain `processing` for local fulfilment.

## 4. Shared Mobo authentication/session mutation is serialized

Login tests and wallet-balance checks use the same remote cookie jar as checkout. They now participate in the shared-cart/session lease instead of being able to re-authenticate and rewrite cookies underneath an active purchase.

Credential changes request a deferred cookie reset; the reset is executed only by a request that actually owns the shared Mobo session lock. Cookie-jar writes are verified by read-back.

Post-purchase wallet hooks run after the purchase releases the shared cart/session lock.

## 5. Configurable external validation no longer receives Portal credentials by default

A custom checkout validation URL may intentionally be a third-party service. `Token` and `X-SEC` are now attached only when the validation endpoint is the exact configured Portal API origin, including scheme, host, and effective port.

Third-party validation endpoints receive only the validation payload and never the main MoboCore Portal credentials.

## 6. Remote plugin deployment tightens package trust

With no explicit administrator package-host allowlist, a package URL must now use the exact Portal API origin rather than merely sharing the same hostname. Manual redirects remain same-origin and are revalidated before credentials are sent.

Staged plugin packages reject symbolic links completely. Upgrade lease loss immediately before filesystem replacement is recorded as an explicit failed deployment instead of leaving ambiguous progress state.

## 7. Public image downloads no longer weaken WordPress SSRF protection

Normal image sideloading no longer dynamically whitelists the source host through `http_request_host_is_external`. Public image URLs use WordPress safe HTTP validation as-is.

Unsafe localhost/private image fetching remains available only through the existing explicit developer opt-in.

## 8. Product Map index is compatible with older utf8mb4 key limits

The Product Map identity index previously combined `remote_guid varchar(191)` and `object_type varchar(32)`. Under utf8mb4 that composite can exceed the legacy 767-byte index limit.

The schema now defines:

`UNIQUE KEY remote_object (remote_guid(150), object_type)`

The complete remote GUID is still stored and compared by runtime lookups. The prefix is used only to keep the identity index within old MySQL/MariaDB key-size limits for the GUID-like identifiers used by Mobo.

Migration readiness also verifies the critical Event Queue column and required Product Map indexes before advancing the DB-version checkpoint.

## 9. Product/variation/type writes verify WooCommerce postconditions

Product type changes between simple and variable now verify both taxonomy persistence and the reloaded WooCommerce product class.

Desired product attributes verify `WC_Product::save()` and then compare the reloaded attribute state with the authoritative payload.

Simple-variant mapping verifies the persisted product ID, mapped flag, Portal Variant ID, incomplete marker, price/stock/title state, and the reloaded simple-product type before declaring convergence.

Variation identity migration keeps the old reverse mapping until the new identity has been durably saved and mapped.

## 10. Image linkage cannot report `done` after a failed product save

Featured/gallery synchronization now checks `WC_Product::save()` and reloads the product to verify the exact featured image and gallery IDs before queue rows are marked done.

Image Refresh also verifies the replacement linkage before recording refresh completion or considering deletion of the old attachment. A prepared replacement whose WooCommerce linkage did not persist remains retryable.

## 11. Revenue Ledger durability matches its immutable financial role

Missing source-cost snapshots now verify the order-item meta after each item save. An item whose cost snapshot cannot be observed in durable order-item metadata remains missing rather than being counted as snapshotted.

The final immutable revenue record verifies the WooCommerce order save and reloads the ledger version, calculated timestamp, and ledger payload before invalidating summary cache or firing the `mobo_core_revenue_ledger_recorded` action.

## 12. Shipping-policy retirement retries until it is actually applied

The one-time mapping-only shipping cleanup no longer stamps its policy version merely because disable operations were attempted. Legacy shipping-method options and zone rows are read back, failures are reported, and the policy checkpoint is written only after the old runtime methods are verifiably disabled/absent.

A failed cleanup therefore retries on a later request instead of silently leaving an old checkout shipping method active forever.

## 13. Runtime queues/files retain durable failure semantics

Webhook file fallback writes remain atomic and never rewrite a known-good durable file directly after a failed rename. Retry/defer state is not reported as stored when the file update actually failed.

Order-queue option persistence is read back, with an independent recovery lane for sites using real cron or `DISABLE_WP_CRON`.

Runtime uninstall cleanup removes stale order/recovery queues, shared Mobo session cookies, and debug/runtime state while preserving business configuration and customer data.

## 14. Additional post-write checks

Reprice verifies WooCommerce prices and bookkeeping metadata after persistence. City asset generation verifies its durable ready/hash checkpoint. Duplicate-product quarantine verifies that the post status actually became `draft` before marking quarantine successful.

These are intentionally narrow postcondition checks: best-effort diagnostics/cleanup metadata that cannot cause state-machine advancement remain best-effort rather than adding unnecessary write failures.

## Database / upgrade behavior

10.33.27 does not delete customer data. `dbDelta()` may repair the Product Map index definition on installations that need it. Existing valid modern indexes remain usable.

The migration checkpoint advances only after required tables, the Event Queue `claim_token`, and required Product Map indexes are observable.

## Known runtime boundary

This release hardens static/state-machine failure paths, but exactly-once behavior for external systems cannot be proven from PHP alone when the external provider offers no idempotency key. For example, an SMS provider may accept a message and then lose the HTTP response before MoboCore stores the sent marker. Provider-side idempotency is required to make that class of event mathematically exactly-once.
