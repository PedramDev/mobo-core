# Mobo Core 10.33.94

## Terminal Product/Variation operational-state storage cutover

This release physically retires the seven high-churn Mobo operational metadata keys from `wp_postmeta` for WooCommerce `product` and `product_variation` objects after a strict, resumable Custom Storage verification process.

### Retired Product/Variation postmeta keys

- `_mobo_product_seen_revision`
- `_mobo_product_applied_revision`
- `_mobo_variant_seen_revision`
- `_mobo_variant_applied_revision`
- `_mobo_product_source_hash`
- `_mobo_variant_source_hash`
- `mobo_sync_incomplete`

`mobo_sync_incomplete` on attachments is explicitly outside this migration and remains ordinary WordPress metadata.

### Upgrade safety

The upgrade does not publish terminal authority immediately. It first forces pre-fence dual-write, requires Product Map legacy seeding to finish, advances bounded Custom Storage backfill, and performs a resumable exact map/state verification pass. Duplicate operational rows, unmapped operational state, duplicate map ownership, missing Custom coverage, database read errors, or mirror failures block the cutover.

Only after those checks pass is a durable terminal fence written to the Custom runtime table. A tiny immutable WordPress option mirrors only the fence version as redundant fail-closed evidence; it contains no migrated operational payload. The completed DB version is a final authority fallback. The fence is authoritative before physical deletion begins. A 120-second grace period protects requests that may have started on the previous build. Cleanup is bounded and removes only the seven keys from Product/Variation rows. `mobo_core_db_version` is not stamped `10.33.94` until an authoritative zero-residue query succeeds.

### Runtime authority after the fence

After the terminal fence:

- Custom Storage is permanently authoritative for the seven managed keys; rollout leases can no longer degrade the runtime back to Legacy.
- `add_post_meta`, `update_post_meta`, and `delete_post_meta` for managed Product/Variation keys are intercepted before WordPress can recreate retired rows; global `delete_all` for a managed key is also blocked from bypassing Custom authority.
- mutations are fail-closed: a missing Custom row, invalid scalar, CAS conflict, revision regression, or Custom write failure never falls through to `wp_postmeta`.
- reads return Custom value/presence directly. Missing Custom state never triggers a Legacy fallback; `mobo_sync_incomplete` fails safe as incomplete when the Custom row is unexpectedly unavailable.
- attachment metadata is not intercepted by this terminal policy.

### Retirement evidence

The retirement finalizer is upgraded to gate version 2 and reports `physical-retirement-complete` only when the durable fence is active, all seven terminal keys are requested, retirement state is complete, and physical Product/Variation residue is zero.

### Compatibility boundary

This release does **not** remove WooCommerce/native metadata, Mobo identity metadata, media metadata, pricing metadata, or other low-churn settings. Those remain separate migration scopes and must not be deleted as part of this cutover.
