# Mobo Core 10.33.28

## Out-of-stock retention hotfix + durability audit checkpoint

10.33.28 stops a destructive interaction between `OnlyInStock` and Deep Integrity reconciliation. A catalog filtered to in-stock products is not a complete inventory and therefore cannot prove that an unseen local Mobo product was deleted remotely.

### Critical product-retention fix

- Deep scans capture the `OnlyInStock` mode at scan start and use the same mode on every page.
- When the captured catalog is filtered, absence-based local product deletion is disabled.
- Local products missing from a filtered catalog are retained; explicit remote delete events remain authoritative.
- Legacy/in-progress deep-scan states that do not contain proof of an unfiltered catalog fail safe and do not perform absence-based product deletion.
- Ambiguous single-product `data: []` responses also retain an existing product while `OnlyInStock` is enabled.

The destructive behavior originated with Adaptive Reconciliation / Deep Integrity in 10.31.80: the deep catalog reused `get-products`, which honors `OnlyInStock`, while the sweep treated every unseen mapped product as remotely missing.

### Additional audit fixes included at this checkpoint

- Category creation/repair now requires durable identity metadata and Category Map persistence before a term is marked complete.
- Legacy Product/Variation/Category map seeding is resumable and stops its cursor on failed durable writes.
- Migration schema postconditions validate correctness-critical indexes before advancing the DB version.
- Product Map prefix-index collisions fail closed instead of mutating a different full GUID mapping.
- Reconciliation health rows must persist before mirrored product health metadata is updated.
- Webhook event status transitions return durable CAS results; stale claims are not counted as successfully committed.
- File-backed webhook completion gains terminal handling so an already-executed side effect is not replayed merely because active-file cleanup failed.
- Payment uncertainty can be fenced outside MySQL so an ambiguous wallet-payment outcome is not automatically retried after a simultaneous DB write failure.

### Recovery note for sites already affected

Install 10.33.28 first. Then temporarily disable `OnlyInStock` and run a full Sync/Repair so out-of-stock source products are returned and recreated locally. Re-enable `OnlyInStock` only after the full recovery sync is complete.
