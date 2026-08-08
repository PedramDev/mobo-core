# Mobo Core 10.33.3 — Deferred Archive Cache Windows

## هدف

این نسخه دو رفتار cache را اصلاح می‌کند:

1. سازگاری WP Rocket با Product Categoryهای hierarchical که URL واقعی آن‌ها با canonical taxonomy متفاوت است.
2. حذف purge لحظه‌ای Archiveها و جایگزینی آن با صف persistent و intervalهای قابل انتخاب.

## WP Rocket Product Category compatibility

- فیلتر `rocket_disable_url_validation` داخل Mobo Core اضافه شده است.
- bypass فقط برای WooCommerce Product Category اعمال می‌شود و سایر URLها همچنان URL validation عادی WP Rocket را دارند.
- category base از تنظیم permalink ووکامرس خوانده می‌شود و fallback آن `product-category` است.
- برای purge، علاوه بر canonical term URL، مسیر hierarchical واقعی Product Category نیز ثبت می‌شود.

نمونه:

- canonical: `/product-category/samsung/`
- hierarchical cache path: `/product-category/products/case/samsung/`

هر دو target در صف Archive لحاظ می‌شوند.

## Deferred archive purge

گزینه قدیمی:

`mobo_core_cache_purge_archives_on_product_update`

با گزینه interval زیر جایگزین شده است:

`mobo_core_cache_archive_purge_interval_minutes`

مقادیر مجاز:

- Disabled
- 5
- 10
- 15
- 20
- 25
- 30
- 45
- 60 minutes

Archive purge فوری دیگر وجود ندارد.

### رفتار invalidation

- Product page cache: فوری
- WordPress/WooCommerce Object Cache و transients: فوری
- Product Category archives: deferred
- Product Tag archives: deferred
- Shop archive: deferred
- Home page: deferred

برای Product Category، parent categoryها نیز وارد صف می‌شوند تا archive والد در فروشگاه‌هایی که descendant products را نمایش می‌دهند stale نماند.

## Persistent batching

- اولین mutation در یک پنجره، `dueAt` را تعیین می‌کند.
- mutationهای بعدی همان پنجره را عقب نمی‌اندازند.
- بنابراین Sync دائمی نمی‌تواند purge را برای همیشه به تعویق بیندازد.
- صف در WordPress option ذخیره می‌شود و بین requestها باقی می‌ماند.
- اجرای due queue توسط real Mobo cron runner انجام می‌شود.
- lock مستقل `cache_archive_purge_queue` از اجرای همزمان جلوگیری می‌کند.
- failure باعث retry در پنجره بعدی می‌شود و tight retry loop ایجاد نمی‌شود.

## Cache integrations

Mutation Guard نسخه 10.33.2 حفظ شده و Sync/Repair/Webhook/Reprice/Recategorize/Image workerها همچنان native purge storm را در scope Mobo مهار می‌کنند.

Deferred Archive purge:

- WP Rocket: exact archive/root URLs، با `rocket_clean_home()` برای Home.
- LiteSpeed Cache: exact archive URLs با `litespeed_purge_url`.
- W3 Total Cache: exact archive URLs با `w3tc_flush_url`.
- WP Super Cache: deferred related-page invalidation با `wp_cache_post_change`.
- WordPress/WooCommerce object/transient invalidation همچنان فوری است.

## Migration

برای upgrade از نسخه‌های قبل:

- legacy archive purge = OFF → interval = Disabled
- legacy archive purge = ON → interval = 5 minutes
- option قدیمی پس از migration حذف می‌شود.

## Operational requirement

برای دقت interval، `mobo-cron.php` باید طبق معماری فعلی Mobo Core به‌صورت دوره‌ای اجرا شود؛ cadence یک دقیقه‌ای مناسب است.
