# Mobo Core 10.33.44.12 — persistence, ordering and crash-consistency hardening

## Scope

This release hardens the state that MoboCore persists between Portal/Webhook input and WooCommerce. It is intentionally focused on correctness under retry, partial payloads, process death, stale snapshots and lease expiry; unrelated checkout, order, shipping and cache behavior is not redesigned.

## Ordering and stale-state protection

- `event_version` survives event-store bulk claim/hydration and reaches Product/Variant processors.
- Product and Variation seen/applied revision and event-version watermarks are persisted and verified.
- Stale ProductUpdated and UpdateVariant events are skipped before they can overwrite newer state.
- Authoritative Variant snapshots persist ordering watermarks on each successfully applied child, not only on the parent.
- Fully stale Variant deltas are rejected before parent topology can be mutated.
- Foreground webhook wall-clock fences are verified before the event may be acknowledged.
- Manual Full Sync and Repair page snapshots both carry a local capture fence; if a newer foreground webhook lands after the page fetch, the exact product is refreshed or the queued snapshot is deferred.

## Fail-closed completion

- Product Map writes/read-back are part of the completion commit.
- `mobo_sync_incomplete` remains set until post-save side effects and map state are durably committed.
- `_mobo_parent_sync_pending` is verified after Variant mutations; failure leaves the event retryable.
- Incomplete authoritative Variant snapshots remain pending and are no longer inferred solely through best-effort diagnostic meta.
- ProductUpdated with a valid identity but failed WooCommerce upsert is retryable instead of being acknowledged as an invalid/skipped product.
- Sync Health transitions are monotonic by revision/version and verified from the health table.

## Partial vs explicit-empty desired state

- Omitted fields preserve existing state for partial payloads.
- Explicit `images: []` clears image queue desired rows and verifies WooCommerce featured/gallery linkage is empty.
- Explicit `productCategories: []` is treated as authoritative empty when automatic category sync is enabled and clears WooCommerce product categories with taxonomy read-back verification.
- Malformed or partially valid images/attributes/categories fail closed instead of normalizing to a destructive subset.
- Partial Product/Variation payloads no longer replace the last complete canonical source hash.

## Image worker ownership

- Image Queue and Image Refresh Queue now return an exact persisted lease token for each claim.
- In-flight attempt/failure/attach/done/identity checks require the same lease token.
- A worker whose lease expired cannot commit after another worker reclaimed the same row, even when GUID and source URL are unchanged.
- Attachment identity/completion meta are read back before image queue completion.
- Async image desired hashes use a pending watermark and are promoted only after the desired queue/linkage converges.

## Data-integrity corrections

- `publishedAt` remains source publication/created-date evidence and no longer writes WooCommerce `date_modified`.
- Product Map `last_hash` is populated from the real canonical source hash; `sync_incomplete` is reconciled with the postmeta crash marker.
- Product Ledger uses the full `product_guid` for its unique key instead of the first 150 characters.
- Migration `10.33.44.12` backfills map hashes/incomplete flags and removes impossible stale variation-map rows that point at product posts.

## Tests

The accompanying Deep Test Suite `10.33.44.12-r6.8` adds regression contracts and guarded local mutation tests for stale revisions/versions, monotonic health, explicit-empty images/categories, partial source-hash preservation, authoritative Variant completeness, parent-finalize persistence, Manual-vs-Webhook snapshot ordering and image lease reclaim ownership.

Runtime mutation tests remain opt-in and are restricted to local/disposable WordPress sites.
