# Mobo Core 10.34.56 — HARDEN02 Money Contract

## Purpose

Make Source prices safe across future Sources/Providers by attaching explicit monetary semantics to every provisioned Connection and failing closed when WooCommerce uses a different unit.

## Contract

Signed Backend provisioning owns these Connection fields:

- `money_unit`
- `money_fraction_digits`
- `money_price_representation`
- `money_contract_version`

The current MoboMobo Source contract is `IRT`, `0`, `major`, version `1`.

`IRT` and `IRR` are intentionally distinct. This release performs no FX and no implicit `IRR <-> IRT` multiplication/division.

## Runtime safety

Monetary compatibility is checked before:

- Product/Variation price mutation
- Reprice calculation
- Mobo shipping package price substitution
- Automatic Mobo shipping rates
- Order submission / remote purchase side effects

A mismatch is reported in Health and blocks financial mutation instead of silently reinterpreting the numeric amount.

## Provisioning rollout

Existing Connection routing remains usable for non-financial operations. Financial paths are fail-closed until the Backend replays the signed monetary contract. The Portal backend includes a bounded reconciliation worker so existing customers do not need to edit or resave their license.
