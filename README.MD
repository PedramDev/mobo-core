# Mobo Core v2

افزونه **Mobo Core** برای همگام‌سازی مرحله‌ای محصولات، تنوع‌ها، دسته‌بندی‌ها، تصاویر و وب‌هوک‌ها با WooCommerce ساخته شده است.

این نسخه برای اجرا روی هاست‌های ضعیف طراحی شده و دیگر به WP-Cron هاست مشتری وابسته نیست. اجرای مرحله‌ای sync از سمت سرویس خارجی C# انجام می‌شود.

---

## نیازمندی‌ها

- WordPress 5.8+
- WooCommerce فعال
- PHP 7.4+
- سرویس خارجی C# برای اجرای دوره‌ای endpointها

---

## نصب

1. پوشه افزونه را در مسیر زیر قرار دهید:

   `wp-content/plugins/mobo-core/`

2. افزونه را از پیشخوان وردپرس فعال کنید.

3. وارد صفحه زیر شوید:

   `پیشخوان وردپرس > Mobo Core`

4. موارد زیر را تنظیم کنید:

   - کد امنیتی `X-SEC`
   - توکن API در صورت نیاز
   - قوانین بروزرسانی محصول
   - دسته‌بندی پیشفرض
   - قوانین قیمت‌گذاری
   - اندازه chunkها

---

## رفتار هنگام فعال‌سازی افزونه

هنگام activation/install:

- optionهای پیشفرضی که وجود ندارند ساخته می‌شوند.
- پوشه‌های `webhook-files/` و `webhook-files/failed/` ساخته و محافظت می‌شوند.
- تمام فایل‌های `.json` داخل `webhook-files/` حذف می‌شوند.

این کار برای جلوگیری از پردازش فایل‌های webhook قدیمی با معماری جدید انجام می‌شود.

در migration عادی نسخه، فایل‌های webhook حذف نمی‌شوند.

---

## اجرای Sync

این افزونه دیگر از WP-Cron استفاده نمی‌کند.

اجرای sync فقط از سمت سرویس خارجی C# انجام می‌شود.

### Endpointهای اصلی C#

#### شروع همگام‌سازی محصول

```http
POST /wp-json/mobo-core/v1/sync/start
X-SEC: YOUR_SECURITY_CODE
Content-Type: application/json
```

بدنه اختیاری:

```json
{
  "syncId": "AAAAAAAAAAAAA"
}
```

اگر `syncId` ارسال نشود، وردپرس یک شناسه تولید می‌کند.

---

#### اجرای یک مرحله Sync

```http
POST /wp-json/mobo-core/v1/sync/run
X-SEC: YOUR_SECURITY_CODE
```

سرویس C# باید این endpoint را به صورت دوره‌ای صدا بزند تا sync جلو برود.

این endpoint برای UI کاربر نهایی نیست و در صفحه ادمین نمایش داده نمی‌شود.

---

#### دریافت وضعیت Sync

```http
GET /wp-json/mobo-core/v1/sync/status
X-SEC: YOUR_SECURITY_CODE
```

نمونه پاسخ:

```json
{
  "success": true,
  "status": "ok",
  "data": {
    "syncId": "AAAAAAAAAAAAA",
    "status": "running",
    "isRunning": true,
    "isDone": false,
    "isCancelled": false,
    "categorySynced": true,
    "productTotalCount": 472,
    "processedProducts": 12,
    "remainingProducts": 460,
    "progressPercent": 2.54,
    "currentProductGuid": "21eac8ee-0b55-4389-982c-273300873030",
    "variantPage": 3,
    "lastMessage": "UpdateVariant processed."
  }
}
```

---

#### توقف Sync

```http
POST /wp-json/mobo-core/v1/sync/cancel
X-SEC: YOUR_SECURITY_CODE
```

---

## جریان Manual Product Sync

وقتی Product Sync شروع می‌شود:

1. اگر `global_update_categories = 1` باشد:
   - ابتدا دسته‌بندی‌ها از API گرفته و sync می‌شوند.

2. اگر `global_update_categories = 0` باشد:
   - sync دسته‌بندی انجام نمی‌شود.
   - اگر `mobo_default_category_id` تنظیم شده باشد، محصول به همان دسته‌بندی پیشفرض وصل می‌شود.

3. محصولات به صورت صفحه‌ای دریافت می‌شوند.

4. هر محصول ابتدا به صورت `simple` ساخته/آپدیت می‌شود.

5. سپس variantهای همان محصول از API دریافت می‌شوند.

6. اگر `totalCount` تنوع‌ها بیشتر از صفر باشد:
   - محصول به `variable` تبدیل می‌شود.
   - variationها ساخته یا آپدیت می‌شوند.

7. اگر `totalCount = 0` باشد:
   - محصول ساده باقی می‌ماند.

---

## API محصولات

### درخواست محصولات

```http
GET /get-products?OnlyInStock=true&RemVariants=true&SyncId=AAAAAAAAAAAAA&PageNumber=1&RecordPerPage=2
```

### ساختار پاسخ

```json
{
  "syncId": "AAAAAAAAAAAAA",
  "schemaVersion": "2.0",
  "totalCount": 100,
  "data": [
    {
      "productId": "guid",
      "stock": null,
      "price": 248000,
      "title": "Product title",
      "caption": "Short description",
      "comparePrice": null,
      "url": "/product-url",
      "attributes": [],
      "images": [],
      "productCategories": [
        {
          "categoryId": "category-guid"
        }
      ],
      "variants": null,
      "publishedAt": "2025-10-15T10:39:00Z"
    }
  ],
  "pageNumber": 1,
  "recordPerPage": 2,
  "hasMore": true,
  "isLastPage": false,
  "totalPages": 50,
  "nextPageNumber": 2
}
```

---

## API تنوع‌ها

### درخواست تنوع‌ها

```http
GET /{productId}/get-variants?SyncId=AAAAAAAAAAAAA&PageNumber=1&RecordPerPage=5
```

### ساختار پاسخ

```json
{
  "productId": "product-guid",
  "syncId": "AAAAAAAAAAAAA",
  "schemaVersion": "2.0",
  "totalCount": 105,
  "data": [
    {
      "portalVariantId": 114847973,
      "variantId": "variant-guid",
      "price": 1000,
      "comparePrice": null,
      "stock": 100,
      "productId": "product-guid",
      "title": "Product title - Variant title",
      "attributes": [
        {
          "name": "مدل",
          "option": "A12"
        },
        {
          "name": "رنگ",
          "option": "طوسی"
        }
      ]
    }
  ],
  "pageNumber": 1,
  "recordPerPage": 5,
  "hasMore": true,
  "isLastPage": false,
  "isFirstPage": true,
  "totalPages": 21,
  "nextPageNumber": 2
}
```

`portalVariantId` ذخیره نمی‌شود، چون tracking بر اساس این دو مقدار انجام می‌شود:

```text
productId → product_guid
variantId → variant_guid
```

---

## API دسته‌بندی‌ها

### درخواست دسته‌بندی‌ها

```http
GET /get-categories?SyncId=AAAAAAAAAAAAA
```

### ساختار payload

```json
[
  {
    "id": "609f97c4-2011-4186-8729-e1aa8a798c3a",
    "title": "🏷️تخفیف 🏷️",
    "url": "/takhfif",
    "parentId": null
  },
  {
    "id": "e753567b-e764-4981-bb0f-2df38414854f",
    "title": "محصولات",
    "url": "/products",
    "parentId": null
  },
  {
    "id": "0e6426d4-7039-485e-93c2-12e5812ab662",
    "title": "قاب و کاور گوشی",
    "url": "/products/case",
    "parentId": "e753567b-e764-4981-bb0f-2df38414854f"
  }
]
```

Mapping:

```text
id       → category_guid
title    → نام دسته‌بندی
url      → slug
parentId → parent category_guid
```

دسته‌بندی‌ها باید از سمت C# به ترتیب parent-first ارسال شوند.

---

## قانون موجودی Stock

API سه حالت دارد:

```text
stock = null
→ موجودی بی‌نهایت
→ manage_stock = false
→ stock_status = instock

stock = 0
→ ناموجود
→ manage_stock = true
→ stock_quantity = 0
→ stock_status = outofstock

stock > 0
→ موجودی محدود
→ manage_stock = true
→ stock_quantity = stock
→ stock_status = instock
```

تفاوت مهم:

```text
stock = null از API
→ محصول/تنوع موجود است و موجودی آن مدیریت نمی‌شود.

variant missing در پایان sync
→ تنوع ناموجود می‌شود.
```

---

## منطق قیمت‌گذاری

منطق قیمت مطابق نسخه قدیمی `set_variant_prices()` حفظ شده است.

### Base Logic

```text
price = API price
comparePrice = API comparePrice
```

اگر `comparePrice` وجود داشته باشد و `global_product_auto_compare_price = 1` باشد:

```text
regular_price = comparePrice
sale_price    = price
```

در غیر این صورت:

```text
regular_price = price
sale_price    = empty
```

---

### سود اختصاصی variation

اگر روی همان product/variation مقدار زیر وجود داشته باشد:

```text
mobo_additional_price
```

همان مقدار به regular/sale اضافه می‌شود و تنظیمات عمومی قیمت‌گذاری نادیده گرفته می‌شوند.

این مقدار داخل تنظیمات عمومی افزونه نیست و از صفحه ویرایش variation در WooCommerce تغییر داده می‌شود.

---

### سود ثابت

اگر:

```text
mobo_price_type = static-price
```

فقط این مقدار استفاده می‌شود:

```text
global_additional_price
```

---

### سود درصدی

اگر:

```text
mobo_price_type = static-percentage
```

فقط این مقدار استفاده می‌شود:

```text
global_additional_percentage
```

محاسبه مطابق نسخه قدیمی است:

```php
floatval('1.' . global_additional_percentage)
```

مثلاً مقدار `20` یعنی ضریب `1.20`.

---

### سود داینامیک

اگر:

```text
mobo_price_type = dynamic-price
```

فقط `mobo_dynamic_price` استفاده می‌شود.

در UI، کاربر JSON نمی‌بیند. مدیر سایت بازه‌های قیمت را از فرم فارسی تنظیم می‌کند و افزونه پشت‌صحنه همان JSON قدیمی را در option زیر ذخیره می‌کند:

```text
mobo_dynamic_price
```

اولین شرط فعال که قیمت داخل بازه آن باشد اعمال می‌شود.

---

## وب‌هوک‌ها

### دریافت وب‌هوک

```http
POST /wp-json/mobo-core/v1/webhook
X-SEC: YOUR_SECURITY_CODE
Content-Type: application/json
```

این endpoint:

1. payload را به صورت فایل JSON ذخیره می‌کند.
2. بلافاصله یک تلاش کوچک برای پردازش صف انجام می‌دهد.
3. اگر هاست ضعیف باشد یا پردازش کامل نشود، فایل در صف باقی می‌ماند.

### اجرای صف وب‌هوک توسط C#

```http
POST /wp-json/mobo-core/v1/webhook/run
X-SEC: YOUR_SECURITY_CODE
```

این endpoint در UI نمایش داده نمی‌شود، ولی C# می‌تواند آن را دوره‌ای صدا بزند.

---

## Eventهای پشتیبانی‌شده Webhook

### ProductUpdated

برای ساخت/بروزرسانی parent product استفاده می‌شود.

```json
{
  "event": "ProductUpdated",
  "syncId": "optional",
  "schemaVersion": "2.0",
  "data": [
    {
      "productId": "guid",
      "stock": null,
      "price": 248000,
      "title": "Product title",
      "caption": "Short description",
      "comparePrice": null,
      "url": "/product-url",
      "attributes": [],
      "images": [],
      "productCategories": [
        {
          "categoryId": "category-guid"
        }
      ],
      "publishedAt": "2025-10-15T10:39:00Z"
    }
  ]
}
```

### UpdateVariant

برای ساخت/بروزرسانی chunkهای variation استفاده می‌شود.

```json
{
  "event": "UpdateVariant",
  "syncId": "guid",
  "schemaVersion": "2.0",
  "productId": "product-guid",
  "totalCount": 17,
  "pageNumber": 1,
  "recordPerPage": 5,
  "hasMore": true,
  "isLastPage": false,
  "data": [
    {
      "variantId": "variant-guid",
      "productId": "product-guid",
      "price": 248000,
      "comparePrice": null,
      "stock": 5,
      "title": "Variant title",
      "attributes": [
        {
          "name": "مدل",
          "option": "iPhone 15"
        }
      ]
    }
  ]
}
```

---

## Missing Variants

وقتی آخرین صفحه variationهای یک محصول پردازش شد، variationهایی که در sync جدید دیده نشده‌اند حذف نمی‌شوند.

رفتار پیشفرض:

```text
manage_stock = true
stock_quantity = 0
stock_status = outofstock
```

این رفتار با option زیر کنترل می‌شود:

```text
mobo_core_missing_variants_behavior
```

مقادیر:

```text
outofstock
ignore
```

---

## تصاویر

تصاویر از سمت API chunk نمی‌شوند، ولی سمت وردپرس مرحله‌ای پردازش می‌شوند.

option:

```text
mobo_core_images_per_run
```

رفتار:

```text
0 → تصاویر sync نمی‌شوند
1 → هر بار فقط یک تصویر پردازش می‌شود
```

برای جلوگیری از دانلود تکراری تصویر، این metaها حفظ می‌شوند:

```text
image_guid
img_guid
```

اولین تصویر به عنوان تصویر شاخص محصول ثبت می‌شود و بقیه تصاویر وارد گالری محصول می‌شوند.

---

## optionهای legacy حفظ‌شده

```text
global_product_auto_stock
global_product_auto_price
global_product_auto_title
global_product_auto_caption
global_product_auto_compare_price
global_product_auto_slug
global_update_categories
global_update_images
mobo_default_category_id
mobo_price_type
global_additional_price
global_additional_percentage
mobo_dynamic_price
mobo_core_only_in_stock
```

---

## metaهای legacy حفظ‌شده

```text
product_guid
variant_guid
category_guid
image_guid
img_guid
attribute_guid
mobo_additional_price
```

---

## تنظیمات پیشنهادی برای هاست ضعیف

```text
Products Per Page: 1
Variants Per Page: 1 تا 5
Images Per Run: 0 یا 1
Webhook Files Per Run: 1
Time Budget: 5 تا 8 ثانیه
```

---

## تنظیمات پیشنهادی برای هاست قوی‌تر

```text
Products Per Page: 5 تا 20
Variants Per Page: 20 تا 100
Images Per Run: 2 تا 10
Webhook Files Per Run: 2 تا 10
Time Budget: 10 تا 25 ثانیه
```

---

## امنیت

- تمام فایل‌های PHP با `ABSPATH` محافظت می‌شوند.
- عملیات مدیریتی نیازمند capability مناسب است.
- فرم‌های admin با nonce محافظت می‌شوند.
- REST endpointها با هدر `X-SEC` محافظت می‌شوند.
- ورودی‌ها sanitize می‌شوند.
- خروجی‌ها escape می‌شوند.
- درخواست‌های همزمان با lock کنترل می‌شوند.
- پوشه‌های webhook با `index.php` و `.htaccess` محافظت می‌شوند.
- secretها در خروجی خطا چاپ نمی‌شوند.

---

## Uninstall Behavior

هنگام uninstall فقط داده‌های runtime حذف می‌شوند:

```text
mobo_core_sync_state
mobo_core_db_version
mobo_seen_variants_*
transient lockها
runtime/chunking options
```

موارد زیر حذف نمی‌شوند:

```text
محصولات
تنوع‌ها
دسته‌بندی‌ها
تصاویر
product_guid
variant_guid
category_guid
image_guid
img_guid
attribute_guid
mobo_additional_price
mobo_default_category_id
optionهای legacy
تنظیمات API/security
```

---

## Runner پیشنهادی C#

جریان