"""ELLSMS public API v1 client for Python 3.8+ (standard library only).

    from ellsms import Client
    sms = Client("ellsms_live_…", "https://panel.example.com")
    sms.send_message(["09121234567"], "سلام")

Every method returns the "data" part of the answer and raises EllsmsError on any error.
429 answers, and transport/5xx failures of SAFE requests (GET, previews, or a POST carrying an
idempotency key / client_message_id) are retried up to max_retries times; nothing that could send
twice is ever retried.
"""

import hashlib
import hmac
import json
import secrets
import time
import urllib.error
import urllib.parse
import urllib.request

__version__ = "1.0.0"
__all__ = ["Client", "EllsmsError", "verify_webhook", "new_idempotency_key"]


class EllsmsError(Exception):
    """An API error answer, or a transport failure (status 0, code "network_error")."""

    def __init__(self, status, code, message, fields=None, request_id=None, retry_after=None):
        super().__init__(message)
        self.status = status
        self.code = code
        self.message = message
        self.fields = fields or {}
        self.request_id = request_id
        self.retry_after = retry_after

    def __repr__(self):
        return "EllsmsError(status=%r, code=%r, message=%r)" % (self.status, self.code, self.message)


def new_idempotency_key():
    return "sdk-py-" + secrets.token_hex(16)


def verify_webhook(secret, timestamp, raw_body, signature, tolerance_seconds=300, now=None):
    """Verifies an ELLSMS webhook delivery. Pass the RAW body (bytes or str), exactly as received."""
    timestamp = str(timestamp or "")
    if not timestamp.isdigit() or abs((time.time() if now is None else now) - int(timestamp)) > tolerance_seconds:
        return False
    body = raw_body.decode("utf-8") if isinstance(raw_body, (bytes, bytearray)) else str(raw_body)
    expected = hmac.new(secret.encode("utf-8"), (timestamp + "." + body).encode("utf-8"), hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, str(signature or "").strip().lower())


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Client:
    def __init__(self, api_key, base_url, timeout=30, max_retries=2, sleep=time.sleep):
        if not api_key or not str(api_key).strip():
            raise ValueError("An API key is required.")
        self.api_key = str(api_key).strip()
        base = str(base_url).rstrip("/")
        if not base.endswith("/api/v1"):
            base += "/api/v1"
        self.base_url = base
        self.timeout = max(1, timeout)
        self.max_retries = max(0, max_retries)
        self._sleep = sleep
        self._opener = urllib.request.build_opener(_NoRedirect)

    # ---------------- account ----------------
    def me(self):
        return self._request("GET", "/me")

    def organization(self):
        return self._request("GET", "/organization")

    def balance(self):
        return self._request("GET", "/balance")

    # ---------------- messages ----------------
    def send_message(self, destinations, content, originator=None, channel=None, client_message_id=None):
        """Sends one text to up to 100 numbers, synchronously."""
        body = {"destinations": list(destinations), "content": content}
        if originator is not None:
            body["originator"] = originator
        if channel is not None:
            body["channel"] = channel
        if client_message_id is not None:
            body["client_message_id"] = client_message_id
        return self._request("POST", "/messages", body, replay_safe=client_message_id is not None)

    def get_message(self, message_id):
        return self._request("GET", "/messages/" + urllib.parse.quote(str(message_id), safe=""))

    def preview_message(self, destinations, content, originator=None, channel=None):
        body = {"destinations": list(destinations), "content": content}
        if originator is not None:
            body["originator"] = originator
        if channel is not None:
            body["channel"] = channel
        return self._request("POST", "/messages/preview", body, replay_safe=True)

    # ---------------- bulk jobs ----------------
    def create_bulk_job(self, job, idempotency_key=None):
        """job: {type: p2p|smart|gradual, title, originator, items: [{mobile, content}], throttle_count?, throttle_minutes?}"""
        return self._request("POST", "/bulk-jobs", job, idempotency_key=idempotency_key or new_idempotency_key())

    def get_bulk_job(self, job_id):
        return self._request("GET", "/bulk-jobs/" + urllib.parse.quote(str(job_id), safe=""))

    def preview_bulk_job(self, job):
        return self._request("POST", "/bulk-jobs/preview", job, replay_safe=True)

    # ---------------- contacts ----------------
    def list_contacts(self, limit=50, after=None):
        """One page: {"data": [...], "next_cursor": str | None}."""
        query = {"limit": limit}
        if after is not None:
            query["after"] = after
        answer = self._request("GET", "/contacts?" + urllib.parse.urlencode(query), full=True)
        return {"data": answer.get("data") or [], "next_cursor": (answer.get("meta") or {}).get("next_cursor")}

    def each_contact(self, page_size=200):
        after = None
        while True:
            page = self.list_contacts(page_size, after)
            for contact in page["data"]:
                yield contact
            after = page["next_cursor"]
            if after is None:
                return

    def create_contact(self, mobile, name="", group=""):
        return self._request("POST", "/contacts", {"mobile": mobile, "name": name, "group": group})

    def get_contact(self, contact_id):
        return self._request("GET", "/contacts/" + urllib.parse.quote(str(contact_id), safe=""))

    def update_contact(self, contact_id, **changes):
        return self._request("PATCH", "/contacts/" + urllib.parse.quote(str(contact_id), safe=""), changes)

    def delete_contact(self, contact_id):
        self._request("DELETE", "/contacts/" + urllib.parse.quote(str(contact_id), safe=""))

    # ---------------- webhooks ----------------
    def list_webhooks(self):
        return self._request("GET", "/webhooks")

    def create_webhook(self, url, event_types, description=""):
        """Returns {"id", "secret"} — the secret is shown only this once."""
        return self._request("POST", "/webhooks", {"url": url, "event_types": list(event_types), "description": description})

    def get_webhook(self, webhook_id):
        return self._request("GET", "/webhooks/" + urllib.parse.quote(str(webhook_id), safe=""))

    def update_webhook(self, webhook_id, **changes):
        return self._request("PATCH", "/webhooks/" + urllib.parse.quote(str(webhook_id), safe=""), changes)

    def delete_webhook(self, webhook_id):
        self._request("DELETE", "/webhooks/" + urllib.parse.quote(str(webhook_id), safe=""))

    def rotate_webhook_secret(self, webhook_id):
        return self._request("POST", "/webhooks/" + urllib.parse.quote(str(webhook_id), safe="") + "/rotate-secret", {})

    def test_webhook(self, webhook_id):
        return self._request("POST", "/webhooks/" + urllib.parse.quote(str(webhook_id), safe="") + "/test", {})

    # ---------------- transport ----------------
    def _request(self, method, path, body=None, idempotency_key=None, replay_safe=False, full=False):
        retry_safe = method == "GET" or idempotency_key is not None or replay_safe
        attempt = 0
        while True:
            attempt += 1
            try:
                answer = self._once(method, path, body, idempotency_key)
                return answer if full else answer.get("data", {})
            except EllsmsError as e:
                retryable = e.status == 429 or (retry_safe and (e.status == 0 or e.status >= 500))
                if not retryable or attempt > self.max_retries:
                    raise
                wait = e.retry_after if e.retry_after is not None else min(8, 2 ** (attempt - 1))
                self._sleep(max(0, min(60, wait)))

    def _once(self, method, path, body, idempotency_key):
        headers = {
            "Authorization": "Bearer " + self.api_key,
            "Accept": "application/json",
            "User-Agent": "ellsms-python/" + __version__,
        }
        if idempotency_key is not None:
            headers["Idempotency-Key"] = idempotency_key
        data = None
        if body is not None and method not in ("GET", "DELETE"):
            headers["Content-Type"] = "application/json"
            data = json.dumps(body, ensure_ascii=False).encode("utf-8")
        request = urllib.request.Request(self.base_url + path, data=data, headers=headers, method=method)
        try:
            with self._opener.open(request, timeout=self.timeout) as response:
                status, raw, response_headers = response.status, response.read(), response.headers
        except urllib.error.HTTPError as e:
            status, raw, response_headers = e.code, e.read(), e.headers
        except (urllib.error.URLError, OSError) as e:
            raise EllsmsError(0, "network_error", "Could not reach ELLSMS: %s" % (getattr(e, "reason", None) or e))
        try:
            decoded = json.loads(raw.decode("utf-8")) if raw else {}
        except ValueError:
            decoded = None
        if 200 <= status < 300:
            return decoded if isinstance(decoded, dict) else {}
        error = decoded.get("error", {}) if isinstance(decoded, dict) and isinstance(decoded.get("error"), dict) else {}
        retry_after = response_headers.get("Retry-After") if response_headers is not None else None
        raise EllsmsError(
            status,
            error.get("code") or "http_%d" % status,
            error.get("message") or "HTTP %d" % status,
            fields=error.get("fields") or {},
            request_id=error.get("request_id") or (response_headers.get("X-Request-Id") if response_headers is not None else None),
            retry_after=int(retry_after) if retry_after and str(retry_after).isdigit() else None,
        )
