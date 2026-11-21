# Mobo Core 10.33.73 — Custom Storage Reconciliation / Cleanup

This release adds the bounded reconciliation layer required before Custom Storage can ever become authoritative.

Legacy Product Map/postmeta remains the source of truth. The reconciler walks only a bounded Product Map slice per low-priority runner invocation, mirrors canonical Product/Variation state into Custom Storage, and removes Custom rows whose local Woo object is definitively deleted, trashed, auto-draft, or of the wrong post type.

Active identity conflicts are never rewritten destructively. They are reported as ambiguous and remain promotion blockers. Product/Variation repository cleanup uses exact observed-row predicates to avoid racing concurrent writers.

WordPress trash/delete/untrash lifecycle hooks keep the Custom mirror aligned. Untrash re-mirroring is allowed only when a surviving Legacy Product Map row exists.

The promotion confidence gate now requires a clean reconciliation checkpoint in addition to clean backfill, exact active identity coverage, fresh parity, 500 post-baseline comparisons, and six clean hours. This release still does not enable or auto-promote `custom-primary` or `custom-only`; both continue to degrade to `shadow-read`.
