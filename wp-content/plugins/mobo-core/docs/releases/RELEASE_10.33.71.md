# Mobo Core 10.33.71 — Shadow Read Parity Verification

## Scope

This release adds phase 4 of the custom-storage rollout. Legacy Product Map/postmeta remains authoritative. Custom Product/Variation rows are read only as a side-channel and compared against a canonical legacy snapshot. No custom row can change a returned Product/Variation ID, post status, sync decision, or customer-facing result.

## Coverage

Shadow parity is attached to the central `Mobo_Core_Product_Map` lookup boundary:

- single Product GUID lookup;
- single Variation GUID lookup;
- Product/Variation status lookup;
- bulk Variation GUID priming used by UpdateVariant pages.

Bulk Variation shadow reads are set-based. They do not issue one custom-table query per variation.

## Comparison contract

Product parity checks local/Portal identity, desired/applied revision, desired/applied source hash and sync status. Variation parity additionally checks parent Product GUID and parent Woo Product ID. Missing Legacy or Custom rows are explicit mismatch reasons.

## Load and failure boundary

- Maximum comparisons per request: 32.
- The same entity GUID is compared at most once per request.
- Telemetry is buffered in memory and flushed once through the `mobo_runtime` row `storage_parity_v1`.
- Mismatch telemetry never throws into or changes the Legacy read path.
- Default mode remains `legacy`; `dual-write` behavior is unchanged.

## Promotion boundary

`shadow-read` is the highest effective mode in 10.33.71. Requests for `custom-primary` or `custom-only` are safely capped to `shadow-read`; authoritative Custom Storage reads are not implemented in this release.
