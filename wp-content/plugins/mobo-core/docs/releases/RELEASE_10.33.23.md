# Mobo Core 10.33.23

## Deep image storage and refresh audit

This release is the result of a second, deeper audit of both the normal image-storage pipeline and the legacy Image Refresh workflow. The focus is convergence under concurrency, physical-file integrity, durable desired state, and strict separation between ordinary WordPress uploads and the private read-only Shared Media repository.

### 1. Queue results are now identity-guarded

A worker can spend seconds downloading an image while a newer webhook updates the same image GUID to another source URL. A stale worker must not be allowed to complete the new row with the old file.

Normal Image Queue and Image Refresh transitions now use compare-and-set predicates over the claimed row identity. A stale worker can commit success/failure only when the product, image GUID and exact source URL still match the state it claimed. When a webhook supersedes in-flight work, the old lease is released and the newer desired state becomes eligible immediately.

The enqueue path also preserves an active lease rather than starting a second worker on the same row. Import locks derived from both image GUID and source URL continue to prevent duplicate WordPress collision files.

### 2. Source-hash fast path now proves storage convergence

An unchanged Portal image payload does not prove that WordPress storage is still healthy. A gallery file can be deleted manually, a queue row can be missing, attachment identity can drift, or WooCommerce image order can be changed locally while the stored source hash remains identical.

Before skipping image processing, Mobo Core now verifies the durable queue state, exact image identity/source, attachment existence, real image payload, and the actual WooCommerce featured/gallery order. Any drift re-enters the normal image queue; the fast path itself never deletes or regenerates files.

### 3. Attachment identity is isolated from shared URLs

Two remote image records can temporarily expose the same source URL. URL equality alone is no longer sufficient to adopt an attachment already owned by another image GUID. Existing GUID metadata is authoritative; legacy attachments with no GUID remain adoptable. UUID-style GUID comparisons are treated case-insensitively.

The periodic completed-row audit applies the same identity rule, so historical cross-GUID attachment corruption is automatically scheduled for repair. Duplicate image GUIDs inside one malformed payload are also treated as ambiguous input and cannot authorize destructive queue pruning.

### 4. Refresh survives source changes during replacement

A narrow race existed when Refresh replaced the old attachment and, before its queue commit, a newer webhook superseded the same GUID with another source. The stale worker was correctly rejected, but the next worker could see that the original legacy attachment was no longer used and incorrectly mark the newer job skipped.

10.33.23 detects the in-use replacement carrying the same image GUID and continues from that attachment toward the latest source. This makes refresh convergence monotonic even when the image changes while a refresh is in flight.

Historical `done` refresh rows are also revalidated. A completed row is trusted only when the replacement still exists, is a real WebP, carries the exact GUID/source identity, is used by the product, and the superseded attachment is no longer used.

### 5. WebP linkage requires final storage readiness

An attachment ID is not treated as a successful image commit by itself. Before normal product linkage or refresh completion, Mobo Core validates the physical original and the required registered WebP subsize state. Missing/corrupt cuts move the durable row back through the repair path rather than silently disappearing from the gallery.

Subsize mutation has a per-attachment lock shared by the normal Image Queue and Image Refresh. This prevents two independent workers from writing the same attachment metadata/cuts concurrently.

Completed normal Image Queue rows are retained as durable desired state rather than age-deleted. They remain available for future physical-file repair and exact gallery-order reconstruction.

### 6. Shared Media is strict during repository outages

Shared Media now distinguishes **configured** from **currently mounted/readable**. If a site is configured for the private shared repository and that mount is temporarily unavailable, the site does not silently switch to private uploads. Work is deferred unless `MOBO_CORE_SHARED_MEDIA_FALLBACK_TO_DOWNLOAD` is explicitly enabled.

Public Shared Media URLs can still be derived from previously committed safe relative metadata during a local mount outage.

### 7. Shared manifest import is commit-verified

Before a Shared attachment is inserted or converted, every file advertised by the committed worker manifest is checked for safe repository path, readability, non-zero bytes, declared byte count when present, real MIME, and exact dimensions.

After WordPress post/meta writes, the resulting attachment is read back again. `_wp_attached_file`, `_wp_attachment_metadata`, manifest revision/profile, identity aliases, and the physical family must agree before the conversion is considered committed or an older local copy is deleted. This closes the partial-database-write window as far as WordPress' non-transactional postmeta model allows.

Shared metadata repair is metadata-only: site WordPress instances never run local thumbnail generation against the worker-owned read-only repository.

### 8. Shared physical files are never site-cleanup targets

Per-site orphan cleanup skips Shared attachments regardless of current mount availability. The refresh deletion path also refuses to call `wp_delete_attachment(..., true)` on an old Shared attachment, preventing a site from asking WordPress to unlink centrally owned media files.

For old local attachments that are safe to remove, existing multi-product/reference identity checks remain in place.

### 9. Retry behavior

Network, storage, temporary manifest, and other operational refresh failures remain retryable with a bounded long-term backoff after the initial short retry window. Starting the automatic refresh cycle also revives legacy failed rows.

## Intentionally unchanged

An absent/empty image list is still not interpreted as a destructive “clear every product image” instruction because the current payload contract does not reliably distinguish an omitted/lightweight image field from an explicit empty desired state.

Shared Media also does not invent a required cut count outside the worker manifest/profile contract. The manifest remains the authoritative declaration of the centrally generated family.

## Database

No database schema migration is required.
