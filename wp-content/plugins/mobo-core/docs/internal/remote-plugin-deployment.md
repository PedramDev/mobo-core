# Portal-Driven Remote Plugin Deployment — Mobo Core 10.31.84

## Bootstrap requirement

Remote deployment endpoints exist from Mobo Core `10.31.82`. The safe global Upgrade Barrier is introduced in `10.31.84`.

- Sites older than `10.31.82` require one manual bootstrap installation.
- The first hop from `10.31.82` or `10.31.83` to `10.31.84` is allowed only after Portal observes the legacy cron and Sync/Repair state as idle twice.
- Every upgrade starting from `10.31.84` uses the full barrier and graceful-drain protocol.

## Endpoints

- `GET /wp-json/mobo-core/v1/upgrade/status`
- `POST /wp-json/mobo-core/v1/upgrade/apply`

Both endpoints are installation-global and require the enabled Default Connection's `X-SEC` (the Installation Controller). A non-default Source credential cannot inspect or mutate plugin-wide upgrade state. The apply request also requires an HMAC-SHA256 deployment signature created by Portal.

## Safe upgrade sequence

1. Download and validate the complete package before pausing local work.
2. Acquire the exclusive remote-upgrade lease.
3. Activate `plugin_upgrade_barrier`.
4. Reject new runtime locks for Sync, Repair, reconciliation and every queue family.
5. Allow existing workers to stop at their next safe item/stage boundary.
6. Observe all runtime leases, including product-level writes, until the site is idle.
7. If the site remains busy past the configured drain timeout, return HTTP `423` and leave the filesystem untouched.
8. Create a local backup, replace the plugin, and verify the target version on disk.
9. Release the barrier and kick the existing shared runner so preserved cursors and queues continue.

Live locks are never force-released by the updater.

## Preserved state

The updater does not cancel or reset:

- manual Sync or Repair state;
- product/variation cursors;
- reconciliation state;
- webhook/image/reprice/recategorize queues;
- pending Desired State repair work.

Only temporary pause metadata is added to `mobo_core_sync_state`, then removed after the barrier is released.

## Drain configuration

WordPress option:

`mobo_core_upgrade_drain_timeout_seconds`

Default: `120` seconds. Allowed range: `15` to `300` seconds.

## Package validation

Before changing the active plugin directory, Mobo Core verifies:

1. deployment timestamp and HMAC signature;
2. expected currently installed version;
3. target version is newer;
4. HTTPS Portal package URL policy (`http://` is accepted only in explicit `WP_DEBUG` development mode);
5. short-lived package token;
6. complete ZIP SHA-256;
7. plugin header version;
8. required updater, coordinator, REST and migration files;
9. every packaged file against `mobo-core-manifest.json`;
10. no untracked file exists.

## Installation and rollback

The current plugin directory is copied to:

`wp-content/uploads/mobo-core/upgrade-backups/latest/mobo-core`

If installation fails or the installed header does not report the requested version, Mobo Core attempts to restore this backup before returning failure.

## UX47-UX49 integrity/recovery hardening

Current release artifacts add three additional deployment guarantees:

- Build identity is verified from the actual installed files, not only from hashes declared by `mobo-core-manifest.json`. A same-version package is considered already current only when the live tree itself verifies.
- The rollback snapshot is first created in pending storage and verified against both the release manifest and a deterministic whole-tree digest. The old trusted snapshot is not rotated until the new snapshot is complete. Restore verifies the backup before deleting the live directory and verifies the restored bytes again afterward.
- A request that dies during a transient upgrade stage is detectable after its lease/barrier expires. If the target version is already fully installed and byte-verified, status can report `completed-recovered`; otherwise it reports an interrupted recovery mode. A damaged active tree cannot overwrite an existing verified same-version rollback snapshot during retry.

Because PHP constants are loaded before filesystem replacement, post-install verification always uses the signed/requested target version explicitly; it never compares a newly installed manifest against the old in-memory plugin version constant.
