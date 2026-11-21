# MoboCore 10.34.01 — Unmapped operational-state reconciliation

This release hardens the terminal operational-postmeta migration against legacy Product/Variation objects that still carry Mobo operational metadata but no longer have a Product Map row.

- Adds a bounded pre-proof reconciliation pass for unmapped operational metadata.
- Safely reconstructs Product Map rows only when Product/Variation identity is valid, unique and conflict-free.
- Includes trashed Mobo objects so Repair remains able to recover them after physical postmeta retirement.
- Removes operational residue from auto-drafts and from trashed objects only when no durable Mobo identity can be proven.
- Keeps live missing/conflicting/duplicate identity fail-closed and exposes explicit blocker codes.
- Newly reconciled map rows are fed back through the exact Custom Storage proof before any terminal fence is activated.
- Shared-host constraints from 10.34.00 remain unchanged: no WP-CLI or information_schema dependency in production migration paths.
