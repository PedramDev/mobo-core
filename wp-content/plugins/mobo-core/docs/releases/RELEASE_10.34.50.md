# Mobo Core 10.34.50 — MIGOPT06-SLICELOOP

## Why this build exists

Fixture telemetry from `test1` on 10.34.49 proved the Identity no-op optimization worked: proof slices fell from roughly 62.5k DB queries and ~6s of Identity worker time to about 1.1k–1.5k total DB queries and ~80–157ms of Identity worker time. However every real-cron migration result still reported `iterations=1` even when the request finished in well under one second.

The cause was a control-flow bug in the accelerated slice waiting detector. `migration_fingerprint_is_waiting()` searched the serialized fingerprint for the generic token `error`; every fingerprint contains the JSON field name `lastError`, so an empty `lastError` still matched and forced the 12-second slice loop to exit after its first iteration. In practice this defeated the intended `one Cron request = many durable migration batches` execution model.

## Change

The waiting detector now inspects only actual worker values:

- a non-empty `lastError`;
- a status containing `grace`;
- a status beginning with `waiting-`;
- a status containing `blocked`;
- a status containing `error`.

It no longer searches JSON field names or arbitrary fingerprint text. The existing progress fingerprint remains unchanged and still detects durable progress between iterations.

## Safety semantics

This is a control-flow correction only. It does not change worker batch semantics, checkpoint ordering, CAS state publication, migration mutex ownership, fence/grace behavior, cleanup journals, late-write recovery, parity proof, zero-residue proof, or batch-size bounds.

A real Cron slice still:

1. acquires the site-wide migration mutex for the whole slice;
2. executes one full prerequisite pipeline pass on the first iteration;
3. advances only the currently active terminal worker on later iterations;
4. stops starting new work before the request safety margin;
5. stops immediately for genuine grace/waiting/blocked/error states;
6. publishes checkpoints only after each durable worker unit succeeds.

## Expected benchmark signal

On an active cleanup/copy/proof phase that is not waiting, the next fixture run should report `iterations > 1` and `progressedIterations > 1` within a single real Cron request, bounded by the 12-second slice budget and the existing 200-iteration hard cap. During fenced grace, it should still stop after one iteration instead of busy-spinning.

## Release gate

Do not release from static validation alone. Resume the current `test1` fixture state and verify:

- healthy active phases execute multiple iterations in one Cron slice;
- grace/waiting states do not spin;
- wall-clock execution stays within the intended bounded slice model;
- cursors/checkpoints advance monotonically and remain replay-safe after forced termination;
- final residue/parity and DB-version completion semantics are unchanged.
