# Mobo Core 10.9.0 Upgrade Notes

## Phase 8: cursor-safe initial sync

This release keeps the legacy page-number sync path, but adds stable cursor support for fresh-site sync.

### Added

- Product sync can request `UseCursor=true` and send the last processed `Cursor` to Portal.
- Variant sync can request `UseCursor=true` and send the last processed `Cursor` to Portal.
- Manual sync state now stores:
  - `productCursor`
  - `productCursorMode`
  - `productCursorSupported`
  - `currentVariantCursor`
- Admin start-sync now kicks the local self-runner, so initial sync can continue on the customer's host without a central runner.

### Backward compatibility

If Portal does not return `cursorMode`, Mobo Core automatically falls back to the old page-number behavior.

Existing customer installs are safe:

- Existing sync state is parsed with defaults.
- Existing product/variant maps remain unchanged.
- Existing queues remain unchanged.
- No destructive migration is performed.

### New options

- `mobo_core_product_cursor_sync_enabled` default: `1`
- `mobo_core_variant_cursor_sync_enabled` default: `1`

These are visible in **Mobo Core → Queue / Processing**.
