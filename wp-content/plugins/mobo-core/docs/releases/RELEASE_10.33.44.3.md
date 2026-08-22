# Mobo Core 10.33.44.3 — nullable stock queue recovery

## Stock diagnostics

- `stock: null` keeps its established unlimited-stock contract: WooCommerce quantity management is disabled, quantity is `null`, and status is `instock`.
- A present stock field now clears the obsolete `_mobo_stock_payload_missing` marker.
- Nullable stock clears the obsolete `_mobo_last_api_stock_quantity` value.
- Cleanup also runs on the source-hash/no-op path, so already-converged products and Variations do not retain stale diagnostic metadata.

## Webhook recovery

- Upgrade re-arms active retry rows with legacy stock errors and expired `processing` leases, without replaying terminal failed history.
- Recovered work schedules the normal self-runner immediately.
- Product/Variant processor exceptions are converted to durable queue failures. Retry counters now advance and the existing max-try policy retires a poison event instead of leaving it permanently `processing` and blocking later Variant events.

## Safety

- Failed/terminal history is not replayed because a newer desired-state event may already have superseded it.
- Recovery changes queue state only; the normal ordered worker remains responsible for applying product and Variation mutations.
