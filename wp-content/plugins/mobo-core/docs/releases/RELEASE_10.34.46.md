# Mobo Core 10.34.46 — MIGOPT02

## Scope

WordPress plugin only. Portal/.NET is unchanged. No WP-CLI dependency is introduced. This candidate supersedes 10.34.45 for migration benchmarking.

## Migration execution corrections

- The complete accelerated migration slice now owns the shared migration mutex for its full lifetime. Direct terminal-worker iterations can no longer overlap with `maybe_run()` from a normal storefront/admin request.
- `mobo-cron.php` bootstrap no longer runs the conservative `plugins_loaded` migration pass before the dedicated 12-second slice. One Cron request therefore has one migration pipeline owner instead of a duplicate pre-pass plus slice pass.
- A Cron request returns immediately after its migration slice even when that slice completes the migration. Ordinary queue work resumes on the next Cron invocation, so the final migration request cannot silently become `12s migration + normal runner budget`.

## Migration performance retained from 10.34.45

- One full prerequisite pipeline pass per slice; subsequent iterations advance only the active terminal retirement worker.
- Accelerated batches are Cron-slice-only: Operational proof 500 / cleanup 2000; Identity proof 500 / cleanup 2000; Entity copy 1000 / cleanup 1000.
- Entity copy/cleanup re-mirroring uses bounded multi-row upserts.
- Cursor/fence/grace/parity/late-write replay/zero-residue safety remains authoritative.
- Grace counters are reset after leaving grace.

## Benchmark telemetry

Each migration slice now reports lightweight telemetry in `databaseMigration.telemetry`:

- `pipelinePasses` and `pipelineElapsedMs`
- terminal `workerCalls` and aggregated `workerElapsedMs` for Operational / Identity / Entity
- WordPress DB query count delta for the slice
- start/peak PHP memory bytes

The existing `iterations`, `progressedIterations`, `elapsedMs`, worker cursors, copied/cleaned row counters, status, grace remaining and residue fields remain available for per-slice benchmark analysis.

## Validation requirement

Do not release from static validation alone. Restore pristine 10.33.67 fixtures for test1 and test2 and benchmark this candidate against 10.34.44, including wall-clock time, active processing time, slice count, rows per phase, DB query pressure, memory, correctness, preservation and zero residue.

## Static validation

- Targeted migration checks: 20/20 PASS.
- PHP lint: 132/132 PASS.
- Manifest and ZIP integrity are regenerated after these changes.
