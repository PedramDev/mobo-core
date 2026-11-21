# Mobo Core 10.33.62 — canonical Woo price metadata

## Why

Live evidence showed duplicate `_price` rows on Mobo-owned products. A duplicate row can make WooCommerce's loaded current price differ from the current Mobo pricing policy. The old Repair integrity phase used that loaded Woo price as the canonical value, which could preserve the wrong duplicate.

## Changes

- Added `Mobo_Core_Price_Meta_Integrity::collapse_duplicates_verified()`.
- Reprice collapses duplicate price rows to the already-calculated authoritative pair before its final durable postcondition.
- Repair integrity derives the canonical price for purchasable Mobo objects from `mobo_api_price` / `mobo_api_compare_price` through the current Mobo price calculator, then collapses duplicates.
- All changes run under the pre-existing shared product lock and use exact database read-back verification.

## Upgrade

No migration is required. Existing Reprice state is preserved and may continue from its durable cursor.
