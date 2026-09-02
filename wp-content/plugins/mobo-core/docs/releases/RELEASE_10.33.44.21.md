# Mobo Core 10.33.44.21 — Repair fallback throughput hardening

- Fixes very slow Repair progress when the local Self Runner/loopback worker is unavailable or delayed.
- The authenticated admin status poll detects one missed Repair poll and performs a bounded catch-up burst of canonical `run_manual_sync_step()` calls instead of advancing only one step every eight seconds.
- Catch-up remains lock-safe and checkpoint-safe because every iteration enters the normal manual-sync worker lease and existing product/ordering fences.
- Foreground Webhook pressure immediately yields the Repair burst so freshness retains priority.
- The browser fallback stops on lock contention, error, no observable cursor progress, completion, or its short time budget.
- Image queue I/O is deferred during a rescue poll so slow image work does not consume the same request budget needed to recover Repair progress.
- Admin UI reports when the browser is rescuing a slow/blocked Self Runner.

Deep Test Suite target: `10.33.44.21-r7.10`.
