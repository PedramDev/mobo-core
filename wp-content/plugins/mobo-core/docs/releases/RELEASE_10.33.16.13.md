# Mobo Core 10.33.16.13 — Settings & Option Cache Fast Path

## Goal

Reduce cold-request database fan-out and per-request CPU/memory overhead from Mobo Core settings/state, while keeping runtime queues and diagnostics out of the global WordPress autoload payload.

## Changes

- `Mobo_Core_Settings::defaults()` now builds its several-hundred-entry map once per PHP request and reuses the immutable result.
- Added bulk option-cache priming with WordPress core support when available and a WordPress 5.8-compatible fallback.
- Real Cron primes its frequently used settings before queue stages begin, replacing many cold one-row option reads with a single bulk load.
- Upgrade/default seeding primes all defined settings before existence checks; Portal settings snapshots do the same before exporting configuration.
- One-time migration normalizes legacy `mobo_core_*` rows that were accidentally autoloaded by older releases back to `autoload=no`; option values are unchanged.
- Repeated identical self-runner `throttled`, `kick-locked`, `deferred-until-init`, and `disabled` diagnostics are persisted at most once every 30 seconds instead of producing a write storm during bursts.
- Removed a duplicate legacy `update_option()` call in default migration logic.

## Compatibility

No table or data reset is required. The upgrade performs only an autoload-flag normalization for existing `mobo_core_*` options and preserves every stored value. Queue, Stage 7, Desired State, Repair, order submission, cache warmup, media, and upgrade-barrier semantics are unchanged.
