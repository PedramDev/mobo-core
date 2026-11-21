# Mobo Core 10.34.59 — HARDEN06 Replay / Out-of-Order Ordering

This release hardens ProductUpdated/UpdateVariant replay and out-of-order delivery semantics.

- Queue coalescing consults durable pending/processing/done/failed ordering evidence; a late stale version is retired even when the newer row previously failed.
- Failed-event retry is version-aware: a later row only suppresses an older failed desired state when its ordering watermark is provably equal/newer. Different non-empty incomparable versions do not suppress each other.
- Equal-version completed queue rows are durable replay evidence and duplicate arrivals are retired without reapplying the desired state.
- Product/Variant processors fail closed when two different non-empty ordering watermarks cannot be safely compared.
- ProductUpdated rejects equal-version payloads whose complete source hash conflicts with the already-applied durable hash.
- Webhook ordering conflicts are terminal/non-retryable so they do not create infinite retry storms.

Backend .NET remains at HARDEN03 for this phase. Its desired-state delivery already advances Desired/Delivered revisions monotonically using numeric SourceRevision; HARDEN06 freezes that contract in the Test Suite.
- Equal-version queue replay now requires canonical desired-state payload equivalence; the same ordering version with a divergent payload is terminally rejected before mutation.
- Failed rows that are provably superseded by equal/newer durable ordering evidence are retired as done/superseded evidence, so maintenance retry cannot repeatedly reconsider the same obsolete failed prefix.
