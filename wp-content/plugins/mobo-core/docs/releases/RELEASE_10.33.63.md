# Mobo Core 10.33.63 — variable-parent price-set safety

WooCommerce variable parents do not use `_price` as singleton metadata. Its variable-product data store deletes parent `_price`, `_sale_price`, and `_regular_price`, then stores one parent `_price` row for every distinct price among visible child variations.

MoboCore 10.33.62 introduced a generic duplicate-price singleton repair helper. Reprice was already safe because variable parents are skipped as writable targets, but Repair could incorrectly treat the legitimate parent `_price` set as singleton corruption.

10.33.63 makes the boundary explicit:

- `Mobo_Core_Price_Meta_Integrity` refuses variable-parent canonicalization.
- Repair records variable parents as derived-price skips and leaves parent price convergence to WooCommerce/Mobo parent-finalize sync.
- Simple products and variations retain the 10.33.62 duplicate-price canonicalization behavior.
