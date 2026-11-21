# Mobo Core 10.33.61 — Durable image-prune uploads boundary

## Context

Mobo Core 10.33.58 moved stale-image physical deletion out of Product/Repair into the durable `mobo_image_prune_queue`. The queue retained GUID ownership checks and two complete bounded cross-store reference-proof passes, but its final `wp_delete_attachment(..., true)` boundary did not independently re-prove that a non-empty attachment file path was inside the current WordPress uploads tree.

Modern WordPress protects filesystem unlinking outside uploads, but `wp_delete_attachment()` can still remove the attachment database row. Mobo's stronger desired-state policy is fail-closed: an attachment whose registered physical path is outside uploads must be retained, not silently converted into a detached external file.

## Hardening

- Immediately before permanent attachment deletion, the prune queue now validates the registered attached-file path.
- Explicit `..` traversal is refused.
- Existing files/symlinks must canonicalize with `realpath()` inside the canonical uploads directory.
- For a missing main file, lexical containment is required and the nearest existing parent directory is canonicalized to detect symlink escapes.
- A non-empty path outside uploads is retained with diagnostic evidence and is never passed to `wp_delete_attachment()`.
- An attachment with no registered file, or with a safely uploads-rooted file that is already missing, remains eligible for database cleanup.

## Upgrade behavior

No database migration, Repair, reset, or manual SQL is required. Existing prune rows continue from their durable cursor/phase. Reprice liveness and runtime-lock hygiene from 10.33.59/10.33.60 are unchanged.
