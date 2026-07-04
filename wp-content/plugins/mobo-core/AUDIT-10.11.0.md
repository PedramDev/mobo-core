# Mobo Core audit summary - 10.11.0

## Safe-upgrade checks

- Activation is idempotent.
- Existing options are not overwritten by default insertion.
- Legacy webhook JSON files are migrated to uploads and are not deleted.
- New queue/map/image tables are created if possible and are not dropped on uninstall.
- Fallback paths remain enabled for older data.

## Risk areas to monitor

- Weak shared hosting can still block loopback requests; use real cron fallback only for those sites.
- Image sync can be slow on low-memory hosts; reduce `mobo_core_images_per_run` to `1` and keep image queue enabled.
- Category mapping required mode should not be enabled globally until mappings are reviewed.
- Checkout validation remains disabled until the external validation endpoint is finalized.

## Production defaults confirmed

- Full payload webhook remains supported.
- Lightweight webhook pull is supported but controlled from Portal.
- Product deletion is not performed.
- Missing variants become out of stock.
