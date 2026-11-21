# Mobo Core 10.33.80 — Controlled Custom-Only Cutover

- `custom-only` can become the effective runtime mode only through Custom-Only Gate v2.
- Gate v2 requires fresh real Custom-Primary usage evidence and invalidates v1 readiness after upgrade.
- Healthy identity reads trust Custom Storage mapping plus Woo post type/status/parent existence, without retained Mobo identity postmeta as an authority guard.
- Exact operational-state reads in Custom-Only resolve Custom rows by Woo object ID, preserving schema-v3 presence/value fidelity.
- A real Custom miss/invalid row takes one emergency Legacy fallback, records the primary fallback, blocks further Custom reads in the request, resets the effective-mode cache, and causes following requests to degrade from Custom-Only until readiness is re-earned.
- Custom write failures likewise invalidate the cached mode immediately.
- REST operational metadata and in-request Legacy mutation scopes remain explicit Legacy compatibility escape hatches.
- No automatic change is made to `mobo_core_storage_mode`; operators must explicitly request `custom-only`.
