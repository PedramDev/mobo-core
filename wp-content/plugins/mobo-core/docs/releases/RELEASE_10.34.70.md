# Mobo Core 10.34.70

## Durable raw Variation attribute convergence

WooCommerce can hydrate a custom Variation attribute through the parent option set and expose the canonical option case even while the persisted `attribute_*` postmeta still contains a legacy case-only value. 10.34.69 compared the hydrated object, so a row such as `Airpods 3` could still be accepted as already converged when the authoritative option is `airpods 3`.

10.34.70 moves storefront convergence to the durable boundary:

- no-op detection reads raw persisted Variation attributes;
- the ordinary WooCommerce save remains the primary mutation path;
- after save, desired `attribute_*` values are explicitly verified and case-only storage drift is rewritten when Woo did not mark the hydrated property dirty;
- the exact persisted values are re-read before completion; failure marks the Variation incomplete and fails closed;
- GUID / PortalVariantId / slug-normalized attribute signature identity semantics are unchanged.

Backend/.NET is unchanged.
