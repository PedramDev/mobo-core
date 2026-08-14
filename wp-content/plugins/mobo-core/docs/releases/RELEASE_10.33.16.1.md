# Mobo Core 10.33.16.1 — Installation Safety Hotfix

This hotfix preserves the automatic multi-pass Stage 7 behavior from 10.33.16 and fixes two installation/bootstrap compatibility issues.

## Fixes

- `wp_mobo_sync_events.entity_lookup` now uses a bounded `entity_guid(120)` index prefix so the composite key stays within old MySQL/MariaDB 1000-byte/767-byte index limits under `utf8mb4`.
- Stage 7 upgrade resume no longer calls `rest_url()` during `plugins_loaded`. The migration stores a one-shot pending flag and dispatches the Self Runner after WordPress `init`.
- `Mobo_Core_Self_Runner::kick()` is fail-safe during early bootstrap and will never attempt REST URL generation before `init`.

No Stage 1-6 image refresh state is reset by this hotfix.
