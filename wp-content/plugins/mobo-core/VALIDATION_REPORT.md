# Validation Report — Mobo Core 10.32.0

Date: 2026-07-31

## Scope

This release changes only storage diagnostics in the WordPress health reporter and the matching Portal Site Health contract/UI. Shared Media, image recovery, Webhook Queue, Image Queue, Desired State Sync, Repair, Reconciliation, Remote Upgrade, Upgrade Barrier, credentials, connection settings, and database schema were not replaced or modified.

## Implemented

- `disk_free_space()` and `disk_total_space()` are now treated strictly as server-filesystem telemetry.
- Legacy account disk fields are left null unless an account-level quota source is available.
- Added a cached 64 KiB create/write/flush/size/delete probe inside the WordPress uploads directory.
- A failed real-write probe marks uploads as not writable even when `is_writable()` returns true.
- Added optional exact cPanel byte and inode quota collection through cPanel UAPI constants.
- Added a filter-based quota provider for non-cPanel hosting integrations.
- No cPanel credential, token, or Authorization header is included in health output or logs.

## Checks completed in the build environment

- All 52 PHP files passed `php -l` using PHP 8.4.16.
- Filesystem-only test passed: legacy account quota fields remained null, server-filesystem capacity was reported separately, and the real write probe succeeded.
- Filter quota test passed: used, limit, free, percentage, and inode values were normalized correctly.
- cPanel UAPI response test passed: MB values converted to bytes and inode values were preserved; the test token was not emitted in the result.
- Write failure test passed using a simulated `Disk quota exceeded` uploads error.
- Site Health JavaScript passed `node --check` using Node.js 22.16.0.
- Modified C# files passed delimiter-balance and duplicate top-level DTO property checks.
- Plugin header, runtime constant, and readme stable tag report 10.32.0.
- No database migration was added.

## Environment limitation

The build environment does not contain .NET SDK 10, the customer's cPanel account, or a real quota-exhausted WordPress filesystem. Therefore no claim is made that the Portal project compiled or that a customer's exact cPanel quota was returned at runtime. Build Portal with the project's .NET 10 SDK and verify one real health response after deployment.
