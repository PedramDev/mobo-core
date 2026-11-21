# Mobo Core 10.34.68

## REL09 — OnlyInStock exact convergence recovery

- Adds a Repair-only exact convergence recovery phase after the catalogue product pass.
- Existing local products classified by Operational Sync Health as `unownedConvergenceGap` are fetched individually by product GUID, independent of the `OnlyInStock` catalogue filter.
- Exact recovery never discovers or imports a new product: a current local WooCommerce product identity is required before the request is queued.
- Active retry ownership and current terminal-upstream-invalid rows are excluded by the Operational Health classifier.
- Exact snapshots are applied through the existing Manual/Repair Parent + Variant pipeline, retaining product locks, webhook watermarks, stale-snapshot rebasing, desired-state checkpoints, and normal field/stock policy.
- A fresh targeted Sync Health snapshot is forced at each recovery boundary so cached dashboard state cannot reselect already healed GUIDs.
- Exact endpoint absence is non-destructive evidence: the product remains unresolved rather than being deleted or retired.

This release does not change Backend/Portal behavior. It closes the client-side gap where an already-local product can be omitted from a normal `OnlyInStock=true` catalogue page after upstream stock reaches zero while still requiring convergence.
