# MoboCore 10.34.13 — Source Registry and Connection Settings Foundation

## Scope

This release continues the fail-closed Multi-Connection migration. It does not enable a second active Connection and does not redesign License handling.

## Source Registry

Connections now carry `source_key`. Existing installations are assigned `provisioned-source`. Backend URL resolution for the built-in source is centralized in `Mobo_Core_Source_Registry`; `api_base_url` stays on the Connection row only as migration/rollback compatibility data and is not identity or a uniqueness constraint. Once a durable Connection exists, an unknown/unregistered `source_key` fails closed instead of falling back to provisioned-source or a stale stored URL. `MOBO_API_BASE_URL`, when explicitly defined by an environment, remains a developer override for the built-in source.

## Connection settings storage

Storage Schema v9 adds `mobo_connection_settings`, keyed by `(connection_id, setting_key)`. Source-dependent Product Update, Pricing, Category, Filter, missing-variant, and category-refresh policy settings are copied idempotently from the legacy options into Default Connection. Retry never overwrites an already-migrated Connection setting.

Until the later runtime cutover, the existing single-source admin UI remains authoritative for the only enabled Connection and mirrors saved values to Default Connection. This is transitional migration behavior, not a permanent dual runtime.

## Safety boundary

The 10.34.12 multiple-enabled-Connections guard remains in place. Product/Variation/Category ownership, operational state, Repair/Cleanup, queues, and order routing are not yet safe for two active sources.

## License

No License architecture, Public Key, credential lifecycle, or License UI changes are included.
