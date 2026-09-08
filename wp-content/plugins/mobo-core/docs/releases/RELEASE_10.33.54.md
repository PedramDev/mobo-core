# MoboCore 10.33.54

Targeted follow-up to 10.33.53.

## Fix

`wp_clear_scheduled_hook( $hook )` matches the supplied argument set. The 10.33.53 ownership migration called it without arguments, so legacy Sync Health events that had been scheduled with non-empty args could remain in the WordPress `cron` option.

10.33.54 performs one bounded migration over the cron option and removes only these retired hooks, regardless of their event args:

- `mobo_core_health_snapshot_refresh_v1`
- `mobo_core_health_snapshot_refresh_v2`
- `mobo_core_sync_health_snapshot_refresh_v1`
- `mobo_core_sync_health_snapshot_refresh_v2`

All unrelated cron timestamps, hooks and event payloads are preserved.

The 10.33.53 real-cron Health ownership and deferred convergence-residue self-heal are unchanged.
