# C75B P4G Direct Currency Pricing — STAGED

## Behavior now wired
- The existing `calculate_price_pair()` used by Sync, Reprice, Repair and fast-skip can convert `IRT`/`IRR` before applying fixed/percentage/dynamic markup.
- Price source fields (`mobo_api_price`, `mobo_api_compare_price`) remain Source-unit values.
- Legacy equal-currency connections continue down the original path.
- A different Source vs Woo store unit is accepted **only when** an administrator has explicitly saved `mobo_core_money_target_preference` (`store`, `IRR`, `IRT`), the target matches WooCommerce, and an exact supported pair is present. If that option is absent, the V1 mismatch failure remains.
- The money converter rejects fractional outputs that the integer-based price calculator would truncate, out-of-range amounts, unsupported pairs, and precision violations.
- Shipping, Checkout, Order Submission, wallet and Backend purchase paths still use the original strict monetary guard. Cross-currency purchase is NOT enabled.

## Important unproven work before release
- Test actual WordPress + Woo product create and reprice with explicit Connection context; verify raw source and Woo price, and repeat for idempotence.
- Verify parent/variation ownership and other writers in runtime.
- Inspect all API order and shipping currency boundaries prior to unblocking purchases.
- This ZIP is NOT production-ready. Never use it on test1/test2.
