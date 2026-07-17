# cPanel CLI Queue Worker — Mobo Core 10.33.3

این Worker برای هاست‌هایی طراحی شده است که Cron را حداقل هر یک دقیقه اجرا می‌کنند. cPanel هر دقیقه `mobo-cron.php` را اجرا می‌کند، اما همان Process تا سقف زمان تعیین‌شده زنده می‌ماند و Queueها را چند بار بررسی می‌کند.

## Queueهای پردازش‌شده

Worker روی Queueها و فرآیندهای قابل‌ادامه موجود افزونه کار می‌کند و Queue جدیدی ایجاد نمی‌کند:

1. Webhook queue
2. Product image queue
3. Legacy image refresh queue / automation
4. Step-based product sync
5. Reprice queue
6. Recategorize queue
7. Queued Mobo order submissions

در شروع هر Round ترتیب Queueها می‌چرخد تا Queueهای انتهایی Starve نشوند.

## تنظیمات wp-config.php

```php
define( 'MOBO_QUEUE_WORKER_ENABLED', true );
define( 'MOBO_QUEUE_WORKER_MAX_RUNTIME', 50 );
define( 'MOBO_QUEUE_WORKER_IDLE_SLEEP', 10 );
define( 'MOBO_QUEUE_WORKER_LOCK_PATH', '' );
```

Default امن `MOBO_QUEUE_WORKER_ENABLED` برابر `false` است. سه Constant دیگر در صورت تعریف‌نشدن به‌ترتیب `50`، `10` و مسیر Lock خودکار استفاده می‌کنند.

اگر Lock Path خالی باشد، افزونه مسیر زیر را در Data Directory خودش می‌سازد:

```text
wp-content/uploads/mobo-core/locks/mobo-queue-worker-<site-hash>.lock
```

وجود فایل Lock به‌تنهایی نشانه اجرای Worker نیست. مالکیت فقط با `flock(LOCK_EX | LOCK_NB)` تعیین می‌شود.

## Cron در cPanel

```cron
* * * * * /usr/local/bin/php -q /home/mayacase/public_html/wp-content/plugins/mobo-core/mobo-cron.php >> /home/mayacase/logs/mobo-cron.log 2>&1
```

مسیر PHP، Home Directory و Document Root را با مقادیر واقعی همان اکانت جایگزین کنید.

## اجرای دستی

```bash
/usr/local/bin/php -q /home/mayacase/public_html/wp-content/plugins/mobo-core/mobo-cron.php
```

## مشاهده Log

```bash
tail -f /home/mayacase/logs/mobo-cron.log
```

فقط خطاها:

```bash
grep -E '"level":"ERROR"|"success":false' /home/mayacase/logs/mobo-cron.log | tail -100
```

## رفتار هم‌زمانی

وقتی `MOBO_QUEUE_WORKER_ENABLED=true` باشد:

- Self Runner از ارسال Loopback HTTP خودداری می‌کند.
- `/cron/run`، `/worker/run`، `/webhook/run` و `/sync/run` اجرای Queue را به Worker CLI واگذار می‌کنند.
- Webhook هنگام دریافت فقط Enqueue می‌شود و داخل همان HTTP Request پردازش نمی‌شود.
- WP-Cron مربوط به Queue ارسال سفارش، Queue را پردازش نمی‌کند.
- Poll صفحه مدیریت Sync فقط Status را می‌خواند و Product/Image Queue را جلو نمی‌برد.
- اجرای مستقیم Image Refresh Queue از پنل غیرفعال است.

## تست Lock

Terminal اول:

```bash
/usr/local/bin/php -q /home/mayacase/public_html/wp-content/plugins/mobo-core/mobo-cron.php
```

پیش از پایان Terminal اول، در Terminal دوم همان فرمان را اجرا کنید. اجرای دوم باید فوراً با وضعیت `mobo_queue_worker_locked` خارج شود و Exit Code آن صفر باشد، چون هم‌پوشانی یک وضعیت مورد انتظار است.

پس از پایان اجرای اول، فرمان باید دوباره قابل اجرا باشد؛ حتی اگر فایل Lock هنوز روی Disk وجود داشته باشد.

## Deadline

کنترل زمان با `microtime(true)` انجام می‌شود. Worker پیش از شروع هر Batch، زمان باقی‌مانده را با زمان Batch قبلی همان Queue مقایسه می‌کند. نزدیک Deadline، Batch جدید شروع نمی‌شود. یک Batch خارجی مانند دانلود تصویر یا HTTP Request قابل Preempt نیست؛ بنابراین اگر خود Batch بیشتر از زمان پیش‌بینی‌شده طول بکشد ممکن است Process اندکی از Deadline عبور کند، اما Worker Batch بعدی را شروع نمی‌کند.

## Rollback

1. ZIP نسخه قبلی را جایگزین کنید.
2. `MOBO_QUEUE_WORKER_ENABLED` را حذف یا `false` کنید.
3. Cron مربوط به `mobo-cron.php` را حذف کنید یا موقتاً Comment کنید.
4. در صورت نیاز Self Runner/Real Cron قبلی را دوباره فعال کنید.

هیچ Migration دیتابیسی برای این تغییر وجود ندارد.
