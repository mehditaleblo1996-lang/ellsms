"""Every SQL statement the worker runs against the ellsms_tg_* tables
(db/migrations/2026_11_01_telegram_user_service.sql). The panel (app/TelegramUser.php) owns money and
webhooks; the worker only moves rows between states and keeps per-account counters."""

from __future__ import annotations

from datetime import datetime, timedelta
from zoneinfo import ZoneInfo

from .db import Database

ACTIVE_CAMPAIGN = "('queued','running')"


class Store:
    def __init__(self, db: Database, timezone: str):
        self.db = db
        self.tz = ZoneInfo(timezone)

    # ---- time -----------------------------------------------------------------------------------
    def now_dt(self) -> datetime:
        return datetime.now(self.tz).replace(tzinfo=None, microsecond=0)

    def now(self, delta_seconds: float = 0) -> str:
        return (self.now_dt() + timedelta(seconds=delta_seconds)).strftime("%Y-%m-%d %H:%M:%S")

    def today(self) -> str:
        return self.now_dt().strftime("%Y-%m-%d")

    # ---- settings and limits --------------------------------------------------------------------
    def setting(self, key: str, default: str) -> str:
        row = self.db.one("SELECT svalue FROM ellsms_settings WHERE skey = %s", (key,))
        value = (row or {}).get("svalue")
        return str(value) if value not in (None, "") else default

    def setting_int(self, key: str, default: int) -> int:
        value = self.setting(key, "")
        return max(0, int(value)) if value.isdigit() else default

    def service_enabled(self) -> bool:
        return self.setting("tg_enabled", "0") == "1"

    def user_limits(self, user_id: int) -> dict:
        """Same rules as tg_user_config() on the panel side (only what the worker needs)."""
        limits = {
            "daily_send_limit": self.setting_int("tg_default_daily_send", 50),
            "daily_check_limit": self.setting_int("tg_default_daily_check", 100),
            "min_interval_seconds": self.setting_int("tg_default_interval", 15),
        }
        row = self.db.one("SELECT * FROM ellsms_tg_user_settings WHERE user_id = %s", (user_id,))
        if row:
            for key in limits:
                if row.get(key) is not None:
                    limits[key] = int(row[key])
        admin = self.db.one("SELECT is_admin FROM ellsms_meta WHERE user_id = %s", (user_id,))
        is_admin = bool(admin and int(admin.get("is_admin") or 0) == 1)
        limits["enabled"] = is_admin or bool(row and int(row["enabled"]) == 1)
        limits["min_interval_seconds"] = max(3, limits["min_interval_seconds"])
        return limits

    def heartbeat(self, api_configured: bool, crypto_ready: bool, version: str, connected: int, note: str | None) -> None:
        self.db.execute(
            "INSERT INTO ellsms_tg_worker_status (id, last_seen_at, api_configured, crypto_ready, version, connected_accounts, note)"
            " VALUES (1,%s,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at), api_configured = VALUES(api_configured),"
            " crypto_ready = VALUES(crypto_ready), version = VALUES(version), connected_accounts = VALUES(connected_accounts), note = VALUES(note)",
            (self.now(), int(api_configured), int(crypto_ready), version, connected, (note or "")[:255] or None),
        )

    # ---- login requests -------------------------------------------------------------------------
    def pending_logins(self) -> list[dict]:
        return self.db.all(
            "SELECT * FROM ellsms_tg_login_requests WHERE state IN ('requested','code_submitted','password_submitted')"
            " AND expires_at > %s ORDER BY id LIMIT 20", (self.now(),))

    def login_get(self, request_id: int) -> dict | None:
        return self.db.one("SELECT * FROM ellsms_tg_login_requests WHERE id = %s", (request_id,))

    def login_update(self, request_id: int, expect_state: str | None = None, **fields) -> bool:
        fields["updated_at"] = self.now()
        sets = ", ".join(f"{k} = %s" for k in fields)
        params = list(fields.values()) + [request_id]
        sql = f"UPDATE ellsms_tg_login_requests SET {sets} WHERE id = %s"
        if expect_state is not None:
            sql += " AND state = %s"
            params.append(expect_state)
        return self.db.execute(sql, params) == 1

    def expire_logins(self) -> None:
        self.db.execute(
            "UPDATE ellsms_tg_login_requests SET state = 'expired', code = NULL, password_enc = NULL, temp_session_enc = NULL, updated_at = %s"
            " WHERE state NOT IN ('done','failed','expired') AND expires_at < %s", (self.now(), self.now()))

    def account_upsert(self, user_id: int, phone: str, session_enc: str, tg_user_id: int, username: str | None, name: str | None) -> int:
        now = self.now()
        self.db.execute(
            "INSERT INTO ellsms_tg_accounts (user_id, phone, tg_user_id, tg_username, display_name, status, session_enc, last_error,"
            " logout_requested, limited_until, created_at, updated_at, disconnected_at)"
            " VALUES (%s,%s,%s,%s,%s,'active',%s,NULL,0,NULL,%s,%s,NULL)"
            " ON DUPLICATE KEY UPDATE tg_user_id = VALUES(tg_user_id), tg_username = VALUES(tg_username), display_name = VALUES(display_name),"
            " status = 'active', session_enc = VALUES(session_enc), last_error = NULL, logout_requested = 0, limited_until = NULL,"
            " updated_at = VALUES(updated_at), disconnected_at = NULL",
            (user_id, phone, tg_user_id, username, name, session_enc, now, now))
        row = self.db.one("SELECT id FROM ellsms_tg_accounts WHERE user_id = %s AND phone = %s", (user_id, phone))
        return int(row["id"]) if row else 0

    # ---- accounts -------------------------------------------------------------------------------
    def account(self, account_id: int) -> dict | None:
        return self.db.one("SELECT * FROM ellsms_tg_accounts WHERE id = %s", (account_id,))

    def accounts_needing_runner(self, read_window_hours: int, service_on: bool) -> list[int]:
        """Accounts with a session and something to do: logout, queued work of their owner, or sent
        messages still waiting to be read."""
        if not service_on:
            rows = self.db.all("SELECT id FROM ellsms_tg_accounts WHERE logout_requested = 1 AND session_enc IS NOT NULL")
            return [int(r["id"]) for r in rows]
        since = self.now(-read_window_hours * 3600)
        rows = self.db.all(
            "SELECT a.id FROM ellsms_tg_accounts a WHERE a.session_enc IS NOT NULL AND ("
            "  a.logout_requested = 1"
            "  OR (a.status IN ('active','limited') AND ("
            "    EXISTS (SELECT 1 FROM ellsms_tg_messages m JOIN ellsms_tg_campaigns c ON c.id = m.campaign_id"
            f"            WHERE m.user_id = a.user_id AND m.status = 'queued' AND c.status IN {ACTIVE_CAMPAIGN}"
            "             AND (c.account_id IS NULL OR c.account_id = a.id))"
            "    OR EXISTS (SELECT 1 FROM ellsms_tg_lookup_items i JOIN ellsms_tg_lookup_jobs j ON j.id = i.job_id"
            "            WHERE i.user_id = a.user_id AND i.status = 'queued' AND j.status IN ('queued','running')"
            "             AND (j.account_id IS NULL OR j.account_id = a.id))"
            "    OR EXISTS (SELECT 1 FROM ellsms_tg_messages m WHERE m.account_id = a.id AND m.status = 'sent' AND m.sent_at > %s)"
            "  ))"
            ")", (since,))
        return [int(r["id"]) for r in rows]

    def account_update(self, account_id: int, **fields) -> None:
        fields["updated_at"] = self.now()
        sets = ", ".join(f"{k} = %s" for k in fields)
        self.db.execute(f"UPDATE ellsms_tg_accounts SET {sets} WHERE id = %s", list(fields.values()) + [account_id])

    def account_counters(self, account_id: int) -> dict:
        """Today's counters, resetting them on the first call of a new day."""
        today = self.today()
        self.db.execute(
            "UPDATE ellsms_tg_accounts SET counter_date = %s, sent_today = 0, checked_today = 0"
            " WHERE id = %s AND (counter_date IS NULL OR counter_date <> %s)", (today, account_id, today))
        row = self.db.one("SELECT sent_today, checked_today FROM ellsms_tg_accounts WHERE id = %s", (account_id,)) or {}
        return {"sent_today": int(row.get("sent_today") or 0), "checked_today": int(row.get("checked_today") or 0)}

    def account_count(self, account_id: int, column: str, amount: int = 1) -> None:
        assert column in ("sent_today", "checked_today")
        self.db.execute(f"UPDATE ellsms_tg_accounts SET {column} = {column} + %s, last_action_at = %s WHERE id = %s",
                        (amount, self.now(), account_id))

    def account_limit(self, account_id: int, seconds: int, reason: str) -> None:
        self.account_update(account_id, status="limited", limited_until=self.now(seconds), last_error=reason[:500])

    def account_clear_expired_limit(self, account_id: int) -> None:
        self.db.execute("UPDATE ellsms_tg_accounts SET status = 'active', limited_until = NULL WHERE id = %s AND status = 'limited'"
                        " AND (limited_until IS NULL OR limited_until <= %s)", (account_id, self.now()))

    def account_disconnected(self, account_id: int, status: str, reason: str | None) -> None:
        """Session gone (logged out, revoked or banned): wipe it and give its in-flight work back."""
        self.account_update(account_id, status=status, session_enc=None, last_error=(reason or None) and reason[:500],
                            logout_requested=0, disconnected_at=self.now())
        self.db.execute("UPDATE ellsms_tg_messages SET status = 'queued', account_id = NULL, claimed_at = NULL, updated_at = %s"
                        " WHERE account_id = %s AND status = 'sending'", (self.now(), account_id))
        self.db.execute("UPDATE ellsms_tg_lookup_items SET status = 'queued', account_id = NULL WHERE account_id = %s AND status = 'checking'",
                        (account_id,))

    # ---- messages -------------------------------------------------------------------------------
    def claim_message(self, account_id: int, user_id: int) -> dict | None:
        row = self.db.one(
            "SELECT m.id, m.phone, m.campaign_id, c.kind, c.content, c.media_path, c.media_kind, c.media_name"
            " FROM ellsms_tg_messages m JOIN ellsms_tg_campaigns c ON c.id = m.campaign_id"
            f" WHERE m.user_id = %s AND m.status = 'queued' AND c.status IN {ACTIVE_CAMPAIGN}"
            " AND (c.account_id IS NULL OR c.account_id = %s)"
            " ORDER BY (c.kind = 'otp') DESC, m.id LIMIT 1", (user_id, account_id))
        if not row:
            return None
        now = self.now()
        claimed = self.db.execute(
            "UPDATE ellsms_tg_messages SET status = 'sending', account_id = %s, claimed_at = %s, attempts = attempts + 1, updated_at = %s"
            " WHERE id = %s AND status = 'queued'", (account_id, now, now, row["id"]))
        if claimed != 1:
            return None
        self.db.execute("UPDATE ellsms_tg_campaigns SET status = 'running' WHERE id = %s AND status = 'queued'", (row["campaign_id"],))
        return row

    def has_queued_otp(self, account_id: int, user_id: int) -> bool:
        return self.db.one(
            "SELECT 1 AS x FROM ellsms_tg_messages m JOIN ellsms_tg_campaigns c ON c.id = m.campaign_id"
            f" WHERE m.user_id = %s AND m.status = 'queued' AND c.kind = 'otp' AND c.status IN {ACTIVE_CAMPAIGN}"
            " AND (c.account_id IS NULL OR c.account_id = %s) LIMIT 1", (user_id, account_id)) is not None

    def message_sent(self, message_id: int, peer_id: int, access_hash: int | None, tg_message_id: int) -> None:
        now = self.now()
        self.db.execute(
            "UPDATE ellsms_tg_messages SET status = 'sent', tg_peer_id = %s, tg_access_hash = %s, tg_message_id = %s, sent_at = %s,"
            " error_code = NULL, error = NULL, updated_at = %s WHERE id = %s AND status = 'sending'",
            (peer_id, access_hash, tg_message_id, now, now, message_id))

    def message_failed(self, message_id: int, code: str, error: str | None = None) -> None:
        self.db.execute(
            "UPDATE ellsms_tg_messages SET status = 'failed', error_code = %s, error = %s, updated_at = %s WHERE id = %s AND status = 'sending'",
            (code, (error or None) and error[:500], self.now(), message_id))

    def message_requeue(self, message_id: int, error: str | None = None) -> None:
        self.db.execute(
            "UPDATE ellsms_tg_messages SET status = 'queued', account_id = NULL, claimed_at = NULL, error = %s, updated_at = %s"
            " WHERE id = %s AND status = 'sending'", ((error or None) and error[:500], self.now(), message_id))

    def known_peer(self, account_id: int, phone: str) -> dict | None:
        """A peer this account already resolved for this number (access hashes are per account)."""
        row = self.db.one(
            "SELECT tg_peer_id, tg_access_hash FROM ellsms_tg_messages WHERE account_id = %s AND phone = %s"
            " AND tg_peer_id IS NOT NULL AND tg_access_hash IS NOT NULL ORDER BY id DESC LIMIT 1", (account_id, phone))
        return row

    def mark_read(self, account_id: int, peer_id: int, max_id: int) -> int:
        now = self.now()
        return self.db.execute(
            "UPDATE ellsms_tg_messages SET status = 'read', read_at = %s, updated_at = %s"
            " WHERE account_id = %s AND tg_peer_id = %s AND status = 'sent' AND tg_message_id <= %s",
            (now, now, account_id, peer_id, max_id))

    def unread_peers(self, account_id: int, window_hours: int, limit: int = 100) -> list[dict]:
        return self.db.all(
            "SELECT tg_peer_id, MAX(tg_access_hash) AS tg_access_hash FROM ellsms_tg_messages"
            " WHERE account_id = %s AND status = 'sent' AND sent_at > %s AND tg_peer_id IS NOT NULL AND tg_access_hash IS NOT NULL"
            " GROUP BY tg_peer_id LIMIT %s", (account_id, self.now(-window_hours * 3600), limit))

    def has_unread(self, account_id: int, window_hours: int) -> bool:
        return self.db.one("SELECT 1 AS x FROM ellsms_tg_messages WHERE account_id = %s AND status = 'sent' AND sent_at > %s LIMIT 1",
                           (account_id, self.now(-window_hours * 3600))) is not None

    def has_pending_work(self, account_id: int, user_id: int) -> bool:
        message = self.db.one(
            "SELECT 1 AS x FROM ellsms_tg_messages m JOIN ellsms_tg_campaigns c ON c.id = m.campaign_id"
            f" WHERE m.user_id = %s AND m.status = 'queued' AND c.status IN {ACTIVE_CAMPAIGN}"
            " AND (c.account_id IS NULL OR c.account_id = %s) LIMIT 1", (user_id, account_id))
        if message is not None:
            return True
        return self.db.one(
            "SELECT 1 AS x FROM ellsms_tg_lookup_items i JOIN ellsms_tg_lookup_jobs j ON j.id = i.job_id"
            " WHERE i.user_id = %s AND i.status = 'queued' AND j.status IN ('queued','running')"
            " AND (j.account_id IS NULL OR j.account_id = %s) LIMIT 1", (user_id, account_id)) is not None

    # ---- lookups --------------------------------------------------------------------------------
    def claim_lookups(self, account_id: int, user_id: int, limit: int) -> list[dict]:
        rows = self.db.all(
            "SELECT i.id, i.phone, i.job_id FROM ellsms_tg_lookup_items i JOIN ellsms_tg_lookup_jobs j ON j.id = i.job_id"
            " WHERE i.user_id = %s AND i.status = 'queued' AND j.status IN ('queued','running')"
            " AND (j.account_id IS NULL OR j.account_id = %s) ORDER BY i.id LIMIT %s", (user_id, account_id, limit))
        claimed = []
        now = self.now()
        for row in rows:
            if self.db.execute("UPDATE ellsms_tg_lookup_items SET status = 'checking', account_id = %s, checked_at = %s WHERE id = %s AND status = 'queued'",
                               (account_id, now, row["id"])) == 1:
                claimed.append(row)
        for job_id in {r["job_id"] for r in claimed}:
            self.db.execute("UPDATE ellsms_tg_lookup_jobs SET status = 'running' WHERE id = %s AND status = 'queued'", (job_id,))
        return claimed

    def cached_lookup(self, user_id: int, phone: str, days: int = 30) -> dict | None:
        return self.db.one(
            "SELECT status, tg_user_id, tg_username, tg_name FROM ellsms_tg_lookup_items WHERE user_id = %s AND phone = %s"
            " AND status IN ('found','not_found') AND checked_at > %s ORDER BY id DESC LIMIT 1",
            (user_id, phone, self.now(-days * 86400)))

    def lookup_result(self, item_id: int, status: str, tg_user_id: int | None = None, username: str | None = None,
                      name: str | None = None, error: str | None = None) -> None:
        self.db.execute(
            "UPDATE ellsms_tg_lookup_items SET status = %s, tg_user_id = %s, tg_username = %s, tg_name = %s, error = %s, checked_at = %s"
            " WHERE id = %s AND status = 'checking'",
            (status, tg_user_id, username, (name or None) and name[:190], (error or None) and error[:255], self.now(), item_id))

    def lookup_requeue(self, item_ids: list[int]) -> None:
        for item_id in item_ids:
            self.db.execute("UPDATE ellsms_tg_lookup_items SET status = 'queued', account_id = NULL WHERE id = %s AND status = 'checking'", (item_id,))
