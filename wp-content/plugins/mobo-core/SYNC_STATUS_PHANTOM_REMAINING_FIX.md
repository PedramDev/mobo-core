# Sync status phantom remaining fix

Problem:
The product sync UI could show `status=done` while `remainingProducts=1` and `progressPercent=99.81`.

Cause:
The Portal `totalCount` can be a stale estimate. The product page response with `hasMore=false`
is the authoritative terminal signal, but the previous `productTotalCount` was still used for
UI progress calculation.

Fix:
- When product sync is `done`, status payload now reports `remainingProducts=0` and `progressPercent=100`.
- When an empty final page is received with `hasMore=false`, the stored effective total is normalized to `processedProducts`.
- Invalid/skipped products are counted as processed and can complete the sync if they are the last item.
