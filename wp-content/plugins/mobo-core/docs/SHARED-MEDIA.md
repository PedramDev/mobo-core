# Mobo Core Private Shared Media

This mode is deliberately hidden and has no WordPress admin setting. It is
enabled only from `wp-config.php`:

```php
define( 'MOBO_CORE_SHARED_MEDIA_ENABLED', true );
define( 'MOBO_CORE_IMAGE_DOWNLOAD_ENABLED', false );
define( 'MOBO_CORE_SHARED_MEDIA_PATH', '/srv/mobo-shared-media' );
define( 'MOBO_CORE_SHARED_MEDIA_URL', 'https://media.example.com' );
define( 'MOBO_CORE_SHARED_MEDIA_PROFILE_HASH', 'PROFILE_HASH_FROM_BUILD_SIZE_PROFILE' );
```

The directory must be mounted read-only in every WordPress/PHP container. The
separate mirror worker is the only process with read/write access.

WordPress creates a local attachment post and metadata record for each image,
but `_wp_attached_file` points to the shared read-only file. WordPress does not
copy the image and does not generate intermediate sizes. The worker must prepare
the original WebP and every exact size listed in the shared profile before the
attachment is registered.

When shared mode is active:

- a local legacy attachment never blocks migration to a shared attachment;
- image queue rows pointing to local files are reprocessed;
- legacy refresh, subsize repair and replacement-delete workflows are disabled
  with status `managed_by_shared_media`;
- attachment deletion cannot delete the shared physical file;
- a manifest with another profile hash is rejected;
- when the manifest is not ready and downloads are disabled, the queue remains
  retryable and never falls back to local download.

The public media server should expose only `/objects/`. Per-image manifests and
worker state remain filesystem-only and are read by WordPress through the
read-only mount.
