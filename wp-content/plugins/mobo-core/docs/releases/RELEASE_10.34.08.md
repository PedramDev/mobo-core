# MoboCore 10.34.08 — Authoritative Legacy snapshot bypass after identity fence

## Why

Terminal identity retirement installs a read filter that makes managed Product/Variation identity metadata Custom-only after the fence. Storage Bridge migration snapshots already bypassed the general Custom-primary reader, but they did not bypass the terminal identity-retirement filter. During parent-first materialization this could make a raw Legacy Portal Product ID appear absent after the new Product State row had been bound only by GUID.

## Change

Identity retirement now exposes a depth-counted, scoped Legacy-read bypass. Storage Bridge enters both the existing Custom-primary bypass and the identity-retirement bypass while reading authoritative Legacy postmeta or checking its presence, and restores both in `finally` blocks.

## Safety

- normal runtime identity reads remain Custom-only after the terminal fence;
- writes and deletes are not bypassed;
- the bypass is scoped to Storage Bridge Legacy snapshots only;
- nested calls are safe through a depth counter;
- exception paths restore both bypass guards.
