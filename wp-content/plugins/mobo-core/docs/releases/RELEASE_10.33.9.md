# Mobo Core 10.33.9 — External Reference Migration for Replaced Images

این نسخه مرحله پاکسازی تصاویر قدیمی را کامل می‌کند. اگر یک JPG/JPEG/PNG قدیمی موبو با WebP سالم جایگزین شده باشد ولی هنوز در بخش دیگری از وردپرس reference داشته باشد، مرحله حذف دیگر صرفاً آن را برای همیشه نگه نمی‌دارد.

## رفتار جدید

مرحله ۶ همچنان کاملاً read-only است و فقط این موارد را مشخص می‌کند:

- پیوست‌هایی که بدون reference اضافه مستقیماً آماده حذف امن هستند.
- پیوست‌هایی که قبل از حذف نیاز به انتقال reference دارند.
- جایگزین WebP نامعتبر یا دارای subsize ناقص.

مرحله ۷ پس از تأیید مدیر:

1. ارتباط old attachment -> replacement WebP را از metadata نوسازی تأیید می‌کند.
2. referenceهای شناخته‌شده را از old attachment/URL به WebP منتقل می‌کند.
3. تمام referenceها را دوباره audit می‌کند.
4. فقط اگر هیچ reference قدیمی باقی نمانده باشد attachment قدیمی و برش‌های ثبت‌شده آن را حذف می‌کند.

## referenceهای قابل انتقال

- `post_content` شامل `wp-image-ID`، `attachment_ID`، URL اصلی و URL برش‌های ثبت‌شده وردپرس، shortcodeهای gallery و کلیدهای شناخته‌شده media در Gutenberg/Elementor JSON.
- featured image و gallery محصولات، از جمله محصولاتی که خودشان محصول موبو نیستند ولی از همان attachment استفاده کرده‌اند.
- `postmeta`، `termmeta` و `usermeta` برای IDهای scalar/serialized و URLهای شناخته‌شده.
- WooCommerce `_product_image_gallery` به‌صورت CSV.
- `site_icon`، `theme_mods_*` و `widget_*`.

Metadata داخلی خود Mobo که برای audit و provenance عمداً ID قدیمی را نگه می‌دارد migrate نمی‌شود.

## Safety

- Migration فقط وقتی اجرا می‌شود که replacement ثبت‌شده همان WebP معتبر باشد.
- Stage 6 هیچ write انجام نمی‌دهد.
- Serialized objectهای ناشناخته به‌صورت خودکار تغییر نمی‌کنند؛ اگر reference داخل آن‌ها باقی بماند حذف old attachment متوقف می‌شود.
- پس از migration، reference audit دوباره از صفر انجام می‌شود.

## Upgrade from 10.33.8

صف اصلی Image Refresh، WebPهای دانلودشده و replacementهای انجام‌شده حفظ می‌شوند. فقط state مرحله‌های ۶ و ۷ reset می‌شود، automation متوقف می‌شود و تأیید حذف قبلی باطل می‌شود تا retained JPEGهای قبلی با منطق جدید دوباره بررسی شوند.
