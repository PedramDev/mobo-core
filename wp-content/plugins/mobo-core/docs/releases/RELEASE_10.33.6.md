# Mobo Core 10.33.6 — Plugin Check Final Cleanup

این نسخه سه هشدار باقی‌مانده‌ی Plugin Check پس از 10.33.5 را بدون تغییر در سیاست Cache برطرف می‌کند.

## تغییرات

- تشخیص درخواست `product_cat` برای سازگاری WP Rocket از WordPress query vars خوانده می‌شود و دیگر خواندن مستقیم `$_GET` ندارد.
- fallback قدیمی Reconciliation برای یافتن محصول بر اساس Portal Product ID به سه lookup محدود تک‌کلیدی تبدیل شد و `meta_query` با OR حذف شد.
- بررسی reference تصویر در `termmeta` و `usermeta` با queryهای صریح و prepared انجام می‌شود و table identifier پویا حذف شد.

## رفتار Cache

رفتار نسخه‌های 10.33.3 تا 10.33.5 بدون تغییر حفظ شده است:

- Product page/object/transient invalidation: فوری.
- Product Category / Product Tag / Shop / Home page cache: deferred queue.
- interval پیش‌فرض نصب جدید: 15 دقیقه.
- `Mobo_Core_Cache_Mutation_Guard` همچنان purge storm افزونه‌های cache را در mutationهای Mobo مهار می‌کند.
