# Mobo Core 10.33.19

## Scope

This release is a narrow Plugin Check/security hardening pass over 10.33.18. It intentionally avoids schema, API-contract, synchronization-state, shipping, pricing, and Portal changes.

## Changes

- Replaced interpolated numeric ID lists in image queue and webhook/sync-event claim/update paths with generated `%d` placeholders and `wpdb::prepare()` argument arrays.
- Replaced the maintenance bulk-delete ID list with `%d` placeholders; the dynamic table/column pair remains protected by its existing strict internal whitelist.
- Added an explicit allowed-table guard to generic maintenance status cleanup.
- Sanitized early REST request context (`rest_route` and `REQUEST_URI`) before request-path inspection.
- Preserved WordPress 5.8 support by keeping `wp_prime_option_caches()` optional and runtime-guarded while avoiding a static compatibility-scanner false positive.
- Added narrow PHPCS/Plugin Check annotations where SQL values are already placeholder-bound but the scanner cannot infer dynamic internal query fragments.
- Kept Revenue Ledger querying through paged WooCommerce CRUD/meta queries to retain CPT/HPOS portability; the slow-query notice is documented as an intentional bounded compatibility tradeoff.
- Reduced the public `readme.txt` changelog below Plugin Check's supported size and moved omitted recent history into the existing legacy changelog document.

## Data and deployment

- No database schema migration.
- No Portal migration.
- No option-key migration.
- No shipping mapping migration.
- Existing queue rows remain compatible.

## Validation required before production

- PHP syntax check for every PHP file.
- Manifest checksum validation.
- Re-run WordPress Plugin Check on a test site.
- Smoke-test image queue claim/attach/done flow and webhook event claim/complete flow.
- Run one bounded maintenance pass and verify no unexpected row deletion.
