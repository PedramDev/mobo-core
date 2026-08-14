# Mobo Core 10.33.16.15 — Priority Lanes & Backpressure Scheduler

## هدف

این نسخه زمان CPU/DB/HTTP کران را بر اساس اهمیت عملیاتی صف‌ها تقسیم می‌کند. Webhookهای Desired State و ثبت سفارش مشتری در مسیر foreground قرار می‌گیرند و کارهای پس‌زمینه هنگام فشار منبع عقب می‌ایستند، بدون اینکه برای همیشه starvation ایجاد شود.

## تغییرات اصلی

- Webhook و Order Submission در بالاترین اولویت Runner قرار گرفتند.
- Order Submission بلافاصله بعد از Webhook اجرا می‌شود و در صورت وجود بودجه کافی می‌تواند دو سفارش را در یک pass پردازش کند.
- Image Queue، Reprice، Recategorize و Image Refresh هنگام Webhook pressure فقط در starvation escapeهای چرخشی اجرا می‌شوند.
- Parent Finalize هر سه round فشار، با batch یک و بودجه دو ثانیه فرصت همگرایی می‌گیرد.
- Reconciliation و Maintenance هنگام وجود webhook due اجرا نمی‌شوند.
- Manual Sync، Reprice، Recategorize، Parent Finalize، Warmup، Order Submission و Image Refresh پیش از autoload کلاس سنگین، با state/setting سبک بررسی می‌شوند.
- Health result وضعیت priority scheduler را گزارش می‌کند.

## سازگاری

- بدون جدول جدید
- بدون migration دستی
- بدون reset صف یا Stage
- PHP 7.4+

