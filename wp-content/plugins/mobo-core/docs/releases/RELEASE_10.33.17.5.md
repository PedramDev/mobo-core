# Mobo Core 10.33.17.5

## Desired-state variation GUID replacement fast-path

- Fixes the case where a remote variant is deleted/recreated with a new GUID while retaining the same normalized WooCommerce attribute signature.
- Mobo Core continues to reuse the existing WooCommerce variation instead of creating a duplicate.
- The fast/no-op path now updates the variation's `variant_guid` before authoritative missing-variant finalization.
- This prevents the reused variation from being mistaken for the removed old GUID and permanently deleted at the end of the same authoritative snapshot.
- Portal Variant ID and product identity updates remain on the same fast path, so no unnecessary WooCommerce variation save is introduced.

No database schema or Portal migration is required.
