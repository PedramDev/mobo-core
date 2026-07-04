# Mobo Core 10.12.0

## Changes

- Added SelectWoo/Select2 support for local WooCommerce category mapping selects.
- Added live Toman preview below money price inputs. Numbers are grouped by three digits and suffixed with تومان.
- Fixed Action Scheduler health report crash by using `date` + `date_compare` instead of a malformed `<=YYYY-MM-DD...` date string.
- Wrapped Action Scheduler health count in try/catch so health reporting cannot fatal the worker.
- Allowed image sideloading from local development hosts such as `localhost` and `127.0.0.1` during `media_sideload_image()` requests. This fixes local WAMP + local .NET image URL tests blocked by WordPress safe HTTP validation.

## Notes

For local development, prefer HTTP image URLs such as:

```text
http://127.0.0.1:5015/images/example.webp
```

instead of local HTTPS with an untrusted certificate.
