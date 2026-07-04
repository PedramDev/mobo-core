# Mobo Core 10.4.0 - Phase 1 Upgrade Notes

## هدف فاز

این نسخه پایه‌ی queue جدولی و lookup سریع محصول/تنوع را اضافه می‌کند، بدون اینکه نصب‌های قبلی مشتری‌ها یا queue فایل‌محور قدیمی خراب شوند.

## تغییرات دیتابیس

در activation/upgrade این جدول‌ها ساخته یا به‌روزرسانی می‌شوند:

- `wp_mobo_sync_events`
- `wp_mobo_product_map`

نام جدول‌ها با prefix همان وردپرس ساخته می‌شود، بنابراین اگر prefix سایت `wp_` نباشد، با همان prefix ساخته می‌شوند.

## رفتار migration

- تنظیمات قبلی مشتری‌ها overwrite نمی‌شود.
- queue فایل‌محور قدیمی همچنان قابل پردازش است.
- webhookهای جدید در جدول `mobo_sync_events` ذخیره می‌شوند.
- اگر ذخیره در جدول به هر دلیل fail شود، پلاگین به file queue قبلی fallback می‌کند.
- map محصول/تنوع از metaهای قدیمی `product_guid` و `variant_guid` به صورت bounded seed می‌شود.
- اگر map کامل نباشد، sync همچنان با meta_query قدیمی fallback می‌کند و همان لحظه map را repair می‌کند.

## Product / Variant lookup

اولویت lookup از این نسخه:

1. جدول `mobo_product_map`
2. fallback به `WP_Query + meta_query`
3. repair کردن map بعد از fallback موفق

این باعث می‌شود سایت‌های قدیمی بدون migration کامل همچنان کار کنند.

## Webhook queue

اولویت queue از این نسخه:

1. جدول `mobo_sync_events`
2. فایل‌های JSON قدیمی در `uploads/mobo-core/webhook-files/`

پردازش eventهای جدولی هم مثل file queue bounded است و به `mobo_core_webhook_files_per_run` و time budget احترام می‌گذارد.

## نکته نصب روی سایت‌های موجود

قبل از نصب روی production بهتر است از دیتابیس بکاپ گرفته شود. این نسخه محصول، تنوع، دسته‌بندی، تصویر و تنظیمات مشتری را حذف نمی‌کند.
