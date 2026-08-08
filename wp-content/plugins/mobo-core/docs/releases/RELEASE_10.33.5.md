# Mobo Core 10.33.5 — Plugin Check Hardening

- Replaces the standalone `mobo-phpinfo.php` entry point with an authenticated `admin-post.php` diagnostics action and removes full `phpinfo()` disclosure.
- Sanitizes product-category request detection in the cache purger while preserving immediate product invalidation and the deferred 15-minute archive queue.
- Uses WordPress filesystem abstractions for storage probes and remote-upgrade cleanup where appropriate.
- Hardens SQL patterns used by image recovery/maintenance and removes a dynamic metadata count column.
- Normalizes documentation layout and filenames for WordPress distribution checks.

No database migration is required.
