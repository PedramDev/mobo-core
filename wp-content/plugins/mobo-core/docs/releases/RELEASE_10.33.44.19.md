# Mobo Core 10.33.44.19 — Authoritative Variation lifecycle hardening

This release hardens the transition from a source Variable product to an authoritative Simple topology and the partial removal of source Variants.

## Runtime changes

- Historical Mobo-owned Variations leave the storefront through WordPress Trash/quarantine; normal lifecycle cleanup no longer hard-deletes them.
- An already-Simple parent is still scanned for stale live Mobo Variation children, closing the legacy stuck-topology case.
- Destructive topology requires an explicit valid Variant list and explicit valid Attributes collection. Missing Attributes preserves topology; malformed evidence fails closed.
- Partial authoritative Variant removal quarantines only unseen, unambiguously Mobo-owned children. Manual/non-Mobo children are preserved.
- Conflicting Variant identity aliases fail closed.
- Variation retirement snapshots exact Product Map rows, verifies forensic markers before Trash, deletes only variation mappings with read-back verification, and rolls back identity/map/markers if Trash fails.
- Parent-level stale Variant purchase identity is cleared before an authoritative no-Variant Simple product is acknowledged; stock becomes zero/out-of-stock.
- Post-sync Repair uses the same quarantine primitive and retains its Repair-generation ordering fence and shared product lock.

## Validation target

Deep Test Suite target: `10.33.44.19-r7.8`.

WAMP mutation tests `1018` and `1019` require a real disposable WordPress/WooCommerce environment and are not considered passed by static packaging validation alone.
