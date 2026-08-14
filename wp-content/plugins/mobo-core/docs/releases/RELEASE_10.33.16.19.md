# Mobo Core 10.33.16.19 — Adaptive Stability Guards

## هدف

جلوگیری از نوسان ظرفیت Workerها بین اجرای Cron، بدون اضافه‌کردن state یا write جدید خارج از Runtime Diagnostics موجود.

## Confidence

- هر stage از تعداد itemهای پردازش‌شده، تعداد run و تازگی sample یک `confidencePermille` بین 0 تا 1000 می‌گیرد.
- افزایش ظرفیت به confidence حداقل 600 نیاز دارد.
- کاهش عادی ظرفیت به confidence حداقل 350 نیاز دارد.
- downshiftهای ایمنی ناشی از memory/time pressure، failure یا کندی شدید می‌توانند این محدودیت را bypass کنند.

## Hysteresis

- برای هر stage یک band برابر ±20% target time تعریف شده است.
- اگر ظرفیت فعلی هنوز داخل این band باشد، مقدار فعلی نگه داشته می‌شود حتی اگر تقسیم integer مقدار مجاور را پیشنهاد کند.
- این guard نوسان‌هایی مثل 5↔6 یا 19↔20 را هنگام jitter طبیعی latency حذف می‌کند.

## Cooldown

- cooldown به‌صورت per-stage و 10 دقیقه است.
- `lastChangedAt` هر stage در همان `runtimeDiagnostics.adaptiveTuning.stability` ذخیره می‌شود.
- flush یا option write جدیدی اضافه نشده؛ آخرین adaptive profile مانند قبل همراه flush تجمیعی diagnostics ذخیره می‌شود.
- تغییر تنظیم baseline توسط مدیر، anchor/cooldown قبلی همان stage را invalidate می‌کند و baseline جدید بلافاصله source of truth می‌شود.
- downshift ایمنی می‌تواند cooldown را bypass کند.

## Bounded ramp

- upshift پذیرفته‌شده در یک تصمیم حداکثر 50% anchor قبلی رشد می‌کند.
- hard capهای قبلی و سقف 2x baseline همچنان برقرارند.

## Diagnostics

برای هر stage موارد زیر در profile ثبت می‌شود:

- anchor
- ideal
- applied
- targetMs
- predictedMsAtAnchor
- confidencePermille
- cooldownActive
- decision reason

این داده‌ها bounded هستند و history نامحدود ایجاد نمی‌کنند. Health tab نیز خلاصه Hold/Changed و جدول تصمیم stageها را نمایش می‌دهد.

## Migration

- Database schema تغییر نکرده است.
- WordPress migration یا Portal migration لازم نیست.
- Sync/Repair/Desired State/Upgrade Barrier/Queue durability تغییر semantics نداده‌اند.
