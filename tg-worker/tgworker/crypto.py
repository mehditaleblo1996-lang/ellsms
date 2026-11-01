"""AES-256-GCM sealing, byte-compatible with tg_encrypt()/tg_decrypt() in app/TelegramUser.php.

Format: "v1:" + base64(nonce[12] | ciphertext | tag[16]).
"""

from __future__ import annotations

import base64
import os

from cryptography.hazmat.primitives.ciphers.aead import AESGCM


def seal(key: bytes, plain: str) -> str:
    nonce = os.urandom(12)
    return "v1:" + base64.b64encode(nonce + AESGCM(key).encrypt(nonce, plain.encode(), None)).decode()


def unseal(key: bytes, sealed: str | None) -> str | None:
    if not sealed or not sealed.startswith("v1:"):
        return None
    try:
        raw = base64.b64decode(sealed[3:], validate=True)
        if len(raw) < 28:
            return None
        return AESGCM(key).decrypt(raw[:12], raw[12:], None).decode()
    except Exception:  # wrong key, tampered or truncated value
        return None
