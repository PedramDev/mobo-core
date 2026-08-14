# Mobo Core 10.33.13 — Persistent Safe Delete Preference

## What changed

- `mobo_core_image_refresh_delete_old` is now the single authoritative consent for Stage 7.
- Once the administrator enables **حذف پیوست قدیمی بعد از جایگزینی امن**, Mobo Core does not turn it off when automation starts, pauses, hits an error, invalidates verification state, resets workflow progress, or completes a cycle.
- Stage 7 no longer asks for a separate one-time delete-old approval. If the persistent setting is on and Stage 6 finds actionable replaced attachments, reference migration and safe deletion continue automatically in bounded batches.
- The setting can be edited while automation is active. Saving it while Stage 7 is waiting kicks Self Runner so the workflow can continue without another approval form.
- The orphan-file cleanup stage still keeps its separate one-time destructive approval.

## Upgrade behavior

Upgrading from 10.33.12 preserves the current value of `mobo_core_image_refresh_delete_old`. The migration only removes obsolete one-time approval state and normalizes a stale `waiting-delete-old-approval` automation result. It never enables deletion automatically when the administrator had it disabled.
