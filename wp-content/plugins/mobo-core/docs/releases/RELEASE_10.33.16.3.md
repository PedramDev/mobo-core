# Mobo Core 10.33.16.3 — Product Sync Performance

This release reduces redundant WooCommerce work during product and variation synchronization without changing desired-state semantics.

## Highlights

- Parent Smart Diff and no-op CRUD skip using `_mobo_product_source_hash`.
- Local storefront drift verification before accepting a parent hash fast path.
- Existing parents use at most one WooCommerce CRUD save for a real storefront mutation instead of the previous preliminary + final save sequence.
- Product image convergence hash (`_mobo_product_images_source_hash`) skips unchanged image sets.
- Unchanged normal and simple-product variants use hash fast paths and avoid redundant writes.
- Identical authoritative parent attributes are not re-saved on each variant page.
- Parent variable sync is coalesced with `_mobo_parent_sync_pending` and executed once on the final snapshot page.
- Intermediate authoritative pages suppress targeted page-cache purge/warmup; the final converged state gets one exact-product invalidation/warmup.
- Existing product type is preserved without repeated term/save operations.

## Upgrade behavior

No new database table or EF/database migration is required. Existing products acquire the new parent/image convergence hashes naturally during subsequent synchronization. The first post-upgrade pass may therefore perform normal work once; later identical desired-state deliveries can take the no-op fast path.

## Safety

Repair mode deliberately bypasses source-hash shortcuts. A matching source hash is also rejected when local title, slug, price, stock, attributes, published timestamp, or controlled category identity has drifted from the desired state.
