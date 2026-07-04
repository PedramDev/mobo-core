# Mobo Core 10.7.0 Upgrade Notes

## Phase 4: WooCommerce Category Mapping

This release adds a safe local category mapping layer for existing customer stores.

### New table

A new table is created on activation/upgrade:

```text
{wp_prefix}_mobo_category_map
```

It stores:

- remote Mobo category GUID
- synced WooCommerce category term ID
- optional manually mapped WooCommerce category term ID
- remote category name/url/parent metadata

### Migration behavior

The upgrade is idempotent and safe for existing installs:

- existing products are not modified during upgrade
- existing WooCommerce categories are not deleted
- existing `category_guid` term meta remains supported
- old synced categories are seeded into the new map table in bounded batches
- if the map is incomplete, runtime lookup still falls back to legacy `category_guid` term meta

### Assignment order

When assigning categories to products, Mobo Core now uses this order:

1. Manual category mapping, if configured
2. Synced category with the same `category_guid`
3. Auto-create/update category from the payload when mapping is not required
4. Default category fallback

### Required mapping mode

If `mobo_core_category_mapping_required` is enabled and a product category GUID has no manual/synced mapping, the product category assignment is not changed. Missing GUIDs are stored on the product meta:

```text
mobo_category_missing_guids
```

The assignment source is stored in:

```text
mobo_category_assign_source
```

### Compatibility

This release keeps all previous category sync behavior unless manual mapping or required mapping is enabled.
