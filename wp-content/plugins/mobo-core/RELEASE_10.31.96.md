# Mobo Core 10.31.96

## Private shared-media mode

This release adds an opt-in server-only mode for private WPStack installations. It is disabled by default and has no WordPress admin setting.

When enabled through the container environment, Mobo Core reads worker-generated manifests and files from a read-only shared repository. WordPress keeps normal attachment records, product featured images and galleries, but does not download or resize another copy inside each site's `wp-content/uploads` directory.

Required private runtime values:

```text
MOBO_CORE_SHARED_MEDIA_ENABLED=1
MOBO_CORE_SHARED_MEDIA_ROOT=/mnt/mobo-shared-media
MOBO_CORE_SHARED_MEDIA_BASE_URL=https://media.example.com
MOBO_CORE_SHARED_MEDIA_PROFILE_HASH=b6b45a8dfe770044c853889bc43984f8740b7d188df0ec7d1f8732100dc79a78
MOBO_CORE_SHARED_MEDIA_DELETE_LOCAL_COPIES=1
MOBO_CORE_SHARED_MEDIA_FALLBACK_TO_DOWNLOAD=0
```

The repository must be mounted read-only at the configured root. Only the Mobo Shared Media Worker may have write access.

Public installations without these values retain the existing per-site media behavior.
