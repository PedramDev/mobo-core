# MoboCore 10.34.07 — Parent-first materialization during Variation identity proof

## Why

Identity proof walks Woo objects in ascending post ID. A live `product_variation` can therefore be visited before its parent Product when the parent's post ID is higher. In that ordering, the Variation may already have a complete, correct `mobo_variant_state` row and exact Product Map/legacy parent identity, while `mobo_product_state` for the parent has not yet been materialized. Variation parity correctly requires the parent Portal Product ID to be represented in Product State, so proof could stop with `variation_identity_proof_<variation_id>` even though every identity value agreed.

## Change

Before mirroring/proving a Variation, terminal Identity retirement now resolves the parent Product through the existing fail-closed proof resolver, requires that resolved GUID to equal the Variation's resolved parent GUID, mirrors the parent through the standard `Mobo_Core_Storage_Bridge::mirror_product_legacy_state()` path, and verifies normal Product custom-storage parity. Only then does it mirror and prove the Variation.

## Safety

- no Variant or Product GUID is generated;
- no provisional bypass is added to `object_matches_custom()`;
- conflicting, missing or malformed parent identity remains blocked;
- Product repository/bridge failures remain proof errors;
- the later normal Product proof remains idempotent when its post ID is reached.
