# Mobo Core 10.33.95

## Terminal Product/Variation identity-storage cutover

This release moves Mobo Product/Variation identity from `wp_postmeta` to the plugin-owned Product and Variant tables and physically retires the legacy identity rows only after a strict, resumable parity proof. It builds on the completed 10.33.94 operational-postmeta retirement; `mobo_core_db_version` cannot advance to 10.33.95 unless both terminal migrations are complete.

### Retired Product/Variation identity keys

The terminal scope contains the canonical keys and historical aliases below:

- `product_guid`
- `variant_guid`
- `mobo_variant_guid`
- `_mobo_variant_guid`
- `portal_product_id`
- `mobo_portal_product_id`
- `_mobo_portal_product_id`
- `PortalProductId`
- `portal_variant_id`
- `mobo_portal_variant_id`
- `_mobo_portal_variant_id`
- `PortalVariantId`

Only `product` and `product_variation` objects are in scope. Identity-named attachment metadata, order-item identity snapshots, forensic/quarantine markers, WooCommerce-native metadata, media metadata, pricing metadata and other non-managed metadata are not deleted by this migration.

### Storage Schema v5

Schema v5 makes the identity columns and indexes correctness-critical in the plugin-owned Product/Variant tables. It also adds explicit Product-table columns for Simple-product purchasable-Variant identity:

- `simple_variant_guid`
- `simple_portal_variant_id`

This removes the previous need to keep Simple Product Variant identity in Product postmeta after terminal cutover.

### Upgrade safety and physical cleanup

Before the identity fence can activate, the migration requires:

- Storage Schema v5 to validate successfully.
- the 10.33.94 operational terminal fence to be active with zero operational residue.
- Product Map legacy seeding to be complete.
- every legacy Product/Variation identity object in the bounded proof cursor to mirror exactly into Custom Storage.
- no malformed required identity, duplicate same-key legacy rows, conflicting alias values, unsafe ownership collision, database read failure, or Custom mirror failure.

After exact proof, a dedicated durable runtime fence is published together with a tiny redundant fence-version option. The option contains no identity payload. A 120-second production grace protects requests that may have started before the fence. Cleanup then removes only the managed Product/Variation identity rows in bounded batches. Completion is recorded only after an authoritative SQL residue count is exactly zero.

### Runtime authority after the fence

After the identity terminal fence:

- Product/Variation identity reads are virtualized from the Product/Variant tables at metadata priority 1; missing Custom identity does not fall back to retired postmeta.
- Product identity writes bind directly to Custom Storage. Product GUID ownership cannot silently rebind to another GUID.
- Variation identity writes require an authoritative Custom parent Product identity.
- a Variation GUID may rebind only for the same Woo Variation under the same parent Product and only when the target GUID is not owned elsewhere. This preserves valid upstream Variant recreation without permitting cross-owner takeover.
- Portal Product/Variant IDs are written directly to Custom rows.
- Simple Product Variant GUID/Portal Variant ID are stored in the new Product-table Simple identity columns.
- global `delete_all` for a retired identity key is blocked. Live core ownership identity cannot be generically deleted.
- plain merchant Trash retains Custom identity so Repair can locate and restore the exact Product/Variation. Explicit Variation quarantine may retire its identity through the lifecycle policy. Hard deletion removes the corresponding Custom row.

### Runtime consumers

Direct identity resolvers that previously depended on raw `wp_postmeta` SQL or `meta_query` have terminal Custom-table branches. This includes Product Sync, Product Concurrency, Missing Image Recovery, Recategorize, Product Ledger, Product Map/Repair flows, Portal-Variant duplicate scanning, duplicate Variant-GUID detection, Trash recovery and identity-backed price ownership checks. Pre-fence upgrade branches remain available only while the terminal identity fence is not active.

### Combined terminal health

The Storage Retirement Finalizer is upgraded to gate v3. `physical-retirement-complete` now requires all of the following at the same time:

- operational terminal fence active
- operational migration complete
- operational Product/Variation residue = 0
- all seven operational keys in terminal scope
- identity terminal fence active
- identity migration complete
- identity Product/Variation residue = 0
- all twelve identity key/alias names in terminal scope

The terminal policy is reported as `custom-only-physical-operational-and-identity-postmeta-retirement`.
