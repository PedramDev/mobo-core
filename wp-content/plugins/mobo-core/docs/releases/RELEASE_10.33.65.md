# Mobo Core 10.33.65 — Sync Health structure semantics + Simple completion

## Fix 1 — WooCommerce-compatible variable structure health

`Mobo_Core_Sync_Health::local_product_structure_is_sane()` no longer assumes parent
attribute `get_options()` is always directly comparable to Variation stored values.

- Uses `WC_Product_Attribute::get_slugs()` when available.
- Falls back to `get_options()` for older compatible WooCommerce builds.
- Accepts an empty Variation attribute value as WooCommerce wildcard / “Any” semantics.
- Still requires the Variation to expose exactly the same variation-attribute keys as the parent.

This is observational health logic only; it does not rewrite product attributes.

## Fix 2 — ProductUpdated can finish a real Simple product even when it has attributes

A Simple Mobo product can carry product attributes while still having exactly one
purchasable source Variant with no selection attributes. 10.33.64 used the existence
of product attribute values as a proxy for variable topology, so ProductUpdated could
finish successfully while leaving both post and Product Map `sync_incomplete=1`.

10.33.65 now probes the authoritative Variant endpoint whenever the current Woo object
is Simple and ProductUpdated topology is otherwise ambiguous:

- one no-selection Variant => apply Simple purchase identity and complete;
- authoritative zero Variant => converge to valid unavailable Simple state and complete;
- multiple/selection Variants => preserve incomplete state and wait for UpdateVariant;
- Portal/network errors remain retryable/fail-closed.

## Compatibility

The new Sync Health comparison capability-detects `get_slugs()` and retains a safe
fallback. No customer WooCommerce version is forcibly pinned by this release.
