# Mobo Core 10.33.44.18 — Post-sync Repair final integrity and deterministic local cron fence

This patch closes the remaining report-driven Repair integrity gaps without broadening destructive heuristics.

## Changes

- Adds a bounded post-sync Repair phase after the authoritative product/variant pass and before missing-image recovery.
- Writes `_mobo_last_repair_applied_revision`, `_mobo_last_repair_webhook_us`, `_mobo_last_repair_synced_at`, and finally `_mobo_last_repair_sync_id` only at the durable product-completion boundary; the sync ID is the final commit marker.
- Final variation cleanup only considers parents whose marker equals the current Repair `syncId`, whose `mobo_sync_incomplete` is `0`, and whose current applied revision/webhook watermark still exactly match the ordering checkpoint captured at Repair completion. Products omitted by `OnlyInStock`, or changed by a newer webhook after Repair, are therefore not treated as safe post-sync mutation targets.
- Post-sync topology cleanup acquires the same per-product concurrency lock used by normal Product/Variant writers. Transient lock contention does not advance the cursor; the parent is retried instead of silently skipped.
- A final non-variable parent may quarantine stale live Mobo variations to Trash. A variable parent only quarantines an identity-less Mobo sibling when exactly one same-signature durable identity-bearing canonical exists. Ambiguous groups remain unchanged.
- Non-Mobo variations are outside the cleanup candidate set even when their attribute signature matches a Mobo variation.
- Duplicate `_price`, `_regular_price`, and `_sale_price` cleanup runs again against final Repair state. It updates one canonical meta row, removes only extra `meta_id` rows, clears cache, and verifies exact cardinality/value read-back.
- Centralizes Product/Variation identity key families and adds `mobo_variant_guid` / `_mobo_variant_guid` to the shared policy.
- Initial Portal Variant duplicate Repair now recognizes every Portal Variant ID alias from the same policy and fails closed if aliases on one variation conflict.
- Adds an expiring local/development-only `Mobo_Core_Cron_Runner` fence used by the deterministic WAMP baseline. The wrapper installs the plugin fence before waiting for any pre-existing cron lease, closing the idle-check race; the runner checks the fence before `lastHitAt`, locks, queue work, or continuation scheduling and ignores it on non-local sites.
- Docker Compose, production env, and server topology are unchanged.

Deep Test Suite target: `10.33.44.18-r7.6`.
