# Mobo Core Health Report

این نسخه گزارش سلامت سمت وردپرس را اضافه می‌کند تا Portal بتواند وضعیت واقعی سایت مشتری را ببیند.

## Endpoint داخلی پلاگین برای Probe

Portal می‌تواند این endpoint را سبک چک کند:

```http
GET /wp-json/mobo-core/v1/health
X-SEC: <mobo_core_security_code>
```

خروجی شامل `pluginVersion`، زمان سایت و payload سلامت محلی است.

## ارسال گزارش به Portal

در هر اجرای Real Cron، پلاگین بعد از انجام slice همگام‌سازی، یک گزارش به Portal ارسال می‌کند:

```http
POST /api/site-health/report
X-SEC: <mobo_core_security_code>
Content-Type: application/json
```

اگر گزینه `Health Report URL` خالی باشد، پلاگین از `API Base URL` این مسیر را می‌سازد:

```text
/api/site-health/report
```

مثال:

```text
https://portal.example.com/api/site-health/report
```

## تنظیمات جدید

در پنل افزونه تب `سلامت سایت` اضافه شده است.

گزینه‌ها:

- ارسال Health Report به Portal
- Health Report URL
- حداقل فاصله ارسال / ثانیه
- Timeout ارسال / ثانیه

## اطلاعاتی که گزارش می‌شود

- Site URL
- Plugin version
- WordPress version
- PHP version
- WooCommerce version
- WP_DEBUG / WP_DEBUG_LOG / WP_DEBUG_DISPLAY
- PHP memory_limit
- memory usage / peak usage
- disk free / total / percent
- last real cron hit
- last sync success
- pending/failed webhook queue files
- pending sync jobs
- Action Scheduler failed/past-due count, اگر موجود باشد
- last error

## نکته

این سیستم به WP-Cron وابسته نیست. مسیر اصلی اجرای آن Real Cron / cPanel Cron است.
