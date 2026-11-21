# Mobo Core 10.33.81 — Stale Mirror Idempotence

- Fixes repository result semantics for replayed/stale Product and Variation snapshots that are already fully dominated by newer Custom Storage watermarks.
- A dominated stale snapshot is now a successful idempotent no-op: persisted newer state is preserved and the mirror does not report `repository_write_failed`.
- Equal-watermark snapshots still require exact hash/presence/value agreement. Same-revision/different-state remains fail-closed.
- Concurrent/incomparable watermark vectors remain fail-closed rather than being mislabeled as benign stale input.
- This prevents false Dual Write failure telemetry from revoking Promotion/Custom-Only confidence during harmless replay or reordering.
- Controlled Custom-Only cutover behavior from 10.33.80 is otherwise unchanged.
