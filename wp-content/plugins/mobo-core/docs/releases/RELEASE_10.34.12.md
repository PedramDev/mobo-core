# MoboCore 10.34.12 — Multi-Connection foundation

## Scope

This release prepares the existing single-connection plugin for the later Multi-Connection ownership migration without enabling two active sources prematurely. Existing installations are adopted as one durable `Default Connection`; legacy connection options remain mirrored and the existing single-source behavior stays intact.

## Connection identity and authentication

`X-Mobo-Webhook-Id` is treated only as the Backend↔WordPress connection discriminator. `X-SEC` remains the authentication credential. An upgraded installation initially has no stored WebHookId; the first request that supplies a WebHookId may bind it to the single Default Connection only after its X-SEC matches. Once bound, a different WebHookId is rejected.

## Durable queue ownership

`mobo_sync_events` now stores `connection_id`. Existing rows are idempotently backfilled to the Default Connection. Remote-event dedupe and safe entity-event coalescing are connection-scoped. When a queued event is processed later, MoboCore restores its owning connection context before any Portal API/payload call.

## Connection-scoped transport

The API client, Portal request signer, health reporter, image-origin validation, and remote updater resolve Backend URL/Token/X-SEC from the active connection context, with legacy settings retained only as the single-connection fallback.

## Safety boundary

This is deliberately not the release that enables two active sources. Product/Variation ownership, category ownership, Repair/cleanup/initial-sync state, and order routing are still globally keyed in 10.34.11-era storage. If more than one Connection is manually enabled, inbound connection runtime fails closed instead of risking cross-source mutation.

## Migration

Storage Schema moves from v7 to v8 and creates `mobo_connections`. The migration is idempotent and assigns pre-existing webhook events to the Default Connection. No existing connection option is deleted.

## Next stage

The next migration stage namespaces Product/Variation/Category identity and backend-dependent runtime state by `connection_id`; only after those postconditions are verified should the multiple-enabled-connections guard be removed.
