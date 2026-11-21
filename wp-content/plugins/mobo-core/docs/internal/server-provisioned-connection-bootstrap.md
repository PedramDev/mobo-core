# Server-Provisioned Connection Bootstrap

Implemented contract:

1. The WordPress plugin ships with provider adapters only; no Source identity or target URL is compiled into the package.
2. The customer stores a license Token in WordPress.
3. The source-specific .NET deployment knows the licensed WordPress domain and pushes Connection provisioning to:
   `/wp-json/mobo-core/v1/connection/provision`
4. The push is authenticated with HMAC-SHA256 using the license Token:
   `timestamp + "\n" + nonce + "\n" + raw JSON body`
5. Ordinary provisioning is idempotent for the same Token/Source/Provider, can refresh URLs/name/enabled state, and rejects silent Source/Provider rebinding.
6. One Token maps to one Connection.
7. Provider/Source/API/Source-site routing is read-only in WordPress and server-owned.
