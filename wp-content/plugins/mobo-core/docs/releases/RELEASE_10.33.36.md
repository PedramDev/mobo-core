# Mobo Core 10.33.36 — Recovery state self-heal

- Fixes a recovery scheduling edge case where a missing `mobo_core_product_recovery_state` option could leave `pending=1` without a generation ID or cursor.
- `schedule()` now rebuilds missing, empty, status-less, or terminal recovery state before arming pending.
- `schedule_followup()` no longer treats `pending + empty state` as an active generation.
- Upgrades from versions before 10.33.36 schedule one bounded site-scoped recovery state self-heal; an already-running generation receives it as a serialized follow-up.
- No change to `OnlyInStock`; parent products remain append-only and recovery continues through exact GUID desired-state fetches.
