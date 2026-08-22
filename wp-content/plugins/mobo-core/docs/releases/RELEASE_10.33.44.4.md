# Mobo Core 10.33.44.4 — self-runner handoff ordering

## Webhook continuation

- The self-runner now records its pending HTTP handoff before starting the non-blocking loopback request.
- A fast `/worker/run` claim can safely clear that marker without the dispatching request recreating stale handoff state afterwards.
- Successful worker continuations no longer become false `dispatch-timeout-backoff` results several minutes later.

## Operational effect

- Large due webhook backlogs can keep chaining bounded worker slices when progress is made.
- Existing pending webhook rows remain untouched and drain through the normal ordered processor.
- No database schema or data migration is required for this release.
