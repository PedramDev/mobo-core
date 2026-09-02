# Mobo Core 10.33.44.7 — bounded image reimport and recovery shutdown

## Image queue

- A local, incomplete WebP attachment that fails final metadata/subsize readiness on the third queue attempt is removed from live Mobo identity lookup without deleting the attachment or its product references.
- The quarantine marker also vetoes the queue's direct `attachment_id` reuse path, so the old incomplete file cannot be selected through the legacy "unclaimed attachment" fallback.
- The next retry performs one fresh source import, allowing a downsized/repaired source file to replace a legacy oversized local original.
- Quarantined identity values remain stored in private attachment metadata for audit and rollback.
- The fallback is attempted exactly once per queue retry generation. A disk-full, network, or write failure after that point remains in normal retry/backoff and cannot create an unbounded series of replacement attachments.
- Shared Media attachments are excluded because their committed manifest remains authoritative.
- Existing active readiness failures already beyond attempt three are re-armed to attempt two during upgrade, so their next normal retry receives the same one-time self-heal behavior.

## Automatic Product Recovery

- Automatic Product Recovery is disabled at build level; manual Product Repair remains available.
- Upgrade migration clears pending/follow-up flags, retires any persisted in-flight/manifest payload, and releases the retired Product Recovery and Reconciliation runtime leases.
- Reconciliation remains disabled as introduced in `10.33.44.6`.

## Deployment

- No schema change is required.
- The normal version migration applies the Product Recovery shutdown automatically.
