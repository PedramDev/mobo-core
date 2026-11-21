# MoboCore Database Charset / Full-Unicode Policy

Status: **Readiness/enforcement implemented in UX41; explicit locked/checkpointed conversion workflow implemented in UX42–UX46.**

This document records the architectural decision and its current implementation state. UX41 adds fail-closed readiness checks, utf8mb4-only Mobo schema creation, Site Health diagnostics and an optional real 4-byte round-trip probe. It deliberately does **not** perform broad conversion during activation, Health or ordinary runtime. UX42–UX46 add a separate administrator-started migration that converts the active WordPress site-prefix database surface one protected DDL boundary at a time.

## 1. Minimum supported charset

MoboCore requires a **full-Unicode / 4-byte UTF-8 capable database path**.

Current concrete minimum: **`utf8mb4`**.

The following must be treated as unsupported for MoboCore runtime readiness:

- `utf8mb3`
- MySQL/MariaDB legacy `utf8` when it resolves to the 3-byte charset
- any 1/2/3-byte or non-Unicode charset that cannot round-trip supplementary Unicode code points

The policy is semantic, not a fragile string equality check: a future WordPress/MySQL/MariaDB-supported charset with capabilities equal to or greater than full 4-byte Unicode may be accepted after an explicit compatibility decision and tests. Today, `utf8mb4` is the supported target.

## 2. What must be checked

A single `DB_CHARSET` check is insufficient. Readiness must eventually prove all relevant layers:

1. **WordPress configuration** — `DB_CHARSET` must resolve to a full-Unicode charset. `utf8mb3`/legacy 3-byte `utf8` is not acceptable.
2. **Live database connection** — the effective `$wpdb` client/connection character set must be full-Unicode; utf8mb4 tables alone do not protect a 3-byte connection.
3. **Database default** — the selected WordPress database default charset/collation must be full-Unicode so newly created objects do not silently regress.
4. **MoboCore-owned tables/columns** — every textual MoboCore custom table/column must actually be utf8mb4-or-better, regardless of what `wp-config.php` currently says.
5. **WordPress/WooCommerce tables MoboCore writes text into** — relevant core/Woo tables used for product/category/order/meta text must also be capable of the same characters. A utf8mb4 Mobo table does not solve failures when the destination `wp_posts`, term/meta, or Woo storage is still 3-byte.
6. **Round-trip proof** — a real 4-byte Unicode value must survive write/read through representative Mobo storage paths without truncation, replacement, SQL error, or lossy normalization.

For the explicit conversion operation, the physical scope is the active WordPress site's exact table prefix: every base table under that prefix is brought to utf8mb4 so the installation cannot retain a hidden 3-byte table. Tables outside the active prefix are never altered. Runtime readiness may still focus its detailed diagnostics on Mobo/WordPress/WooCommerce paths, but terminal migration completion performs a fresh prefix-wide physical plan.

## 3. Where the requirement should surface

Recommended implementation points:

- **Storage/schema readiness:** charset incompatibility is a hard readiness blocker, not a warning that allows normal mutation to continue.
- **Site Health:** show a manager-readable `Database Unicode compatibility` state and the exact failing layer (configuration, connection, database default, table/column).
- **Upgrade/migration preflight:** schema migrations must refuse to call an utf8mb3 state healthy and must not create new Mobo tables by blindly inheriting a 3-byte `$wpdb->get_charset_collate()` result.
- **Release Gate:** permanent tests must include an intentionally utf8mb3 fixture and a successful utf8mb4 fixture, plus real 4-byte round-trip data.

## 4. Conversion strategy decision

### Decision: conversion is implemented, but never as a silent whole-database side effect

MoboCore should **not** silently run broad `ALTER TABLE ... CONVERT TO CHARACTER SET` operations during ordinary activation, page load, or routine plugin update. Such DDL can lock large tables, expand indexes, exceed hosting limits, and affect tables owned by WordPress/WooCommerce/other plugins.

The implemented design is an explicit **Database Unicode Upgrade** maintenance operation with:

- a clear backup requirement before conversion;
- server/collation/index-capability preflight;
- a deterministic scope of MoboCore tables plus WordPress/WooCommerce tables that MoboCore actually needs to write full-Unicode text into;
- maintenance/operation locking so Sync/Repair/Webhook/Image/Order mutation cannot race the conversion;
- durable checkpoints and idempotent re-entry, because DDL cannot be assumed transactional;
- per-table verification after conversion;
- final connection/database/table/column re-audit and a 4-byte round-trip proof before readiness becomes green;
- explicit failure reporting that leaves the site in `conversion incomplete / MoboCore not ready` rather than pretending the migration succeeded.

A manual SQL/hosting-panel path may remain as a fallback, but the product should not rely solely on WP-CLI because supported customer hosting may not provide WP-CLI.

## 5. Collation policy

Do not hard-code a MySQL-8-only collation such as `utf8mb4_0900_ai_ci` for all environments. The target charset is the invariant; the exact `utf8mb4_*` collation must be chosen from collations supported by the live MySQL/MariaDB server and remain compatible with WordPress/WooCommerce expectations.

`DB_COLLATE=''` can be acceptable when WordPress/server resolution still produces a supported utf8mb4 collation. An explicitly configured 3-byte collation is not acceptable.

Existing MoboCore index-prefix compatibility work for older utf8mb4 InnoDB key limits must remain intact during conversion.

## 6. Fail-closed behavior

The enforced conversion/readiness contract is:

- Detect incompatibility early.
- Explain exactly what must be changed.
- Do not silently truncate/strip/replace 4-byte characters to make an utf8mb3 database appear compatible.
- Do not mark schema/database health green while any Mobo-required storage path is known to be 3-byte.
- Avoid partial schema mutation after a preflight already proves that the required full-Unicode storage contract cannot be satisfied.

## 7. Remaining validation and operational tuning

- Keep runtime-required WordPress/WooCommerce table diagnostics current as classic order storage and HPOS evolve; the physical conversion itself remains active-prefix-wide.
- Validate active-prefix-wide conversion against large classic Woo and HPOS fixtures, including third-party prefixed tables.
- How hosting-specific DDL timeouts/online-DDL capabilities are detected and surfaced.
- Validate Site Health Start/Resume/Pause controls and recovery messaging across supported WordPress admin layouts.
- Exact rollback/recovery instructions for a partially converted database (DDL rollback cannot be assumed).
- How to estimate required free disk space before rebuilding large tables/indexes.

Permanent regression debt for this policy is tracked in `docs/internal/admin-ux-test-backlog.md` under `UX-DBCHAR-*`.
## 8. UX41 implementation baseline

The following parts are now implemented and must remain permanent regression contracts:

- `Mobo_Core_Database_Charset` is the single runtime authority for Unicode readiness and Mobo-owned schema charset clauses.
- New/updated Mobo-owned tables request `utf8mb4` explicitly and never fall back to an inherited `utf8`/`utf8mb3` `$wpdb->get_charset_collate()` clause.
- Runtime readiness audits `DB_CHARSET`/`DB_COLLATE`, effective `$wpdb` charset/collation, live client/connection/results charset variables, database defaults, Mobo-owned textual columns, and required WordPress/WooCommerce textual columns.
- Storage/schema readiness fails closed when required Mobo custom-storage tables are not full-Unicode.
- Site Health exposes `databaseUnicode` and keeps `databaseSchemaReady=false` while any required Unicode layer is incompatible.
- A temporary-table supplementary-Unicode write/read probe exists for explicit maintenance/release verification without leaving persistent probe data.
- No broad `ALTER TABLE ... CONVERT` is executed from activation, page load, routine update, Site Health, or schema validation.

The explicit, locked and checkpointed **Database Unicode Upgrade** workflow is implemented in UX42–UX46. Remaining release debt is to exercise the recorded contracts against permanent live MySQL/MariaDB fixtures, including intentionally failing utf8mb3/index-limit cases and passing utf8mb4 round-trip cases.



## 9. UX42–UX46 explicit remediation workflow

- First start is administrator-only and nonce protected; routine Health/Cron never auto-start conversion.
- A deterministic plan covers the selected database default and current active WordPress site-prefix base tables that are below utf8mb4.
- The global runtime barrier drains Mobo workers before conversion. A dedicated step lease prevents two migration requests from running DDL concurrently.
- Before DDL, a bounded conservative index-width preflight rejects keys that are provably too wide under utf8mb4 for the detected engine/row-format key limit.
- After the exclusive DDL step lease is acquired, durable migration state and the barrier/drain boundary are checked again, closing the concurrent Pause/barrier-expiry race before SQL.
- Each request executes at most one DDL boundary, verifies the result, then persists the checkpoint. Retries verify before ALTER so a lost HTTP/PHP response does not require blind duplicate conversion.
- Cron may continue exactly one step only while an administrator-started run is active.
- Pause during an active DDL is a request, not an immediate barrier release; it becomes effective only after that DDL has verified and checkpointed.
- Before terminal completion the planner runs again to catch newly-created current-prefix tables, then the existing real 4-byte Unicode round-trip must pass.
- `wp-config.php` is never modified. If physical conversion is complete but DB_CHARSET/wpdb connection remains legacy, the workflow releases the barrier and waits in `awaiting-config` for the operator to switch to utf8mb4 and re-check.

## Textual foreign keys during explicit conversion (UX50)

The explicit utf8mb4 planner performs a bounded foreign-key preflight before any conversion DDL. `ALTER TABLE ... CONVERT TO CHARACTER SET` changes character-column definitions and MySQL requires compatible definitions on both sides of a foreign key. Therefore a text FK whose planned child or parent column would change charset/collation is a preflight blocker rather than a mid-migration surprise.

Mobo Core does not silently drop or recreate third-party foreign keys. The operator must resolve that schema relationship explicitly, then Resume/Re-check so the planner can rebuild the remaining physical plan. Integer/binary foreign keys are not blocked by this character-set check.
