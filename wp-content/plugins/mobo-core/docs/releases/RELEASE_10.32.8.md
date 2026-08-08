# Mobo Core 10.32.8 — Shipping Destination Scope Repair

## Summary

This release makes destination scope an explicit, repairable part of every Mobo-managed WooCommerce shipping instance.

## Behavior

- Courier methods (`پیک`) are Tehran-only.
- Mobo drop-shipping postal method `148395514` is available nationwide across Iran, including Tehran.
- Repair persists `mobo_destination_scope` as `tehran_only`, `iran_wide`, or `source_restricted`.
- Existing managed instances are updated in place; unrelated WooCommerce methods and zones are not removed.
- Runtime checkout validation uses the same authoritative scope written by Repair.

## Upgrade

No database migration is required. After upgrading, run the shipping Repair action once.
