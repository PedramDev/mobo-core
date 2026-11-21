# Mobo Core 10.34.30 — cumulative UX66 + UX67 final source-audit tranche

Date: 2026-09-30. Candidate, NOT a runtime/production release sign-off. Baseline: cumulative 10.34.28.
UX66 / 10.34.29 is an internal checkpoint. Only cumulative 10.34.30 is distributed.

## UX66: specialized Checkout/Portal authority

- Re-read the positive, enabled, provisioned durable Connection before shared-cart/Login/Mobo HTTP operations. Reject cached-versus-fresh drift in owner ID, Provider, Source, Source storefront, API base, Connection key, Token and X-SEC. Recheck near send; never adopt Default on unknown/deleted owner.
- External validation destinations matching the configured Portal origin receive freshly sourced Token and X-SEC; fingerprint is verified again after signing. Other configurable third-party destinations are never sent Portal credentials. Redirects stay disabled.
- Portal request signer reads the latest durable Token when the Connection table exists; fallback to legacy token is only for absent Connection table.
- HTTP transport errors in the specialized Checkout path now use stable codes and operation label; `requestMayHaveReachedServer=true` remains for potentially sent requests and ambiguous Wallet recovery remains manual.

## UX67: specialized diagnostics / secrets inventory

- Narrowly remove raw upstream Exception/WP_Error text from selected persistent status, Webhook, storage, maintenance, health/cPanel, cache, image and image refresh, shipping diagnostics, SMS notes/errors, Self Runner, reprice, recategorization, upgrade and worker catches.
- Store stable generic operation messages, optional bounded sanitized WP error *code* and exception *class*. Do not include file/line or source/private URL where patched.
- Retain the existing SMS one-shot fence and uncertain gateway handling; **no automatic SMS resend** on ambiguous outcome. No change to remote Wallet retry semantics.

## Verification and explicit exclusions

- New isolated PHP tests check owner freshness, Token rotation, disabled/missing owner, signer, HTTP error redaction/uncertainty, external Portal origin matching and source-level diagnostic contracts. The four UX64/UX65 test harnesses are replayed on the new plugin tree.
- No new DDL, table changes, forced migration/Unicode conversion, legacy re-adoption, data deletion, multilingual/multicurrency flow or Portal Swagger model rename.
- The source-level checks do NOT establish atomic admin disable versus in-flight HTTP, real dual-process MySQL/Woo/HPOS ordering, Portal payment idempotency, third-party transport hooks, previous persisted raw logs, or 30K per Source throughput. These remain open in backlog BJ/BK and prior BH/BI.
- Frozen UX51 Deep Test Suite r8.3.177 is not changed, re-pinned or claimed PASS. A newly pinned test suite and separate full runtime gate are required.
