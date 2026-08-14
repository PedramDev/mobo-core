# Mobo Core 10.33.17.6

## Lightweight UpdateVariant parent-context recovery

- Fixes a real webhook bug found by the r23 Deep Surface test suite.
- Four alias lookups in `Mobo_Core_Webhook_Queue::ensure_update_variant_product_context()` incorrectly passed the literal string `productId` as the default value of `get_value()`.
- When a lightweight pulled `UpdateVariant` payload omitted the parent GUID at one layer, the queue could therefore assign `productId` as the parent GUID instead of continuing through the alias/URL fallback chain.
- The four call sites now read `product_guid` and `productId` independently and feed both values into `first_non_empty()`.
- Numeric event mapping, full-payload webhooks, table/file queues, version coalescing and desired-state variation behavior are unchanged.

No database schema or Portal migration is required.
