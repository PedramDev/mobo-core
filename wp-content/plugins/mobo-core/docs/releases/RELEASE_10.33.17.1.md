# Mobo Core 10.33.17.1 — Image Refresh Stage 7 Auto-Drain

## Fixed

- Enabling **حذف پیوست قدیمی بعد از جایگزینی امن** at an already-safe Stage 7 now arms the existing Image Refresh automation even when the earlier workflow was driven manually.
- A manual Stage 7 click is only the first bounded slice; if more safe work remains, Cron/Self Runner continues automatically until Stage 7 reaches a complete pass with zero new safe progress.
- Completing Stage 6 manually while delete-old is already enabled automatically hands off to Stage 7.
- Upgrade migration re-arms stores already parked at an actionable Stage 7.

## Safety preserved

- No attachment is deleted unless the verified WebP replacement is valid, required subsizes are healthy, known references are migrated, and the final conservative reference audit allows deletion.
- Blocked attachments remain in place and do not stop unrelated attachments from being processed.
- Existing cursors are preserved; no workflow reset is performed.
- No database schema migration and no Portal change are required.
