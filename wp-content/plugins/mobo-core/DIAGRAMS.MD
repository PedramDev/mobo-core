# Mobo Core — Architecture & Flow Diagrams / نمودارهای معماری و جریان

<div align="center">

**Mobo Core `10.31.63` · Portal `v25 / .NET 10`**

[فارسی](#fa) · [English](#en) · [راهنمای کامل](README_FULL.MD) · [مرجع توابع](FUNCTIONS.MD) · [نمودارها](DIAGRAMS.MD)

</div>

---

<a id="fa"></a>

# نمودارهای فارسی

تمام labelهای اصلی نمودارها دوزبانه هستند تا یک نمودار مشترک برای نسخه فارسی و انگلیسی باقی بماند. GitHub این فایل را با Mermaid رندر می کند.

## 1. معماری کلان

```mermaid
flowchart LR
    P["MoboCore Portal v25<br/>.NET 10 + PostgreSQL"]
    W["Mobo Core 10.31.63<br/>WordPress Plugin"]
    WC["WooCommerce<br/>Products / Variations / Orders"]
    M["mobomobo.ir<br/>Cart & Order Workflow"]
    C["Server Cron / cPanel"]
    H["Portal Health Worker"]

    P -- "Token: Catalog API" --> W
    P -- "X-SEC: Webhook" --> W
    W -- "Product, Variation, Category, Image" --> WC
    WC -- "Checkout / Order" --> W
    W -. "Optional login, cart validation, submit" .-> M
    C -- "cron token" --> W
    H -- "X-SEC health probe" --> W
    W -. "Optional health report" .-> P
```

## 2. Sync کامل با Cursor

```mermaid
sequenceDiagram
    autonumber
    participant A as Admin/Cron
    participant WP as Mobo Core
    participant API as Portal API
    participant DB as WP Database
    participant WC as WooCommerce

    A->>WP: Start sync / شروع همگام سازی
    WP->>DB: Save syncId and cursor
    WP->>API: GET get-categories (Token)
    API-->>WP: Category payload
    WP->>WC: Upsert categories and map GUIDs
    loop Product cursor slices
        WP->>API: GET get-products?UseCursor=true&Cursor=N
        API-->>WP: Products + nextCursor + hasMore
        WP->>DB: Acquire product locks / compare hashes
        WP->>WC: Upsert changed products
        loop Changed product variations
            WP->>API: GET /productGuid/get-variants?UseCursor=true
            API-->>WP: Changed/current variations
            WP->>WC: Upsert variations; missing => outofstock
        end
        WP->>DB: Queue image jobs and persist progress
    end
    WP-->>A: isDone=true
```

## 3. Lightweight Webhook و Pull API

```mermaid
sequenceDiagram
    autonumber
    participant P as Portal
    participant R as WP REST /webhook
    participant Q as wp_mobo_sync_events
    participant F as JSON fallback
    participant API as Pull API
    participant S as Product Sync

    P->>R: POST eventId, type, changesUrl + X-SEC
    R->>Q: Enqueue persistent event
    alt Database queue unavailable
        R->>F: Store protected JSON file
    end
    R-->>P: accepted
    R->>R: Kick self-runner (non-blocking)
    R->>Q: Claim due event
    Q-->>R: Lightweight notification
    R->>API: GET changesUrl + Token + X-SEC
    API-->>R: Full payload
    R->>S: ProductUpdated / UpdateVariant
    S-->>Q: done, retry, waiting, or failed
```

## 4. پردازش Cron واقعی

```mermaid
flowchart TD
    CR["GET/POST /cron/run?token=..
کران سرور"] --> SECCron token valid?
    SEC -- No --> E401["401 Unauthorized"]
    SEC -- Yes --> LOCKRunner lock acquired?
    LOCK -- No --> BUSY["locked / another slice active"]
    LOCK -- Yes --> BUDGET["Start bounded time budget"]
    BUDGET --> WH["Process webhook queue"]
    WH --> SY["Continue product sync / repair"]
    SY --> IM["Process image queues"]
    IM --> RP["Reprice / Recategorize"]
    RP --> OR["Process queued Mobo orders"]
    OR --> HL["Health report if due"]
    HL --> MT["Bounded maintenance"]
    MT --> SAVE["Save last result and release lock"]
```

## 5. صف ها و Storage محلی

```mermaid
flowchart TB
    REST["REST Webhook"] --> EVENTS["wp_mobo_sync_events"]
    REST -. fallback .-> FILES["uploads/mobo-core/webhook-files/*.json"]
    SYNC["Product Sync"] --> PMAP["wp_mobo_product_map"]
    CAT["Category Sync"] --> CMAP["wp_mobo_category_map"]
    SYNC --> IQ["wp_mobo_image_queue"]
    REFRESH["Legacy Image Refresh"] --> IRQ["wp_mobo_image_refresh_queue"]
    CLEAN["Orphan Scanner"] --> OQ["wp_mobo_orphan_image_files"]
    PMAP --> WC["WooCommerce posts/meta"]
    CMAP --> TERMS["WooCommerce product_cat"]
    IQ --> MEDIA["WordPress Media Library"]
    IRQ --> MEDIA
    OQ --> UPLOADS["Protected deletion in uploads"]
```

## 6. دسته بندی و Mapping دستی

```mermaid
flowchart LR
    RC["Remote Category GUID"] --> MAP["wp_mobo_category_map"]
    MAP --> ST["synced_term_id
دسته ساخته شده از منبع"]
    MAP --> MT["manual_term_id
دسته انتخابی مدیر"]
    CS["Category Sync"] --> ST
    PA["Product Assignment"] --> CHOOSEManual mapping exists?
    CHOOSE -- Yes --> MT
    CHOOSE -- No --> ST
    MT --> WCAT["WooCommerce product categories"]
    ST --> WCAT
```

## 7. نوسازی امن تصویر

```mermaid
stateDiagram-v2
    [*] --> Scanned: find legacy jpg/png
    Scanned --> Queued: create refresh job
    Queued --> Downloading: acquire lock
    Downloading --> Imported: import new WebP
    Imported --> Reassigned: product points to new attachment
    Reassigned --> SafetyCheck: verify old attachment unused
    SafetyCheck --> Done: safe delete or keep old file
    Downloading --> Retry: transient failure
    Retry --> Queued: after backoff
    SafetyCheck --> Skipped: still referenced / unsafe
    Skipped --> [*]
    Done --> [*]
```

## 8. تولید فایل شهرهای موبو برای Checkout

```mermaid
flowchart TD
    API["MoboCore get-address-mapping"] --> CACHE["Cache countries / states / cities"]
    MAP["WooCommerce country/state mapping"] --> GEN["Mobo_Core_City_Assets"]
    CACHE --> GEN
    ALIAS["Province aliases: THR / TE / ..."] --> GEN
    GEN --> JS["uploads/mobo-core-public/assets/iran_cities.js"]
    GEN --> MIN["uploads/mobo-core-public/assets/iran_cities.min.js"]
    JS --> HANDLE["Replace pw-iran-cities"]
    MIN --> HANDLE
    HANDLE --> CHECKOUT["Persian_Woo_iranCities() in Checkout"]
    CHECKOUT --> VALUE["City field value = Mobo city_id"]
    VALUE --> ORDER["Validate city belongs to resolved Mobo state"]
```

## 9. Checkout و ثبت خودکار سفارش

```mermaid
sequenceDiagram
    autonumber
    participant U as Customer
    participant WC as WooCommerce
    participant MC as Mobo Core
    participant MM as mobomobo.ir
    participant Q as Order Queue

    U->>WC: Submit checkout
    WC->>MC: Cart/checkout validation hooks
    alt All Mobo checkout features disabled
        MC-->>WC: No runtime intervention
    else Validation enabled
        MC->>MC: Check GUID, sync state, local stock
        opt Remote Mobo cart validation
            MC->>MM: Login, clear cart, add items, compare
            MM-->>MC: Validation result
        end
        MC-->>WC: Allow or add checkout errors
    end
    WC->>WC: Order enters processing
    WC->>MC: order_status_changed
    alt Order contains Mobo item and submission enabled
        MC->>Q: Queue order idempotently
        Q->>MM: Login, rebuild cart, checkout, shipping
        MM-->>Q: Mobo order details
        Q->>WC: Save meta/log; complete only all-Mobo orders; keep mixed orders processing
    else Non-Mobo order
        MC->>WC: Mark as not_mobo and skip submission
    end
```

## 10. نگاشت روش ارسال

```mermaid
flowchart TD
    RATE["Selected WooCommerce rate"] --> RULE["Method rule / instance rule"]
    STATE["Resolved Mobo state"] --> SRULE["State-specific allowed IDs"]
    TIME["Current WordPress time slot"] --> TRULE["Time-slot scenario"]
    SNAP["Portal shipping snapshot"] --> RESOLVE["Resolve final Mobo shipping ID"]
    RULE --> RESOLVE
    SRULE --> RESOLVE
    TRULE --> RESOLVE
    RESOLVE --> ORDER["Mobo checkout payload"]
```

## 11. Health دوطرفه

```mermaid
sequenceDiagram
    participant WP as Mobo Core
    participant P as Portal
    participant HW as Portal Health Worker

    opt Customer health reporting enabled
        WP->>P: POST /api/site-health/report + X-SEC
        P-->>WP: accepted / state updated
    end
    loop Probe interval
        HW->>WP: GET /wp-json/mobo-core/v1/health + X-SEC
        WP-->>HW: Local health snapshot
        HW->>WP: GET /sync/status + X-SEC
        WP-->>HW: Sync state
        HW->>P: Store reachable/warning/critical state
    end
```

## 11. راهنمای خواندن نمودارها

- خط پیوسته: مسیر اصلی و فعال سیستم.
- خط نقطه چین: قابلیت اختیاری یا fallback.
- هر Queue به صورت bounded batch پردازش می شود.
- هر ارتباط حساس با Token، `X-SEC` یا Cron Token محافظت می شود.
- جزئیات route و کلاس ها در [`FUNCTIONS.MD`](FUNCTIONS.MD#fa) است.

## 12. نگهداری ترجمه

Labelهای Mermaid تا حد ممکن دوزبانه هستند. هنگام تغییر معماری، یک نمودار را اصلاح کنید و توضیحات فارسی و انگلیسی مرتبط را در همان commit بروزرسانی کنید.

---

<a id="en"></a>

# English diagram guide

The Mermaid diagrams above are shared by both languages. Their primary labels are bilingual so architecture changes only need one diagram update.

## Diagram index

1. High-level architecture
2. Cursor-based full synchronization
3. Lightweight webhook and Pull API
4. Real-cron processing
5. Local queues and storage
6. Category synchronization and manual mapping
7. Safe image refresh
8. Checkout and asynchronous order submission
9. Shipping-method resolution
10. Bidirectional health reporting and probing

## Operational interpretation

- Solid edges are primary runtime paths.
- Dotted edges indicate optional features or fallbacks.
- Every queue is processed in bounded slices.
- Sensitive transport is protected by the Portal Token, `X-SEC`, or a dedicated Cron Token.
- `wp_mobo_sync_events` is the primary webhook queue; JSON files are the compatibility fallback.
- Checkout automation is deliberately outside the default runtime until explicitly enabled.

## Related references

- [`README.MD`](README.MD#en)
- [`README_FULL.MD`](README_FULL.MD#en)
- [`FUNCTIONS.MD`](FUNCTIONS.MD#en)

---

<div align="center">

[Persian](#fa) · [English](#en) · [Full documentation](README_FULL.MD) · [Function reference](FUNCTIONS.MD) · [Diagrams](DIAGRAMS.MD)

</div>
