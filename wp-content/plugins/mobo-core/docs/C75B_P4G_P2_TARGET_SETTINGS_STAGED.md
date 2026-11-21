# C75B-P4G-P2 — Store Target Currency Settings (STAGED, INERT)

- Baseline: P4G-P1 Windows isolated verifier r2 PASS, 383 tracked, 133 PHP, 284 converter and 7 V1 guard tests.
- Backend remains Source currency authority per provisioned, signed Connection (`money_unit`). No Backend edits or Swagger schema changes.
- WooCommerce is authoritative for actual checkout currency. New installation-wide `mobo_core_money_target_preference` selection has `store` (default), `IRR`, `IRT`. The choice is validated strictly, saved with capability and nonce inherited from Connection settings, and read-back checked.
- Only an approved IRR/IRT pair can be previewed. If chosen target does not equal WooCommerce currency, preview explicitly fails. Unknown pairs also fail closed.
- New pure `Mobo_Core_Money_Target_Settings` is UI/preflight-only. Its `preview()` always returns `runtimeEnabled=false`, even when `ready=true`; no hooks/pricing/shipping/order pipelines consume it.
- V1 `Mobo_Core_Money_Policy` unchanged, so IRR/IRT mismatched runtime mutation remains blocked. No reprice, migration, queue worker, provider purchase or API call executed.
- Future P4G-P3: first establish per-product owner Connection in ALL price/reprice paths before activation; trace raw source price, dynamic threshold units, Woo fixed profit units, shipping fee, and provider monetary payload. Upgrade opt-in and rollout only with clone runtime gates.
- Do not deploy this intermediate plugin to customer sites or the WAMP clones yet. Version is intentionally still 10.34.77.
