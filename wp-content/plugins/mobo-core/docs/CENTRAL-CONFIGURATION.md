# Central signed configuration

Mobo Core 10.33.0 no longer treats WordPress options as the source of truth for managed settings after the first successful bind.

## Flow

```text
Portal saves immutable revision
→ Portal signs installation/domain-bound payload with RSA private key
→ ConfigurationChanged webhook notifies WordPress
→ WordPress pulls the envelope over HTTPS
→ Embedded public key verifies RS256 signature
→ current.php is replaced atomically
→ previous.php remains Last Known Good
→ WordPress sends ACK
```

A five-minute pull is also performed as recovery when a notification is lost.

## Bootstrap values

These are connection credentials, not business settings:

```php
define( 'MOBO_API_BASE_URL', 'https://portal.example.com/' );
define( 'MOBO_TOKEN', 'license-guid' );
define( 'MOBO_SECURITY_CODE', 'installation-security-code' );
define( 'MOBO_CRON_TOKEN', 'long-random-token' );
define( 'MOBO_CONFIG_KEY_ID', 'mobo-config-v1' );
define( 'MOBO_CONFIG_CACHE_DIR', '/var/lib/mobo/config' );
```

Equivalent environment variables are supported.

Before the first bind, legacy `wp_options` values may be imported once. On successful activation, the plugin writes the four bootstrap values into `credentials.php` with mode `0600`. Afterwards, direct changes to the corresponding database rows are ignored.

## Cache files

Preferred external path:

```text
/var/lib/mobo/config/
```

Fallback:

```text
wp-content/mobo-private/
```

Files:

```text
current.php        verified active envelope
previous.php       previous verified envelope
installation.php   permanent installation binding
credentials.php    private bootstrap credentials
```

All files are atomically written and mode `0600`. The directory contains deny rules and PHP guards. An external non-web path is still preferred.

## Admin behavior

- Existing Mobo settings forms become read-only after binding.
- Local settings-save handlers reject writes.
- Category mappings and dynamic shipping mappings are read from the signed payload.
- The Central Settings page shows revision, hash, signature status, last pull, error, and a masked JSON view.
- Runtime state such as queues, cursors, timestamps, locks, cookies, and logs remains local.

## Fail-safe rules

- Invalid signature or key ID: reject.
- Wrong domain or installation ID: reject.
- Unsupported schema or unknown key: reject.
- Older revision: reject.
- Broken current cache: verify and restore previous cache.
- Both caches broken after bind: never return to mutable database configuration.
- Portal unavailable: keep Last Known Good.

## Important limitation

The signature protects integrity and authenticity, not confidentiality. Sensitive values inside the signed JSON are masked in diagnostics but exist in the private cache. Keep the cache outside the web root and restrict filesystem access.
