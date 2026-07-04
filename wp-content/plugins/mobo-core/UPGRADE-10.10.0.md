# Mobo Core 10.10.0 - Phase 9 Upgrade Notes

## هدف فاز

این نسخه اسکلت اعتبارسنجی قبل از خرید را به پلاگین اضافه می‌کند، بدون اینکه رفتار خرید سایت‌های مشتری به صورت خودکار تغییر کند.

## رفتار پیش‌فرض

- `mobo_core_checkout_validation_enabled = 0`
- اعتبارسنجی checkout به صورت پیش‌فرض غیرفعال است.
- هیچ API خارجی به صورت پیش‌فرض صدا زده نمی‌شود.
- هیچ migration دیتابیس جدیدی لازم نیست.
- با HPOS سازگار است؛ این فاز فقط cart item و `WC_Product` را بررسی می‌کند و مستقیم با order storage کار نمی‌کند.

## تنظیمات جدید

- `mobo_core_checkout_validation_enabled`
- `mobo_core_checkout_validate_only_mobo_products`
- `mobo_core_checkout_require_remote_guid`
- `mobo_core_checkout_block_incomplete_sync`
- `mobo_core_checkout_local_stock_check_enabled`
- `mobo_core_checkout_external_validation_enabled`
- `mobo_core_checkout_external_validation_url`
- `mobo_core_checkout_external_timeout_seconds`
- `mobo_core_checkout_external_error_behavior`

## مسیر پنل

`موبو → اعتبارسنجی خرید`

## External API Contract پیشنهادی

Request:

```json
{
  "siteUrl": "https://example.com/",
  "cartHash": "...",
  "currency": "USD",
  "items": [
    {
      "cartKey": "...",
      "productId": 123,
      "variationId": 456,
      "quantity": 1,
      "sku": "...",
      "productGuid": "...",
      "variantGuid": "...",
      "price": "100.00",
      "stockStatus": "instock"
    }
  ],
  "timestamp": 1710000000
}
```

Responseهای پشتیبانی‌شده:

```json
{ "allow": true }
```

```json
{ "allow": false, "message": "Product is not available." }
```

```json
{
  "items": [
    { "allow": false, "message": "Variant is not available." }
  ]
}
```

```json
{ "errors": ["Product is not available."] }
```

## Hookهای توسعه

- `mobo_core_checkout_validation_payload`
- `mobo_core_checkout_validation_external_url`
- `mobo_core_checkout_validation_external_response`
- `mobo_core_checkout_validation_errors`

## نکته Rollout

برای سایت‌های مشتری، اول نسخه را deploy کن. سپس validation را مرحله‌ای فعال کن. پیشنهاد اولیه:

1. فقط local validation فعال شود.
2. external validation غیرفعال بماند تا API مقصد مشخص شود.
3. بعد از آماده شدن API مقصد، external validation را با `error_behavior = allow` تست کن.
4. بعد از پایدار شدن API، در صورت نیاز `error_behavior = block` فعال شود.

## رفتار اجرای external validation

Local validation می‌تواند روی cart/checkout اجرا شود. اما external validation روی صفحه cart معمولی صدا زده نمی‌شود تا API خارجی بی‌دلیل تحت فشار قرار نگیرد. در checkout، external validation فعال می‌شود.
