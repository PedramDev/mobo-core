# Mobo Core 10.18.0

## Waiting for Portal resume

Network/timeout failures during manual sync no longer turn the sync into a hard failure after max transient retries.

When Portal is unavailable, the plugin stores:

- `status = waiting_for_portal`
- current product page/cursor
- current variant page/cursor
- current product state
- next retry time

The admin dashboard can resume from the last saved point without resetting the sync.
