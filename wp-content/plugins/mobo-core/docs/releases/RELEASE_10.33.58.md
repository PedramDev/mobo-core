# Mobo Core 10.33.58

## Scope

This release hardens Repair/image desired-state convergence after a production database clone reproduced 120-second `wpdb` timeouts while stale attachment cleanup was running under the `manual_sync` lock.

## Changes

- Moves physical stale-attachment deletion out of Product/Repair hot-path into the durable `mobo_image_prune_queue`.
- Uses `image_guid` as the canonical Mobo image identity and `img_guid` as the legacy identity. If both exist they must agree. Missing, invalid, or conflicting GUID identity is retained fail-closed. `mobo_source_url` alone is not destructive ownership proof.
- Processes reference proof in two complete bounded primary-key cursor passes so large `wp_postmeta`, `wp_options`, or `wp_posts` tables cannot monopolize one Repair request, while reducing the race window before destructive deletion.
- Migrates legacy prune metadata in bounded background slices; plugin upgrade itself performs no broad table scan.
- Prevents the admin Repair status fallback from racing Real Cron/Self Runner by requiring the site-wide dispatcher lease.
- Treats `manual_sync` lock contention as contention, not successful Product progress, and disables Product Sync for the remainder of that runner invocation.
- Persists Manual Cron `dispatcher-locked` attempts so diagnostics never display an older plugin result as if it were current.
- Corrects Repair progress when a current product or queued snapshots still remain.
- Includes prune debt in Image Pending/Due scheduling even when remote image download processing is disabled or its Image Queue circuit breaker is open.

## Upgrade behavior

Existing Repair state is preserved. Existing legacy stale-image cleanup metadata is scheduled for bounded migration into the new queue; no manual SQL cleanup is required.
