# Mobo Core 10.33.44.5 — negative stock convergence

## Stock contract

- `stock: null` remains unlimited stock: quantity management is disabled and the item is in stock.
- Positive integer stock remains an exact managed quantity.
- Zero remains managed and out of stock.
- Negative integer stock now converges to managed quantity zero and out of stock instead of failing the complete Product/Variation event.
- Fractional, boolean, object, scientific-notation, partially numeric, and positive-overflow values remain invalid.

## Queue diagnostics

- Committing an event as done, retry, or terminal failure clears obsolete `progress_json` state from an earlier parent-busy deferral unless a done result explicitly supplies fresh progress.
- `last_error`, `try_count`, and `next_retry_at` remain the authoritative failure and retry diagnostics.

## Deployment

- No database schema or data migration is required.
- Existing pending rows are processed normally on their next due attempt.
