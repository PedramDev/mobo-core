# Mobo Core 10.32.0 — Hosting Quota–Aware Site Health

Release date: 2026-07-31

## Problem

PHP `disk_free_space()` and `disk_total_space()` report the capacity of the mounted server filesystem. On shared hosting they normally do not reflect the byte or inode quota assigned to the cPanel account. A customer account can therefore be unable to create files while Site Health incorrectly shows hundreds of gigabytes free.

## Changes

- Legacy `diskFreeBytes`, `diskTotalBytes`, and `diskFreePercent` now represent account quota only.
- When account quota is unavailable, those legacy fields are deliberately `null` instead of exposing misleading server capacity.
- Underlying filesystem capacity is sent separately as `filesystemDisk*` and is explicitly labeled as server-filesystem information.
- A cached 64 KiB real-write probe is performed in the WordPress uploads directory. The probe uses actual create, write, flush, size verification, and cleanup operations instead of relying only on `is_writable()`.
- A failed probe marks uploads as not writable and provides a diagnostic suitable for exhausted byte quota, exhausted inode quota, permission failures, and filesystem errors.
- Exact cPanel byte and inode quota reporting is optionally supported through cPanel UAPI. No token is required for the safe write-probe fallback.
- Hosting-specific integrations can provide exact quota data through the `mobo_core_hosting_quota_stats` filter.

## Optional cPanel UAPI configuration

The following constants may be placed in `wp-config.php` when exact cPanel quota values are required:

```php
define( 'MOBO_CORE_CPANEL_QUOTA_URL', 'https://cpanel-host.example.com:2083' );
define( 'MOBO_CORE_CPANEL_USERNAME', 'cpanel_user' );
define( 'MOBO_CORE_CPANEL_API_TOKEN', 'cpanel_api_token' );
```

The URL may be either the cPanel base URL or the complete `Quota/get_quota_info` UAPI endpoint. The API token is used only in the outbound Authorization header and is never returned by the health endpoint, written to logs, or included in the release package.

## Compatibility

- No database schema or EF migration is required.
- Existing Site Health snapshot columns are retained.
- New detailed fields are stored in the existing raw health JSON.
- Sites without cPanel credentials still receive the real write-probe result and correctly labeled server-filesystem capacity.
- Shared Media, image recovery, Sync, Repair, Webhook Queue, Remote Upgrade, and Upgrade Barrier behavior are unchanged.
