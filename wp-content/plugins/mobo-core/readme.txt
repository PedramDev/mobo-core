=== Mobo Core ===
Contributors: pedramdev
Tags: woocommerce, iran, product sync, mobomobo, order automation
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 8.2
WC tested up to: 10.9
Stable tag: 10.31.45
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Mobo Core connects Iranian WooCommerce stores to MoboCore and a specific mobomobo.ir workflow for product sync, checkout validation, shipping mapping, and optional Mobo order automation.

== Description ==

Mobo Core is a WooCommerce integration plugin built for stores operating in Iran and using the specific `mobomobo.ir` workflow as their Mobo/Mobomobo product and order source. This plugin is not presented as the official plugin of mobomobo.ir unless such authorization is explicitly stated by the service owner.

The plugin connects WooCommerce to the MoboCore service for product synchronization, webhook processing, shipping method mapping, checkout validation, automatic order submission, and operational health checks.

Main features:

* Step-based product, variation, category, price, and image synchronization.
* Queue-based webhook processing to avoid timeout in WordPress requests.
* Shipping method mapping between WooCommerce shipping zones/methods and Mobo shipping methods.
* Separate shipping mapping for Mobo-only orders and mixed Mobo/non-Mobo orders.
* Optional automatic order submission for Mobo-only and mixed WooCommerce orders.
* Address mapping for checkout country, state, and city values used in Iran.
* Image refresh workflow for legacy images after a full Repair run.
* Optional health reporting for cron, queue, memory, disk, and debug status.
* Optional order SMS notifications through the Persian WooCommerce SMS plugin.

This plugin requires an active MoboCore account/license for the external synchronization and order automation features. You can buy or manage access at:

https://mobo.codeya.ir/

Sales and activation contact:

* Phone: +989124508218
* Telegram: https://t.me/yazdan_ghadiri
* WhatsApp: https://wa.me/989124508218
* Tel link: tel:+989124508218

Technical support contact:

* Phone: +989367362228
* Telegram: https://t.me/Codeya

== External services ==

This plugin is designed for Iranian WooCommerce stores and a specific external Mobo/Mobomobo source: `mobomobo.ir`.

The plugin may connect to these external services depending on administrator settings:

1. MoboCore service at `mobo.codeya.ir`

Used for license/account access, token-based connection, product synchronization orchestration, webhook processing, queue status, repair/sync workflows, health reporting, and order automation support.

2. Mobo/Mobomobo source at `mobomobo.ir`

Used when checkout validation, cart checking, shipping method retrieval, or automatic order submission is enabled. This is the specific source this plugin is built for.

The plugin may send or receive the following data depending on enabled settings:

* Site domain and license/token information.
* Product, variation, category, price, stock, and image synchronization data.
* Webhook payload references and processing status.
* WooCommerce order data needed for Mobo order submission, including customer name, phone, shipping address, selected shipping method, Mobo product/variation identifiers, and order item quantities.
* Technical health data such as queue counts, cron state, PHP memory, disk space, and debug status.

This communication happens only after the site administrator enters a Token or explicitly uses/enables related features such as synchronization, Repair/sync, webhook processing, checkout validation/order automation, image refresh, or health reporting. Sensitive external workflows such as order submission, health reporting, address mapping, and legacy image refresh are disabled by default on fresh installations.

Service website:

https://mobo.codeya.ir/

Terms of Service:

https://mobo.codeya.ir/terms

Privacy Policy:

https://mobo.codeya.ir/privacy

== Installation ==

1. Upload the `mobo-core` folder to `/wp-content/plugins/`, or install the plugin through the WordPress plugins screen.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to **Mobo > خرید و فعال سازی** to buy or manage your MoboCore license.
4. Go to **Mobo > اتصال** and enter the Token and Webhook Security Code from MoboCore.
5. Complete address mapping and shipping method mapping before enabling automatic checkout/order workflows.
6. If upgrading from old versions such as version 7, run one full Repair from the dashboard before using image refresh.

== Frequently Asked Questions ==

= Is this plugin for all countries? =

No. This plugin is intended for WooCommerce stores operating in Iran and using the specific `mobomobo.ir` source/workflow.

= Does this plugin work without MoboCore? =

The admin screens can be opened, but synchronization, license status, webhook processing, health reporting, checkout validation, and Mobo order automation require an active MoboCore account and token.

= Does this plugin connect to mobomobo.ir? =

Yes, when checkout validation, cart checking, shipping method retrieval, or automatic order submission is enabled. The plugin is built for that specific source.

= Does it create WooCommerce shipping methods? =

No. WooCommerce shipping methods are still managed in WooCommerce shipping zones. Mobo Core maps the selected WooCommerce shipping method to a Mobo shipping method for automatic order submission.

= Does it send SMS directly? =

No. SMS notifications are sent through the Persian WooCommerce SMS plugin if that plugin is installed, configured, and enabled.

= Is Repair required after upgrading from version 7? =

Yes. Legacy installations should run one full Repair so product maps, image queues, and synchronization state match the current structure.

== Screenshots ==

1. Mobo Core dashboard and sync status.
2. Purchase and activation screen.
3. Connection and license information.
4. WooCommerce to Mobo shipping method mapping.
5. Queue, cron, and image refresh settings.

== Changelog ==

= 10.31.45 =
* Added sales and technical contact information to the purchase/activation screen and documentation.
* Kept GitHub links aligned with https://github.com/PedramDev/mobo-core.
* Changed the default MoboCore API URL to HTTPS.
* Enabled SSL verification by default for outbound HTTP requests.
* Disabled sensitive external workflows by default on fresh installs: automatic order submission, health reporting, address mapping, and legacy image refresh.
* Added a developer-only opt-in filter for unsafe local/private image downloads used in local test environments.
* Clarified that this is an integration for a specific mobomobo.ir workflow and not presented as an official mobomobo.ir plugin unless separately authorized.

= 10.31.43 =
* Added ready-to-publish Terms and Privacy pages for mobo.codeya.ir.
* Clarified that the plugin is intended for Iranian stores and the specific mobomobo.ir source.
* Updated external service disclosure with mobo.codeya.ir and mobomobo.ir.
* Updated purchase/activation UI text for the Iran-only and mobomobo.ir workflow.

= 10.31.42 =
* Added purchase and activation screen linked to mobo.codeya.ir.
* Added WordPress.org-ready readme.txt and external service disclosure.
* Updated plugin metadata, license headers, and GitHub URL.

== Upgrade Notice ==

= 10.31.45 =
Adds sales/technical contact information and keeps WordPress.org hardening: HTTPS defaults, SSL verification, and disabled sensitive external workflows on fresh installations. Run Repair once when upgrading from legacy versions such as version 7.
