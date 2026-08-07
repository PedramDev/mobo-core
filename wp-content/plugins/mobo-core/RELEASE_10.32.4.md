# Mobo Core 10.32.4

## Image-only recovery for existing products without images

A product can be created in WooCommerce and then become unavailable while the site is configured to receive only in-stock products. In that case the product may disappear from later product-list pages before its image payload is applied, leaving a published local product without a featured image.

Version 10.32.4 adds a dedicated image-only recovery path for these existing local products.

### Repair behavior

After the normal Repair product pass completes, Repair scans local Mobo products whose featured-image reference is missing or invalid. Each candidate is fetched individually by its stored product GUID through the existing single-product endpoint, which is independent of the normal `OnlyInStock` product-list filter.

Only the remote `images` array is passed to the existing safe Image Queue. The recovery pass does not upsert the product and does not request or process variants.

### Image Refresh behavior

The Image Refresh discovery and queue-building stages now include local Mobo products without a usable featured image in addition to legacy JPG/PNG attachments. Their image payload is handed to the normal Image Queue, so Shared Media readiness, retries, attachment linkage, and cron processing retain the existing behavior.

### Safety boundaries

- Runs only when automatic image updates (`global_update_images`) are enabled.
- Requires an existing local WooCommerce product with a stored `product_guid`.
- Does not create an out-of-stock product that is absent locally.
- Does not update title, price, stock, publish status, categories, attributes, metadata fields, or variants.
- Does not bypass Shared Media rules or download fallback settings.
- API and image-source failures stay resumable through existing retry handling.
- No WordPress database migration is required.
