# Mobo Core 10.31.99

## هدف نسخه

جلوگیری از ماندن دائمی محصولات بدون تصویر، زمانی که Webhook محصول قبل از آماده‌شدن فایل تصویر به WordPress رسیده یا اجرای PHP بین دانلود Attachment و اتصال آن به محصول متوقف شده است.

## تغییرات Image Queue

- خطاهای موقت دانلود، Timeout، HTTP 404/5xx، آماده‌نبودن Shared Media Manifest و خطاهای موقت Import دیگر پس از رسیدن به `mobo_core_image_max_try` به وضعیت نهایی `failed` نمی‌روند.
- `mobo_core_image_max_try` اکنون تعداد تلاش‌های سریع قبل از ورود به Retry بلندمدت است.
- گزینه جدید `mobo_core_image_long_retry_seconds` با مقدار پیش‌فرض 21600 ثانیه (۶ ساعت) اضافه شد.
- Retry بلندمدت محدود و قابل تنظیم است و با Jitter کوچک از Retry هم‌زمان تعداد زیادی سایت جلوگیری می‌کند.
- فقط خطاهای ساختاری غیرقابل بازیابی مانند محصول حذف‌شده، GUID خالی یا URL غیر HTTP/HTTPS به `failed` نهایی می‌روند.
- پیام خطای نهایی با پیشوند `Permanent:` مشخص می‌شود.

## رفع Race اتصال تصویر

چرخه وضعیت جدید:

```text
pending
→ processing
→ attaching
→ done
```

بعد از ساخت یا پیدا کردن Attachment، رکورد ابتدا `attaching` می‌شود. تنها پس از اعمال تصویر شاخص و گالری WooCommerce، وضعیت `done` ثبت می‌شود. اگر PHP در این فاصله متوقف شود، Cron بعدی همان Attachment را Reuse و اتصال محصول را کامل می‌کند.

## بازیابی داده‌های قدیمی

هنگام ارتقا به 10.31.99:

- رکوردهای `failed` قدیمی که محصول، Image GUID و URL معتبر دارند در Batchهای ۵۰۰تایی به `pending` برگردانده می‌شوند.
- هیچ Sync یا Repair کامل محصول به‌صورت خودکار آغاز نمی‌شود.
- Attachment موجود دوباره دانلود نمی‌شود.
- محصولات دارای Queue `done` ولی تصویر شاخص نامنطبق برای Linkage Repair زمان‌بندی می‌شوند.
- Maintenance نیز هر شش ساعت Recovery محدود را تکرار می‌کند.

## Maintenance

- رکورد `done` با Attachment حذف‌شده دیگر حذف نمی‌شود؛ به `pending` برمی‌گردد تا فایل دوباره دریافت شود.
- فقط Failureهای واقعاً دائمی و قدیمی پاک می‌شوند.
- Failureهای قابل بازیابی قبل از Cleanup دوباره باز می‌شوند.

## Health

فیلدهای زیر به پاسخ Health اضافه شدند:

```text
attachingImageJobs
nextImageRetryAt
```

فیلدهای قبلی حفظ شده‌اند.

## Shared Media

- Shared Media روشن: Manifest آماده‌نشده همچنان Retry می‌شود و فایل محلی تکراری ساخته نمی‌شود.
- Shared Media خاموش: دانلود معمول WordPress با Retry بلندمدت ادامه پیدا می‌کند.
- محدودیت Migration برای تغییر ناگهانی `Shared Media enabled → disabled` همچنان برقرار است.

## Migration دیتابیس

Schema جدول تغییر نکرده است. وضعیت `attaching` از ستون متنی موجود استفاده می‌کند. Migration فقط رکوردهای Queue موجود را به‌صورت محدود و قابل تکرار Recover می‌کند.
