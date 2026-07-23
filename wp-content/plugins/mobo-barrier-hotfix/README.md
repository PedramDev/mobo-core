# Mobo Core barrier telemetry hotfix

This package contains a source patch for the current Mobo Core release.

## What it changes

- Keeps the upgrade barrier owned through drain, backup, install and verification.
- Records explicit barrier stages: `draining`, `drained`, `backing-up`, `installing`, `verifying`, `completed` or `failed`.
- Stores a persistent audit in the WordPress option:

  `mobo_core_last_upgrade_barrier_audit`

- Exposes the audit in:

  `GET /wp-json/mobo-core/v1/upgrade/status`

  under `upgradeBarrier.lastAudit`.

## Apply to current source

Apply `mobo-core-barrier-telemetry.patch` to the latest Mobo Core source, then bump the plugin version and regenerate `mobo-core-manifest.json`.

Do not publish the two reference PHP files as a complete plugin package unless they have first been merged with the current source. They were generated from the last source package available to the patch builder and are included for review/diffing.

## Verification after deployment

Read one endpoint only, so the stage and barrier state come from the same WordPress request:

```powershell
$state = Invoke-RestMethod `
  -Uri "http://test1.local/wp-json/mobo-core/v1/upgrade/status" `
  -Headers @{ "X-SEC" = $sec } `
  -TimeoutSec 10

$state | ConvertTo-Json -Depth 12
```

After completion, verify:

- `status = completed`
- `upgradeBarrier.active = false`
- `upgradeBarrier.lastAudit.result = completed`
- `drainCompletedAt > 0`
- `installStartedAt >= drainCompletedAt`
- `releasedAt >= installStartedAt`
