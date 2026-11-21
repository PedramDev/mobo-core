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

> **REL03-CUSTOM-STATE-RESIDUE-FINALIZER / 10.34.62:** Release-residue self-heal now proves convergence from Product Map + canonical Custom Storage Product/Variant state; Sync Health is observational output only. Re-arms the bounded finalizer for 10.34.61 upgrades. Backend remains unchanged.

> **HARDEN14-OBSERVABILITY-ADMIN-HEALTH / 10.34.61:** Admin Health now exposes durable monetary compatibility, precise network failure classes, backlog/stalled state and last successful sync without executing probes during rendering. HARDEN03 Backend remains unchanged.

> **HARDEN10-CHECKOUT-SCOPE-PREFLIGHT / 10.34.60:** Automatic Mobo order submission now rejects mixed, multi-Connection and ownership-ambiguous checkouts before payment, while leaving optional remote/cart validation independent. Phases 8/9/11/12/13 retain the existing production mechanisms and are covered by the HARDEN13 Test Suite checkpoint.

> **HARDEN06-REPLAY-OUT-OF-ORDER / 10.34.59:** Version-aware queue supersession and failed-event recovery prevent a late stale delivery from suppressing a failed newer desired state. Different non-empty incomparable watermarks fail closed instead of using arrival order, equal-version completed rows are replay-deduped, and equal-version ProductUpdated payloads with conflicting durable source hashes are blocked.

> **HARDEN04-AMBIGUOUS-PURCHASE-IDEMPOTENCY / 10.34.58:** Provider purchase ambiguity is fail-closed with durable attempt/remote-order checkpoints and positive-only reconciliation. A known remote order can only heal local uncertainty when provider details positively prove the exact expected order and `paid=true`; missing/false/incomplete evidence never authorizes another Checkout/Wallet POST. Reconciliation owns the same per-order and shared Provider-session leases, and stale queue/admin paths cannot replay a known remote purchase or post-success listeners.
> **HARDEN02-MONEY-CONTRACT / 10.34.56:** Connection provisioning now carries explicit Source monetary semantics (`money_unit`, fraction digits, representation and monotonic contract version). MoboMobo is provisioned as IRT/Toman with zero fraction digits. IRT and IRR are distinct; Product Sync, Reprice, Shipping and Order Submission fail closed on mismatch and no implicit x10 conversion remains. Backend reconciliation refreshes existing customers without requiring a license re-save.

> **REL02-DURABLE-RESIDUE-FINALIZER / 10.34.55:** Pre-release Plugin Gate proved that 10.34.54's bounded residue scan still depended on retained historical done-event rows. 10.34.55 re-arms the scan and promotes current durable state to the proof boundary: exact applied Product/Variant revision+version, matching source hash, completed authoritative desired-state, complete child mappings, sane local topology, and no active event owner. Historical event rows are no longer required. `_thumbnail_id` duplicate normalization now preserves the oldest row only when WordPress' public singleton read resolves to that exact value, allowing conflicting later rows to be removed without changing the effective thumbnail. Portal/.NET is unchanged.

> **REL01-CONVERGENCE-RESIDUE / 10.34.54:** Pre-release Plugin Gate found a historical cohort of 927 variable products with sane local topology and current source/applied evidence but stale `mobo_sync_incomplete` + Product Map incomplete markers. The old 10.33.53 proof-heal scanned only one 200-row `behind` page. 10.34.54 makes that exact proof bounded/resumable across map-id pages, includes already-`synced` observational Health rows, and lets real Cron drain pending proof pages inside its existing 12-second migration budget. It also removes provably redundant `_thumbnail_id` rows for current Mobo products only when all duplicate values are identical; conflicting values fail closed for review. No Portal/.NET change.

<div align="center">

# Mobo Core for WooCommerce

**اتصال کنترل شده فروشگاه ووکامرس به MoboCore و جریان کاری the provisioned Source**  
**Controlled WooCommerce integration with MoboCore and the the provisioned Source workflow**

![Plugin Version](https://img.shields.io/badge/Mobo_Core-10.34.62-1f6feb)

> **MIGOPT09-BACKFILL-CACHE / 10.34.53 (bounded Backfill meta cache):** The clean 10.34.52 `test1` FULL benchmark completed correctly in 25 slices with 959,936 DB queries and ~82.5s process CPU, but peak PHP memory rose to 157,286,400 bytes (~150 MiB) while accelerated Backfill processed many 100-row units in one request. 10.34.53 bulk-primes WordPress postmeta once per bounded Backfill unit and releases that unit's postmeta cache in `finally`, so speed no longer comes with request-long accumulation of completed Backfill pages. The 100-row durable unit, cursor/CAS, fence/grace, replay, parity and zero-residue rules are unchanged. Candidate only.

> **MIGOPT08-PREFENCE-READS / 10.34.52 (trusted pre-fence read scope):** The clean 10.34.51 `test1` benchmark cut requests 153→28, DB queries ~1.92M→~1.20M, and peak PHP memory ~272MiB→~136MiB, while preserving zero residue/parity. The remaining hot paths still spent ~628k queries in Backfill and ~485k in Operational proof. 10.34.52 adds an explicit migration-only pre-fence Legacy-read scope so those two trusted workers do not repeatedly re-read the durable terminal fence for every Operational meta field. The scope is entered only by the retirement worker before fence activation and is always unwound with `finally`; normal runtime/webhook/write paths keep the original per-call fence checks. Batch sizes, grace windows, cursor/CAS/journal/parity/zero-residue protections are unchanged. Candidate only.

> **MIGOPT07-OPBACKFILL / 10.34.51 (bounded backfill loop + Operational verify-first):** A clean `test1` run on 10.34.50 completed correctly but exposed the remaining migration hot paths: 132 `waiting-backfill` requests consumed ~772k DB queries, Operational proof consumed ~1.03M DB queries, and request-local meta caching peaked near 272 MiB. 10.34.51 treats only `waiting-backfill + backfillStatus=running` as active bounded work so multiple durable 100-row backfill units may run in one Cron slice, adds durable backfill cursor telemetry to the progress fingerprint, converts Operational proof to bulk-prefetched verify-first/no-op behavior with mirror+fresh verification only on mismatch, and bounds proof memory by avoiding unnecessary Identity full-meta priming and releasing Operational page meta cache after each unit. Backfill/Operational units stay small; no aggressive batch growth was added. Candidate only; benchmark/chaos-test before release.

> **MIGOPT06-SLICELOOP / 10.34.50 (real multi-batch Cron slice):** `test1` telemetry proved 10.34.49 collapsed the Identity hot path, but also exposed that every accelerated migration request still stopped at one iteration. The waiting detector was matching the JSON field name `lastError` even when its value was empty. 10.34.50 inspects only real status/error values, so active phases can consume the existing bounded 12-second slice while genuine grace/waiting/blocked/error states still stop immediately. Worker safety/checkpoint semantics are unchanged. Candidate only; benchmark before release.

> **MIGOPT05-IDENTITY-NOOP / 10.34.49 (query-optimized crash-safe migration):** Keeps the full customer-host crash/replay contract and fixes the measured Identity proof hot path: a bulk-prefetched object/parent whose Custom identity is already exact is now verified without replaying the heavyweight Legacy bridge. Only mismatched/missing Custom state takes the original mirror + fresh-read verification path. Operational/Identity cleanup cursors, existence-first blockers, byte-bounded Entity bulk SQL, CAS checkpoints, terminal residue proof and all fence/grace protections remain unchanged. Candidate only; benchmark before release.

> **MIGOPT03-SAFE / 10.34.47 (crash-safe customer-host migration):** Migration now treats arbitrary PHP/host termination, DB errors/reconnects, overlapping Cron/web requests, lock loss, partial batches, and ultra-late Legacy writes as normal recovery conditions. The 12-second Cron slice remains bounded, state checkpoints use optimistic CAS, Entity destructive cleanup uses a durable two-phase journal plus immutable `legacy_origin_meta_id`, and completed migrations are re-probed for physical residue so late writes can self-heal without rolling back the schema version. Custom Storage schema is v14. Portal/.NET is unchanged and no WP-CLI dependency is introduced. This remains a benchmark/chaos-test candidate, not a release build. See `docs/releases/RELEASE_10.34.47.md` and `docs/internal/migration-customer-hosting-chaos-contract.md`.

> **REL01 / 10.34.44 (terminal Sync-Health evidence retention):** Maintenance now preserves terminal `UpdateVariant` upstream-invalid evidence into durable Sync Health before deleting old failed sync-event rows. Operational health can use that persisted version/error after queue retention, so a product cannot silently change from `terminal-upstream-invalid` to `unowned convergence gap` merely because the 30-day failed-event retention window elapsed. This cumulative candidate retains PERF01–PERF12 from 10.34.42; Portal/.NET is unchanged. See `docs/releases/RELEASE_10.34.44.md`.

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

Mobo Core افزونه تخصصی ووکامرس برای فروشگاه های ایرانی است که محصولات، تنوع ها، دسته بندی ها، تصاویر، قیمت و موجودی را از MoboCore دریافت می کند و رویدادهای تغییر را از طریق وب هوک پردازش می کند. در صورت فعال سازی تنظیمات مربوطه، افزونه می تواند اعتبارسنجی خرید، Location Resolution مرکزی، نگاشت روش ارسال و ثبت خودکار سفارش در جریان اختصاصی `the provisioned Source` را نیز انجام دهد.

این مخزن مربوط به افزونه وردپرس است. بک اند سازگار فعلی، Portal نسخه 25 با `.NET 10` است؛ با این حال هیچ کد یا مستند مشتری در پروژه .NET قرار داده نشده و تمام توضیحات قابل انتشار در همین افزونه نگهداری می شوند.

> **PERF11+PERF12 / 10.34.42 (indexed Parent queue-order candidate):** Custom Storage schema v13 adds `queued_at_us` plus `queue_order(connection_id,queue_type,queued_at_us,id)`. Historical Parent rows are backfilled lazily with payload-hash/dedupe CAS while the old full-map `queuedAt` path remains the migration fallback. After a Connection is fully backfilled, Parent Finalize selects only a bounded indexed due snapshot and retains the existing per-row claim/commit fences. Current payload `queuedAt` is second-resolution; `_us` is an indexed representation, not invented precision. No .NET change or measured speed claim. See `docs/releases/RELEASE_10.34.42.md` and backlog BV/BW.

> **PERF09+PERF10 / 10.34.40 (cross-source WordPress-only performance candidate):** Portal source was inspected read-only. PERF09 uses the existing licensed `/{productId}/get-variants-count` endpoint only as a non-destructive multi-Variant proof (`count > 1`) before a ProductUpdated Simple-topology probe; zero/one/error still fetch the full authoritative Variant snapshot. PERF10 persists an already-pulled immutable UpdateVariant `EventWebhook.Data` payload into deferred queue progress so parent-wait retries do not download the same event again. No .NET file/API/schema change, no destructive count-only topology decision, no throughput percentage claim. See `docs/releases/RELEASE_10.34.40.md` and backlog BT/BU.

> **PERF07+PERF08 / 10.34.38 (separate cumulative source-only candidate):** PERF07 removes the final Parent Finalize full-map materialization and ordinary due probe by using strict row-store pending/due aggregates while intentionally preserving the authoritative start-of-batch payload `queuedAt` snapshot. PERF08 rewrites Reprice count/candidate SQL into direct Connection-owned Product/Variation branches with `EXISTS` metadata filters and `UNION ALL`, removing row multiplication plus `DISTINCT` without changing the stable post-ID cursor. No DDL, measured throughput claim or live MySQL execution-plan proof; installed 10.34.30/r8.3.179 Plugin Gate remains unaffected. See `docs/releases/RELEASE_10.34.38.md` and backlog BR/BS.

> **PERF03+PERF04 / 10.34.34 (separate source-only candidate):** Grouped verified read-back is used only for contiguous no-op price-source metadata; ordering/payment/crash fences retain their individual durability checks. Order Recovery reads a bounded, hash-verified FIFO page and cleans only the observed payload generation using owner-scoped CAS. Primary Order Submission and Parent Finalize batch processing still contain historical full-map work and are not described as row-level. No SQL performance benchmark or production sign-off is claimed; the frozen 10.34.30 r8.3.179 Plugin Gate is unaffected. See `docs/releases/RELEASE_10.34.34.md` and backlog BN/BO.

> **PERF01+PERF02 / 10.34.32 (separate performance candidate):** The existing row-store `put()` now checks the exact key after upsert rather than reading the entire queue. Parent Finalize uses a row-based enqueue below its capacity boundary; the historical 5,000-item eviction path remains unchanged. The request-local variation signature index now maintains a reverse winner lookup. No Woo/Portal runtime benchmark or throughput percentage is claimed. Do not use r8.3.179 (pinned to 10.34.30) as a release gate for this branch; see `docs/releases/RELEASE_10.34.32.md` and backlog BL/BM.

> **UX66+UX67 / 10.34.30:** تقویت کنترل مالکیت تازه Connection در مسیرهای تخصصی Checkout/External Validation و امضای Portal، و حذف متن خام Exception/HTTP از مسیرهای تشخیصی تأییدشده. Candidate است؛ بدون اثبات Runtime دوپردازه، Portal و MySQL قابل تأیید برای انتشار عمومی نیست. جزئیات در `docs/releases/RELEASE_10.34.30.md` و Backlog بخش‌های BJ/BK.

> **UX64+UX65 / 10.34.28:** مالکیت Product Map برای یک Woo ID بین Sourceها با lease و GET_LOCK مشترک محافظت می‌شود؛ Revision/Ack سفارشی در برابر داده قدیمی fail-closed شده است. API Client پیش از هر GET/POST و Redirect مالک تازه و Fingerprint مسیر/توکن را کنترل می‌کند؛ تغییرات Admin/Provisioning با CAS محافظت می‌شوند و خطاهای خام upstream در این Client منتشر نمی‌شوند. این Candidate جایگزین تست واقعی WordPress/MySQL/Portal نیست؛ جزئیات در `docs/releases/RELEASE_10.34.28.md` و backlog بخش‌های BH/BI.

> **UX62+UX63 / 10.34.26:** Audit امنیت REST و مالکیت Multi-Connection شامل بازخوانی بدون کش WebHookId/Token، جلوگیری از پذیرش X-SEC قدیمی، کنترل Provisioning هم‌زمان و Nonce با CAS؛ همچنین حفاظت Upgrade هنگام Rollback نامطمئن، نگه‌داشتن Recovery Hold، CAS وضعیت Barrier و محافظت از شواهد مالی/صف‌ها هنگام Uninstall. این Candidate فقط در سطح Source/Mock بررسی شده و PASS واقعی WordPress/MySQL/Portal نیست. جزئیات در `docs/releases/RELEASE_10.34.26.md` و backlog بخش‌های BF/BG ثبت شده‌اند.

> **UX60+UX61 / 10.34.24:** ادامه Full Pipeline Audit: تجدید lease بین Sourceهای Dispatcher، گزارش aggregate صحیح در slice ناقص، تأیید دوباره fail-closed یونیکد پس از درج lock، و ثبت نوبت Warmup تنها هنگام اجرای Stage. انتقال Legacy صف سفارش/Parent حالا additive و checkpointed است؛ map read ناسالم مانع write مخرب می‌شود و update/delete مربوط به replace_map با payload-hash CAS انجام می‌شود. تست‌های چندپردازه WAMP/MySQL هنوز در backlog بخش‌های BD و BE باز هستند؛ این نسخه به‌عنوان Runtime PASS اعلام نشده است.

> **UX59 / 10.34.22:** خروجی تجمیعی UX58 و UX59: قفل مشترک Connection/Product برای صف و لینک تصاویر، محافظت از پاک‌کردن گالری با snapshot خالی قدیمی، و قفل نسل درخواست API برای دسته‌بندی همراه با CAS در Retirement و محافظت از نشانگر فعال/بازنشسته. تست‌های عملی concurrent/WAMP هنوز در backlog بخش‌های BB و BC هستند و نباید PASS تلقی شوند.

> **UX57 / 10.34.20:** چهارمین مرحله Audit سراسری: نتیجه دیرهنگام Shipping API نمی‌تواند Snapshot Webhook جدیدتری را حتی داخل یک ثانیه بازنویسی کند؛ Wallet Alert از مالک تازه، Route یکسان، Lock مشترک ارسال/Rearm و خطاهای بدون متن خام upstream استفاده می‌کند. Address Mapping و City Assets قبل از شروع عملیات مالک معتبر را می‌خواهند و از Lockهای Connection-scoped موجود استفاده می‌کنند. UX56 و UX57 در یک بسته منتشر شده‌اند. تست‌های Mock/ساختاری جایگزین WAMP/Portal نیستند؛ به گزارش و backlog بخش‌های AZ و BA مراجعه کنید.

> **UX56 / 10.34.19:** سومین مرحله Audit سراسری: Routing سفارش با خواندن بدون cache از Connection قبل از درخواست‌های Portal و مرحله Wallet کنترل می‌شود، Quarantine سفارش `running` قدیمی فقط با همان Per-Order Lock و بازخوانی وضعیت Woo انجام می‌گیرد، و Queue Marker باقیمانده اجازه Retry خودکار سفارش failed/aborted را ندارد. نتیجه تست Mock معادل اثبات پرداخت واقعی نیست؛ جزئیات در سند Audit و backlog بخش AZ ثبت شده است.

> **UX55 / 10.34.18:** ادامه Audit سراسری: snapshot مرجع Full/Repair Sync پیش از HTTP GET ثبت می‌شود؛ وقتی Webhook جدیدتری مراحل والد را supersede کند، تصاویر/Variation از checkpoint قبلی ادامه نمی‌یابند و والد با exact GET مجدداً دریافت می‌شود. Reprice والدهای نیازمند `WC_Product_Variable::sync()` را همراه Cursor در state نگه می‌دارد و پس از crash با قفل مالک همان محصول بازیابی می‌کند. فقط Mock/Static Gate این مرحله اجرا شده است؛ برای تست‌های واقعی WAMP به Backlog مراجعه کنید.

> **UX54 / 10.34.17:** شروع Audit سراسری Pipelineها؛ اصلاح Uninstall، محدودسازی Exception Logging در REST/Cron، کنترل مالک معتبر/فعال هنگام ثبت و پردازش Webhook و نگه‌داشتن Eventهای متعلق به Connection غیرفعال به‌صورت قابل‌ازسرگیری. این مرحله هنوز Full Runtime Gate نیست. جزئیات: `docs/internal/full-plugin-pipeline-audit.md`.

> **UX53 / 10.34.16:** بهینه‌سازی Cache Warmup برای کاتالوگ بزرگ شامل Selective Warmup پیش‌فرض، صف HOT/WARM مستقل با عملیات Row-based، کنترل GET سراسری، ظرفیت رزرو HOT، مهاجرت افزایشی صف قدیمی، نوبت‌دهی مستقل چند Connection و اعتبارسنجی پاسخ Cache. جزئیات عملیاتی: `docs/internal/cache-warmup-30k-policy.md`.

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
- پیامک سراسری بر اساس نوع سفارش و هشدار one-shot موجودی کیف پول Connection-scoped از طریق افزونه «پیامک حرفه ای ووکامرس»
- Real Cron، Self Runner، گزارش سلامت و ابزارهای عیب یابی
- Adaptive Self-Tuning برای تنظیم موقت ظرفیت Worker بر اساس سرعت واقعی Stageها، فشار حافظه و بودجه اجرا بدون تغییر تنظیمات ذخیره‌شده
- اعلام سازگاری با WooCommerce HPOS

### نیازمندی ها

| مورد | حداقل / وضعیت |
|---|---|
| WordPress | `5.8+` |
| PHP | `7.4+` |
| WooCommerce | `8.2+` |
| ووکامرس فارسی | اختیاری؛ برای MoboCore الزامی نیست |
| WooCommerce tested up to | `10.9` |
| Mobo Core | `10.34.51` |
| Portal سازگار | `v25 / .NET 10` |
| دسترسی خروجی HTTP | به MoboCore و در صورت فعال بودن، `the provisioned Source` |

### نصب سریع

1. پوشه `mobo-core` را در مسیر `/wp-content/plugins/` قرار دهید یا ZIP را از بخش افزونه های وردپرس نصب کنید.
2. WooCommerce را نصب و فعال کنید، سپس Mobo Core را فعال کنید؛ ووکامرس فارسی پیش‌نیاز نیست.
3. از مسیر **موبو > خرید و فعال سازی** وضعیت حساب و لایسنس را بررسی کنید.
4. در **موبو > اتصال**، مقدار API Base URL، Token و Webhook Security Code را وارد کنید.
5. در **موبو > کران واقعی**، Cron Token را تنظیم و Cron سرور را فعال کنید.
6. قبل از فعال کردن ثبت خودکار سفارش، Resolver مرکزی Backend و نگاشت روش ارسال را روی محیط آزمایشی تست کنید.
7. در سایت هایی که از نسخه های قدیمی مانند نسخه 7 ارتقا یافته اند، یک Repair کامل اجرا کنید.

### اتصال پایه

```php
// wp-config.php
// مقدار فعلی پیش فرض افزونه در نسخه 10.33.16.18:
define( 'MOBO_API_BASE_URL', 'the provisioned Backend' );
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

مسیر HTTP جایگزین Cron/Worker:

```text
https://example.com/wp-json/mobo-core/v1/cron/run
```

در حالت HTTP، Cron Token را در Header `X-SEC` ارسال کنید. روش اصلی پیشنهادی برای Cron هاست، اجرای مستقیم فایل `mobo-cron.php` با PHP CLI است؛ URL دارای `?token=` فقط برای سازگاری قدیمی باقی مانده است.

> Token، Security Code، رمز حساب موبو و Cron Token را داخل مخزن Git ثبت نکنید. اطلاعات حساب موبو در WordPress options ذخیره می شود؛ دسترسی مدیر، دیتابیس و فایل های backup باید محدود باشد.

### مستندات

| فایل | کاربرد |
|---|---|
| [`README_FULL.MD`](README_FULL.MD#fa) | نصب، پیکربندی، عملیات، امنیت، خطایابی و راهنمای مشتری |
| [`FUNCTIONS.MD`](FUNCTIONS.MD#fa) | REST API، endpointها، hookها، filterها، optionها، جداول و کلاس های PHP |
| [`DIAGRAMS.MD`](DIAGRAMS.MD#fa) | نمودار معماری، Sync، Webhook، Cron، Checkout، Health و تصاویر |
| [`readme.txt`](readme.txt) | فرمت انتشار افزونه برای WordPress.org |

### محدوده محصول

این افزونه یک کانکتور عمومی برای همه مارکت پلیس ها نیست و بدون اعلام رسمی مالک سرویس، به عنوان افزونه رسمی `the provisioned Source` معرفی نمی شود. محصول برای فروشگاه های ووکامرس فعال در ایران و جریان کاری مشخص Mobo/provisioned-source طراحی شده است.

### پشتیبانی و فروش

- فروش و فعال سازی: `+989124508218` — [Telegram](https://t.me/yazdan_ghadiri) — [WhatsApp](https://wa.me/989124508218)
- پشتیبانی فنی: `+989367362228` — [Telegram](https://t.me/Codeya)
- سرویس: [the provisioned Backend](the provisioned Backend)
- مخزن: [PedramDev/mobo-core](https://github.com/PedramDev/mobo-core)

### مجوز

GPLv2 or later. فایل [`LICENSE`](LICENSE) را ببینید.

---



### مرکز وضعیت نوسازی تصاویر

در نسخه 10.31.70 بالای تب نوسازی تصاویر یک مرکز وضعیت واحد نمایش داده می شود. این بخش به شکل مستقیم اعلام می کند عملیات در حال اجرای batch است، منتظر اجرای بعدی Cron/Self Runner مانده، متوقف شده، خطا دارد، منتظر تایید حذف است یا کامل شده است. مرحله جاری، درصد همان مرحله، پیشرفت تقریبی کل چرخه، آخرین فعالیت واقعی، سلامت موتور اجرا، نتیجه آخرین batch و مسیر ۹ مرحله ای نیز در همان کادر دیده می شوند.

### اجرای خودکار امن نوسازی تصاویر

در تب «نوسازی تصاویر»، دکمه «شروع یا ادامه اجرای خودکار امن» تمام مراحل اسکن، ساخت صف، جایگزینی، بررسی و بازسازی برش های WebP و اسکن های تاییدی را با batch محدود از طریق Cron واقعی یا Self Runner پیش می برد. اجرای خودکار در خطا متوقف می شود. حذف پیوست قدیمی جایگزین شده از تنظیم دائمی «حذف پیوست قدیمی بعد از جایگزینی امن» پیروی می کند و پس از یک بار فعال سازی دیگر در شروع، توقف، خطا یا پایان چرخه خاموش نمی شود؛ فقط پاکسازی خانواده فایل بدون پیوست تایید جداگانه خود را نگه می دارد.

وضعیت همین تب بدون بازخوانی صفحه به صورت خودکار تازه می شود. هنگام اجرای Automation فاصله بررسی ۴ ثانیه و در حالت عادی ۱۲ ثانیه است. اگر مدیر یکی از تنظیمات فرم را تغییر دهد، به روزرسانی موقتا متوقف می شود تا مقدار ذخیره نشده از بین نرود.


### تخلیه چنددوره‌ای صف و Lease امن Cron

از نسخه `10.31.75` هر فراخوانی Cron واقعی فقط یک batch ثابت اجرا نمی‌کند. Runner صف‌های Webhook، تصاویر، نوسازی تصاویر، Sync محصول، Reprice، Recategorize و سفارش‌های queued را در دورهای منصفانه و پشت‌سرهم پردازش می‌کند و تا نزدیک‌شدن به بودجه امن PHP، خالی‌شدن کار قابل‌اجرا، نبود پیشرفت، رسیدن به سقف دورها یا از دست‌رفتن مالکیت Lock ادامه می‌دهد. مقدار «حداکثر step محصول» سهم همان صف در هر دور است، نه سقف کل اجرای Cron.

قفل Runner یک lease اتمیک token-based با زمان انقضای محدود است. مالک قبل از هر مرحله heartbeat می‌فرستد و lease را تمدید می‌کند؛ اجرای همزمان دوم با وضعیت `locked` خارج می‌شود. اگر Process به دلیل خطا، timeout، kill شدن PHP یا crash متوقف شود، heartbeat قطع می‌شود و Lock بعد از TTL منقضی و در اجرای بعدی خودکار بازیابی می‌شود. TTL موثر بر اساس بودجه اجرا و طولانی‌ترین timeout شبکه افزایش می‌یابد، اما دائمی نیست و سقف دارد. اگر Runner در میانه اجرا مالکیت Lock را از دست بدهد، بلافاصله پردازش بیشتر را متوقف می‌کند.

اگر بعد از پایان slice هنوز صف قابل‌اجرا باقی مانده و پیشرفت واقعی انجام شده باشد، Cron Runner یک Self Runner غیرمسدودکننده برای ادامه کار kick می‌کند؛ بنابراین حتی Cron مستقیم cPanel نیز در حالت سالم loopback منتظر دقیقه بعد نمی‌ماند. اگر loopback در هاست مسدود باشد، اجرای Cron بعدی ادامه کار را برمی‌دارد. وضعیت آخرین اجرا، تعداد دورها، علت توقف، heartbeat/انقضای Lock، تمدیدها، stageهای خطادار و نیاز به ادامه داخل فیلد `cronRunner` گزارش سلامت ارسال می‌شود؛ token قفل هرگز گزارش نمی‌شود.

### پاک‌سازی هدفمند Cache محصولات

از نسخه `10.31.73` هر ذخیره واقعی محصول یا Variation متصل به موبو در انتهای همان اجرای PHP به‌صورت تجمیعی Cache را invalidate می‌کند. افزونه `WooCommerce product transients` و Object Cache مربوط به Post را پاک می‌کند و سپس URL محصول، دسته‌ها و برچسب‌های فعلی و حذف‌شده، Shop و Home را برای LiteSpeed Cache، WP Rocket، W3 Total Cache و WP Super Cache در صورت در دسترس بودن API هدفمند آنها purge می‌کند. هیچ `wp_cache_flush()`، `rocket_clean_domain()`، `litespeed_purge_all` یا Purge All دیگری اجرا نمی‌شود.

برای صفحات سفارشی Elementor/Block که فهرست محصولات را خارج از Shop/Home نمایش می‌دهند، URLهای اضافی را با filter `mobo_core_cache_purge_urls` اضافه کنید. پاک‌سازی Home نیز با `mobo_core_cache_purge_home_enabled` قابل کنترل است.

از نسخه `10.31.74` نتیجه آخرین Purge نیز داخل `cachePurge` گزارش سلامت ارسال می‌شود: وضعیت کلی، زمان آخرین تلاش و موفقیت کامل، نسخه Mobo، تعداد محصول/Object/URL، مدت اجرا، خطاهای متوالی و آخرین خطای محدودشده. برای WordPress/WooCommerce، WP Rocket، LiteSpeed Cache، W3 Total Cache، WP Super Cache و hookهای سفارشی، نسخه تست‌شده، نسخه فعلی و وضعیت `success`، `failed`، `not_detected` یا `not_tested` ثبت می‌شود.

از نسخه `10.33.16.2` بعد از Purge موفق، فقط permalink فعلی همان محصول وارد صف `Product Cache Warmup` می‌شود. Real Cron یک GET ناشناس و same-origin برای همان URL انجام می‌دهد تا Cache صفحه محصول دوباره ساخته شود. این Warmup هیچ URL مربوط به Home، Shop، دسته، برچسب یا permalink قدیمی را preload نمی‌کند و اجرای Sync را منتظر render صفحه نگه نمی‌دارد.

<a id="en"></a>

## English overview

Mobo Core is a specialized WooCommerce integration for Iranian stores. It imports products, variations, categories, images, prices, and stock from MoboCore, then applies incremental changes delivered through webhooks. When explicitly enabled, it can also validate checkout data, map Mobo addresses and shipping methods, and submit eligible WooCommerce orders through the dedicated `the provisioned Source` workflow.

This repository contains the WordPress plugin. The current compatible backend is Portal v25 on `.NET 10`; no customer-facing documentation or code is added to the .NET project. All publishable customer documentation is maintained inside `mobo-core`.

### Main capabilities

- Resumable chunked product and variation synchronization with page and cursor modes
- Changed-only processing for `ProductUpdated` and `UpdateVariant`
- Lightweight webhook notifications with payload retrieval through the Pull API
- Database-backed webhook queue with a protected JSON-file fallback
- Remote GUID mapping for WooCommerce products, variations, and categories
- Bounded image queue, controlled WebP refresh, and safe orphan cleanup
- Pricing rules, per-variation additional price, and batch repricing
- Central Backend location resolution for auto-order, native WooCommerce checkout fields, and WooCommerce-to-Mobo shipping mapping
- Optional cart validation and asynchronous Mobo order submission
- Store-wide order-type SMS plus Connection-scoped one-shot wallet alerts through Persian WooCommerce SMS
- Real cron, loopback self-runner, health reporting, and diagnostics
- Adaptive self-tuning of request-local worker capacity from recent stage speed, memory headroom, and runner budget without overwriting saved settings
- WooCommerce HPOS compatibility declaration


### Multi-round queue draining and renewable cron lease

Since `10.31.75`, one real-cron invocation no longer stops after a single fixed batch for most queues. The runner gives webhook, image, image-refresh, product-sync, reprice, recategorize, and queued-order work a fair share in repeated rounds until the safe PHP deadline is near, no due work remains, no progress is possible, the configured round cap is reached, or lease ownership is lost. The product-step setting is a per-round share rather than the total limit for the whole invocation.

The global runner lock is an atomic token-owned lease with a finite expiry. Its owner renews a heartbeat before each major stage. A concurrent invocation exits as `locked`; a crashed or killed process stops renewing and the lease expires automatically. The effective TTL expands to cover the runtime budget and the longest blocking network timeout, remains capped, and is never permanent. Losing lease ownership causes the current worker to stop before doing more protected work.

When useful work was completed but immediately runnable work remains, the runner dispatches a non-blocking local continuation even when the original request came from direct cPanel PHP cron. If loopback requests are unavailable, the next server-cron invocation remains the fallback. The health payload now includes token-free `cronRunner` telemetry with the last status, rounds, stop reason, elapsed time, renewals, failed stages, continuation decision, and current lease heartbeat/expiry.

### Targeted product cache invalidation

Since `10.31.73`, Mobo-linked product and variation saves are deduplicated during the request and invalidated once at shutdown. Mobo Core clears WooCommerce product transients and targeted WordPress post/object caches, then purges the product URL, current and removed product category/tag archives, Shop, and Home through the targeted APIs exposed by LiteSpeed Cache, WP Rocket, W3 Total Cache, and WP Super Cache when available. It never calls `wp_cache_flush()`, `rocket_clean_domain()`, `litespeed_purge_all`, or another full-site purge.

Custom Elementor/Block listing pages can be appended through `mobo_core_cache_purge_urls`. Homepage purging can be controlled through `mobo_core_cache_purge_home_enabled`.

Since `10.31.74`, the health payload includes structured `cachePurge` telemetry: overall status, last attempt/full-success times, Mobo version, affected counts, duration, consecutive failures, and a bounded last error. Each supported integration reports its tested/current version and `success`, `failed`, `not_detected`, or `not_tested` state.

Since `10.33.16.2`, a successful targeted purge queues only the product's current permalink for deferred cache warmup. The Real Cron performs one anonymous same-origin GET for that URL so the product page cache can be regenerated without preloading Home, Shop, category/tag archives, or old permalinks, and without making product synchronization wait for the front-end render.

### Requirements

| Component | Minimum / status |
|---|---|
| WordPress | `5.8+` |
| PHP | `7.4+` |
| WooCommerce | `8.2+` |
| Persian WooCommerce | Optional; not required by MoboCore |
| WooCommerce tested up to | `10.9` |
| Mobo Core | `10.34.51` |
| Compatible Portal | `v25 / .NET 10` |
| Outbound HTTP access | MoboCore and, when enabled, `the provisioned Source` |

### Quick installation

1. Place the `mobo-core` directory in `/wp-content/plugins/`, or upload the ZIP through WordPress.
2. Install and activate WooCommerce, then activate MoboCore. Persian WooCommerce is optional.
3. Open **Mobo > Purchase & Activation** to verify the account and license.
4. Open **Mobo > Connection** and enter the API base URL, Token, and Webhook Security Code.
5. Configure a Cron Token in **Mobo > Real Cron**, then add the server cron request.
6. Test the central Backend Location Resolver and shipping method mapping before enabling automatic remote order submission.
7. Run one full Repair after upgrading a legacy installation such as version 7.

### Basic connection

```php
// wp-config.php
// Current built-in default in version 10.31.76:
define( 'MOBO_API_BASE_URL', 'the provisioned Backend' );
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

HTTP Cron/Worker fallback endpoint:

```text
https://example.com/wp-json/mobo-core/v1/cron/run
```

For HTTP fallback, send the Cron Token in the `X-SEC` header. The preferred host-Cron setup is direct PHP CLI execution of `mobo-cron.php`; query-string `?token=` authentication remains only for backward compatibility.

> Never commit the license Token, Security Code, Mobo account password, or Cron Token. Mobo account credentials are stored in WordPress options, so administrator access, database access, and backups must be protected.

### Documentation map

| File | Purpose |
|---|---|
| [`README_FULL.MD`](README_FULL.MD#en) | Installation, configuration, operations, security, troubleshooting, and customer guidance |
| [`FUNCTIONS.MD`](FUNCTIONS.MD#en) | REST API, endpoints, hooks, filters, options, tables, and PHP class reference |
| [`DIAGRAMS.MD`](DIAGRAMS.MD#en) | Architecture, sync, webhook, cron, checkout, health, and image diagrams |
| [`readme.txt`](readme.txt) | WordPress.org distribution metadata |

### Product scope

This is not a generic marketplace connector and must not be described as the official `the provisioned Source` plugin unless the service owner grants that authorization. It is designed for WooCommerce stores operating in Iran and using the specific Mobo/provisioned-source workflow.

### Sales and support

- Sales and activation: `+989124508218` — [Telegram](https://t.me/yazdan_ghadiri) — [WhatsApp](https://wa.me/989124508218)
- Technical support: `+989367362228` — [Telegram](https://t.me/Codeya)
- Service: [the provisioned Backend](the provisioned Backend)
- Repository: [PedramDev/mobo-core](https://github.com/PedramDev/mobo-core)

### License

GPLv2 or later. See [`LICENSE`](LICENSE).

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
- Post-upgrade targeted convergence now owns every actionable unowned gap while its generation is active, even when the durable mutation window is capped at 1000 rows.
- The worker rolls to a fresh bounded window instead of appending new targets behind an already-full 1000-row list.
- Final reclassification reads up to 2000 rows so carried unresolved rows cannot hide the next mutation window.
- Unresolved attempts are bounded to 1000; reaching that safety boundary releases ownership with an explicit unresolved count instead of looping indefinitely.
