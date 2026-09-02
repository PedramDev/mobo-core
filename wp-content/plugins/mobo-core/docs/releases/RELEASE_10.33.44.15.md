# Mobo Core 10.33.44.15 — cross-policy regression hardening

## Scope

This patch follows the shared-policy consolidation in 10.33.44.14 with end-to-end regression coverage and closes two Repair bypasses discovered by those tests. The goal is not further broad refactoring; it is to prove that every runtime consumer actually obeys the shared policies and that stale/partial work cannot bypass them.

## Excluded-product Repair hardening

Products listed in `mobo_core_excluded_product_urls` are now fenced in all Repair integrity mutators:

- Trash restoration;
- duplicate Portal variation identity repair/quarantine;
- duplicate `_price` / `_regular_price` / `_sale_price` cleanup.

Repair records excluded groups/objects diagnostically and leaves their WooCommerce state unchanged. The shared URL policy remains path-exact, so `/0338` and `/338` are distinct identities.

The obsolete fallback option parser in Product Sync was also removed; Product Sync delegates URL normalization/list ownership entirely to `Mobo_Core_Product_Exclusions`.

## Regression coverage

Deep Test Suite `10.33.44.15-r7.2` adds eight contract tests and eight local mutation/runtime tests covering:

- Product exclusion across ProductUpdated, UpdateVariant, Repair, Reprice, Recategorize, Missing-Image Recovery, Image Queue/Refresh and Parent Finalize;
- ordering aliases (`SourceRevision`, `eventVersion`, `revision`, `entityVersion`, `version`) and stale-before-mutation fences;
- absent/null/explicit-empty/valid/malformed/partially-invalid desired-state matrices for categories, images, attributes, price, compare price and stock;
- category runtime/UI fallback consistency, mapping-required behavior and explicit `categories=[]`;
- money/currency boundaries, zero-vs-null behavior, snake-case `compare_price` and partial money preservation;
- one Order Submission truth table across all runtime consumers;
- exact image lease/claim ownership after worker A expires and worker B reclaims;
- full Repair state preservation for excluded products.

A single-source contract also fails if new runtime code directly reintroduces raw excluded-product/order-submission option reads or duplicate shipping-money conversion filters.

## Validation

The package is intended to be validated on disposable WAMP WordPress sites with `RUN-ALL-MOBO-TESTS.cmd`, followed separately by `RUN-ALL-MOBO-TESTS-WITH-REAL-CRON.cmd` for real-cron/chaos coverage. Standalone contract/static validation can run outside WordPress, but mutation tests require the real WooCommerce/WP runtime.
