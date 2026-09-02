# Mobo Core 10.33.44.8 — image storage preflight and collision cleanup

## Stage-zero cleanup

- Image optimization starts with small cleanup batches before any download, conversion or subsize generation.
- Numeric files such as `source-10.webp` and `source-99.webp` are deleted only when all safety evidence agrees: the attachment is marked incomplete, its authoritative Mobo source proves the numeric collision name, it is not Shared Media, no unexpired image worker owns it, and no product/content/meta/option/path reference remains.
- Existing unregistered orphan-family candidates are also deleted between scan batches so a constrained disk can begin recovering immediately.

## Storage and queue safety

- Every local image mutation performs a fresh uploads reserve check and a real create/write/delete probe, catching filesystem quota or inode exhaustion that a writable-directory check alone misses.
- Normal and refresh queues checkpoint their attempt counter before entering sideload/editor code. A PHP timeout or fatal therefore advances the row instead of retrying forever with an unchanged counter.
- An interrupted incomplete normal import receives the existing exact third-attempt quarantine/fresh-import escape hatch. Refresh rows beyond their bounded attempt budget become terminally quarantined rather than blocking the workflow indefinitely.
- Heavy image-library scan batches are capped at 50 during upgrade for predictable shared-host runner checkpoints.

## Product-state isolation

- Image optimization never starts, retries or cancels Product Repair or Reconciliation.
- A completed Product Repair remains a safety prerequisite for destructive media cleanup, but running it is a separate administrator decision.
- Reconciliation and automatic Product Recovery remain disabled by the build.

## Deployment

- No schema change or manual SQL is required.
- The normal version migration resets only rescanable image-cleanup state, retains deletion history, and applies the safer scan cap automatically.
