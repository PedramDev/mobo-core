# Mobo Core 10.33.93 — Strict Large-Runtime Storage Cutover

## Scope

This release closes the cutover gap left by 10.33.92 for large/high-churn runtime state. Storage Schema v4 is unchanged. The change is about migration completion proof and runtime authority, not another DDL expansion.

## Cutover invariant

`mobo_core_db_version` is stamped to `10.33.93` only after:

- all required custom runtime tables are available;
- the 10.33.92 copy/verify/delete migration succeeds;
- temporary order-recovery bridge rows are drained into `mobo_work_queue`;
- every retired fixed heavy option is absent; and
- every retired prefixed option (`sync-health last-good`, order recovery, and Seen-Variant generations) has zero residue.

Prefix migrations remain bounded. If one request migrates only a batch, the DB version intentionally remains old and the next request resumes the migration.

Destination verification is also fail-closed: State/Queue read-back database errors prevent legacy retirement, Checkout debug-event migration verifies the migrated newest-first window, and Seen-Variant migration publishes its authoritative scope marker only after exact row verification.

## Runtime authority after cutover

Before the strict cutover is complete, legacy heavy options can still be used as upgrade fallback if a custom store is unavailable. Once `mobo_core_db_version >= 10.33.93`, those options are no longer a runtime read/write fallback. A missing custom table therefore fails closed instead of recreating a second authoritative state.

## Seen-Variant finalization fix

Variation finalization now reads the authoritative Seen-Variant scope through `Mobo_Core_Seen_Variants_Store::load_set()`. This fixes the case where 10.33.92 migrated and deleted `mobo_seen_variants_*` but `finalize_missing_variants()` still attempted to read that legacy option and blocked destructive finalization as “checkpoint missing”.

## Intentionally not part of this release

This is not a blanket removal of every WordPress option. Small configuration, feature flags, version markers and bounded control records remain in `wp_options` where appropriate. Product/Variation operational postmeta retirement also remains governed by the existing Custom Storage readiness/cutover machinery and is a separate phase.
