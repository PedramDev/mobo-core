# Mobo Core 10.33.77 — Custom Storage State Schema Fidelity

Custom Storage schema v2 adds lossless operational-state fields while Legacy remains authoritative. Aggregate desired/applied columns remain for compatibility, but exact Product and Variation watermarks, source hashes, and sync-incomplete state are now mirrored for a later guarded State-Primary cutover.
