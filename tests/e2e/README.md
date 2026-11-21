# MoboCore admin E2E suite (Playwright)

Browser tests for the WordPress admin UI of **MoboCore 10.34.77**
(`wp-content/plugins/mobo-core/`). This folder sits at the repo root, outside the
plugin, so it never ships to customers.

Owner requirement: *every button must really do what it's supposed to do.* Each
test clicks the real control, then checks the effect you can observe: the rendered
`.mobo-alert-{type}` notice text from the `mobo_message`/`mobo_type` redirect, the
persisted option or Connection-scoped setting, the durable state row
(`wp_mobo_state`), order meta and order notes, or the AJAX payload the page polls.
A test never passes just because a page loaded.

## Run

```powershell
cd tests\e2e
npm ci                                # installs only @playwright/test (pinned 1.64.1)
npx playwright test --list            # sanity check, needs no site
npx playwright test                   # full run against BASE_URL (default http://127.0.0.1:8081)
npx playwright test --grep-invert @backend   # skip tests that need the local Backend/mock
npx playwright test specs/pricing.spec.ts    # one area
$env:HEADED='1'; npx playwright test  # watch it
npx playwright show-report
```

The suite uses the installed Microsoft Edge (`channel: 'msedge'`). Do **not** run
`npx playwright install`.

All specs share one WordPress site, so they run serially (`workers: 1`). A setup
project (`specs/auth.setup.ts`) logs in once and saves `.auth/admin.json`
(git-ignored) as storageState.

## Safety guards

- `BASE_URL`, `MOBO_MOCK_SOURCE_URL` and `MOBO_BACKEND_URL` must be `http(s)://127.0.0.1` or
  `localhost`. Otherwise config loading throws and the whole run fails, `--list` included.
- Every browser request to a non-loopback host is aborted (`lib/fixtures.ts`).
- DB access is read-only. `lib/db.ts` accepts one `SELECT`/`SHOW` statement and
  wraps it in `START TRANSACTION READ ONLY … ROLLBACK`. The password goes in
  through `MYSQL_PWD`, never on the command line.
- Secrets are read at runtime and never printed or hardcoded.
- Destructive actions (reset sync, quarantine, clear logs, delete a pending
  Connection, Unicode migration) are expected on staging. Settings tests restore
  the original value in a `finally` block, using the same real save button.

## Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `BASE_URL` | `http://127.0.0.1:8081` | Staging site (8081 clean, 8082–8085 customer copies, 8086 clean on PHP 7.4) |
| `WP_ADMIN_USER` / `WP_ADMIN_PASSWORD` | from `C:\MOBO-WORK\staging\secrets\wp-admin.env` | wp-admin login (`mobo_test_admin`) |
| `MOBO_WP_ADMIN_ENV` / `MOBO_DB_ENV` | `…\staging\secrets\wp-admin.env` / `db.env` | Alternative secrets files |
| `MOBO_DB_USER` / `MOBO_DB_PASSWORD` | `WP_DB_USER` / `WP_DB_PASSWORD` from `db.env` | Read-only queries |
| `MOBO_DB_HOST` / `MOBO_DB_PORT` | `127.0.0.1` / `3310` | MariaDB |
| `MOBO_DB_NAME` / `MOBO_TABLE_PREFIX` | derived from the BASE_URL port | Override for unknown ports (casemobilee uses prefix `adminsadra`) |
| `MOBO_MYSQL_BIN` | `C:\wamp64\bin\mariadb\mariadb11.5.2\bin\mysql.exe` | mysql CLI |
| `MOBO_MOCK_SOURCE_URL` | `http://127.0.0.1:5090` | Mock Source; `GET /__control/log` is used to prove requests arrived |
| `MOBO_BACKEND_URL` | `http://127.0.0.1:5080` | Local Backend; probed to auto-skip `@backend` tests |
| `MOBO_REQUIRE_BACKEND` | unset | `1` = `@backend` tests fail instead of auto-skipping when the Backend is down |
| `HEADED` | unset | `1` = headed browser |

## Tags and state-dependent skips

- `@backend`: the effect needs the local Backend and/or mock Source (sync progress,
  category fetch, Mobo login test, address/shipping sync, order submission). These
  tests skip automatically when `127.0.0.1:5080` is unreachable, and you can exclude
  them with `--grep-invert @backend`. Where the mock log is reachable, the tests also
  check that new entries showed up in `/__control/log`.
- **Conditional controls.** Some buttons render only in a given site state: resume
  sync, the quarantine warning, wallet re-arm, test SMS, Unicode migration
  start/pause/resume, image-refresh start, reprice/recategorize cancel and resume,
  and the order retry button. The DB helper is read-only, so tests never fake that
  state. When a control is not rendered, the test is **skipped with an explicit
  reason** in the report ("Control not rendered in the current site state: …").
  Running against the customer copies (8082–8085) exercises more of them.

## Coverage: handler → test

Code refs are `includes/class-mobo-core-admin.php` (A) and
`includes/class-mobo-core-checkout-validator.php` (CV) at 10.34.77 (commit 028bb27).

| Handler / control | Ref | Spec › test | Effect asserted |
|---|---|---|---|
| Menu `page=mobo-core` + submenus | A:265 | navigation › side menu "…" opens the X tab (8) + "تنظیمات پیشرفته" | `#mobo-core-admin.mobo-admin--{tab}` + tab-only marker |
| In-page tabs (8 main, 6 advanced), back link | A:1020 | navigation › in-page tab / advanced in-page tab / "بازگشت به تنظیمات اصلی" | same |
| Admin bar nodes | A:345 | navigation › admin bar "موبو" node… | every node label, links navigate |
| Plugin row action links | mobo-core.php:53 | navigation › plugin row links… | destination tab rendered |
| start_sync | A:10212 | dashboard › "شروع همگام‌سازی محصولات"… | notice + new `syncId`, status running, repairMode 0 |
| (sync reaches Source) | — | dashboard › running sync reaches the mock Source `@backend` | new `/__control/log` entries |
| cancel_sync | A:10332 | dashboard › "توقف همگام‌سازی"… | notice + state `cancelled`, same syncId |
| reset_sync | A:10353 | dashboard › "پاک‌سازی وضعیت ذخیره‌شده"… | notice + `sync_state` row deleted |
| start_repair | A:10278 | dashboard › "Repair کامل محصولات"… | notice + repairMode 1, repair-completed marker cleared |
| resume_sync | A:10305 | dashboard › "ادامه همگام‌سازی"… (conditional) | same syncId, status running |
| sync_categories | A:10184 | dashboard › "به‌روزرسانی دسته‌بندی‌ها برای نگاشت" `@backend`; categories › "دریافت تازه‌ترین فهرست دسته‌ها" `@backend` | notice + snapshot timestamp + mock log |
| quarantine_duplicate_products | A:10239 | dashboard › "حفظ محصول اصلی…" (conditional) | notice + `mobo_core_last_duplicate_quarantine_at/result` |
| rearm_wallet_alert | A:9424 | dashboard › "فعال‌سازی مجدد یادآوری موجودی"; advanced-sms › SMS-tab re-arm (conditional) | notice + `mobo_core_wallet_alert_notified`=0, return tab |
| ajax get_sync_status | A:9819 | dashboard › ajax mobo_core_get_sync_status… | JSON success/isRunning; bad nonce → 403 |
| create_connection | A:9479 | connection › "ایجاد اتصال"… | notice + pending row (enabled 0); duplicate token refused |
| cancel_pending_connection | A:9504 | connection › … "لغو اتصال در انتظار"… | notice + row and scoped settings deleted |
| save_settings: connection | A:9543 / A:2229 | connection › "ذخیره اطلاعات اتصال"…; invalid security code is blocked | `mobo_core_money_target_preference`; HTML `pattern` blocks submit, code unchanged (the server-side guard at A:9602 can't be reached through the UI) |
| save_settings: product | A:9045 / A:2627 | product › toggle {8 keys}…; cache warmup policy | Connection-scoped setting + re-rendered radio |
| save_settings: categories | A:5102 / A:5125 | categories › "ذخیره رفتار دسته‌بندی"; mapping off clears required; "ذخیره نگاشت‌های این صفحه" | settings + `wp_mobo_category_map.manual_term_id` |
| save_settings: pricing | A:5160 | pricing › "ذخیره سیاست قیمت"… | `mobo_price_type`, `global_additional_percentage` |
| save_settings: filters | A:5223 | advanced-filters › "ذخیره استثناها"…; invalid URL rejected | canonical, de-duplicated paths; error + unchanged |
| save_settings: queue | A:5509 | advanced-queue › products per page; image queue off → warning | verified read-back values |
| save_settings: cron | A:4845 | advanced-cron › "هاست ضعیف" preset… | preset fills form, persisted values |
| save_settings: checkout | A:2817 | advanced-checkout › toggle shipping diagnostics… | setting persisted (also accepts the fail-closed order-submission notice) |
| save_settings: sms | A:3785 | advanced-sms › "حد هشدار موجودی"… | `mobo_core_wallet_alert_threshold` |
| start_recategorize | A:10502 | categories › "اعمال روی محصولات موجود"… | notice + durable state + ajax status |
| cancel_recategorize | A:10534 | categories › "توقف عملیات"… (conditional) | status no longer running |
| reset_recategorize | A:10562 | categories › "پاک کردن گزارش قبلی"… (conditional) | state row deleted |
| ajax recategorize status | A:10085 | categories › ajax mobo_core_get_recategorize_status… | JSON success/status |
| start_reprice | A:10375 | pricing › "اعمال قیمت روی همه کاندیداها از ابتدا"… | notice + state running/done |
| cancel_reprice | A:10444 | pricing › "توقف اعمال قیمت"… (conditional) | state `cancelled` |
| resume_reprice | A:10412 | pricing › "ادامه از checkpoint"… (conditional) | state running/done |
| reset_reprice | A:10472 | pricing › "پاک کردن گزارش قبلی"… (conditional) | state row deleted |
| ajax reprice status | A:10032 | pricing › ajax mobo_core_get_reprice_status… | JSON success/status |
| start_image_refresh_automation | A:10990 | advanced-image-refresh › "نوسازی کامل تصاویر از منبع"… (conditional) | notice + `…automation_enabled`=1, start hidden |
| ajax image refresh live status | A:10120 | advanced-image-refresh › ajax … re-renders the tab HTML | JSON html/serverTime; bad nonce → 403 |
| tool_run_cron_now | A:9228/9330 | advanced-cron › "اجرای دستی یک Slice واقعی Worker"… | notice type matches status; `mobo_core_real_cron_last_result` changed |
| tool_test_mobo_login | A:9315 | advanced-checkout › "تست اتصال به موبو" `@backend` | notice consistent with stored result + timestamp |
| tool_sync_address_mapping | A:9259 | advanced-checkout › "بروزرسانی آدرس موبو…" `@backend` | success + last attempt/success timestamps |
| tool_sync_remote_shipping_methods | A:9275 | advanced-checkout › "بروزرسانی روش‌های ارسال از MoboCore" `@backend` | success + timestamps |
| tool_clear_mobo_debug_log | A:9239 | advanced-checkout › "پاک کردن گزارش سبد موبو"… | `checkout_debug` events = 0, legacy option gone |
| tool_clear_shipping_diagnostics | A:9249 | advanced-checkout › "پاک کردن گزارش ارسال"… | `mobo_core_shipping_diagnostics_last` gone |
| test_wallet_sms | A:9452 | advanced-sms › "ارسال SMS آزمایشی"… (conditional) | notice + `…test_sms_last_result` |
| php_diagnostics | A:4070 | health › "مشاهده جزئیات امن محیط PHP"…; rejects invalid nonce | popup title/table, no env leak |
| unicode migration start | A:3976 | health › "شروع تبدیل کنترل‌شده utf8mb4"… (conditional) | notice + migration state |
| unicode migration resume | A:4016 | health › "ادامه از Checkpoint" / "اجرای یک مرحله الآن"… (conditional) | notice |
| unicode migration pause | A:4046 | health › "Pause در مرز امن"… (conditional) | warning notice + state |
| retry_failed_webhooks | A:10590 | health › "برگرداندن وب‌هوک‌های ناموفق به صف"… | count in notice = min(failed,1000); failed rows decreased |
| Product list source filter | A:393 / A:41 | product › "محصولات موبویی" / "غیر موبویی" filters partition… | every row badge matches, mobo + non-mobo = all |
| Pricing override modal | A:726 / A:753 | product › out-of-range rejected / "ذخیره و قیمت‌گذاری مجدد"… | client validation (no request); meta `_mobo_pricing_override_*` + row badge |
| ajax save pricing override | A:789 | product › same + rejects an invalid nonce | JSON success/status; 403 |
| Order meta box | CV:6902 | orders › meta box renders… | box + state line |
| retry_mobo_order_submission | CV:6913 | orders › "ارسال به موبو" / "ارسال مجدد به موبو"… `@backend` (conditional) | new order note or `admin_manual_retry_requested` log entry |
| clear_mobo_order_log | CV:7052 | orders › "پاک کردن لاگ فنی"… (conditional) | `_mobo_order_submission_log` deleted, submit status preserved |

### Not covered (with reason)

| Handler | Reason |
|---|---|
| `tool_install_automatic_shipping` (A:60 / A:9291) | Hooked, but no 10.34.77 screen renders a control for it. |
| `save_settings` for `image-refresh` / `health` / `sync-health` tabs | Accepted by the handler, but those tabs render no settings form (legacy fail-closed paths). |
| Legacy flags inside `save_settings` (`mobo_core_clear_mobo_debug_log`, `mobo_core_sync_address_mapping`, `mobo_core_run_cron_now`, …) | No current form posts them; the UI uses the separate `tool_*` actions, which are covered. |
| `handle_apply_shipping_wizard`, image-refresh pause/run/approve/scan/delete/reset handlers (A:9216, A:11036–11620) | Not hooked to any `admin_post_*` action in 10.34.77 (dead code); no reachable control. |
| Purchase tab | No handlers; render is covered by navigation tests. |

## Notes

- Other engineers are changing the migration admin notices, price-mismatch notices on
  orders, and order retry capability. Selectors rely on form contracts (hidden
  `action` / `mobo_active_tab` inputs, field names, `data-*` hooks) and Persian
  button text, not layout classes.
- The staging sites were installed with MoboCore 10.33.67 (see
  `C:\MOBO-WORK\staging\STAGING.md`). Tests for 10.34.77-only features (for example
  Multi-Connection create/cancel, the Unicode migration, and the product-list
  filter/pricing modal) need 10.34.77 deployed on the target site.
- Last run (2026-11-21, 8081 clean on **10.33.67**, Backend/mock down): 24 passed,
  19 skipped (state-dependent or `@backend`), 50 failed. Every failure traced to
  10.33.67 differing from 10.34.77: no `mobo-admin--{tab}` wrapper class, older labels
  and notice texts, different bool-field markup, and no money-target select,
  product-list filter or Connection tables. Re-run after 10.34.77 is deployed. The DB
  helpers fall back to legacy storage (`mobo_core_sync_state` option, tables without
  `connection_id`), so the sync and health tests also run on 10.33.67.
