# C75B-P4G — Reprice currency generation safety (STAGED)

- Reprice generation policy fingerprint includes explicit target-currency opt-in *presence and value*, WooCommerce currency and Woo price decimals, in addition to existing profit rules.
- An in-progress or cancelled generation using the old money policy is invalidated by existing `generation_matches_current_policy()` checks; administrator must start a new generation from zero.
- No new worker, lock, database schema or network action has been added.
- The Connection settings help text now matches the installed Direct Pricing behavior: Source pricing conversion is active when explicitly selected; shipping / checkout / provider purchase remain strict and fail-closed.
- Changing the selected currency **does not automatically reprice existing items**. Start a new Reprice generation on the appropriate existing site after verifying the setting.
- This is NOT an end-to-end Product Sync/Reprice or customer release approval. An existing valid Mobo-owned product is still required for operational Reprice proof. The currency semantics of signed Source connections changing independently while a generation runs remains an open audit item.
