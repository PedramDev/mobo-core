# Desired-State Variable Product Synchronization

Version 10.31.77 treats Portal as the authoritative product state.

- `UpdateVariant` snapshots are authoritative and may be paged.
- Missing WooCommerce variations are permanently deleted together with post meta and Mobo map rows.
- Attribute name/count changes trigger a full rebuild: variations, attributes and defaults are cleared, the product is saved, current attributes are applied, and current variations are recreated.
- Attribute order is intentionally ignored because Portal does not persist a stable attribute position.
- When attribute structure is unchanged, variations are matched first by remote GUID and then by normalized attribute selection. This allows a recreated Portal variant to reuse the correct WooCommerce variation without preserving obsolete identity.
- Upgrade to 10.31.77 enqueues one bounded, resumable Repair automatically on existing Mobo installations. An active Sync/Repair is never overwritten.
- Empty authoritative snapshots delete all obsolete variations instead of retaining or merely marking them out of stock.

No Portal EF schema migration is required. Deploy Portal first, run the normal source import, then deploy Mobo Core 10.31.77.
