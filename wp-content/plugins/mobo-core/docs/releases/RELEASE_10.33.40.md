# Mobo Core 10.33.40 — Checkout cart preparation hotfix

## Incident

A checkout could show «آماده‌سازی سبد موبو برای بررسی سفارش انجام نشد» even after Mobo had accepted deletion of stale cart rows. The clear path documented its final read as authoritative, but used `GET /site/api/v1/cart?update=false`; a stale pre-delete snapshot could therefore be mistaken for a surviving row.

## Fix

- Cart clear snapshots and verification now use `update=true`.
- Clearing runs for at most three bounded convergence passes.
- A transport/non-2xx DELETE outcome is treated as ambiguous until an authoritative snapshot proves whether the row survived; no checkout is allowed merely because DELETE returned an error.
- The safety invariant is unchanged: checkout remains blocked if the authoritative cart still contains any row after bounded retries.
- Authentication retry and the shared cart/session lock remain in force.

## Scope

No database migration and no checkout UI change. Repair/product synchronization behavior from 10.33.39 is unchanged.
