# Mobo Core 10.34.66

## Release-residue finalizer

- Uses exact canonical Custom Variant State as the authoritative child proof after Custom Storage retirement.
- Does not require legacy Variation Product Map coverage when exact Custom Variant State covers every live child and every row is fully applied, clean, unclaimed, and complete.
- Retains the hashed Variation Map fallback only for parents with no Custom Variant State at all.
- Re-arms the bounded historical convergence-residue finalizer on upgrade from earlier builds.
