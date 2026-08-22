# Mobo Core 10.33.34 — Verification hotfix

- Rejects scientific/exponent notation in source price and comparePrice before WooCommerce decimal normalization.
- Prevents malformed source values such as `1e999999` from becoming an unintended finite catalog price.
- Runtime change is intentionally narrow; recovery/image-refresh/order orchestration from 10.33.33 is unchanged.
