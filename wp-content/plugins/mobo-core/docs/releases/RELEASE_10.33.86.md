# Mobo Core 10.33.86 — Commit-Group Source-Hash Suppression

This release extends controlled operational-postmeta suppression to:

- `_mobo_product_source_hash`
- `_mobo_variant_source_hash`

The four revision watermark keys remain supported. Hash suppression is stricter than
revision suppression: the retained Legacy revision/presence vector and
`mobo_sync_incomplete` presence/value must exactly match the Custom row before a
source hash can be committed Custom-first.

A hash write is suppressible only when all of the following hold:

1. storage mode is effective `custom-only`;
2. the exact hash key is explicitly opted in;
3. per-key readiness policy v3 grants a fresh lease;
4. the object has no prior Legacy mutation/pending mirror in the request;
5. the Custom identity row is valid;
6. the exact retained-Legacy revision/presence/incomplete commit group matches Custom;
7. the Custom hash CAS succeeds.

Any mismatch or Custom failure restores the normal Legacy postmeta write immediately
and blocks further suppression for the request.

Still Legacy-authoritative in 10.33.86:

- `mobo_sync_incomplete`
- REST metadata mutations
- add/delete metadata paths
- non-opted-in operational keys

No existing Legacy data is deleted.
