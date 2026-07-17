# Category placeholder fix — Mobo Core 10.33.0

## Root cause

Older product webhooks carried only `categoryId`. When a category was not yet present in WordPress, the plugin created a fallback term named `Mobo Category <GUID>`. WordPress generated the matching slug automatically. The normal category-sync protection then preserved that existing local term, so the placeholder remained.

## New behavior

- Portal product webhooks include `categoryId`, `title`, `url`, and `parentId`.
- Mobo Core refuses to create a WooCommerce category from a GUID-only reference.
- A full category sync repairs only exact legacy Mobo placeholders for the same GUID.
- Customer-created category names and slugs remain protected.
