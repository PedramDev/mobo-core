# Mobo Core 10.32.3

## Shared Media responsive image URL fix

WordPress builds responsive `srcset` candidates from the normal uploads base URL. Shared Media attachments store virtual relative paths such as `objects/80/5f/805f5e--300x300.webp`, which caused WordPress to emit URLs such as:

```text
https://example.com/wp-content/uploads/objects/80/5f/805f5e--300x300.webp
```

Version 10.32.3 registers a `wp_calculate_image_srcset` filter for Shared Media attachments and rewrites validated `objects/...` candidates to the configured `MOBO_CORE_SHARED_MEDIA_BASE_URL`:

```text
https://media.example.com/objects/80/5f/805f5e--300x300.webp
```

### Safety boundaries

- Only attachments marked with `mobo_shared_media = 1` are processed.
- Only candidate URLs containing a validated, readable `objects/...` file in the Shared Media repository are rewritten.
- Local WordPress attachments and unrelated URLs remain unchanged.
- Unexpected parsing or filesystem errors return the original `srcset` and do not cause a fatal error.
- No database migration is required.
