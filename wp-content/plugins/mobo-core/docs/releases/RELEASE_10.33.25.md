# Mobo Core 10.33.25

## System-wide failure and concurrency audit

10.33.25 extends the failure-oriented audit beyond the image subsystem. The reviewed path includes webhook ingestion and claims, Portal payload pulls, product/variation desired-state convergence, adaptive reconciliation, manual Sync/Repair, maintenance queues, checkout/order submission, payment ambiguity, shipping snapshots, SMS delivery, remote package downloads, runtime locks, and operational diagnostics.

The guiding rule for this release is that destructive or irreversible state must be committed only when the plugin can prove ownership and authoritative input. HTTP 200, an old queue snapshot, or an expired lease alone is not sufficient evidence.

## 1. Webhook/Event Queue uses claim ownership

A processing row now carries a random `claim_token`. Completion, progress, retry and failure transitions from a worker are compare-and-set updates that require the same token and `processing` status.

This prevents a slow worker whose lease expired from overwriting a row already reclaimed by a newer worker. Bulk release is likewise token-scoped.

Maintenance that retires old `UpdateVariant` rows waiting for a parent now updates only rows that are still `pending`; a row claimed between the maintenance read and write is left to its active worker.

The event table receives one additive `claim_token varchar(64)` column and index through WordPress `dbDelta`.

## 2. Reconciliation is fail-closed before deletion

Deep catalog reconciliation no longer treats a malformed-but-successful API response as an empty catalog. Catalog pages must expose an explicit list and explicit pagination state, product identities must be valid, and cursor-based pages must make progress while `hasMore=true`.

The deep sweep phase is allowed only after a terminal catalog snapshot has been explicitly validated. A pre-10.33.25 installation found in an old unproven sweep is reset to a safe idle state by migration and will rebuild the catalog snapshot. The migration itself deletes no product.

Single-product lookup and variant snapshots use the same rule: missing/invalid `data` is an error; only an explicit valid empty result can represent authoritative absence.

Revision-feed processing also fails closed. Malformed changes, revision regression, or a non-empty page that does not advance the watermark no longer permits `currentRevision` to move forward and silently skip work.

Local deletion is committed in order: WordPress post deletion must succeed before the product/variation mapping is removed or the sweep cursor is advanced.

## 3. Variation replacement is transaction-safe

Attribute structure changes no longer pre-delete all existing WooCommerce variations before the replacement data has arrived. Existing children remain available until the replacement variant snapshot reaches a validated terminal state.

`finalize_missing_variants()` deletes only children absent from that complete seen set. If WordPress refuses even one stale-child deletion, finalization is not committed, seen-state is retained, and mapping cleanup is not run. The next safe retry continues from the preserved state.

Variable-to-simple conversion follows the same rule. A parent is not changed to `simple` while a stale variation still exists. Failed child deletion records an incomplete state and leaves the product type intact for retry.

Variation map rows are now deleted only after `wp_delete_post()` confirms physical post deletion.

## 4. Manual Sync/Repair has one state owner

Worker and Start locks are owned inside the product-sync service instead of being inconsistently applied by REST/Admin wrappers. REST, Admin, Cron and Portal Remote Control therefore share the same serialization rules.

Every run has a `syncId` generation. A stale worker cannot save a checkpoint over a newer generation, and cancellation of the same generation cannot be reversed by a previously loaded `running` snapshot.

Cron checkpoint coalescing re-reads the durable generation/cancel boundary after acquiring the worker lock and before any product mutation. This closes the gap where a long request could otherwise continue writing the old run after another request cancelled it or created a newer generation.

Reset refuses to remove state while the worker lock is active. Cancel no longer force-releases locks owned by another request.

## 5. Shared Mobo cart and order queue are concurrency-safe

The shared Mobo cart previously used a TTL option lock that was not renewed while several remote requests were executed. A second checkout could therefore acquire the same remote cart if the first checkout exceeded the TTL.

The cart now uses the central token-owned runtime lock and renews its lease around remote side effects.

The order-submission queue still remains lightweight in WordPress options, but mutation is serialized with a database advisory lock and each enqueue carries a unique queue token. A worker finishing an older snapshot can remove only the exact entry it claimed and cannot erase a newer concurrent enqueue for the same order.

## 6. Payment ambiguity is not auto-retried

Wallet Payment is an irreversible boundary. Once that request has actually been sent, a timeout, transport failure, 5xx response, or structurally ambiguous 2xx response cannot prove that the remote payment/order did not happen.

10.33.25 records such orders as `uncertain` / administrator-review-required instead of automatically retrying them. Only an explicit remote response proving `success=false` or `paid=false` is a definite failure eligible for the normal retry path. Explicit `success=true` and `paid=true` is the success path.

This favors a visible manual review over the higher-risk outcome of duplicating a paid Mobo order.

## 7. Outbound credentials stay on trusted origins

Absolute webhook `payloadUrl` values are now constrained to the configured API origin (scheme, host and effective port) and cannot contain user information. Redirects are followed manually; every hop is revalidated before `Token` or `X-SEC` is attached.

Remote plugin package downloads similarly disable automatic redirects. Each redirect must pass the secure package URL policy and remain on the initial origin before license/package credentials are sent.

This closes SSRF and cross-origin credential-forwarding paths created by otherwise valid HTTP redirects or untrusted absolute payload URLs.

## 8. Maintenance queues preserve failed work

Reprice and Recategorize advance their cursor only after an item reaches a terminal outcome. Product-lock contention or a transient exception leaves that item retryable instead of recording the error and permanently stepping past it.

Start, Cancel and Reset operations are serialized with the same worker lock so an old worker cannot save its final state over a freshly-started queue generation.

## 9. Shipping can authoritatively become empty

A valid Mobo response can mean that zero shipping methods are currently active. The previous snapshot logic rejected an empty normalized set and retained the old cache.

An explicit `methods: []` or `shippings: []` is now a valid authoritative empty snapshot. A response where the collection field is missing, or a non-empty collection whose rows have no usable identity, remains invalid and cannot erase the prior snapshot.

## 10. SMS and diagnostic credential hygiene

Order SMS dispatch is serialized per `order + scenario`. Simultaneous Classic Checkout and Blocks hooks cannot both pass the "not sent" meta check and send the same scenario twice.

The internal Self Runner no longer puts the cron credential in the `/worker/run` query string. It sends the credential through the `X-SEC` header, so normal access logs and the reported worker URL do not expose it.

Order-submission diagnostic logs redact token, authorization, security-code and related sensitive fields. The dedicated internal order token metadata required by the order integration is not removed.

## 11. Upgrade behavior

The schema change is additive: `wp_mobo_sync_events` receives `claim_token` plus an index through `dbDelta`.

The 10.33.25 migration removes only the obsolete shared-cart option lock and aborts an old deep-sweep state if that state predates proof of validated catalog completion. It does not delete products, variations, orders, images, or queue payloads.

## Residual boundaries

Exactly-once SMS delivery cannot be mathematically guaranteed when the external SMS provider itself does not expose a provider-side idempotency key: a provider may accept a message and then lose the HTTP response. The new lock prevents local concurrent double-sends, which is the part controlled by Mobo Core.

External cPanel/legacy cron callers may still use the existing query-token compatibility path. The built-in Self Runner no longer does so; header authentication is preferred for controlled callers.

The image subsystem is not substantially redesigned in this release because the storage/refresh failure audit was completed in 10.33.22–10.33.24.
