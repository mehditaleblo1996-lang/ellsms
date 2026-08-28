-- Some positional gateways return a negative numeric value (for example -N) as an error sentinel,
-- not as a real provider message reference. Historical rows created before the transport validates
-- that distinction must not be polled forever or shown as if they had a usable provider identity.
--
-- This migration is deliberately data-only and idempotent. It never touches positive/nonnumeric
-- provider references, and only terminalizes rows whose provider_message_id is explicitly a negative
-- integer string.

UPDATE ellsms_message_attempts
SET delivery_status = 'rejected',
    provider_status = 'provider_rejected',
    delivery_checked_at = COALESCE(delivery_checked_at, UTC_TIMESTAMP())
WHERE status = 'accepted'
  AND provider_message_id REGEXP '^-[0-9]+$'
  AND (delivery_status IS NULL OR delivery_status NOT IN ('delivered','failed','rejected','expired'));

UPDATE ellsms_bulk_items
SET delivery_status = 'rejected',
    provider_status = 'provider_rejected',
    delivery_checked_at = COALESCE(delivery_checked_at, UTC_TIMESTAMP())
WHERE provider_message_id REGEXP '^-[0-9]+$'
  AND (delivery_status IS NULL OR delivery_status NOT IN ('delivered','failed','rejected','expired'));
