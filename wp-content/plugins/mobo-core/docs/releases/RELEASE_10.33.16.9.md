# Mobo Core 10.33.16.9 — WooCommerce CRUD Write Coalescing

This release reduces WooCommerce CRUD writes and hook/lookup churn after the queue, variation, media, and taxonomy fast paths introduced in earlier 10.33.16.x releases.

## Main changes

- New parent products and new variations are fully assembled in memory and written with one WooCommerce CRUD save instead of two.
- Crash detection remains safe: the single create save persists `mobo_sync_incomplete=1`; the marker is cleared with a lightweight meta update only after CRUD persistence succeeds.
- Existing product/variation mutations compare price, stock, slug, published dates, parent IDs, names, variation attributes, identity metadata, and source metadata before calling setters/update_meta_data.
- Source/policy/stock timestamps now move only when their associated state changes, avoiding timestamp-only writes on every sync.
- Variation attribute setters are skipped when the current normalized signature already equals the desired signature.
- Reprice Queue performs a WooCommerce save only when the rendered regular/sale price changes. If only Mobo bookkeeping metadata differs, it is updated directly without product-save hooks or transient churn.
- Variable-parent reprice synchronization is queued only for variations with an actual storefront price change.

## Expected effect

- Initial imports perform roughly one WooCommerce create/save cycle per new product or variation instead of two for the core object write path.
- Price/stock-only updates carry fewer postmeta writes and less WooCommerce dirty-data work.
- Reprice runs with unchanged calculated prices avoid product-save hooks, lookup/transient work, and unnecessary parent recalculation.

## Database / migration

No new table, schema change, or manual migration is required. Existing sync state, mappings, queues, Stage 7 state, and product metadata remain compatible.
