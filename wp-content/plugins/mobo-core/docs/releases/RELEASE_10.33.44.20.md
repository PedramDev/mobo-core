# Mobo Core 10.33.44.20 — Product-level pricing override

- Adds a durable parent-level custom percentage pricing override for Mobo products.
- The override is available from WooCommerce → Products and applies to a Simple product or all live Mobo-owned Variations.
- Custom parent pricing has precedence over the legacy per-Variation fixed add-on while active; Global reset restores the previous policy chain.
- Product Sync, Variant Sync, Reprice and Repair paths all use the centralized price calculator, so later source-price changes keep the override.
- Shared product locking prevents races with webhook/manual sync. Busy locks and transient failures retain a generation-token pending request and schedule a retry.
- Targeted repricing preflights the entire Mobo family before mutation and snapshots current prices for rollback on save/postcondition failure.
- Excluded products, non-Mobo products, manual Variations and ambiguous Variant identity fail closed or remain untouched as appropriate.
- Admin UI exposes custom/pending/error badges and uses a nonce/capability protected AJAX Save & Reprice flow.

Deep Test Suite target: `10.33.44.20-r7.9`.
