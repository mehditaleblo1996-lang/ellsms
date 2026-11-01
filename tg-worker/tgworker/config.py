"""Environment configuration of the tg-worker container (see docs/telegram-user-service.md)."""

from __future__ import annotations

import hashlib
import os
from dataclasses import dataclass
from urllib.parse import unquote, urlparse

VERSION = "1.0.0"
KEY_PREFIX = b"ellsms-telegram-session-v1|"


def _env(name: str, default: str = "") -> str:
    value = os.environ.get(name, "")
    return value.strip() if value.strip() != "" else default


def crypto_key_from_env(env: dict[str, str] | None = None) -> bytes | None:
    """The 32-byte key shared with the panel — identical derivation to tg_crypto_key() in app/TelegramUser.php."""
    env = os.environ if env is None else env
    own = (env.get("TELEGRAM_SESSION_KEY") or "").strip()
    if own:
        if len(own) == 64 and all(c in "0123456789abcdefABCDEF" for c in own):
            return bytes.fromhex(own)
        return hashlib.sha256(KEY_PREFIX + own.encode()).digest()
    master = (env.get("SMS_GATEWAY_MASTER_KEY") or "").strip()
    if master:
        return hashlib.sha256(KEY_PREFIX + master.encode()).digest()
    return None


def parse_proxy(raw: str) -> dict | None:
    """TELEGRAM_PROXY=socks5://user:pass@host:1080 (or socks4:// / http://) -> Telethon's proxy dict."""
    if not raw:
        return None
    url = urlparse(raw)
    if url.scheme not in ("socks5", "socks4", "http") or not url.hostname or not url.port:
        raise ValueError("TELEGRAM_PROXY must look like socks5://[user:pass@]host:port")
    proxy = {"proxy_type": url.scheme, "addr": url.hostname, "port": url.port, "rdns": True}
    if url.username:
        proxy["username"] = unquote(url.username)
    if url.password:
        proxy["password"] = unquote(url.password)
    return proxy


@dataclass(frozen=True)
class Config:
    api_id: int
    api_hash: str
    proxy: dict | None
    key: bytes | None
    db_host: str
    db_port: int
    db_name: str
    db_user: str
    db_pass: str
    media_dir: str
    timezone: str
    poll_seconds: float
    idle_seconds: int
    read_window_hours: int
    lookup_batch: int

    @property
    def api_configured(self) -> bool:
        return self.api_id > 0 and self.api_hash != ""

    @classmethod
    def from_env(cls) -> "Config":
        api_id_raw = _env("TELEGRAM_API_ID", "0")
        return cls(
            api_id=int(api_id_raw) if api_id_raw.isdigit() else 0,
            api_hash=_env("TELEGRAM_API_HASH"),
            proxy=parse_proxy(_env("TELEGRAM_PROXY")),
            key=crypto_key_from_env(),
            db_host=_env("BACKEND_DB_HOST", "localhost"),
            db_port=int(_env("BACKEND_DB_PORT", "3306")),
            db_name=_env("BACKEND_DB_NAME"),
            db_user=_env("BACKEND_DB_USER"),
            db_pass=os.environ.get("BACKEND_DB_PASS", ""),
            media_dir=_env("TG_MEDIA_DIR", "/data/media"),
            timezone=_env("APP_TIMEZONE", "Asia/Tehran"),
            poll_seconds=float(_env("TG_POLL_SECONDS", "1")),
            idle_seconds=int(_env("TG_IDLE_SECONDS", "300")),
            read_window_hours=int(_env("TG_READ_WINDOW_HOURS", "48")),
            lookup_batch=max(1, min(50, int(_env("TG_LOOKUP_BATCH", "10")))),
        )
