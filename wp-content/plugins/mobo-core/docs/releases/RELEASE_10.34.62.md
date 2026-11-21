# Mobo Core 10.34.62 — REL03 Custom-State Residue Finalizer

## Why this release exists

The 10.34.55 convergence-residue finalizer correctly stopped requiring retained completed-event history, but its candidate query still used `mobo_sync_health` as an INNER JOIN and took revision/hash proof from that observational table. Pre-release customer snapshots demonstrated 927 current variable products per site whose durable Product Map and local structure were current while old incomplete markers remained. The scan exhausted and cleared its pending flag without healing them because observational Health did not provide the authoritative proof boundary.

## Production change

- Re-arms the bounded convergence-residue finalizer on upgrades from versions below 10.34.62.
- Candidate identity/revision/hash proof now comes from Product Map plus canonical Custom Storage `products` state.
- Product and Variant seen/applied revision checkpoints must be present and converged, Product source hash must match Product Map, aggregate desired revision must not exceed applied revision, and no Custom Storage claim may be active.
- Every live child variation must have a complete Product Map row and a converged canonical Custom Storage Variant row for the same Product.
- Active `ProductUpdated` / `UpdateVariant` ownership remains fail-closed.
- Local Woo variable topology, desired-state completion and missing-variant guards remain required.
- `mobo_sync_health` is written only after durable convergence is proven and the durable incomplete markers have been cleared; it is no longer used to decide whether those markers may be cleared.
- Sync Health refresh is best-effort after durable convergence is committed. A failed observational Health write is recorded in migration diagnostics and does **not** roll durable incomplete markers back.

Backend/Portal code is unchanged.
