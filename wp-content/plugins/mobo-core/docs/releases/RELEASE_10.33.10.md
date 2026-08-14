# Mobo Core 10.33.10 — Structured JSON / Serialized Image Reference Migration

این نسخه migration مراجع تصاویر قدیمی را از string/regex replacement به پردازش ساختاری ارتقا می‌دهد.

## JSON / Elementor

اگر metadata مثل `_elementor_data` یک JSON معتبر باشد، ابتدا با `json_decode()` باز می‌شود، ساختار آن recursive بررسی می‌شود و در پایان با `wp_json_encode()` دوباره ذخیره می‌شود.

نمونه امن:

- `image.id: OLD -> NEW`
- `image.url: old.jpeg -> new.webp`
- `gallery` / `media` / `thumbnail` containerها

کلید عمومی `id` فقط وقتی migrate می‌شود که همان node شواهد تصویر قدیمی داشته باشد یا داخل یک media container معتبر باشد. در نتیجه وجود یک URL قدیمی در یک widget باعث تغییر IDهای نامرتبط در کل document نمی‌شود.

## PHP serialization

- آرایه‌های serialize شده با `maybe_unserialize()` باز می‌شوند و بعد از migration توسط WordPress دوباره serialize می‌شوند.
- serialized stringهای تو در تو نیز decode -> recursive migrate -> `serialize()` می‌شوند؛ طول رشته‌های serialization هرگز با SQL/regex دستی تغییر داده نمی‌شود.
- public propertyهای objectهای serialize شده قابل migrate هستند.
- propertyهای private/protected یا ساختارهایی که قابل تغییر امن نیستند دست‌نخورده می‌مانند و Audit نهایی مانع حذف JPEG قدیمی می‌شود.

## Safety

- Stage 6 همچنان read-only است.
- Stage 7 فقط برای old -> WebP replacement تاییدشده migration انجام می‌دهد.
- پس از migration همه referenceها دوباره audit می‌شوند.
- اگر حتی یک reference قدیمی ناشناخته باقی بماند attachment قدیمی حذف نمی‌شود.
- Metadata stringها قبل از `update_metadata_by_mid()` با `wp_slash()` آماده می‌شوند تا JSON بازتولیدشده در عبور از API متادیتای وردپرس خراب نشود.

## Upgrade from 10.33.9

WebPهای دانلودشده، queue و replacementهای تکمیل‌شده حفظ می‌شوند. فقط Stage 6/7 و approval حذف reset می‌شوند تا JPEGهای نگه‌داشته‌شده با migrator ساختاری جدید دوباره بررسی شوند.
