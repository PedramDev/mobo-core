# Mobo Core 10.33.20

## Image refresh / cleanup reliability

- Removed the redundant `mobo_core_image_refresh_enabled` requirement from queue processing. Starting the automatic cycle or explicitly running Stage 3 is the execution intent.
- WebP subsize generation/verification and guarded leftover cleanup are safe invariants and are always enabled.
- Orphan-family discovery now recognizes stale WordPress filename-collision WebPs (`name-1.webp` ... `name-N.webp`) using the current Mobo attachment and its persisted `mobo_source_url` as trusted family identity.
- The current WebP attachment and all paths registered in its WordPress metadata are excluded before cleanup candidates are built. Other registered or database-referenced files still block deletion.
- Partial deletion failures store the exact remaining file paths with `fileWritable` and `parentWritable` diagnostics. Failed families are actionable again on the next approved delete attempt.
- Upgrading to 10.33.20 reopens only the orphan-family scan state so the new collision detection can find leftovers. The migration itself deletes no media files.

## Safety

Destructive gates are unchanged: old attachment deletion remains administrator-controlled, and orphan-file deletion still requires the Stage 9 approval gate.
