# Mobo Core 10.34.40 — PERF09 + PERF10 (WordPress-only cross-source performance candidate)

2026-09-30. Baseline `10.34.38` already contains PERF01–PERF08. Portal/.NET source `Portal-20260930-224009` was inspected **read-only** to verify existing API semantics. No .NET file, route, schema, contract or deployment artifact is modified by this release. `10.34.39` is an internal checkpoint; the distributed candidate is cumulative PERF01–PERF10 at `10.34.40`. The installed `10.34.30` / r8.3.179 Plugin Gate remains a separate frozen line.

## PERF09 — lightweight Variant-count fast path for ProductUpdated topology handoff

Portal already exposes licensed `GET /{productId}/get-variants-count`, implemented as an `AsNoTracking().Where(ProductId).LongCountAsync()` query. The plugin now has a scalar-safe client method for this existing endpoint while retaining the same fresh Connection route check, Token/X-SEC signing, redirect policy, deadline and circuit breaker used by normal GETs.

The count is used only when a `ProductUpdated` payload is variation-capable and the local parent is still `WC_Product_Simple`. A count **greater than one** is enough to prove that choosing one purchasable Variant for the Simple parent is unsafe, so the heavier `get-variants` snapshot is skipped and processing hands off to normal UpdateVariant convergence. Count `0`, count `1`, malformed response or endpoint/request failure does **not** authorize a topology mutation and falls back to the previous full snapshot path. Manual/Repair Step 5 is unchanged.

## PERF10 — reuse immutable pulled UpdateVariant payload on defer

Portal's customer-batch payload endpoint returns the stored `EventWebhook.Data` for a fixed `EventWebhook.Id`; the entity exposes `Data` with a private setter and delivery workers mutate status/try/lock fields rather than rewriting that payload. ProductUpdated already returned its normalized pulled payload to queue progress, but UpdateVariant did not.

After a successful lightweight payload pull, the UpdateVariant processor now returns that normalized payload to the queue transition. When an UpdateVariant is deferred (for example, waiting for its parent/product lock), both DB `mark_pending_progress()` and file fallback can persist the full payload. The next retry sees local `data` and does not re-fetch the same `changesUrl`. Event ordering/identity context and `_moboPulledFrom` diagnostics are retained. Failed payload pulls remain failures and are not cached as payload.

## Scope / non-claims

- WordPress/PHP only. No .NET source was changed.
- No new endpoint and no changed public API contract.
- No DDL/index/schema change.
- No payment/Wallet/order behavior change.
- No claim that count probing helps a one-Variant product; those cases intentionally fall back to the full snapshot.
- No measured throughput/latency percentage until the cumulative PERF branch is re-pinned and run on WAMP/MySQL/Portal.
