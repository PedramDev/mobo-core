# Mobo Core 10.33.44 — Webhook priority during Repair/Sync

## Behavior

- Webhook ingestion remains durable while Repair/Sync is running.
- ProductUpdated for a different product can create/update that product without waiting for the full Repair run.
- Same-product writes remain serialized by the existing product-level concurrency guard.
- Repair product-page snapshots are watermarked. If a newer ProductUpdated or UpdateVariant webhook is applied before a queued Repair product is written, Repair refreshes that product by GUID and will not replay the older page snapshot. A second watermark check after product-lock acquisition closes the refresh/write race.
- After each durable Repair/Sync step, the runner checks for genuinely runnable webhook work (including legacy retry timing) and yields the round so webhook processing is the first stage of the next round.
- Remote Upgrade pauses new worker dispatch while preserving durable dispatch intent. Existing workers stop at their next safe checkpoint, release their leases, and the upgrade proceeds without requiring the complete Repair/Sync job to finish.
- After barrier release, the updater kicks the worker so preserved webhook/Repair/Sync work resumes.

## Safety

The change does not force-release live worker or product leases. Same-product Repair and webhook mutations are not allowed to run concurrently. A stale Repair snapshot is discarded rather than overwriting newer webhook-applied local state when an exact refresh no longer exposes that product. Plugin replacement still waits until the active request reaches the existing safe drain boundary.
