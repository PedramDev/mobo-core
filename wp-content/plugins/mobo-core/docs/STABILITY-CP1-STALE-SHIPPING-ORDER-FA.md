# MOBO — گزارش نقطه امن CP1 (Stale / Shipping Money / Order Boundary)

**تاریخ:** 2026-10-14  
**وضعیت:** `STAGED / DO NOT DEPLOY / RELEASE BLOCKED`  
**مبنای سورس:** Plugin 10.34.77-REL37-C75B-P4G؛ Portal-20261011-220624؛ Deep Suite r8.3.181-CORRECTED75.

## قرارداد ممیزی‌شده

- Plugin: `includes/class-mobo-core-checkout-validator.php`، مسیر `build_cart_items_payload` هم‌اکنون `block_incomplete=false` است. این یک شرط لازم برای فروش Stale است؛ **تأیید معتبر Variant+Attributes+Stock+Price نیست**.
- Plugin: `validate_mobo_cart_api` و مقایسه Snapshot سبد، شناسه و مجموع تعداد را می‌سنجند و Remote Cart را زیر Lock بازسازی می‌کنند. در Snapshot موجود مجموعه کامل ویژگی‌های خرید و Quote قیمت با واحد معتبر جهت تطبیق قطعی پوشش داده نشده است. ادعای Endpoint مستقل read-only مجاز نیست.
- Plugin: `class-mobo-core-money-policy.php`، قرارداد Signed Connection شامل `money_unit`, `money_fraction_digits`, `money_price_representation`, `money_contract_version` دارد. بخش قیمت‌گذاری Product/Reprice قبلاً opt-in تبدیل IRT/IRR دارد؛ Shopping/Wallet همچنان Strict است.
- Backend: `Portal.WebApp/Contracts/Shipping/MoboShippingMethodPayload.cs` هزینه/کران‌ها را از Source می‌گیرد و پیش از این واحد مستقل در پاسخ نداشت. `WordPressConnectionProvisioningClient` واحد پول Source را از پیکربندی امضاشده `Conf:Provisioning:MoneyUnit` می‌گیرد.
- Backend: هیچ Variant Quote/Reservation Endpoint تأییدشده‌ای برای کنترل دقیق `connection/product/variant + attributes + quantity + money + freshness` در Source مرورشده اثبات نشد. نام Swagger Modelهای موجود **تغییر نکرده است**.
- Checkout: Order scope تک-Connection و Mixed fail-closed و وضعیت‌های uncertain/Wallet retry حفاظت‌هایی دارند؛ کافی بودن آنها فقط با Full-chain Provider Double روشن می‌شود.

## کار انجام‌شده در CP1

### Backend (تغییر افزایشی، بدون Rename Swagger)

- افزودن `money_unit` و `money_contract_version` به پاسخ `get-mobo-shipping-methods`؛ مقدار از همان پیکربندی Sourceِ Signed Provisioning استخراج می‌شود، نه Store Currency.
- افزودن همان metadata به رویداد `ShippingMethodsChanged`؛ برای Payload قدیمی هیچ فیلدی حذف نشده است.
- کامپایل .NET در این محیط انجام نشده است؛ دریافت سورس/اجرای ASP.NET واقعی همچنان OPEN.

### Plugin

- مسیر مستقل تبدیل **Shipping Source→Woo و Woo→Source** با `Money_Converter::convert_exact`، بدون نرخ بازار، fallback پنهان و rounding خودکار اضافه شد. فقط Connection فعال و دارای قرارداد امضاشده و ترجیح صریح مقصد مجاز است. این Bridge اجازه خرید/Wallet بین دو ارز را نمی‌دهد.
- Native shipping rate و shipping-only package به Bridge متصل شدند. مسیر همان‌ارز نیز از قرارداد Connection صریح استفاده می‌کند و هیچ fallback به Connection پیش‌فرض برای تبدیل مبلغ ندارد. هزینه‌های Source و کران‌های Source در همان Source Money Unit سنجیده می‌شوند؛ ریال/تومان قبل از نمایش Woo یک‌بار تبدیل می‌شود. در ورودی مبهم خروجی null/fail-closed است.
- مصرف‌کننده Snapshot حمل: اگر `money_unit` یا `money_contract_version` در payload وجود داشته باشد، قبل از ذخیره، با قرارداد Signed همان Connection مقایسه می‌شود؛ قراردادهای قدیمی بدون فیلد همچنان قابل‌مصرف‌اند. این سازگاریِ Legacy باید در Full-chain بررسی شود.
- مرز Order: تطبیق ارز Frozen Woo Order با ارز فعلی Woo هنگام شروع Submit اضافه شد. در آخرین مرز قبل Wallet نیز قرارداد Fresh Source و ارز سفارش دوباره بررسی می‌شود؛ در صورت تغییر، Wallet انجام نمی‌شود.
- `Mobo_Core_Purchase_Eligibility` به‌عنوان **Predicate آماده برای اتصال بعدی** اضافه شده: `STALE + ELIGIBLE_VERIFIED` را مجاز می‌داند؛ هویت Connection/Product/Variant، Attributeهای خرید، Quantity، Money، TTL و Provenance را چک می‌کند. Normalization فارسی را برای ویژگی‌ها انجام می‌دهد؛ Variant با ID جدید را از روی ظاهر حدس نمی‌زند. فعلاً به Checkout وصل نشده زیرا Backend Evidence معتبر و Endpoint کنترل قطعی هنوز آماده نیست؛ **هیچ فروش تازه‌ای با این Predicate در runtime فعال نشده است**.

### Deep Suite

- فایل ماتریس `E01–E38` در همان Suite اضافه شده؛ هیچ‌کدام را PASS جعلی نکرده‌ایم.
- `stability/contract-smoke.php`: تعداد **25 کنترل Pure Runtime** با ورودی عمدتاً فارسی. بدون Woo، API، PostgreSQL، MySQL، Wallet، اینترنت یا mutation.
- `RUN-MOBO-FINAL-STABILITY-GATE.cmd`: یک Runner واحد برای گزارش تجمیعی CP1، JSON و JUnit و Fingerprint. **اگر حتی یک E2E P0 اجرا نشده باشد با ExitCode=1 / BLOCKED خارج می‌شود.** تاریخچه گیت‌های PASS قبلی را بدون دلیل تکرار نمی‌کند.
- Full-chain E2E واقعی هنوز اجرا نشده و این Runner تا افزودن orchestrator ایزوله دارای 38 BLOCKED است؛ نام Gate دلیل اثبات نیست.
- آزمون‌های تاریخی بسته و Fingerprintهای آنها در محدوده پیشین باقی مانده‌اند؛ نصب Build جدید روی Site نیازمند Baseline fingerprint هماهنگ و اجرای Gateهای تغییرکرده است.

## شواهد انجام‌شده در این محیط

- 25/25 PASS در Pure Runtime PHP.
- `php -l` روی فایل‌های تغییرکرده و فایل جدید PASS.
- سورس Backend کامپایل/Run نشده (`dotnet` در محیط موجود نیست). PowerShell روی ویندوز اجرا نشده (`pwsh` در محیط موجود نیست).
- هیچ سایت وردپرسی (`test1`، `test2` یا سایت Checkout آزمایشی موجود) باز/تغییر داده نشده؛ از این محیط دسترسی به مسیر WAMP آن سایت‌ها یا دیتابیس‌ها نداریم.
- هیچ خرید واقعی یا خرید روی Provider Double صورت نگرفت؛ هیچ HTTP Purchase POST اجرا نشد.

## Gaps / کار ادامه — اولویت انتشار

1. **P0 Backend Variant Evidence:** قرارداد Authenticated Quote/Check با `(Connection, Product, Variant ID, full purchase attributes, requestedQty, valid stock, source unit, source price, revision, checkedAt, validUntil)`؛ شناسایی مسیر Provider-specific و هر Side Effect مربوط به Cart. هیچ قرارداد موجود/آینده را بدون شواهد فرض نکنید.
2. **P0 Plugin Integration:** اتصال Predicate به Classic/Blocks/Auto Submit با Snapshot Frozen، Price Confirmation UX، تجمع تعداد/Uncertain و Fail-Closed برای Missing Evidence. شناسه/Owner را فقط از Custom Storage معتبر بگیرید؛ Source Sync Version به‌تنهایی مانع خرید نیست.
3. **P0 Order Currency Boundary:** تأیید اینکه Portal Checkout/Wallet مبلغ Source را خودش از سبد محاسبه می‌کند یا Body مبلغ دارد؛ تست تطبیق مبلغ مالی Woo vs Provider با Currency معتبر، Shipping/Discount/Tax و عدم تغییر بعد پرداخت. فعلاً Cross-unit purchase بسته می‌ماند.
4. **P0 Shipping E2E:** تست درصد/ثابت/ارسال رایگان، کران هزینه/سبد، چند Connection، تغییربین Sync و Checkout، مدیریت نرخ و رندینگ روی سایت آزمایشی با شبکه Mock.
5. **P0 E2E E01–E38:** اجرای واقعی Source Fixture→Backend .NET/PostgreSQL→Plugin→Woo/Custom Storage→Provider Double؛ بررسی تمام 38 سناریو و end-state، تعداد اثر خرید 0 یا 1، JSON/JUnit و Evidence لازم.
6. **P0 Historical integrity:** پس از تست هماهنگ، Fingerprint دقیق Plugin/Backend و قدیمی‌ترین Gateهای تغییرکرده را به‌روز و در environment ایزوله آزمایش کنید؛ 24–72 ساعت Soak/Chaos/Canary طبق سند Master Plan به‌صورت مجزا.

**سیاست ایمنی:** `test1` و `test2` ممنوع؛ از سایت Checkout آزمایشی موجود فقط تست محدود غیرتخریبی پس از تأیید وضعیت و Backup؛ تست‌های مخرب و خرید ایزوله فقط Disposable با Provider Double. تا PASS کامل، Auto Order واقعی باید خاموش باقی بماند.

## CP2 افزایشی — 2026-10-14 — STAGED / DO NOT DEPLOY

- `Mobo_Core_Purchase_Eligibility::evaluate()` اکنون موجودی صفر تأییدشده را از موجودی نامعلوم تفکیک می‌کند. مقادیر `available` باید Boolean واقعی و `availableQty` عدد صحیح نامنفیِ معتبر باشند؛ `false` تنها با `tombstoneVerified === true` به `INELIGIBLE_VARIANT` تبدیل می‌شود.
- `evaluate_cart_lines()` Predicate صرف و بدون IO است. تعدادهای یکسان در یک Connection/Product/Variant پیش از کنترل Stock جمع می‌شوند. تضاد Attribute، مبلغ یا Quote نسل متفاوت Fail-Closed است؛ حالت `single_connection_required` برای خرید خودکار چند Connection را مسدود می‌کند. **این متد در Runtime Checkout/Order متصل نشده است.**
- Shipping ingestion برای payloadهای دارای Money Metadata وجود هم‌زمان `money_unit` و `money_contract_version` را لازم می‌داند؛ هرگونه Fraction/Representation ارائه‌شده نیز با قرارداد Signed Connection سنجیده می‌شود. Payload کاملاً Legacy هنوز پذیرفته می‌شود؛ downgrade resistance نیازمند پروتکل Version Pinning آتی است.
- Backend در `get-mobo-shipping-methods` با Config ناقص Currency/Precision/Representation خطای 503 می‌دهد و اطلاعات لازم را در جواب موفق منتشر می‌کند. Webhook نیز دو فیلد افزایشی Fraction/Representation دارد.
- کنترل Pure Runtime مستقل فقط قرارداد را تست کرده است؛ هیچ خرید واقعی، Checkout واقعی، Backend .NET یا Full Chain انجام نشده. Mock/Static PASS معادل Full-chain نیست.
- ایمنی: test1/test2 بدون تغییر. Cross-Currency Wallet/Provider Auto Submit همچنان Fail-Closed و Release BLOCKED.
