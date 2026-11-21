# Mobo Core 10.34.57 — HARDEN03 Network / Dependency Resilience

- Adds stable dependency-failure classification for DNS, connect, TLS/certificate, timeout, authentication, access denial, endpoint unavailable, rate limiting, 5xx and invalid responses.
- Persists bounded per-Connection dependency backoff in existing runtime storage and exposes it through Health.
- Portal API GET/POST and Provider storefront paths fail closed with sanitized failure metadata; no raw remote body/exception is stored.
- Provider financial/storefront operations are not automatically replayed after ambiguous transport failures.
- Backend uses the same bounded network taxonomy, honors bounded Retry-After, limits automatic retries to idempotent methods, and disables implicit HTTPS→HTTP downgrade by default.
- Provisioning and webhook fallback, when explicitly enabled, is limited to connection-refused evidence rather than TLS/timeout/ambiguous transport failures.

Phase 3 source/static implementation is complete; Windows/.NET and WAMP runtime integration evidence is required before Phase 3 is closed.
