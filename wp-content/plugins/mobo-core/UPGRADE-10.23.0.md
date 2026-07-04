# Mobo Core 10.23.0

Fixes UpdateVariant payload handling for Portal paged payloads shaped as `{ productId, data: [...] }`.

Previously the webhook queue unwrapped `data` when resolving pulled payloads, which could drop top-level `productId`, `pageNumber`, cursor and other paging metadata. This caused `productId is required.` even when the pulled payload contained `productId`.

After upgrading, delete/requeue old failed `UpdateVariant` events that were created with the old processing path.
