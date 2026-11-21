# Mobo Core 10.34.69

## Exact Variation attribute-value convergence

A Variable Product can be structurally stale even when its legacy variation identity signature matches the current upstream selection. The shared identity signature intentionally slug-normalizes option values so that harmless case-only differences do not create duplicate WooCommerce variations. That signature must not, however, decide whether the persisted storefront attribute value is already converged.

10.34.69 separates those two responsibilities:

- GUID / PortalVariantId / attribute-signature lookup remains identity-oriented and slug-normalized.
- storefront no-op detection now compares normalized attribute keys with exact sanitized option values.
- variation saves likewise rewrite exact option values when only value case differs.
- existing lock, ordering, source-hash, desired-state, and duplicate-prevention semantics are unchanged.

This repairs historical children such as `Airpods 3` under an authoritative parent option `airpods 3` without creating a replacement variation.
