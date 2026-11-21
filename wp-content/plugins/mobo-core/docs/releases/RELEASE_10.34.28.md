# Mobo Core 10.34.28 — UX65 cumulative UX64+UX65 full-pipeline audit

Date: 2026-09-30. Candidate artifact, **not** production/real two-site sign-off.

Baseline: 10.34.26 cumulative UX62/UX63. UX64 10.34.27 is an internal checkpoint and only UX65 10.34.28 is distributed. Existing UX51 r8.3.177 frozen test Suite and published Swagger models were not modified. No new DDL, forced purge, automatic Wallet retry or multilingual/multicurrency behavior.

## UX64 — Installation-global identity and Custom Storage revision

- Serialize Product Map Woo object owner selection/commit across Sources using the existing runtime lease machinery plus a DB-wide deterministic named lock. Legacy Default-only map seed joins the same guard; fail closed on owner SELECT failure/lock unavailable, release only acquired lock.
- Make Product Map identity comparisons binary-exact, particularly for collation/prefix collisions. Readback remains mandatory.
- Harden Product/Variant `mark_desired()` against stale/equal-conflicting revision/hash and `mark_applied()` against stale or actively claimed generation; verify readback. Initial Variation mirror identity assignments now respect monotone seen/applied watermark.
- No schema change. Custom Storage already has global unique Woo ID keys; full two-process cross-table identity remains an outstanding runtime contract.

## UX65 — Connection route authority and cross-pipeline admission

- General API Client forms request context from a freshly resolved provisioned enabled owner; verify route/credential digest before every GET/POST send including redirect hops. Refuse disabled/rotated/reprovisioned routes without falling back to Default.
- Admin Connection metadata update compares all routing/credential/enable preimage fields in SQL, checks final readback, reports conflicting edits; Token rotation uses installation-global named lock and binary owner lookup. Signed provisioning binds previous route/enable/security fields in CAS. Both admin and signed provisioning refuse new mutations under upgrade/Unicode maintenance.
- Redact untrusted upstream GET/POST transport and HTTP-body strings in generic API Client WP_Error surfaces while preserving stable code and numeric status needed for endpoint discovery.

## Local validation and outstanding risks

- Four isolated PHP harnesses (42 passing checks in the accompanying external validation report): Product Map guard, general API route/redirect/redaction, Connection Admin CAS/Token guard, Product/Variant revision/Ack readback and structural checks.
- All plugin PHP files must pass lint; verify every SHA-256 manifest entry against packed bytes and ZIP single-root integrity.
- **Not tested:** live MySQL lock and ON DUPLICATE behavior, WP object-cache, two-process lease expiry/reconnect, Portal in-flight side effects, Wallet exactly-once, HPOS, UX51 frozen/re-pinned full gate or 30K+30K performance.
- Remaining systemic UX65 contracts are enumerated in `docs/internal/full-plugin-pipeline-audit.md` and backlog BH/BI. Failed/unverified remote payments remain manual-recovery only, never automatic replay.

Do not deploy this candidate to customer stores without a separate backed-up disposable-site validation and explicit runtime gate.
