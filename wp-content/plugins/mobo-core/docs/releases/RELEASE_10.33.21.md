# Mobo Core 10.33.21

## Revenue metadata privacy

- Keeps `_mobo_revenue_source_unit_cost` and `_mobo_revenue_source_snapshot_at` on WooCommerce line items because the immutable revenue ledger depends on the stored source-cost snapshot.
- Adds both keys to WooCommerce's hidden order-item metadata list for the admin order editor.
- Removes both keys from `WC_Order_Item::get_formatted_meta_data()` output so they do not appear in customer order details, emails, or invoice/PDF integrations that use WooCommerce's formatted metadata API.
- Raw metadata and revenue calculations are unchanged.
- No database schema migration is required.
