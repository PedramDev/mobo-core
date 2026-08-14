# Mobo Core 10.33.11 — Bounded Replaced-Attachment Audit

## Stage 6 performance and safety

- Stage 6 no longer performs a site-wide content/metadata/options reference audit for every legacy attachment.
- It verifies the old -> WebP replacement and WebP subsize health, then marks the attachment for Stage 7 final migration/audit.
- Stage 6 uses at most 50 attachments per request and a soft request time budget.
- Running progress is persisted before and during the batch so the admin UI no longer remains at 0% while a long batch is executing.

## Cursor safety

- Stage 6/7 cursors now advance only after an attachment has actually completed processing.
- A timeout or terminated PHP request no longer skips the unprocessed remainder of a prefetched batch.
- Upgrading from 10.33.10 resets only Stage 6/7 cursors/results and destructive approval so any possibly skipped attachments are rechecked. Existing WebP replacements and queues are preserved.

## Stage 7 database work

- Stage 7 is limited to at most 5 legacy attachments per request.
- The redundant full reference scan before migration was removed. References are migrated first, then one authoritative final audit is performed before deletion.
- post_content candidate lookup no longer begins with a broad `%attachmentId%` search; it uses structured attachment/JSON/serialized/URL tokens.
