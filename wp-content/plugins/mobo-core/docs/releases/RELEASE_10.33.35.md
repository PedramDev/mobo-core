# Mobo Core 10.33.35 — Historical variation integrity self-heal

- Remote ContentHash no-op now requires the local WooCommerce product to exist and variable children to contain the full parent variation-attribute key set.
- Product Recovery repairs missing/drifted products by exact GUID; the one-time 10.33.35 local-ledger re-audit exact-refetches every previously imported local identity once for full historical convergence.
- Upgrade from any pre-10.33.35 version schedules one bounded site-scoped integrity re-audit.
- Partial concrete variant payloads are rejected before child creation/update; diagnostic parent meta is persisted and reconciliation is marked behind.
- Designed to pair with Portal v67's deterministic parent-aware variant attribute parser.
