# Mobo Core 10.8.0 Upgrade Notes

## هدف فاز ۵

این نسخه برای مقاوم‌سازی sync تصاویر ساخته شده است:

- صف مستقل تصویر داخل دیتابیس وردپرس
- جلوگیری از دانلود تکراری attachmentها بر اساس `image_guid`، `img_guid` و `mobo_source_url`
- retry/backoff برای دانلود تصویر
- resume بهتر برای sync تصاویر در webhook و initial sync
- حفظ سازگاری با مسیر قدیمی `process_images()`

## Migration

در activation/upgrade جدول زیر ساخته می‌شود:

```text
{prefix}_mobo_image_queue
```

این migration داده‌های قبلی را حذف نمی‌کند و روی نصب‌های فعلی مشتری‌ها safe است.

## تنظیمات جدید

```text
mobo_core_image_queue_enabled = 1
mobo_core_image_queue_blocking = 1
mobo_core_image_max_try = 5
mobo_core_image_retry_base_seconds = 120
```

`mobo_core_image_queue_blocking = 1` رفتار قدیمی‌تر را حفظ می‌کند؛ یعنی محصول/وب‌هوک تا تکمیل تصاویر همان محصول ادامه کامل نمی‌دهد. اگر سرعت مهم‌تر از کامل بودن فوری تصاویر باشد، می‌توان آن را غیرفعال کرد تا تصویرها مستقل‌تر و پس‌زمینه‌ای پردازش شوند.

## Runner

`Mobo_Core_Cron_Runner` حالا در هر slice صف تصویر را هم پردازش می‌کند. بنابراین self-runner بدون WP-Cron می‌تواند تصویرهای pending را مرحله‌ای جلو ببرد.

## Compatibility

اگر جدول image queue وجود نداشته باشد یا تنظیم غیرفعال باشد، sync تصویر به مسیر direct/chunk قبلی برمی‌گردد.
