# Mobo Core 10.33.22

## Image storage and recovery hardening

This release closes multiple storage/state races and recovery blind spots found during a focused audit of the Mobo image pipeline.

### 1. Per-image import identity lock

The normal Image Queue and the legacy Image Refresh queue have independent worker locks and can run at the same time. Previously both paths could check for an attachment before either one had completed its import, then both sideload the same remote image. WordPress would resolve the filename collision by creating files such as `image-1.webp`, `image-2.webp`, and so on.

10.33.22 adds short-lived locks derived from both the remote `image_guid` and the source URL. The attachment lookup is repeated with request-local lookup caches cleared after the locks are acquired. This covers both the same GUID arriving through two workers and the same physical source URL being referenced by different remote image rows. Only conflicting imports are serialized; unrelated images remain independent.

Shared Media refresh now uses this same locked path as normal image imports. It still preserves the refresh invariant that the superseded local attachment is not converted in place.

### 2. Broken attachment rows are no longer reused

An attachment record is now reusable only when its attached file:

- exists;
- is non-empty;
- has a real image MIME signature;
- and, for a WebP source, is actually WebP content.

This closes the case where `_wp_attached_file` still existed in the database after the file had been removed or corrupted. Such rows previously prevented the image queue from downloading a healthy replacement.

The reuse check also respects an existing `mobo_source_url`: when the remote URL changes for the same image GUID, the old physical file is no longer silently relabeled as the new source. A fresh import is required. Legacy GUID-only rows with no stored source URL remain eligible for recovery.

### 3. Physical-file recovery is discoverable

Missing Image Recovery now cursor-scans every known local Mobo product and applies the authoritative filesystem/MIME check at runtime. It no longer relies on SQL metadata alone, so a product whose `_wp_attached_file` value exists but points to a deleted, zero-byte or invalid file is discoverable by Repair and the automatic image-refresh cycle.

In addition, periodic bounded maintenance audits `done` Image Queue rows. Missing/corrupt/source-mismatched attachments are moved back to `pending` with a clean retry state. This protects both featured and gallery rows while their durable queue state is retained.

### 4. Desired image queue pruning

When Mobo sends a new non-empty image list for a product, obsolete image-queue rows that are no longer part of that list are removed. This prevents stale `done` rows from keeping deleted remote images in the WooCommerce gallery.

Only Mobo queue state is pruned. WordPress attachments/files are deliberately not deleted here because the same attachment may be reused by another product.

### 5. Shared Media manifest validation before mutation

Shared Media now builds and validates the complete attachment metadata before `wp_insert_attachment()` or `wp_update_post()` is called. A valid JSON manifest that is nevertheless incomplete (for example missing original dimensions) therefore cannot create a half-initialized attachment or partially convert a local attachment.

## Intentionally unchanged

An absent/empty image list is not treated as a destructive command. Some lightweight payloads omit image data, so clearing a product gallery from an empty array would be unsafe until the API contract explicitly distinguishes “images omitted” from “the desired image list is intentionally empty.”

## Database

No database schema migration is required.
