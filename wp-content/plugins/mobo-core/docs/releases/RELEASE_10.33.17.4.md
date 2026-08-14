# Mobo Core 10.33.17.4

## Pricing sanitization consistency

- `global_additional_price` and `global_additional_percentage` are now clamped to zero when a negative value is submitted.
- This matches the existing admin UI (`min=0`), legacy pricing save path and per-variation additional-price behavior.
- Prevents a crafted/alternate settings submission from turning a configured profit into a price discount.

## Portal settings metadata typing

- Numeric defaults of `0`/`1` are no longer automatically treated as booleans.
- Boolean typing now uses semantic option names/flags.
- Integer options such as product page size, wallet threshold and shipping IDs remain integers.
- Numeric-looking usernames/mobile-like text no longer change type between sites.
- Global fixed/percentage profit settings are reported as decimals.

No database schema change or Portal migration is required.
