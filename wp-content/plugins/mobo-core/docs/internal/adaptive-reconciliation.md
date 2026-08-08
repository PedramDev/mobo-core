# Adaptive Reconciliation — Mobo Core 10.31.80

## Architecture

All entry points use the existing desired-state engine:

- Webhook delivery -> `Mobo_Core_Product_Sync`
- Automatic health/reconciliation -> `Mobo_Core_Product_Sync`
- Manual Repair / Deep Integrity actions -> `Mobo_Core_Product_Sync`

No second product synchronization engine is introduced. Portal remains the source of truth and WooCommerce is rebuilt toward the current Portal snapshot.

## Fast check

The client first requests `GET /sync/changes?afterRevision={n}&limit={batch}` and then the compatibility path `GET /api/sync/changes`.

When the Portal endpoint is unavailable, Mobo Core uses a stable cursor over `get-products` and processes only the configured product batch. It never scans the complete catalog in one hourly run. With the defaults, each execution processes at most 100 products and 1,000 variations.

## Deep integrity

The daily/weekly deep pass is resumable and bounded. It checks current Portal product snapshots and authoritative variation snapshots, then sweeps local mappings to remove:

- products absent from Portal;
- variations absent from the authoritative variation snapshot;
- invalid product and variation map rows;
- variations with missing or mismatched parents;
- health rows pointing to deleted WooCommerce products.

Attribute-structure drift continues to use the Desired State Sync full variable-product rebuild implemented in 10.31.77.

## Product health

The `mobo_sync_health` table tracks:

- Portal product GUID and numeric ID;
- WooCommerce product ID;
- Portal revision and snapshot hash;
- last successful sync time;
- `synced`, `behind`, `repairing`, or `failed` status;
- last bounded error.

Equivalent product metadata is updated for operational visibility.

## Admin defaults

- Auto Reconciliation: enabled
- Fast Check Interval: 1 hour
- Products Per Run: 100
- Variation Batch: 1,000
- Deep Integrity Check: weekly

## Recovery scenarios

1. A product created while WordPress is offline is discovered by revision changes or the bounded rolling fallback and created through desired-state sync.
2. A missed variation deletion is removed when the product receives its next authoritative variation snapshot.
3. Attribute-structure drift triggers the existing full variable-product rebuild.
4. Orphan and invalid product/variation mappings are removed during the deep sweep.
