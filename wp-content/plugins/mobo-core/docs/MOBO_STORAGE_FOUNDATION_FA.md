# Mobo Storage Foundation v1

این Foundation اولین مرحله‌ی جداسازی state عملیاتی Mobo از WordPress EAV است.

## اصل rollout

نسخه‌ی اول هیچ مسیر Sync موجودی را به جدول‌های جدید منتقل نمی‌کند. حالت پیش‌فرض و effective همیشه `legacy` است مگر اینکه هم feature flag عمداً تغییر کند و هم schema جدید کامل و سالم باشد.

حالت‌های داخلی rollout:

- `legacy`
- `dual-write`
- `shadow-read`
- `custom-primary`
- `custom-only`

در v1 فقط Foundation و repositoryهای Product/Variant ساخته شده‌اند. اتصال Runtime به آن‌ها در release جداگانه انجام می‌شود.

## جدول‌های v1

- `{prefix}mobo_products`
- `{prefix}mobo_variants`
- `{prefix}mobo_jobs`
- `{prefix}mobo_media`
- `{prefix}mobo_order_dispatch`
- `{prefix}mobo_runtime`
- `{prefix}mobo_migrations`

`{prefix}` همیشه از `$wpdb->prefix` گرفته می‌شود و نام ثابت `wp_` استفاده نمی‌شود.

## Fail-closed

Schema جدید version مستقل دارد (`mobo_core_storage_schema_version`). خطای ساخت/اعتبارسنجی Foundation، `mobo_core_db_version` فعلی را fail نمی‌کند و سایت در Legacy Mode ادامه می‌دهد. خطای Foundation در گزینه‌های زیر ثبت می‌شود:

- `mobo_core_storage_schema_last_error`
- `mobo_core_storage_schema_last_error_at`

این جداسازی اجازه می‌دهد rollout جدول‌های جدید قبل از Dual Write روی تعداد زیادی سایت واقعی بررسی شود.


## فاز ۲ — نسخه 10.33.69

در این نسخه فقط Product/Variation State به‌صورت legacy-first dual-write آینه می‌شود. مسیر خواندن همچنان Legacy است و خطای write در Custom Storage باعث fail شدن sync موفق Legacy نمی‌شود. حالت‌های بالاتر از `dual-write` تا زمان پیاده‌سازی و تست Read Path به‌صورت runtime به `dual-write` محدود می‌شوند.


## فاز ۳ — نسخه 10.33.70

Backfill مرحله‌ای Product/Variation اضافه شده است. منبع پیمایش `mobo_product_map` است و هیچ scan سراسری روی `wp_postmeta` انجام نمی‌شود. cursor در `mobo_migrations` نگه‌داری می‌شود، هر اجرای runner حداکثر یک batch کوچک را پردازش می‌کند و این stage فقط وقتی `dual-write` مؤثر باشد فعال است.

Backfill پایین‌ترین اولویت runner را دارد: Webhook، Product Sync، Image، Order و صف‌های عملیاتی مقدم هستند. شکست mirror روی یک object زنده cursor را از آن object عبور نمی‌دهد و در اجرای بعد retry می‌شود؛ residueهای حذف‌شده/Trash قابل عبور هستند. Read Path در این فاز همچنان Legacy است.


## فاز ۴ — نسخه 10.33.71

Shadow Read فقط برای سنجش parity فعال شده است. نتیجه‌ی Legacy Product Map/postmeta همچنان نتیجه‌ی authoritative است و هیچ مقدار خوانده‌شده از Custom Storage در تصمیم Runtime دخالت نمی‌کند. مقایسه‌ها در هر request سقف دارند، GUIDهای تکراری دوباره خوانده نمی‌شوند و telemetry به‌صورت تجمیعی یک‌بار در `mobo_runtime` ثبت می‌شود.

مقایسه Product/Variation شامل identity محلی/Portal، revisionهای desired/applied، hashهای desired/applied، parent identity و `sync_status` است. mismatchها فقط diagnostic هستند. حالت‌های `custom-primary` و `custom-only` در این نسخه همچنان به `shadow-read` محدود می‌شوند.


## فاز ۵ — نسخه 10.33.72

Promotion Gate فقط eligibility برای فاز بعد را محاسبه می‌کند و هیچ Read Path یا feature flag را خودکار تغییر نمی‌دهد. شروع confidence window فقط وقتی مجاز است که Backfill با وضعیت دقیق `completed` و بدون gap/error تمام شده باشد و شمارش identityهای فعال Legacy با Custom Storage دقیقاً منطبق باشد؛ row مفقود یا orphan هر دو blocker هستند.

پس از شروع window، حداقل ۵۰۰ Shadow Comparison در یک بازه‌ی حداقل ۶ ساعته لازم است. هر mismatch جدید یا failure جدید Dual Write همان لحظه window را reset می‌کند. Evidence قدیمی قبل از شروع window baseline می‌شود تا یک خطای تاریخی که بعداً تعمیر شده، eligibility را برای همیشه مسدود نکند. parity قدیمی‌تر از ۲۴ ساعت نیز برای promotion معتبر نیست. خروجی Gate در `mobo_runtime` و Site Health ثبت می‌شود و `custom-primary/custom-only` در 10.33.72 همچنان به `shadow-read` محدود هستند.

## فاز ۶ — نسخه 10.33.73

Reconciliation محدود و کم‌اولویت بین Legacy و Custom Storage اضافه شده است. Legacy Product Map/postmeta همچنان authoritative است؛ worker فقط state سفارشی را از Legacy refresh می‌کند، rowهای Custom متصل به Product/Variation حذف‌شده یا Trash را پاک می‌کند و identity conflict فعال را به‌صورت `ambiguous` گزارش می‌دهد. هیچ مسیر repair این فاز Product Map، postmeta یا WooCommerce object را از روی Custom Storage تغییر نمی‌دهد.

Trash/Delete/Untrash lifecycle نیز به این boundary وصل شده است: object بازنشسته row سفارشی فعال نگه نمی‌دارد و در صورت Untrash، فقط اگر Legacy mapping معتبر باقی مانده باشد state سفارشی دوباره mirror می‌شود. Promotion Gate از این نسخه علاوه بر backfill/coverage/parity به reconciliation clean checkpoint نیز نیاز دارد. `custom-primary` و `custom-only` همچنان به `shadow-read` محدود هستند.



## فاز ۷ — نسخه 10.33.74

در این فاز، `custom-primary` فقط پس از Promotion Gate معتبر برای lookup هویت Product/Variation فعال می‌شود. هر Custom miss یا identity drift معتبر به Legacy fallback می‌کند؛ fallback واقعی در همان request مسیر Primary را متوقف می‌کند و lease بعدی را نیز revoke می‌کند. `custom-only` هنوز فعال نیست.

## فاز ۸ — نسخه 10.33.75

قبل از انتقال state read، پوشش write کامل‌تر می‌شود. Metadata Commit Bridge روی تغییرات مستقیم operational postmeta شامل identity، Portal IDs، revision watermarkها، source hash و `mobo_sync_incomplete` کار می‌کند. تغییرات در هر request بر اساس Woo object dedupe و در batch حداکثر ۱۰۰ entity mirror می‌شوند. Legacy همچنان authoritative است و Custom-Primary در این نسخه فقط identity read است؛ بنابراین هیچ state decision هنوز از Custom Storage گرفته نمی‌شود.

## فاز ۹ — نسخه 10.33.76

State Shadow Read برای خواندن‌های مستقیم operational postmeta اضافه شده است. این observer روی revision watermarkها، source hash و `mobo_sync_incomplete` فعال می‌شود، اما مقدار برگشتی `get_post_meta()` را به هیچ شکل تغییر نمی‌دهد. برای هر Product/Variation در هر request فقط یک snapshot کامل مقایسه می‌شود و سقف ۳۲ entity و ۵ sample mismatch دارد.

Promotion Gate به policy version 3 ارتقا پیدا کرده است. علاوه بر ۵۰۰ identity parity comparison، حداقل ۱۰۰ direct-state comparison در همان clean window لازم است. هر state mismatch جدید window را reset می‌کند و leaseهای Gate نسخه قبلی پس از upgrade معتبر نیستند. State Read authoritative همچنان Legacy است؛ این فاز فقط evidence لازم برای انتقال بعدی را جمع می‌کند.


## فاز ۱۰ — نسخه 10.33.77

Schema Fidelity: نگهداری lossless watermarkهای Product/Variation و hash/status دقیق در Custom Storage؛ Legacy همچنان authoritative است.

## فاز ۱۱ — نسخه 10.33.78

Operational State Custom-Primary با Schema v3: state دقیق Product/Variation علاوه بر watermarkهای مستقل، presence واقعی postmeta را نیز نگه می‌دارد. برای `mobo_sync_incomplete` مقدار exact-meta و presence از aggregate sync status جدا هستند؛ در نتیجه نبودن meta با `0` صریح یکی نمی‌شود.

اگر Promotion Gate v5 معتبر باشد، read مستقیم revision/hash/`mobo_sync_incomplete` می‌تواند ابتدا از Custom Storage انجام شود. Retained Woo identity، post type/status، parent identity و exact field presence مستقل verify می‌شوند. Custom miss یا inconsistency واقعی به Legacy fallback می‌کند و primary circuit breaker همان request را می‌بندد.

برای حفظ authoritative Legacy writes، add/update/delete روی metaهای عملیاتی قبل از تصمیم داخلی WordPress همان object را برای باقی request به Legacy pin می‌کند. objectهای pending در Metadata Commit Bridge و canonical snapshotها نیز Custom را bypass می‌کنند. REST operational-meta در این نسخه عمداً Legacy-primary می‌ماند، چون preflight داخلی REST قبل از mutation hook انجام می‌شود.

`custom-only` هنوز مستقل فعال نیست و به Custom-Primary + Legacy fallback محدود می‌ماند.


## فاز ۱۲ — نسخه 10.33.79

Custom-Only Readiness فقط evidence جمع می‌کند و هیچ cutover خودکاری انجام نمی‌دهد. hitهای موفق Custom-Primary برای identity و operational state در هر request de-duplicate و در `mobo_runtime` به‌صورت cumulative ثبت می‌شوند.

Gate مستقل Custom-Only حداقل ۱۰۰۰ identity hit، حداقل ۲۵۰ state hit، ۲۴ ساعت clean window، evidence تازه در شش ساعت اخیر، صفر primary fallback جدید و صفر dual-write failure جدید می‌خواهد. از دست رفتن Promotion Gate یا هر fallback/failure جدید window را reset می‌کند.

در این نسخه `custom-only` همچنان به `custom-primary + Legacy fallback` محدود است؛ `eligible` شدن Gate فقط readiness است و mode را تغییر نمی‌دهد.


## فاز ۱۳ — نسخه 10.33.80

Custom-Only به‌صورت کنترل‌شده فعال می‌شود: فقط requested mode برابر custom-only و lease معتبر Custom-Only Gate v2 می‌تواند effective mode را custom-only کند. در مسیر سالم، identity/state از Custom خوانده می‌شوند و identity postmeta دیگر guard اصلی نیست. هر fallback واقعی یا dual-write failure همان request را fail-safe می‌کند و requestهای بعدی تا ارزیابی مجدد به custom-primary/Legacy برمی‌گردند. REST operational-meta و read-after-write scopeها همچنان به‌صورت صریح Legacy-safe باقی می‌مانند.


## فاز ۱۴ — نسخه 10.33.81

Stale Mirror Idempotence: اگر snapshot قدیمی به‌طور کامل توسط watermarkهای جدیدتر Custom Storage پوشش داده شده باشد، mirror یک no-op موفق محسوب می‌شود و failure telemetry تولید نمی‌کند. Snapshotهای هم‌revision با hash/presence متفاوت و snapshotهای concurrent/incomparable همچنان fail-closed باقی می‌مانند تا drift واقعی پنهان نشود.

## فاز ۱۵ — نسخه 10.33.82

Legacy Retirement Readiness صرفاً evidence جمع می‌کند و هیچ Product Map یا operational postmeta write را خاموش نمی‌کند. در requested `custom-only`، هر direct-state read که هنوز به Legacy نیاز داشته باشد (مثل absent-meta compatibility، REST write-safety یا read-after-write bypass) به‌صورت bounded در `mobo_runtime` ثبت می‌شود. emergency primary fallback و Dual Write failure همچنان از counterهای قبلی خوانده می‌شوند.

Gate مستقل Retirement حداقل ۲۰۰۰ identity hit و ۵۰۰ state hit جدید، ۷۲ ساعت clean window، usage تازه در شش ساعت اخیر، صفر Legacy read dependency جدید، صفر primary fallback جدید و صفر Custom write failure جدید می‌خواهد. writeهای Legacy جداگانه فقط برای sizing/inventory شمرده می‌شوند و blocker نیستند. Eligible شدن Gate هیچ mode یا write path را تغییر نمی‌دهد و فقط مجوز evidence برای فاز کنترل‌شده‌ی بعدی است.

