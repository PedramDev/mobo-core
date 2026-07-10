# Mobo Core for WooCommerce

Mobo Core connects WooCommerce stores in Iran to MoboCore and the specific Mobo/Mobomobo source at `mobomobo.ir` for product synchronization, shipping mapping, checkout validation, automatic order submission, image refresh workflows, webhook queues, cron runners, and health reporting.

## Scope

This plugin is not a generic marketplace connector. It is built for:

- WooCommerce stores operating in Iran
- Product/order workflows connected to `mobomobo.ir`
- MoboCore account/license management through `mobo.codeya.ir`

## Purchase / Activation

Mobo Core requires an active MoboCore account and token for synchronization and order automation.

- Service: https://mobo.codeya.ir/
- Source workflow: https://mobomobo.ir/
- WordPress admin path: **Mobo > خرید و فعال سازی**
- Connection settings: **Mobo > اتصال**

## Public service pages

Ready-to-publish page drafts are included in this repository:

- `docs/mobo-codeya-terms.html`
- `docs/mobo-codeya-privacy.html`
- `docs/mobo-codeya-terms.md`
- `docs/mobo-codeya-privacy.md`

Recommended live URLs:

- https://mobo.codeya.ir/terms
- https://mobo.codeya.ir/privacy

## Recommended repository name

Recommended GitHub repository URL:

```text
https://github.com/PedramDev/mobo-core
```

Alternative names:

```text
https://github.com/PedramDev/mobocore-woocommerce
https://github.com/PedramDev/woocommerce-mobocore-sync
```

For WordPress.org, keep the plugin folder/slug as:

```text
mobo-core
```

## WordPress.org notes

The plugin uses external services and must disclose them in `readme.txt`:

- `mobo.codeya.ir` for MoboCore license, sync, queue, webhook, health, and automation workflows
- `mobomobo.ir` as the specific Mobo/Mobomobo source for checkout validation and order submission when enabled

## License

GPLv2 or later.
