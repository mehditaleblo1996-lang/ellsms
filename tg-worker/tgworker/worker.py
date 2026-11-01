"""tg-worker: every call to Telegram for the panel's Telegram user-account service.

One asyncio process. The main loop (every TG_POLL_SECONDS):
  - writes a heartbeat to ellsms_tg_worker_status,
  - advances login requests (send code -> sign in -> 2FA password),
  - starts one AccountRunner per account that has something to do (logout, queued messages or
    lookups of its owner, or sent messages still waiting to be read).

An AccountRunner keeps that account's Telethon client connected while it works, sends one message at
a time with the owner's minimum interval, checks numbers in small batches, honours the daily caps,
stops the account on Telegram's first anti-spam signal (PEER_FLOOD / FLOOD_WAIT), tracks read
receipts (live updates + a periodic sweep), and disconnects after TG_IDLE_SECONDS of nothing to do.

Run exactly ONE instance: claims are race-safe, but the per-account pacing assumes a single process.
"""

from __future__ import annotations

import asyncio
import logging
import os
import random
import signal
import time

from telethon import TelegramClient, errors, events, functions, types
from telethon.sessions import StringSession

from . import crypto
from .config import VERSION, Config
from .db import Database
from .store import Store

log = logging.getLogger("tgworker")

DEVICE = dict(device_model="ELLSMS Panel", system_version="Linux", app_version=f"ELLSMS tg-worker {VERSION}",
              lang_code="fa", system_lang_code="fa")


class ImportLimited(Exception):
    """Telegram accepted none of the numbers of a contact import (retry_contacts): the account is
    importing too much and must rest."""


class AccountGone(Exception):
    """The account's session no longer works (logged out elsewhere, revoked, or banned)."""

    def __init__(self, status: str, reason: str):
        super().__init__(reason)
        self.status = status
        self.reason = reason


def classify_send_error(exc: Exception) -> tuple[str, str | None, int]:
    """(action, error_code, seconds) for an exception raised while sending one message.

    action: 'fail' (this message failed for a reason of the recipient), 'limit' (the sending account
    hit an anti-spam limit: stop it for `seconds`), 'gone' (the session is dead), 'retry' (transient).
    """
    if isinstance(exc, errors.PeerFloodError):
        return "limit", "account_limited", 24 * 3600
    if isinstance(exc, ImportLimited):
        return "limit", "account_limited", 6 * 3600
    if isinstance(exc, errors.FloodWaitError):
        return "limit", "flood_wait", max(60, int(getattr(exc, "seconds", 60)))
    if isinstance(exc, (errors.UserPrivacyRestrictedError,)) or type(exc).__name__ in ("PrivacyPremiumRequiredError",):
        return "fail", "privacy_restricted", 0
    if isinstance(exc, (errors.UserIsBlockedError, errors.YouBlockedUserError)):
        return "fail", "blocked", 0
    if isinstance(exc, (errors.InputUserDeactivatedError,)):
        return "fail", "deactivated", 0
    if isinstance(exc, (errors.AuthKeyUnregisteredError, errors.SessionRevokedError, errors.AuthKeyDuplicatedError,
                        errors.SessionExpiredError)):
        return "gone", None, 0
    if isinstance(exc, (errors.UserDeactivatedBanError, errors.UserDeactivatedError)):
        return "gone", None, 0
    if isinstance(exc, (ConnectionError, OSError, asyncio.TimeoutError, errors.ServerError)):
        return "retry", None, 0
    return "fail", "error", 0


class Worker:
    def __init__(self, config: Config):
        self.config = config
        self.store = Store(Database(config.db_host, config.db_port, config.db_name, config.db_user, config.db_pass), config.timezone)
        self.runners: dict[int, asyncio.Task] = {}
        self.login_clients: dict[int, TelegramClient] = {}
        self.login_busy: set[int] = set()
        self.stopping = asyncio.Event()
        self._last_heartbeat = 0.0
        self._last_schedule = 0.0

    # ---- lifecycle ------------------------------------------------------------------------------
    def new_client(self, session: str = "", flood_sleep: int = 0) -> TelegramClient:
        return TelegramClient(StringSession(session), self.config.api_id, self.config.api_hash, proxy=self.config.proxy,
                              flood_sleep_threshold=flood_sleep, request_retries=2, connection_retries=3, retry_delay=2,
                              receive_updates=True, **DEVICE)

    def note(self) -> str | None:
        if not self.config.api_configured:
            return "TELEGRAM_API_ID / TELEGRAM_API_HASH not set"
        if self.config.key is None:
            return "TELEGRAM_SESSION_KEY / SMS_GATEWAY_MASTER_KEY not set"
        return None

    async def run(self) -> None:
        log.info("tg-worker %s starting (api configured: %s, proxy: %s)", VERSION, self.config.api_configured, bool(self.config.proxy))
        while not self.stopping.is_set():
            started = time.monotonic()
            try:
                self.tick()
                if self.config.api_configured and self.config.key is not None:
                    await self.handle_logins()
                    if time.monotonic() - self._last_schedule >= 3:
                        self._last_schedule = time.monotonic()
                        self.schedule_runners()
            except Exception:
                log.exception("main loop pass failed")
            delay = max(0.2, self.config.poll_seconds - (time.monotonic() - started))
            try:
                await asyncio.wait_for(self.stopping.wait(), timeout=delay)
            except asyncio.TimeoutError:
                pass
        log.info("stopping: %d account runner(s), %d login client(s)", len(self.runners), len(self.login_clients))
        for task in list(self.runners.values()):
            task.cancel()
        await asyncio.gather(*self.runners.values(), return_exceptions=True)
        for client in list(self.login_clients.values()):
            await client.disconnect()

    def tick(self) -> None:
        if time.monotonic() - self._last_heartbeat >= 15:
            self._last_heartbeat = time.monotonic()
            self.store.heartbeat(self.config.api_configured, self.config.key is not None, VERSION, len(self.runners), self.note())
            self.store.expire_logins()

    # ---- logins ---------------------------------------------------------------------------------
    async def handle_logins(self) -> None:
        for request in self.store.pending_logins():
            rid = int(request["id"])
            if rid in self.login_busy:
                continue
            self.login_busy.add(rid)
            asyncio.create_task(self._login_step(request))
        # Clients of finished/expired attempts are closed.
        for rid in list(self.login_clients):
            row = self.store.login_get(rid)
            if row is None or row["state"] in ("done", "failed", "expired"):
                client = self.login_clients.pop(rid)
                await client.disconnect()

    async def _login_client(self, request: dict) -> TelegramClient:
        rid = int(request["id"])
        client = self.login_clients.get(rid)
        if client is None:
            session = crypto.unseal(self.config.key, request.get("temp_session_enc")) or ""
            client = self.new_client(session, flood_sleep=0)
            self.login_clients[rid] = client
        if not client.is_connected():
            await client.connect()
        return client

    async def _login_step(self, request: dict) -> None:
        rid = int(request["id"])
        state = request["state"]
        phone = "+" + str(request["phone"])
        try:
            client = await self._login_client(request)
            if state == "requested":
                sent = await client.send_code_request(phone)
                self.store.login_update(rid, "requested", state="code_sent", phone_code_hash=sent.phone_code_hash,
                                        temp_session_enc=crypto.seal(self.config.key, client.session.save()))
                log.info("login %s: code sent", rid)
            elif state == "code_submitted":
                code = str(request.get("code") or "")
                try:
                    await client.sign_in(phone=phone, code=code, phone_code_hash=request.get("phone_code_hash"))
                except errors.SessionPasswordNeededError:
                    hint = None
                    try:
                        hint = (await client(functions.account.GetPasswordRequest())).hint
                    except Exception:
                        pass
                    self.store.login_update(rid, "code_submitted", state="password_needed", code=None, password_hint=(hint or None) and hint[:128],
                                            temp_session_enc=crypto.seal(self.config.key, client.session.save()))
                    return
                except errors.PhoneCodeInvalidError:
                    self.store.login_update(rid, "code_submitted", state="code_sent", code=None, error_code="code_invalid",
                                            error="کد واردشده اشتباه است؛ دوباره وارد کنید.")
                    return
                await self._login_done(rid, request, client)
            elif state == "password_submitted":
                password = crypto.unseal(self.config.key, request.get("password_enc")) or ""
                try:
                    await client.sign_in(password=password)
                except errors.PasswordHashInvalidError:
                    self.store.login_update(rid, "password_submitted", state="password_needed", password_enc=None,
                                            error_code="password_invalid", error="رمز دومرحله‌ای اشتباه است.")
                    return
                await self._login_done(rid, request, client)
        except errors.PhoneNumberInvalidError:
            self._login_failed(rid, state, "phone_invalid", "این شماره در تلگرام معتبر نیست.")
        except errors.PhoneNumberBannedError:
            self._login_failed(rid, state, "phone_banned", "این شماره از طرف تلگرام مسدود شده است.")
        except (errors.PhoneCodeExpiredError,):
            self._login_failed(rid, state, "code_expired", "کد ورود منقضی شد؛ دوباره تلاش کنید.")
        except errors.FloodWaitError as exc:
            self._login_failed(rid, state, "flood_wait", f"تلگرام تلاش‌های زیاد را محدود کرده است؛ {max(1, int(exc.seconds) // 60)} دقیقه‌ی دیگر دوباره تلاش کنید.")
        except (errors.PhoneNumberFloodError, errors.PhonePasswordFloodError):
            self._login_failed(rid, state, "flood", "تلاش‌های ورود این شماره زیاد بوده است؛ چند ساعت بعد دوباره تلاش کنید.")
        except errors.ApiIdInvalidError:
            self._login_failed(rid, state, "api_invalid", "مشخصات API تلگرام سرور نامعتبر است؛ به مدیر سامانه اطلاع دهید.")
        except errors.RPCError as exc:
            log.warning("login %s failed: %s", rid, exc)
            self._login_failed(rid, state, "rpc_error", f"تلگرام درخواست را رد کرد: {exc.message if hasattr(exc, 'message') else exc}")
        except (ConnectionError, OSError, asyncio.TimeoutError) as exc:
            # Leave the request in its state: the next pass retries it until it expires.
            log.warning("login %s: network problem: %s", rid, exc)
            await asyncio.sleep(3)
        except Exception as exc:
            log.exception("login %s failed", rid)
            self._login_failed(rid, state, "error", f"خطای داخلی: {type(exc).__name__}")
        finally:
            self.login_busy.discard(rid)

    def _login_failed(self, rid: int, state: str, code: str, message: str) -> None:
        self.store.login_update(rid, state, state="failed", code=None, password_enc=None, temp_session_enc=None,
                                error_code=code, error=message[:500])

    async def _login_done(self, rid: int, request: dict, client: TelegramClient) -> None:
        me = await client.get_me()
        name = " ".join(p for p in [getattr(me, "first_name", None), getattr(me, "last_name", None)] if p) or None
        account_id = self.store.account_upsert(int(request["user_id"]), str(request["phone"]),
                                               crypto.seal(self.config.key, client.session.save()), int(me.id),
                                               getattr(me, "username", None), name)
        self.store.login_update(rid, None, state="done", account_id=account_id, code=None, password_enc=None, temp_session_enc=None,
                                phone_code_hash=None, error=None, error_code=None)
        self.login_clients.pop(rid, None)
        await client.disconnect()
        log.info("login %s: account %s connected", rid, account_id)

    # ---- account runners ------------------------------------------------------------------------
    def schedule_runners(self) -> None:
        for rid, task in list(self.runners.items()):
            if task.done():
                self.runners.pop(rid, None)
        for account_id in self.store.accounts_needing_runner(self.config.read_window_hours, self.store.service_enabled()):
            if account_id not in self.runners:
                self.runners[account_id] = asyncio.create_task(AccountRunner(self, account_id).run(), name=f"account-{account_id}")


class AccountRunner:
    def __init__(self, worker: Worker, account_id: int):
        self.worker = worker
        self.store = worker.store
        self.config = worker.config
        self.account_id = account_id
        self.client: TelegramClient | None = None
        self.user_id = 0
        self.contacts: set[int] | None = None
        self.last_send = 0.0
        self.next_gap = 0.0
        self.last_lookup = 0.0
        self._limits: tuple[float, bool, dict] | None = None
        self.last_sweep = 0.0
        self.idle_since = time.monotonic()

    async def run(self) -> None:
        try:
            await self._run()
        except AccountGone as gone:
            log.warning("account %s: session gone (%s)", self.account_id, gone.reason)
            self.store.account_disconnected(self.account_id, gone.status, gone.reason)
        except asyncio.CancelledError:
            raise
        except Exception as exc:
            log.exception("account %s runner crashed", self.account_id)
            self.store.account_update(self.account_id, last_error=f"{type(exc).__name__}: {exc}"[:500])
            await asyncio.sleep(10)  # back off before the main loop restarts this runner
        finally:
            if self.client is not None:
                try:
                    await self.client.disconnect()
                except Exception:
                    pass

    async def _connect(self, account: dict) -> None:
        session = crypto.unseal(self.config.key, account.get("session_enc"))
        if not session:
            raise AccountGone("error", "نشست ذخیره‌شده قابل خواندن نیست (کلید رمزنگاری تغییر کرده؟) — دوباره متصل شوید.")
        self.client = self.worker.new_client(session, flood_sleep=0)
        await self.client.connect()
        if not await self.client.is_user_authorized():
            raise AccountGone("disconnected", "نشست از طرف تلگرام یا کاربر پایان یافته است — دوباره متصل شوید.")
        self.client.add_event_handler(self._on_read, events.MessageRead(inbox=False))

    async def _on_read(self, event) -> None:
        peer = getattr(event.original_update, "peer", None)
        if isinstance(peer, types.PeerUser) and event.max_id:
            n = self.store.mark_read(self.account_id, int(peer.user_id), int(event.max_id))
            if n:
                log.info("account %s: %d message(s) read by %s", self.account_id, n, peer.user_id)

    async def _run(self) -> None:
        account = self.store.account(self.account_id)
        if account is None or not account.get("session_enc"):
            return
        self.user_id = int(account["user_id"])
        await self._connect(account)
        log.info("account %s: connected", self.account_id)
        while not self.worker.stopping.is_set():
            account = self.store.account(self.account_id)
            if account is None:
                return
            if int(account["logout_requested"]) == 1:
                await self._logout()
                return
            did_work = False
            service_on, limits = self._settings()
            if account["status"] == "limited":
                self.store.account_clear_expired_limit(self.account_id)
                account = self.store.account(self.account_id) or account
            if service_on and limits["enabled"] and account["status"] == "active":
                did_work = await self._send_next(limits) or did_work
                did_work = await self._lookup_next(limits) or did_work
            if time.monotonic() - self.last_sweep >= 180:
                self.last_sweep = time.monotonic()
                await self._sweep_reads()
            if did_work:
                self.idle_since = time.monotonic()
            elif (time.monotonic() - self.idle_since >= self.config.idle_seconds
                  and not self.store.has_unread(self.account_id, self.config.read_window_hours)
                  and not (service_on and limits["enabled"] and self.store.has_pending_work(self.account_id, self.user_id))):
                # Work that is only waiting (daily cap, interval, temporary limit) keeps the connection;
                # disconnecting would make the main loop reconnect it right away.
                log.info("account %s: idle, disconnecting", self.account_id)
                return
            await asyncio.sleep(0.5 if did_work else 2)

    def _settings(self) -> tuple[bool, dict]:
        """The service switch and the owner's limits, re-read every 20 seconds."""
        if self._limits is None or time.monotonic() - self._limits[0] > 20:
            self._limits = (time.monotonic(), self.store.service_enabled(), self.store.user_limits(self.user_id))
        return self._limits[1], self._limits[2]

    async def _logout(self) -> None:
        try:
            await self.client.log_out()
        except Exception as exc:
            log.warning("account %s: log_out failed (%s); wiping the session anyway", self.account_id, exc)
        self.store.account_disconnected(self.account_id, "disconnected", None)
        log.info("account %s: logged out", self.account_id)

    # ---- sending --------------------------------------------------------------------------------
    async def _send_next(self, limits: dict) -> bool:
        # An OTP waits only a short fixed gap; anything else the owner's interval plus a little jitter.
        since = time.monotonic() - self.last_send
        if since < 3:
            return False
        if since < self.next_gap and not self.store.has_queued_otp(self.account_id, self.user_id):
            return False
        counters = self.store.account_counters(self.account_id)
        if counters["sent_today"] >= limits["daily_send_limit"]:
            return False
        message = self.store.claim_message(self.account_id, self.user_id)
        if message is None:
            return False
        self.last_send = time.monotonic()
        interval = limits["min_interval_seconds"]
        self.next_gap = interval + random.uniform(0, max(1.0, interval * 0.3))
        mid = int(message["id"])
        phone = str(message["phone"])
        try:
            peer = await self._resolve(phone)
            if peer is None:
                self.store.message_failed(mid, "not_on_telegram")
                return True
            sent = await self._deliver(peer, message)
            self.store.message_sent(mid, int(peer.user_id), int(peer.access_hash), int(sent.id))
            self.store.account_count(self.account_id, "sent_today")
            log.info("account %s: message %s sent", self.account_id, mid)
        except FileNotFoundError:
            self.store.message_failed(mid, "media_missing")
        except Exception as exc:
            action, code, seconds = classify_send_error(exc)
            log.warning("account %s: message %s -> %s (%s)", self.account_id, mid, action, exc)
            if action == "fail":
                self.store.message_failed(mid, code or "error", str(exc))
            elif action == "limit":
                self.store.account_limit(self.account_id, seconds, f"{type(exc).__name__}: {exc}")
                if message["kind"] == "otp":
                    self.store.message_failed(mid, code or "account_limited", str(exc))
                else:
                    self.store.message_requeue(mid, str(exc))
            elif action == "gone":
                self.store.message_requeue(mid, str(exc))
                raise AccountGone("error" if isinstance(exc, (errors.UserDeactivatedBanError, errors.UserDeactivatedError)) else "disconnected",
                                  "اکانت از طرف تلگرام مسدود شد." if isinstance(exc, errors.UserDeactivatedBanError)
                                  else "نشست از طرف تلگرام یا کاربر پایان یافته است — دوباره متصل شوید.")
            else:
                self.store.message_requeue(mid, str(exc))
                await asyncio.sleep(5)
        return True

    def _media_path(self, relative: str) -> str:
        root = os.path.realpath(self.config.media_dir)
        path = os.path.realpath(os.path.join(root, relative))
        if not path.startswith(root + os.sep) or not os.path.isfile(path):
            raise FileNotFoundError(relative)
        return path

    async def _deliver(self, peer: types.InputPeerUser, message: dict):
        text = message.get("content") or ""
        if message.get("media_path"):
            path = self._media_path(str(message["media_path"]))
            attributes = None
            if message.get("media_kind") == "document" and message.get("media_name"):
                attributes = [types.DocumentAttributeFilename(str(message["media_name"]))]
            return await self.client.send_file(peer, path, caption=text or None, force_document=message.get("media_kind") == "document",
                                               attributes=attributes, parse_mode=None)
        return await self.client.send_message(peer, text, link_preview=False, parse_mode=None)

    async def _load_contacts(self) -> set[int]:
        if self.contacts is None:
            try:
                result = await self.client(functions.contacts.GetContactsRequest(hash=0))
                self.contacts = {int(u.id) for u in getattr(result, "users", [])}
            except Exception as exc:
                log.warning("account %s: could not read contacts (%s)", self.account_id, exc)
                self.contacts = set()
        return self.contacts

    async def _resolve(self, phone: str) -> types.InputPeerUser | None:
        """The recipient's peer: a peer this account already used, else resolvePhone (adds no contact),
        else a contact import that is undone afterwards — never touching the owner's real contacts."""
        known = self.store.known_peer(self.account_id, phone)
        if known:
            return types.InputPeerUser(int(known["tg_peer_id"]), int(known["tg_access_hash"]))
        try:
            resolved = await self.client(functions.contacts.ResolvePhoneRequest(phone="+" + phone))
            for user in resolved.users:
                if getattr(user, "access_hash", None) is not None:
                    return types.InputPeerUser(int(user.id), int(user.access_hash))
        except (errors.PhoneNotOccupiedError, errors.PhoneNumberInvalidError):
            pass
        except errors.RPCError as exc:
            if isinstance(exc, (errors.FloodWaitError, errors.PeerFloodError, errors.AuthKeyUnregisteredError, errors.SessionRevokedError)):
                raise
            log.info("account %s: resolvePhone refused (%s), trying import", self.account_id, exc)
        users = await self._import([phone])
        user = users.get(phone)
        return types.InputPeerUser(int(user.id), int(user.access_hash)) if user is not None else None

    async def _import(self, phones: list[str]) -> dict:
        """Imports the numbers as contacts, returns {phone: User}, then removes the ones that were not
        already in the owner's contacts. Raises FloodWaitError-like limits to the caller."""
        existing = await self._load_contacts()
        contacts = [types.InputPhoneContact(client_id=i + 1, phone="+" + p, first_name=p, last_name="") for i, p in enumerate(phones)]
        result = await self.client(functions.contacts.ImportContactsRequest(contacts=contacts))
        if result.retry_contacts and not result.imported:
            raise ImportLimited("Telegram did not accept the contact import (retry_contacts)")
        users_by_id = {int(u.id): u for u in result.users}
        out = {}
        added = []
        for imported in result.imported:
            phone = phones[int(imported.client_id) - 1]
            user = users_by_id.get(int(imported.user_id))
            if user is not None and getattr(user, "access_hash", None) is not None:
                out[phone] = user
                if int(user.id) not in existing:
                    added.append(types.InputUser(int(user.id), int(user.access_hash)))
        if added:
            try:
                await self.client(functions.contacts.DeleteContactsRequest(id=added))
            except Exception as exc:
                log.warning("account %s: could not remove %d temporary contact(s): %s", self.account_id, len(added), exc)
        return out

    # ---- lookups --------------------------------------------------------------------------------
    async def _lookup_next(self, limits: dict) -> bool:
        if time.monotonic() - self.last_lookup < max(5, limits["min_interval_seconds"]):
            return False
        counters = self.store.account_counters(self.account_id)
        room = limits["daily_check_limit"] - counters["checked_today"]
        if room <= 0:
            self.last_lookup = time.monotonic()
            return False
        items = self.store.claim_lookups(self.account_id, self.user_id, min(self.config.lookup_batch, room))
        if not items:
            return False
        self.last_lookup = time.monotonic()
        to_ask = []
        for item in items:
            cached = self.store.cached_lookup(self.user_id, str(item["phone"]))
            if cached:
                self.store.lookup_result(int(item["id"]), str(cached["status"]), cached.get("tg_user_id"), cached.get("tg_username"), cached.get("tg_name"))
            else:
                to_ask.append(item)
        if not to_ask:
            return True
        phones = [str(i["phone"]) for i in to_ask]
        try:
            found = await self._import(phones)
        except Exception as exc:
            action, _code, seconds = classify_send_error(exc)
            log.warning("account %s: lookup batch -> %s (%s)", self.account_id, action, exc)
            self.store.lookup_requeue([int(i["id"]) for i in to_ask])
            if action == "limit":
                self.store.account_limit(self.account_id, seconds if seconds > 0 else 3600, f"{type(exc).__name__}: {exc}")
            elif action == "gone":
                raise AccountGone("disconnected", "نشست از طرف تلگرام یا کاربر پایان یافته است — دوباره متصل شوید.")
            else:
                await asyncio.sleep(5)
            return True
        for item in to_ask:
            user = found.get(str(item["phone"]))
            if user is not None:
                name = " ".join(p for p in [getattr(user, "first_name", None), getattr(user, "last_name", None)] if p) or None
                self.store.lookup_result(int(item["id"]), "found", int(user.id), getattr(user, "username", None), name)
            else:
                self.store.lookup_result(int(item["id"]), "not_found")
        self.store.account_count(self.account_id, "checked_today", len(to_ask))
        log.info("account %s: checked %d number(s), %d found", self.account_id, len(to_ask), len(found))
        return True

    # ---- read receipts --------------------------------------------------------------------------
    async def _sweep_reads(self) -> None:
        peers = self.store.unread_peers(self.account_id, self.config.read_window_hours)
        for chunk_start in range(0, len(peers), 50):
            chunk = peers[chunk_start:chunk_start + 50]
            try:
                result = await self.client(functions.messages.GetPeerDialogsRequest(peers=[
                    types.InputDialogPeer(types.InputPeerUser(int(p["tg_peer_id"]), int(p["tg_access_hash"]))) for p in chunk]))
            except errors.RPCError as exc:
                log.warning("account %s: read sweep failed (%s)", self.account_id, exc)
                return
            for dialog in result.dialogs:
                peer = getattr(dialog, "peer", None)
                if isinstance(peer, types.PeerUser) and getattr(dialog, "read_outbox_max_id", 0):
                    self.store.mark_read(self.account_id, int(peer.user_id), int(dialog.read_outbox_max_id))


def main() -> None:
    logging.basicConfig(level=os.environ.get("TG_LOG_LEVEL", "INFO"), format="%(asctime)s %(levelname)s %(name)s: %(message)s")
    logging.getLogger("telethon").setLevel(logging.WARNING)
    config = Config.from_env()
    worker = Worker(config)
    loop = asyncio.new_event_loop()
    asyncio.set_event_loop(loop)
    for sig in (signal.SIGTERM, signal.SIGINT):
        loop.add_signal_handler(sig, worker.stopping.set)
    loop.run_until_complete(worker.run())


if __name__ == "__main__":
    main()
