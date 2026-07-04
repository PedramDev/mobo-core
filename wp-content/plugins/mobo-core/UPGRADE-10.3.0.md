# Mobo Core 10.3.0 Upgrade Notes

## Scope

This release is a compatibility and migration-safety release. It does not change the product sync protocol yet.

## Changes

- Declares WooCommerce HPOS compatibility for custom order tables.
- Adds WooCommerce compatibility headers:
  - `WC requires at least: 8.2`
  - `WC tested up to: 10.9`
- Moves the webhook queue location from the plugin directory to uploads:
  - Old: `wp-content/plugins/mobo-core/webhook-files/`
  - New: `wp-content/uploads/mobo-core/webhook-files/`
- Existing queued webhook JSON files are migrated safely. They are not deleted.
- Activation no longer deletes webhook JSON files.
- Product image/gallery assignment now uses WooCommerce product CRUD instead of writing `_thumbnail_id` / `_product_image_gallery` directly.

## Existing customer installs

The migration is idempotent and safe to run multiple times. It preserves:

- plugin settings
- security code
- cron/self-runner token
- existing manual sync state
- pending webhook JSON files
- failed webhook JSON files

## Notes

The legacy plugin queue directory may remain with `index.php` and `.htaccess`. JSON files are moved to the new uploads queue when possible.
