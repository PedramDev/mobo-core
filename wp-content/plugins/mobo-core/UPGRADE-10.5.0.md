# Mobo Core 10.5.0 - Phase 2 Upgrade Notes

## هدف این نسخه

این نسخه مسیر اجرای sync را از مدل وابسته به runner خارجی به مدل customer-side self runner نزدیک می‌کند.

بعد از دریافت webhook، پلاگین:

1. payload را داخل queue محلی ذخیره می‌کند.
2. سریع پاسخ `accepted` برمی‌گرداند.
3. یک درخواست non-blocking به endpoint داخلی خودش می‌زند:
   `/wp-json/mobo-core/v1/worker/run?token=...`
4. worker داخلی، صف webhook و initial sync را به صورت bounded slice پردازش می‌کند.
5. اگر در همان slice پیشرفت واقعی انجام شد و هنوز کار باقی بود، خودش slice بعدی را kick می‌کند.

## نکته migration

- دیتای قبلی حذف نمی‌شود.
- جدول‌های فاز ۱ حفظ می‌شوند.
- queue قدیمی فایل‌محور همچنان پشتیبانی می‌شود.
- `/cron/run`، `/webhook/run`، `/sync/run` همچنان برای سازگاری باقی مانده‌اند.
- تنظیمات جدید فقط در صورت نبودن option ساخته می‌شوند و مقدارهای مشتری overwrite نمی‌شوند.

## Endpointهای جدید

### Run worker

```text
/wp-json/mobo-core/v1/worker/run?token=<mobo_core_cron_token>
```

این endpoint توسط خود پلاگین با `wp_remote_post(... blocking=false)` صدا زده می‌شود.

### Worker status

```text
/wp-json/mobo-core/v1/worker/status
```

این endpoint با `X-SEC` محافظت می‌شود و برای status/debug است.

## تنظیمات جدید

```text
mobo_core_self_runner_enabled = 1
mobo_core_self_runner_continue_enabled = 1
mobo_core_self_runner_min_interval_seconds = 3
mobo_core_self_runner_http_timeout_seconds = 1
```

## fallback

اگر self-kick روی یک هاست به دلیل loopback restriction شکست بخورد:

- webhook همچنان در queue ذخیره می‌شود.
- webhook بعدی دوباره تلاش می‌کند worker را بیدار کند.
- real cron همچنان به عنوان fallback قابل استفاده است.
- پردازش فوری webhook داخل request اصلی همچنان به صورت option وجود دارد ولی پیش‌فرض آن خاموش است.

## تست پس از نصب

1. یک webhook تستی ارسال کنید.
2. پاسخ باید شامل `selfKick.status = dispatched` یا نهایتاً `throttled` باشد.
3. از داشبورد پلاگین تب Runner وضعیت آخرین kick و آخرین worker run را ببینید.
4. اگر `request-failed` گرفتید، loopback هاست را بررسی کنید یا real cron fallback را فعال کنید.
