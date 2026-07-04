# Mobo Core deployment checklist

## Before upgrade

- Backup database.
- Backup `wp-content/uploads/mobo-core/` if it exists.
- Confirm WooCommerce is active.
- Confirm `mobo_core_security_code` exists and matches the Portal webhook security code.

## After upgrade

1. Open WordPress admin → Mobo Core.
2. Confirm plugin version is `10.11.0`.
3. Confirm these tables exist if the DB user has permission to create tables:
   - `{prefix}_mobo_sync_events`
   - `{prefix}_mobo_product_map`
   - `{prefix}_mobo_category_map`
   - `{prefix}_mobo_image_queue`
4. Confirm queue directory exists:
   - `wp-content/uploads/mobo-core/webhook-files/`
5. Confirm self-runner status updates after receiving a webhook or starting sync.
6. Keep checkout validation disabled until the external validation endpoint is finalized.

## Safe rollback

If a customer site has a problem:

1. Disable lightweight webhook in Portal for that site or globally.
2. Upload the previous mobo-core zip.
3. Do not delete `wp-content/uploads/mobo-core/`.
4. Do not drop the custom tables; they are harmless and preserve resume data.

## Notes

- The plugin does not delete WooCommerce products.
- Missing variants are marked out of stock according to `mobo_core_missing_variants_behavior`.
- Product deletion is intentionally not part of the sync model.
