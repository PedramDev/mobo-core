# Mobo Core 10.33.29 — Parent Retention + Automatic Recovery

## Policy

A Mobo parent product is append-only once it has reached WooCommerce. Mobo Core must not physically delete that parent because the source marks it deleted, because an exact lookup returns an empty snapshot, or because the product is absent from a filtered or unfiltered reconciliation catalog.

Authoritative Variation reconciliation is unchanged: stale child variations may still be removed when a complete desired-state snapshot proves they no longer exist.

## Automatic recovery after upgrade

Upgrades from an existing version schedule a one-time recovery automatically. The customer does not need to change `mobo_core_only_in_stock`, run Sync/Repair, or press an admin button.

Recovery evidence is processed in this order:

1. Local append-only Product Ledger, seeded from durable proof that a parent existed on the site: Product Map, `product_guid` postmeta, surviving Image Queue rows, and completed local ProductUpdated sync-event rows.
2. Portal site-scoped delivered webhook history for the licensed domain, exposed by `GET /get-product-recovery-manifest`.

For a missing identity, Mobo Core requests the exact snapshot through `get-products-by-guid`, restores the parent through the normal product desired-state engine, then applies the full authoritative Variation snapshot bundled with the exact product response.

## Runtime safety

- `OnlyInStock` is never modified by recovery.
- Recovery has its own runtime lock and persistent cursor/state.
- Work is bounded in small batches through Real Cron / Self Runner.
- Portal endpoint or transport failures use persistent retry/backoff; recovery remains pending.
- Existing products are detected and skipped rather than duplicated.
- The Product Ledger is not deleted when a live Product Map entry disappears.

## Database

Adds `wp_mobo_product_ledger` through `dbDelta` with a unique `product_guid(150)` index compatible with older utf8mb4 key-length limits.
