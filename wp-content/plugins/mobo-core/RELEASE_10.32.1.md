# Mobo Core 10.32.1 — Fatal-Safe Storage Health and Restricted Reference Refresh

Release date: 2026-07-31

## Storage-health hardening

- Account-quota integrations are isolated by a `Throwable` boundary.
- The real uploads write probe is isolated independently and performs best-effort cleanup even when an unexpected error interrupts the probe.
- PHP filesystem-capacity functions are isolated independently.
- Final storage-report composition has a last-resort `Throwable` boundary.
- Failures return stable `unavailable` values instead of causing the Site Health endpoint to return a fatal error or HTTP 500.
- Exception messages and integration internals are not returned in fallback health output.

## Portal reference-data API contract

- `get-address-mapping` no longer receives or sends a public `force` query parameter.
- `get-mobo-shipping-methods` no longer receives or sends a public `force` query parameter.
- A manual refresh in WordPress may bypass only the plugin's local refresh cadence. It cannot force Portal to rebuild central reference data.
- Central force rebuild is reserved for explicit administrator POST actions in the Portal UI.

## Compatibility

- No WordPress database migration is required.
- Existing Site Health JSON fields are retained.
- Shared Media, image recovery, Webhook Queue, Sync, Repair, Remote Upgrade, and Upgrade Barrier behavior are unchanged.
- The configured public Portal address remains `http://mobo.codeya.ir`.
