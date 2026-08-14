# Mobo Core 10.33.17.0 — Integrated Runtime Control Plane

## Scope

This release combines the remaining runtime-performance and reliability phases into one integrated control plane while keeping each concern isolated in its own class.

## 1. Adaptive Budget Allocation

- One queue-pressure snapshot is captured at runner start.
- Per-stage time budgets are derived from backlog, EWMA latency/failure health and bounded base weights.
- Webhook, Order Submission and Product Sync retain foreground reserve.
- A single stage cannot receive more than 42% of the distributable runner budget.
- The existing `mobo_core_adaptive_execution_enabled` setting remains the master switch for adaptive weight changes.

## 2. Fair Scheduling / Backpressure

- Background stages use weighted fair scheduling instead of a static escape slot.
- Under due-webhook pressure, at most one background lane is selected per round.
- Without webhook pressure, up to three background lanes can run per round.
- Persisted `lastSelectedAt` and defer counters provide starvation recovery.
- Variable-parent finalization receives additional priority under webhook pressure so converged parent state is applied before lower-value side effects.

## 3. Database Hot-Path Reduction

- Webhook due state from the current processor/scheduler result is reused at round finalization.
- The previous end-of-round `has_due_work()` table probe is removed.
- Queue pressure is captured once at invocation start; existing request-local summary caches are reused.
- No WordPress core-table index or schema modification is introduced.

## 4. Persistent Runtime Intelligence

The existing bounded `mobo_core_runtime_diagnostics` option now also carries:

- EWMA milliseconds per item
- EWMA failure permille
- bounded latency trend
- success/failure streaks
- last success/failure timestamps
- the last 20 run-duration samples
- p50/p95 run latency
- fair-scheduler selected/deferred counters

The diagnostics window remains bounded to 24 hours and still flushes at most once per request.

## 5. Failure Isolation / Circuit Breaker

- Three consecutive failing requests or a high EWMA failure rate can trip a stage circuit.
- Cooldown starts at 60 seconds and grows exponentially to a 15-minute maximum.
- Background stages become `open` and are skipped.
- Webhook and Order Submission become `degraded` and continue at reduced capacity.
- Expired circuits enter `half-open` and run one conservative probe.

## 6. Health / Observability v2

The Health tab and authenticated health payload expose:

- Adaptive stage budgets and backlog pressure
- Fair-scheduler selected/deferred history
- Circuit state and reason
- EWMA, p50/p95 and latency trend
- Failure streaks
- Automatic circuit/starvation recommendations

## 7. Final Hardening

- Existing settings remain immutable baselines.
- Upgrade Barrier / lease renewal / queue durability semantics are preserved.
- No new database table, column, migration or standalone runtime-state option is created.
- All cross-request intelligence is kept inside the existing bounded runtime diagnostics payload.

## Upgrade

- Base: `10.33.16.19`
- Target: `10.33.17.0`
- WordPress DB migration: **not required**
- Portal migration: **not required**
