# Sending gateway per number

On the **Numbers** page (`/numbers.php`, platform admin), each number has a **Sending gateway** column:

- **Default (by route)** is today's behaviour. The number sends through its route's gateway (sender route,
  then operator route, then default route). If the route names no gateway, it uses the default gateway.
- **A chosen gateway** means everything this number sends goes through that gateway only. This covers
  direct sends, bulk, scheduled sends, the API and auto-reply.

## Rules

| Topic | Behaviour |
|---|---|
| Price | Unchanged. The price still comes from the route (`sms_pricing_route_for_sender`). Only the carrier changes. |
| Gateway cannot send | If the gateway is archived, has sending switched off, or is a mock while mocks are disabled, the message is **not** sent through any other gateway or the legacy path. The send fails as **retryable**: bulk jobs retry later, a direct send shows an error, and nothing is charged. The numbers page flags such a number in red. |
| Choosing | Only active gateways with sending switched on are offered, and the server re-checks that on save. |
| Message type | One gateway per number, for every message type. |
| Inbound | Not affected. Inbound polling works as before. |
| Gateway mode | The choice only takes effect with `SMS_GATEWAY_TRANSPORT=1`. The page warns when that mode is off. |
| Propagation | Pins are cached for the gateway version-check interval (`gateway_version_check_seconds()`). Running workers pick up a change within that interval, and the page that made the change sees it at once. |

## Code

- **Column:** `ellsms_numbers.gateway_id`, added by migration `2026_09_30_number_gateway.sql`.
- **Resolution:** `gateway_for_sender($originator, $route)` in `app/Sms/GatewayCache.php` picks the pinned
  gateway, or falls back to `gateway_for_route()`. All pins are loaded in one cached query.
- **Callers:**
  - `gateway_send_for_dispatch_group()` and `gateway_connector_capability_for_sender()`
  - `bulk_job_provider_key()`, so "cancel everything queued on a provider" sees the pinned gateway
  - `cron/sms-gateway-simulate.php`
- **Tests:** `tests/Integration/NumberGatewayTest.php` and `NumberGatewayHttpTest.php`.
