# Mobo Core 10.33.16.7 — Media Link & Attachment Fast Path

This release reduces WooCommerce CRUD, postmeta, and attachment lookup work in the asynchronous image pipeline without changing desired-state or image-refresh semantics.

## Main changes

- Image queue rows are imported/resolved first and product featured/gallery linkage is coalesced to one WooCommerce save per touched product per worker batch.
- Imported files remain in the durable `attaching` state until the product link succeeds; successful rows are completed with one bulk status update.
- `attaching` rows receive a short retry grace window, preventing concurrent re-entry while preserving crash recovery.
- Attachment lookup by `image_guid`, `img_guid`, and `mobo_source_url` is request-cached, including negative results.
- Product and attachment post/meta caches are primed for each claimed batch.
- Attachment identity metadata uses update-if-changed semantics.
- Product image linkage compares the current featured/gallery state before calling `WC_Product::save()`.
- Product-link hook failures leave the attachment reusable and the queue retryable.

## Database / migration

No new table and no manual migration are required. Existing image queue rows remain compatible.
