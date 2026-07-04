# Mobo Core 10.11.0 - Final rollout audit / cleanup

This release is a rollout-safety cleanup release after phases 0-9.

## What changed

- Plugin version bumped to `10.11.0`.
- Activation/bootstrap documentation was corrected: activation does **not** delete queued webhook JSON files.
- No new database table is introduced in this release.
- Existing migrations from 10.3.0 to 10.10.0 remain idempotent and safe for customer upgrades.
- Checkout validation remains disabled by default for safe rollout.

## Existing customer install safety

This version keeps all compatibility fallbacks:

- Legacy file webhook queue remains supported.
- New table-based webhook queue remains supported.
- Product/variant map table is used first, then legacy `product_guid` / `variant_guid` meta fallback.
- Category mapping is used first, then synced `category_guid` fallback.
- Image queue is used when available, then image sync fallback.
- Lightweight webhook pull is supported, while full payload webhook still works.

## Recommended rollout order

1. Upgrade one internal/staging WordPress site to Mobo Core 10.11.0.
2. Open **Mobo Core → Health / Runner** and confirm table creation and queue status.
3. Run one small initial sync or webhook test.
4. Upgrade a small customer batch.
5. Keep Portal `WebhookSendLightweightNotification=false` until most customers are on 10.6.0+.
6. Enable Portal lightweight webhook only after customer plugin coverage is confirmed.

## Important defaults

- `mobo_core_process_webhook_on_receive = 0`
- `mobo_core_self_runner_enabled = 1`
- `mobo_core_pull_payload_enabled = 1`
- `mobo_core_checkout_validation_enabled = 0`
- `mobo_core_category_mapping_required = 0`

These defaults are intentionally safe for existing stores.
