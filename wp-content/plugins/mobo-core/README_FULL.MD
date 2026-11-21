> **REL32-VARIATION-SINGLETON-DISCOVERY / 10.34.77:** completes release-hygiene singleton media discovery across both WooCommerce products and product variations. Ownership now unions canonical Product State, canonical Variant State, Product Map product/variation compatibility rows, and live legacy Mobo identity rows exactly as the release integrity gate does. Hygiene revision 4 re-arms the same-version pass once.\n\n> **REL31-LEGACY-IDENTITY-THUMBNAIL-DISCOVERY / 10.34.77:** completes the one-time singleton thumbnail repair ownership universe so it matches release-gate discovery: canonical Custom Storage, Product Map compatibility rows, and live products that still carry historical Mobo identity postmeta. Legacy rows are discovery-only and are not restored as runtime identity authority. Hygiene revision 3 re-arms the same-version release pass once.\n\n> **REL30-CANONICAL-RESIDUE-DISCOVERY / 10.34.77:** widens release-hygiene candidate discovery to canonical Custom Storage product ownership and postmeta-only pre-HPOS Revenue Ledger residue. Revenue ownership enrichment updates WooCommerce CRUD authority and the existing legacy ledger shadow without recalculating any financial field; a hygiene revision marker re-arms the corrected pass on same-version replacement.

> **REL29-RELEASE-HYGIENE-DEFERRED-WIRING / 10.34.77:** fixes the one-time 10.34.77 release-hygiene bootstrap so deferred repairs can arm themselves on the first normal request, including same-version candidate replacement. The durable Revenue Ledger ownership pending flag now also keeps the deferred repair hook alive until convergence; completed sites do not keep loading the hook because `mobo_core_103477_release_hygiene_scheduled_at` is persisted.

> **REL28-RELEASE-HYGIENE-LEGACY-OWNERSHIP / 10.34.77:** one-time web-safe release hygiene re-arms proven-safe `_thumbnail_id` singleton dedupe and backfills missing Revenue Ledger Connection ownership only from unambiguous durable evidence (or a sole-Connection historical installation). Financial ledger values are never recalculated; ambiguous history remains fail-closed. Stable tag aligned to 10.34.77.

> **REL27-QUEUE-FAILED-SUPERSESSION-CONSISTENCY / 10.34.77:** Queue Summary and `Retry Failed` now share one ordering-aware failed-event supersession classifier. A newer arrival with a lower version, or a different non-empty incomparable version, remains actionable instead of being hidden from Health/UI metrics; legacy rows without ordering evidence retain arrival-order fallback. Classification DB read errors fail closed instead of producing a false zero actionable-failure count.

> **REL26-FINAL-HARDENING / 10.34.77:** Final pre-publish cumulative candidate. This build includes same-site Self Runner TLS policy, runtime `MOBO_API_BASE_URL` precedence, HTTP-authoritative server-provisioned Backend URLs, endpoint-scoped network circuits, retry/backoff coordination without fake retry consumption, Exact Convergence retry reset after a valid response, bounded rolling targeted-convergence windows with global ownership, and the deferred post-upgrade recovery lifecycle proven through clean completion/no-rebirth. `mobo-core-manifest.json` is regenerated from the packaged bytes for exact-build verification.

> **REL23-HTTP-BACKEND-POLICY / 10.34.77:** `api_base_url` is scheme-authoritative. HTTP endpoints are valid when server-provisioned (including `http://mobo.codeya.ir`); the plugin must not auto-upgrade, reject, or rewrite them to HTTPS. The admin UI now reports HTTP factually without treating HTTPS as a production requirement. Local `MOBO_API_BASE_URL` override precedence and endpoint-scoped network circuits remain unchanged.

> **REL20-NETWORK-BACKOFF-COORDINATION / 10.34.77:** Product Sync now treats durable Portal/MoboCore circuit backoff as a deferral rather than a new transport attempt: retry budget is consumed only by real requests, `waiting_for_portal` honors the network retry boundary, and a backoff resume preserves the count of prior real attempts. Exact targeted convergence recovery also advances with a durable cursor and counts only finalized targets, preventing a temporary Backend outage from repeatedly rescanning the entire frozen target set. TLS verification and external request authentication are unchanged.

> **REL19-SAME-SITE-LOOPBACK-TLS / 10.34.77:** Self Runner wake-ups now follow WordPress core's `https_local_ssl_verify` policy for the same-site `/worker/run` loopback, while `mobo_core_http_sslverify` remains the final override. This prevents private/local certificate chains from stranding a durable post-upgrade convergence owner. External MoboCore, Provider, image, updater, checkout and other outbound HTTPS requests retain their existing verified-TLS defaults. Backend remains unchanged.
> **REL18-DEFERRED-POST-UPGRADE-CONVERGENCE-HOOK / 10.34.77:** The durable `mobo_core_103473_post_upgrade_convergence_pending` intent now participates in the lightweight deferred-repair bootstrap pre-check, so a request after signed provisioning actually registers `Mobo_Core_Migration::run_deferred_repairs()` on `init`. Upgrades crossing 10.34.77 re-arm the intent, preserving the provisioning-boundary guard while ensuring provisioned installations create the targeted convergence owner instead of leaving pending intent dormant. Backend remains unchanged.

> **REL16-CURRENT-BOUNDARY-TERMINAL-OWNERSHIP / 10.34.76:** Operational convergence now accepts terminal upstream ownership only when terminal evidence matches the current product/source ordering boundary. Historical terminal events whose evidence has been superseded are reclassified as actionable convergence gaps so post-upgrade targeted recovery can adopt and heal them instead of leaving stale terminal ownership behind.

> **REL15-DYNAMIC-POST-UPGRADE-CONVERGENCE-RECLASSIFICATION / 10.34.76:** Targeted post-upgrade convergence recovery now reclassifies Operational Health before releasing its owner. Newly-actionable gaps that appear while the frozen generation is running (for example terminal evidence superseded by a newer reconnect/source boundary) are adopted into the same durable generation. Completion is based on fresh post-apply unowned state with the generation owner ignored, so attempted-but-still-broken products are reported as unresolved instead of false-PASS.

> **REL12-CANONICAL-RAW-VARIATION-META-CONVERGENCE / 10.34.71:** Exact Variant storefront convergence now reads durable `attribute_*` rows directly from postmeta, canonicalizes legacy equivalent meta-key spellings, removes duplicate/stale attribute rows, and re-verifies the raw byte values after every successful Variant mutation. Woo object hydration remains useful for storefront objects but is no longer accepted as exact persistence evidence.

> **REL11-RAW-VARIATION-ATTRIBUTE-VALUE-CONVERGENCE / 10.34.70:** Exact storefront convergence now reads and verifies durable WooCommerce `attribute_*` postmeta instead of trusting the hydrated Variation object, which can canonicalize parent option case and hide legacy raw drift. Case-only mismatches are rewritten and verified after the normal Woo save while slug-normalized identity matching remains unchanged.

> **REL03-CUSTOM-STATE-RESIDUE-FINALIZER / 10.34.62:** Release-residue self-heal now proves convergence from Product Map + canonical Custom Storage Product/Variant state; Sync Health is observational output only. Re-arms the bounded finalizer for 10.34.61 upgrades. Backend remains unchanged.

> **HARDEN14-OBSERVABILITY-ADMIN-HEALTH / 10.34.61:** Admin Health now exposes durable monetary compatibility, precise network failure classes, backlog/stalled state and last successful sync without executing probes during rendering. HARDEN03 Backend remains unchanged.

> **HARDEN10-CHECKOUT-SCOPE-PREFLIGHT / 10.34.60:** Automatic Mobo order submission now rejects mixed, multi-Connection and ownership-ambiguous checkouts before payment, while leaving optional remote/cart validation independent. Phases 8/9/11/12/13 retain the existing production mechanisms and are covered by the HARDEN13 Test Suite checkpoint.

> **HARDEN06-REPLAY-OUT-OF-ORDER / 10.34.59:** Version-aware queue supersession and failed-event recovery prevent a late stale delivery from suppressing a failed newer desired state. Different non-empty incomparable watermarks fail closed instead of using arrival order, equal-version completed rows are replay-deduped, and equal-version ProductUpdated payloads with conflicting durable source hashes are blocked.

> **HARDEN04-AMBIGUOUS-PURCHASE-IDEMPOTENCY / 10.34.58:** Provider purchase ambiguity is fail-closed with durable attempt/remote-order checkpoints and positive-only reconciliation. A known remote order can only heal local uncertainty when provider details positively prove the exact expected order and `paid=true`; missing/false/incomplete evidence never authorizes another Checkout/Wallet POST. Reconciliation owns the same per-order and shared Provider-session leases, and stale queue/admin paths cannot replay a known remote purchase or post-success listeners.
> **HARDEN02-MONEY-CONTRACT / 10.34.56:** Connection provisioning now carries explicit Source monetary semantics (`money_unit`, fraction digits, representation and monotonic contract version). MoboMobo is provisioned as IRT/Toman with zero fraction digits. IRT and IRR are distinct; Product Sync, Reprice, Shipping and Order Submission fail closed on mismatch and no implicit x10 conversion remains. Backend reconciliation refreshes existing customers without requiring a license re-save.

> **REL10-EXACT-VARIATION-ATTRIBUTE-VALUE-CONVERGENCE / 10.34.69:** Variation identity continues to use slug-normalized signatures for duplicate-safe matching, while storefront convergence now compares exact sanitized option values. Case-only historical drift such as `Airpods 3` vs `airpods 3` is rewritten in place instead of being incorrectly accepted as a no-op.
> **REL09-ONLYINSTOCK-EXACT-CONVERGENCE-RECOVERY / 10.34.68:** Repair now exact-fetches existing local products that Operational Health classifies as unowned after the catalogue pass. This closes OnlyInStock policy omissions without importing new out-of-stock products: terminal/active-owned rows are excluded, existing Woo identity is required, and the exact snapshot is fed through the normal Parent/Variant lock and webhook-ordering pipeline.

> **REL08-VARIANT-CHECKPOINT-ORDER-RESIDUE-PROOF / 10.34.67:** The historical residue finalizer now removes the last legacy parent-level Variant watermark equality assumption. When the optional parent Variant checkpoint pair exists, both values must be present and preserve monotonic ordering (`seen<=applied`); exact Custom Variant State remains authoritative for child convergence after retirement.
> **REL06-VARIANT-APPLIED-STATE-RESIDUE-PROOF / 10.34.66:** The historical residue finalizer now follows the canonical Variant State repository applied-state contract. A migrated Variant may legitimately have applied revision ahead of seen revision; exact Custom Variant coverage is proven by applied status, no dirty/claim state, desired<=applied, complete child identity coverage, and non-conflicting hashes. Hashed child-map fallback is retained only when no Custom Variant state exists.

> **REL02-DURABLE-RESIDUE-FINALIZER / 10.34.55:** Pre-release Plugin Gate proved that 10.34.54's bounded residue scan still depended on retained historical done-event rows. 10.34.55 re-arms the scan and promotes current durable state to the proof boundary: exact applied Product/Variant revision+version, matching source hash, completed authoritative desired-state, complete child mappings, sane local topology, and no active event owner. Historical event rows are no longer required. `_thumbnail_id` duplicate normalization now preserves the oldest row only when WordPress' public singleton read resolves to that exact value, allowing conflicting later rows to be removed without changing the effective thumbnail. Portal/.NET is unchanged.

> **REL01-CONVERGENCE-RESIDUE / 10.34.54:** Pre-release Plugin Gate exposed historical incomplete-marker residue that the original one-page 10.33.53 self-heal could not exhaust. The proof itself remains strict; only scan ownership/lifetime is corrected. The repair now resumes by Product Map id, includes synced/behind candidates, remains pending until the bounded scan is exhausted, and is advanced by real Cron. A second bounded repair normalizes duplicate `_thumbnail_id` rows on current Mobo products without changing the effective thumbnail value.

> **MIGOPT09-BACKFILL-CACHE / 10.34.53:** The clean 10.34.52 full benchmark reduced DB queries to 959,936 and CPU to ~82.5s, but accelerated Backfill retained WordPress postmeta caches from multiple completed 100-row units until request shutdown, peaking near 150 MiB. 10.34.53 bulk-primes only the current bounded Backfill unit and releases its postmeta cache in `finally`; no batch/grace/cursor/fence safety is weakened.
> **MIGOPT08-PREFENCE-READS / 10.34.52:** The clean 10.34.51 full benchmark completed with zero residue/parity and sharply lower request/memory pressure, but Backfill and Operational proof still paid repeated pre-fence state checks inside each Legacy snapshot. 10.34.52 introduces a trusted migration-only pre-fence Legacy-read scope for those two workers; it is exception/return-safe via `finally` and never changes normal runtime/write-path fence behavior. No batch or grace enlargement.
> **MIGOPT07-OPBACKFILL / 10.34.51:** A clean 10.34.50 `test1` benchmark completed with exact zero residue/parity, but 132 backfill requests plus Operational proof dominated runtime/query pressure and peak PHP memory reached ~272 MiB. 10.34.51 lets a genuinely running backfill consume multiple small durable units in the existing bounded Cron slice, makes Operational proof bulk-prefetched verify-first/no-op, and bounds proof cache retention without increasing destructive unit size.
# Mobo Core — Full Documentation / مستندات کامل
> **MIGOPT06-SLICELOOP / 10.34.50:** `test1` fixture telemetry showed that 10.34.49 reduced the Identity hot path dramatically, but every real-cron request still reported one migration iteration. The accelerated waiting detector was matching the JSON field name `lastError` even when empty. 10.34.50 inspects only actual status/error values, allowing multiple durable worker batches per bounded Cron slice while preserving immediate stop on grace/waiting/error states.
> **MIGOPT05-IDENTITY-NOOP / 10.34.49:** Fixture telemetry on test1 showed ~62.5k DB queries per Identity proof slice despite set-based prefetch. The cause was unconditional replay of the heavyweight Product/Variation Legacy bridge for every proof object and Variation parent. 10.34.49 verifies the prefetched Custom snapshot first and executes the original mirror + fresh verification only on real mismatch/missing state; crash/replay and terminal proof semantics are unchanged.


<div align="center">

**Version `10.33.16.18` · Compatible with Portal `v25 / .NET 10`**

[فارسی](#fa) · [English](#en) · [راهنمای کامل](README_FULL.MD) · [مرجع توابع](FUNCTIONS.MD) · [نمودارها](DIAGRAMS.MD)

</div>

---

> **MIGOPT05-IDENTITY-NOOP / 10.34.49:** Retains the 10.34.47 crash/replay contract and optimizes remaining migration query paths: set-based Identity proof and Operational reconcile reads, durable cleanup/reconcile cursors, existence-first boolean blockers, and byte-bounded Entity bulk statements. Sensitive ownership creation revalidates fresh identity under lock, and exact terminal proof remains authoritative. Adaptive row-batch growth remains disabled pending fixture telemetry. See `docs/releases/RELEASE_10.34.49.md` and the customer-host chaos contract.

> **MIGOPT03-SAFE / 10.34.47:** Customer-host migration is hardened for arbitrary PHP/host termination, transient DB failure/reconnect, overlapping Cron/web requests, stale/lost locks, partial batches, and late Legacy writes. The bounded Cron slice is retained; checkpoints publish through exact-state CAS, Entity cleanup is durably journaled and lineage-safe with `legacy_origin_meta_id`, and terminal migrations are physically re-probed so residue can re-enter recovery after the DB version is already current. No WP-CLI dependency is introduced. Portal/.NET is unchanged. See `docs/releases/RELEASE_10.34.47.md` and `docs/internal/migration-customer-hosting-chaos-contract.md`.

> **Current cumulative candidate — 10.34.53 / PERF01–PERF12 + REL01 + MIGOPT09-BACKFILL-CACHE:** Custom Storage schema v14 retains immutable Entity lineage and crash-safe cleanup journaling; migration hot paths use bounded set-based reads and the real Cron accelerator can now execute multiple durable batches within its existing 12-second budget. This remains a WordPress-only benchmark/chaos candidate; Portal/.NET is unchanged. See `docs/releases/RELEASE_10.34.53.md`.

> **REL01 / 10.34.44:** failed sync-event retention no longer destroys the only evidence that an incomplete product is blocked by a terminal upstream-invalid `UpdateVariant`. Before a qualifying event row is deleted, its current ordering/error evidence is durably mirrored into Sync Health; operational classification consumes that durable evidence after retention. Portal/.NET is unchanged.


<a id="fa"></a>

# مستندات کامل فارسی

## 1. هدف و معماری محصول

Mobo Core لایه اتصال بین فروشگاه WooCommerce و سرویس MoboCore است. افزونه اطلاعات کاتالوگ را از API دریافت می کند، شناسه های راه دور را به آبجکت های محلی نگاشت می کند، تغییرات را به صورت idempotent اعمال می کند و عملیات سنگین را در صف های محدود و قابل ادامه اجرا می کند.

اجزای اصلی:

1. **MoboCore Portal v25**: API، لایسنس، داده محصول، payload رویدادها، Health و نگاشت های مرکزی.
2. **Mobo Core 10.33.16.18**: افزونه وردپرس، صف محلی، Sync، نگاشت، تصاویر، Checkout و عملیات مشتری.
3. **WooCommerce**: منبع نهایی محصول، تنوع، دسته بندی، رسانه، سبد و سفارش در سایت مشتری.
4. **the provisioned Source**: جریان اختصاصی بررسی سبد و ثبت سفارش که فقط در صورت فعال سازی تنظیمات Checkout استفاده می شود.

نمودارهای کامل در [`DIAGRAMS.MD`](DIAGRAMS.MD#fa) قرار دارند.


### Adaptive Self-Tuning (10.33.16.18)

Runner از آمار سبک ۲۴ساعته `runtimeDiagnostics` برای محاسبه ظرفیت موقت همان اجرای Cron استفاده می‌کند. تنظیمات ذخیره‌شده baseline باقی می‌مانند و افزونه هیچ batch sizeای را خودکار در دیتابیس تغییر نمی‌دهد. Parent Finalize، Image Queue، Reprice، Recategorize، Cache Warmup و از 10.33.16.18 خود Product Sync می‌توانند بر اساس `recentMsPerItem` در محدوده‌های امن حرکت کنند. Product Sync زمان هر step را فقط در buffer همان request ثبت می‌کند و پس از حداقل چهار نمونه می‌تواند `productStepsPerRound` را بین 1 تا حداکثر 2 برابر baseline (با سقف 20) تنظیم کند؛ Memory Pressure، Time Budget Exhaustion و failureها همچنان باعث downshift/cap محافظه‌کارانه می‌شوند. این قابلیت از تب Real Cron قابل غیرفعال‌سازی است.

## 2. سازگاری نسخه

| جزء | نسخه / وضعیت |
|---|---|
| Mobo Core | `10.33.16.18` |
| Portal | `v25` |
| Target Framework پرتال | `net10.0` |
| WordPress | `5.8+` |
| PHP | `7.4+` |
| WooCommerce | `8.2+`، تست شده تا `10.9` |
| ووکامرس فارسی | اختیاری؛ برای MoboCore الزامی نیست |
| HPOS | اعلام سازگاری شده است |
| Cursor sync | محصول و تنوع پشتیبانی می شوند |
| Lightweight webhook pull | پشتیبانی می شود |

افزونه همچنان از درخواست های Page Number قدیمی پشتیبانی می کند و در نسخه جدید، Cursor را برای پایداری بیشتر Sync اولیه ترجیح می دهد.

## 3. نصب و ارتقا

### نصب جدید

1. از دیتابیس و فایل های سایت backup بگیرید.
2. WooCommerce را نصب و فعال کنید؛ ووکامرس فارسی اختیاری است.
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

`Webhook Security Code` یک secret متنی عمومی نیست؛ مقدار آن داخل Header ارسال می‌شود و باید فقط شامل ASCII قابل‌چاپ بازه `0x21` تا `0x7E` باشد. حروف انگلیسی، عدد و نمادهایی مانند `@ # $ % & * _ [ ] -` مجاز هستند. فاصله، Tab، Enter، حروف فارسی، ایموجی و سایر Unicodeها مجاز نیستند. حداکثر طول قابل قبول ۲۵۵ کاراکتر است.

اولویت تعیین API Base URL:

1. فیلتر `mobo_core_api_base_url`
2. ثابت `MOBO_API_BASE_URL`
3. option با نام `mobo_core_api_base_url`

نمونه در `wp-config.php`:

```php
define( 'MOBO_API_BASE_URL', 'the provisioned Backend' );
```

تست پایه:

```bash
curl -H 'Token: YOUR_TOKEN'   'the provisioned BackendLicenseInfo'
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

Mobo Core برای عملیات حیاتی به WP-Cron وابسته نیست. روش اصلی پیشنهادی برای Cron هاست، اجرای مستقیم فایل `mobo-cron.php` با PHP CLI است. Endpoint HTTP فقط fallback است:

```text
/wp-json/mobo-core/v1/cron/run
```

نمونه HTTP fallback با Header امن‌تر:

```cron
* * * * * curl -fsS --max-time 55 -H 'X-SEC: REPLACE_WITH_CRON_TOKEN' 'https://example.com/wp-json/mobo-core/v1/cron/run' >/dev/null 2>&1
```

احراز هویت قدیمی `?token=` برای سازگاری حفظ شده، اما برای تنظیم جدید توصیه نمی‌شود چون Secret می‌تواند وارد history یا access log شود.

از نسخه `10.31.75` هر slice در چند دور منصفانه اجرا می شود. در هر دور، صف های Webhook، تصویر معمولی، نوسازی تصاویر، Sync محصول، Reprice، Recategorize و سفارش queued سهم محدود خود را می گیرند. تا وقتی زمان امن باقی مانده، کار قابل اجرای فوری وجود دارد و پیشرفت واقعی اتفاق می افتد، دور بعدی در همان Process شروع می شود. توقف فقط در پایان بودجه امن، خالی شدن صف قابل اجرا، نبود پیشرفت، رسیدن به سقف دورها، خطای غیرقابل ادامه یا از دست رفتن lease انجام می شود. Health و maintenance نیز در همان slice به شکل کنترل شده اجرا می شوند.

Runner از lease اتمیک token-based استفاده می کند. پیش از هر stage اصلی، مالک heartbeat می زند و انقضای lease را تمدید می کند. اجرای همزمان دوم lock را می بیند و بدون ورود به صف ها خارج می شود. اگر Process crash کند، توسط هاست kill شود یا قبل از `finally` پایان یابد، heartbeat متوقف می شود و lock پس از TTL محدود خودکار منقضی می شود. TTL موثر حداقل تنظیم مدیر است، اما برای پوشش بودجه اجرا و طولانی ترین timeout شبکه به طور محدود افزایش پیدا می کند. اگر CAS تمدید شکست بخورد یا token دیگر مالک نباشد، Worker فعلی فوراً از ادامه عملیات محافظت شده دست می کشد.

Self Runner با loopback request غیرمسدودکننده، بعد از دریافت وب هوک یا شروع Sync یک اجرای محلی را kick می کند. از `10.31.75` اگر یک Cron مستقیم cPanel نیز با وجود پیشرفت به انتهای slice برسد و هنوز کار فوری باقی باشد، همان Runner یک continuation غیرمسدودکننده dispatch می کند؛ در صورت مسدود بودن loopback، Cron بعدی سرور fallback باقی می ماند. خطای یک queue stage مهار می شود، همان stage برای ادامه slice غیرفعال می شود و صف های مستقل بعدی ادامه پیدا می کنند.

گزارش سلامت فیلد `cronRunner` را نیز ارسال می کند: نسخه Mobo، نتیجه آخرین اجرا، source، بودجه تنظیم شده و موثر، مدت، تعداد دورها، علت توقف، میزان پیشرفت، نیاز به continuation، تعداد تمدید lock، از دست رفتن lease، stageهای خطادار، آمار passهای صف و وضعیت فعلی lease شامل heartbeat، زمان انقضا و ثانیه باقی مانده. token مالک در گزارش قرار نمی گیرد.

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
- فیلد اختصاصی `mobo_additional_price` فقط روی variation موبویی با مالکیت معتبر؛ مقدار مثبت آن بالاترین اولویت قیمت‌گذاری است، خالی/۰ یعنی fallback به override محصول یا policy عمومی، و ذخیره فیلد به‌تنهایی قیمت فعلی را فوراً بازمحاسبه نمی‌کند.
- filter توسعه دهنده `mobo_core_calculated_price_pair`

Reprice به صورت cursor/batch اجرا می شود تا نیاز به Sync کامل یا درخواست مجدد تمام payloadها نباشد.

## 11. تصاویر

### صف همگام سازی معمولی

جدول `wp_mobo_image_queue` وضعیت دریافت تصاویر هر محصول را نگه می دارد. تعداد پردازش هر اجرا محدود است و تلاش دوباره با فاصله افزایشی انجام می شود. اگر یک تصویر WebP قبلا با همان `image_guid` یا نشانی منبع ثبت شده باشد، همان پیوست معتبر دوباره استفاده می شود و پیوست تکراری ساخته نمی شود.

### روند امن نوسازی WebP

بخش «نوسازی تصاویر» یک Workflow یک‌کلیکی و خودکار است، اما **Product Repair را خودکار شروع یا Retry نمی‌کند**. قبل از شروع چرخه باید یک Repair کامل و موفق محصولات ثبت شده باشد. Repair تصمیم مستقلی در «نمای کلی» است؛ پس از تکمیل آن، Image Refresh فقط مراحل مربوط به رسانه را اجرا می‌کند.

قبل از شروع از دیتابیس و `wp-content/uploads` پشتیبان قابل بازیابی بگیرید. با شروع چرخه، MoboCore به‌صورت bounded و از طریق Cron/Self Runner این مراحل را ادامه می‌دهد:

1. پاک‌سازی اولیه collision/orphanهای اثبات‌شده و بررسی پیش‌نیازها؛
2. اسکن تصاویر Mobo و ساخت صف نوسازی؛
3. دریافت دوباره منبع و جایگزینی WebP، حتی وقتی URL منبع نسبت به چرخه قبل تغییر نکرده است؛
4. کنترل و در صورت امکان بازسازی برش‌های WebP؛
5. Safety Audit پیوست‌های قدیمی، انتقال مراجع و حذف فقط مواردی که بی‌خطر بودن حذف آن‌ها اثبات شده است؛
6. اسکن و پاک‌سازی کنترل‌شده خانواده‌های orphan که بلافاصله قبل از حذف دوباره اعتبارسنجی می‌شوند.

Retry و backoff خودکار است. خطای یک تصویر مانع ادامه تصاویر مستقل نمی‌شود؛ مواردی که پس از بودجه Retry قابل اصلاح نیستند برای همان چرخه قرنطینه و بدون حذف ناامن حفظ می‌شوند. رسیدن Workflow به `completed` می‌تواند به معنی «نقطه پایدار با موارد حفظ‌شده» باشد؛ UI فعلی بین تکمیل کاملاً سالم و تکمیل با quarantine/Safety-block/error تفاوت می‌گذارد.

پاک‌سازی destructive تصمیم دستی مرحله‌ای ندارد، اما Safety Audit حذف نشده است: فعال بودن Workflow به‌تنهایی مجوز حذف هیچ Attachment یا خانواده فایل نیست. Shared Media نیز مالکیت و fallback policy خودش را حفظ می‌کند و Image Refresh در زمان اختلال mount، ownership را با fallback خصوصی ناامن دور نمی‌زند.

### کنترل و بازسازی برش ها

برای هر پیوست WebP، ابزار داخلی موارد زیر را بررسی می کند:

- وجود و غیرخالی بودن فایل اصلی؛
- کامل بودن متادیتای تصویر؛
- سایزهای لازم بر اساس اندازه های ثبت شده فعلی وردپرس و قالب؛
- وجود فیزیکی تمام فایل های ثبت شده در `uploads`؛
- WebP بودن فرمت برش های ثبت شده؛
- توانایی GD یا Imagick در باز کردن و ساخت WebP.

بازسازی با توابع استاندارد رسانه وردپرس انجام می شود. پس از بازسازی، همان کنترل ها دوباره اجرا می شوند. اگر حتی یک سایز، فایل یا فرمت همچنان ناقص باشد، عملیات ناموفق ثبت می شود، دلیل در جدول فارسی نمایش داده می شود، محصول به تصویر ناقص منتقل نمی شود و تصویر قدیمی حذف نمی شود. گزارش های اسکن و بازسازی در طول هر دوره تجمعی هستند و با cursor کل کتابخانه رسانه را پوشش می دهند.

اگر بازسازی داخلی ناموفق بود، ابتدا فضای دیسک، مجوز نوشتن `uploads`، حافظه PHP و پشتیبانی WebP در GD یا Imagick را اصلاح کنید. سپس بازسازی داخلی و اسکن نهایی را دوباره اجرا کنید. برای بازسازی عمومی کتابخانه رسانه می توان از افزونه `Regenerate Thumbnails` استفاده کرد. `Force Regenerate Thumbnails` تهاجمی تر است و فقط پس از پشتیبان کامل مناسب است. این افزونه ها مشکل نبود پشتیبانی WebP در سرور را برطرف نمی کنند.

### پاک سازی خانواده های قدیمی

فایل اصلی و مشتقات `150x150`، `768x1024`، `scaled`، `rotated` و نسخه های ویرایش شده یک خانواده محسوب می شوند. خانواده های ثبت شده در کتابخانه رسانه اصلا داخل جدول حذف ذخیره نمی شوند. هر خانواده آماده حذف پیش از پاک شدن دوباره از نظر attachment، محصول، محتوای نوشته، post meta، term meta، user meta، options و فایل فیزیکی کنترل می شود. پاک سازی فقط پس از یک ترمیم موفق باز می شود و حذف به صورت محدود و مرحله ای انجام می شود.

## 12. Checkout و ثبت سفارش

تمام قابلیت های حساس Checkout در نصب جدید خاموش هستند. وقتی validation و order submission خاموش باشند، افزونه نباید در runtime مشتری دخالت کند و WooCommerce/افزونه های حمل ونقل مالک جریان Checkout می مانند.

قابلیت های اختیاری:

- بررسی موجودی محلی
- بررسی کامل بودن GUID و Sync
- اعتبارسنجی سبد در the provisioned Source
- اعتبارسنجی خارجی سفارشی
- نگاشت کشور، استان و شهر
- انتخاب روش ارسال موبو بر اساس rate ووکامرس، استان و بازه زمانی
- ثبت خودکار سفارش موبو

ثبت سفارش خودکار فقط زمانی queue می‌شود که سفارش `processing`، تماماً موبو باشد و مالکیت تمام اقلام آن به یک Connection مشخص برسد. سفارش غیرموبو، ترکیبی یا Multi-Connection به‌صورت Fail-Closed از این جریان خارج می‌شود و هیچ بخش جزئی از آن به موبو ارسال نمی‌شود. گزینه تکمیل خودکار فقط پس از ثبت موفق همین سفارش‌های واجد شرایط اعمال می‌شود.


### قفل تنظیمات صف هنگام Sync و Repair

در زمان اجرای Sync کامل یا Repair، تنظیمات تب «صف و پردازش» قفل می شوند. تغییر `products_per_page` یا `variants_per_page` در اجرای page-based می تواند مرز صفحه را جابه جا کند و باعث رد شدن یا پردازش دوباره رکوردها شود؛ تغییر cursor mode نیز strategy ادامه را عوض می کند. قفل هم در UI و هم در مسیر ذخیره سمت سرور اعمال می شود و direct `update_option()` نیز تا پایان یا لغو عملیات مقدار قبلی را حفظ می کند. وضعیت `waiting_for_portal` هم بخشی از اجرای فعال محسوب می شود.

### آدرس Checkout و Location Resolver

Mobo Core همچنان وابستگی عمومی پروژه به ووکامرس فارسی را حفظ می‌کند، اما ثبت سفارش خودکار به گزینه «فعالسازی شهرهای ایران» یا مالکیت فیلدهای آدرس توسط آن افزونه وابسته نیست. فیلدهای country/state/city در اختیار WooCommerce و افزونه‌های آدرس فروشگاه باقی می‌مانند. هنگام Submit Order، Mobo Core مقادیر native ذخیره‌شده روی سفارش را به Location Resolver مرکزی MoboCore می‌فرستد و Provider در Backend شناسه‌های مقصد را resolve می‌کند.

در Build آزمایشی C75B، تولید فایل‌های `iran_cities.js` و جایگزینی انتخابگر شهر غیرفعال و پیاده‌سازی آن حذف شده است. فیلدهای آدرس متعلق به WooCommerce هستند و شناسه‌های Provider فقط هنگام ثبت خودکار سفارش از Resolver مرکزی Backend دریافت می‌شوند. داده‌های Cache قدیمی در دیتابیس عمداً پاک نمی‌شوند.

قبل از فعال سازی production:

1. ورود حساب موبو را تست کنید.
2. country/state/city واقعی یک سفارش را با Resolver مرکزی بررسی کنید.
3. تمام روش‌های ارسال فعال WooCommerce را به shipping_id فعلی موبو نگاشت کنید.
4. یک سفارش آزمایشی با یک variation واقعی ثبت کنید.
5. log و order meta box موبو را بررسی کنید.
6. رفتار retry و duplicate submission را آزمایش کنید.

## 13. پیامک سفارش و هشدار کیف پول

Mobo Core درگاه پیامک مستقل پیاده‌سازی نمی‌کند و ارسال را به `PWSMS()->send_sms()` متعلق به «پیامک حرفه‌ای ووکامرس» واگذار می‌کند. در دسترس بودن API محلی یا پذیرفته‌شدن درخواست توسط درگاه، به‌تنهایی تحویل نهایی SMS به گوشی را اثبات نمی‌کند.

تنظیمات پیامک سفارش **سراسری فروشگاه** هستند و با تغییر Connection عوض نمی‌شوند. سناریوها:

- `non_mobo`: سفارش بدون محصول موبو
- `mobo_only`: فقط محصولات موبو
- `mixed`: ترکیب محصول موبو و عادی

این پیامک‌ها در hook ساخته‌شدن سفارش Checkout ارسال می‌شوند و منتظر پرداخت موفق نمی‌مانند. هر سفارش/سناریو دارای lock و replay fence پایدار است؛ قبل از تماس با درگاه fence ذخیره می‌شود و اگر نتیجه remote یا commit نهایی مبهم باشد، Mobo Core ارسال را خودکار replay نمی‌کند. خطای ارسال در Order Note ثبت می‌شود و صف Retry مستقل برای SMS سفارش وجود ندارد.

Placeholderهای اختصاصی:

```text
{mobo_order_type}
{mobo_order_type_label}
{mobo_items_count}
{non_mobo_items_count}
{customer_mobile}
```

هشدار کمبود موجودی کیف پول **Connection-scoped** است و مستقل از master پیامک سفارش عمل می‌کند. فقط Providerی که endpoint موجودی دارد می‌تواند آن را اجرا کند و بررسی موجودی پس از موفقیت durable ثبت خودکار سفارش موبو انجام می‌شود. مقدار threshold برابر `0` معتبر است. هشدار one-shot است؛ افزایش موجودی یا تغییر threshold آن را خودکار re-arm نمی‌کند و مدیر باید صریحاً دوباره فعالش کند. پیش از remote send یک replay fence پایدار ثبت می‌شود؛ نتیجه نامطمئن یا شکست commit، ارسال بعدی را تا re-arm صریح مسدود می‌کند.

SMS آزمایشی کیف پول state جدا از هشدار زنده دارد، موجودی Provider را fetch نمی‌کند و one-shot واقعی را مصرف/پاک نمی‌کند.

## 14. Health Report

Endpoint محلی برای Probe پرتال:

```http
GET /wp-json/mobo-core/v1/health
X-SEC: <default-connection-security-code>
```

این endpoint سطح نصب است و فقط Connection پیش‌فرضِ فعال (Installation Controller) مجاز به Pull آن است. Portal گزارش را مستقیماً از endpoint بالا Pull می‌کند. افزونه گزارش دوره‌ای به Portal Push نمی‌کند. در صورت قدیمی بودن Cron، Portal می‌تواند endpoint جداگانه `/heartbeat` را برای Recovery فراخوانی کند؛ نتیجه Heartbeat مستقل از دسترسی Health ثبت می‌شود.

گزارش می‌تواند نسخه WordPress/PHP/WooCommerce، وضعیت Debug، memory، disk، cron، Sync، صف وب‌هوک و Action Scheduler را شامل شود.

## 15. REST API افزونه

تمام routeها زیر namespace زیر هستند:

```text
/wp-json/mobo-core/v1
```

| Method | Route | امنیت | کاربرد |
|---|---|---|---|
| POST | `/categories/ensure-sync` | `X-SEC` | Sync دسته ها در صورت نیاز |
| GET/POST | `/cron/run` | Cron Token یا `X-SEC` | اجرای یک slice |
| GET | `/cron/status` | Installation Controller `X-SEC` | وضعیت Cron |
| GET/POST | `/worker/run` | Cron Token یا `X-SEC` | اجرای Self Worker |
| GET | `/worker/status` | Installation Controller `X-SEC` | وضعیت Self Runner |
| GET | `/health` | Installation Controller `X-SEC` | سلامت محلی |
| GET/POST | `/health/report-now` | Installation Controller `X-SEC` | snapshot سازگاری Health |
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
- مقدار `mobo_core_security_code` قبل از ذخیره و قبل از استفاده در Header به صورت visible ASCII اعتبارسنجی می‌شود و مقدار نامعتبر جایگزین مقدار قبلی نمی‌شود.
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

- سفارش باید `processing`، تماماً موبو و متعلق به دقیقاً یک Connection باشد؛ Mixed/Multi-Connection عمداً ارسال نمی‌شود.
- Connection، login test، sender fields، Location Resolver و shipping mapping را بررسی کنید.
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
- [ ] Location Resolver و نگاشت تمام روش‌های ارسال فعال WooCommerce تست شده است.
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
- [MoboCore service](the provisioned Backend)
- فروش: `+989124508218`
- فنی: `+989367362228`

---


## پاک‌سازی هدفمند Cache پس از Sync

Mobo Core `10.31.73` ذخیره‌های تکراری یک محصول/Variation را در طول request جمع می‌کند و یک بار در shutdown پاک‌سازی انجام می‌دهد. محدوده شامل WooCommerce transients، Post/Object Cache، صفحه محصول، آرشیو دسته و برچسب فعلی یا حذف‌شده، Shop و Home است. اتصال مستقیم هدفمند برای LiteSpeed Cache و WP Rocket وجود دارد و در صورت موجود بودن APIهای هدفمند، W3 Total Cache و WP Super Cache نیز پشتیبانی می‌شوند. Purge کل سایت یا Flush کل Object Cache انجام نمی‌شود. صفحات listing سفارشی را می‌توان با filter `mobo_core_cache_purge_urls` اضافه کرد.

از نسخه `10.31.74` آخرین نتیجه این عملیات در option غیرautoload با نام `mobo_core_cache_purge_last_result` نگهداری و در فیلد `cachePurge` گزارش سلامت ارسال می‌شود. گزارش شامل نسخه Mobo هنگام اجرا، زمان تلاش/موفقیت، تعداد هدف‌ها، مدت اجرا، خطای محدودشده و نتیجه و نسخه هر Integration است؛ Product ID و URL واقعی در گزارش ذخیره یا ارسال نمی‌شوند. خطای Cache همچنان non-blocking است و Sync محصول ادامه پیدا می‌کند.

از نسخه `10.33.16.2`، بعد از موفقیت Purge هدفمند، فقط permalink فعلی محصول در صف non-autoload `mobo_core_cache_warmup_queue` قرار می‌گیرد. این صف توسط Real Cron/Self Runner با batch محدود پردازش می‌شود و برای هر آیتم یک درخواست GET ناشناس same-origin می‌فرستد. هدف فقط گرم‌کردن صفحه همان محصول است؛ Home، Shop، category/tag archive و URL قبلی محصول warm نمی‌شوند. خطاهای موقت با backoff retry می‌شوند و وضعیت صف بدون افشای URL/Product ID داخل Site Health قابل مشاهده است.

<a id="en"></a>

# Full English documentation

## Targeted cache invalidation after sync

Mobo Core `10.31.73` batches repeated saves for the same product/variation and invalidates them once during shutdown. The scope includes WooCommerce transients, targeted post/object cache entries, the product page, current and removed category/tag archives, Shop, and Home. LiteSpeed Cache and WP Rocket receive direct targeted purge calls; W3 Total Cache and WP Super Cache are used when their targeted APIs exist. No site-wide page-cache purge or global object-cache flush is performed. Additional custom listing URLs can be supplied through `mobo_core_cache_purge_urls`.

Since `10.31.74`, the last result is persisted in the non-autoloaded `mobo_core_cache_purge_last_result` option and sent in the health payload as `cachePurge`. It contains Mobo/plugin versions, attempt/full-success times, target counts, duration, bounded errors, and per-integration status without storing or transmitting the target product-ID or URL lists. Cache failures remain non-blocking for product synchronization.

Since `10.33.16.2`, a successful targeted purge queues only the product's current permalink in the non-autoloaded `mobo_core_cache_warmup_queue`. Real Cron/Self Runner drains the queue in bounded batches and sends one anonymous same-origin GET per item. Home, Shop, category/tag archives, and previous product permalinks are not warmed. Transient failures use retry backoff, while Site Health exposes only queue counts/status and never the queued URL or product-ID list.



## 1. Product purpose and architecture

Mobo Core is the integration layer between WooCommerce and MoboCore. It retrieves catalog data from the Portal API, maps remote identifiers to local objects, applies changes idempotently, and moves expensive work into bounded resumable queues.

Primary components:

1. **MoboCore Portal v25**: API, licensing, product data, event payloads, health, and central mappings.
2. **Mobo Core 10.33.16.18**: WordPress integration, local queues, sync, mapping, images, checkout, and customer-side operations.
3. **WooCommerce**: the final product, variation, category, media, cart, and order store on the customer site.
4. **the provisioned Source**: the dedicated cart-check and order-submission workflow, used only when checkout features are enabled.

See [`DIAGRAMS.MD`](DIAGRAMS.MD#en) for the complete diagrams.

### Adaptive Self-Tuning (10.33.16.18)

The runner uses the lightweight 24-hour `runtimeDiagnostics` history to calculate request-local worker limits. Saved settings remain the immutable baseline; Mobo Core does not write automatic batch-size changes back to the database. Webhook ceiling, Parent Finalize, Image Queue, Reprice, Recategorize, Cache Warmup and, starting in 10.33.16.18, Product Sync can move within safe bounds based on recent milliseconds per item. Product Sync records exact per-step timing only in the request-local diagnostics buffer and, after at least four measured steps, may tune `productStepsPerRound` from 1 up to 2x baseline with a hard cap of 20. Recent failures, memory pressure or repeated time-budget exhaustion retain conservative caps/downshifts. The feature can be disabled from the Real Cron tab.

## 2. Version compatibility

| Component | Version / status |
|---|---|
| Mobo Core | `10.33.16.18` |
| Portal | `v25` |
| Portal target framework | `net10.0` |
| WordPress | `5.8+` |
| PHP | `7.4+` |
| WooCommerce | `8.2+`, tested through `10.9` |
| Persian WooCommerce | Optional; not required by MoboCore |
| HPOS | compatibility declared |
| Cursor sync | supported for products and variations |
| Lightweight webhook pull | supported |

Legacy page-number requests remain supported. New installations prefer cursor pagination for more stable initial synchronization.

## 3. Installation and upgrade

### New installation

1. Back up the database and site files.
2. Install and activate WooCommerce; Persian WooCommerce is optional.
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

`Webhook Security Code` is transported as an HTTP header value and must contain only visible ASCII bytes from `0x21` through `0x7E`. English letters, digits, and symbols such as `@ # $ % & * _ [ ] -` are valid. Spaces, tabs, line breaks, Persian text, emoji, and all other Unicode characters are rejected. Maximum length is 255 characters.

API base URL priority:

1. `mobo_core_api_base_url` filter
2. `MOBO_API_BASE_URL` constant
3. `mobo_core_api_base_url` WordPress option

Example:

```php
define( 'MOBO_API_BASE_URL', 'the provisioned Backend' );
```

Basic test:

```bash
curl -H 'Token: YOUR_TOKEN'   'the provisioned BackendLicenseInfo'
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

The preferred production host-Cron setup is direct PHP CLI execution of `mobo-cron.php`. The HTTP endpoint is a fallback:

```text
/wp-json/mobo-core/v1/cron/run
```

HTTP fallback example using the header-based secret:

```cron
* * * * * curl -fsS --max-time 55 -H 'X-SEC: REPLACE_WITH_CRON_TOKEN' 'https://example.com/wp-json/mobo-core/v1/cron/run' >/dev/null 2>&1
```

Legacy `?token=` authentication remains supported for compatibility, but new setups should use `X-SEC` so the secret is not placed in the URL/history/access log.

Since `10.31.75`, each invocation runs repeated fair rounds rather than stopping after one fixed batch for most queues. Every round gives webhook, normal-image, image-refresh, product-sync, reprice, recategorize, and queued-order work a bounded share. The same process continues until the safe PHP deadline is near, no immediately runnable work remains, no progress is possible, the configured round cap is reached, or lease ownership is lost. Health and maintenance remain bounded within the same slice.

The runner owns an atomic token-based lease and renews its heartbeat before every major stage. A concurrent invocation returns `locked` without entering the queues. If PHP crashes, is killed by the host, or ends before normal cleanup, heartbeat renewal stops and the finite lease expires automatically. The effective TTL treats the administrator value as a minimum, expands enough to cover the runtime budget and longest blocking HTTP timeout, and remains capped. A failed compare-and-swap renewal means ownership is no longer proven, so the current process stops protected work immediately.

The self-runner uses non-blocking loopback requests to accelerate progress after a webhook or sync start. A direct cPanel/REST cron invocation now also dispatches a non-blocking continuation when it made progress and immediately runnable work remains. If loopback is blocked by the host, the next real-cron invocation is the fallback. A queue-stage exception is isolated, recorded, and disables only that stage for the rest of the current slice so independent queues can continue.

The health payload includes token-free `cronRunner` telemetry: Mobo version, last result/source, configured and effective budget, elapsed time, round count, stop reason, progress and continuation decisions, renewals, lease-loss state, failed stages, queue-pass counters, and the current lease heartbeat, expiry, and remaining seconds.

## 9. Category mapping

`synced_term_id` and `manual_term_id` are intentionally separate. Category synchronization updates the synced term, while product assignment prefers a configured manual term. Run Recategorize after changing mappings.

## 10. Pricing

Pricing can include API price/compare price, legacy rules, global adjustments, the per-variation `mobo_additional_price` field, and the `mobo_core_calculated_price_pair` filter. Reprice runs in bounded batches without requiring a full catalog sync.

## 11. Images

Normal imports use `wp_mobo_image_queue`. Repeated work reuses an existing valid WebP attachment matched by image GUID or source URL instead of creating a duplicate.

The administrator-facing refresh workflow is numbered and intentionally conservative: back up the database and uploads, complete one product Repair, scan legacy images through a full cursor cycle, build the queue without immediate execution, process it, run the dedicated WebP-subsize health scan, repair missing cuts, and only then enable destructive cleanup. On the first 10.31.65-or-newer image-maintenance upgrade run, old-attachment deletion and orphan deletion are switched off and require an explicit administrator opt-in after verification.

Subsize health checks the original file, attachment metadata, currently registered WordPress sizes, physical files, WebP output format, and GD/Imagick editor capability. Repair uses WordPress media APIs and then verifies the result again. A replacement is not assigned and the legacy attachment is not deleted when any required size, physical file, metadata field, or WebP format remains incomplete. Scan and repair results accumulate across the current cursor cycle.

The original raster and all WordPress derivatives such as `150x150`, `scaled`, `rotated`, and edited copies are treated as one image family. Registered Media Library families are not persisted as orphan candidates. A separate replaced-attachment scanner handles dry runs where the product was moved to WebP while the old registered Media Library attachment was intentionally kept. It follows durable replacement metadata, verifies the replacement and all cuts, and only then offers bounded safe deletion. Every destructive deletion is revalidated against attachment, product, content, metadata, taxonomy, options, and physical files. Orphan cleanup remains locked until a successful Repair has completed.

## 12. Checkout and order submission

Sensitive checkout features are disabled by default. When all validation and submission options are disabled, Mobo Core stays out of the customer checkout runtime.

Optional features include local stock checks, remote cart validation, external validation, optional city-cache compatibility, shipping mapping, and automatic Mobo order submission. An eligible order is queued only when it is entirely Mobo-owned, all Mobo items belong to one Connection, and it enters `processing`. Non-Mobo, mixed, and multi-Connection orders fail closed and no partial Mobo submission is attempted. Successful eligible submissions may move the WooCommerce order to `completed` when auto-complete is enabled.


### Queue settings lock during Sync and Repair

While a full Sync or Repair run is active, the Queue and Processing tab is immutable. Changing `products_per_page` or `variants_per_page` during page-based pagination can move page boundaries and skip or duplicate records; changing cursor mode changes the continuation strategy. The guard applies in the UI, in the server-side save handler, and to direct `update_option()` writes. `waiting_for_portal` is treated as an active resumable run until it is completed or cancelled.

### Persian WooCommerce settings for automatic submission

Automatic Mobo order submission does **not** require the Persian WooCommerce options “Enable Iran cities” or “Swap state and city fields”. Mobo Core does not read, modify, block, or periodically inspect those settings. Native WooCommerce order country/state/city values are resolved by the Backend Location Resolver only during automatic remote-order submission. Legacy city assets are retired in C75B.

Prior releases used generated Persian WooCommerce city assets, but C75B retires this mechanism. Native WooCommerce fields are used for checkout. The old numeric city decoder is retained temporarily for historical order compatibility until the isolated retry and shipping tests are complete. No global order-city migration is performed.

## 13. Order SMS and wallet alert

Mobo Core delegates SMS transport to Persian WooCommerce SMS through `PWSMS()->send_sms()`; local API availability or gateway acceptance is not proof of final handset delivery. Order-SMS settings are store-wide. Scenarios are `non_mobo`, `mobo_only`, and `mixed`, with Mobo-specific placeholders for order type and Mobo/non-Mobo item counts. These notifications run when Checkout creates/processes the WooCommerce order, not after payment success. A durable per-order/scenario replay fence is stored before the gateway call, so an ambiguous gateway/final-commit outcome is not automatically replayed. Failed/uncertain sends are recorded in Order Notes and there is no independent Order-SMS retry queue.

The low-wallet alert is Connection-scoped and independent from the Order-SMS master switch. It is evaluated only after durable successful automatic Mobo purchase for Providers that expose a wallet-balance endpoint. Threshold `0` is valid. The alert is one-shot and never auto-rearms after balance recovery or threshold changes; explicit administrator re-arm is required. A durable pre-send fence suppresses automatic resend after an ambiguous transport or post-send commit. Wallet transport tests use separate history, do not fetch Provider balance, and do not consume/re-arm the live one-shot state.

## 14. Health reporting

Portal can probe:

```http
GET /wp-json/mobo-core/v1/health
X-SEC: <security-code>
```

Portal pulls detailed site state from the authenticated Health endpoint. The plugin does not periodically push Health reports. If the reported cron is stale, Portal may invoke `/heartbeat` separately for recovery; heartbeat outcomes do not determine endpoint reachability.

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
- `mobo_core_security_code` is validated as visible ASCII before storage and before header transport; an invalid submitted value does not overwrite the previous secret.
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
- [MoboCore service](the provisioned Backend)
- Sales: `+989124508218`
- Technical support: `+989367362228`

---

<div align="center">

[Persian](#fa) · [English](#en) · [Full documentation](README_FULL.MD) · [Function reference](FUNCTIONS.MD) · [Diagrams](DIAGRAMS.MD)

</div>

### Current WooCommerce shipping-method policy

Mobo Core is **mapping-only** for checkout shipping. It maps merchant-owned WooCommerce shipping rates to the Source `shipping_id`; it does not register, create, or publish a customer-facing `mobo_core_shipping` rate. Any `mobo_core_shipping` zone instance is legacy residue and is kept disabled/retired.

- Network resilience for Portal API is endpoint-scoped: a circuit opened against one effective scheme/host/port is not inherited after an environment/runtime API-base override switches to another endpoint.


### REL24 exact-recovery retry reset
A valid Exact Convergence API response now clears transient retry state before candidate finalization, so a recovered dependency does not carry stale real-attempt budget into later failures. Network circuit scope remains endpoint-aware and production `mobo.codeya.ir` remains HTTP-only.

### REL25 targeted convergence window hardening
Post-upgrade exact convergence uses a bounded 1000-target worker window but the active generation now overlays ownership on all actionable unowned convergence gaps. Once a window is exhausted, a fresh owner-ignored Health view selects the next bounded window and resets the durable cursor. Successful old rows are pruned from the attempted ledger; unresolved rows are retained only to a bounded safety limit. This prevents >1000-gap catalogues from getting stuck on the first window or exposing the later windows as unowned while recovery is active.
