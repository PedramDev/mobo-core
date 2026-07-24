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
