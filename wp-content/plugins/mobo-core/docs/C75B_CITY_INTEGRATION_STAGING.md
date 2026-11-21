# C75B staged city dependency decoupling (NOT RELEASE-READY)

Baseline: MoboCore 10.34.77 REL37 corrected manifest. This build is a disposable-clone-only staging candidate.

- WordPress `Requires Plugins` and runtime dependency guard now require only WooCommerce.
- The separate PWSMS transport remains optional/unchanged.
- MoboCore no longer registers the Persian WooCommerce city-script override or generates the legacy `iran_cities.js` assets on mapping synchronization/manual save. The old implementation file was removed.
- Native WooCommerce city/state/country capture and the central Backend `resolve-location` automatic-order path are unchanged. No historical order migration is performed.
- Address Mapping's old numeric-city decoder and legacy cache/API surface are deliberately retained pending isolated retry/shipping regression; do not treat full cleanup as complete.
- Existing city asset files in `wp-content/uploads` and old settings are deliberately NOT deleted during upgrade.
- The cached address-mapping sync from storefront init is disabled; explicit legacy sync paths remain available to existing consumers.
- Existing suite C75 exact hashes/fixtures are bound to the REL37 baseline, not this candidate; they must be forked/regenerated, never overridden.

Open runtime gates: WP activation without Persian WooCommerce, Classic/Blocks checkout, resolver resolved/pending/timeout/alias/provider scope, remote-order no-purchase-on-pending, retry/idempotency, shipping mapping, PWSMS optionality, PHP/Windows integrity and full test suite, fairness, locks, migration, crash resume.

DO NOT deploy to test1.local, test2.local or customer sites. Full Release Gate remains BLOCKED.
