# Mobo Core 10.33.97

## Terminal metadata compatibility and late-repair hardening

10.33.97 is the compatibility/final-audit release on top of the completed Storage Schema v6 retirement in 10.33.96. It does not introduce a new storage schema or expand retirement scope. It makes the standard WordPress/WooCommerce metadata surfaces exactly reflect the already-authoritative Custom Storage and makes post-terminal cleanup failure evidence durable.

### WordPress metadata semantics

Terminal single-value reads now use a shape that satisfies both `get_post_meta(..., true)` and `metadata_exists()`: present values are wrapped internally so valid false-y values such as an empty string or `0` remain present, while absent typed identity/operational/generic values remain truly absent.

`get_post_meta($post_id)` and `get_post_custom($post_id)` now receive a unified all-meta view for Product/Variation objects. Physical native WordPress/WooCommerce metadata is preserved, physically retired Mobo metadata is removed from the legacy portion, and authoritative operational, identity and generic Custom values are appended with WordPress-compatible raw value arrays.

### WooCommerce metadata compatibility

WooCommerce reads Product custom metadata through its data store and can update/delete loaded rows by meta ID. 10.33.97 adds the corresponding typed bridge for the 10.33.94 operational and 10.33.95 identity stores, complementing the generic v6 bridge added in 10.33.96.

- `WC_Product::get_meta()` sees terminal typed identity and operational values.
- loaded operational rows can be updated/deleted through `save_meta_data()` without recreating `wp_postmeta`.
- live ownership identity remains protected by its existing fail-closed identity policy.
- ordinary WooCommerce/native metadata remains untouched.

### Non-Mobo isolation

The terminal `mobo_sync_incomplete` corruption fail-safe is now applied only to a Product/Variation with authoritative Mobo identity. An unrelated Woo Product with no Mobo state row remains metadata-absent rather than being synthesized as incomplete.

### Durable late cleanup repair

If a pre-transition request commits late after terminal authority and the mirror or legacy-delete step encounters a transient database failure, the failure is persisted in runtime state and a tiny repair-pending marker. A bounded `init` repair tick replays failed commit cleanup, removes Custom mirrors for confirmed legacy deletes, runs cleanup/prune convergence, and clears the marker only after pending IDs and physical Product/Variation residue are both zero.

Finalizer gate v4 now treats this pending-repair evidence as a hard blocker. A site cannot report physical retirement complete merely because the instantaneous legacy residue happens to be zero while a recorded Custom/Legacy cleanup failure still requires reconciliation.

### Scope

Storage Schema remains **v6**. The generic entity-meta migration target remains **10.33.96**. Attachments, orders/order-items, WooCommerce-native Product metadata and non-Mobo `published_at` remain outside Product/Variation Mobo metadata retirement.

### Runtime architecture guard

The WooCommerce terminal bridges use stable positive synthetic metadata IDs outside the normal `wp_postmeta` ID range. 10.33.97 makes the existing 64-bit integer requirement explicit: activation and runtime bootstrap fail closed on 32-bit PHP rather than allowing ambiguous meta-ID truncation/collision.
