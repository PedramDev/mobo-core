# Mobo Core 10.34.65 — REL06 Variant Applied-State Residue Proof

- Re-arms the bounded convergence-residue finalizer on upgrades from versions below 10.34.65.
- Fixes the historical Variant proof boundary: `variant_seen_revision == variant_applied_revision` is not a repository invariant. Migrated rows may validly have `applied > seen` while the canonical state is fully applied.
- For products with exact Custom Variant coverage, proof now requires exact live-child identity coverage, zero incomplete child maps, `sync_incomplete=0`, `desired_revision<=applied_revision`, `sync_status=applied`, clean dirty state, no claim, and non-conflicting applied/source hashes.
- Child Product Map `last_hash` remains required only for the legacy Map-only fallback where no Custom Variant rows exist.
- Active retry owners, terminal upstream-invalid products, desired-state gaps, partial Custom Variant coverage, and local topology failures remain fail-closed.
