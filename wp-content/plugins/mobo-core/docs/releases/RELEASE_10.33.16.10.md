# Mobo Core 10.33.16.10 — Checkpoint Coalescing & Runner Budget

## هدف

کاهش write-amplification روی `wp_options` و جلوگیری از عبور Workerهای سنگین از بودجه زمان/حافظه، بدون از دست دادن Resume، Repair، Stage 7 یا crash recovery.

## تغییرات

- Product Sync/Repair در Real Cron حداکثر 3 step یا 2 ثانیه را در حافظه coalesce می‌کند و سپس یک checkpoint durable می‌نویسد. اجرای دستی تک‌مرحله‌ای همچنان بلافاصله state را ذخیره می‌کند.
- در انتهای هر round، checkpoint اجباری flush می‌شود. پیش از flush، cancellation پایدار دوباره خوانده می‌شود تا worker قدیمی نتواند لغو مدیر را overwrite کند.
- Reprice و Recategorize به‌جای `update_option()` بعد از هر محصول، هر 5 محصول یا 2 ثانیه checkpoint می‌نویسند. در crash ناگهانی حداکثر چند آیتم idempotent replay می‌شود.
- Reprice/Recategorize cooperative time budget دارند و با `budget-exhausted` تمیز به Runner برمی‌گردند.
- Real Cron علاوه بر deadline، حاشیه حافظه PHP را کنترل می‌کند و قبل از OOM با `memory-pressure` متوقف می‌شود.
- Health runner اکنون `memoryUsageBytes`, `memoryPeakBytes`, `memoryLimitBytes`, `memoryReserveBytes` را گزارش می‌کند.
- Image Queue وقتی زمان کمی از slice باقی مانده باشد batch را خودکار به 1 یا 2 تصویر کاهش می‌دهد.
- telemetry غیرحیاتی Image Refresh Automation در continuationهای سریع حداکثر هر 5 ثانیه نوشته می‌شود.
- نتیجه Cron در حالت عادی یک‌بار ذخیره می‌شود؛ فقط Health Push برای اینکه payload نتیجه اجرای جاری را ببیند دو write هدفمند دارد.

## سازگاری و Migration

- جدول جدید ندارد.
- migration دستی ندارد.
- state و cursorهای موجود حفظ می‌شوند.
- Stage 7، Event Coalescing، Smart Diff، Variation/Media/Taxonomy fast paths و exact-product warmup بدون تغییر semantics حفظ شده‌اند.
