# راهنمای توسعه‌دهنده API و Webhook

## احراز هویت
- APIهای کاتالوگ و Revision Feed: هدر `Token` با GUID لایسنس فعال.
- درخواست‌های Portal به WordPress: هدر `X-SEC`.
- دریافت بسته ارتقا: `X-Mobo-Package-Token`.

## Revision Feed
```http
GET /sync/changes?afterRevision=0&limit=100
Token: <license-guid>
```
Alias سازگار: `/api/sync/changes`.

## وب‌هوک وردپرس
```http
POST /wp-json/mobo-core/v1/webhook
X-SEC: <security-code>
```
کد امنیتی باید ASCII قابل چاپ و بدون فاصله باشد.


## کنترل از راه دور Portal

تمام مسیرهای زیر بدون Login وردپرس قابل فراخوانی‌اند، اما Header محرمانه `X-SEC` الزامی است:

- `GET /wp-json/mobo-core/v1/portal/settings` — دریافت همه تنظیمات قابل‌مدیریت و غیرمحرمانه.
- `POST /wp-json/mobo-core/v1/portal/operations/start` — شروع `sync` یا `repair` با `requestId` یکتا.
- `GET /wp-json/mobo-core/v1/portal/operations/status` — وضعیت، درصد پیشرفت، تعداد پردازش‌شده و خطای آخر.
- `POST /wp-json/mobo-core/v1/portal/operations/cancel` — لغو امن Sync/Repair جاری.

کد وب‌هوک، License Token، Cron Token، Password و Cookie هیچ‌گاه در snapshot تنظیمات برگردانده نمی‌شوند.

---

## کنترل از راه دور Portal — نسخه 10.31.91

تمام endpointهای این بخش Public REST هستند؛ یعنی به cookie یا ورود WordPress نیاز ندارند، اما بدون Header امنیتی `X-SEC` قابل استفاده نیستند. مقدار `X-SEC` همان کد امنیتی Webhook ثبت‌شده برای سایت است.

### دریافت تنظیمات غیرمحرمانه

```http
GET /wp-json/mobo-core/v1/portal/settings
X-SEC: <webhook-security-code>
```

پاسخ شامل مقدار فعلی، مقدار پیش‌فرض، نوع، گروه، منبع و read-only بودن تنظیمات است. این endpoint برای مشاهده و پشتیبانی ساخته شده و تنظیمی را تغییر نمی‌دهد.

موارد زیر هرگز در پاسخ قرار نمی‌گیرند:

- کد امنیتی Webhook
- Token لایسنس
- Cron token
- password، cookie، secret و credentialهای مشابه

### شروع Sync یا Repair

```http
POST /wp-json/mobo-core/v1/portal/operations/start
X-SEC: <webhook-security-code>
Content-Type: application/json

{
  "operation": "sync",
  "requestId": "portal-sync-unique-id"
}
```

مقدار `operation` یکی از `sync` یا `repair` است. `requestId` برای idempotency استفاده می‌شود. Repair همان Desired-State engine را با bypass کنترل‌شده Source Hash اجرا می‌کند.

### وضعیت عملیات

```http
GET /wp-json/mobo-core/v1/portal/operations/status
X-SEC: <webhook-security-code>
```

پاسخ شامل نوع عملیات، وضعیت، درصد پیشرفت، تعداد پردازش‌شده و باقی‌مانده، Cursor/Sync ID، خطا و زمان‌های Unix است.

### لغو عملیات

```http
POST /wp-json/mobo-core/v1/portal/operations/cancel
X-SEC: <webhook-security-code>
```

لغو، state قابل ادامه را حفظ می‌کند و از اجرای همزمان عملیات جدید جلوگیری می‌شود.

### گزارش Health

گزارش Health دوره‌ای دو بخش جدید دارد:

- `remoteControl`: وضعیت آخرین Sync/Repair و پیشرفت آن
- `settingsSnapshot`: تعداد تنظیمات غیرمحرمانه، hash و زمان تولید snapshot

Portal می‌تواند وضعیت جاری را از Health نمایش دهد و برای وضعیت لحظه‌ای endpoint مستقیم status را فراخوانی کند.


## تست اعتبار کد وب‌هوک — نسخه 10.31.92

```http
POST /wp-json/mobo-core/v1/portal/webhook-test
X-SEC: <webhook-security-code>
X-Mobo-Webhook-Test: 1
```

این endpoint برای مقایسه کد ثبت‌شده در Portal با `mobo_core_security_code` استفاده می‌شود. هیچ مقدار secret در پاسخ برگردانده نمی‌شود.

کدهای پاسخ:

- `200`: کد Portal و افزونه یکسان است.
- `401`: کد Portal با افزونه یکسان نیست.
- `403`: کد امنیتی در افزونه ثبت نشده است.
- `503`: کد ذخیره‌شده در افزونه برای Header معتبر نیست.

آخرین نتیجه تست به‌صورت غیرمحرمانه در option `mobo_core_webhook_auth_status` ذخیره و در داشبورد، صفحه اتصال و گزارش Health نمایش داده می‌شود.


## قرارداد لایسنس API — نسخه 10.31.93

فقط `GET /get-products-free` بدون لایسنس است. تمام درخواست‌های Portal که این افزونه مصرف می‌کند Header زیر را ارسال می‌کنند:

```http
Token: <LICENSE-GUID>
```

Portal مقدار را با `FillTokenDto()` می‌خواند. گزارش سلامت علاوه بر Token، هدر `X-SEC` و دانلود بسته Deploy علاوه بر Token، هدر `X-Mobo-Package-Token` را ارسال می‌کند. payloadهای Webhook نیز Token و X-SEC را همزمان دارند.
