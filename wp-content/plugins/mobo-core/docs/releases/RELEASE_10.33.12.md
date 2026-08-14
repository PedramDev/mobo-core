# Mobo Core 10.33.12 — Mobo Wallet Low-Balance Reminder

## Summary

This release adds post-purchase Mobo wallet monitoring and a one-shot low-balance SMS reminder for store managers.

## Runtime flow

1. Automatic Mobo order submission completes wallet payment successfully.
2. Mobo Core reuses the authenticated storefront session/cookie jar and requests:
   `GET /site/api/v1/user/billing/transactions/balance`
3. The returned `amount` is stored as the site's latest Mobo wallet balance and on the triggering WooCommerce order.
4. If the wallet alert is enabled, `amount <= configured threshold`, and no alert has been sent since the last manual re-arm, the dedicated wallet SMS template is sent through Persian WooCommerce SMS.
5. Only a successful SMS marks the site as notified. Further purchases continue checking/storing the balance but do not send another alert until the manager clicks the dashboard re-arm button.

## Settings

The advanced SMS page now includes a separate **Mobo wallet balance alert** configuration:

- enable/disable
- balance threshold
- recipient mobile numbers
- independent text/pattern template

Supported wallet placeholders:

- `{mobo_wallet_balance}`
- `{mobo_wallet_balance_formatted}`
- `{mobo_wallet_threshold}`
- `{mobo_wallet_threshold_formatted}`
- `{site_name}`
- `{site_url}`

Because the alert is associated with the successful WooCommerce order that triggered the check, existing Persian WooCommerce SMS order shortcodes remain available as well.

## Dashboard

The manager dashboard shows:

- latest wallet balance
- configured threshold
- latest check time
- notification state (`ready`, `notified`, or `disabled`)
- latest wallet/SMS error
- **Re-arm Mobo wallet reminder** button after a notification has been sent

Balance increases do not automatically clear the notified flag. Re-arming is deliberately explicit.

## Safety

- Browser cookies from copied curl commands are not stored. The existing Mobo username/password login and option-backed `userauth` cookie jar are reused.
- The balance request retries once after a forced login when the stored Mobo session has expired.
- Wallet/SMS errors do not roll back or fail an already successful Mobo purchase.
- The low-balance notification flag is written only after the SMS gateway reports success.
