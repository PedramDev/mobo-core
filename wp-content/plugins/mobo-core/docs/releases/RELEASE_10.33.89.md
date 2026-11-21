# Mobo Core 10.33.89 — Terminal Operational-State Retirement Finalizer

10.33.89 closes the Custom Storage operational-meta migration as a **logical retirement** rather than an unsafe physical purge. The seven Product/Variation operational keys remain guarded by the 10.33.88 Custom-first suppression machinery; this release adds a terminal evidence finalizer that proves all seven keys are simultaneously requested and runtime-eligible on top of fresh Custom-Only and Legacy-retirement leases.

## Terminal state

`Mobo_Core_Storage_Retirement_Finalizer` reports `logical-retirement-complete` only when:

- Custom Storage schema is ready and effective mode is `custom-only`.
- the Custom-Only and Legacy-retirement runtime leases are fresh and eligible.
- per-key suppression readiness reports all seven keys eligible.
- all seven operational keys are explicitly requested for suppression.
- Meta Commit Bridge has no pending entity.
- request-local Primary Read and suppression breakers are closed.

The state is persisted in the Custom runtime table as `storage_retirement_finalizer_v1` and exposed in site health.

## Physical deletion policy

The finalizer **cannot delete retained `wp_postmeta` rows** and always reports `physicalDeletionAllowed=false`. This is intentional, not unfinished migration work. Three structural compatibility boundaries remain:

1. REST operational metadata reads intentionally remain Legacy-primary for safe WordPress metadata mutation semantics.
2. direct `add_post_metadata` / `delete_post_metadata` operations remain guarded Legacy mutation paths.
3. retained Legacy rows are the immediate rollback copy if a Custom-first suppression path later fails closed.

Deleting those rows while the above semantics remain would make WordPress metadata behavior surface-dependent. 10.33.89 therefore defines the safe terminal policy as `logical-retirement-with-retained-rollback-postmeta`.

## Runner evidence fix

The cron runner now carries `storageCustomOnlyGate`, `storageLegacyRetirementGate`, `storagePostmetaSuppressionReadiness`, and `storageRetirementFinalizer` into its aggregate result. Older builds executed the first three but only carried the Promotion Gate forward in the aggregate, which could hide later-stage evidence from diagnostics.

## Release boundary

No automatic suppression option is enabled by this release. Runtime eligibility remains evidence-driven and all seven keys remain explicit opt-in. Portal remains frozen at V80 CORRECTED1.
