# Mobo Core 10.33.44.14 — shared runtime policies and fail-closed payload presence

## Scope

This release consolidates cross-cutting runtime decisions that had drifted across Product/Variant synchronization, shipping, order submission, category recovery and image recovery. It keeps the existing queue-specific retry/backoff and Product-vs-Variant completion semantics separate; only genuinely shared policy is centralized.

## Money and shipping price policy

`Mobo_Core_Money_Policy` is now the single implementation for:

- source amount → WooCommerce store amount conversion;
- store amount → source amount conversion;
- canonical `mobo_api_price` lookup, with Variation metadata taking precedence and safe Parent fallback;
- source-money payload validity checks used before Product/Variation mutation.

Automatic Shipping and Remote Shipping no longer maintain duplicate conversion/API-price helpers.

## Order submission activation

`Mobo_Core_Order_Submission_Policy` is now the single truth table for the automatic Mobo order-submission master switch. The plugin bootstrap, Checkout Validator, Address Mapping, City Assets, Cron Runner, Shipping Diagnostics, admin/runtime diagnostics and Remote Shipping use the same decision.

The bootstrap policy reads options directly and remains lightweight; it does not load the full settings registry merely to decide whether checkout/address runtime must be initialized.

Checkout validation flags remain deliberately separate because they control optional pre-payment validation rather than order-submission intent.

## Payload presence semantics

`Mobo_Core_Payload_Field_Policy` preserves these states distinctly:

- field absent → preserve existing desired state;
- field present with `null` → explicit nullable state where that field contract permits it;
- field present with `[]` → authoritative empty collection;
- field present with a non-empty array → authoritative collection value;
- field present but malformed → fail closed, never silently normalize into empty.

Shared aliases now cover category, image, attribute, price, compare-price and stock fields.

A concrete alias drift was fixed for `compare_price`: previous code could detect the snake-case field as present but then read only `comparePrice/ComparePrice`, effectively turning the value into `null`. Presence, hashing, no-op comparison, source-meta persistence and mutation now read compare price through the same alias resolver.

## Product and Variant money integrity

Explicitly present malformed `price` or `comparePrice/compare_price` values are rejected before WooCommerce mutation for Product, Variation and embedded Simple-Variant paths. Absent fields remain preserve-only; explicit null/empty remains a supported nullable desired state according to the existing price contract.

## Category recovery

Recategorize no longer collapses a malformed current `productCategories` field into `[]`.

When the newest relevant event contains malformed category desired state, it also does not fall back to an older event and resurrect stale categories. The queue requests current authoritative API state instead; malformed current API state is recorded diagnostically and fails closed without writing false authoritative-empty evidence.

## Image desired state and recovery

`Mobo_Core_Image_Desired_State_Policy` centralizes authoritative image-collection validation for Product Sync and Missing-Image Recovery.

Missing-Image Recovery now distinguishes:

- absent `images` → insufficient evidence; preserve and retry/fail safely;
- explicit `images: []` → valid authoritative empty state;
- malformed/partially invalid image collection → fail closed;
- valid collection → normal recovery path.

Missing or malformed API `data`, or failure to find the requested Product in the response, can no longer be reported as if the authoritative product simply had no images.

Remote image GUID/source extraction is also shared by Product image fast-path checks, Image Queue and Image Sync, preventing those paths from accepting different identity aliases or URL schemes.
Remote identities are now required to be scalar non-boolean values before sanitization, so malformed arrays/objects cannot be string-cast into a fake `Array` identity.

## Deliberate non-unification

The release does **not** force all queues onto one retry policy. Queue-specific retry/backoff behavior remains independent.

Product completion and authoritative multi-page Variant completion also remain separate because Variant `SyncId + Revision + all pages delivered` has stronger promotion semantics.

The shared invariant for claimed work remains ownership: a worker may commit only while it still owns the exact claim/lease evidence.

## Tests

Deep Test Suite `10.33.44.14-r7.1` adds:

- read-only/runtime policy contract `134-money-order-and-presence-policy-contract.php`;
- local mutation regression `1004-money-order-and-presence-policy.php`;
- explicit absent/null/empty/malformed presence assertions;
- snake-case `compare_price` value/presence consistency;
- malformed Product/Variant money fail-closed checks;
- category newest-malformed-event stale-fallback prevention;
- image desired-state validation and missing-evidence safeguards;
- shared order-submission bootstrap/runtime decisions;
- shared Money Policy conversion and variation→parent API-price precedence.

The local mutation test remains gated behind `MOBO_DEEP_ALLOW_MUTATION=1` and a Local/WAMP safety check.
