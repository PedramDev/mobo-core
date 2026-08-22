# Safe Remote Upgrade Barrier

Mobo Core coordinates remote plugin replacement with all local work through one global barrier. Version `10.33.44` keeps that handoff protection and explicitly preserves producer wake-up intent while dispatch is paused behind the upgrade barrier.

## Barrier-covered work

- real cron and heartbeat worker;
- manual Sync and Repair;
- Adaptive Reconciliation and Deep Integrity Check;
- webhook queue;
- image queue and image-refresh automation;
- reprice and recategorize queues;
- maintenance cleanup and self-runner dispatch;
- product and variation writes protected by product-level leases.

## Guarantees

- No new covered worker starts after the barrier owns its lease.
- A worker that won the acquisition race immediately surrenders only its own newly created lease.
- Existing workers finish the current safe unit and stop before taking another item.
- Live worker leases are observed and never force-released.
- A `worker_dispatcher` lease that is still only an unclaimed loopback handoff may be cancelled atomically during barrier activation; compare-and-delete prevents cancellation after a concurrent worker claim/renew.
- Plugin files are not replaced until all visible workers are idle.
- Busy sites return a retryable `423` response with active-lock diagnostics.
- Sync/Repair cursors and all durable queues survive both successful and blocked upgrades.
- Webhook receipt is still accepted durably while dispatch is paused for the upgrade; the release path wakes preserved work again.

## Failure behavior

If a real blocking lock remains active beyond the effective drain timeout, status becomes `blocked-site-busy`. The effective timeout is never lower than the configured value and includes headroom for the longest configured blocking Mobo HTTP timeout, capped at 300 seconds. The validated temporary package is removed, the barrier is released, and normal processing resumes. No backup or plugin directory replacement has begun at that point.
