# MoboCore 10.34.06 — Entity Meta migration readiness on current Storage Schema

## Why

After Operational and Identity terminal retirement completed with zero physical residue, the remaining Entity Meta retirement stayed forever in its default `pending` state. The Entity Meta worker still required `mobo_core_storage_schema_version` to equal the historical literal `6`, while the current validated Storage Schema is version `7`. Because `run_migration_step()` failed its readiness prerequisite before loading/persisting migration state, no copy cursor or residue proof could start and convergence timed out with `entityMetaResidue=null`.

## Change

`Mobo_Core_Storage_Entity_Meta_Retirement::storage_ready()` no longer duplicates an obsolete schema-generation check. It now relies on `Mobo_Core_Storage_Schema::is_ready()` plus a resolved `entity_meta` table name. `Storage_Schema::is_ready()` already requires the current schema version and validates all required tables, columns and correctness-critical indexes, including `mobo_entity_meta`.

## Safety

- the Entity Meta worker still does not start when the current Storage Schema is unready;
- no schema migration, GUID synthesis or metadata bypass is introduced;
- the entity-meta table remains mandatory and is validated by the central schema contract;
- later compatible schema generations do not deadlock this terminal migration merely because their version number is newer than the generation that introduced the table.
