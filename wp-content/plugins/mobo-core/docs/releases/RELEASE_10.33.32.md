# Mobo Core 10.33.32 — Recovery orchestration hardening

## Recovery / dispatcher
- Activation and deferred repairs coalesce their wake-up intent into one site worker dispatch instead of spawning one loopback request per recovery source.
- Every active site worker is protected by the atomic `worker_dispatcher` lease. A duplicate active request returns `423 Locked`; a stale transferred handoff returns `409 Conflict`.
- A successfully dispatched loopback transfers lease ownership to `/worker/run`; the worker renews that lease while executing bounded stages and releases it before any continuation is dispatched.
- Non-arrived/failed loopback dispatches use bounded backoff (30s, 2m, 10m, 30m, 60m) instead of immediate retry storms.

## Product recovery
- Product Recovery uses the shared `recovery_pipeline` lease and durable generation/cursor checkpoints. Only one recovery batch can mutate a site at a time.
- Recovery work is intentionally short: at most 10 candidates per direct call and 1–3 candidates per normal cron round, with persisted ledger/portal cursors.
- Interrupted exact fetches are re-fetched by GUID instead of feeding an empty/stale payload into Product Sync.
- Item failures use bounded backoff and are quarantined after a finite retry budget so one broken product cannot strand the whole site.

## Cache warmup
- Product cache warmup is never started while Recovery is pending. URLs may be deduplicated into the persistent queue, but only one post-recovery warmup intent is recorded.
- Warmup shares the recovery pipeline lease, re-checks Recovery after lock acquisition, and drains serially (one URL per batch) after Recovery converges.
- `cache-warmup-queued`, real-cron continuation and self-runner continuation are all serialized by the same site dispatcher, so they cannot overlap an active worker request.

## Image Refresh
- Retains the 10.33.31 one-click autonomous Image Refresh: no pause/approval/stage decisions, automatic prerequisite Repair, bounded retry/backoff and safe quarantine.
