# Mobo Core 10.33.39 — Repair integrity and Variation identity hardening

This release upgrades the existing **Product Repair** workflow. It does not add another administrator button.

## Existing Repair workflow

Before the normal authoritative Mobo product snapshot is applied, Repair now runs bounded integrity stages. Each stage persists its cursor in the normal manual-sync state so large stores continue through the existing self-runner instead of holding one long admin request.

The integrity pass:

- repairs duplicate live Variations only when `_mobo_portal_variant_id`, parent product and normalized WooCommerce attribute signature all agree;
- keeps one canonical Variation, strips Mobo identity from the redundant copy, records rollback/audit metadata and moves the redundant copy to Trash rather than hard-deleting it;
- leaves signature-only or cross-parent conflicts untouched and reports them as ambiguous;
- collapses duplicate `_price`, `_regular_price`, and `_sale_price` rows on Mobo-owned products/variations;
- removes stale Mobo shipping-mapping options only when the referenced WooCommerce shipping instance no longer exists;
- reruns the existing legacy shipping mapping-only retirement policy.

After those bounded stages, the normal Repair snapshot continues unchanged.

## Variation identity prevention

Variation upsert now resolves in this order:

1. exact Variant GUID;
2. same-parent PortalVariantId;
3. same-parent normalized attribute signature;
4. create a new WooCommerce Variation only when no safe existing identity exists.

This closes the missing-map/reidentity window that could leave two live local Variations representing one Portal purchase identity.

## Trash recovery policy

Normal sync and webhooks still respect a merchant Trash action. During explicit Repair, trashed parent products are checked one-at-a-time through the exact GUID Portal endpoint, so an out-of-stock product omitted from the normal list can still be verified. A parent is restored only when there is exactly one local trashed identity, no active local duplicate, and Portal returns the exact GUID. Variation untrash still requires the exact identity in the authoritative Repair payload. Ambiguous Trash identities are never guessed.

Duplicate Variations quarantined by the integrity pass have their Mobo identity stripped before Trash, so they cannot be accidentally restored by the later authoritative Repair snapshot.

## REST status fix

`GET /mobo-core/v1/sync/status` now has its registered callback implementation and returns the durable manual Product Sync/Repair status instead of failing with an uncallable callback.

## Safety properties

- No parent product is hard-deleted by the new integrity pass.
- Duplicate Variation repair uses Trash, not permanent deletion.
- If WordPress Trash retention is disabled (`EMPTY_TRASH_DAYS <= 0`), automatic duplicate quarantine is refused rather than allowing `wp_trash_post()` to become a permanent delete.
- Ambiguous duplicates are report-only.
- Repair is cursor-based and safe to rerun.
- Field, price and stock update policies remain the same during the authoritative Product Repair snapshot.
