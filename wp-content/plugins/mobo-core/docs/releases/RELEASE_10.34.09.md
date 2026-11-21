# MoboCore 10.34.09 — Disjoint WooCommerce terminal metadata ID namespaces

## Why

The terminal compatibility bridge intentionally assigns synthetic WooCommerce metadata IDs in disjoint ranges: generic Entity Meta rows use the 4e18 range, Operational rows use 5e18, and Identity rows use 6e18. Entity Meta retirement previously treated every ID above 4e18 as one of its own virtual IDs. Because the Entity Meta Woo read bridge runs after the specialized typed bridge, it could remove valid Operational and Identity rows that had just been injected.

## Change

Entity Meta virtual IDs are now bounded to a dedicated one-quintillion-wide namespace: IDs must be greater than 4e18 and strictly below 5e18. The virtual-ID encoder also refuses custom IDs outside that range. Operational and Identity synthetic IDs remain in their existing 5e18 and 6e18 namespaces.

## Safety

- no storage schema or migration state changes;
- no physical postmeta is recreated;
- generic Entity Meta by-ID routing remains unchanged inside its reserved range;
- specialized typed rows survive the priority-20 Entity Meta bridge;
- Custom-only terminal authority and existing mutation routing remain fail-closed.
