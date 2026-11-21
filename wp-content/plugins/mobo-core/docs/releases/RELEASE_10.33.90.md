# Mobo Core 10.33.90 — Real-Cron Storage-Reconciliation Aggregation Fix

Release 10.33.90 fixes a production Real-Cron crash discovered by the full Release Gate.

`Mobo_Core_Cron_Runner::merge_queue_counters()` requires four arguments. The
`storageReconciliation` aggregate call still passed only three arguments,
causing an `ArgumentCountError` whenever Real-Cron reached round aggregation.

The fix supplies an explicit empty latest-boolean-key list for
`storageReconciliation`. Its merged fields are numeric/status counters, so no
latest-boolean carry-forward keys are required.

No Portal change is required. The seven-key Custom Storage logical-retirement
policy and retained Legacy rollback compatibility remain unchanged.
