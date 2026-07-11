<div align="center">

# Mobo Core for WooCommerce

**اتصال کنترل شده فروشگاه ووکامرس به MoboCore و جریان کاری mobomobo.ir**  
**Controlled WooCommerce integration with MoboCore and the mobomobo.ir workflow**

![Plugin Version](https://img.shields.io/badge/Mobo_Core-10.31.58-1f6feb)
![Portal](https://img.shields.io/badge/Portal-v25%20%2F%20.NET%2010-512bd4)
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
- اعلام سازگاری با WooCommerce HPOS

### نیازمندی ها

| مورد | حداقل / وضعیت |
|---|---|
| WordPress | `5.8+` |
| PHP | `7.4+` |
| WooCommerce | `8.2+` |
| ووکامرس فارسی | نصب و فعال، slug: `persian-woocommerce` |
| WooCommerce tested up to | `10.9` |
| Mobo Core | `10.31.58` |
| Portal سازگار | `v25 / .NET 10` |
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
// مقدار فعلی پیش فرض افزونه در نسخه 10.31.58:
define( 'MOBO_API_BASE_URL', 'http://mobo.codeya.ir/' );
```

هدرهای اصلی ارتباط:

```http
Token: <portal-license-token>
X-SEC: <webhook-security-code>
```

مسیر دریافت وب هوک در سایت وردپرسی:

```text
https://example.com/wp-json/mobo-core/v1/webhook
```

مسیر کران واقعی:

```text
https://example.com/wp-json/mobo-core/v1/cron/run?token=<cron-token>
```

> Token، Security Code، رمز حساب موبو و Cron Token را داخل مخزن Git ثبت نکنید. اطلاعات حساب موبو در WordPress options ذخیره می شود؛ دسترسی مدیر، دیتابیس و فایل های backup باید محدود باشد.

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

<a id="en"></a>

## English overview

Mobo Core is a specialized WooCommerce integration for Iranian stores. It imports products, variations, categories, images, prices, and stock from MoboCore, then applies incremental changes delivered through webhooks. When explicitly enabled, it can also validate checkout data, map Mobo addresses and shipping methods, and submit eligible WooCommerce orders through the dedicated `mobomobo.ir` workflow.

This repository contains the WordPress plugin. The current compatible backend is Portal v25 on `.NET 10`; no customer-facing documentation or code is added to the .NET project. All publishable customer documentation is maintained inside `mobo-core`.

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

### Requirements

| Component | Minimum / status |
|---|---|
| WordPress | `5.8+` |
| PHP | `7.4+` |
| WooCommerce | `8.2+` |
| Persian WooCommerce | Required; installed and active with slug `persian-woocommerce` |
| WooCommerce tested up to | `10.9` |
| Mobo Core | `10.31.58` |
| Compatible Portal | `v25 / .NET 10` |
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
// Current built-in default in version 10.31.58:
define( 'MOBO_API_BASE_URL', 'http://mobo.codeya.ir/' );
```

Primary transport headers:

```http
Token: <portal-license-token>
X-SEC: <webhook-security-code>
```

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
