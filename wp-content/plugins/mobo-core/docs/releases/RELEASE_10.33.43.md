# Mobo Core 10.33.43 — Remote Upgrade drain reliability

## Incident
Remote Upgrade could fail with `Site workers did not reach an idle boundary before the upgrade drain timeout.` even when no mutation worker was actually running.

## Root cause
The self-runner uses `worker_dispatcher` first as a non-blocking HTTP handoff lease and later as the active worker lease. The handoff lease can live for 180 seconds, while the default upgrade drain timeout is 120 seconds. If the loopback request had not reached WordPress yet, the upgrader treated that pre-work handoff as live work and timed out.

A second mismatch was possible when a configured blocking Mobo HTTP timeout exceeded the drain window.

## Fix
- Barrier activation detects a dispatched-but-not-started self-runner handoff.
- It removes only the exact unchanged lease snapshot using atomic compare-and-delete. If the worker claims or renews the lease concurrently, cleanup fails safely and the upgrader waits for the live worker.
- Durable pending work is preserved and is kicked again after the upgrade barrier is released.
- The effective drain timeout includes 30 seconds of headroom above the longest configured Mobo blocking HTTP timeout, while retaining the existing 300-second hard cap.
- Retryable busy errors include bounded blocking-lock names and remaining lease seconds.

## Safety
No active worker lease is force-unlocked. Filesystem replacement still begins only after all real blocking runtime leases have drained.
