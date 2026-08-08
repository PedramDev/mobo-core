# Mobo Core 10.33.7 — Reconciliation Legacy Lookup Cleanup

این نسخه دو هشدار نهایی Plugin Check مربوط به `meta_key` و `meta_value` در Reconciliation را برطرف می‌کند.

## تغییر اصلی

- مسیر اصلی همچنان `Mobo_Core_Product_Map` و lookup بر اساس GUID است.
- فقط برای نصب‌های قدیمی که هنوز map آن‌ها کامل نشده، Portal Product ID از سه کلید تاریخی post meta با lookup محدود و prepared پیدا می‌شود.
- بعد از اولین legacy hit، همان محصول فوراً در `Mobo_Core_Product_Map` ثبت می‌شود تا lookupهای بعدی از مسیر سریع map انجام شوند.
- اولویت تاریخی کلیدها حفظ شده است: `portal_product_id` سپس `mobo_portal_product_id` سپس `_mobo_portal_product_id`.

## Cache

هیچ تغییری در سیاست Cache ایجاد نشده است:

- Product page/object/transient invalidation: فوری.
- Product Category / Product Tag / Shop / Home page cache: deferred queue.
- interval پیش‌فرض نصب جدید: 15 دقیقه.
- `Mobo_Core_Cache_Mutation_Guard` بدون تغییر باقی مانده است.
