# Mobo Settings Exporter 1.0.0

A temporary, read-only migration plugin.

It exports:

- all 104 managed Mobo configuration keys;
- supported dynamic shipping mapping keys;
- exact WordPress/WooCommerce registered image subsizes;
- WordPress, PHP and Mobo version metadata.

The JSON can be uploaded directly in Portal's central configuration page. The
same file can be passed to `build_size_profile.py` to create the shared-media
size profile.

The export may contain secrets. Remove this plugin after migration and keep the
JSON in a protected location.
