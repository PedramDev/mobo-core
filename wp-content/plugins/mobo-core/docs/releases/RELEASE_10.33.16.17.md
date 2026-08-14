# Mobo Core 10.33.16.17 — Adaptive Self-Tuning

## Goal

Turn the runtime diagnostics introduced in 10.33.16.16 into safe, request-local execution tuning. The plugin should use more capacity on fast VPS hosts and reduce batch pressure on constrained/shared hosts without persisting automatic configuration changes.

## Changes

- Added `Mobo_Core_Adaptive_Tuner`.
- Configured batch sizes remain immutable baselines; adaptive limits exist only for the current Real Cron invocation.
- Runtime diagnostics now retain recent EWMA milliseconds-per-item and failure ratios for worker stages.
- Adaptive limits cover the pressure-aware Webhook Queue ceiling, Parent Finalize, Image Queue, Reprice, Recategorize and exact-product Cache Warmup.
- Product sync steps only downshift during proven resource pressure; they are never automatically increased.
- Recent `memory-pressure` runner stops select a conservative profile for a bounded recovery window; recent frequent `time-budget-exhausted` stops select a cautious profile. Old pressure automatically ages out so a recovered host can scale back up.
- Healthy fast stages may scale up to 2x their configured baseline, still bounded by the existing hard worker caps.
- Conservative profiles can reduce worker limits to roughly 50-75% of baseline.
- The active profile is included in `runtimeDiagnostics` and the Real Cron runner result.
- Added a Real Cron admin toggle to disable adaptive execution instantly.
- No database table/schema migration is required.

## Safety properties

- No automatic settings overwrite.
- No change to Desired State, Repair, Stage 7, event ordering or queue durability.
- Existing execution deadlines, lock leases, memory reserve and priority-lane backpressure remain authoritative.
- A missing/insufficient diagnostics history keeps the configured baseline unchanged (`learning` mode).
