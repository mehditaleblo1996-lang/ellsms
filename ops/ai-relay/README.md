# AI relay (relay خارج از کشور)

OpenAI, Anthropic and OpenRouter refuse requests from Iranian addresses. ELLSMS offers two ways
around this; both are set up per provider under Platform Admin → «موتورهای هوش مصنوعی». See
[docs/ai-engines.md](../../docs/ai-engines.md) §Proxy and relay.

1. **Proxy** (`socks5://…` or `http://…`): enter its URL under «پراکسی / relay خارج از کشور». The
   URL is stored in the AI key vault and never shown again. Every request of that provider goes
   through it, and the proxy resolves the provider's hostname (`socks5` is sent as `socks5h`). This
   works with any provider and needs only a proxy you already run.
2. **Relay** (this directory): a small nginx reverse proxy on a server abroad. ELLSMS calls it like
   the provider itself. No proxy software is needed on the ELLSMS side.

## Setting up the relay

On a server outside Iran with a domain pointing at it:

```bash
mkdir -p ai-relay/certs && cd ai-relay
# copy nginx.conf.template and docker-compose.yml from this directory
# put a TLS certificate in certs/fullchain.pem and certs/privkey.pem (e.g. from certbot)
export RELAY_DOMAIN=relay.example.com
export RELAY_KEY=$(openssl rand -hex 32)
docker compose up -d
```

Then, for each provider in ELLSMS:

| Provider type | Base URL | Extra header |
|---|---|---|
| OpenAI | `https://relay.example.com/openai/v1` | `X-Relay-Key: <RELAY_KEY>` |
| Anthropic | `https://relay.example.com/anthropic/v1` | `X-Relay-Key: <RELAY_KEY>` |
| OpenRouter | `https://relay.example.com/openrouter/api/v1` | `X-Relay-Key: <RELAY_KEY>` |

The provider's own API key is entered in ELLSMS as usual. The relay passes it through and stores
nothing. A request without the right `X-Relay-Key` gets 403. Press «تست اتصال» and
«تشخیص مدل‌ها» to check.

Keep `RELAY_KEY` secret. Anyone who has it can use the relay, though not your provider API keys,
which only ELLSMS holds.
