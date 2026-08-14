# Mobo Core 10.33.16.11 — Portal HTTP & Source Fetch Fast Path

## هدف

کاهش latency و request-amplification ارتباط Plugin با Portal، به‌خصوص در Real Cron، Webhook payload pull و Adaptive Reconciliation؛ بدون تغییر Desired State یا قرارداد API.

## تغییرات

- Portal API requestها در Real Cron به deadline واقعی همان Cron slice محدود می‌شوند. timeout تنظیم‌شده 60 ثانیه دیگر نمی‌تواند یک runner با بودجه 25 ثانیه را خارج از budget نگه دارد.
- API Client قبل از شروع request حاشیه‌ای برای checkpoint/finalization نگه می‌دارد و در پایان budget با خطای cooperative متوقف می‌شود.
- Lightweight Webhook payload pull timeout از زمان باقی‌مانده همان webhook pass محاسبه می‌شود.
- payload URLهای یکسان در همان PHP request فقط یک بار fetch می‌شوند و نتیجه موفق request-local cache می‌شود.
- token/security headers در طول همان request یک بار normalize/validate می‌شوند و برای requestهای بعدی reuse می‌شوند.
- پس از transport error یا 502/503/504 یک circuit breaker کوتاه request-local باز می‌شود تا یک batch روی همان upstream خراب چند بار منتظر timeout نماند.
- Adaptive Reconciliation endpoint discovery هوشمند شد: primary/compat endpoint سالم به خاطر سپرده می‌شود. compatibility endpoint فقط روی 404/405/410/501 probe می‌شود، نه روی network/5xx failure.
- اگر هر دو revision endpoint واقعاً موجود نباشند، unavailable capability به‌طور bounded cache می‌شود (پیش‌فرض 6 ساعت) و rolling cursor fallback بدون دو request شکست‌خورده در هر reconciliation اجرا می‌شود.
- با تغییر API base URL، capability cache خودکار invalid می‌شود.
- uninstall cleanup برای optionهای operational جدید اضافه شد.

## سازگاری و Migration

- جدول جدید ندارد.
- migration دیتابیس ندارد.
- endpoint contract تغییر نکرده است.
- Stage 7، Smart Diff، Event Coalescing، DB/Variation/Media/Taxonomy/CRUD fast paths و exact-product warmup حفظ شده‌اند.
