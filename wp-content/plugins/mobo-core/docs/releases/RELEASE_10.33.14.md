# Mobo Core 10.33.14 — Image Refresh Self-Healing Runner

## Problem

Image Refresh could remain enabled while no new worker invocation was observed. The non-blocking Self Runner loopback can be accepted by WordPress HTTP APIs even when the hosting/network layer never executes the dispatched request. Stage 7 made this more visible because reference migration is intentionally bounded.

## Changes

- The Image Refresh live-status AJAX endpoint now has an authenticated direct rescue path.
- If automation is enabled, is not intentionally waiting, no batch is active, and no real automation activity has been recorded for 12 seconds, exactly one `run_tick("admin-live-rescue")` is executed.
- The normal `image_refresh_automation` lock remains authoritative, so the rescue cannot run in parallel with cron/Self Runner.
- Intentional wait states (`waiting-retry`, `waiting-active-processor`, delete-old setting wait, orphan approval wait) are excluded.
- Stage 7 internal batch time budget is reduced to 8 seconds; read-only Stage 6 is capped at 12 seconds. This leaves room for the outer cron runner to perform additional rounds in one invocation.
- Self Runner is still kicked after a rescued batch when more work remains.

This is a runtime-only reliability change and does not reset image workflow state or media data.
