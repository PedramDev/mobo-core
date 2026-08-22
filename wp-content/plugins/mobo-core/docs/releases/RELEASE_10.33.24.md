# Mobo Core 10.33.24

## Failure-oriented image storage and refresh audit

10.33.24 performs a third image-pipeline audit focused on failure boundaries rather than only normal synchronization. The examined path is remote desired state → queue claim → local/shared import → attachment identity → physical storage → WordPress metadata/subsizes → WooCommerce featured/gallery linkage → refresh/recovery → cleanup.

### 1. Local attachment health is now storage-consistent

A WebP attachment is healthy only when the physical original, WordPress post MIME, filename extension, `_wp_attached_file`, `_wp_attachment_metadata`, and derivative files describe the same image family.

The verifier now checks the physical original dimensions against attachment metadata and checks every registered derivative's real MIME and dimensions against its metadata row. This detects copied/restored metadata, stale `_wp_attached_file` paths, zero/invalid files, and mislabeled WebP payloads that PHP might still decode while the web server/CDN would serve with an incorrect extension or MIME contract.

### 2. Current WordPress image-size settings are authoritative

A derivative can be internally self-consistent and still be obsolete. For example, both the file and its old metadata may say 800×600 even though the current theme/settings require 1024×768.

Mobo Core now recalculates the expected dimensions for registered image sizes from the actual original dimensions and current WordPress resize settings. Stale-but-present entries are invalidated before `wp_update_image_subsizes()` so WordPress can regenerate the correct cuts.

### 3. Featured image and gallery have distinct WooCommerce roles

WooCommerce stores the featured attachment separately from `_product_image_gallery`. The first Mobo image is now written only as the featured image; gallery metadata contains only the remaining ordered images.

Existing installations are repaired through bounded maintenance. A product is scheduled for linkage-only repair when the featured image is wrong, the featured ID is duplicated in the gallery, or a desired non-featured image is missing. No image redownload is required for a pure linkage repair.

### 4. Image success has one deep definition

Previously, active Refresh could consider thumbnail integrity while a Fast Path or historical `done` row accepted only a healthy original file. That allowed an unchanged source hash to hide corrupt/stale derivatives.

Normal Image Queue repair, source-hash Fast Path validation, product gallery reconstruction, Shared Media checks, and historical Image Refresh completion now converge on the same deep readiness rules. A durable `done` state is no longer a substitute for physical proof.

### 5. Local sideload identity survives a PHP crash

WordPress creates the attachment row before Mobo Core previously wrote `image_guid` and `mobo_source_url`. A PHP termination in that narrow interval left a valid file/attachment with no Mobo identity; retry could not find it and WordPress generated a filename collision such as `image-1.webp`.

10.33.24 hooks the attachment creation lifecycle for the current guarded import. The intended GUID/source are persisted immediately with `mobo_sync_incomplete=1`. The attachment remains incomplete until the physical original, metadata, and required derivatives pass final readiness. A retry can therefore recover the same attachment instead of creating another copy.

### 6. Durable queue identity is stricter than legacy adoption

A legacy attachment with no GUID can still be adopted when the source URL is otherwise safe and unclaimed. However, a queue row cannot be considered converged while that identity is absent. Processing repairs the identity first and only then commits the row.

Likewise, an attachment explicitly carrying `mobo_sync_incomplete=1` is treated as a recoverable checkpoint rather than a completed image. It is re-entered through readiness and only becomes durable after the final commit marker is cleared.

Attachments already owned by a different GUID remain isolated even when source URLs happen to be identical.

### 7. Shared Media mutation is guarded and rollback-aware

Shared import and manifest refresh now use the same image-GUID identity lock. The complete manifest family is validated before WordPress mutation, and persisted post/meta is read back after mutation.

When post-write validation fails, an existing attachment is restored from its captured post/meta state. If the operation created a new virtual attachment, only its WordPress database record is removed; site-side rollback never unlinks worker-owned read-only Shared Media files.

Deep Shared Media health also validates GUID aliases, Mobo format, stored MIME, attached-file path, manifest revision/profile, physical bytes/MIME/dimensions, and attachment metadata consistency.

### 8. Queue leases match storage-mutation leases

Image Queue row leases previously expired sooner than the import/subsize locks. On a slow host, another worker could reclaim a row while the first worker was still completing an expensive image operation.

Normal Image Queue and Image Refresh row leases are now 300 seconds, matching the storage-mutation lock horizon. Existing compare-and-set guards still prevent stale workers from committing a superseded source.

Queue identities are also canonicalized case-insensitively. On upgrade, enqueue detects a legacy row whose key was created from differently-cased GUID text, adopts that row, and migrates it to the canonical key instead of creating a second durable job. The same compatibility behavior is applied to Image Refresh.

### 9. Existing installations are re-audited safely

The 10.33.24 upgrade resets bounded image-health cursors and requests an image queue recovery pass. This lets the stronger validators repair old gallery linkage, stale cuts, incomplete identity, and damaged files on existing sites.

The migration does not delete image files and does not alter the database schema.

## Intentionally conservative behavior

An empty/omitted image payload is still not interpreted as a destructive “clear every image” instruction because the current upstream contract does not reliably distinguish a lightweight payload from an explicit empty desired state.

Shared Media integrity remains based on the worker manifest's current contract (paths, revision/profile, bytes when declared, MIME and dimensions). A cryptographic content hash can only become mandatory if the writer manifest contract exposes one consistently.

## Database

No database schema migration is required.
