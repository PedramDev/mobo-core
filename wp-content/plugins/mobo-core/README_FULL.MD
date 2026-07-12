# Mobo Core — Full Documentation / مستندات کامل

<div align="center">

**Version `10.31.62` · Compatible with Portal `v25 / .NET 10`**

[فارسی](#fa) · [English](#en) · [راهنمای کامل](README_FULL.MD) · [مرجع توابع](FUNCTIONS.MD) · [نمودارها](DIAGRAMS.MD)

</div>

---

<a id="fa"></a>

# مستندات کامل فارسی

## 1. هدف و معماری محصول

Mobo Core لایه اتصال بین فروشگاه WooCommerce و سرویس MoboCore است. افزونه اطلاعات کاتالوگ را از API دریافت می کند، شناسه های راه دور را به آبجکت های محلی نگاشت می کند، تغییرات را به صورت idempotent اعمال می کند و عملیات سنگین را در صف های محدود و قابل ادامه اجرا می کند.

اجزای اصلی:

1. **MoboCore Portal v25**: API، لایسنس، داده محصول، payload رویدادها، Health و نگاشت های مرکزی.
2. **Mobo Core 10.31.62**: افزونه وردپرس، صف محلی، Sync، نگاشت، تصاویر، Checkout و عملیات مشتری.
3. **WooCommerce**: منبع نهایی محصول، تنوع، دسته بندی، رسانه، سبد و سفارش در سایت مشتری.
4. **mobomobo.ir**: جریان اختصاصی بررسی سبد و ثبت سفارش که فقط در صورت فعال سازی تنظیمات Checkout استفاده می شود.

نمودارهای کامل در [`DIAGRAMS.MD`](DIAGRAMS.MD#fa) قرار دارند.

## 2. سازگاری نسخه

| جزء | نسخه / وضعیت |
|---|---|
| Mobo Core | `10.31.62` |
| Portal | `v25` |
| Target Framework پرتال | `net10.0` |
| WordPress | `5.8+` |
| PHP | `7.4+` |
| WooCommerce | `8.2+`، تست شده تا `10.9` |
| ووکامرس فارسی | اجباری؛ نصب و فعال با slug برابر `persian-woocommerce` |
| HPOS | اعلام سازگاری شده است |
| Cursor sync | محصول و تنوع پشتیبانی می شوند |
| Lightweight webhook pull | پشتیبانی می شود |

افزونه همچنان از درخواست های Page Number قدیمی پشتیبانی می کند و در نسخه جدید، Cursor را برای پایداری بیشتر Sync اولیه ترجیح می دهد.

## 3. نصب و ارتقا

### نصب جدید

1. از دیتابیس و فایل های سایت backup بگیرید.
2. WooCommerce و افزونه «ووکامرس فارسی» (`persian-woocommerce`) را نصب و فعال کنید.
3. ZIP افزونه را از **افزونه ها > افزودن افزونه > بارگذاری** نصب کنید.
4. افزونه را فعال کنید؛ Migration داخلی optionهای پیش فرض و جدول های محلی را ایجاد می کند.
5. از **موبو > اتصال** ارتباط را تنظیم کنید.
6. قبل از شروع Sync، Cron واقعی را آماده کنید.

### ارتقا از نسخه قدیمی

1. backup کامل بگیرید.
2. افزونه را جایگزین و فعال کنید.
3. وارد داشبورد موبو شوید و وضعیت Migration را بررسی کنید.
4. برای نصب هایی که از ساختارهای قدیمی مانند نسخه 7 آمده اند، **Repair کامل محصولات** را اجرا کنید.
5. تا پایان Repair، پاک سازی orphan image و عملیات مخرب تصویر را اجرا نکنید.
6. بعد از Repair، دسته بندی، تصاویر، قیمت و چند محصول متغیر را به صورت نمونه بررسی کنید.

Repair برای بازسازی map داخلی، متادیتای GUID، hashها و وضعیت صف ها استفاده می شود و رکوردهای عادی WooCommerce را بدون نیاز حذف نمی کند.

## 4. تنظیم اتصال

مسیر: **موبو > اتصال**

| فیلد | کاربرد |
|---|---|
| API Base URL | آدرس ریشه API پرتال |
| Token | GUID لایسنس/دسترسی مشتری برای درخواست های خروجی افزونه |
| Webhook Security Code | مقدار مشترک `X-SEC` برای درخواست های ورودی و Pull API |

اولویت تعیین API Base URL:

1. فیلتر `mobo_core_api_base_url`
2. ثابت `MOBO_API_BASE_URL`
3. option با نام `mobo_core_api_base_url`

نمونه در `wp-config.php`:

```php
define( 'MOBO_API_BASE_URL', 'http://mobo.codeya.ir/' );
```

تست پایه:

```bash
curl -H 'Token: YOUR_TOKEN'   'http://mobo.codeya.ir/LicenseInfo'
```

Token و کد امنیتی باید با اطلاعات Webhook/License در پرتال یکسان باشند.

## 5. پنل مدیریت افزونه

پنل افزونه یک منوی اصلی با تب های زیر دارد:

| تب | وظیفه |
|---|---|
| داشبورد | وضعیت Sync، پیشرفت، خطا، Repair و سلامت صف ها |
| اتصال | API Base URL، Token و Security Code |
| خرید و فعال سازی | وضعیت LicenseInfo و اطلاعات فروش |
| محصول | batch size، موجودی، Cursor و رفتار تنوع های حذف شده |
| دسته بندی | Sync دسته ها، mapping دستی و reapply دسته بندی |
| قیمت گذاری | قواعد قیمت، افزایش قیمت تنوع و Reprice |
| فیلترها | URLهای مستثنا و قوانین انتخاب محصول |
| صف و پردازش | وب هوک ها، retry و وضعیت صف های داخلی |
| نوسازی تصاویر | اسکن قدیمی، WebP refresh و orphan cleanup |
| کران واقعی | token، بودجه اجرا، interval و وضعیت آخرین run |
| اعتبارسنجی خرید | بررسی سبد، ورود موبو، سفارش خودکار، آدرس و ارسال |
| پیامک سفارش | سناریوی غیرموبو، فقط موبو و ترکیبی |
| سلامت سایت | گزارش دوره ای و ارسال دستی Health Report |

## 6. همگام سازی محصول

### جریان Sync کامل

1. افزونه یک `syncId` تولید یا دریافت می کند.
2. دسته بندی ها در صورت نیاز Sync می شوند.
3. محصولات از `/get-products` در batchهای کوچک دریافت می شوند.
4. در Cursor mode، پاسخ `nextCursor` و `hasMore` را برمی گرداند.
5. هر محصول با `product_guid` در `wp_mobo_product_map` نگاشت می شود.
6. تنوع ها از `/{productGuid}/get-variants` دریافت و با `variant_guid` نگاشت می شوند.
7. Hashها از نوشتن غیرضروری جلوگیری می کنند.
8. تصاویر در صف جداگانه قرار می گیرند.
9. وضعیت Sync ذخیره می شود و در run بعد ادامه پیدا می کند.

### رفتار تنوع گمشده

مقدار پیش فرض `mobo_core_missing_variants_behavior` برابر `outofstock` است؛ بنابراین تنوعی که دیگر در payload جدید وجود ندارد، به جای حذف فیزیکی، ناموجود می شود. این رفتار برای حفظ idempotency و جلوگیری از شکستن سفارش ها و لینک های قبلی مناسب است.

### جلوگیری از همزمانی ناسالم

- lock عمومی برای شروع و اجرای Sync
- lock سطح محصول برای جلوگیری از برخورد Repair، Webhook، Reprice و Recategorize
- شناسایی GUID تکراری و امکان quarantine محصول های duplicate
- batchهای محدود برای هاست های ضعیف

## 7. وب هوک و Pull API

رویدادهای پردازش شده:

| Event | کاربرد |
|---|---|
| `ProductUpdated` | تغییر اطلاعات اصلی، ارتباطات، دسته بندی، attribute و تصویر محصول |
| `UpdateVariant` | تغییر یا ناموجود شدن تنوع ها |
| `ShippingMethodsChanged` | بروزرسانی snapshot روش های ارسال موبو |
| `WebhookDeliveryStatusChanged` | ذخیره وضعیت تحویل گزارش شده از پرتال |

مسیر ورودی:

```http
POST /wp-json/mobo-core/v1/webhook
X-SEC: <security-code>
Content-Type: application/json
```

در حالت Lightweight، وب هوک فقط metadata و `changesUrl` را می فرستد. افزونه payload اصلی را با `X-SEC` و `Token` دریافت می کند. اگر payload کامل از قبل وجود داشته باشد، درخواست Pull غیرضروری انجام نمی شود.

صف جدید ابتدا از جدول `wp_mobo_sync_events` استفاده می کند. اگر جدول در دسترس نباشد یا enqueue شکست بخورد، فایل JSON در `wp-content/uploads/mobo-core/webhook-files/` ذخیره می شود. مسیر fallback با `index.php` و `.htaccess` محافظت می شود.

## 8. Cron واقعی و Self Runner

Mobo Core برای عملیات حیاتی به WP-Cron وابسته نیست. Endpoint کران واقعی:

```text
/wp-json/mobo-core/v1/cron/run?token=<cron-token>
```

نمونه cPanel/cron:

```cron
* * * * * curl -fsS --max-time 55 'https://example.com/wp-json/mobo-core/v1/cron/run?token=REPLACE_WITH_TOKEN' >/dev/null 2>&1
```

در هر slice، Runner با بودجه زمانی محدود می تواند وب هوک، Sync، تصویر، Reprice، Recategorize، سفارش های queued، Health و maintenance را پردازش کند. lock داخلی مانع اجرای همزمان sliceهای تکراری می شود.

Self Runner با loopback request غیرمسدودکننده، بعد از دریافت وب هوک یا شروع Sync یک اجرای محلی را kick می کند. Cron واقعی همچنان مسیر قابل اتکاتر برای production است.

## 9. دسته بندی و Mapping

افزونه دو مفهوم را جدا نگه می دارد:

- `synced_term_id`: دسته ای که از MoboCore ساخته یا بروزرسانی شده است.
- `manual_term_id`: دسته محلی انتخاب شده توسط مدیر برای assignment محصول.

در Sync خود دسته بندی، synced term بروزرسانی می شود؛ در assignment محصول، manual mapping در اولویت است. این جداسازی اجازه می دهد ساختار دسته بندی فروشگاه با ساختار منبع یکسان نباشد.

بعد از تغییر mapping، از صف Recategorize برای اعمال دسته های جدید روی محصولات منتشر شده استفاده کنید.

## 10. قیمت گذاری

قیمت خروجی توسط `Mobo_Core_Price_Calculator` ساخته می شود و می تواند از موارد زیر تاثیر بگیرد:

- قیمت و compare price دریافتی API
- قواعد legacy/global
- نوع قیمت گذاری
- درصد یا مقدار افزایش قیمت
- فیلد اختصاصی `mobo_additional_price` در هر variation
- filter توسعه دهنده `mobo_core_calculated_price_pair`

Reprice به صورت cursor/batch اجرا می شود تا نیاز به Sync کامل یا درخواست مجدد تمام payloadها نباشد.

## 11. تصاویر

### صف Sync معمولی

جدول `wp_mobo_image_queue` وضعیت import تصاویر هر محصول را نگه می دارد. تعداد پردازش در هر run محدود است و retry با backoff انجام می شود.

### نوسازی WebP

فرآیند Refresh جدا از صف معمولی است:

1. تصویر raster قدیمی شناسایی می شود.
2. job در `wp_mobo_image_refresh_queue` ساخته می شود.
3. WebP جدید import می شود.
4. محصول به attachment جدید وصل می شود.
5. فقط پس از اثبات عدم استفاده، فایل/attachment قدیمی حذف می شود.

### پاک سازی orphan

پاک سازی فقط بعد از یک Repair موفق unlock می شود. فایل باید داخل uploads باشد، Media Library آن را نشناسد، در content/meta/options مرجع نداشته باشد و WebP متناظر معتبر باشد.

## 12. Checkout و ثبت سفارش

تمام قابلیت های حساس Checkout در نصب جدید خاموش هستند. وقتی validation و order submission خاموش باشند، افزونه نباید در runtime مشتری دخالت کند و WooCommerce/افزونه های حمل ونقل مالک جریان Checkout می مانند.

قابلیت های اختیاری:

- بررسی موجودی محلی
- بررسی کامل بودن GUID و Sync
- اعتبارسنجی سبد در mobomobo.ir
- اعتبارسنجی خارجی سفارشی
- نگاشت کشور، استان و شهر
- انتخاب روش ارسال موبو بر اساس rate ووکامرس، استان و بازه زمانی
- ثبت خودکار سفارش موبو

ثبت سفارش خودکار زمانی queue می شود که سفارش واجد محصول موبو وارد وضعیت `processing` شود. سفارش های غیرموبو از این جریان خارج می مانند. در سفارش ترکیبی فقط اقلام موبو به موبو ارسال می شوند و سفارش WooCommerce برای انجام اقلام غیرموبو در وضعیت `processing` باقی می ماند. گزینه تکمیل خودکار فقط برای سفارش هایی اعمال می شود که تمام اقلام آنها موبو باشند.


### قفل تنظیمات صف هنگام Sync و Repair

در زمان اجرای Sync کامل یا Repair، تنظیمات تب «صف و پردازش» قفل می شوند. تغییر `products_per_page` یا `variants_per_page` در اجرای page-based می تواند مرز صفحه را جابه جا کند و باعث رد شدن یا پردازش دوباره رکوردها شود؛ تغییر cursor mode نیز strategy ادامه را عوض می کند. قفل هم در UI و هم در مسیر ذخیره سمت سرور اعمال می شود و direct `update_option()` نیز تا پایان یا لغو عملیات مقدار قبلی را حفظ می کند. وضعیت `waiting_for_portal` هم بخشی از اجرای فعال محسوب می شود.

### تنظیمات ووکامرس فارسی برای ثبت خودکار

برای عملکرد صحیح ثبت سفارش خودکار، گزینه «فعالسازی شهرهای ایران» باید در تنظیمات افزونه ووکامرس فارسی فعال باشد. Mobo Core فقط این الزام را در پنل یادآوری می‌کند و این تنظیم را نمی‌خواند یا تغییر نمی‌دهد.

در نسخه `10.31.56` نگاشت دستی شهر حذف شده است. افزونه ابتدا کشورها، استان ها و شهرهای موبو را از MoboCore دریافت می کند، سپس در مسیر uploads دو فایل `iran_cities.js` و `iran_cities.min.js` می سازد. این فایل ها همان تابع سازگار `Persian_Woo_iranCities()` را ارائه می کنند، اما مقدار هر گزینه شهر شناسه واقعی `city_id` موبو است. Mobo Core از فیلتر `persian_woo_iran_cities` استفاده می کند و در مرحله نهایی فقط URL و version همان handle موجود `pw-iran-cities` را تغییر می دهد؛ handle حذف و دوباره ثبت نمی شود تا dependency، inline data و ترتیب اجرای ووکامرس فارسی حفظ شود. نگاشت کشور و استان همچنان باقی می ماند، چون WooCommerce استان را با کد محلی مانند `THR` نگه می دارد و موبو از `state_id` استفاده می کند. فایل قدیمی شهرها فقط برای تشخیص alias استان و سازگاری سفارش های قبلی استفاده می شود و منبع شهرهای Checkout نیست.

قبل از فعال سازی production:

1. ورود حساب موبو را تست کنید.
2. نگاشت آدرس را sync و بررسی کنید.
3. روش ارسال WooCommerce را به ID موبو نگاشت کنید.
4. یک سفارش آزمایشی با یک variation واقعی ثبت کنید.
5. log و order meta box موبو را بررسی کنید.
6. رفتار retry و duplicate submission را آزمایش کنید.

## 13. پیامک سفارش

ارسال مستقیم gateway داخل Mobo Core پیاده سازی نشده است. افزونه از `PWSMS()->send_sms()` متعلق به «پیامک حرفه ای ووکامرس» استفاده می کند.

سناریوها:

- `non_mobo`: سفارش بدون محصول موبو
- `mobo_only`: فقط محصولات موبو
- `mixed`: ترکیب محصول موبو و عادی

Placeholderهای اختصاصی:

```text
{mobo_order_type}
{mobo_order_type_label}
{mobo_items_count}
{non_mobo_items_count}
{customer_mobile}
```

هر سناریو فقط یک بار برای سفارش ارسال می شود و نتیجه در order meta ثبت می شود.

## 14. Health Report

Endpoint محلی برای Probe پرتال:

```http
GET /wp-json/mobo-core/v1/health
X-SEC: <security-code>
```

ارسال گزارش از افزونه به پرتال:

```http
POST /api/site-health/report
X-SEC: <security-code>
Content-Type: application/json
```

گزارش می تواند نسخه WordPress/PHP/WooCommerce، وضعیت Debug، memory، disk، cron، Sync، صف وب هوک و Action Scheduler را شامل شود. ارسال Health در نصب جدید به صورت پیش فرض خاموش است.

## 15. REST API افزونه

تمام routeها زیر namespace زیر هستند:

```text
/wp-json/mobo-core/v1
```

| Method | Route | امنیت | کاربرد |
|---|---|---|---|
| POST | `/categories/ensure-sync` | `X-SEC` | Sync دسته ها در صورت نیاز |
| GET/POST | `/cron/run` | Cron Token یا `X-SEC` | اجرای یک slice |
| GET | `/cron/status` | `X-SEC` | وضعیت Cron |
| GET/POST | `/worker/run` | Cron Token یا `X-SEC` | اجرای Self Worker |
| GET | `/worker/status` | `X-SEC` | وضعیت Self Runner |
| GET | `/health` | `X-SEC` | سلامت محلی |
| GET/POST | `/health/report-now` | `X-SEC` | ارسال فوری Health |
| POST | `/webhook` | `X-SEC` | پذیرش رویداد |
| POST | `/webhook/run` | `X-SEC` | پردازش صف وب هوک |
| POST | `/sync/start` | `X-SEC` | شروع Sync |
| POST | `/sync/run` | `X-SEC` | اجرای یک مرحله Sync |
| GET | `/sync/status` | `X-SEC` | وضعیت Sync |
| POST | `/sync/cancel` | `X-SEC` | لغو Sync |

جزئیات توسعه دهنده در [`FUNCTIONS.MD`](FUNCTIONS.MD#fa) آمده است.

## 16. جداول محلی

با درنظر گرفتن prefix وردپرس:

| جدول | کاربرد |
|---|---|
| `wp_mobo_product_map` | GUID محصول/variation به post ID و hash |
| `wp_mobo_category_map` | remote category به synced/manual term |
| `wp_mobo_sync_events` | صف دیتابیسی وب هوک و progress |
| `wp_mobo_image_queue` | صف import تصویر |
| `wp_mobo_image_refresh_queue` | صف نوسازی WebP |
| `wp_mobo_orphan_image_files` | کاندیدهای پاک سازی فایل قدیمی |

داده های runtime دیگری مانند وضعیت Sync، Cron و آخرین Health در WordPress options نگهداری می شوند.

## 17. امنیت

- تمام routeهای عملیاتی با `X-SEC` یا Cron Token محافظت می شوند.
- مقایسه secret با `hash_equals` انجام می شود.
- درخواست های خروجی با WordPress HTTP API ارسال می شوند.
- SSL verification برای HTTPS به صورت پیش فرض فعال است و فقط با filter توسعه دهنده قابل تغییر است.
- URLهای تصویر محلی/خصوصی ناامن به صورت پیش فرض مجاز نیستند.
- عملیات مدیر با capability، nonce و sanitize انجام می شوند.
- صف ها lock، retry limit، expiration و batch limit دارند.
- فایل های fallback وب هوک در uploads محافظت می شوند.
- secrets را در Git، screenshot، ticket عمومی یا log خام قرار ندهید.
- حساب WordPress مدیر و database backup باید جداگانه محافظت شوند.

## 18. خطایابی

### LicenseInfo خطا می دهد

- API Base URL و Token را بررسی کنید.
- trailing slash را درست نگه دارید.
- دسترسی DNS/HTTP از سرور وردپرس را تست کنید.
- پاسخ endpoint `LicenseInfo` را با curl بررسی کنید.

### وب هوک 401 می گیرد

- `X-SEC` پرتال و `mobo_core_security_code` باید دقیقاً یکسان باشند.
- reverse proxy نباید هدر را حذف کند.
- cache/CDN نباید routeهای `/wp-json/mobo-core/v1/*` را cache کند.

### Sync قفل شده است

- وضعیت `/sync/status` را بخوانید.
- اجرای همزمان Cron یا Worker را بررسی کنید.
- از Reset فقط بعد از اطمینان از نبود process فعال استفاده کنید.
- duplicate GUID و product-level locks را بررسی کنید.

### تصاویر کامل نمی شوند

- Repair legacy را تمام کنید.
- queue status و آخرین error را بررسی کنید.
- MIME و دسترسی uploads را بررسی کنید.
- در هاست ضعیف، batch تصویر را کم نگه دارید.

### سفارش موبو ثبت نمی شود

- سفارش باید حداقل یک محصول موبو و وضعیت `processing` داشته باشد.
- login test، address mapping و shipping mapping را بررسی کنید.
- order meta box و WooCommerce logs را بخوانید.
- Cron واقعی باید queue سفارش را پردازش کند.

## 19. چک لیست Production

- [ ] backup کامل تهیه شده است.
- [ ] Token و Security Code تست شده اند.
- [ ] routeهای WordPress REST از بیرون قابل دسترس هستند.
- [ ] Real Cron هر دقیقه اجرا می شود.
- [ ] یک Sync کامل یا Repair موفق انجام شده است.
- [ ] دسته بندی و variation نمونه کنترل شده اند.
- [ ] صف وب هوک backlog غیرعادی ندارد.
- [ ] نگاشت آدرس و روش ارسال تست شده است.
- [ ] ثبت سفارش خودکار قبل از production با سفارش آزمایشی تست شده است.
- [ ] Health reporting در صورت نیاز فعال و endpoint آن بررسی شده است.
- [ ] logها و backupها secret خام ندارند.

## 20. سیاست نگهداری ترجمه

هر بخش فارسی و انگلیسی در همین فایل شماره یکسان دارد. هنگام تغییر رفتار افزونه، ابتدا متن فنی را با کد نسخه جدید تطبیق دهید و سپس بخش متناظر زبان دیگر را در همان commit بروزرسانی کنید. لینک های `#fa` و `#en` برای جابه جایی مستقیم بین دو نسخه نگهداری شده اند.


### رفتار محصول ساده در نسخه 10.31.51

محصول ساده ووکامرس نیز در موبو یک Variant قابل خرید دارد. افزونه این `portal_variant_id` را روی خود `WC_Product_Simple` ذخیره می کند، قیمت و موجودی را از همان واحد خرید اعمال می کند و در صورت نبود یا چندمعنا بودن Variant، محصول را ناموجود و `mobo_sync_incomplete` می کند. هنگام فعال بودن ثبت خودکار سفارش، checkout باید افزودن واقعی همه آیتم ها به سبد احراز هویت شده موبو و snapshot با `update=true` را با موفقیت پشت سر بگذارد.

## 21. لینک ها و پشتیبانی

- [`README.MD`](README.MD#fa)
- [`FUNCTIONS.MD`](FUNCTIONS.MD#fa)
- [`DIAGRAMS.MD`](DIAGRAMS.MD#fa)
- [Repository](https://github.com/PedramDev/mobo-core)
- [MoboCore service](http://mobo.codeya.ir/)
- فروش: `+989124508218`
- فنی: `+989367362228`

---

<a id="en"></a>

# Full English documentation

## 1. Product purpose and architecture

Mobo Core is the integration layer between WooCommerce and MoboCore. It retrieves catalog data from the Portal API, maps remote identifiers to local objects, applies changes idempotently, and moves expensive work into bounded resumable queues.

Primary components:

1. **MoboCore Portal v25**: API, licensing, product data, event payloads, health, and central mappings.
2. **Mobo Core 10.31.62**: WordPress integration, local queues, sync, mapping, images, checkout, and customer-side operations.
3. **WooCommerce**: the final product, variation, category, media, cart, and order store on the customer site.
4. **mobomobo.ir**: the dedicated cart-check and order-submission workflow, used only when checkout features are enabled.

See [`DIAGRAMS.MD`](DIAGRAMS.MD#en) for the complete diagrams.

## 2. Version compatibility

| Component | Version / status |
|---|---|
| Mobo Core | `10.31.62` |
| Portal | `v25` |
| Portal target framework | `net10.0` |
| WordPress | `5.8+` |
| PHP | `7.4+` |
| WooCommerce | `8.2+`, tested through `10.9` |
| Persian WooCommerce | Required; installed and active with slug `persian-woocommerce` |
| HPOS | compatibility declared |
| Cursor sync | supported for products and variations |
| Lightweight webhook pull | supported |

Legacy page-number requests remain supported. New installations prefer cursor pagination for more stable initial synchronization.

## 3. Installation and upgrade

### New installation

1. Back up the database and site files.
2. Install and activate WooCommerce and Persian WooCommerce (`persian-woocommerce`).
3. Upload the plugin ZIP through **Plugins > Add New > Upload Plugin**.
4. Activate Mobo Core; its migration creates defaults and local tables.
5. Configure the connection under **Mobo > Connection**.
6. Configure real cron before starting a production sync.

### Legacy upgrade

1. Take a complete backup.
2. Replace and activate the plugin.
3. Open the Mobo dashboard and inspect migration status.
4. Run one full **Product Repair** for installations upgraded from older structures such as version 7.
5. Do not run orphan cleanup or destructive image maintenance before Repair completes.
6. Verify categories, images, pricing, and representative variable products after Repair.

Repair rebuilds internal maps, GUID metadata, hashes, and queue state without arbitrarily deleting ordinary WooCommerce records.

## 4. Connection configuration

Path: **Mobo > Connection**

| Field | Purpose |
|---|---|
| API Base URL | Portal API root URL |
| Token | Customer license/access GUID used by outbound requests |
| Webhook Security Code | Shared `X-SEC` secret for inbound requests and payload pulls |

API base URL priority:

1. `mobo_core_api_base_url` filter
2. `MOBO_API_BASE_URL` constant
3. `mobo_core_api_base_url` WordPress option

Example:

```php
define( 'MOBO_API_BASE_URL', 'http://mobo.codeya.ir/' );
```

Basic test:

```bash
curl -H 'Token: YOUR_TOKEN'   'http://mobo.codeya.ir/LicenseInfo'
```

The Token and security code must match the customer license and webhook record in Portal.

## 5. Administration tabs

| Tab | Responsibility |
|---|---|
| Dashboard | Sync progress, errors, Repair, and queue health |
| Connection | API base URL, Token, and Security Code |
| Purchase & Activation | LicenseInfo status and sales information |
| Product | Batch sizes, stock, cursor mode, and missing-variation behavior |
| Categories | Category sync, manual mapping, and recategorization |
| Pricing | Pricing rules, additional variation price, and repricing |
| Filters | Excluded URLs and product selection rules |
| Queue & Processing | Webhooks, retries, and internal queue state |
| Image Refresh | Legacy scan, WebP refresh, and orphan cleanup |
| Real Cron | Token, runtime budget, interval, and last-run state |
| Checkout Validation | Cart checks, Mobo login, order submission, address, and shipping |
| Order SMS | Non-Mobo, Mobo-only, and mixed scenarios |
| Site Health | Periodic health report and manual report action |

## 6. Product synchronization

### Full-sync flow

1. The plugin generates or accepts a `syncId`.
2. Categories are synchronized when due.
3. Products are fetched from `/get-products` in small batches.
4. Cursor mode consumes `nextCursor` and `hasMore`.
5. Each product is mapped by `product_guid` in `wp_mobo_product_map`.
6. Variations are fetched from `/{productGuid}/get-variants` and mapped by `variant_guid`.
7. Hash comparison avoids unnecessary writes.
8. Images are delegated to a separate queue.
9. Sync state is persisted and resumed on the next slice.

The default missing-variation policy is `outofstock`, not physical deletion. Locks prevent overlapping manual sync, Repair, webhook, reprice, and recategorization writers.

## 7. Webhook and Pull API

Supported events:

- `ProductUpdated`
- `UpdateVariant`
- `ShippingMethodsChanged`
- `WebhookDeliveryStatusChanged`

Inbound endpoint:

```http
POST /wp-json/mobo-core/v1/webhook
X-SEC: <security-code>
Content-Type: application/json
```

In lightweight mode, Portal sends metadata and a `changesUrl`. The plugin retrieves the real payload with `X-SEC` and `Token`. The primary queue is `wp_mobo_sync_events`; protected JSON files under `wp-content/uploads/mobo-core/webhook-files/` are used only as a fallback.

## 8. Real cron and self-runner

Production endpoint:

```text
/wp-json/mobo-core/v1/cron/run?token=<cron-token>
```

Example:

```cron
* * * * * curl -fsS --max-time 55 'https://example.com/wp-json/mobo-core/v1/cron/run?token=REPLACE_WITH_TOKEN' >/dev/null 2>&1
```

Each bounded slice may process webhooks, sync, images, repricing, recategorization, queued orders, health, and maintenance. The self-runner uses non-blocking loopback requests to accelerate progress after a webhook or sync start, but real cron remains the recommended production scheduler.

## 9. Category mapping

`synced_term_id` and `manual_term_id` are intentionally separate. Category synchronization updates the synced term, while product assignment prefers a configured manual term. Run Recategorize after changing mappings.

## 10. Pricing

Pricing can include API price/compare price, legacy rules, global adjustments, the per-variation `mobo_additional_price` field, and the `mobo_core_calculated_price_pair` filter. Reprice runs in bounded batches without requiring a full catalog sync.

## 11. Images

Normal imports use `wp_mobo_image_queue`. WebP refresh uses the separate `wp_mobo_image_refresh_queue` and deletes an old attachment only after the new attachment is imported, assigned, and the old file is proven unused. Orphan cleanup stays locked until a successful Repair has completed.

## 12. Checkout and order submission

Sensitive checkout features are disabled by default. When all validation and submission options are disabled, Mobo Core stays out of the customer checkout runtime.

Optional features include local stock checks, remote cart validation, external validation, address mapping, shipping mapping, and automatic Mobo order submission. An eligible order is queued when it contains at least one Mobo item and enters `processing`. Non-Mobo orders are excluded. Successful submissions may move the WooCommerce order to `completed` when auto-complete is enabled.


### Queue settings lock during Sync and Repair

While a full Sync or Repair run is active, the Queue and Processing tab is immutable. Changing `products_per_page` or `variants_per_page` during page-based pagination can move page boundaries and skip or duplicate records; changing cursor mode changes the continuation strategy. The guard applies in the UI, in the server-side save handler, and to direct `update_option()` writes. `waiting_for_portal` is treated as an active resumable run until it is completed or cancelled.

### Persian WooCommerce settings for automatic submission

When automatic Mobo order submission is enabled, the Persian WooCommerce options “Enable Iran cities” and “Swap state and city fields” must be enabled. Mobo Core now displays guidance only: it does not read, modify, block, or periodically inspect these settings, and it does not stop checkout or order submission based on their values.

Version `10.31.56` removes manual city-to-city mapping. The plugin first downloads countries, states, and cities from MoboCore, then generates `iran_cities.js` and `iran_cities.min.js` under the WordPress uploads directory. Both files expose the Persian WooCommerce-compatible `Persian_Woo_iranCities()` function, while every city option value is the authoritative Mobo `city_id`. Mobo Core replaces the source through the `persian_woo_iran_cities` filter and, as a final safeguard, mutates only the existing `pw-iran-cities` source/version without deregistering the handle, preserving dependencies, inline data, and execution order. Country and state mapping remain because WooCommerce stores province codes such as `THR`, while Mobo uses numeric `state_id` values. The legacy bundled city source is retained only for province aliases and old-order compatibility, not as the current checkout city source.

## 13. Order SMS

Mobo Core delegates SMS delivery to Persian WooCommerce SMS through `PWSMS()->send_sms()`. Scenarios are `non_mobo`, `mobo_only`, and `mixed`. Mobo-specific placeholders include order type labels and Mobo/non-Mobo item counts.

## 14. Health reporting

Portal can probe:

```http
GET /wp-json/mobo-core/v1/health
X-SEC: <security-code>
```

The plugin can report detailed site state to:

```http
POST /api/site-health/report
X-SEC: <security-code>
```

Health reporting is disabled by default on fresh installations.

## 15. Plugin REST API

All routes use `/wp-json/mobo-core/v1`. Operational routes require `X-SEC`; cron/worker run routes accept the Cron Token and support `X-SEC` as fallback. See [`FUNCTIONS.MD`](FUNCTIONS.MD#en) for the complete route table.

## 16. Local tables

- `wp_mobo_product_map`
- `wp_mobo_category_map`
- `wp_mobo_sync_events`
- `wp_mobo_image_queue`
- `wp_mobo_image_refresh_queue`
- `wp_mobo_orphan_image_files`

Additional runtime state is stored in WordPress options and WooCommerce object metadata.

## 17. Security

- Operational endpoints are protected by `X-SEC` or a dedicated Cron Token.
- Secrets are compared with `hash_equals`.
- Outbound requests use the WordPress HTTP API.
- HTTPS certificate verification is enabled by default for HTTPS URLs.
- Unsafe private/local image downloads are disabled by default.
- Admin actions use capabilities, nonces, and sanitization.
- Queues are bounded by locks, retry limits, expiry, and batch sizes.
- Webhook fallback files are protected in uploads.
- Never expose secrets in Git, screenshots, public tickets, or raw logs.

## 18. Troubleshooting

For license errors, verify the base URL, Token, DNS, and HTTP reachability. For webhook 401 responses, verify `X-SEC` and reverse-proxy header forwarding. For stuck sync, inspect `/sync/status` and concurrent workers before resetting. For image failures, complete Repair and inspect queue errors. For order-submission failures, verify the order is `processing`, contains a Mobo item, and has valid login, address, and shipping mappings.

## 19. Production checklist

- [ ] Complete backup exists.
- [ ] Token and Security Code are verified.
- [ ] WordPress REST routes are externally reachable.
- [ ] Real cron runs every minute.
- [ ] Full sync or Repair has completed successfully.
- [ ] Representative categories and variations were checked.
- [ ] Webhook backlog is healthy.
- [ ] Address and shipping mappings were tested.
- [ ] Automatic order submission was tested with a controlled order.
- [ ] Health reporting was verified if enabled.
- [ ] Logs and backups do not expose raw secrets.

## 20. Translation maintenance policy

The Persian and English sections use the same numbering. Any behavior change must update both matching sections in the same commit. The stable `#fa` and `#en` anchors provide direct language switching.


### Simple product behavior in version 10.31.51

A WooCommerce simple product still has one purchasable Mobo Variant. The plugin stores that `portal_variant_id` on the `WC_Product_Simple`, applies price and stock from the purchasable unit, and marks the product out of stock and `mobo_sync_incomplete` when the Variant is missing or ambiguous. When automatic order submission is enabled, checkout must successfully add every item to the authenticated Mobo cart and refresh the authoritative snapshot with `update=true`.

## 21. Links and support

- [`README.MD`](README.MD#en)
- [`FUNCTIONS.MD`](FUNCTIONS.MD#en)
- [`DIAGRAMS.MD`](DIAGRAMS.MD#en)
- [Repository](https://github.com/PedramDev/mobo-core)
- [MoboCore service](http://mobo.codeya.ir/)
- Sales: `+989124508218`
- Technical support: `+989367362228`

---

<div align="center">

[Persian](#fa) · [English](#en) · [Full documentation](README_FULL.MD) · [Function reference](FUNCTIONS.MD) · [Diagrams](DIAGRAMS.MD)

</div>
