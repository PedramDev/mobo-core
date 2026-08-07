# Mobo Core 10.32.11 — Existing Store Method Activation Fix

## Problem

When the administrator selected **Use current store methods**, version 10.32.10 still treated the Flat Rate previously created by Mobo Core as a current method. That fallback therefore remained visible in Checkout.

A second WooCommerce Zone could already contain the store's real Flat Rate, but WooCommerce selects only the first matching Zone. The dedicated `ایران - ارسال موبو` Zone matched Iran first, so methods from the broader store Zone were not calculated.

## Fix

- A Mobo-created fallback is no longer considered a real existing store method.
- The wizard detects a real enabled WooCommerce method from the best applicable non-Mobo Zone.
- Repair mirrors that method into the Mobo Iran Zone so WooCommerce can calculate it for the store package.
- The mirror preserves the source method type, title, cost, tax settings, and other instance settings.
- The old fallback is disabled only after the real method is available.
- Repair updates an existing mirror instead of creating duplicates.
- Switching back to fallback mode disables the mirror; switching to existing mode disables the fallback.

## Expected result

For the reported setup:

- `ارسال محصولات فروشگاه` fallback instance `31` becomes disabled.
- The real Flat Rate from `ارسال پستی محصولات فروشگاه` is connected to `ایران - ارسال موبو`.
- Store-only and mixed store packages show the real store method, not the fallback.
- Mobo packages continue to show only Mobo rates.

No database migration or Portal update is required.
