# Mobo Core 10.33.44.13 — excluded-product enforcement and category fallback diagnostics

## Scope

This release fixes a policy bypass around **Products excluded from synchronization** and adds accurate category fallback diagnostics. It does not redesign Portal, shipping, checkout, order submission, server topology, cron topology, or Docker configuration.

## Excluded products

The administrator URL list is now enforced through one shared `Mobo_Core_Product_Exclusions` policy.

The direct regression was in Repair integrity: a Mobo product already in WordPress Trash could be confirmed by Portal and restored through `wp_untrash_post()` before the ordinary Product Sync URL filter was reached. Repair now checks both local durable `mobo_url` evidence and the fresh authoritative Portal product payload before any untrash operation.

The same policy also fences source-driven work which may already be queued when an administrator adds an exclusion:

- ProductUpdated and Manual/Repair snapshots
- UpdateVariant
- Parent Finalize
- Reprice
- Recategorize
- Missing-Image Recovery
- Image Queue
- Image Refresh

An exclusion does **not** delete an existing WooCommerce product. It prevents subsequent Mobo-driven mutation. If an already-created product must be removed or trashed, that remains an explicit store-manager action.

### URL identity

Absolute URLs and path-only values are normalized to a lowercase path and ignore query strings/trailing slashes. Numeric-looking paths are never cast to integers, so `/0338` remains different from `/338`.

For Variant payloads which do not carry a product URL, the plugin keeps bounded GUID→URL evidence after an excluded Product payload is observed. This evidence is not a second source of truth: every lookup is checked against the administrator's current exclusion list, so removing the URL immediately re-enables the GUID.

### Queue ownership

A pending image row which belongs to a newly excluded product is terminated with an `excluded` state only while the worker still owns the exact row identity and lease. A stale worker cannot cancel a row reclaimed/superseded by another worker. A later authoritative enqueue can reopen the row if the exclusion is removed.

## Category fallback behavior

A missing **Default / fallback category** does not prevent WooCommerce product creation. Mobo Core persists the parent product before category assignment.

If no source category can be resolved through manual mapping, `category_guid`, or permitted automatic creation, a new product may remain without the intended category (and WooCommerce may apply its own category behavior).

Warnings are now shown in both:

- Overview (`نمای کلی`)
- Categories (`دسته‌بندی‌ها`)

The warning also explains two important exceptions:

- With **Required manual mapping** enabled, a fallback category is not a substitute for the missing mapping.
- An explicit authoritative `categories=[]` means the desired category set is empty and is intentionally not replaced by fallback.

## Tests

Deep Test Suite `10.33.44.13-r7.0` adds:

- normalization tests for `/7001`, `/2237`, `/0338`, and `/338` separation;
- exact Repair regression: an excluded Trash product must remain in Trash and must not increment the restored counter;
- durable GUID evidence / removal-of-exclusion behavior;
- shared-policy contracts for Variant, Reprice, Recategorize, images and Parent Finalize;
- image lease-fenced excluded transition contract;
- category warning semantics and the product-save-before-category-assignment contract.

The destructive regression is mutation-gated and local-only.
