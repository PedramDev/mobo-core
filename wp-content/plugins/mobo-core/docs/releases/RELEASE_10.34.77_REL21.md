# MoboCore 10.34.77 REL21 — Runtime API Base Developer Override

## Scope

Restores the documented runtime precedence of the explicit `MOBO_API_BASE_URL` environment/developer override after durable Connection provisioning.

- The provisioned `api_base_url` remains stored unchanged on the Connection.
- When `MOBO_API_BASE_URL` is defined and non-empty, outbound runtime routing uses that URL.
- API route freshness and checkout Portal-origin validation use the same effective resolver, preventing mixed production/development routing.
- When the constant is absent, durable Connection routing is unchanged.
