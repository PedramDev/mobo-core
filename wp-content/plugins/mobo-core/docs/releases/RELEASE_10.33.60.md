# Mobo Core 10.33.60 — Runtime lock hygiene after interrupted work

## Context

Mobo runtime leases are finite JSON option rows and are already self-healing on the next acquire/status check for the same lock. A focused post-run audit on `bazhircase.com` nevertheless found two expired product runtime-lock rows left behind by interrupted requests. They did not block Reprice because product-lock acquisition recovers an expired row before attempting a new lease, but the residue remained in `wp_options` until that specific product lock was touched again.

## Hardening

- After the real Cron Runner owns its own site-wide runner lease, it performs one bounded scan of `mobo_core_runtime_lock_%` through the existing `Mobo_Core_Lock::recover_stale_locks()` primitive.
- Recovery remains fail-safe: malformed/expired rows are removed only with compare-and-delete against the exact payload inspected; live leases are never shortened or force-released.
- Cleanup errors do not abort queue processing. A compact non-secret recovery summary is attached to the runner result for diagnostics.
- No pricing formula, queue cursor, webhook priority, product identity, schema, or Repair behavior changed.

## Upgrade behavior

No migration, Repair, reset, or manual SQL is required. Existing stale runtime-lock residue is cleaned on the next real Cron Runner slice; relevant lock acquisition remains self-healing even before that slice occurs.
