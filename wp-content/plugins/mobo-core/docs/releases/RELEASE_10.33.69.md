# Mobo Core 10.33.69 — Legacy-first Custom Storage Dual Write

## Scope

This release activates phase 2 of the custom-storage rollout for Product and Variation operational state only. Legacy Product Map and WordPress metadata remain authoritative. After a successful legacy mapping commit, Mobo mirrors identity, ordering revision, source hash and incomplete/applied state into `mobo_products` / `mobo_variants`.

## Safety boundary

- Default storage mode remains `legacy`.
- `dual-write` enables the mirror.
- Requests for `shadow-read`, `custom-primary` or `custom-only` are capability-capped to `dual-write` in this release.
- No custom table is used as a runtime read source.
- A custom mirror failure is recorded in `mobo_core_storage_dual_write_last_error*` telemetry and does not fail a successful legacy sync.
- Schema readiness still fails closed to effective `legacy`.

## Mirrored Product state

Product rows mirror Woo/Portal identity, conservative desired/applied revision boundaries, product source hash and `mobo_sync_incomplete` state. The applied revision is intentionally conservative across ProductUpdated and UpdateVariant scopes so the custom row cannot claim convergence while one required legacy scope is behind.

## Mirrored Variation state

Variation rows mirror parent/product identity, Woo/Portal variation IDs, variant ordering watermarks, source hash and incomplete/applied state.

## Not in scope

Webhook queue, images, orders, scheduler/runtime state, shadow-read parity and custom-primary reads remain on their existing persistence paths.
