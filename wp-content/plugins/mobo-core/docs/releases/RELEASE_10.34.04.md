# MoboCore 10.34.04 — Safe retirement of trashed parent-only Variation identity residue

## Why

Terminal identity proof could hard-block on a trashed `product_variation` that no longer had any durable Variation identity, Variant State row, or Variation Product Map binding, but still carried Product-level identity metadata copied from its parent Product. In that shape there is no Variation GUID to recover: the surviving `product_guid` / `portal_product_id` values identify the parent, not the trashed Variation.

A production example was a trashed Variation whose `product_guid` and Portal Product ID exactly matched its published parent Product while Variant State and Variation Product Map were absent. Treating that parent-only residue as a missing live Variation GUID prevented terminal migration even though inventing a GUID would have been incorrect.

## Change

Identity proof now recognizes one narrow stale-residue class before raising `variation_guid_missing_<post_id>`:

- the object is a Woo `product_variation` in `trash`;
- no Variant State row exists for that Woo Variation ID;
- no Variation Product Map binding exists for that Woo Variation ID;
- no non-empty Variant GUID or Portal Variant ID metadata survives;
- the real Woo parent is a Product with a valid Product State row; and
- every surviving Product GUID / Portal Product ID value on the trashed Variation exactly matches that Parent Product State.

When all conditions hold, proof advances without creating a Variant State row or generating identity. The redundant parent-only postmeta remains in place until the normal terminal fence and bounded physical cleanup remove it.

## Safety

The bypass does **not** apply to arbitrary trashed Variations. It remains fail-closed when any of the following is present:

- a Variant GUID or Portal Variant ID value;
- a Variant State row;
- a Variation Product Map row;
- malformed managed identity metadata;
- missing/invalid Parent Product State; or
- any Product GUID / Portal Product ID disagreement with the parent.

No GUID is synthesized, no ambiguous identity is selected, and no legacy identity row is deleted before the existing terminal fence.

## Telemetry

Migration state increments `staleTrashedVariationResidues` for recognized parent-only trash residue that is safely skipped during exact proof.
