# Mobo Core 10.34.60 — HARDEN10 Checkout Scope Pre-Payment Guard

## Production change

When automatic Mobo order submission is enabled for a Connection represented in the checkout, mixed Mobo/non-Mobo carts, carts spanning multiple Mobo Connections, and ownership-ambiguous carts now fail before the payment boundary. Classic checkout and Checkout Blocks/Store API use the same all-or-nothing policy.

The guard does not force optional external/cart validation on. Fully non-Mobo carts remain unrelated to Mobo automatic ordering, and an unrelated enabled Connection cannot block them.

## Hardening checkpoint context

The HARDEN13 Test Suite checkpoint covers Phases 8-13. Source/Provider lifecycle, Product/Category/Variation lifecycle, HPOS/order storage, utf8mb4/partial schema upgrade, and low-resource hosting were audited against the existing production implementation. No additional production defect requiring a code delta was established in those phases.

Backend .NET remains the HARDEN03 Network Resilience candidate; no Backend bytes changed for Phases 8-13.
