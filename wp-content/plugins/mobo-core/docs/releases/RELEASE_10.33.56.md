# Mobo Core 10.33.56

## Image-order desired-state hardening

- Portal image `position` is now a first-class ordering contract when present.
- Explicit positions must be non-negative, unique, complete, contiguous and start at zero; malformed positioned snapshots fail closed before WooCommerce mutation.
- Positioned collections are normalized before product image hashing, queueing, featured-image selection and gallery reconciliation.
- Missing-image recovery and the image-sync service consume the same ordering policy.
- Backward compatibility is preserved: payloads from older Portal releases without explicit position retain their array order.
- Position `0` remains the authoritative featured image; later positions remain gallery order.
