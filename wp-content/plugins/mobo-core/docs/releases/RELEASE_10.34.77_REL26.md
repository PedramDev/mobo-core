# Mobo Core 10.34.77 — REL26 Final Hardening

Final cumulative pre-publish candidate.

Included release-hardening changes:
- deferred post-upgrade targeted convergence bootstrap/liveness;
- same-site Self Runner TLS policy via WordPress `https_local_ssl_verify`;
- explicit runtime `MOBO_API_BASE_URL` precedence without mutating provisioned Connection data;
- scheme-authoritative HTTP/HTTPS Backend provisioning (`http://mobo.codeya.ir/` is valid);
- endpoint-scoped network resilience state by scheme/host/port;
- network backoff deferral without artificial retry-budget consumption or repeated full target rescans;
- Exact Convergence retry-state reset after a valid API response;
- bounded 1000-target rolling recovery windows with global ownership and bounded unresolved carry;
- clean completion/no-rebirth behavior after targeted recovery;
- refreshed exact release manifest generated from final packaged bytes.

External outbound authentication, Provider routing, monetary contract, and existing storage/migration safety boundaries remain unchanged.
