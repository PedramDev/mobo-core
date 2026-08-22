# Mobo Core 10.33.33 — Deep orchestration/test hardening

- Image Refresh, Product Recovery and post-Recovery Cache Warmup share the same atomic site-wide mutation pipeline lease.
- Product Recovery has priority over Image Refresh; autonomous image work defers instead of overlapping recovery.
- Activation captures the pre-upgrade DB version before stamping the new build, so the original 10.33.29 parent-product Recovery cannot be skipped by deactivate/replace/activate upgrades.
- A one-time 10.33.33 Recovery Re-Audit covers installations from 10.33.29 through 10.33.32, including sites that may already have missed the original recovery schedule; it is idempotently armed once.
- Test-suite source assertions were corrected to avoid PHP interpolation false failures.
- Cross-process lock and dispatcher tests now use explicit ready/ack handshakes instead of timing assumptions.
- `run-all.ps1` performs Core + Fault Injection + Strict Audits + cross-process Concurrency on both standard local sites with one command.
