# Mobo Core 10.34.26 — UX63 cumulative UX62+UX63 full-pipeline audit

Date: 2026-09-30. Candidate artifact; **NOT** a production/runtime release gate.

Baseline: Mobo Core 10.34.24 UX61. Internal UX62 checkpoint: 10.34.25; UX63 contains both tranches. No new custom schema, mass Unicode conversion, forced customer-data purge, Provider hard-code, or public .NET Swagger model rename. Existing UX51 r8.3.177 frozen suite was not touched.

## UX62 — REST/API security and Multi-Connection authority

- Fix invalid webhook-credential diagnostics that invoked a private normalizer and could throw a PHP fatal. Reject overlong WebHookId rather than turning it into another valid identity or silently clearing it during an admin edit.
- Authenticate inbound requests using fresh, binary-exact enabled WebHookId row and final fresh owner check; bypass stale request caches. For legacy no-discriminator mode require one enabled Default; unknown explicit IDs never fall back.
- Resolve signed provisioning Token with an uncached binary-equality query. Reject ambiguous legacy duplicate owners.
- Clear request Connection Context before each new REST authentication; require fresh active Default role on installation-global endpoint permission.
- Server provisioning atomically compares current immutable Source/Provider and Token while updating route and status; enforce non-disableable Default; verify final route readback.
- Expired nonce cleanup uses exact old-byte MySQL CAS; invalidate option caches before atomic add. Concurrent nonce reservation cannot be deleted by stale expiry observation.
- Suppress historical raw failed-upgrade errors in public REST/Health and use stable new stage/manifest diagnostics.

## UX63 — Installation/Upgrade/Rollback/Uninstall

- Interrupted upgrade: if active plugin files were touched and backup restore throws or does not return verified true, persist a manual recovery hold. Workers and Unicode mutation remain blocked after finite barrier expiry. Existing signed updater/barrier locks can perform verified recovery.
- Only a successfully installed, verified target version/manifest/fingerprint can retire an earlier recovery hold; require option readback. Do not kick workers under hold.
- Barrier diagnostics carry token SHA-256, not the token. Old release state is only deleted by exact serialized-source SQL CAS after owner token verification; read-only expired-barrier checks do not delete competing owner state.
- Uninstall preserves UX61 transitional Order/Parent importer options, ambiguous remote purchase recovery markers, current runtime leases and any unresolved manual upgrade hold/status. Expired lock retirement uses exact byte CAS. Custom business tables remain preserved.
- Failure status/manifest diagnostics no longer include raw untrusted exception/path strings; public reading filters historical persisted errors.

## Validation performed

- Isolated PHP source/mock checks, including credential/Token rotation, two-owner semantics, rejected HMAC replay and expired nonce CAS, competing signed provisioning bind, persistent recovery lock blocking, barrier-state CAS and preservation of uninstall evidence.
- Full plugin PHP syntax lint and self-consistent SHA-256 package manifest are mandatory build checks.
- Real WordPress/WooCommerce/Portal/WAMP+MySQL faults, concurrent processes, installation/rollback integrity and HPOS live tests **remain open** in backlog BF and BG. UX65 retains atomic disable/drain, media/order cross-pipeline issues and external Wallet idempotency limitations.

Operational caution: Do not install this candidate on customer sites without a database/filesystem backup and a disposable-site validation. If a failed rollback leaves `mobo_core_upgrade_manual_recovery_hold`, inspect installation integrity and use a fully verified signed upgrade or operator-controlled filesystem recovery; never delete it solely because a lease expired.
