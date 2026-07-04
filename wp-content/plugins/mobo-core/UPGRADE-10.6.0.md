# Mobo Core 10.6.0 Upgrade Notes

## Phase 3: Lightweight webhook + pull payload

This release keeps backward compatibility with full-payload webhooks and adds support for lightweight Portal notifications.

### New flow

1. Portal sends a small notification to `/wp-json/mobo-core/v1/webhook`:

```json
{
  "EventId": "...",
  "SyncId": "...",
  "Type": 0,
  "ChangesUrl": "https://portal.example.com/api/customer-batches/..."
}
```

2. WordPress stores the notification in the local queue.
3. The customer-side self runner wakes up.
4. The worker pulls the real payload from `ChangesUrl` using `X-SEC`.
5. The normal product/variant processors run with the fetched payload.

### Backward compatibility

- Full-payload webhooks still work.
- Legacy JSON file queue still works.
- Table-backed event queue still works.
- ProductUpdated image offset resume remains active.
- If the payload pull fails, the event is retried with the normal retry/backoff logic.

### New options

- `mobo_core_pull_payload_enabled` default: `1`
- `mobo_core_payload_pull_timeout_seconds` default: `20`

### Portal requirement

Enable:

```json
"WebhookSendLightweightNotification": true
```

and configure:

```json
"PublicBaseUrl": "https://your-portal-domain.com"
```

inside the `Conf` section.
