# Mobo Core 10.33.66 — ProductUpdated → UpdateVariant multi-page handoff

## Problem proven by Clean Gate evidence

A live product was still a `WC_Product_Simple`, while its ProductUpdated payload carried a
variation-capable attribute and the authoritative Variant endpoint reported additional pages.

10.33.65 correctly attempted to distinguish a descriptive-attribute Simple product from a
real variable topology, but treated the explicit “additional Variant pages” result as a retryable
Simple-probe error. ProductUpdated therefore deferred forever and UpdateVariant never ran.

## Fix

When all of the following are true:

- ProductUpdated is variation-capable,
- the current WooCommerce object is still Simple,
- the authoritative Simple-topology probe returns the exact
  `mobo_core_simple_variant_pagination_ambiguous` condition,

the condition is interpreted as evidence that automatic Simple selection is unsafe and the
product must continue through the normal UpdateVariant convergence path.

ProductUpdated reaches its terminal checkpoint while preserving the durable incomplete marker.
The subsequent authoritative UpdateVariant snapshot can then convert the parent to Variable and
clear completion markers.

Transport, authentication, malformed response, and other probe errors still defer/fail closed.

## Retained 10.33.65 fixes

- taxonomy attribute slug-compatible Sync Health validation;
- empty Variation attribute values are valid WooCommerce wildcard/Any semantics;
- one no-selection Variant can prove a real Simple product even when ProductUpdated carries
  descriptive/product attributes.
