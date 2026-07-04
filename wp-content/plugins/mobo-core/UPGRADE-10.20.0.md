# Mobo Core 10.20.0

## Re-apply pricing policy

This version adds a bounded repricing worker for WooCommerce products and variations already synced by Mobo.

The worker uses saved raw API price meta:

- `mobo_api_price`
- `mobo_api_compare_price`

and recalculates WooCommerce prices using the current pricing settings.

## Admin UI

Go to:

`Mobo -> Pricing`

Use:

`اعمال مجدد قیمت روی همه محصولات`

The operation is cursor-based and continues through the existing self-runner/real-cron worker. It does not fetch products from Portal again.

## Options

- `mobo_core_reprice_batch_size` default: `20`
- `mobo_core_reprice_state` stores progress.

## Safety

No database table migration is required. State is stored in WordPress options.
