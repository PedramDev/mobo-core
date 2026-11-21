# Mobo Core 10.34.67

## Release-residue finalizer

- Uses canonical Custom Variant convergence as the post-retirement child authority: exact live-child coverage, `sync_incomplete=0`, `desired_revision <= applied_revision`, `sync_status=applied`, clean dirty flags, no claim, and compatible applied/source hashes.
- Does not require historical migrated child rows to carry legacy `variant_seen_revision_present` / `variant_applied_revision_present` bits when canonical desired/applied state already proves convergence.
- Keeps optional parent Variant source checkpoints fail-closed when present: both watermarks must exist and preserve `seen <= applied`.
- Keeps legacy Variation Map only as the fallback when no Custom Variant State exists for the parent.
- Continues to reject products with due desired state, active ownership, terminal exclusions, incomplete local topology, partial child coverage, dirty state, claims, or conflicting hashes.
- Re-arms the bounded convergence-residue finalizer on upgrade from earlier builds.
