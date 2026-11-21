# Mobo Core 10.33.18

> Historical note: the mixed-order partial-submission policy documented in this release was superseded by the current fail-closed policy. Current builds submit only all-Mobo, single-Connection orders and never partially submit mixed orders.


Adds the immutable Revenue Ledger required by Portal Site Health.

- `GET /wp-json/mobo-core/v1/portal/revenue-summary`
- source unit cost frozen before/at Mobo submission
- one immutable record when the WooCommerce order reaches completed
- mixed orders include only Mobo line items
- all-time / last-30-day / recent summary

No WordPress schema migration and no Portal EF migration are required.
