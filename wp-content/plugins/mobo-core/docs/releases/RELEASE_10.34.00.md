# MoboCore 10.34.00 — Shared-host-safe migration hardening

## Goal

Make the 10.33.92–10.33.99 storage cutover safe for ordinary shared-hosting customers who have no SSH/WP-CLI access and whose database account cannot read `information_schema`.

## Runtime contract

- Plugin migration is driven by ordinary WordPress requests while `mobo_core_db_version` is behind the plugin version.
- The migration path itself requires no WP-CLI command, shell process, OS cron, MySQL advisory lock, `performance_schema`, or `information_schema` access.
- Schema discovery/verification uses `SHOW COLUMNS` and `SHOW INDEX` against Mobo-owned tables only.
- A short-lived option-backed mutex ensures only one web request advances migration at a time; crashed requests leave a 120-second stale lease that later traffic can reclaim.
- Terminal Product/Variation metadata migrations remain resumable and fail-closed. Batch sizes are deliberately conservative for shared-host PHP limits.

## Shared-host batch limits

- Product and category legacy-map seeding: 100 objects/request.
- Custom-storage backfill from Product Map: 100 objects/request.
- Operational/identity proof: 100 objects/request.
- Operational/identity physical cleanup: 200 postmeta rows/request.
- Generic entity-meta copy: 100 rows/request.
- Generic entity-meta physical cleanup / stale-mirror drain: 200 rows/request.
- Large-runtime sync-health option migration: 100 options/request.
- Order-recovery option migration: 200 options/request.

## Failure behavior

If the shared-host database user lacks CREATE/ALTER/INDEX privileges required for the plugin's own tables, migration does not claim success. The old DB version remains in place and Health/migration diagnostics retain the concrete schema failure for the site owner/host to resolve. Existing storefront data is not deleted merely because an index/table repair failed.
