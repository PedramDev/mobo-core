# CP4 — قرارداد درخواست Quote و مرز عدم مجوز خرید

وضعیت: STAGED / DO NOT DEPLOY / RELEASE BLOCKED (۱۴ اکتبر ۲۰۲۶)

- متد `get_purchase_eligibility_quote()` در API Client افزوده شد. نخست Request را اعتبارسنجی می‌کند، سپس Capability لایسنس‌دار را از Backend می‌خواند. وقتی Backend از Quote معتبر پشتیبانی نمی‌کند، نتیجه `mobo_core_purchase_quote_unsupported` است؛ **هیچ POST Quote، POST خرید یا Wallet انجام نمی‌شود**.
- `validate_purchase_eligibility_quote_request()` پروتکل v1، اتصال/محصول/شناسه Variant غیرخالی، مقدار عدد صحیح مثبت، ارز صریح `IRT`/`IRR` و فهرست کامل Attribute فارسی را بررسی می‌کند. این اعتبارسنجی جای Owner Proof از Custom Storage را نمی‌گیرد.
- `validate_purchase_eligibility_quote_response()` فقط پاسخ صریح `UNVERIFIED_PENDING` با `purchase_allowed=false` و `provider_side_effect=false` را در این نسخه می‌پذیرد. پاسخ `ELIGIBLE_VERIFIED` حتی با `trusted=true` در payload خام مجوز نیست.
- سازگاری با Policy: `Stale != Unpurchasable`، اما تا استعلام زنده معتبر در همان Connection، Variant، Quantity و Currency قابل اثبات نباشد، اجازه Auto Submit جدید صادر نمی‌شود. Predicate هنوز عمداً Hook نشده است.
- قرارداد Provider-side در این مرحله تنها Interface + Adapter ایمنِ Unsupported است؛ هنوز Quote/Reserve واقعی از Portal.ir اثبات نشده است. 38 سناریوی Full-chain همچنان BLOCKED هستند.
