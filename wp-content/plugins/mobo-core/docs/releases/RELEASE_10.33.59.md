# Mobo Core 10.33.59 — Reprice liveness under sustained webhook pressure

## Root cause

The weighted Fair Scheduler used `lastSelectedAt` to calculate starvation age. For a newly-started Reprice or Recategorize generation that had never received a scheduler slot, `lastSelectedAt` was `0`. The scheduler interpreted that as age `0` on every decision. Under sustained webhook pressure, where background capacity is intentionally reduced to one stage, such a queue could remain `running` indefinitely and never reach the documented starvation escape.

A second operational gap existed on hosts where a non-blocking local loopback request was accepted by WordPress HTTP but never actually executed. The Pricing page kept polling status and repeatedly kicked the Self Runner, but unlike Product Sync and Image Refresh it had no bounded authenticated fallback that could advance the queue directly.

## Fix

- If Reprice/Recategorize has never been selected, Fair Scheduler uses the durable queue generation `startedAt` timestamp as the starvation-age anchor. After the first real selection, `lastSelectedAt` resumes its normal role.
- The authenticated Reprice status poll treats eight seconds without a checkpoint as stale and runs one item with a two-second cooperative budget.
- The direct rescue path still goes through `Mobo_Core_Reprice_Queue::process_batch()`, therefore the existing `reprice_queue_worker` lease, Product Sync contention rules, per-product lock, durable cursor and retry policy remain authoritative.
- Webhook foreground processing and webhook-pressure background capacity are unchanged.

## Upgrade behavior

No migration, Repair, reset, or manual SQL is required. Existing running Reprice state is preserved and becomes eligible for the corrected starvation-age calculation immediately after upgrade.
