# Safe Remote Upgrade Barrier

Mobo Core `10.31.84` coordinates remote plugin replacement with all local work through one global barrier.

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
- Live leases are observed and never force-released.
- Plugin files are not replaced until all visible workers are idle.
- Busy sites return a retryable `423` response with active-lock diagnostics.
- Sync/Repair cursors and all durable queues survive both successful and blocked upgrades.

## Failure behavior

If a lock remains active beyond the drain timeout, status becomes `blocked-site-busy`. The validated temporary package is removed, the barrier is released, and normal processing resumes. No backup or plugin directory replacement has begun at that point.
