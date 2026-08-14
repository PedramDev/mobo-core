# Mobo Core 10.33.16 — Automatic Stage 7 Convergence

Stage 7 (reference migration + safe deletion of replaced legacy attachments) is now fully automated by the existing Cron/Self Runner workflow.

## What changed

- A single completed Stage 7 pass is no longer treated as final when it made progress.
- If a pass migrates references or deletes any attachment, the next pass is scheduled automatically.
- Stage 7 converges only after a full pass produces **zero new safe progress**.
- Safety-blocked attachments remain intact and are reported instead of forcing repeated manual clicks.
- Existing Stage 1-6 state and the current Stage 7 cursor are preserved during upgrade.
- Sites incorrectly marked `completed` by 10.33.15 are automatically reopened directly at Stage 7 when safe deletion is enabled and work remains.
- The duplicate `failed` counter increment in Stage 7 was fixed.
- The admin workflow no longer asks for a Stage 6 rescan after every Stage 7 pass.

## Expected behavior

Once the administrator has enabled **Delete old attachments after safe replacement**, Stage 7 should continue in bounded Cron/Self Runner batches without further clicks. It may take multiple passes over remaining legacy attachments. The workflow advances only after a complete pass makes no additional safe changes.
