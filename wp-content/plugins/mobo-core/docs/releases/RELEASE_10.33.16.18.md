# Mobo Core 10.33.16.18 — Adaptive Product Sync + Indexed Reconciliation Recovery

## هدف

این فاز دو مسیر داغ باقی‌مانده از 10.33.16.17 را تکمیل می‌کند: بازیابی سریع محصول در Reconciliation و یادگیری واقعی ظرفیت Product Sync.

## Reconciliation

- `mobo_product_map` همچنان اولین و authoritative lookup است.
- در صورت نبودن map، قبل از fallback قدیمی `wp_postmeta` از `mobo_sync_health` استفاده می‌شود.
- وقتی GUID موجود است، lookup از unique index `product_guid` انجام می‌شود؛ در غیر این صورت از index `portal_product_id` استفاده می‌شود.
- `wp_product_id`، نوع post، وضعیت و سازگاری GUID/Portal ID قبل از پذیرش بررسی می‌شوند.
- hit معتبر، GUID map را همان لحظه self-heal می‌کند.
- fallback قدیمی postmeta فقط برای نصب‌های legacy باقی مانده و پس از hit نیز map را repair می‌کند.
- بررسی وجود جدول `mobo_sync_health` برای همان PHP request cache می‌شود؛ بنابراین مسیرهایی مثل health upsert دیگر برای هر محصول چند `SHOW TABLES` تکراری اجرا نمی‌کنند.

## Adaptive Product Sync

- Real Cron مدت زمان هر `run_manual_sync_step()` را جداگانه اندازه می‌گیرد.
- metricها فقط در buffer موجود `Mobo_Core_Runtime_Diagnostics` جمع می‌شوند و همچنان حداکثر یک flush تجمیعی در پایان request دارند؛ per-step option write جدیدی ایجاد نشده است.
- پس از حداقل 4 Product Sync step اندازه‌گیری‌شده، Adaptive Tuner می‌تواند `productStepsPerRound` را بر اساس EWMA `recentMsPerItem` تنظیم کند.
- حد پایین Product Sync برابر 1، hard cap برابر 20 و سقف رشد برابر 2x baseline تنظیم‌شده است.
- failureهای اخیر، memory pressure و time-budget exhaustion همچنان رشد را محدود یا ظرفیت را کاهش می‌دهند.
- در نبود sample کافی، مقدار configured baseline بدون تغییر استفاده می‌شود.

## سازگاری و Migration

- Database schema تغییر نکرده است.
- EF/WordPress migration جدید لازم نیست.
- Desired State، Repair، Stage 7، ترتیب eventها، queue durability، Upgrade Barrier و Cache Mutation Guard تغییر semantics نداده‌اند.
