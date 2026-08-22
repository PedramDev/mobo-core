# Mobo Core 10.33.31 — One-click autonomous image refresh

## Goal
Image Refresh is an operator-free workflow after the administrator clicks **نوسازی تصاویر** once. No manual Repair prerequisite, pause, retry, destructive approval, stage button, or technical choice is required.

## Behaviour
- Starts the required full Product Repair automatically when it has not completed yet. A Repair generation that dies with `lastError` is backed off, retired and restarted automatically.
- Never cancels an already-running Product Sync; waits for that lane, then starts Repair itself.
- Processes legacy-image replacement in bounded Cron/Self Runner slices.
- Retries transient image failures with backoff. After a bounded total retry budget, only that row is quarantined; the rest of the workflow continues. A future refresh cycle gives quarantined rows a fresh retry budget.
- Retries WebP subsize repair automatically. Unrepairable/missing-original cases are quarantined for the cycle and are never used as permission to delete an unsafe old attachment.
- Automatically enables replaced-old cleanup only when Stage 7 is reached, after replacement/subsize verification. The destructive switch is disabled during replacement and again after Stage 7 convergence; deletion still requires the existing per-attachment reference and WebP/subsize safety audit.
- Automatically enables orphan-family cleanup, but each family is revalidated immediately before filesystem deletion. A filesystem refusal retains/skips the family rather than blocking the workflow.
- Removes manual pause and approval gates from the Image Refresh admin screen. The screen exposes one operation button only.

## Safety invariant
Automation removes operator decisions, not safety checks. Any item that cannot prove safe mutation is retained. Independent failures cannot stop the complete image refresh.
