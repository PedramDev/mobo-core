# Mobo Core 10.33.17.3

## Shared-media wait-state reliability

- Shared-media manifest-not-ready retries remain durable `pending` queue rows, but are now counted as `deferred` instead of `failed` in the worker result.
- Runtime Diagnostics and the image Circuit Breaker therefore no longer interpret normal shared-writer lag as an image worker failure streak.
- Network/download/import errors remain operational failures and still participate in failure isolation.
- Maintenance now includes trashed, auto-draft and non-product parents in conservative orphan image-queue cleanup after the existing retention period.
- No database schema migration and no Portal change are required.
