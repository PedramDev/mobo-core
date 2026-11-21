# Mobo Core 10.33.68 — Custom Storage Foundation v1

This release introduces the non-disruptive storage foundation for moving Mobo operational state out of WordPress EAV storage.

- Creates seven prefixed custom tables.
- Keeps existing sync/webhook/image/order runtime paths on legacy persistence.
- Uses an independent storage schema version and fail-closed mode gate.
- Adds Product and Variant state repositories for later dual-write integration.
- Does not delete or migrate existing postmeta/options data.
