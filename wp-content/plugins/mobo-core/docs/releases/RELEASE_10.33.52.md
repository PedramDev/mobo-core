# Mobo Core 10.33.52

## Targeted post-repair closure

- Health / Sync Health snapshot refresh is owned by Mobo real cron instead of WP-Cron.
- Cached Health responses cannot refresh their own freshness timestamp.
- Legacy v1/v2 Health WP-Cron hooks are cleared on upgrade and maintenance.
- A bounded one-time migration heals only the proven pre-10.33.51 variable-product completion race signature.
- Shared Media semantics are unchanged: shared images remain remotely served and are not forced into local uploads.
- Variant attribute fail-closed behavior is unchanged.

## Safety gates for convergence residue self-heal

A product is healed only when all of the following are true: live variable product, sane local topology, current post/map incomplete markers, no rebuild pending marker, exact Product and Variant applied revision/version match, source hash matches Sync Health and Product Map, no active sync-event owner, latest ProductUpdated and UpdateVariant share one syncId/version, ProductUpdated started first but completed later, UpdateVariant is terminal authoritative last-page data, payload variation count equals local variation count, and every payload variant has non-empty attributes.
