# Mobo Core 10.33.64 — compare-price invariant + parent price convergence

## Compare-price invariant

WooCommerce accepts a sale only when sale < regular. Live evidence showed a source object with
`price=248000`, `comparePrice=198000`, and auto-compare enabled. The previous legacy mapping produced
regular from compare and sale from price, which became regular=378000 / sale=428000 after the active
static rule. WooCommerce correctly cleared the invalid sale during save, while MoboCore still expected
428000 as sale/active price, causing a permanent Reprice postcondition retry.

10.33.64 only treats comparePrice as a sale reference when comparePrice > price. Equal/lower compare
values produce a normal no-sale pair based on source price.

## Variable-parent convergence

WooCommerce variable-parent `_price` is a derived multi-row set from visible children. Reprice now
requests parent sync for every successfully processed variation, even if the child already had the
desired price, allowing stale historical parent price sets to converge during a full pass.
