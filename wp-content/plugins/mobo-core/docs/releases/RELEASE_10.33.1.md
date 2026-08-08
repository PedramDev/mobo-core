# Mobo Core 10.33.1 — Controlled WP Rocket Invalidation

## هدف

جلوگیری از پاک شدن مکرر cache آرشیوهای WooCommerce توسط hookهای داخلی WP Rocket هنگام mutationهای متعلق به Mobo Core، بدون تغییر رفتار ویرایش‌های عادی WooCommerce/WordPress.

## تغییرات

- یک guard جدید با نام `Mobo_Core_WP_Rocket_Import_Guard` اضافه شد.
- در محدوده عملیات Mobo فیلتر `rocket_is_importing` فعال می‌شود و در `finally` حتماً برداشته می‌شود.
- Guard به صورت reference-counted است؛ بنابراین عملیات nested مثل Repair → Product Sync → Image Sync باعث remove زودهنگام فیلتر نمی‌شوند.
- مسیرهای زیر پوشش داده شدند:
  - Manual Sync
  - Manual Repair
  - Adaptive Reconciliation / Deep Repair
  - `ProductUpdated` webhook
  - `UpdateVariant` webhook
  - Reprice Queue
  - Recategorize Queue
  - Image Queue
  - Image Refresh Queue
- purge هدفمند موجود در `Mobo_Core_Cache_Purger` همچنان در `shutdown` اجرا می‌شود.
- وقتی `mobo_core_cache_purge_archives_on_product_update=0` باشد، Mobo فقط cache URLهای هدفمند محصول را پاک می‌کند و WP Rocket دیگر از مسیر CRUD، archiveها را مستقل از این setting پاک نمی‌کند.
- وقتی setting آرشیو روشن باشد، purge آرشیوها همچنان توسط pipeline خود Mobo در پایان request انجام می‌شود.

## محدوده عدم تغییر

- رفتار cache برای ویرایش دستی محصول در wp-admin تغییر نکرده است.
- Cart، Checkout، My Account و منطق cache استاندارد WooCommerce تغییر نکرده‌اند.
- Redis Object Cache و LiteSpeed server capability دستکاری نشده‌اند.
- schema دیتابیس و migration جدیدی اضافه نشده است.

## Verification

- PHP syntax برای تمام فایل‌های تغییرکرده بررسی شد.
- reference counting و cleanup بعد از exception برای guard با stubهای WordPress تست شد.
- public APIهای موجود (`run_manual_sync_step`, `process_product_updated_payload`, `process_update_variant_payload`, queue processors و reconciliation tick) حفظ شدند و implementation داخلی به wrapperهای guarded منتقل شد.
