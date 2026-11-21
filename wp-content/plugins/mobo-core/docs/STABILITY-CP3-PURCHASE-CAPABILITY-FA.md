# MoboCore — CP3 / Purchase Eligibility Capability

وضعیت: **STAGED / DO NOT DEPLOY / RELEASE BLOCKED** — 2026-10-14

- `Mobo_Core_API_Client::get_purchase_eligibility_capability()` فقط از Transport موجود، Signed Connection و Token استفاده می‌کند؛ پاسخ را در Cache پایدار ذخیره نمی‌کند.
- پاسخ `get-purchase-eligibility-capability` با `protocol_version=1` و مقادیر بولین `quote_supported=false`, `provider_read_only_quote=false`, `purchase_allowed=false` در قالب قرارداد صریح Unsupported شناخته می‌شود. پاسخ 200 با schema نامعتبر، truthy رشته‌ای، نسخه دیگر یا ادعای `quote_supported=true` باعث خطای کنترل‌شده می‌شود.
- `Mobo_Core_Purchase_Eligibility::evaluate_backend_capability()` هیچ نتیجه `ELIGIBLE_VERIFIED` از Capability نمی‌سازد. این Endpoint تصمیم خرید نیست.
- Evidence ورودی `Mobo_Core_Purchase_Eligibility::evaluate()` در Primitive آزمایشی اکنون تنها Boolean واقعی `trusted === true` را می‌پذیرد؛ اما این Boolean **اصالت رمزنگاری‌شده** نیست و نباید از داده Woo یا پاسخ خام کاربر ساخته شود.
- Predicate به Checkout/Blocks/Auto Submit وصل نشده و هیچ سفارش Provider انجام نمی‌شود. برای Live Quote، Adapter قابل‌اعتماد/بدون Side Effect خرید، Connection-bound Price/Stock/Attributes، Revision، TTL، و Proof مسیر تأمین لازم است.
