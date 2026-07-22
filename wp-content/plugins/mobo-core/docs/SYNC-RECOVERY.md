# Durable Product and Variant Sync Recovery — Mobo Core 10.31.81

Mobo Core no longer treats HTTP delivery as proof that a product change reached
WooCommerce. Portal attaches a durable event ID plus monotonic component and
aggregate versions. WordPress acknowledges the event only after the local queue
has applied it.

## Incoming metadata

```json
{
  "eventId": "uuid",
  "entityVersion": 12,
  "aggregateVersion": 31,
  "deliveryComponent": "product|variants",
  "deliveryKind": "live|recovery"
}
```

The metadata is normalized into private `_mobo...` fields while the event is in
the local file/table queue.

## Applied version ledger

The last completely applied versions are stored on the WooCommerce product:

```text
_mobo_applied_product_version
_mobo_applied_variant_version
_mobo_applied_aggregate_version
```

An event is stale only when the stored component version is strictly greater
than the incoming version. Equal-version pages remain valid so a multi-page
variant snapshot can finish. The version is committed only on the real final
page from the pulled payload.

## Apply ACK

After application, WordPress posts to:

```text
POST <PortalBaseUrl>/api/mobo/sync-recovery/ack
X-SEC: <webhook security code>
```

Final local failures are also acknowledged with a classified reason:
`expired`, `max-try`, or `parent-wait-timeout`. Failed ACK delivery is kept in a
bounded WordPress option queue and retried by Real Cron.

## Authoritative variants

Portal sends the complete current variant set for a VariantVersion. An empty
set is authoritative and triggers the configured missing-variant behavior, so a
site that missed variant deletions can converge to the current state.

## Compatibility

Events without version metadata continue through the legacy path. Remote update
bootstrap remains version 10.31.79; install 10.31.81 through Update Center only
on sites that already have the bootstrap.
