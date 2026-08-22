# Mobo Core 10.33.38 — Deep regression hardening

This release is intentionally narrow and defensive. It adds fail-closed boundaries found while expanding the automated test matrix.

## Runtime hardening

- Stock is treated as the Portal contract actually defines it: nullable integer. Fractional, exponent, boolean, object/array and overflow values cannot be coerced into a WooCommerce quantity.
- Raw concrete Variant attributes are validated before map normalization. Duplicate attribute names, malformed rows and empty selections fail before mutation.
- `delete_variation_permanently()` now verifies `post_type=product_variation` at the physical deletion boundary. Parent products cannot be deleted even if a corrupt caller passes the wrong ID.
- Remote shipping amount/bound strings must use plain decimal grammar; scientific notation is rejected instead of silently reinterpreted.
- Product Recovery normalizes corrupted state schema (buffers, payloads, counters and identity fields) before bounded processing.

## Test expansion

The one-command suite now contains 111 numbered Core tests (`00..110`), 9 fault-injection tests, 8 audits and the existing cross-process concurrency helpers. New coverage focuses on hostile payloads, duplicate Variant attributes, nullable/integer stock, shipping revision safety, Recovery corruption/backoff, exact-origin redirects and global irreversible boundaries.
