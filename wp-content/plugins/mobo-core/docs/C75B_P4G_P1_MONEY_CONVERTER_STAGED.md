# C75B-P4G-P1: Money Conversion Engine, Staged

**NOT DEPLOYABLE — runtime money paths remain in V1 strict fail-closed mode.**

- Backend signed connection `money_unit` remains authoritative. No Backend change.
- WooCommerce currency remains authoritative for storefront amounts; do not add a second conflicting store-currency setting.
- A read-only monetary summary appears in Connection & License, showing Source unit, store unit, supported fixed pair, and v1 contract version.
- Engine: `Mobo_Core_Money_Converter::pair_definition($from,$to)` and `convert_exact($raw,$from,$to,$sourceDigits,$targetDigits)`.
- The engine uses decimal strings and exact decimal shifts for IRT/IRR. Input decimal separator must be a dot; ambiguous commas are rejected. 1 IRT = 10 IRR. Invalid or unsupported pairs, excess source precision, destination precision requiring rounding, or unsafe values fail closed.
- Same-currency pairs are supported for well-formed currencies without creating FX assumptions.
- No setting enables the conversion in this stage. `Mobo_Core_Money_Policy::compatibility_status()` and `assert_compatible()` remain unchanged, including mismatch rejection. All production-facing pipelines retain old gating.
- Mandatory next stage: explicit per-Connection ownership in product sync, reprice, repair, shipping & checkout; opt-in Woo-bound target setting; reprice trigger on currency policy change; effect on fixed profit and dynamic thresholds; isolate provider payload money; persistence/retry/idempotency; automated fixtures on disposable WordPress clone.
