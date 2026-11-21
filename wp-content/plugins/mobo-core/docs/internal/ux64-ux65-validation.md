# UX64+UX65 validation scope — 10.34.28 cumulative candidate

- Isolated harness source: external audit workspace, **not** shipped inside the production plugin.
- Isolated suites: Product Map Woo owner named/runtime lock; general API fresh GET/redirect/GET+POST redaction; Admin Connection preimage CAS/Token lock; Product/Variant desired/Ack readback and static SQL fences.
- 42 local assertions passed. These harnesses are *fake DB/HTTP* (only code shape and readback/ordering evidence), not genuine WordPress, WooCommerce, Portal or MySQL runtime tests.
- Full plugin PHP lint, complete SHA-256 manifest cross-check and ZIP archive check reported separately in build validation output.
- Open contracts: backlog BH/BI; especially actual two-process GET_LOCK/lease expiry, utf8mb4 binary SQL, in-flight HTTP disable, Portal Wallet idempotency, upgrade/Unicode draining, Classic/HPOS and 60K soak.
