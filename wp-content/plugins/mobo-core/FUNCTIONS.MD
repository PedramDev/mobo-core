# Mobo Core — Functions & Integration Reference / مرجع توابع و اتصال

<div align="center">

**Generated against Mobo Core `10.31.62` and Portal `v25 / .NET 10`**

[فارسی](#fa) · [English](#en) · [راهنمای کامل](README_FULL.MD) · [مرجع توابع](FUNCTIONS.MD) · [نمودارها](DIAGRAMS.MD)

</div>

---

<a id="fa"></a>

# مرجع فارسی

این فایل مرجع توسعه دهنده افزونه است. امضای متدهای public مستقیماً از سورس نسخه `10.31.62` استخراج شده است. متدهای `private` و `protected` جزو قرارداد پایدار افزونه نیستند و فقط تعداد آنها برای هر کلاس نمایش داده می شود.

## 1. ثابت های اصلی

| Constant | مقدار / کاربرد |
|---|---|
| `MOBO_CORE_VERSION` | `10.31.62` |
| `MOBO_CORE_PLUGIN_FILE` | فایل اصلی افزونه |
| `MOBO_CORE_PLUGIN_DIR` | مسیر filesystem افزونه |
| `MOBO_CORE_PLUGIN_URL` | URL افزونه |
| `MOBO_CORE_PURCHASE_URL` | `http://mobo.codeya.ir/` |
| `MOBO_CORE_GITHUB_URL` | `https://github.com/PedramDev/mobo-core` |
| `MOBO_API_BASE_URL` | آدرس API پیش فرض/قابل override |
| `MOBO_CORE_DATA_DIR` | `wp-content/uploads/mobo-core/` |
| `MOBO_CORE_WEBHOOK_FILE_DIR` | مسیر fallback فایل های وب هوک |

## 2. REST routeهای وردپرس

Base: `/wp-json/mobo-core/v1`

| Method | Route | Permission callback | پارامتر مهم |
|---|---|---|---|
| POST | `/categories/ensure-sync` | `check_security` | `syncId`, `force` |
| GET/POST | `/cron/run` | `check_cron_security` | `token` |
| GET | `/cron/status` | `check_security` | `X-SEC` |
| GET/POST | `/worker/run` | `check_cron_security` | `token`, `source` |
| GET | `/worker/status` | `check_security` | `X-SEC` |
| GET | `/health` | `check_security` | `X-SEC` |
| GET/POST | `/health/report-now` | `check_security` | `X-SEC` |
| POST | `/webhook` | `check_security` | JSON event payload |
| POST | `/webhook/run` | `check_security` | `X-SEC` |
| POST | `/sync/start` | `check_security` | JSON: `syncId` اختیاری |
| POST | `/sync/run` | `check_security` | بدون body اجباری |
| GET | `/sync/status` | `check_security` | `X-SEC` |
| POST | `/sync/cancel` | `check_security` | `X-SEC` |

`check_security` مقدار option `mobo_core_security_code` را با هدر `X-SEC` مقایسه می کند. `check_cron_security` مقدار `mobo_core_cron_token` را از query/body `token` می خواند و `X-SEC` را به عنوان fallback می پذیرد.

## 3. Endpointهای خروجی به Portal

| Method | Path | Header |
|---|---|---|
| GET | `/get-categories?SyncId=...` | `Token`, در صورت وجود `X-SEC` |
| GET | `/get-products?...&UseCursor=true&Cursor=...` | `Token`, `X-SEC` |
| GET | `/get-products-by-guid?ProductId=...&SyncId=...` | `Token`, `X-SEC` |
| GET | `/{productGuid}/get-variants?...` | `Token`, `X-SEC` |
| GET | `/get-address-mapping?force=true|false` | `Token`, `X-SEC` |
| GET | `/get-mobo-shipping-methods?force=true|false` | `Token`, `X-SEC` |
| GET | `/LicenseInfo` | `Token`, `X-SEC` |
| GET | `changesUrl` از Lightweight webhook | `Token`, `X-SEC` |
| POST | `/api/site-health/report` | `X-SEC` |

## 4. رویدادهای وب هوک

| رویداد | مقدار عددی legacy | Handler |
|---|---:|---|
| `ProductUpdated` | `0` و alias قدیمی `2` | `process_product_updated_payload()` |
| `UpdateVariant` | `1` و مقدار فعلی `4` | `process_update_variant_payload()` |
| `ShippingMethodsChanged` | `20` | refresh روش ارسال |
| `WebhookDeliveryStatusChanged` | `21` | ذخیره delivery status |

## 5. Filterهای توسعه دهنده

| Filter | توضیح فارسی | English purpose |
|---|---|---|
| `mobo_core_allow_unsafe_local_image_download` | نقطه توسعه افزونه برای override یا مشاهده داده در runtime. | Extension point for runtime override or observation. |
| `mobo_core_api_base_url` | نقطه توسعه افزونه برای override یا مشاهده داده در runtime. | Extension point for runtime override or observation. |
| `mobo_core_calculated_price_pair` | نقطه توسعه افزونه برای override یا مشاهده داده در runtime. | Extension point for runtime override or observation. |
| `mobo_core_checkout_validation_errors` | نقطه توسعه افزونه برای override یا مشاهده داده در runtime. | Extension point for runtime override or observation. |
| `mobo_core_checkout_validation_external_response` | نقطه توسعه افزونه برای override یا مشاهده داده در runtime. | Extension point for runtime override or observation. |
| `mobo_core_checkout_validation_external_url` | نقطه توسعه افزونه برای override یا مشاهده داده در runtime. | Extension point for runtime override or observation. |
| `mobo_core_checkout_validation_payload` | نقطه توسعه افزونه برای override یا مشاهده داده در runtime. | Extension point for runtime override or observation. |
| `mobo_core_http_sslverify` | نقطه توسعه افزونه برای override یا مشاهده داده در runtime. | Extension point for runtime override or observation. |
| `mobo_core_legacy_cron_hooks` | نقطه توسعه افزونه برای override یا مشاهده داده در runtime. | Extension point for runtime override or observation. |
| `mobo_core_local_city_candidates` | نقطه توسعه افزونه برای override یا مشاهده داده در runtime. | Extension point for runtime override or observation. |

مهم ترین filterها:

- `mobo_core_api_base_url`: تعیین آدرس API با اولویت بالاتر از option.
- `mobo_core_http_sslverify`: کنترل certificate verification درخواست خروجی؛ خاموش کردن آن برای production توصیه نمی شود.
- `mobo_core_allow_unsafe_local_image_download`: opt-in توسعه دهنده برای تست URLهای local/private تصویر.
- `mobo_core_calculated_price_pair`: تغییر خروجی `regular_price` و `sale_price`.
- `mobo_core_checkout_validation_payload`: تغییر payload اعتبارسنجی خارجی.
- `mobo_core_checkout_validation_errors`: افزودن یا تغییر خطاهای Checkout.
- `mobo_core_local_city_candidates`: توسعه لیست شهرهای محلی برای mapping.
- `mobo_core_legacy_cron_hooks`: توسعه فهرست hookهای قدیمی پاک سازی.

## 6. Actionها و Hookهای مهم

Hookهای اصلی WordPress/WooCommerce که افزونه به آنها متصل می شود:

- `add_meta_boxes`
- `admin_enqueue_scripts`
- `admin_init`
- `admin_menu`
- `admin_notices`
- `admin_post_mobo_core_cancel_recategorize`
- `admin_post_mobo_core_cancel_reprice`
- `admin_post_mobo_core_cancel_sync`
- `admin_post_mobo_core_clear_mobo_order_log`
- `admin_post_mobo_core_delete_orphan_images`
- `admin_post_mobo_core_enqueue_image_refresh`
- `admin_post_mobo_core_process_image_refresh`
- `admin_post_mobo_core_quarantine_duplicate_products`
- `admin_post_mobo_core_reset_image_refresh`
- `admin_post_mobo_core_reset_orphan_images`
- `admin_post_mobo_core_reset_recategorize`
- `admin_post_mobo_core_reset_reprice`
- `admin_post_mobo_core_reset_sync`
- `admin_post_mobo_core_resume_sync`
- `admin_post_mobo_core_retry_failed_webhooks`
- `admin_post_mobo_core_retry_image_refresh`
- `admin_post_mobo_core_retry_mobo_order_submission`
- `admin_post_mobo_core_save_settings`
- `admin_post_mobo_core_scan_legacy_images`
- `admin_post_mobo_core_scan_orphan_images`
- `admin_post_mobo_core_start_recategorize`
- `admin_post_mobo_core_start_repair`
- `admin_post_mobo_core_start_reprice`
- `admin_post_mobo_core_start_sync`
- `admin_post_mobo_core_sync_categories`
- `admin_post_mobo_core_tool_clear_mobo_debug_log`
- `admin_post_mobo_core_tool_clear_shipping_diagnostics`
- `admin_post_mobo_core_tool_run_cron_now`
- `admin_post_mobo_core_tool_sync_address_mapping`
- `admin_post_mobo_core_tool_sync_remote_shipping_methods`
- `admin_post_mobo_core_tool_test_mobo_login`
- `before_woocommerce_init`
- `manage_shop_order_posts_custom_column`
- `mobo_core_process_queued_mobo_orders`
- `mobo_core_queue_mobo_order_submission`
- `plugins_loaded`
- `rest_api_init`
- `woocommerce_after_checkout_validation`
- `woocommerce_check_cart_items`
- `woocommerce_checkout_create_order`
- `woocommerce_checkout_order_processed`
- `woocommerce_checkout_update_order_review`
- `woocommerce_order_status_changed`
- `woocommerce_save_product_variation`
- `woocommerce_shop_order_list_table_custom_column`
- `woocommerce_store_api_checkout_order_processed`
- `woocommerce_store_api_checkout_update_order_from_request`
- `woocommerce_variation_options_pricing`
- `wp_ajax_mobo_core_get_recategorize_status`
- `wp_ajax_mobo_core_get_reprice_status`
- `wp_ajax_mobo_core_get_sync_status`

## 7. جدول های داخلی

| جدول | کلاس مالک | نقش |
|---|---|---|
| `wp_mobo_product_map` | `Mobo_Core_Product_Map` | نگاشت GUID، hash و وضعیت ناقص Sync |
| `wp_mobo_category_map` | `Mobo_Core_Category_Map` | synced/manual category mapping |
| `wp_mobo_sync_events` | `Mobo_Core_Sync_Event_Store` | صف persistent وب هوک |
| `wp_mobo_image_queue` | `Mobo_Core_Image_Queue` | صف import تصویر |
| `wp_mobo_image_refresh_queue` | `Mobo_Core_Image_Refresh_Queue` | صف نوسازی WebP |
| `wp_mobo_orphan_image_files` | `Mobo_Core_Orphan_Image_Cleanup` | فایل های کاندید پاک سازی |

## 8. Optionهای پیش فرض

<details>
<summary>نمایش تمام optionهای تعریف شده در Mobo_Core_Settings::defaults()</summary>

| Option | Default |
|---|---|
| `mobo_core_security_code` | `''` |
| `mobo_core_api_base_url` | `''` |
| `mobo_core_only_in_stock` | `'0'` |
| `global_product_auto_stock` | `'1'` |
| `global_product_auto_price` | `'1'` |
| `global_product_auto_title` | `'1'` |
| `global_product_auto_compare_price` | `'1'` |
| `global_product_auto_slug` | `'1'` |
| `global_update_categories` | `'1'` |
| `global_update_images` | `'1'` |
| `mobo_core_category_mapping_enabled` | `'1'` |
| `mobo_core_category_mapping_required` | `'0'` |
| `mobo_default_category_id` | `'0'` |
| `mobo_price_type` | `'static-price'` |
| `global_additional_price` | `'0'` |
| `global_additional_percentage` | `'0'` |
| `mobo_dynamic_price` | `'[]'` |
| `mobo_core_sync_time_budget_seconds` | `8` |
| `mobo_core_webhook_files_per_run` | `4` |
| `mobo_core_webhook_max_try` | `5` |
| `mobo_core_webhook_expire_days` | `2` |
| `mobo_core_variant_parent_wait_timeout_seconds` | `600` |
| `mobo_core_pull_payload_enabled` | `'1'` |
| `mobo_core_payload_pull_timeout_seconds` | `60` |
| `mobo_core_api_request_timeout_seconds` | `60` |
| `mobo_core_transient_retry_max_try` | `10` |
| `mobo_core_waiting_for_portal_retry_delay_seconds` | `60` |
| `mobo_core_reprice_batch_size` | `20` |
| `mobo_core_products_per_page` | `1` |
| `mobo_core_product_cursor_sync_enabled` | `'1'` |
| `mobo_core_variants_per_page` | `5` |
| `mobo_core_variant_cursor_sync_enabled` | `'1'` |
| `mobo_core_images_per_run` | `3` |
| `mobo_core_image_queue_enabled` | `'1'` |
| `mobo_core_image_queue_blocking` | `'1'` |
| `mobo_core_image_max_try` | `5` |
| `mobo_core_image_retry_base_seconds` | `120` |
| `mobo_core_image_refresh_enabled` | `'0'` |
| `mobo_core_image_refresh_delete_old` | `'1'` |
| `mobo_core_image_refresh_per_run` | `2` |
| `mobo_core_image_refresh_scan_limit` | `500` |
| `mobo_core_image_refresh_max_try` | `5` |
| `mobo_core_image_refresh_retry_base_seconds` | `120` |
| `mobo_core_orphan_image_cleanup_enabled` | `'1'` |
| `mobo_core_orphan_image_scan_limit` | `500` |
| `mobo_core_orphan_image_delete_per_run` | `20` |
| `mobo_core_missing_variants_behavior` | `'outofstock'` |
| `mobo_core_excluded_product_urls` | `''` |
| `mobo_core_categories_last_sync_at` | `0` |
| `mobo_core_categories_refresh_interval_hours` | `12` |
| `mobo_core_token` | `''` |
| `mobo_core_cron_token` | `''` |
| `mobo_core_real_cron_last_hit_at` | `0` |
| `mobo_core_real_cron_last_success_at` | `0` |
| `mobo_core_real_cron_last_result` | `array()` |
| `mobo_core_real_cron_time_budget_seconds` | `25` |
| `mobo_core_real_cron_max_sync_steps` | `3` |
| `mobo_core_real_cron_lock_ttl_seconds` | `120` |
| `mobo_core_real_cron_expected_interval_seconds` | `60` |
| `mobo_core_real_cron_process_webhooks` | `'1'` |
| `mobo_core_process_webhook_on_receive` | `'0'` |
| `mobo_core_self_runner_enabled` | `'1'` |
| `mobo_core_self_runner_continue_enabled` | `'1'` |
| `mobo_core_self_runner_min_interval_seconds` | `3` |
| `mobo_core_self_runner_http_timeout_seconds` | `1` |
| `mobo_core_self_runner_last_kick_attempt_at` | `0` |
| `mobo_core_self_runner_last_kick_success_at` | `0` |
| `mobo_core_self_runner_last_kick_result` | `array()` |
| `mobo_core_self_runner_last_run_at` | `0` |
| `mobo_core_self_runner_last_run_success_at` | `0` |
| `mobo_core_self_runner_last_run_result` | `array()` |
| `mobo_core_health_report_enabled` | `'0'` |
| `mobo_core_health_report_url` | `''` |
| `mobo_core_health_report_min_interval_seconds` | `300` |
| `mobo_core_health_report_timeout_seconds` | `15` |
| `mobo_core_health_last_report_attempt_at` | `0` |
| `mobo_core_health_last_report_success_at` | `0` |
| `mobo_core_health_last_report_result` | `array()` |
| `mobo_core_checkout_validation_enabled` | `'0'` |
| `mobo_core_checkout_validate_only_mobo_products` | `'1'` |
| `mobo_core_checkout_require_remote_guid` | `'1'` |
| `mobo_core_checkout_block_incomplete_sync` | `'1'` |
| `mobo_core_checkout_local_stock_check_enabled` | `'0'` |
| `mobo_core_checkout_mobo_cart_validation_enabled` | `'0'` |
| `mobo_core_checkout_mobo_debug_enabled` | `'0'` |
| `mobo_core_shipping_diagnostics_enabled` | `'0'` |
| `mobo_core_checkout_mobo_site_url` | `'https://mobomobo.ir'` |
| `mobo_core_checkout_mobo_username` | `''` |
| `mobo_core_checkout_mobo_password` | `''` |
| `mobo_core_checkout_mobo_timeout_seconds` | `8` |
| `mobo_core_checkout_mobo_cart_lock_wait_seconds` | `15` |
| `mobo_core_checkout_mobo_cart_lock_ttl_seconds` | `60` |
| `mobo_core_checkout_mobo_cookie_jar` | `array()` |
| `mobo_core_checkout_mobo_login_success_at` | `0` |
| `mobo_core_checkout_mobo_cart_success_at` | `0` |
| `mobo_core_checkout_external_validation_enabled` | `'0'` |
| `mobo_core_checkout_external_validation_url` | `''` |
| `mobo_core_checkout_external_timeout_seconds` | `3` |
| `mobo_core_checkout_external_error_behavior` | `'allow'` |
| `mobo_core_checkout_last_validation_attempt_at` | `0` |
| `mobo_core_checkout_last_validation_success_at` | `0` |
| `mobo_core_checkout_last_validation_result` | `array()` |
| `mobo_core_mobo_order_submission_enabled` | `'0'` |
| `mobo_core_mobo_order_auto_complete_enabled` | `'1'` |
| `mobo_core_mobo_order_sender_name` | `''` |
| `mobo_core_mobo_order_sender_mobile` | `''` |
| `mobo_core_mobo_order_shipping_id` | `148395514` |
| `mobo_core_remote_shipping_sync_interval_hours` | `1` |
| `mobo_core_address_mapping_enabled` | `'0'` |
| `mobo_core_address_mapping_sync_interval_days` | `7` |
| `mobo_core_sms_notifications_enabled` | `'0'` |
| `mobo_core_sms_non_mobo_enabled` | `'0'` |
| `mobo_core_sms_non_mobo_recipients` | `''` |
| `mobo_core_sms_non_mobo_template` | `''` |
| `mobo_core_sms_mobo_only_enabled` | `'0'` |
| `mobo_core_sms_mobo_only_recipients` | `''` |
| `mobo_core_sms_mobo_only_template` | `''` |
| `mobo_core_sms_mixed_enabled` | `'0'` |
| `mobo_core_sms_mixed_recipients` | `''` |
| `mobo_core_sms_mixed_template` | `''` |

</details>

## 9. کلاس ها و متدهای public

### `Mobo_Core_Address_Mapping`

Source: [`includes/class-mobo-core-address-mapping.php`](includes/class-mobo-core-address-mapping.php)  
نگاشت کشور/استان و resolve مستقیم شناسه شهر موبو برای Checkout ووکامرس. / Maps country/state values and resolves authoritative Mobo city IDs for WooCommerce checkout.  
Public methods: **22** · Internal non-public methods: **49**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public function init()` | ثبت hookها و راه اندازی نگاشت و cache آدرس موبو برای Checkout ووکامرس. | Bootstrap hooks. |
| `public function maybe_sync_if_due($source = 'cron', $force = false)` | اجرای شرطی sync if due در بخش نگاشت و cache آدرس موبو برای Checkout ووکامرس. | Sync from MoboCore if the weekly interval is due. |
| `public function sync_now($source = 'manual', $force = true)` | همگام سازی now در بخش نگاشت و cache آدرس موبو برای Checkout ووکامرس. | Force sync from MoboCore. |
| `public function filter_checkout_fields($fields)` | اعمال فیلتر روی checkout fields در بخش نگاشت و cache آدرس موبو برای Checkout ووکامرس. | Replace WooCommerce country/state/city fields with Mobo ID selects. |
| `public function render_checkout_mapping_script()` | رندر checkout mapping script در بخش نگاشت و cache آدرس موبو برای Checkout ووکامرس. | Render dependency JS for checkout country/state/city selects. |
| `public function filter_checkout_value($value, $input)` | اعمال فیلتر روی checkout value در بخش نگاشت و cache آدرس موبو برای Checkout ووکامرس. | Legacy compatibility hook. |
| `public function normalize_checkout_review_destination($post_data)` | نرمال سازی checkout review destination در بخش نگاشت و cache آدرس موبو برای Checkout ووکامرس. | Legacy compatibility hook. |
| `public function normalize_cart_shipping_packages($packages)` | نرمال سازی cart shipping packages در بخش نگاشت و cache آدرس موبو برای Checkout ووکامرس. | Legacy compatibility hook. |
| `public function save_order_location_meta($order, $data)` | ذخیره order location meta در بخش نگاشت و cache آدرس موبو برای Checkout ووکامرس. | Save selected Mobo address IDs and labels on the WooCommerce order. |
| `public function save_store_api_order_location_meta($order, $request)` | ذخیره نگاشت آدرس سفارش در Checkout Block و Store API. | Persist Mobo location metadata for Checkout Block / Store API orders. |
| `public function save_store_api_processed_order_location_meta($order)` | اجرای fallback نهایی نگاشت آدرس پس از پردازش سفارش Store API. | Final Store API fallback after WooCommerce persists the order address. |
| `public function get_status()` | دریافت status در بخش نگاشت و cache آدرس موبو برای Checkout ووکامرس. | Get status for admin UI. |
| `public function get_cached_mapping()` | دریافت cached mapping در بخش نگاشت و cache آدرس موبو برای Checkout ووکامرس. | Public access to the cached Mobo location payload. |
| `public function get_manual_mapping()` | دریافت manual mapping در بخش نگاشت و cache آدرس موبو برای Checkout ووکامرس. | Get admin-approved manual mapping from local WooCommerce values to Mobo IDs. |
| `public function save_manual_mapping_from_post($post)` | ذخیره manual mapping from post در بخش نگاشت و cache آدرس موبو برای Checkout ووکامرس. | Save manual address mapping from the checkout settings form. |
| `public function get_local_location_candidates()` | دریافت local location candidates در بخش نگاشت و cache آدرس موبو برای Checkout ووکامرس. | Get local WooCommerce/Persian-WooCommerce address candidates for admin UI. |
| `public function get_manual_mapping_status()` | دریافت manual mapping status در بخش نگاشت و cache آدرس موبو برای Checkout ووکامرس. | Get manual mapping status counts for the admin UI. |
| `public function resolve_order_group($order, $group = 'billing')` | حل و تعیین order group در بخش نگاشت و cache آدرس موبو برای Checkout ووکامرس. | Resolve a WC order group to Mobo IDs without relying on checkout field ownership. |
| `public function resolve_state_to_mobo_id($country, $state)` | حل و تعیین state to mobo id در بخش نگاشت و cache آدرس موبو برای Checkout ووکامرس. | Resolve local country/state values to a Mobo state ID using the approved manual mapping. |
| `public function is_mobo_state_tehran($mobo_state_id)` | بررسی وضعیت mobo state tehran در بخش نگاشت و cache آدرس موبو برای Checkout ووکامرس. | Check whether a resolved Mobo state ID is Tehran using the cached Mobo mapping data. |
| `public function resolve_values_to_mobo_location($country, $state, $city)` | حل و تعیین values to mobo location در بخش نگاشت و cache آدرس موبو برای Checkout ووکامرس. | Resolve country/state through the approved map and accept or match the authoritative Mobo city ID. |
| `public function write_order_group_location_meta($order, $group, $resolved)` | ذخیره شناسه های آدرس resolve شده روی سفارش برای retry و preflight. | Persist a resolved location on an existing order during retry/preflight. |

### `Mobo_Core_City_Assets`

Source: [`includes/class-mobo-core-city-assets.php`](includes/class-mobo-core-city-assets.php)  
تولید و جایگزینی فایل شهرهای سازگار با ووکامرس فارسی از داده واقعی موبو. / Generates and serves Persian WooCommerce-compatible city assets from authoritative Mobo data.  
Public methods: **9** · Internal non-public methods: **21**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public function init()` | ثبت filter و hookهای جایگزینی فایل شهرهای ووکامرس فارسی. | Register city-asset generation and replacement hooks. |
| `public function maybe_generate_from_cache()` | در صورت تغییر cache یا حذف فایل، ساخت مجدد از داده ذخیره‌شده موبو. | Regenerate when the cache changes or generated files are missing. |
| `public function generate($mapping, $manual = array(), $source = 'manual')` | ساخت اتمیک `iran_cities.js` و `iran_cities.min.js` با `city_id` واقعی موبو. | Atomically generate readable and minified city scripts with real Mobo city IDs. |
| `public function get_status()` | دریافت وضعیت فایل‌ها، تعداد شهرها، hash و آخرین خطا. | Return generated asset status and diagnostics. |
| `public function is_ready()` | بررسی آماده بودن فایل معتبر برای Checkout. | Check whether a valid generated city asset is ready. |
| `public function filter_persian_woo_iran_cities_url($url)` | جایگزینی URL از طریق filter رسمی ووکامرس فارسی. | Replace the city source through Persian WooCommerce's filter. |
| `public function replace_persian_woocommerce_city_script()` | تغییر URL و نسخه handle موجود بدون حذف metadata و ترتیب اجرای ووکامرس فارسی. | Mutate the existing handle source/version without destroying metadata or execution order. |
| `public function force_synchronous_city_script($tag, $handle, $src = '')` | حذف async/defer از فایل provider شهر برای جلوگیری از race در Checkout. | Keep the city provider synchronous to prevent checkout race conditions. |
| `public function render_admin_notice()` | نمایش هشدار دفاعی در صورت فعال بودن خرید خودکار و آماده نبودن فایل شهرها. | Show an admin warning when automatic submission is enabled without valid city assets. |

### `Mobo_Core_Admin`

Source: [`includes/class-mobo-core-admin.php`](includes/class-mobo-core-admin.php)  
پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. / Persian administration UI and all plugin admin actions.  
Public methods: **31** · Internal non-public methods: **94**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public function init()` | ثبت hookها و راه اندازی پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Bootstrap admin hooks. |
| `public function register_menu()` | اجرای عملیات `register_menu` در بخش پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Register admin menu. |
| `public function enqueue_admin_assets($hook)` | اجرای عملیات `enqueue_admin_assets` در بخش پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Enqueue admin assets for the Mobo settings page. |
| `public function render()` | اجرای عملیات `render` در بخش پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Render admin page. |
| `public function handle_admin_tool_action()` | مدیریت رویداد یا درخواست admin tool action در بخش پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Handle separated admin/support tool actions without saving tab settings. |
| `public function handle_save_settings()` | مدیریت رویداد یا درخواست save settings در بخش پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Handle save settings. |
| `public function handle_ajax_sync_status()` | مدیریت رویداد یا درخواست ajax sync status در بخش پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Return current sync status for admin auto-refresh. |
| `public function handle_ajax_reprice_status()` | مدیریت رویداد یا درخواست ajax reprice status در بخش پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Return current repricing status for AJAX polling on the pricing tab. |
| `public function handle_ajax_recategorize_status()` | مدیریت رویداد یا درخواست ajax recategorize status در بخش پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Return current recategorize status for AJAX polling on the categories tab. |
| `public function handle_sync_categories()` | مدیریت رویداد یا درخواست sync categories در بخش پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Sync categories before product sync so the customer can complete mapping. |
| `public function handle_start_sync()` | مدیریت رویداد یا درخواست start sync در بخش پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Handle start sync. |
| `public function handle_quarantine_duplicate_products()` | مدیریت رویداد یا درخواست quarantine duplicate products در بخش پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Move duplicate products to draft without deleting data. |
| `public function handle_start_repair()` | مدیریت رویداد یا درخواست start repair در بخش پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Handle start repair sync. |
| `public function handle_resume_sync()` | مدیریت رویداد یا درخواست resume sync در بخش پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Resume a waiting manual sync without resetting cursor/page state. |
| `public function handle_cancel_sync()` | مدیریت رویداد یا درخواست cancel sync در بخش پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Handle cancel sync. |
| `public function handle_reset_sync()` | مدیریت رویداد یا درخواست reset sync در بخش پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Handle reset sync. |
| `public function handle_start_reprice()` | مدیریت رویداد یا درخواست start reprice در بخش پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Start repricing all synced WooCommerce products/variations. |
| `public function handle_cancel_reprice()` | مدیریت رویداد یا درخواست cancel reprice در بخش پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Cancel current repricing run. |
| `public function handle_reset_reprice()` | مدیریت رویداد یا درخواست reset reprice در بخش پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Reset repricing status. |
| `public function handle_start_recategorize()` | مدیریت رویداد یا درخواست start recategorize در بخش پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Start applying current category mapping to published synced products. |
| `public function handle_cancel_recategorize()` | مدیریت رویداد یا درخواست cancel recategorize در بخش پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Cancel current category reapply run. |
| `public function handle_reset_recategorize()` | مدیریت رویداد یا درخواست reset recategorize در بخش پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Reset category reapply state. |
| `public function handle_retry_failed_webhooks()` | مدیریت رویداد یا درخواست retry failed webhooks در بخش پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Re-queue failed table-backed webhook events and wake the worker. |
| `public function handle_scan_legacy_images()` | مدیریت رویداد یا درخواست scan legacy images در بخش پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Scan old Mobo images. |
| `public function handle_enqueue_image_refresh()` | مدیریت رویداد یا درخواست enqueue image refresh در بخش پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Enqueue legacy image refresh jobs. |
| `public function handle_process_image_refresh()` | مدیریت رویداد یا درخواست process image refresh در بخش پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Process image refresh queue. |
| `public function handle_retry_image_refresh()` | مدیریت رویداد یا درخواست retry image refresh در بخش پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Retry failed image refresh rows. |
| `public function handle_reset_image_refresh()` | مدیریت رویداد یا درخواست reset image refresh در بخش پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Reset image refresh queue. |
| `public function handle_scan_orphan_images()` | مدیریت رویداد یا درخواست scan orphan images در بخش پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Scan orphan old raster files that match final Mobo WebP filenames. |
| `public function handle_delete_orphan_images()` | مدیریت رویداد یا درخواست delete orphan images در بخش پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Delete safe orphan old raster files. |
| `public function handle_reset_orphan_images()` | مدیریت رویداد یا درخواست reset orphan images در بخش پنل مدیریت فارسی و تمام actionهای مدیریتی افزونه. | Reset orphan file list only. |

### `Mobo_Core_API_Client`

Source: [`includes/class-mobo-core-api-client.php`](includes/class-mobo-core-api-client.php)  
کلاینت درخواست های خروجی به API پرتال. / Outbound client for the Portal API.  
Public methods: **8** · Internal non-public methods: **4**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public function get_categories($sync_id)` | دریافت categories در بخش کلاینت درخواست های خروجی به API پرتال. | Get categories from API. |
| `public function get_products_page($page_number, $record_per_page, $sync_id, $cursor = 0, $use_cursor = false)` | دریافت products page در بخش کلاینت درخواست های خروجی به API پرتال. | Get products page. |
| `public function get_product_by_guid($product_guid, $sync_id = '')` | دریافت product by guid در بخش کلاینت درخواست های خروجی به API پرتال. | Get a single product payload by remote product GUID. |
| `public function get_variants_page($product_guid, $page_number, $record_per_page, $sync_id, $cursor = 0, $use_cursor = false)` | دریافت variants page در بخش کلاینت درخواست های خروجی به API پرتال. | Get variants page. |
| `public function get_event_payload($payload_url)` | دریافت event payload در بخش کلاینت درخواست های خروجی به API پرتال. | Pull a lightweight webhook payload from MoboCore. |
| `public function get_address_mapping($force = false)` | دریافت address mapping در بخش کلاینت درخواست های خروجی به API پرتال. | Get cached address mapping from MoboCore. |
| `public function get_mobo_shipping_methods($force = false)` | دریافت mobo shipping methods در بخش کلاینت درخواست های خروجی به API پرتال. | Get cached Mobo shipping methods from MoboCore. |
| `public function get_license_info()` | دریافت license info در بخش کلاینت درخواست های خروجی به API پرتال. | Get license information from MoboCore/API. |

### `Mobo_Core_Category_Map`

Source: [`includes/class-mobo-core-category-map.php`](includes/class-mobo-core-category-map.php)  
نگاشت GUID دسته راه دور به term همگام شده یا دستی. / Maps remote category GUIDs to synchronized or manually selected terms.  
Public methods: **14** · Internal non-public methods: **8**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public static function table_name()` | برگرداندن نام جدول با prefix وردپرس. | Return table name. |
| `public static function create_table()` | ایجاد یا بروزرسانی schema جدول داخلی. | Create/update table schema. |
| `public static function table_exists()` | بررسی وجود جدول داخلی در دیتابیس. | Check whether table exists. |
| `public function get_manual_term_id($guid)` | دریافت manual term id در بخش نگاشت GUID دسته راه دور به term همگام شده یا دستی. | Get manually mapped local term ID. |
| `public function get_manual_term_id_by_identifiers($identifiers)` | دریافت manual term id by identifiers در بخش نگاشت GUID دسته راه دور به term همگام شده یا دستی. | Get manually mapped local term ID using GUID identifiers only. |
| `public function get_synced_term_id($guid)` | دریافت synced term id در بخش نگاشت GUID دسته راه دور به term همگام شده یا دستی. | Get synced local term ID. |
| `public function get_synced_term_id_by_identifiers($identifiers)` | دریافت synced term id by identifiers در بخش نگاشت GUID دسته راه دور به term همگام شده یا دستی. | Get synced local term ID using GUID identifiers only. |
| `public function resolve_assignment_term($guid)` | حل و تعیین assignment term در بخش نگاشت GUID دسته راه دور به term همگام شده یا دستی. | Get best assignment term: manual first, synced fallback. |
| `public function resolve_assignment_term_by_identifiers($identifiers)` | حل و تعیین assignment term by identifiers در بخش نگاشت GUID دسته راه دور به term همگام شده یا دستی. | Get best assignment term using GUID identifiers: manual first, synced fallback. |
| `public function upsert_remote_category_for_mapping($guid, $name = '', $url = '', $parent_guid = '')` | ایجاد یا بروزرسانی remote category for mapping در بخش نگاشت GUID دسته راه دور به term همگام شده یا دستی. | Upsert remote category metadata for mapping only. |
| `public function upsert_synced_category($guid, $synced_term_id, $name = '', $url = '', $parent_guid = '')` | ایجاد یا بروزرسانی synced category در بخش نگاشت GUID دسته راه دور به term همگام شده یا دستی. | Upsert synced category metadata. |
| `public function update_manual_mapping($guid, $term_id)` | بروزرسانی manual mapping در بخش نگاشت GUID دسته راه دور به term همگام شده یا دستی. | Update manual mapping. |
| `public function list_mappings($limit = 500)` | اجرای عملیات `list_mappings` در بخش نگاشت GUID دسته راه دور به term همگام شده یا دستی. | List mappings for admin UI. |
| `public function seed_from_legacy_term_meta($limit = 500)` | اجرای عملیات `seed_from_legacy_term_meta` در بخش نگاشت GUID دسته راه دور به term همگام شده یا دستی. | Seed map from legacy term meta category_guid. |

### `Mobo_Core_Category_Sync`

Source: [`includes/class-mobo-core-category-sync.php`](includes/class-mobo-core-category-sync.php)  
ساخت، بروزرسانی و assignment دسته بندی ها. / Creates, updates, and assigns WooCommerce product categories.  
Public methods: **6** · Internal non-public methods: **12**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public function __construct()` | ساخت نمونه و آماده سازی وابستگی های ساخت، بروزرسانی و assignment دسته بندی ها. | Executes `__construct` for this component. |
| `public function load_categories_for_mapping_payload($payload)` | بارگذاری categories for mapping payload در بخش ساخت، بروزرسانی و assignment دسته بندی ها. | Load remote categories into the mapping table only. |
| `public function sync_categories_payload($payload)` | همگام سازی categories payload در بخش ساخت، بروزرسانی و assignment دسته بندی ها. | Executes `sync_categories_payload` for this component. |
| `public function assign_product_categories($product_id, $categories, $auto_categories_enabled, $is_new_product = false)` | اجرای عملیات `assign_product_categories` در بخش ساخت، بروزرسانی و assignment دسته بندی ها. | Assign product categories. |
| `public function upsert_category($category_data)` | ایجاد یا بروزرسانی category در بخش ساخت، بروزرسانی و assignment دسته بندی ها. | Executes `upsert_category` for this component. |
| `public function find_term_id_by_guid($category_guid)` | پیدا کردن term id by guid در بخش ساخت، بروزرسانی و assignment دسته بندی ها. | Executes `find_term_id_by_guid` for this component. |

### `Mobo_Core_Checkout_Validator`

Source: [`includes/class-mobo-core-checkout-validator.php`](includes/class-mobo-core-checkout-validator.php)  
اعتبارسنجی سبد، اتصال موبو و صف ثبت سفارش. / Validates carts, communicates with Mobo, and queues order submission.  
Public methods: **21** · Internal non-public methods: **91**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public function init()` | ثبت hookها و راه اندازی اعتبارسنجی سبد، اتصال موبو و صف ثبت سفارش. | Register validation hooks. |
| `public function validate_cart_notices()` | اعتبارسنجی cart notices در بخش اعتبارسنجی سبد، اتصال موبو و صف ثبت سفارش. | Validate cart and add WooCommerce notices. |
| `public function validate_checkout_errors($data, $errors)` | اعتبارسنجی checkout errors در بخش اعتبارسنجی سبد، اتصال موبو و صف ثبت سفارش. | Validate checkout and add errors to WooCommerce/WP_Error object. |
| `public function get_last_status()` | دریافت last status در بخش اعتبارسنجی سبد، اتصال موبو و صف ثبت سفارش. | Return last validation status for admin UI / health. |
| `public function test_mobo_login()` | اجرای عملیات `test_mobo_login` در بخش اعتبارسنجی سبد، اتصال موبو و صف ثبت سفارش. | Test Mobo login from admin settings. |
| `public function validate_current_cart($include_external = true)` | اعتبارسنجی current cart در بخش اعتبارسنجی سبد، اتصال موبو و صف ثبت سفارش. | Validate the active cart. |
| `public function handle_wc_cart_item_removed($cart_item_key, $cart)` | مدیریت رویداد یا درخواست wc cart item removed در بخش اعتبارسنجی سبد، اتصال موبو و صف ثبت سفارش. | Mirror WooCommerce cart item removal to the Mobo storefront cart. |
| `public function get_mobo_debug_log()` | دریافت mobo debug log در بخش اعتبارسنجی سبد، اتصال موبو و صف ثبت سفارش. | Return recent Mobo cart debug log entries for admin UI. |
| `public function clear_mobo_debug_log()` | اجرای عملیات `clear_mobo_debug_log` در بخش اعتبارسنجی سبد، اتصال موبو و صف ثبت سفارش. | Clear Mobo cart debug log. |
| `public function handle_order_status_changed($order_id, $old_status, $new_status, $order = null)` | مدیریت رویداد یا درخواست order status changed در بخش اعتبارسنجی سبد، اتصال موبو و صف ثبت سفارش. | Submit WooCommerce orders to Mobo once they enter processing. |
| `public function handle_scheduled_order_queue($order_id, $context = array())` | مدیریت رویداد یا درخواست scheduled order queue در بخش اعتبارسنجی سبد، اتصال موبو و صف ثبت سفارش. | Scheduled callback that only puts an order id into the option-backed queue. |
| `public function handle_scheduled_queued_order_submissions()` | مدیریت رویداد یا درخواست scheduled queued order submissions در بخش اعتبارسنجی سبد، اتصال موبو و صف ثبت سفارش. | WP-Cron callback. |
| `public function process_queued_mobo_order_submissions($limit = 1, $source = 'real-cron')` | پردازش queued mobo order submissions در بخش اعتبارسنجی سبد، اتصال موبو و صف ثبت سفارش. | Process queued Mobo order submissions. |
| `public function register_order_meta_box()` | اجرای عملیات `register_order_meta_box` در بخش اعتبارسنجی سبد، اتصال موبو و صف ثبت سفارش. | Executes `register_order_meta_box` for this component. |
| `public function handle_admin_retry_mobo_order_submission()` | مدیریت رویداد یا درخواست admin retry mobo order submission در بخش اعتبارسنجی سبد، اتصال موبو و صف ثبت سفارش. | Executes `handle_admin_retry_mobo_order_submission` for this component. |
| `public function handle_admin_clear_mobo_order_log()` | مدیریت رویداد یا درخواست admin clear mobo order log در بخش اعتبارسنجی سبد، اتصال موبو و صف ثبت سفارش. | Executes `handle_admin_clear_mobo_order_log` for this component. |
| `public function render_order_meta_box($object)` | رندر order meta box در بخش اعتبارسنجی سبد، اتصال موبو و صف ثبت سفارش. | Executes `render_order_meta_box` for this component. |
| `public function add_legacy_order_column($columns)` | افزودن legacy order column در بخش اعتبارسنجی سبد، اتصال موبو و صف ثبت سفارش. | Executes `add_legacy_order_column` for this component. |
| `public function render_legacy_order_column($column, $post_id)` | رندر legacy order column در بخش اعتبارسنجی سبد، اتصال موبو و صف ثبت سفارش. | Executes `render_legacy_order_column` for this component. |
| `public function add_hpos_order_column($columns)` | افزودن hpos order column در بخش اعتبارسنجی سبد، اتصال موبو و صف ثبت سفارش. | Executes `add_hpos_order_column` for this component. |
| `public function render_hpos_order_column($column, $order)` | رندر hpos order column در بخش اعتبارسنجی سبد، اتصال موبو و صف ثبت سفارش. | Executes `render_hpos_order_column` for this component. |

### `Mobo_Core_Cron_Runner`

Source: [`includes/class-mobo-core-cron-runner.php`](includes/class-mobo-core-cron-runner.php)  
اجرای محدود عملیات افزونه مستقل از WP-Cron. / Runs bounded plugin work independently from WP-Cron.  
Public methods: **3** · Internal non-public methods: **3**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public function run($source = 'real-cron')` | اجرای عملیات `run` در بخش اجرای محدود عملیات افزونه مستقل از WP-Cron. | Run one bounded cron slice. |
| `public static function build_cron_url()` | ساخت cron url در بخش اجرای محدود عملیات افزونه مستقل از WP-Cron. | Build a secure cron URL for the current site. |
| `public static function get_status()` | دریافت status در بخش اجرای محدود عملیات افزونه مستقل از WP-Cron. | Get cron status for admin UI and central health checks. |

### `Mobo_Core_Health_Reporter`

Source: [`includes/class-mobo-core-health-reporter.php`](includes/class-mobo-core-health-reporter.php)  
ساخت و ارسال گزارش سلامت سایت مشتری. / Builds and sends customer-site health reports.  
Public methods: **4** · Internal non-public methods: **25**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public function build_report()` | ساخت report در بخش ساخت و ارسال گزارش سلامت سایت مشتری. | Build a health report compatible with MoboCore WordPressSiteHealthReportDto. |
| `public function send_report($source = 'real-cron', $force = false)` | ارسال report در بخش ساخت و ارسال گزارش سلامت سایت مشتری. | Send report to MoboCore. |
| `public function get_local_status()` | دریافت local status در بخش ساخت و ارسال گزارش سلامت سایت مشتری. | Return current local health status for REST /health. |
| `public function get_last_report_status()` | دریافت last report status در بخش ساخت و ارسال گزارش سلامت سایت مشتری. | Get last report status for UI. |

### `Mobo_Core_Image_Queue`

Source: [`includes/class-mobo-core-image-queue.php`](includes/class-mobo-core-image-queue.php)  
صف دیتابیسی import تصاویر معمولی. / Database-backed queue for normal image imports.  
Public methods: **17** · Internal non-public methods: **11**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public static function table_name()` | برگرداندن نام جدول با prefix وردپرس. | Return table name. |
| `public static function create_table()` | ایجاد یا بروزرسانی schema جدول داخلی. | Create/update table schema. |
| `public static function table_exists()` | بررسی وجود جدول داخلی در دیتابیس. | Check whether queue table exists. |
| `public function enqueue_product_images($product_id, $product_guid, $images)` | اجرای عملیات `enqueue_product_images` در بخش صف دیتابیسی import تصاویر معمولی. | Add/update image queue rows for one product. |
| `public function get_due_product_images($product_id, $limit)` | دریافت due product images در بخش صف دیتابیسی import تصاویر معمولی. | Get due rows for one product. |
| `public function count_due_by_product($product_id)` | شمارش due by product در بخش صف دیتابیسی import تصاویر معمولی. | Count immediately due image rows for one product. |
| `public function get_due_images($limit)` | دریافت due images در بخش صف دیتابیسی import تصاویر معمولی. | Get due rows across all products. |
| `public function lock($id, $ttl = 90)` | اجرای عملیات `lock` در بخش صف دیتابیسی import تصاویر معمولی. | Lock image row. |
| `public function mark_done($id, $attachment_id)` | ثبت وضعیت done در بخش صف دیتابیسی import تصاویر معمولی. | Mark image as done. |
| `public function mark_failure($id, $message, $try_count, $final_failed = false)` | ثبت وضعیت failure در بخش صف دیتابیسی import تصاویر معمولی. | Mark image retry/failure. |
| `public function count_pending_by_product($product_id, $include_failed = false)` | شمارش pending by product در بخش صف دیتابیسی import تصاویر معمولی. | Count not-done images for product. |
| `public function get_done_attachment_ids_for_product($product_id)` | دریافت done attachment ids for product در بخش صف دیتابیسی import تصاویر معمولی. | Get done attachment IDs for a product ordered by source position. |
| `public function get_ordered_rows_for_product($product_id)` | دریافت ordered rows for product در بخش صف دیتابیسی import تصاویر معمولی. | Get all image queue rows for one product ordered by source position. |
| `public function count_due()` | شمارش due در بخش صف دیتابیسی import تصاویر معمولی. | Count due image rows. |
| `public function count_pending()` | شمارش pending در بخش صف دیتابیسی import تصاویر معمولی. | Count pending/processing image rows. |
| `public function count_failed()` | شمارش failed در بخش صف دیتابیسی import تصاویر معمولی. | Count failed image rows. |
| `public function get_status()` | دریافت status در بخش صف دیتابیسی import تصاویر معمولی. | Get compact status. |

### `Mobo_Core_Image_Refresh_Queue`

Source: [`includes/class-mobo-core-image-refresh-queue.php`](includes/class-mobo-core-image-refresh-queue.php)  
صف جداگانه نوسازی امن تصاویر قدیمی به WebP. / Separate queue for safe legacy-to-WebP refresh jobs.  
Public methods: **14** · Internal non-public methods: **3**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public static function table_name()` | برگرداندن نام جدول با prefix وردپرس. | Return table name. |
| `public static function create_table()` | ایجاد یا بروزرسانی schema جدول داخلی. | Create/update table schema. |
| `public static function table_exists()` | بررسی وجود جدول داخلی در دیتابیس. | Check whether table exists. |
| `public function enqueue($job)` | اجرای عملیات `enqueue` در بخش صف جداگانه نوسازی امن تصاویر قدیمی به WebP. | Add/update one refresh job. |
| `public function get_due_jobs($limit)` | دریافت due jobs در بخش صف جداگانه نوسازی امن تصاویر قدیمی به WebP. | Get due jobs. |
| `public function lock($id, $ttl = 120)` | اجرای عملیات `lock` در بخش صف جداگانه نوسازی امن تصاویر قدیمی به WebP. | Lock a job. |
| `public function mark_done($id, $new_attachment_id, $note = '')` | ثبت وضعیت done در بخش صف جداگانه نوسازی امن تصاویر قدیمی به WebP. | Mark done. |
| `public function mark_skipped($id, $message)` | ثبت وضعیت skipped در بخش صف جداگانه نوسازی امن تصاویر قدیمی به WebP. | Mark skipped. |
| `public function mark_failure($id, $message, $try_count, $final_failed = false)` | ثبت وضعیت failure در بخش صف جداگانه نوسازی امن تصاویر قدیمی به WebP. | Mark retry/failure. |
| `public function retry_failed()` | تلاش مجدد برای failed در بخش صف جداگانه نوسازی امن تصاویر قدیمی به WebP. | Reset failed/skipped rows back to pending. |
| `public function reset($only_done = false)` | اجرای عملیات `reset` در بخش صف جداگانه نوسازی امن تصاویر قدیمی به WebP. | Clear queue rows. |
| `public function count_due()` | شمارش due در بخش صف جداگانه نوسازی امن تصاویر قدیمی به WebP. | Count due jobs. |
| `public function get_status()` | دریافت status در بخش صف جداگانه نوسازی امن تصاویر قدیمی به WebP. | Get status counters. |
| `public function get_recent_rows($limit = 20)` | دریافت recent rows در بخش صف جداگانه نوسازی امن تصاویر قدیمی به WebP. | Get recent rows for admin diagnostics. |

### `Mobo_Core_Image_Refresh_Service`

Source: [`includes/class-mobo-core-image-refresh-service.php`](includes/class-mobo-core-image-refresh-service.php)  
اسکن و پردازش نوسازی تصاویر قدیمی. / Scans and processes legacy image refresh work.  
Public methods: **4** · Internal non-public methods: **21**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public function get_status()` | دریافت status در بخش اسکن و پردازش نوسازی تصاویر قدیمی. | Get combined status. |
| `public function scan_legacy_images($limit = 500)` | اجرای عملیات `scan_legacy_images` در بخش اسکن و پردازش نوسازی تصاویر قدیمی. | Scan legacy Mobo attachments without changing data. |
| `public function enqueue_legacy_images($limit = 500)` | اجرای عملیات `enqueue_legacy_images` در بخش اسکن و پردازش نوسازی تصاویر قدیمی. | Build refresh queue from legacy Mobo attachments. |
| `public function process_queue($limit = 0)` | پردازش queue در بخش اسکن و پردازش نوسازی تصاویر قدیمی. | Process bounded refresh jobs. |

### `Mobo_Core_Image_Sync`

Source: [`includes/class-mobo-core-image-sync.php`](includes/class-mobo-core-image-sync.php)  
import و اتصال تصاویر به محصولات ووکامرس. / Imports images and attaches them to WooCommerce products.  
Public methods: **4** · Internal non-public methods: **29**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public function process_images($product_id, $images, $offset, $blocking_override = null)` | پردازش images در بخش import و اتصال تصاویر به محصولات ووکامرس. | Process images for a product. |
| `public function process_queue($limit = 0)` | پردازش queue در بخش import و اتصال تصاویر به محصولات ووکامرس. | Process due image queue rows across products. |
| `public function get_queue_status()` | دریافت queue status در بخش import و اتصال تصاویر به محصولات ووکامرس. | Return compact queue status. |
| `public function import_image_for_refresh($url, $product_id, $image_guid, $old_attachment_id = 0)` | اجرای عملیات `import_image_for_refresh` در بخش import و اتصال تصاویر به محصولات ووکامرس. | Import a replacement image for the legacy-image refresh queue. |

### `Mobo_Core_Legacy_Rules`

Source: [`includes/class-mobo-core-legacy-rules.php`](includes/class-mobo-core-legacy-rules.php)  
دسترسی متمرکز به قواعد سازگاری قدیمی. / Central access to legacy behavior and compatibility rules.  
Public methods: **13** · Internal non-public methods: **2**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public function get_options()` | دریافت options در بخش دسترسی متمرکز به قواعد سازگاری قدیمی. | Get all legacy/global product options. |
| `public function refresh()` | اجرای عملیات `refresh` در بخش دسترسی متمرکز به قواعد سازگاری قدیمی. | Clear cached options. |
| `public function should_update_stock()` | تعیین اینکه آیا update stock در بخش دسترسی متمرکز به قواعد سازگاری قدیمی. | Should update stock? |
| `public function should_update_price()` | تعیین اینکه آیا update price در بخش دسترسی متمرکز به قواعد سازگاری قدیمی. | Should update price? |
| `public function should_update_title()` | تعیین اینکه آیا update title در بخش دسترسی متمرکز به قواعد سازگاری قدیمی. | Should update title? |
| `public function should_update_compare_price()` | تعیین اینکه آیا update compare price در بخش دسترسی متمرکز به قواعد سازگاری قدیمی. | Should update compare price? |
| `public function should_update_slug()` | تعیین اینکه آیا update slug در بخش دسترسی متمرکز به قواعد سازگاری قدیمی. | Should update slug? |
| `public function should_update_categories()` | تعیین اینکه آیا update categories در بخش دسترسی متمرکز به قواعد سازگاری قدیمی. | Should update categories automatically? |
| `public function should_update_images()` | تعیین اینکه آیا update images در بخش دسترسی متمرکز به قواعد سازگاری قدیمی. | Should update images automatically? |
| `public function only_in_stock()` | اجرای عملیات `only_in_stock` در بخش دسترسی متمرکز به قواعد سازگاری قدیمی. | Should only fetch in-stock products? |
| `public function price_type()` | اجرای عملیات `price_type` در بخش دسترسی متمرکز به قواعد سازگاری قدیمی. | Get price type. |
| `public function default_category_id()` | اجرای عملیات `default_category_id` در بخش دسترسی متمرکز به قواعد سازگاری قدیمی. | Get default category ID. |
| `public function missing_variants_behavior()` | اجرای عملیات `missing_variants_behavior` در بخش دسترسی متمرکز به قواعد سازگاری قدیمی. | Missing variants behavior. |

### `Mobo_Core_Lock`

Source: [`includes/class-mobo-core-lock.php`](includes/class-mobo-core-lock.php)  
قفل runtime مبتنی بر transient. / Transient-based runtime lock helper.  
Public methods: **3** · Internal non-public methods: **1**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public static function acquire($name, $ttl = 30)` | اجرای عملیات `acquire` در بخش قفل runtime مبتنی بر transient. | Acquire a named lock. |
| `public static function release($name, $token)` | اجرای عملیات `release` در بخش قفل runtime مبتنی بر transient. | Release a named lock. |
| `public static function force_release($name)` | اجرای عملیات `force_release` در بخش قفل runtime مبتنی بر transient. | Force delete a lock. |

### `Mobo_Core_Dependencies`

Source: [`includes/class-mobo-core-dependencies.php`](includes/class-mobo-core-dependencies.php)  
کنترل پیش نیازهای اجباری افزونه و توقف امن activation/bootstrap. / Enforces required plugin dependencies and safely blocks activation/bootstrap.  
Public methods: **6** · Internal non-public methods: **5**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public static function get_missing_dependencies()` | دریافت فهرست WooCommerce یا ووکامرس فارسی نصب نشده/غیرفعال. | Return missing required plugin dependencies. |
| `public static function requirements_met()` | بررسی کامل بودن همه پیش نیازهای افزونه. | Whether all required dependencies are active. |
| `public static function is_woocommerce_active()` | بررسی فعال بودن WooCommerce. | Whether WooCommerce is active. |
| `public static function is_persian_woocommerce_active()` | بررسی فعال بودن افزونه رسمی `persian-woocommerce`. | Whether Persian WooCommerce is active. |
| `public static function enforce_activation_requirements()` | توقف activation و نمایش خطای واضح در وردپرس های قدیمی. | Block activation when a dependency is missing. |
| `public static function register_admin_notices($missing = null)` | ثبت اعلان مدیریتی پایدار بعد از غیرفعال شدن dependency. | Register persistent missing-dependency notices. |

### `Mobo_Core_Logger`

Source: [`includes/class-mobo-core-logger.php`](includes/class-mobo-core-logger.php)  
ثبت log ساختاریافته از طریق WooCommerce. / Structured logging through the WooCommerce logger.  
Public methods: **2** · Internal non-public methods: **0**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public static function error($message, $context = array())` | اجرای عملیات `error` در بخش ثبت log ساختاریافته از طریق WooCommerce. | Write an error message to the WooCommerce log when available. |
| `public static function warning($message, $context = array())` | اجرای عملیات `warning` در بخش ثبت log ساختاریافته از طریق WooCommerce. | Write a warning message to the WooCommerce log when available. |

### `Mobo_Core_Maintenance`

Source: [`includes/class-mobo-core-maintenance.php`](includes/class-mobo-core-maintenance.php)  
پاک سازی محدود و دوره ای داده های داخلی. / Bounded periodic cleanup of internal data.  
Public methods: **2** · Internal non-public methods: **7**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public static function maybe_run($source = 'real-cron')` | اجرای شرطی run در بخش پاک سازی محدود و دوره ای داده های داخلی. | Run maintenance when due. |
| `public static function mobo_cron_hooks()` | اجرای عملیات `mobo_cron_hooks` در بخش پاک سازی محدود و دوره ای داده های داخلی. | Mobo-related WP-Cron hooks that should not stay in wp_options:cron. |

### `Mobo_Core_Migration`

Source: [`includes/class-mobo-core-migration.php`](includes/class-mobo-core-migration.php)  
Migration گزینه ها، جدول ها و داده های legacy. / Migrates options, tables, and legacy data.  
Public methods: **2** · Internal non-public methods: **13**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public static function activate()` | اجرای عملیات `activate` در بخش Migration گزینه ها، جدول ها و داده های legacy. | Activation hook. |
| `public static function maybe_run()` | اجرای شرطی run در بخش Migration گزینه ها، جدول ها و داده های legacy. | Run lightweight migrations if version changed. |

### `Mobo_Core_Orphan_Image_Cleanup`

Source: [`includes/class-mobo-core-orphan-image-cleanup.php`](includes/class-mobo-core-orphan-image-cleanup.php)  
شناسایی و حذف محافظه کارانه فایل های تصویر orphan. / Conservatively identifies and removes orphan image files.  
Public methods: **8** · Internal non-public methods: **15**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public static function table_name()` | برگرداندن نام جدول با prefix وردپرس. | Return table name. |
| `public static function create_table()` | ایجاد یا بروزرسانی schema جدول داخلی. | Create/update table schema. |
| `public static function table_exists()` | بررسی وجود جدول داخلی در دیتابیس. | Check whether table exists. |
| `public function scan($limit = 500)` | اجرای عملیات `scan` در بخش شناسایی و حذف محافظه کارانه فایل های تصویر orphan. | Scan uploads for old raster files matching final Mobo WebP filenames. |
| `public function delete_candidates($limit = 20)` | حذف candidates در بخش شناسایی و حذف محافظه کارانه فایل های تصویر orphan. | Delete bounded candidate rows after re-validating them. |
| `public function reset($only_rescanable = false)` | اجرای عملیات `reset` در بخش شناسایی و حذف محافظه کارانه فایل های تصویر orphan. | Reset table rows. |
| `public function get_status()` | دریافت status در بخش شناسایی و حذف محافظه کارانه فایل های تصویر orphan. | Get summary status. |
| `public function get_recent_rows($limit = 20)` | دریافت recent rows در بخش شناسایی و حذف محافظه کارانه فایل های تصویر orphan. | Get recent rows. |

### `Mobo_Core_Price_Calculator`

Source: [`includes/class-mobo-core-price-calculator.php`](includes/class-mobo-core-price-calculator.php)  
محاسبه regular/sale price بر اساس قواعد موبو. / Calculates regular and sale prices from Mobo rules.  
Public methods: **2** · Internal non-public methods: **5**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public function __construct(Mobo_Core_Legacy_Rules $rules)` | ساخت نمونه و آماده سازی وابستگی های محاسبه regular/sale price بر اساس قواعد موبو. | Constructor. |
| `public function calculate_price_pair($object_id, $price, $compare_price, $context)` | محاسبه price pair در بخش محاسبه regular/sale price بر اساس قواعد موبو. | Calculate regular and sale prices for a product or variation. |

### `Mobo_Core_Product_Concurrency`

Source: [`includes/class-mobo-core-product-concurrency.php`](includes/class-mobo-core-product-concurrency.php)  
lock سطح محصول و مدیریت GUID تکراری. / Product-level locks and duplicate GUID handling.  
Public methods: **10** · Internal non-public methods: **2**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public static function acquire_product_lock($product_guid, $wait_seconds = 5, $ttl_seconds = 180)` | دریافت lock برای product lock در بخش lock سطح محصول و مدیریت GUID تکراری. | Acquire a product GUID lock. |
| `public static function release_product_lock($lock)` | آزاد کردن lock برای product lock در بخش lock سطح محصول و مدیریت GUID تکراری. | Release a product GUID lock. |
| `public static function is_manual_sync_busy_for_product($product_guid)` | بررسی وضعیت manual sync busy for product در بخش lock سطح محصول و مدیریت GUID تکراری. | Check if manual/repair sync is currently working on this product GUID. |
| `public static function defer_result($product_guid, $reason, $seconds = 30)` | اجرای عملیات `defer_result` در بخش lock سطح محصول و مدیریت GUID تکراری. | Build a standard defer result for webhook processing. |
| `public static function get_product_ids_by_guid($product_guid)` | دریافت product ids by guid در بخش lock سطح محصول و مدیریت GUID تکراری. | Return active product IDs that share one product_guid. |
| `public static function get_canonical_product_id($product_guid, $preferred_id = 0)` | دریافت canonical product id در بخش lock سطح محصول و مدیریت GUID تکراری. | Choose canonical product ID for a GUID and repair the map table if needed. |
| `public static function is_non_canonical_product($post_id)` | بررسی وضعیت non canonical product در بخش lock سطح محصول و مدیریت GUID تکراری. | Check whether a product is an active duplicate but not canonical. |
| `public static function count_duplicate_product_groups()` | شمارش duplicate product groups در بخش lock سطح محصول و مدیریت GUID تکراری. | Count product_guid groups with more than one active product. |
| `public static function get_duplicate_product_groups($limit = 10)` | دریافت duplicate product groups در بخش lock سطح محصول و مدیریت GUID تکراری. | Return a few duplicate groups for admin diagnostics. |
| `public static function quarantine_duplicate_products($limit_groups = 50)` | اجرای عملیات `quarantine_duplicate_products` در بخش lock سطح محصول و مدیریت GUID تکراری. | Move non-canonical duplicate products to draft so they stop showing in shop. |

### `Mobo_Core_Product_Map`

Source: [`includes/class-mobo-core-product-map.php`](includes/class-mobo-core-product-map.php)  
نگاشت سریع GUID محصول و variation به post ID. / Fast GUID-to-post mapping for products and variations.  
Public methods: **11** · Internal non-public methods: **5**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public static function table_name()` | برگرداندن نام جدول با prefix وردپرس. | Return table name. |
| `public static function create_table()` | ایجاد یا بروزرسانی schema جدول داخلی. | Create/update table schema. |
| `public static function table_exists()` | بررسی وجود جدول داخلی در دیتابیس. | Check whether table exists. |
| `public function get_product_id($guid)` | دریافت product id در بخش نگاشت سریع GUID محصول و variation به post ID. | Get product ID by remote product GUID. |
| `public function get_variation_id($guid)` | دریافت variation id در بخش نگاشت سریع GUID محصول و variation به post ID. | Get variation ID by remote variant GUID. |
| `public function get_product_post_status($guid)` | دریافت product post status در بخش نگاشت سریع GUID محصول و variation به post ID. | Get product post status by remote product GUID. |
| `public function get_variation_post_status($guid)` | دریافت variation post status در بخش نگاشت سریع GUID محصول و variation به post ID. | Get variation post status by remote variant GUID. |
| `public function upsert_product($guid, $post_id, $last_hash = '', $sync_incomplete = false)` | ایجاد یا بروزرسانی product در بخش نگاشت سریع GUID محصول و variation به post ID. | Upsert product mapping. |
| `public function upsert_variation($guid, $post_id, $parent_guid = '', $last_hash = '', $sync_incomplete = false)` | ایجاد یا بروزرسانی variation در بخش نگاشت سریع GUID محصول و variation به post ID. | Upsert variation mapping. |
| `public function upsert($guid, $post_id, $object_type, $parent_guid = '', $last_hash = '', $sync_incomplete = false)` | اجرای عملیات `upsert` در بخش نگاشت سریع GUID محصول و variation به post ID. | Upsert a map row. |
| `public function seed_from_legacy_meta($limit = 500)` | اجرای عملیات `seed_from_legacy_meta` در بخش نگاشت سریع GUID محصول و variation به post ID. | Incrementally seed product/variation map from legacy post meta. |

### `Mobo_Core_Sync_Settings_Guard`

Source: [`includes/class-mobo-core-sync-settings-guard.php`](includes/class-mobo-core-sync-settings-guard.php)  
قفل تنظیمات صف و pagination در زمان Sync و Repair. / Locks queue and pagination settings during active Sync and Repair runs.  
Public methods: **6** · Internal non-public methods: **1**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public function init()` | ثبت فیلترهای محافظ و اعلان مدیریتی. | Register option guards and the fallback admin notice. |
| `public static function protected_options()` | دریافت فهرست تنظیمات قفل شونده. | Return queue/processing option names protected during a run. |
| `public static function get_lock_state()` | دریافت وضعیت و نوع عملیات فعال. | Return active Sync/Repair lock details. |
| `public static function is_locked()` | بررسی فعال بودن قفل تنظیمات. | Check whether queue settings are locked. |
| `public function guard_option_update($new_value, $old_value, $option_name)` | جلوگیری از تغییر option در اجرای فعال. | Preserve the old value during active runs. |
| `public function render_admin_notice()` | نمایش هشدار برای تلاش تغییر مستقیم. | Render a warning after blocked direct updates. |

### `Mobo_Core_Product_Sync`

Source: [`includes/class-mobo-core-product-sync.php`](includes/class-mobo-core-product-sync.php)  
هسته Sync کامل، Repair و پردازش payload تغییرات. / Core full sync, Repair, and incremental payload processor.  
Public methods: **13** · Internal non-public methods: **79**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public function __construct()` | ساخت نمونه و آماده سازی وابستگی های هسته Sync کامل، Repair و پردازش payload تغییرات. | Executes `__construct` for this component. |
| `public function start_manual_sync($sync_id = '', $source = 'admin', $repair_mode = false)` | شروع manual sync در بخش هسته Sync کامل، Repair و پردازش payload تغییرات. | Executes `start_manual_sync` for this component. |
| `public function cancel_manual_sync()` | لغو manual sync در بخش هسته Sync کامل، Repair و پردازش payload تغییرات. | Executes `cancel_manual_sync` for this component. |
| `public function reset_manual_sync_state()` | بازنشانی manual sync state در بخش هسته Sync کامل، Repair و پردازش payload تغییرات. | Executes `reset_manual_sync_state` for this component. |
| `public function get_manual_sync_state()` | دریافت manual sync state در بخش هسته Sync کامل، Repair و پردازش payload تغییرات. | Executes `get_manual_sync_state` for this component. |
| `public function get_manual_sync_status()` | دریافت manual sync status در بخش هسته Sync کامل، Repair و پردازش payload تغییرات. | Executes `get_manual_sync_status` for this component. |
| `public function run_manual_sync_step()` | اجرای عملیات `run_manual_sync_step` در بخش هسته Sync کامل، Repair و پردازش payload تغییرات. | Executes `run_manual_sync_step` for this component. |
| `public static function get_repair_completed_at()` | دریافت repair completed at در بخش هسته Sync کامل، Repair و پردازش payload تغییرات. | Get repair completion timestamp. |
| `public static function is_repair_completed()` | بررسی وضعیت repair completed در بخش هسته Sync کامل، Repair و پردازش payload تغییرات. | Whether the required repair pass has completed. |
| `public function process_product_updated_payload(&$payload)` | پردازش product updated payload در بخش هسته Sync کامل، Repair و پردازش payload تغییرات. | Executes `process_product_updated_payload` for this component. |
| `public function process_update_variant_payload($payload, $from_manual_sync = false)` | پردازش update variant payload در بخش هسته Sync کامل، Repair و پردازش payload تغییرات. | Executes `process_update_variant_payload` for this component. |
| `public function preload_categories_for_mapping($sync_id = '')` | پیش بارگذاری categories for mapping در بخش هسته Sync کامل، Repair و پردازش payload تغییرات. | Fetch remote categories only for admin mapping. |
| `public function ensure_categories_synced_if_due($sync_id = '', $force = false)` | اطمینان از categories synced if due در بخش هسته Sync کامل، Repair و پردازش payload تغییرات. | Ensure categories are synced if refresh interval has passed. |

### `Mobo_Core_Recategorize_Queue`

Source: [`includes/class-mobo-core-recategorize-queue.php`](includes/class-mobo-core-recategorize-queue.php)  
اعمال مجدد mapping دسته روی محصولات منتشر شده. / Reapplies category mapping to published synchronized products.  
Public methods: **6** · Internal non-public methods: **22**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public function __construct()` | ساخت نمونه و آماده سازی وابستگی های اعمال مجدد mapping دسته روی محصولات منتشر شده. | Constructor. |
| `public function start($source = 'admin')` | اجرای عملیات `start` در بخش اعمال مجدد mapping دسته روی محصولات منتشر شده. | Start a new category reapply run. |
| `public function cancel()` | اجرای عملیات `cancel` در بخش اعمال مجدد mapping دسته روی محصولات منتشر شده. | Cancel current run. |
| `public function reset()` | اجرای عملیات `reset` در بخش اعمال مجدد mapping دسته روی محصولات منتشر شده. | Reset state. |
| `public function process_batch($limit = null)` | پردازش batch در بخش اعمال مجدد mapping دسته روی محصولات منتشر شده. | Process one bounded batch. |
| `public function get_status()` | دریافت status در بخش اعمال مجدد mapping دسته روی محصولات منتشر شده. | Return UI/status payload. |

### `Mobo_Core_Remote_Shipping_Methods`

Source: [`includes/class-mobo-core-remote-shipping-methods.php`](includes/class-mobo-core-remote-shipping-methods.php)  
cache، rule و نگاشت روش های ارسال موبو. / Caches and resolves Mobo shipping methods and rules.  
Public methods: **19** · Internal non-public methods: **15**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public function init()` | ثبت hookها و راه اندازی cache، rule و نگاشت روش های ارسال موبو. | Bootstrap hooks. |
| `public function maybe_sync_if_due($source = 'cron', $force = false)` | اجرای شرطی sync if due در بخش cache، rule و نگاشت روش های ارسال موبو. | Sync remote methods from MoboCore if due. |
| `public function sync_now($source = 'manual', $force = true)` | همگام سازی now در بخش cache، rule و نگاشت روش های ارسال موبو. | Force sync remote methods from MoboCore. |
| `public function store_snapshot($payload, $source = 'webhook')` | ذخیره snapshot در بخش cache، rule و نگاشت روش های ارسال موبو. | Store webhook or API shipping snapshot. |
| `public function get_methods()` | دریافت methods در بخش cache، rule و نگاشت روش های ارسال موبو. | Get normalized active remote shipping methods. |
| `public function get_status()` | دریافت status در بخش cache، rule و نگاشت روش های ارسال موبو. | Get status for admin UI. |
| `public function filter_package_rates($rates, $package)` | اعمال فیلتر روی package rates در بخش cache، rule و نگاشت روش های ارسال موبو. | Compatibility method. |
| `public function resolve_shipping_id_for_order($order, $available_shippings = array())` | حل و تعیین shipping id for order در بخش cache، rule و نگاشت روش های ارسال موبو. | Resolve Mobo shipping_id for a WC order. |
| `public function get_rules()` | دریافت rules در بخش cache، rule و نگاشت روش های ارسال موبو. | Get configured shipping rules for admin/debug. |
| `public function get_scenarios()` | دریافت scenarios در بخش cache، rule و نگاشت روش های ارسال موبو. | Scenario definitions used for Mobo submission. |
| `public function get_rule_modes()` | دریافت rule modes در بخش cache، rule و نگاشت روش های ارسال موبو. | Old UI modes kept only for backward compatibility with older saved options. |
| `public function build_wc_method_rule_option_key($zone_id, $method_id, $instance_id, $scenario = 'mobo_only')` | ساخت wc method rule option key در بخش cache، rule و نگاشت روش های ارسال موبو. | Build option key for a WooCommerce shipping-method to Mobo shipping-method rule. |
| `public function build_legacy_wc_method_rule_option_key($zone_id, $method_id, $instance_id)` | ساخت legacy wc method rule option key در بخش cache، rule و نگاشت روش های ارسال موبو. | Build the legacy scenario-less WooCommerce shipping-method mapping key. |
| `public function build_state_rule_option_key($scenario, $state_id, $slot)` | ساخت state rule option key در بخش cache، rule و نگاشت روش های ارسال موبو. | Build option key for a scenario/state/time-slot rule. |
| `public function get_allowed_ids_for_state_slot($scenario, $state_id, $slot)` | دریافت allowed ids for state slot در بخش cache، rule و نگاشت روش های ارسال موبو. | Get selected IDs for a state/time-slot rule. |
| `public function get_current_time_slot()` | دریافت current time slot در بخش cache، rule و نگاشت روش های ارسال موبو. | Get current WordPress time slot. |
| `public function get_time_slots()` | دریافت time slots در بخش cache، rule و نگاشت روش های ارسال موبو. | Time slot labels. |
| `public function get_time_slot_label($slot)` | دریافت time slot label در بخش cache، rule و نگاشت روش های ارسال موبو. | Get one time-slot label. |
| `public function get_wordpress_time_status()` | دریافت wordpress time status در بخش cache، rule و نگاشت روش های ارسال موبو. | WordPress clock status for admin UI. |

### `Mobo_Core_Reprice_Queue`

Source: [`includes/class-mobo-core-reprice-queue.php`](includes/class-mobo-core-reprice-queue.php)  
صف batch برای محاسبه مجدد قیمت. / Batch queue for recalculating product prices.  
Public methods: **6** · Internal non-public methods: **8**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public function __construct()` | ساخت نمونه و آماده سازی وابستگی های صف batch برای محاسبه مجدد قیمت. | Constructor. |
| `public function start($source = 'admin')` | اجرای عملیات `start` در بخش صف batch برای محاسبه مجدد قیمت. | Start a new repricing run. |
| `public function cancel()` | اجرای عملیات `cancel` در بخش صف batch برای محاسبه مجدد قیمت. | Cancel current repricing run. |
| `public function reset()` | اجرای عملیات `reset` در بخش صف batch برای محاسبه مجدد قیمت. | Reset state. |
| `public function process_batch($limit = null)` | پردازش batch در بخش صف batch برای محاسبه مجدد قیمت. | Process one bounded batch. |
| `public function get_status()` | دریافت status در بخش صف batch برای محاسبه مجدد قیمت. | Return UI/status payload. |

### `Mobo_Core_Rest_Controller`

Source: [`includes/class-mobo-core-rest-controller.php`](includes/class-mobo-core-rest-controller.php)  
Routeهای REST ورودی افزونه و امنیت آنها. / Plugin REST routes and their security checks.  
Public methods: **17** · Internal non-public methods: **1**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public function init()` | ثبت hookها و راه اندازی Routeهای REST ورودی افزونه و امنیت آنها. | Init REST routes. |
| `public function register_routes()` | اجرای عملیات `register_routes` در بخش Routeهای REST ورودی افزونه و امنیت آنها. | Register REST routes. |
| `public function check_security($request)` | بررسی security در بخش Routeهای REST ورودی افزونه و امنیت آنها. | Check external security header. |
| `public function check_cron_security($request)` | بررسی cron security در بخش Routeهای REST ورودی افزونه و امنیت آنها. | Check real cron token. |
| `public function ensure_categories_sync(WP_REST_Request $request)` | اطمینان از categories sync در بخش Routeهای REST ورودی افزونه و امنیت آنها. | Ensure categories are synced if due. |
| `public function receive_webhook($request)` | اجرای عملیات `receive_webhook` در بخش Routeهای REST ورودی افزونه و امنیت آنها. | Receive webhook, store it, and automatically try to process queue. |
| `public function run_real_cron($request)` | اجرای عملیات `run_real_cron` در بخش Routeهای REST ورودی افزونه و امنیت آنها. | Run one real-cron slice. |
| `public function get_real_cron_status($request)` | دریافت real cron status در بخش Routeهای REST ورودی افزونه و امنیت آنها. | Return real-cron status. |
| `public function run_self_worker($request)` | اجرای عملیات `run_self_worker` در بخش Routeهای REST ورودی افزونه و امنیت آنها. | Run one local self-worker slice. |
| `public function get_self_worker_status($request)` | دریافت self worker status در بخش Routeهای REST ورودی افزونه و امنیت آنها. | Return local self-worker status. |
| `public function get_health($request)` | دریافت health در بخش Routeهای REST ورودی افزونه و امنیت آنها. | Return local site health for MoboCore probe. |
| `public function send_health_report_now($request)` | ارسال health report now در بخش Routeهای REST ورودی افزونه و امنیت آنها. | Force-send health report to MoboCore. |
| `public function run_webhook_queue($request)` | اجرای عملیات `run_webhook_queue` در بخش Routeهای REST ورودی افزونه و امنیت آنها. | Run webhook queue. |
| `public function start_product_sync($request)` | شروع product sync در بخش Routeهای REST ورودی افزونه و امنیت آنها. | Start product sync from C#. |
| `public function run_product_sync($request)` | اجرای عملیات `run_product_sync` در بخش Routeهای REST ورودی افزونه و امنیت آنها. | Run one product sync step. |
| `public function get_product_sync_status($request)` | دریافت product sync status در بخش Routeهای REST ورودی افزونه و امنیت آنها. | Get product sync status. |
| `public function cancel_product_sync($request)` | لغو product sync در بخش Routeهای REST ورودی افزونه و امنیت آنها. | Cancel product sync. |

### `Mobo_Core_Self_Runner`

Source: [`includes/class-mobo-core-self-runner.php`](includes/class-mobo-core-self-runner.php)  
اجرای loopback غیرمسدودکننده و ادامه خودکار work. / Non-blocking loopback worker and continuation logic.  
Public methods: **5** · Internal non-public methods: **1**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public static function build_worker_url($source = 'self-kick')` | ساخت worker url در بخش اجرای loopback غیرمسدودکننده و ادامه خودکار work. | Build local worker URL. |
| `public static function kick($reason = 'webhook', $force = false)` | اجرای عملیات `kick` در بخش اجرای loopback غیرمسدودکننده و ادامه خودکار work. | Dispatch a non-blocking local worker request. |
| `public static function record_run_result($result)` | اجرای عملیات `record_run_result` در بخش اجرای loopback غیرمسدودکننده و ادامه خودکار work. | Record a worker run result. |
| `public static function should_continue_after_result($result)` | تعیین اینکه آیا continue after result در بخش اجرای loopback غیرمسدودکننده و ادامه خودکار work. | Decide whether another local worker slice should be kicked. |
| `public static function get_status()` | دریافت status در بخش اجرای loopback غیرمسدودکننده و ادامه خودکار work. | Return self runner status. |

### `Mobo_Core_Settings`

Source: [`includes/class-mobo-core-settings.php`](includes/class-mobo-core-settings.php)  
تعریف و خواندن تنظیمات افزونه. / Defines, reads, and saves plugin settings.  
Public methods: **5** · Internal non-public methods: **8**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public static function defaults()` | برگرداندن optionهای پیش فرض افزونه. | Default options. |
| `public static function get($key, $fallback = null)` | اجرای عملیات `get` در بخش تعریف و خواندن تنظیمات افزونه. | Get option. |
| `public static function get_int($key, $default, $min, $max)` | خواندن option عددی و محدود کردن آن به بازه مجاز. | Get integer option with min/max clamp. |
| `public static function enabled($key, $default = '0')` | خواندن یک option بولی با مقدار پیش فرض. | Get boolean-like option. |
| `public static function save_from_post($post)` | ذخیره from post در بخش تعریف و خواندن تنظیمات افزونه. | Save settings from admin post. |

### `Mobo_Core_Shipping_Diagnostics`

Source: [`includes/class-mobo-core-shipping-diagnostics.php`](includes/class-mobo-core-shipping-diagnostics.php)  
ثبت diagnostics نرخ های ارسال Checkout. / Captures checkout shipping-rate diagnostics.  
Public methods: **7** · Internal non-public methods: **14**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public function init()` | ثبت hookها و راه اندازی ثبت diagnostics نرخ های ارسال Checkout. | Bootstrap hooks. |
| `public function get_last_report()` | دریافت last report در بخش ثبت diagnostics نرخ های ارسال Checkout. | Return last report. |
| `public function clear()` | اجرای عملیات `clear` در بخش ثبت diagnostics نرخ های ارسال Checkout. | Clear last report. |
| `public function capture_checkout_update_order_review($post_data)` | اجرای عملیات `capture_checkout_update_order_review` در بخش ثبت diagnostics نرخ های ارسال Checkout. | Capture serialized checkout update request. |
| `public function capture_package_rates($rates, $package)` | اجرای عملیات `capture_package_rates` در بخش ثبت diagnostics نرخ های ارسال Checkout. | Capture calculated rates. |
| `public function capture_cart_no_shipping_html($html)` | اجرای عملیات `capture_cart_no_shipping_html` در بخش ثبت diagnostics نرخ های ارسال Checkout. | Capture WooCommerce no-shipping message context. |
| `public function capture_no_shipping_html($html)` | اجرای عملیات `capture_no_shipping_html` در بخش ثبت diagnostics نرخ های ارسال Checkout. | Capture WooCommerce no-shipping message context. |

### `Mobo_Core_SMS_Notifications`

Source: [`includes/class-mobo-core-sms-notifications.php`](includes/class-mobo-core-sms-notifications.php)  
پیامک مبتنی بر نوع سفارش از طریق PWSMS. / Order-type SMS notifications through PWSMS.  
Public methods: **8** · Internal non-public methods: **7**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public function init()` | ثبت hookها و راه اندازی پیامک مبتنی بر نوع سفارش از طریق PWSMS. | Bootstrap hooks. |
| `public function handle_checkout_order_processed($order_id, $posted_data = array(), $order = null)` | مدیریت رویداد یا درخواست checkout order processed در بخش پیامک مبتنی بر نوع سفارش از طریق PWSMS. | Classic checkout hook. |
| `public function handle_store_api_checkout_order_processed($order)` | مدیریت رویداد یا درخواست store api checkout order processed در بخش پیامک مبتنی بر نوع سفارش از طریق PWSMS. | Block checkout hook. |
| `public function maybe_send_for_order($order)` | اجرای شرطی send for order در بخش پیامک مبتنی بر نوع سفارش از طریق PWSMS. | Send configured notification once for the order type. |
| `public function get_scenarios()` | دریافت scenarios در بخش پیامک مبتنی بر نوع سفارش از طریق PWSMS. | Scenario labels. |
| `public function get_scenario_label($scenario)` | دریافت scenario label در بخش پیامک مبتنی بر نوع سفارش از طریق PWSMS. | Get scenario label. |
| `public function get_scenario_config($scenario)` | دریافت scenario config در بخش پیامک مبتنی بر نوع سفارش از طریق PWSMS. | Read one scenario config. |
| `public function classify_order($order)` | طبقه بندی order در بخش پیامک مبتنی بر نوع سفارش از طریق PWSMS. | Classify order by Mobo product presence. |

### `Mobo_Core_Sync_Event_Store`

Source: [`includes/class-mobo-core-sync-event-store.php`](includes/class-mobo-core-sync-event-store.php)  
صف دیتابیسی persistent برای رویدادهای Sync. / Persistent database queue for synchronization events.  
Public methods: **17** · Internal non-public methods: **14**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public static function table_name()` | برگرداندن نام جدول با prefix وردپرس. | Return table name. |
| `public static function create_table()` | ایجاد یا بروزرسانی schema جدول داخلی. | Create/update table schema. |
| `public static function table_exists()` | بررسی وجود جدول داخلی در دیتابیس. | Check whether table exists. |
| `public function enqueue($raw_payload)` | اجرای عملیات `enqueue` در بخش صف دیتابیسی persistent برای رویدادهای Sync. | Enqueue webhook payload. |
| `public function get_due_events($limit)` | دریافت due events در بخش صف دیتابیسی persistent برای رویدادهای Sync. | Get due pending events. |
| `public function lock_event($id, $ttl = 90)` | اجرای عملیات `lock_event` در بخش صف دیتابیسی persistent برای رویدادهای Sync. | Lock a pending/stale event for processing. |
| `public function row_to_item($row)` | اجرای عملیات `row_to_item` در بخش صف دیتابیسی persistent برای رویدادهای Sync. | Convert row to processable queue item. |
| `public function mark_done($id)` | ثبت وضعیت done در بخش صف دیتابیسی persistent برای رویدادهای Sync. | Mark event as completed. |
| `public function mark_done_with_progress($id, $payload = array(), $progress = array())` | ثبت وضعیت done with progress در بخش صف دیتابیسی persistent برای رویدادهای Sync. | Mark event as completed while keeping diagnostic progress. |
| `public function retire_stale_parent_waiting_events($timeout_seconds = 600, $limit = 200)` | اجرای عملیات `retire_stale_parent_waiting_events` در بخش صف دیتابیسی persistent برای رویدادهای Sync. | Retire UpdateVariant events that have been waiting for a missing parent too long. |
| `public function mark_pending_progress($id, $payload, $progress = array())` | ثبت وضعیت pending progress در بخش صف دیتابیسی persistent برای رویدادهای Sync. | Keep partially processed event pending with updated payload/progress. |
| `public function mark_failure($id, $message, $try_count, $final_failed = false)` | ثبت وضعیت failure در بخش صف دیتابیسی persistent برای رویدادهای Sync. | Mark event retry or failure. |
| `public function mark_retry_now($id, $message, $try_count, $final_failed = false)` | ثبت وضعیت retry now در بخش صف دیتابیسی persistent برای رویدادهای Sync. | Mark event as pending and immediately due for retry. |
| `public function count_due()` | شمارش due در بخش صف دیتابیسی persistent برای رویدادهای Sync. | Count due events that can be attempted now. |
| `public function count_pending()` | شمارش pending در بخش صف دیتابیسی persistent برای رویدادهای Sync. | Count pending events. |
| `public function count_failed()` | شمارش failed در بخش صف دیتابیسی persistent برای رویدادهای Sync. | Count failed events. |
| `public function retry_failed_events($limit = 200)` | تلاش مجدد برای failed events در بخش صف دیتابیسی persistent برای رویدادهای Sync. | Re-queue failed events for another attempt. |

### `Mobo_Core_Variation_Fields`

Source: [`includes/class-mobo-core-variation-fields.php`](includes/class-mobo-core-variation-fields.php)  
فیلد قیمت اضافه برای variation ووکامرس. / Adds and saves the variation additional-price field.  
Public methods: **3** · Internal non-public methods: **0**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public function init()` | ثبت hookها و راه اندازی فیلد قیمت اضافه برای variation ووکامرس. | Init hooks. |
| `public function render_additional_price_field($loop, $variation_data, $variation)` | رندر additional price field در بخش فیلد قیمت اضافه برای variation ووکامرس. | Render additional price field in WooCommerce variation pricing section. |
| `public function save_additional_price_field($variation_id, $loop)` | ذخیره additional price field در بخش فیلد قیمت اضافه برای variation ووکامرس. | Save additional price field. |

### `Mobo_Core_Webhook_Queue`

Source: [`includes/class-mobo-core-webhook-queue.php`](includes/class-mobo-core-webhook-queue.php)  
پذیرش و پردازش صف وب هوک با fallback فایل. / Accepts and processes webhook events with a file fallback.  
Public methods: **4** · Internal non-public methods: **31**

| Signature | توضیح فارسی | English summary |
|---|---|---|
| `public function store($payload)` | اجرای عملیات `store` در بخش پذیرش و پردازش صف وب هوک با fallback فایل. | Store webhook payload as a file. |
| `public function get_status()` | دریافت status در بخش پذیرش و پردازش صف وب هوک با fallback فایل. | Return lightweight queue status. |
| `public function has_due_work()` | بررسی وجود due work در بخش پذیرش و پردازش صف وب هوک با fallback فایل. | Whether there is due work that can run now. |
| `public function process()` | اجرای عملیات `process` در بخش پذیرش و پردازش صف وب هوک با fallback فایل. | Process webhook queue. |
---

<a id="en"></a>

# English integration reference

This file documents the developer-facing surface of Mobo Core `10.31.62`. Public method signatures are extracted from the current source. Private and protected methods are implementation details and are not a stable integration contract.

## 1. Stable integration surfaces

- WordPress REST namespace: `/wp-json/mobo-core/v1`
- Portal authorization header: `Token`
- Shared webhook/pull secret: `X-SEC`
- Dedicated server runner secret: `token=<mobo_core_cron_token>`
- Primary webhook queue: `wp_mobo_sync_events`
- Fallback webhook storage: `wp-content/uploads/mobo-core/webhook-files/`
- Extension filters: all `mobo_core_*` filters listed in the Persian section above
- Public PHP methods: the bilingual class tables above are language-neutral and include English summaries

## 2. REST security contract

`check_security()` requires `mobo_core_security_code` and compares it with `X-SEC` using `hash_equals()`. `check_cron_security()` requires `mobo_core_cron_token`, accepts `token` from the request, and falls back to `X-SEC`.

## 3. Outbound API contract

`Mobo_Core_API_Client` sends JSON GET requests with `Token` and, when configured, `X-SEC`. It supports products, variations, product lookup by GUID, categories, address mapping, shipping methods, LicenseInfo, and absolute/root-relative/base-relative payload URLs from lightweight notifications.

## 4. Compatibility guarantees

- Public REST route names are the primary external contract with Portal.
- Product and variation GUID metadata remain supported for legacy installations.
- Page-number sync remains compatible while cursor sync is preferred.
- The database event queue falls back to protected JSON files.
- Missing variations default to out-of-stock rather than physical deletion.
- Checkout and order automation remain disabled on fresh installs.

## 5. Translation maintenance

The detailed class tables intentionally combine Persian and English descriptions to keep both languages attached to the same source signature. When a public method, route, filter, option, or table changes, update one row rather than maintaining disconnected copies.

## 6. Related documents

- [`README.MD`](README.MD#en)
- [`README_FULL.MD`](README_FULL.MD#en)
- [`DIAGRAMS.MD`](DIAGRAMS.MD#en)

---

<div align="center">

[Persian](#fa) · [English](#en) · [Full documentation](README_FULL.MD) · [Function reference](FUNCTIONS.MD) · [Diagrams](DIAGRAMS.MD)

</div>
