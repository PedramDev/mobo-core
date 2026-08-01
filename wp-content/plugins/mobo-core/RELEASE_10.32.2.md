# Mobo Core 10.32.2 — Same-Name JPG/JPEG Cleanup During Image Refresh

Release date: 2026-08-01

## Image Refresh cleanup

- After a replacement WebP has been downloaded or resolved, validated, checked for required subsizes, and attached to the WooCommerce product, Image Refresh removes local `.jpg` and `.jpeg` files with the same base filename.
- The cleanup includes derivative names such as `name-300x300.jpg`, `name-scaled.jpeg`, and other `name-*.jpg/jpeg` files.
- Both the new WebP directory and the previous attachment directory are checked when they are located inside WordPress uploads.
- Matching is intentionally filename-based, as requested. It does not require GUID comparison or database-reference analysis.
- `.png`, `.webp`, and files with a different base name are left unchanged.

## Failure handling

- Cleanup runs only after the WebP replacement has completed successfully.
- Directory scanning and file deletion are protected by `Throwable` boundaries.
- A failed delete is counted in the Image Refresh result and does not cause a fatal error or invalidate the completed replacement.

## Compatibility

- No WordPress database migration is required.
- Shared Media, Image Queue recovery, Webhook Queue, Sync, Repair, Remote Upgrade, and Upgrade Barrier behavior are unchanged.
- The configured Portal URL remains `http://mobo.codeya.ir`.
