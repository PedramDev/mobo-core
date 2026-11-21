# Mobo Core 10.34.54 — REL01-CONVERGENCE-RESIDUE

Pre-release corrective candidate after the r8.3.181 Plugin Gate. Portal/.NET is unchanged.

## Evidence that triggered this build

The 10.34.53 deterministic Plugin Gate finished with healthy post-run state but found the same 927 current Product GUIDs on both WAMP sites with `mobo_sync_incomplete=1` and Product Map `sync_incomplete=1` while local product topology remained sane. The cohort crossed both observational Health states (`synced` and `behind`), proving that the release blocker was durable marker ownership rather than a random local database failure. One Mobo product also had two physical `_thumbnail_id` rows.

## Root cause

The historical 10.33.53 convergence-residue self-heal was intentionally conservative but not resumable:

- it considered only `sync_status=behind`;
- it selected at most 200 rows;
- it cleared its pending option after that single page.

Large catalogs could therefore retain later rows, and an otherwise-complete row whose observational Health status had already returned to `synced` could never be considered.

## 10.34.54 change

The same strict variable-product race proof is retained: matching Product Map/Health/source hashes, matching product+variant applied revision/version, sane local topology, no active event owner, matching completed ProductUpdated/UpdateVariant generation ordering, and authoritative final variant payload/count are all still mandatory before an incomplete marker is cleared.

The scan now:

1. resumes by durable Product Map id in 200-row pages;
2. considers every incomplete mapped product with current Health/source boundary, including `synced` and `behind`;
3. keeps its pending intent until the page set is exhausted;
4. participates in the existing bounded real-Cron migration slice, so customer hosts do not depend on WP-CLI or an unbounded web request.

A separate bounded repair deduplicates `_thumbnail_id` only for current Mobo-managed products. It deduplicates only when every physical `_thumbnail_id` row has the same value; conflicting rows are left untouched for operator review. For identical rows it preserves the oldest meta ID, deletes later duplicates, and verifies one row remains.

## Safety / non-changes

- No marker is cleared from local structure alone.
- Rows that fail any proof remain incomplete and stay visible to Sync Health / release tests.
- No synthetic webhook/recovery event is invented.
- No automatic reconciliation/product-recovery worker is reintroduced.
- No Portal or .NET changes.
- Existing terminal Operational/Identity/Entity migration semantics are unchanged.
