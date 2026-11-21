# Mobo Core 10.34.34 — PERF03 + PERF04 (source-only performance candidate)

Date: 2026-09-30. Cumulative branch base: `10.34.32`, which already includes PERF01/PERF02 from frozen `10.34.30`. Intermediate `10.34.33` (PERF03) was an internal checkpoint; the shipped candidate `10.34.34` includes both tranches. **Do not install this ZIP on the WAMP sites that are running r8.3.179 Plugin Gate for 10.34.30.** The historical Suite is not changed/re-pinned here.

## PERF03 — narrow, verified metadata grouping

- Confirmed before: each of the 2–3 contiguous `sync_price_source_meta_fast()` fields performed its own pre-read, write-if-changed, explicit `post_meta` cache invalidation and read-back, including on an all-no-op source payload.
- New `Mobo_Core_Durable_State_Policy::update_post_meta_verified_batch()` validates the whole key set, retains the WordPress scalar normalization and writes changed members only. It performs **one final explicit `wp_cache_delete(post_meta)`**, reads back **each** key from the refreshed metadata boundary and returns true only if all desired values match.
- Applied only to the adjacent `mobo_api_price`, optional `mobo_api_compare_price` and `mobo_price_policy_type` fields after determining the fast-path policy. `mobo_price_policy_updated_at` remains individually verified after the group when a change occurred. Existing pre-batch change detection is retained.
- No crash/ack marker, product source hash, ordering watermark, Wallet, order status or non-contiguous Woo side effect has been grouped. A partially applied group returns failure and remains retryable; this is **not a cross-key atomic transaction**.
- Evidence: no-op batch avoids repeated explicit invalidations; stale-cache apparent no-op, canonical values, partial failed write and sanitizer collision were injected into PHP stubs. Exact real WP object cache/filter behavior and impact remain pending.

## PERF04 — bounded, fail-closed Order Recovery page

- Confirmed before: `get_order_queue_recovery_items($limit)` loaded the entire Connection-owned `order_recovery` payload map then `array_slice()`-ed only up to 10 entries. Invalid row cleanup and post-processing marker cleanup used an unconditional key delete.
- New `Mobo_Core_Work_Queue_Store::first_rows_checked(type, limit)` uses `(connection_id,queue_type) ORDER BY id ASC LIMIT N`. This preserves the old first-page ordering and validates dedupe hash, full key, payload SHA-256 and array decoding for the **entire selected page** before any item is returned. SQL/corruption errors are explicit, not an empty success page. It intentionally does not scan off-page rows.
- `delete_key_if_hash()` requires owner, queue, full dedupe hash/key and expected payload SHA-256, and will not delete an active claim. A recovery cleanup from an older snapshot cannot delete a different re-enqueued payload. A theoretically byte-identical ABA generation still needs real cross-process proof or a future monotone row revision.
- Checkout aborts order processing with `queue-read-failed` and `remaining=true` if the primary map or the bounded recovery page cannot be safely read. Existing legacy Default-only fallback is retained. Woo order queue processing, remote Cart/Wallet code and auto-retry policy are NOT modified.
- No DDL, column/index change, owner fallback, destructive data migration or automatic healing of corrupt rows. Normal `Order Submission` still uses a full map in some paths, and `Parent_Finalize_Queue::process_batch()/status` retain their historical map-wide read pattern; see next-scope backlog.

## Source/isolated evidence and exclusions

- PERF03 isolated fixture: 23 assertions; PERF04 recovery fixture: 24 assertions. Four negative source mutations each triggered a fixture failure. PERF01/PERF02 prior fixtures and UX64–UX67 isolation regressions replayed on this cumulative source.
- No actual product creation, WooCommerce/Portal execution, two-process MySQL race, EXPLAIN or measured speedup was attempted. PHP source syntax checked with local PHP 8.4; a dedicated PHP 7.4 runtime still needs a compatible runner.
- Remaining design work: `Parent_Finalize_Queue` exact `queuedAt` ordering and claim fencing without map-wide replacement; bounded primary order submission listing; metadata terminal-retirement filter behavior in live WordPress; SQL index/execution-plan proof.
