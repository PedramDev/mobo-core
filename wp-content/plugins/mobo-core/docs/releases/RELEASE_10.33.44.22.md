# Mobo Core 10.33.44.22 — stale lock / stalled Repair recovery

- Adds centralized stale runtime-lock recovery using exact raw-value compare-and-delete semantics.
- Healthy live leases are never force-released; normal leases remain authoritative until their TTL expires.
- Malformed/expired rows are reclaimed automatically. A corrupted far-future expiry is recoverable only after a lock-specific heartbeat safety ceiling that exceeds the normal lease TTL.
- Recovery events are recorded without lock tokens/secrets.
- Manual Sync/Repair start now detects a durable `running` checkpoint that has no active `manual_sync` worker and has made no progress for the safety window. The same generation/cursors are re-awakened instead of creating/resetting state.
- A live worker lease, mismatched Repair/Sync mode, or different explicit remote `syncId` remains fail-closed.

Deep Test Suite target: `10.33.44.22-r7.11`.
