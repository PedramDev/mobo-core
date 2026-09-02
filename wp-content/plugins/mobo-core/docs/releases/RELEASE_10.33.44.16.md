# Mobo Core 10.33.44.16 — report-driven durability and desired-state hardening

## Scope

This release is based on the dual-site WAMP r7.2 reports. It separates real runtime defects from contract-test drift introduced by the shared-policy refactor, fixes the runtime defects, and adds focused regressions rather than suppressing failures.

## Durable metadata scalar verification

WordPress metadata tables normalize non-serialized scalar values to strings. The previous critical read-back verifier compared the in-memory integer/float type against the stored string type strictly, so a successful write such as revision `987654321` could be reported as a durability failure when read back as `"987654321"`.

`Mobo_Core_Durable_State_Policy` now owns WordPress-normalized verified postmeta and termmeta persistence. Product ordering/crash checkpoints, Image Sync checkpoints and Category Sync identity/checkpoints delegate to that boundary. Arrays/objects retain structural comparison while numeric/bool/null scalars use WordPress storage semantics.

## Authoritative empty categories

WooCommerce can bootstrap the default product category when a product is created without categories. An authoritative Portal state of `categories=[]` must still converge to no `product_cat` relationships. Category Sync now removes taxonomy relationships explicitly for the empty desired set and verifies the exact read-back before acknowledging convergence.

Omitted category fields still preserve existing state. Malformed category collections still fail closed.

## Image desired-state URL validation

Image rows now require an explicit raw `http` or `https` scheme before `esc_url_raw()` normalization. This prevents a malformed scheme-less token from being normalized into an actionable download URL and then accepted as authoritative desired state.

## Test-suite corrections

Several r7.2 source contracts still searched for pre-policy literal implementations or interpolated PHP variables inside double-quoted search strings. Those tests were corrected to verify the current policy boundaries and runtime invariants without weakening the checks.

The stale authoritative Variant runtime fixture now explicitly sets `variantListAuthoritative=true`; without that flag the production code correctly treats the payload as a delta. The category partial-invalid fixture now uses an actually invalid remote identity shape instead of assuming all remote identifiers must be UUIDs. Read-only shipping diagnostics are also allowed by the shipping-hook contract while mutation callbacks remain forbidden.

## Remaining data-integrity failures from the submitted reports

The submitted local databases still contain real pre-existing catalog corruption detected by the suite: duplicate WooCommerce price meta rows and duplicate variation attribute signatures under the same parent. These checks remain release-blocking FAILs; r7.3 does not downgrade or hide them and does not silently mutate the whole catalog during a test run.

## Validation target

Deep Test Suite target: `10.33.44.16-r7.3`.

Run the deterministic baseline first. Repair existing local data-integrity failures deliberately, rerun the baseline to zero FAIL, and only then run the Real-Cron/chaos gate.
