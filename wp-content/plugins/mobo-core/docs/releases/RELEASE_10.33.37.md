# Mobo Core 10.33.38 — stock/recovery diagnostics hardening

- Nullable Portal stock is handled consistently for simple products and variations; explicit `stock: null` no longer produces `mobo_core_simple_stock_invalid`.
- The manual **بروزرسانی و ترمیم نگاشت ارسال** control is removed from administrator UI. Automatic shipping-method refresh and legacy policy cleanup remain backend responsibilities.
- Variation-integrity recovery reason canonicalization is fixed, with a one-time serialized re-audit for sites that already crossed 10.33.35/10.33.36.
- System webhook entity extraction now follows the internal `type/guid` contract and no longer emits undefined-key warnings.
