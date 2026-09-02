# Mobo Core 10.33.44.9 — full source image refresh

## Fresh replacement from the canonical source

- Image Refresh now includes marked local Mobo WebP attachments, not only legacy JPG/PNG media.
- A stable workflow-generation query token and explicit no-cache headers force the source payload to be fetched again even when its canonical URL did not change.
- Canonical attachment identity remains unchanged; the cache-busting query parameter is request-only.

## Bounded disk and concurrency behavior

- One verified fresh attachment is created per old attachment per workflow generation, then safely reused for every product that references it.
- A verified candidate is checkpointed before the WooCommerce product save, so a timeout or failed save retries linkage without downloading another collision file.
- After every successful replacement, the existing full reference audit migrates remaining references and deletes the old family as soon as it is safe, releasing space incrementally instead of retaining two full media libraries until the final cleanup stage.
- Existing identity locks, storage reserve/write probes, WebP/subsize validation, reference migration and safe deletion checks remain mandatory.
- Worker-owned Shared Media WebPs are excluded from forced site-local replacement.

## Compact live report

- The Image Refresh page shows the current stage, progress within scan/queue/replacement stages, fresh download and reuse counts, and measured before/after attachment-family size savings.
- The page continues to refresh automatically while the workflow is active.

## Deployment

- No schema change or manual SQL is required.
- Upgrade resets only an inactive old refresh queue/state so completed legacy rows cannot suppress the new full-source generation.
- An already-running older cycle is not interrupted; after it finishes, starting Image Refresh creates the fresh-source generation.
- Repair and Reconciliation are never started by this workflow.
