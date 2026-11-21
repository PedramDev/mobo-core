# Mobo Core 10.33.84 — Operational Postmeta Suppression Readiness

- Adds evidence-only readiness tracking for each managed operational postmeta key. This release does not suppress or delete operational postmeta.
- Tracks three bounded request-deduplicated signals per key while Custom-Only is requested: successful Custom reads, Legacy read dependencies, and retained Legacy writes.
- Persists those counters in `mobo_runtime` with at most three set-based metric writes per request.
- Adds an independent per-key readiness gate: 250 fresh Custom reads, 50 exercised Legacy writes, 72 clean hours, recent read/write evidence, zero per-key Legacy read dependencies, and a valid global Legacy Retirement lease.
- Any new Custom-primary fallback or Custom write failure resets the per-key confidence baseline globally; a Legacy read dependency resets only the affected key.
- Keeps `mobo_sync_incomplete` independent from revision/hash keys because exact absence/presence and REST write-decision compatibility remain stricter than ordinary revision/hash semantics.
- Adds runner and Site Health visibility for eligible keys. Existing Product Map no-op suppression remains separately opted-in and unchanged.
- No option is promoted automatically and no retained Legacy row is deleted.
