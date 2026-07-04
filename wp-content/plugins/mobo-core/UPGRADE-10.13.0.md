# Mobo Core 10.13.0

## Local image sideload fallback

This version adds a fallback image downloader for local development URLs such as `http://127.0.0.1:5015/images/...`.

WordPress `media_sideload_image()` may reject localhost/private IP URLs as unsafe. Mobo Core now retries the download using `wp_remote_get()` with `reject_unsafe_urls=false`, then imports the temporary file using `media_handle_sideload()` so WordPress still validates the file type.

This is mainly for WAMP + local .NET testing. Production should still use a public HTTPS Portal URL.
