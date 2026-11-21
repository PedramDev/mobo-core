# Mobo Core 10.34.64 — REL05 Historical Residue Proof

## Why this release exists

The 10.34.63 targeted convergence probe proved that 934 incomplete Product Map rows were found correctly. The remaining blocker was proof semantics: Product and Variant revisions are independent event streams, but the 10.34.62/63 finalizer required Variant applied revision to equal Product applied revision and required Custom Variant state for historical rows that pre-dated authoritative Variant Custom Storage.

## Production change

- Re-arms the bounded convergence-residue finalizer on upgrades from versions below 10.34.64.
- Product revision proof is independent from Variant revision proof. A present Variant checkpoint must converge with itself; it is never required to equal the Product revision.
- Requires canonical Product desired revision to be fully applied. Rows with real desired-state residue remain incomplete and release-visible.
- Treats terminal failed `UpdateVariant` attribute-integrity ownership as a conservative exclusion.
- Supports simple products with the same Product-state/hash/local-structure proof.
- For variable products, requires exact live child coverage by complete Product Map rows with non-empty hashes and zero incomplete child mappings.
- When Custom Variant state exists for any live child, it must cover every live child and each row must be independently converged. If no Custom Variant rows exist, the exact complete child Product Map is accepted as the historical pre-retirement fallback.
- `_mobo_desired_state_last_completed_at` is no longer required when canonical Custom Product `desired_revision <= applied_revision` already proves the desired-state boundary.
- Sync Health remains observational and is refreshed only after durable marker clearance.

Portal/.NET is unchanged.
