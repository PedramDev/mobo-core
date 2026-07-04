# Mobo Core 10.25.0

Fixes a fatal error in UpdateVariant lightweight webhook processing where `Mobo_Core_Webhook_Queue::is_list_array()` was referenced but not defined.

No database migration is required.
