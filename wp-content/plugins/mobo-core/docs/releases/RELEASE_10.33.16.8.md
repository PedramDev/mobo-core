# Mobo Core 10.33.16.8 — Taxonomy & Attribute Fast Path

This release removes repeated WooCommerce taxonomy and product-attribute writes from common product update paths without changing category mapping or authoritative attribute semantics.

## Main changes

- Product category assignment compares the current sorted `product_cat` term set with the desired term IDs before calling `wp_set_object_terms()`.
- Unchanged category assignments therefore avoid taxonomy hooks, term counting, object-cache churn, and third-party cache reactions during price/stock-only updates.
- Category assignment diagnostics (`mobo_category_assign_source` and missing GUID metadata) use update-if-changed semantics.
- Category GUID resolution, map table existence, and term existence are request-cached, including bounded negative lookup caching.
- Parent product updates do not call `set_attributes()` when the desired attribute state is already identical, so unrelated price/stock/title changes avoid rewriting `_product_attributes`.
- Desired attribute comparisons normalize directly from the source payload instead of constructing temporary `WC_Product_Attribute` objects.
- Attribute GUID compatibility metadata is written only when changed and deleted only when present.

## Database / migration

No new table, schema change, or manual migration is required. Existing product/category mappings remain compatible.
