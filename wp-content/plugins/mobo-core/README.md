<div align="center">

# Mobo Core for WooCommerce

**اتصال کنترل شده فروشگاه ووکامرس به MoboCore و جریان کاری mobomobo.ir**  
**Controlled WooCommerce integration with MoboCore and the mobomobo.ir workflow**

![Plugin Version](https://img.shields.io/badge/Mobo_Core-10.31.81-1f6feb)
![Portal](https://img.shields.io/badge/Portal-v38%20%2F%20.NET%2010-512bd4)
![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-21759b?logo=wordpress&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php&logoColor=white)
![WooCommerce](https://img.shields.io/badge/WooCommerce-8.2%2B-96588a?logo=woocommerce&logoColor=white)
![License](https://img.shields.io/badge/License-GPL--2.0%2B-green)

[فارسی](#fa) · [English](#en) · [راهنمای کامل](README_FULL.MD) · [مرجع توابع](FUNCTIONS.MD) · [نمودارها](DIAGRAMS.MD)

</div>

---

<a id="fa"></a>

## معرفی فارسی

Mobo Core افزونه تخصصی ووکامرس برای فروشگاه های ایرانی است که محصولات، تنوع ها، دسته بندی ها، تصاویر، قیمت و موجودی را از MoboCore دریافت می کند و رویدادهای تغییر را از طریق وب هوک پردازش می کند. در صورت فعال سازی تنظیمات مربوطه، افزونه می تواند اعتبارسنجی خرید، نگاشت آدرس، نگاشت روش ارسال و ثبت خودکار سفارش در جریان اختصاصی `mobomobo.ir` را نیز انجام دهد.

این مخزن مربوط به افزونه وردپرس است. بک اند سازگار فعلی، Portal نسخه 25 با `.NET 10` است؛ با این حال هیچ کد یا مستند مشتری در پروژه .NET قرار داده نشده و تمام توضیحات قابل انتشار در همین افزونه نگهداری می شوند.

### قابلیت های اصلی

- همگام سازی مرحله ای و قابل ادامه محصولات و تنوع ها با Pagination و Cursor
- دریافت فقط تغییرات محصول و تنوع از رویدادهای `ProductUpdated` و `UpdateVariant`
- پشتیبانی از Lightweight Webhook و دریافت Payload از Pull API
- صف دیتابیسی برای وب هوک ها با fallback امن فایل JSON
- نگاشت GUIDهای راه دور به محصولات، تنوع ها و دسته بندی های ووکامرس
- صف کنترل شده تصاویر، نوسازی WebP و پاک سازی امن فایل های قدیمی
- قیمت گذاری، افزایش قیمت تنوع و اجرای Reprice به صورت batch
- نگاشت کشور و استان به موبو، تولید خودکار فایل شهرهای موبو برای Checkout و نگاشت روش ارسال ووکامرس به موبو
- نگاشت Variant قابل خرید موبو روی محصول ساده و جلوگیری از تبدیل اشتباه آن به محصول متغیر
- اعتبارسنجی واقعی افزودن به سبد موبو؛ اجباری هنگام ثبت خودکار سفارش
- پیامک بر اساس نوع سفارش از طریق افزونه «پیامک حرفه ای ووکامرس»
- Real Cron، Self Runner، گزارش سلامت و ابزارهای عیب یابی
- AutoUpdater امن و کنترل‌شده از Portal با اعتبارسنجی Manifest، Backup، Rollback و ACK
- اعلام سازگاری با WooCommerce HPOS

### نیازمندی ها

| مورد | حداقل / وضعیت |
|---|---|
| WordPress | `5.8+` |
| PHP | `7.4+` |
| WooCommerce | `8.2+` |
| ووکامرس فارسی | نصب و فعال، slug: `persian-woocommerce` |
| WooCommerce tested up to | `10.9` |
| Mobo Core | `10.31.81` |
| Portal سازگار | `v38 / .NET 10` |
| دسترسی خروجی HTTP | به MoboCore و در صورت فعال بودن، `mobomobo.ir` |

### نصب سریع

1. پوشه `mobo-core` را در مسیر `/wp-content/plugins/` قرار دهید یا ZIP را از بخش افزونه های وردپرس نصب کنید.
2. WooCommerce و افزونه «ووکامرس فارسی» با slug برابر `persian-woocommerce` را نصب و فعال کنید. سپس Mobo Core را فعال کنید.
3. از مسیر **موبو > خرید و فعال سازی** وضعیت حساب و لایسنس را بررسی کنید.
4. در **موبو > اتصال**، مقدار API Base URL، Token و Webhook Security Code را وارد کنید.
5. در **موبو > کران واقعی**، Cron Token را تنظیم و Cron سرور را فعال کنید.
6. قبل از فعال کردن ثبت خودکار سفارش، نگاشت کشور/استان را ذخیره کنید، فایل شهرهای موبو را بسازید و نگاشت روش ارسال را تست کنید.
7. در سایت هایی که از نسخه های قدیمی مانند نسخه 7 ارتقا یافته اند، یک Repair کامل اجرا کنید.

### اتصال پایه

```php
// wp-config.php
// مقدار فعلی پیش فرض افزونه در نسخه 10.31.79:
define( 'MOBO_API_BASE_URL', 'http://mobo.codeya.ir/' );
```

هدرهای اصلی ارتباط:

```http
Token: <portal-license-token>
X-SEC: <webhook-security-code>
```

مقدار `X-SEC` باید فقط از ASCII قابل‌چاپ و بدون فاصله تشکیل شود. حروف انگلیسی، عدد و نمادهای قابل‌چاپ مجاز هستند؛ حروف فارسی، ایموجی، Tab، Enter و سایر Unicodeها مجاز نیستند.

مسیر دریافت وب هوک در سایت وردپرسی:

```text
https://example.com/wp-json/mobo-core/v1/webhook
```

مسیر کران واقعی:

```text
https://example.com/wp-json/mobo-core/v1/cron/run?token=<cron-token>
```

> Token، Security Code، رمز حساب موبو و Cron Token را داخل مخزن Git ثبت نکنید. اطلاعات حساب موبو در WordPress options ذخیره می شود؛ دسترسی مدیر، دیتابیس و فایل های backup باید محدود باشد.


### AutoUpdater کنترل‌شده از Portal — نسخه Bootstrap 10.31.79

نسخه `10.31.79` باید فقط یک بار روی سایت‌های فعلی به‌صورت دستی نصب شود. پس از آن Portal Update Center می‌تواند نسخه‌های جدیدتر را بدون ورود به پیشخوان هر سایت ارسال کند. نسخه‌های قدیمی‌تر از Bootstrap عمداً فرمان آپدیت را قبول نمی‌کنند، چون هنوز کد امن دریافت و نصب بسته را ندارند.

فرآیند هر Deployment:

1. Portal بسته ZIP را خارج از Web Root نگهداری و Header نسخه، ساختار ZIP و پوشش دقیق Manifest SHA-256 را اعتبارسنجی می‌کند.
2. فرمان به شناسه Deployment، نسخه مقصد، SHA-256، حجم، URL بسته، URL ACK، زمان انقضا و Token دانلود متصل و با `X-SEC` امضای HMAC می‌شود.
3. افزونه فرمان را دوباره اعتبارسنجی، بسته را Stream و Hash می‌کند و فایل اضافه، گمشده، مسیر ناامن یا Symlink را رد می‌کند.
4. از پوشه افزونه در Temp خصوصی Backup می‌گیرد، با `Plugin_Upgrader` نصب می‌کند و نسخه مقصد را دوباره می‌سنجد.
5. در خطای نصب یا عدم تطابق نسخه، Backup بازگردانی می‌شود؛ وضعیت و ACK ناموفق برای Retry نگهداری می‌شوند.

برای خاموش‌کردن کامل قابلیت روی یک سرور:

```php
define( 'MOBO_CORE_REMOTE_UPDATES_ENABLED', false );
```

آپدیت بدون حضور فقط وقتی Ready است که `DISALLOW_FILE_MODS` فعال نباشد، روش Filesystem برابر `direct` باشد، پوشه افزونه قابل‌نوشتن باشد و فرمان دیگری Pending نباشد. Portal این موارد را پیش از صف‌کردن بررسی می‌کند.

> Portal فعلی روی HTTP نیز کار می‌کند، اما HMAC و SHA-256 محرمانگی انتقال ایجاد نمی‌کنند. برای Token و X-SEC از شبکه خصوصی، VPN، Allowlist یا TLS روی Reverse Proxy استفاده شود.

### مستندات

| فایل | کاربرد |
|---|---|
| [`README_FULL.MD`](README_FULL.MD#fa) | نصب، پیکربندی، عملیات، امنیت، خطایابی و راهنمای مشتری |
| [`FUNCTIONS.MD`](FUNCTIONS.MD#fa) | REST API، endpointها، hookها، filterها، optionها، جداول و کلاس های PHP |
| [`DIAGRAMS.MD`](DIAGRAMS.MD#fa) | نمودار معماری، Sync، Webhook، Cron، Checkout، Health و تصاویر |
| [`readme.txt`](readme.txt) | فرمت انتشار افزونه برای WordPress.org |

### محدوده محصول

این افزونه یک کانکتور عمومی برای همه مارکت پلیس ها نیست و بدون اعلام رسمی مالک سرویس، به عنوان افزونه رسمی `mobomobo.ir` معرفی نمی شود. محصول برای فروشگاه های ووکامرس فعال در ایران و جریان کاری مشخص Mobo/Mobomobo طراحی شده است.

### پشتیبانی و فروش

- فروش و فعال سازی: `+989124508218` — [Telegram](https://t.me/yazdan_ghadiri) — [WhatsApp](https://wa.me/989124508218)
- پشتیبانی فنی: `+989367362228` — [Telegram](https://t.me/Codeya)
- سرویس: [mobo.codeya.ir](http://mobo.codeya.ir/)
- مخزن: [PedramDev/mobo-core](https://github.com/PedramDev/mobo-core)

### مجوز

GPLv2 or later. فایل [`LICENSE`](LICENSE) را ببینید.

---



### مرکز وضعیت نوسازی تصاویر

در نسخه 10.31.70 بالای تب نوسازی تصاویر یک مرکز وضعیت واحد نمایش داده می شود. این بخش به شکل مستقیم اعلام می کند عملیات در حال اجرای batch است، منتظر اجرای بعدی Cron/Self Runner مانده، متوقف شده، خطا دارد، منتظر تایید حذف است یا کامل شده است. مرحله جاری، درصد همان مرحله، پیشرفت تقریبی کل چرخه، آخرین فعالیت واقعی، سلامت موتور اجرا، نتیجه آخرین batch و مسیر ۹ مرحله ای نیز در همان کادر دیده می شوند.

### اجرای خودکار امن نوسازی تصاویر

در تب «نوسازی تصاویر»، دکمه «شروع یا ادامه اجرای خودکار امن» تمام مراحل اسکن، ساخت صف، جایگزینی، بررسی و بازسازی برش های WebP و اسکن های تاییدی را با batch محدود از طریق Cron واقعی یا Self Runner پیش می برد. اجرای خودکار در خطا متوقف می شود و برای دو مرحله حذفی، یعنی حذف پیوست قدیمی جایگزین شده و حذف خانواده فایل بدون پیوست، هر بار یک تایید صریح مدیر لازم است.

وضعیت همین تب بدون بازخوانی صفحه به صورت خودکار تازه می شود. هنگام اجرای Automation فاصله بررسی ۴ ثانیه و در حالت عادی ۱۲ ثانیه است. اگر مدیر یکی از تنظیمات فرم را تغییر دهد، به روزرسانی موقتا متوقف می شود تا مقدار ذخیره نشده از بین نرود.


### تخلیه چنددوره‌ای صف و Lease امن Cron

از نسخه `10.31.75` هر فراخوانی Cron واقعی فقط یک batch ثابت اجرا نمی‌کند. Runner صف‌های Webhook، تصاویر، نوسازی تصاویر، Sync محصول، Reprice، Recategorize و سفارش‌های queued را در دورهای منصفانه و پشت‌سرهم پردازش می‌کند و تا نزدیک‌شدن به بودجه امن PHP، خالی‌شدن کار قابل‌اجرا، نبود پیشرفت، رسیدن به سقف دورها یا از دست‌رفتن مالکیت Lock ادامه می‌دهد. مقدار «حداکثر step محصول» سهم همان صف در هر دور است، نه سقف کل اجرای Cron.

قفل Runner یک lease اتمیک token-based با زمان انقضای محدود است. مالک قبل از هر مرحله heartbeat می‌فرستد و lease را تمدید می‌کند؛ اجرای همزمان دوم با وضعیت `locked` خارج می‌شود. اگر Process به دلیل خطا، timeout، kill شدن PHP یا crash متوقف شود، heartbeat قطع می‌شود و Lock بعد از TTL منقضی و در اجرای بعدی خودکار بازیابی می‌شود. TTL موثر بر اساس بودجه اجرا و طولانی‌ترین timeout شبکه افزایش می‌یابد، اما دائمی نیست و سقف دارد. اگر Runner در میانه اجرا مالکیت Lock را از دست بدهد، بلافاصله پردازش بیشتر را متوقف می‌کند.

اگر بعد از پایان slice هنوز صف قابل‌اجرا باقی مانده و پیشرفت واقعی انجام شده باشد، Cron Runner یک Self Runner غیرمسدودکننده برای ادامه کار kick می‌کند؛ بنابراین حتی Cron مستقیم cPanel نیز در حالت سالم loopback منتظر دقیقه بعد نمی‌ماند. اگر loopback در هاست مسدود باشد، اجرای Cron بعدی ادامه کار را برمی‌دارد. وضعیت آخرین اجرا، تعداد دورها، علت توقف، heartbeat/انقضای Lock، تمدیدها، stageهای خطادار و نیاز به ادامه داخل فیلد `cronRunner` گزارش سلامت ارسال می‌شود؛ token قفل هرگز گزارش نمی‌شود.

### پاک‌سازی هدفمند Cache محصولات

از نسخه `10.31.73` هر ذخیره واقعی محصول یا Variation متصل به موبو در انتهای همان اجرای PHP به‌صورت تجمیعی Cache را invalidate می‌کند. افزونه `WooCommerce product transients` و Object Cache مربوط به Post را پاک می‌کند و سپس URL محصول، دسته‌ها و برچسب‌های فعلی و حذف‌شده، Shop و Home را برای LiteSpeed Cache، WP Rocket، W3 Total Cache و WP Super Cache در صورت در دسترس بودن API هدفمند آنها purge می‌کند. هیچ `wp_cache_flush()`، `rocket_clean_domain()`، `litespeed_purge_all` یا Purge All دیگری اجرا نمی‌شود.

برای صفحات سفارشی Elementor/Block که فهرست محصولات را خارج از Shop/Home نمایش می‌دهند، URLهای اضافی را با filter `mobo_core_cache_purge_urls` اضافه کنید. پاک‌سازی Home نیز با `mobo_core_cache_purge_home_enabled` قابل کنترل است.

از نسخه `10.31.74` نتیجه آخرین Purge نیز داخل `cachePurge` گزارش سلامت ارسال می‌شود: وضعیت کلی، زمان آخرین تلاش و موفقیت کامل، نسخه Mobo، تعداد محصول/Object/URL، مدت اجرا، خطاهای متوالی و آخرین خطای محدودشده. برای WordPress/WooCommerce، WP Rocket، LiteSpeed Cache، W3 Total Cache، WP Super Cache و hookهای سفارشی، نسخه تست‌شده، نسخه فعلی و وضعیت `success`، `failed`، `not_detected` یا `not_tested` ثبت می‌شود.

<a id="en"></a>

## English overview

Mobo Core is a specialized WooCommerce integration for Iranian stores. It imports products, variations, categories, images, prices, and stock from MoboCore, then applies incremental changes delivered through webhooks. When explicitly enabled, it can also validate checkout data, map Mobo addresses and shipping methods, and submit eligible WooCommerce orders through the dedicated `mobomobo.ir` workflow.

This repository contains the WordPress plugin. The current compatible backend is Portal v38 on `.NET 10`; no customer-facing documentation or code is added to the .NET project. All publishable customer documentation is maintained inside `mobo-core`.

### Main capabilities

- Resumable chunked product and variation synchronization with page and cursor modes
- Changed-only processing for `ProductUpdated` and `UpdateVariant`
- Lightweight webhook notifications with payload retrieval through the Pull API
- Database-backed webhook queue with a protected JSON-file fallback
- Remote GUID mapping for WooCommerce products, variations, and categories
- Bounded image queue, controlled WebP refresh, and safe orphan cleanup
- Pricing rules, per-variation additional price, and batch repricing
- Mobo country/state mapping, generated Mobo city assets for checkout, and WooCommerce-to-Mobo shipping mapping
- Optional cart validation and asynchronous Mobo order submission
- Order-type SMS notifications through Persian WooCommerce SMS
- Real cron, loopback self-runner, health reporting, and diagnostics
- WooCommerce HPOS compatibility declaration


### Multi-round queue draining and renewable cron lease

Since `10.31.75`, one real-cron invocation no longer stops after a single fixed batch for most queues. The runner gives webhook, image, image-refresh, product-sync, reprice, recategorize, and queued-order work a fair share in repeated rounds until the safe PHP deadline is near, no due work remains, no progress is possible, the configured round cap is reached, or lease ownership is lost. The product-step setting is a per-round share rather than the total limit for the whole invocation.

The global runner lock is an atomic token-owned lease with a finite expiry. Its owner renews a heartbeat before each major stage. A concurrent invocation exits as `locked`; a crashed or killed process stops renewing and the lease expires automatically. The effective TTL expands to cover the runtime budget and the longest blocking network timeout, remains capped, and is never permanent. Losing lease ownership causes the current worker to stop before doing more protected work.

When useful work was completed but immediately runnable work remains, the runner dispatches a non-blocking local continuation even when the original request came from direct cPanel PHP cron. If loopback requests are unavailable, the next server-cron invocation remains the fallback. The health payload now includes token-free `cronRunner` telemetry with the last status, rounds, stop reason, elapsed time, renewals, failed stages, continuation decision, and current lease heartbeat/expiry.

### Targeted product cache invalidation

Since `10.31.73`, Mobo-linked product and variation saves are deduplicated during the request and invalidated once at shutdown. Mobo Core clears WooCommerce product transients and targeted WordPress post/object caches, then purges the product URL, current and removed product category/tag archives, Shop, and Home through the targeted APIs exposed by LiteSpeed Cache, WP Rocket, W3 Total Cache, and WP Super Cache when available. It never calls `wp_cache_flush()`, `rocket_clean_domain()`, `litespeed_purge_all`, or another full-site purge.

Custom Elementor/Block listing pages can be appended through `mobo_core_cache_purge_urls`. Homepage purging can be controlled through `mobo_core_cache_purge_home_enabled`.

Since `10.31.74`, the health payload includes structured `cachePurge` telemetry: overall status, last attempt/full-success times, Mobo version, affected counts, duration, consecutive failures, and a bounded last error. Each supported integration reports its tested/current version and `success`, `failed`, `not_detected`, or `not_tested` state.

### Requirements

| Component | Minimum / status |
|---|---|
| WordPress | `5.8+` |
| PHP | `7.4+` |
| WooCommerce | `8.2+` |
| Persian WooCommerce | Required; installed and active with slug `persian-woocommerce` |
| WooCommerce tested up to | `10.9` |
| Mobo Core | `10.31.81` |
| Compatible Portal | `v38 / .NET 10` |
| Outbound HTTP access | MoboCore and, when enabled, `mobomobo.ir` |

### Quick installation

1. Place the `mobo-core` directory in `/wp-content/plugins/`, or upload the ZIP through WordPress.
2. Install and activate WooCommerce and Persian WooCommerce (`persian-woocommerce`), then activate Mobo Core.
3. Open **Mobo > Purchase & Activation** to verify the account and license.
4. Open **Mobo > Connection** and enter the API base URL, Token, and Webhook Security Code.
5. Configure a Cron Token in **Mobo > Real Cron**, then add the server cron request.
6. Save country/state mapping, generate the Mobo city assets, and test shipping mapping before enabling automatic order submission.
7. Run one full Repair after upgrading a legacy installation such as version 7.

### Basic connection

```php
// wp-config.php
// Current built-in default in version 10.31.79:
define( 'MOBO_API_BASE_URL', 'http://mobo.codeya.ir/' );
```

Primary transport headers:

```http
Token: <portal-license-token>
X-SEC: <webhook-security-code>
```

`X-SEC` must contain visible ASCII only, with no whitespace. Letters, digits, and printable symbols are accepted; Persian characters, emoji, tabs, line breaks, and other Unicode values are rejected.

WordPress webhook endpoint:

```text
https://example.com/wp-json/mobo-core/v1/webhook
```

Real-cron endpoint:

```text
https://example.com/wp-json/mobo-core/v1/cron/run?token=<cron-token>
```

> Never commit the license Token, Security Code, Mobo account password, or Cron Token. Mobo account credentials are stored in WordPress options, so administrator access, database access, and backups must be protected.

### Documentation map

| File | Purpose |
|---|---|
| [`README_FULL.MD`](README_FULL.MD#en) | Installation, configuration, operations, security, troubleshooting, and customer guidance |
| [`FUNCTIONS.MD`](FUNCTIONS.MD#en) | REST API, endpoints, hooks, filters, options, tables, and PHP class reference |
| [`DIAGRAMS.MD`](DIAGRAMS.MD#en) | Architecture, sync, webhook, cron, checkout, health, and image diagrams |
| [`readme.txt`](readme.txt) | WordPress.org distribution metadata |

### Product scope

This is not a generic marketplace connector and must not be described as the official `mobomobo.ir` plugin unless the service owner grants that authorization. It is designed for WooCommerce stores operating in Iran and using the specific Mobo/Mobomobo workflow.

### Sales and support

- Sales and activation: `+989124508218` — [Telegram](https://t.me/yazdan_ghadiri) — [WhatsApp](https://wa.me/989124508218)
- Technical support: `+989367362228` — [Telegram](https://t.me/Codeya)
- Service: [mobo.codeya.ir](http://mobo.codeya.ir/)
- Repository: [PedramDev/mobo-core](https://github.com/PedramDev/mobo-core)

### License

GPLv2 or later. See [`LICENSE`](LICENSE).

---

<div align="center">

[Persian](#fa) · [English](#en) · [Full documentation](README_FULL.MD) · [Function reference](FUNCTIONS.MD) · [Diagrams](DIAGRAMS.MD)

</div>

## Direct health and external Cron proof

`healthcheck.php` provides an authenticated health response without relying on WordPress REST rewrites. Send the configured Webhook Security Code in the `X-SEC` header. `mobo-cron.php` records a best-effort pre-WordPress CLI marker, while only external sources confirm Real Cron; self-runner and administrator tests remain separate.

