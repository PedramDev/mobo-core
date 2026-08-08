# Mobo Core 10.33.2 — Cache-Agnostic Mutation Guard

## هدف

یکسان‌سازی cache invalidation در Sync، Repair، Webhook و workerهای mutation برای تمام page-cache backendهایی که Mobo Core به‌صورت مستقیم پشتیبانی می‌کند. Native purgeهای افزونه‌های cache در طول هر `save()` مهار می‌شوند و `Mobo_Core_Cache_Purger` در پایان request یک‌بار purge هدفمند و deduplicated را طبق policy زیر انجام می‌دهد:

`mobo_core_cache_purge_archives_on_product_update`

## تغییرات

- `Mobo_Core_Cache_Mutation_Guard` به‌عنوان guard عمومی، request-local، reference-counted و exception-safe اضافه شد.
- همه mutationهای Mobo که در 10.33.1 با guard اختصاصی WP Rocket پوشش داده شده بودند به guard عمومی منتقل شدند.
- WP Rocket:
  - در کل scope عملیات Mobo از `rocket_is_importing=true` استفاده می‌شود.
  - purge نهایی بعد از خروج از guard توسط `Mobo_Core_Cache_Purger` انجام می‌شود.
- LiteSpeed Cache:
  - در scope Mobo از `litespeed_purge_tags` برای حذف tagهای broad/related استفاده می‌شود.
  - tagهای مستقیم post/URL و tagهای custom ناشناخته دست‌نخورده می‌مانند تا consistency محلی حفظ شود.
  - وقتی archive purge روشن باشد، broad purge نهایی فقط یک‌بار و بعد از خروج از guard توسط Mobo اجرا می‌شود.
  - برای tag constants شناخته‌شده fallback سازگار با نسخه جاری LiteSpeed وجود دارد تا load-order کلاس `LiteSpeed\\Tag` باعث از دست رفتن protection نشود.
- W3 Total Cache:
  - `w3tc_preflush_post`, `w3tc_preflush_posts`, `w3tc_preflush_all` در تمام scope mutation native flush را veto می‌کنند.
  - purge نهایی URL/post پس از خروج از guard توسط Mobo انجام می‌شود.
- WP Super Cache:
  - `wp_super_cache_clear_post_cache=false` native post purge را در scope Mobo متوقف می‌کند.
  - `wpsc_delete_related_pages_on_edit=0` نیز به‌عنوان compatibility fallback نصب می‌شود.
  - purge نهایی post/related pages بر اساس setting آرشیو توسط Mobo انجام می‌شود.
- WordPress/WooCommerce Object Cache / Redis:
  - invalidation object/transient عمداً suppress نمی‌شود؛ این بخش consistency داده است و full-page archive purge محسوب نمی‌شود.
- facade قدیمی `Mobo_Core_WP_Rocket_Import_Guard` برای backward compatibility حفظ شد.
- hookهای توسعه‌ای موجود هستند:
  - `mobo_core_cache_mutation_guard_begin`
  - `mobo_core_cache_mutation_guard_end`

## مسیرهای پوشش‌داده‌شده

- Manual Sync
- Manual Repair
- Adaptive Reconciliation / Deep Repair
- `ProductUpdated` webhook
- `UpdateVariant` webhook
- Reprice Queue
- Recategorize Queue
- Image Queue / Image Sync
- Image Refresh Queue

## رفتار setting آرشیو

### OFF

- native broad/related purgeهای cache pluginها در طول saveهای Mobo مهار می‌شوند.
- Purger نهایی فقط URLهای هدفمند محصول/فایل را invalidate می‌کند.
- Product Category / Tag / Shop / Home صرفاً به خاطر saveهای داخلی Mobo purge نمی‌شوند.

### ON

- native per-save broad purge همچنان در scope Mobo مهار می‌شود تا purge storm ایجاد نشود.
- بعد از پایان mutation، `Mobo_Core_Cache_Purger` یک‌بار purge مرتبط را طبق integration backend فعال اجرا می‌کند.

## عدم تغییر

- ویرایش دستی محصول در wp-admin خارج از Mobo guard است و رفتار طبیعی cache plugin حفظ می‌شود.
- Cart / Checkout / My Account تغییر نکرده‌اند.
- schema دیتابیس، migration و remote API contract جدیدی اضافه نشده است.
