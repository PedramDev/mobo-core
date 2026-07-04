# Mobo Core 10.24.0

Fixes UpdateVariant product context resolution. If a pulled variant payload or webhook notification loses top-level `productId`, the plugin now recovers it from:

- top-level payload fields,
- first variant row,
- lightweight notification entity fields,
- the `{productGuid}/get-variants` payload URL.

The processor also injects the resolved product id into variant rows before upsert and returns diagnostic payload keys if product id is still missing.
