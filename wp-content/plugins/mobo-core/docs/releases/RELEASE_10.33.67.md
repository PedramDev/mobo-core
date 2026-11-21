# Mobo Core 10.33.67 — Products list Mobo ownership filter

## Admin Products filter

The WooCommerce **Products** list now includes a source filter with three states:

- all products (default);
- Mobo products;
- non-Mobo products.

Classification is based on `Mobo_Core_Product_Identity_Policy`, so durable Mobo identity aliases remain authoritative instead of treating `product_guid` as the only proof of ownership.

The query uses a correlated `EXISTS` / `NOT EXISTS` predicate against post meta. This avoids duplicate product rows when more than one Mobo identity key is present and keeps WooCommerce's existing list query, pagination, sorting, status, category and stock filters intact.

The existing **موبویی** product-list badge now uses the same shared identity policy, keeping UI marking and list filtering consistent.

No schema migration, Repair, reset, or manual SQL is required.
