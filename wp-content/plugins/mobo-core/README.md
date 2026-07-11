# Mobo Core for WooCommerce

Mobo Core connects WooCommerce stores in Iran to MoboCore and a specific Mobo/Mobomobo workflow at `mobomobo.ir` for product synchronization, shipping mapping, checkout validation, optional automatic order submission, image refresh workflows, webhook queues, cron runners, and optional health reporting.

## Scope

This plugin is not a generic marketplace connector and is not presented as the official plugin of mobomobo.ir unless such authorization is explicitly stated by the service owner. It is built for:

- WooCommerce stores operating in Iran
- Product/order workflows connected to `mobomobo.ir`
- MoboCore account/license management through `mobo.codeya.ir`

## Purchase / Activation

Mobo Core requires an active MoboCore account and token for synchronization and order automation.

- Service: http://mobo.codeya.ir/
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

- http://mobo.codeya.ir/terms
- http://mobo.codeya.ir/privacy

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
- `mobomobo.ir` as the specific Mobo/Mobomobo workflow source for checkout validation and order submission when enabled

## License

GPLv2 or later.

## WordPress.org hardening in 10.31.47

- Default MoboCore API URL uses HTTPS.
- SSL verification is enabled by default for outbound HTTP calls.
- Sensitive external workflows are disabled by default on fresh installs.
- Unsafe local/private image downloads are disabled by default and require a developer filter for local test environments.

## تماس

### بخش فروش و فعال سازی

- تلفن: `+989124508218`
- تماس مستقیم: `tel:+989124508218`
- تلگرام: https://t.me/yazdan_ghadiri
- واتساپ: https://wa.me/989124508218

### بخش فنی

- تلفن: `+989367362228`
- تلگرام: https://t.me/Codeya

