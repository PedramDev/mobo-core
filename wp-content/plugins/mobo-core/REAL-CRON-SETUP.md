# Mobo Core real cron setup

This version is designed to use real server cron / cPanel cron as the primary execution path. WP-Cron is not required.

## Endpoint

The plugin exposes:

```text
/wp-json/mobo-core/v1/cron/run?token=CRON_TOKEN
```

The cron token is generated on activation and displayed in:

```text
Mobo → کران واقعی
```

## cPanel command

Use the command shown in the plugin admin page. Typical shape:

```bash
*/5 * * * * curl -fsS "https://example.com/wp-json/mobo-core/v1/cron/run?token=TOKEN" >/dev/null 2>&1
```

For 5000 customers, do not use the same minute on every site. Use offsets such as:

```bash
3-59/5 * * * * curl -fsS "https://example.com/wp-json/mobo-core/v1/cron/run?token=TOKEN" >/dev/null 2>&1
```

## What cron does

Each cron hit runs a bounded slice:

- Processes a small number of local webhook queue files.
- Runs a limited number of product-sync steps if a sync is already active.
- Uses a lock to avoid overlapping runs.
- Saves health status: last hit, last success, last result.

## New REST routes

- `GET|POST /mobo-core/v1/cron/run?token=...`
- `GET /mobo-core/v1/cron/status` with `X-SEC`

## Important

`/cron/run` is intentionally lightweight and chunked. It should not process a full sync in one request.

## Health Report

After each real cron slice, the plugin sends a bounded health report to Portal if health reporting is enabled.

Portal probe endpoint:

```http
GET /wp-json/mobo-core/v1/health
X-SEC: SECURITY_CODE
```

Manual health report endpoint:

```http
GET|POST /wp-json/mobo-core/v1/health/report-now
X-SEC: SECURITY_CODE
```
