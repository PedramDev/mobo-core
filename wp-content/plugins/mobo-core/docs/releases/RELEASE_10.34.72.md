# Mobo Core 10.34.72

## REL13 — Embedded exact Variant structure recovery

- Exact Repair now detects the narrow completed/mapped `localStructureSane=false` convergence gap.
- Concrete Variants embedded in the exact product snapshot are replayed through the existing UpdateVariant mutation path as a non-destructive delta.
- This path does not infer deletions from omitted embedded Variants and does not change normal catalogue/Webhook topology finalization.
- Raw persisted `attribute_*` exact-value convergence from 10.34.71 remains the durable write boundary.
