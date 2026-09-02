# Mobo Core 10.33.44.11 — retire automatic recovery/reconciliation runtime

- Retires automatic Product Recovery and product-mutating Reconciliation from Cron Runner, fair scheduling, adaptive budget and circuit-breaker execution paths.
- Introduces `Mobo_Core_Sync_Health` as an observational-only health layer backed by the existing `mobo_sync_health` table. It never fetches Portal snapshots or applies desired state.
- Fixes behind-product health transitions:
  - partial successful processing stays `behind`;
  - terminal `ProductUpdated` marks a simple product `synced`;
  - terminal `ProductUpdated` keeps a variable product `behind` until terminal `UpdateVariant` convergence;
  - failures become `failed`, and a later successful terminal retry can converge them again.
- Completed Manual Product Sync now marks the current product `synced` before clearing its checkpoint identity.
- Status-only health updates preserve the last known Portal revision/hash instead of overwriting them with empty values.
- Removes obsolete Reconciliation admin settings/actions and cleans historical Reconciliation/Product Recovery options, cursors, pending markers and locks during upgrade.
- Keeps tiny compatibility facade/tombstone classes and the legacy REST status field to avoid breaking older integrations; these compatibility surfaces cannot schedule or mutate products.
- Keeps Webhook/Product Sync, Reprice, Recategorize, Image queues and manual Repair as the authoritative mutation paths.
