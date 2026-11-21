# MoboCore Admin UI Design System

Phase 1 introduces a shared admin shell for all MoboCore pages and fully migrates the Connection & License page.

## Isolation rules

- Every MoboCore admin page is rendered inside `#mobo-core-admin`.
- New admin CSS must be scoped below `#mobo-core-admin`.
- New component classes use the `mobo-admin-*` prefix.
- Assets are enqueued only on MoboCore admin pages.
- Page-specific assets live under `assets/admin/css/pages/` and `assets/admin/js/pages/`.
- Do not use generic global selectors such as `.card`, `.button`, `input`, `table`, `body`, or `*` outside the MoboCore root.
- `!important` is reserved for compatibility-critical cases only.

## Asset layout

- `assets/admin/css/mobo-admin-core.css`: root variables, shell, header, navigation and scoped reset.
- `assets/admin/css/mobo-admin-components.css`: shared buttons, cards, badges, secret controls and helper UI.
- `assets/admin/css/pages/connection.css`: Connection & License page layout.
- `assets/admin/js/mobo-admin-core.js`: shared copy/secret controls.
- `assets/admin/js/pages/connection.js`: connection-page behavior.

## Connection ownership

The browser may edit only the Connection token and webhook security code. Provider, Source, backend URL, source-site URL and display name are server-provisioned metadata. New Connections are created from a Token only and remain pending/disabled until provisioning succeeds.


## Phase 1.1 polish

The first real wp-admin rendering was reviewed at desktop width.

- The Connection header is more compact.
- Security-code structure and last webhook-auth test are consolidated into the Connection settings card instead of separate full-width notices.
- License transport failures use a short Persian operator-facing message; the raw transport error is available only in a collapsed technical-details section.
- Status labels have slightly stronger visual hierarchy without increasing page height.
- No Connection ownership or runtime behavior changed.

## Phase 1.2 polish

- Primary admin tabs use a quieter light-blue selected state with high-contrast blue text instead of a saturated fill.
- The create-Connection action is stacked below the token field and aligned with the RTL form edge to avoid a detached button column.

## Phase 2 — Daily admin pages

The dashboard, product settings, and purchase/activation pages use dedicated page assets on top of the shared admin shell. The migration is presentation-only: sync, provisioning, license, and settings persistence contracts remain unchanged.

- `assets/admin/css/pages/dashboard.css` prioritizes sync state, build integrity, and manual operations.
- `assets/admin/css/pages/product.css` groups product options into stock/pricing, content/URL, and image/cache sections.
- `assets/admin/css/pages/purchase.css` presents activation state, onboarding steps, and sales/support contacts.
- All selectors remain scoped under `#mobo-core-admin`; page styles are enqueued only for their matching tab.
- Responsive rules must not create horizontal page overflow; components use `min-width: 0` and collapse to one-column layouts on narrow screens.


## Phase 2.1 — action hierarchy and Select2 isolation

- Dashboard manual operations follow progressive disclosure: one context-aware primary action is visible; category refresh, Repair, and sync-state reset live under an advanced disclosure unless Repair is explicitly required by migration state.
- SelectWoo/Select2 is reserved for search-worthy selects. Small status/boolean selects stay native.
- On mobile (`<=782px`) enhanced selects are destroyed and native controls are used.
- Enhanced dropdowns are mounted under `#mobo-core-admin` via `dropdownParent`, with scoped RTL/z-index CSS, and dynamic selects are re-initialized through a bounded MutationObserver.

## Phase 2.2 — task-oriented overview and binary controls

- Purchase/activation is intentionally unchanged after Phase 2.1 approval.
- Dashboard keeps operational state and the one recommended sync action in the primary flow. Build-integrity and wallet details are secondary disclosures, and a category fallback problem is shown as a short actionable notice rather than a long policy paragraph.
- Dashboard sync statistics expose only the four everyday facts by default; execution identifiers, cursors/pages, Repair details, and diagnostic counters are under a technical disclosure.
- Dashboard license rendering uses the compact operator-facing presentation; raw transport/license details remain available under disclosure.
- Boolean settings rendered through `bool_field()` use an accessible two-option segmented control (`روشن` / `خاموش`) while preserving the exact POST values `1` and `0`. They no longer participate in Select2/SelectWoo enhancement.
- Product technical cache/content notes are secondary disclosures so the main page is primarily the settings decisions themselves.

## Final manager UX pass (2026-09-28)

- Manager-facing pages are summary-first: current state, required action, then optional detail.
- Advanced pages show only the advanced navigation group; the manager navigation is not duplicated in the same row.
- Dense diagnostics, raw counters, command snippets, debug logs, and expert tuning stay available under native `<details>` panels.
- Category mapping is paged at 50 rows per page and exposes All / Unmapped / Mapped views to avoid rendering the full mapping corpus at once.
- SelectWoo/Select2 is never initialized while its field is hidden inside a collapsed details panel. It is initialized when the panel opens; mobile remains native-select first.
- Checkout, SMS, Cron, queue tuning, and technical health all expose a short operational summary before expert controls.
- Destructive/reset/repair-style actions remain secondary and are not presented as primary manager actions.

## Searchable selects after Final UX hardening

MoboCore admin no longer depends on Select2/SelectWoo for its own searchable fields. The shared `mobo-admin-picker.js` component keeps the real `<select>` as the submitted source of truth and renders a single scoped, searchable popover only on desktop. On mobile the native select is used.

Reasons:

- prevents third-party wp-admin Select2 CSS/version conflicts;
- prevents dropdowns from being mounted under another plugin's container or behind cards;
- avoids dozens of independent Select2 instances in category mapping tables;
- preserves normal form submission and `change` events for existing MoboCore logic;
- keeps all picker CSS below `#mobo-core-admin`.

Simple two-state selects are never enhanced. Search enhancement is reserved for category, address, shipping and other explicitly marked searchable selects.


## Dashboard final overview polish

- Service, license and webhook summary cards expose a compact last-check/last-test time.
- Dashboard POST actions use `data-mobo-busy-submit` so repeated clicks are blocked while navigation is in progress.
- Duplicate product links open in a new tab and retain the MoboCore dashboard context.
- When no dashboard-level issue is active, a short positive clean-state message is shown instead of empty warning space.

## Categories manager UX hardening (Final UX11)

- Category policy wording describes runtime behavior rather than implementation details: automatic category synchronization, manual mapping, required mapping, and fallback category are distinct decisions.
- Required manual mapping is disabled in the UI when manual mapping itself is disabled. Saving that state normalizes the required flag to off; legacy contradictory states are shown conservatively until saved.
- The mapping table shows the effective current result, not only the historically synced WooCommerce term.
- WooCommerce and source categories are rendered as full breadcrumb paths to disambiguate identical leaf names.
- Category search is server-side across the active mapping corpus. The auto-suggestion action is explicitly page-scoped and never auto-saves a suggestion.
- Complete authoritative source snapshots store connection-scoped active remote GUIDs. Categories removed from the source disappear from the active mapping UI without deleting their historical manual mapping, so a returning source category can recover its prior mapping.
- The page shows the latest successful source-category refresh and active source count. Mapping changes explicitly explain that existing products require the separate recategorization action for immediate application.
- Recategorization is summary-first; cursor/last-ID and skipped counters are technical details rather than primary manager information.
