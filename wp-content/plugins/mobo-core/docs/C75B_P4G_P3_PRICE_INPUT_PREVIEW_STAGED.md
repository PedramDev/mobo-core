# C75B-P4G-P3 — Connection-scoped price input preview (STAGED, INERT)

- Requires an independently verified durable product/variation owner Connection ID. The planner only compares the supplied owner and Connection row, and **cannot itself prove** that the object map belongs to the supplied ID.
- Rejects absent/disabled/ambiguous/mismatched Connection rows, missing/invalid V1 contract, malformed Source price, unsupported source-target pair, target different from WooCommerce, excessive precision or unsafe amount.
- Keeps raw `mobo_api_price` and `mobo_api_compare_price` in Source units; no persistence is performed.
- Converts Source INPUTS with `Mobo_Core_Money_Converter::convert_exact()` before calling the existing markup/dynamic threshold algorithm, so fixed profit and thresholds are understood in WooCommerce currency.
- Rejects fractional converted values for the current legacy calculator because it truncates base values with `intval`; support needs a separate audited change.
- New `Mobo_Core_Price_Calculator::preview_converted_price_pair()` is a STAGED, explicit preview route, not called by Product Sync, Reprice, Shipping, Checkout or any hooks. It does not write object prices and always returns `runtime_enabled=false`.
- Production `calculate_price_pair()` retains `Mobo_Core_Money_Policy::assert_compatible()` and V1 strict fail-closed behavior; real currency-converted price mutation is NOT enabled.
- No Backend changes or modifications to existing deployments. Do not deploy this staged Plugin.

## Next gates

1. Durable ownership resolution for every Product Sync, delta, fast/no-op and Reprice path, including variation/parent disagreements and connection context switching.
2. Convert only once, before rule evaluation; audit rounding, numeric range, sale invariants and reprice idempotency on the disposable Clone.
3. Independently audit `mobo_api_price` consumers in shipping, checkout, revenue and Provider payload. Do not authorize runtime conversion until they have explicit monetary units and postconditions.
4. Add an opt-in runtime feature gate with migration/reprice strategy and recovery/rollback; V1 stays compatible.
