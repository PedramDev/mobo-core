# Mobo Core 10.34.58 — HARDEN04 Ambiguous Purchase Idempotency

## Scope

This release hardens the direct Plugin → Provider purchase path against ambiguous remote side effects. The Backend .NET service is not in the provider Checkout/Wallet purchase path and therefore has no HARDEN04 code delta.

## Purchase safety invariants

- Every logical submission has a durable `_mobo_order_submit_attempt_key`.
- The provider cart token is durably checkpointed before Checkout POST.
- Once a remote order ID is positively known, Core never rebuilds Checkout automatically for that WooCommerce order.
- Ambiguous Checkout/Wallet transport is reconciled by GET details only.
- Reconciliation is **positive-only**: exact expected remote order + explicit `paid=true` may heal local uncertainty.
- Missing order fields, `paid=false`, absent/invalid payment evidence, timeout or unreadable details never prove that another financial POST is safe.
- A durable remote order ID cannot be overridden by the administrator “verified absent” checkbox.
- Reconciliation owns the same per-order submission lease and the shared Provider cart/session lease used by purchase orchestration.
- Reconciled post-success listeners run only after the shared cart/session lease is released and are fenced against stale replay.
- Existing filesystem Wallet uncertainty fences remain in force.

## Provider contract

`Mobo_Core_Provider_Interface::interpret_order_reconciliation_details()` exposes provider-specific interpretation of order-details payloads. The adapter can provide positive evidence, but must never infer remote absence from missing or incomplete fields.

## Retry policy

HARDEN03 network retry rules remain intact. Idempotent GET reconciliation can be retried according to dependency policy. Checkout/Shipping/Wallet POST ambiguity is not blindly replayed.

## Backend

No HARDEN04 Backend code change is required because provider purchase/payment is initiated directly by Mobo Core. Backend remains at HARDEN03 network-resilience candidate pending Windows/.NET runtime evidence.
