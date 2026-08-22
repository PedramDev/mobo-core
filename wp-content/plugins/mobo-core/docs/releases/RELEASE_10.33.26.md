# Mobo Core 10.33.26

## Durable state, authoritative snapshots, and partial-failure audit

10.33.26 is a second system-wide failure audit focused on the points where Mobo Core crosses a durability boundary: database writes, authoritative cache replacement, hierarchy construction, queue claim/commit, numeric source data, and upgrade schema checkpoints.

The central rule is that a state machine must not advance merely because an operation was attempted. A durable write must be observable after the write, destructive state must be backed by a complete authoritative snapshot, and a stale worker must never erase work queued by a newer mutation.

## 1. Database migrations verify their postconditions

`dbDelta()` is not treated as proof that a schema change succeeded. After table creation/update, the migration verifies the required Mobo tables and the critical Event Queue `claim_token` column before committing `mobo_core_db_version` / `mobo_core_schema_version`.

If a host temporarily rejects an ALTER because of permissions, metadata locks, or another database problem, the installed database version is left behind so a later request retries the migration. Health reports the plugin DB version, schema version, readiness state, and the last bounded migration error.

10.33.26 adds only an additive Product Map index:

`parent_object (parent_remote_guid, object_type)`

No customer data is deleted by this schema update.

## 2. Webhook ingestion and fallback are durable under concurrency/crash

Remote webhook dedupe now serializes the `remote_event_id` SELECT/INSERT boundary so simultaneous deliveries cannot both observe a miss and create duplicate active rows.

The JSON fallback queue, used when the database queue is unavailable, writes to a temporary file in the same directory and atomically renames it into place. An interrupted rewrite therefore does not truncate the previous durable fallback envelope.

## 3. Order submission queue has an independent recovery lane

The option-backed Mobo order queue now verifies persistence by reading the written snapshot back. A failed option write no longer reports enqueue success.

When the short queue mutex or durable option write fails, a per-order recovery marker is stored separately. Real Cron/Self Runner scans this bounded recovery lane, so `DISABLE_WP_CRON=true` does not make the legacy single-event fallback the only way an order can re-enter the submission queue.

## 4. Sensitive diagnostic data is recursively redacted

Debug/order log sanitization now uses canonical lowercase secret names after key normalization. CSRF tokens, `Token`, Authorization, X-SEC, security codes and related credential fields are recursively masked. Logged request URIs use the path rather than the full query string.

## 5. Category snapshots and hierarchy fail closed

A child category is not finalized at the taxonomy root merely because its parent has not been created yet. Category snapshots are applied in retry passes; children wait for a mapped parent and unresolved/cyclic parent chains prevent the snapshot from being checkpointed as complete.

New or repaired terms remain `mobo_sync_incomplete=1` until the final `wp_update_term()` succeeds. A partial/malformed category snapshot can preserve safe work already applied, but it does not advance `categorySynced` or the successful-sync timestamp.

Product taxonomy assignment now surfaces `wp_set_object_terms()` failures. A product remains incomplete/retryable instead of being reported as converged after a failed category write.

## 6. Address and shipping snapshots are transactional replacements

Address mapping and remote shipping method synchronization validate the complete collection before replacing the last known-good cache. Malformed rows, duplicate IDs, invalid parent references, or missing authoritative collection fields preserve the previous snapshot.

Address synchronization is serialized and verifies the stored option by read-back. The success checkpoint is advanced only after both the mapping and generated city assets are durable.

Shipping rejects stale late responses using the authoritative `changedAt` when supplied, or the request start boundary otherwise. Snapshot and changed-at option writes are read back before success is recorded.

## 7. Maintenance queues use short claim/commit ownership

Cache Warmup, Parent Finalize, and deferred archive purge no longer hold their queue mutex while HTTP, WooCommerce synchronization, or third-party cache purge work is executing.

Each due item is claimed with a `processingToken` and expiration. Work runs outside the mutex, and completion can remove/update only the exact token it claimed. A newer enqueue supersedes the old token, so an older worker cannot erase a mutation that arrived while it was busy.

## 8. Manual Sync and Upgrade no longer share mutable display state

Admin Resume goes through Product Sync ownership rules and can resume only the current `waiting_for_portal` generation. It can no longer directly revive a completed/cancelled state.

Upgrade Barrier no longer edits the Manual Sync cursor/state simply to mark it visually paused. The barrier itself remains the source of truth, removing an unnecessary write race against live sync checkpoints.

## 9. Reconciliation and Product Map distinguish DB failure from an empty result

Deep sweep checks critical Product Map deletions before moving its cursor. Bulk variation-map cleanup reports SELECT/DELETE database failures separately from a legitimate zero-row result, so authoritative finalization is not committed after a failed cleanup.

The Product Map gains the `parent_object` composite index for the hot `parent_remote_guid + object_type` cleanup path.

## 10. Variation identity migration is ordered for crash safety

When the same WooCommerce variation receives a new Portal GUID for the same attribute signature, the new GUID is first persisted on the variation and successfully upserted into Product Map. Only after that durable identity exists are stale reverse mappings for the local variation retired.

This removes the crash window where the old mapping could be deleted before the replacement identity existed.

## 11. Price and stock fields fail closed

A present source price/compare-price/stock value must now be a valid finite numeric value in the supported non-negative integer range. Partially numeric text such as `12abc`, negative values, non-finite values and overflow-sized values are not coerced to zero.

Missing fields retain the previous missing-field policy. Present-but-invalid fields instead keep the product incomplete and retryable, preventing a malformed Portal payload from silently zeroing a price or advancing a source hash without applying stock.

## 12. Image desired-state writes are checked before prune/commit

Image Queue now checks each database INSERT/UPDATE/DELETE used to persist desired image rows. If persistence fails, old desired rows are not pruned and Product Sync does not report success.

Image Refresh likewise checks update results in the `already_done`, `processing`, and `pending` enqueue paths. A failed desired-source update is no longer returned as a successful queue operation.

Cron Image Refresh uses the current automation/workflow state rather than the obsolete legacy enable options, preventing an upgraded/restored site from reporting the workflow available while Cron silently skips it.

## Upgrade behavior

The database change is additive and non-destructive. Existing products, variations, orders, images, mappings, and queue payloads are not deleted by the migration.

If a required schema write cannot be proven complete, Mobo Core deliberately keeps the older database version checkpoint and retries later instead of pretending the migration succeeded.
