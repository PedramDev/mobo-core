# PHP Capability Diagnostics — Mobo Core 10.31.77

## هدف

این قابلیت مشخص می‌کند کدام توابع PHP روی هاست واقعاً قابل فراخوانی هستند، کدام مورد در `disable_functions` یا محدودیت مشابه قرار گرفته و نبود هر تابع چه اثری روی Mobo Core دارد. هیچ تابع خطرناکی برای آزمون اجرا نمی‌شود.

## Runtime Probe مستقل از WordPress

```text
/wp-content/plugins/mobo-core/mobo-runtime-probe.php
```

درخواست باید Header زیر را داشته باشد:

```http
X-SEC: <Webhook Security Code>
Accept: application/json
Cache-Control: no-store
```

License خام در Query String پشتیبانی نمی‌شود. افزونه فقط SHA-256 کد امنیتی را در فایل محافظت‌شده زیر نگهداری می‌کند:

```text
wp-content/uploads/mobo-core/runtime-probe-auth.php
```

فایل بالا Secret اصلی را نگهداری نمی‌کند. اگر uploads سفارشی است، می‌توان مسیر cache را در `wp-config.php` تعریف کرد:

```php
define( 'MOBO_CORE_RUNTIME_PROBE_AUTH_FILE', '/absolute/private/path/runtime-probe-auth.php' );
```

برای endpoint مستقل که WordPress را load نمی‌کند، همان مسیر باید به‌صورت Environment Variable وب‌سرور/PHP-FPM نیز در دسترس باشد:

```text
MOBO_CORE_RUNTIME_PROBE_AUTH_FILE=/absolute/private/path/runtime-probe-auth.php
```

## وضعیت‌ها

- `available`: تابع وجود دارد و callable است.
- `disabled-by-host`: نام تابع در `disable_functions` یا blacklist گزارش شده است.
- `unavailable`: تابع وجود ندارد یا callable نیست؛ معمولاً Extension مربوط نصب نشده یا میزبان آن را به شکلی غیرقابل مشاهده محدود کرده است.

هر تابع دارای `requiredLevel` است:

- `critical`: نبود آن می‌تواند Runtime اصلی Mobo Core را متوقف کند.
- `warning`: یک Feature، fallback یا بخش تشخیصی محدود می‌شود.
- `info`: تابع برای عملکرد اصلی لازم نیست؛ مانند `phpinfo`, `exec`, `shell_exec`.

## رفتار phpinfo

`mobo-phpinfo.php` فقط برای مدیر لاگین‌شده و Nonce معتبر قابل دسترسی است. اگر `phpinfo()` غیرفعال باشد، پاسخ کنترل‌شده `501` برمی‌گردد و Fatal Error ایجاد نمی‌شود. غیرفعال بودن `phpinfo()` به‌تنهایی خرابی Mobo Core یا Cron را نشان نمی‌دهد.

## CDN/WAF

مسیر Runtime Probe نباید Cache شود و Header `X-SEC` باید بدون حذف به Origin برسد. پاسخ HTML، Challenge یا پاسخ Cache شده به‌عنوان JSON معتبر Mobo Core پذیرفته نمی‌شود.

The report also returns the complete normalized `disable_functions` list, including functions outside the audited Mobo Core catalog. Portal displays that raw list separately from impact/severity analysis.
