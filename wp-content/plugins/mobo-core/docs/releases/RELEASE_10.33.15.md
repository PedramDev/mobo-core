# Mobo Core 10.33.15 — Non-blocking Stage 7

## Image Refresh

- Stage 7 now treats an undeletable legacy attachment as an isolated safety block instead of a fatal workflow error.
- The Stage 7 cursor continues through all other attachments and reports `blocked` separately from operational `errors`.
- A completed Stage 7 is accepted as the final result for the current Image Refresh cycle; blocked items do not immediately force Stage 6/7 to restart.
- Remaining post content, metadata, term metadata, user metadata and supported options are structurally verified after SQL prefiltering. This avoids false positives from unrelated generic JSON `id` fields.
- Stage 7 issue details include detected reference locations such as `postmeta#POST_ID:key`, `post_content#POST_ID:type`, `termmeta#TERM_ID:key`, `usermeta#USER_ID:key`, `option:name`, or product reference counts.

## Upgrade recovery

If 10.33.14 stopped automation with status `delete-old-failed`, upgrading to 10.33.15 automatically resumes the existing Stage 7 cursor. Manual pauses and unrelated errors are not changed.
