# Mobo Core 10.17.0

- Increased default Portal/API request timeout for local/staging sync.
- Added `mobo_core_api_request_timeout_seconds` setting.
- Added transient retry handling for category/product/variant API timeouts during manual sync.
- A single cURL timeout no longer poisons `lastError` and stops the self-runner permanently.
- Added `mobo_core_transient_retry_max_try` setting.
