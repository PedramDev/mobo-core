# Mobo Core 10.32.5 — Complete Shipping Contract and WooCommerce Shipping Context

## Summary

This release completes the Mobo shipping-method contract consumed from Portal and adds an optional WooCommerce shipping-only context for Mobo products.

## Complete method data

The cached method payload now preserves:

- `id`, `type`, `status`, `title`, `description`, and `position`
- `minimum_weight`, `maximum_weight`
- `minimum_subtotal`, `maximum_subtotal`
- `minimum_cost`, `maximum_cost`, `round_cost`, and nullable `cost`
- `countries`, `states`, and `cities`
- all `rules`
- `created` metadata

Suspended or non-approved methods are not offered as active methods in WordPress.

## WooCommerce shipping-only context

A store administrator may enable a shipping-only package transformation and select a WooCommerce product shipping class for Mobo products.

For Mobo cart lines only:

- the product object is cloned before changing its shipping class or price;
- the configured class is applied only to the clone;
- `mobo_api_price × quantity` may replace the line amount only inside the shipping package;
- package `contents_cost` is recalculated from the transformed copy;
- non-Mobo lines retain their normal WooCommerce values.

The real product, cart item, discount calculation, checkout total, payment amount, and order item values are not modified.

## Administration guide

The Checkout settings page now includes:

- enable/disable switch for the shipping-only Mobo package context;
- enable/disable switch for using `mobo_api_price`;
- WooCommerce shipping-class selector;
- complete Mobo shipping-method catalog;
- guidance for creating a class and rebuilding rule ranges in WooCommerce shipping zones or a compatible conditional-shipping extension.

## Compatibility

- No WordPress database migration is required.
- Existing shipping ID mappings remain valid.
- Automatic Mobo order submission remains optional and independent from shipping-package calculation.
- PHP 7.4 compatibility is retained.
