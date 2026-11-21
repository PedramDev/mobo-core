# Mobo Core 10.33.78 — Guarded Custom-Primary Operational State Read

## Scope

This release promotes exact Product/Variation operational-state reads to Custom Storage only when `Mobo_Core_Storage_Promotion_Gate` v5 has a fresh eligible lease. Legacy WordPress/WooCommerce persistence remains the authoritative write path and the mandatory fallback. `custom-only` is still capability-capped to Custom-Primary with Legacy fallback.

## Schema v3 presence fidelity

Storage schema v3 keeps the lossless v2 Product/Variation watermarks and adds explicit metadata-presence flags. `mobo_sync_incomplete` has a dedicated exact-meta value plus presence flag, separate from the aggregate scheduling/status columns. Custom Storage can therefore represent all three Legacy states without collapsing semantics: metadata absent, explicit `0`, and explicit `1`. Revision/hash fields also carry presence flags so an absent Legacy key is not confused with a numeric zero or empty hash.

## Read contract

The direct `get_post_meta()` rollout boundary covers Product/Variation seen/applied revision watermarks, source hashes, and `mobo_sync_incomplete`. A Custom value may be returned only after the promotion lease, retained Woo identity, post type/status, parent identity for Variations, Custom row identity, exact field presence and typed-value validation all pass.

Present Custom values are returned through the WordPress metadata filter in a one-value array. WordPress unwraps that shape for single-value reads while `metadata_exists()` still observes a truthy short-circuit even when the stored value is the explicit string `0`. When Custom says the exact meta key is absent, the read deliberately falls through to Legacy instead of fabricating a value; this preserves WordPress absence/default behavior without counting drift.

## Authoritative-write safety

Operational metadata writes remain Legacy-first. Before WordPress performs add/update/delete metadata write decisions for Mobo operational keys, the object is pinned to Legacy reads for the remainder of the request. This prevents a Custom mirror value from changing WordPress no-op/old-value decisions and also preserves Legacy read-after-write semantics.

The Metadata Commit Bridge remains buffered and bounded. While an object is pending or the bridge is flushing a canonical Legacy snapshot, Custom state reads are bypassed without counting a fallback. Canonical Legacy value reads and presence checks use an explicit Custom-state bypass.

## REST boundary

Operational State Custom-Primary is intentionally disabled for REST requests in 10.33.78. WordPress REST meta controllers perform duplicate/delete preflight reads before the generic metadata mutation hooks can pin the object to Legacy. Keeping REST state reads Legacy-primary prevents a Custom mirror from influencing an authoritative REST write decision. Identity Custom-Primary behavior is unchanged.

## Failure behavior

A real Custom row miss, invalid field/value, parent mismatch or identity mismatch returns control to Legacy and records the fallback through the shared `Mobo_Core_Storage_Primary_Read` request circuit breaker. Further Custom-Primary reads in that request stop and durable fallback telemetry invalidates the stale promotion lease. Expected absence, in-request write/mirror scopes, REST requests and unmanaged objects are safe bypasses rather than drift.

## Promotion safety

Gate policy is v5, invalidating older leases. Promotion remains manual/requested-mode only; the gate never writes `mobo_core_storage_mode`. State parity uses the v3 evidence namespace so pre-presence-fidelity evidence is not reused.
