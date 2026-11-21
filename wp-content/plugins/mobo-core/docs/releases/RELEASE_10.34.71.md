# Mobo Core 10.34.71

## REL12 — Canonical raw Variation meta convergence

This release closes a storefront convergence gap where a WooCommerce Variation could retain legacy case or equivalent legacy `attribute_*` meta-key spelling even after an authoritative Repair snapshot.

- Exact persistence checks now read raw `wp_postmeta` rows instead of the hydrated `WC_Product_Variation` attribute object.
- Equivalent legacy attribute meta keys are canonicalized to `attribute_` + the sanitized attribute key.
- Duplicate rows for the same normalized Variation attribute and stale extra attribute rows are removed only after the incoming Variant payload has passed the existing completeness validation.
- Every successful Variant mutation performs a second exact raw persistence checkpoint before ordering/finalization can commit.
- Identity/signature matching, lock ordering, terminal ownership, and Variant topology validation are unchanged.
