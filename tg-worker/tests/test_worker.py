"""tg-worker tests. The crypto/config/classification tests need nothing; the runner tests drive an
AccountRunner against a fake Telegram client and a REAL database with the ELLSMS schema loaded
(set TG_TEST_DB_HOST / _NAME / _USER / _PASS, e.g. the integration-test database), otherwise skip.

    cd tg-worker && python -m unittest discover -s tests
"""

from __future__ import annotations

import asyncio
import os
import random
import unittest
from types import SimpleNamespace

from telethon import errors, functions, types

from tgworker import crypto
from tgworker.config import Config, crypto_key_from_env, parse_proxy
from tgworker.db import Database
from tgworker.store import Store
from tgworker.worker import AccountRunner, classify_send_error


class CryptoAndConfigTest(unittest.TestCase):
    def test_seal_roundtrip_and_tamper(self):
        key = crypto_key_from_env({"SMS_GATEWAY_MASTER_KEY": "abc"})
        sealed = crypto.seal(key, "session-string")
        self.assertTrue(sealed.startswith("v1:"))
        self.assertEqual(crypto.unseal(key, sealed), "session-string")
        self.assertIsNone(crypto.unseal(crypto_key_from_env({"SMS_GATEWAY_MASTER_KEY": "other"}), sealed))
        self.assertIsNone(crypto.unseal(key, sealed[:-4] + "AAAA"))
        self.assertIsNone(crypto.unseal(key, None))

    def test_php_sealed_value_opens(self):
        # Produced by tg_encrypt() in app/TelegramUser.php with SMS_GATEWAY_MASTER_KEY=abc123.
        key = crypto_key_from_env({"SMS_GATEWAY_MASTER_KEY": "abc123"})
        self.assertEqual(crypto.unseal(key, "v1:o2nG4Dl+WUL1YRJ5/s1mE0MSxp9Uq+qj1WvAM8IvjCUY2rx0BekpHn/8zm7r"), "سلام pass🙂")

    def test_key_precedence(self):
        own = "ab" * 32
        self.assertEqual(crypto_key_from_env({"TELEGRAM_SESSION_KEY": own, "SMS_GATEWAY_MASTER_KEY": "x"}), bytes.fromhex(own))
        self.assertIsNone(crypto_key_from_env({}))

    def test_proxy(self):
        self.assertIsNone(parse_proxy(""))
        self.assertEqual(parse_proxy("socks5://u:p%40ss@10.0.0.1:1080"),
                         {"proxy_type": "socks5", "addr": "10.0.0.1", "port": 1080, "rdns": True, "username": "u", "password": "p@ss"})
        with self.assertRaises(ValueError):
            parse_proxy("ftp://x:1")

    def test_error_classification(self):
        self.assertEqual(classify_send_error(errors.PeerFloodError(None))[0:2], ("limit", "account_limited"))
        self.assertEqual(classify_send_error(errors.FloodWaitError(None, capture=120)), ("limit", "flood_wait", 120))
        self.assertEqual(classify_send_error(errors.UserPrivacyRestrictedError(None))[1], "privacy_restricted")
        self.assertEqual(classify_send_error(errors.UserIsBlockedError(None))[1], "blocked")
        self.assertEqual(classify_send_error(errors.AuthKeyUnregisteredError(None))[0], "gone")
        self.assertEqual(classify_send_error(ConnectionError())[0], "retry")


class FakeClient:
    """Just enough of TelegramClient for AccountRunner: a phone book of registered users."""

    def __init__(self, registered: dict[str, int], hidden: set[str] = frozenset(), fail_send: Exception | None = None):
        self.registered = registered          # phone (digits) -> user id
        self.hidden = hidden                  # registered but not findable via resolvePhone
        self.fail_send = fail_send
        self.sent: list[tuple[int, str]] = []
        self.deleted: list[int] = []
        self.contacts = {999}
        self.next_id = 100
        self.read_upto: dict[int, int] = {}

    def _user(self, phone: str):
        uid = self.registered[phone]
        return types.User(id=uid, access_hash=uid * 7, first_name="U" + phone[-3:], username=None)

    async def __call__(self, request):
        if isinstance(request, functions.contacts.GetContactsRequest):
            return SimpleNamespace(users=[types.User(id=i, access_hash=1) for i in self.contacts])
        if isinstance(request, functions.contacts.ResolvePhoneRequest):
            phone = request.phone.lstrip("+")
            if phone not in self.registered or phone in self.hidden:
                raise errors.PhoneNotOccupiedError(None)
            return SimpleNamespace(users=[self._user(phone)])
        if isinstance(request, functions.contacts.ImportContactsRequest):
            imported, users = [], []
            for c in request.contacts:
                phone = c.phone.lstrip("+")
                if phone in self.registered:
                    imported.append(types.ImportedContact(user_id=self.registered[phone], client_id=c.client_id))
                    users.append(self._user(phone))
            return SimpleNamespace(imported=imported, users=users, retry_contacts=[])
        if isinstance(request, functions.contacts.DeleteContactsRequest):
            self.deleted.extend(int(u.user_id) for u in request.id)
            return SimpleNamespace()
        if isinstance(request, functions.messages.GetPeerDialogsRequest):
            dialogs = []
            for p in request.peers:
                uid = int(p.peer.user_id)
                dialogs.append(SimpleNamespace(peer=types.PeerUser(uid), read_outbox_max_id=self.read_upto.get(uid, 0)))
            return SimpleNamespace(dialogs=dialogs)
        raise AssertionError(f"unexpected request {type(request).__name__}")

    async def send_message(self, peer, text, **kwargs):
        if self.fail_send:
            raise self.fail_send
        self.next_id += 1
        self.sent.append((int(peer.user_id), text))
        return SimpleNamespace(id=self.next_id)

    async def send_file(self, peer, path, **kwargs):
        return await self.send_message(peer, "[file] " + (kwargs.get("caption") or ""))

    async def disconnect(self):
        pass


@unittest.skipUnless(os.environ.get("TG_TEST_DB_HOST"), "needs TG_TEST_DB_HOST (a database with the ELLSMS schema)")
class RunnerTest(unittest.TestCase):
    def setUp(self):
        self.db = Database(os.environ["TG_TEST_DB_HOST"], int(os.environ.get("TG_TEST_DB_PORT", "3306")), os.environ.get("TG_TEST_DB_NAME", "ellsms_test"),
                           os.environ.get("TG_TEST_DB_USER", "ellsms_test"), os.environ.get("TG_TEST_DB_PASS", "ellsms_test"))
        self.store = Store(self.db, "Asia/Tehran")
        self.db.execute("INSERT INTO ellsms_settings (skey, svalue) VALUES ('tg_enabled','1') ON DUPLICATE KEY UPDATE svalue = '1'")
        self.user_id = random.randint(10_000_000, 99_999_999)
        self.db.execute("INSERT INTO ellsms_tg_user_settings (user_id, enabled, daily_send_limit, daily_check_limit, min_interval_seconds) VALUES (%s,1,2,100,3)", (self.user_id,))
        self.account_id = self.db.insert("INSERT INTO ellsms_tg_accounts (user_id, phone, status, session_enc, created_at) VALUES (%s,%s,'active','v1:x',%s)",
                                         (self.user_id, "989100000001", self.store.now()))
        worker = SimpleNamespace(store=self.store, config=Config.from_env().__class__(**{**Config.from_env().__dict__, "media_dir": "/nonexistent"}),
                                 stopping=asyncio.Event())
        self.runner = AccountRunner(worker, self.account_id)
        self.runner.user_id = self.user_id
        self.limits = self.store.user_limits(self.user_id)

    def campaign(self, phones, kind="notify", account_id=None, content="سلام *دنیا*"):
        cid = self.db.insert("INSERT INTO ellsms_tg_campaigns (user_id, kind, account_id, content, price_each, total, status, created_at) VALUES (%s,%s,%s,%s,10,%s,'queued',%s)",
                             (self.user_id, kind, account_id, content, len(phones), self.store.now()))
        for p in phones:
            self.db.execute("INSERT INTO ellsms_tg_messages (campaign_id, user_id, phone, status, created_at) VALUES (%s,%s,%s,'queued',%s)",
                            (cid, self.user_id, p, self.store.now()))
        return cid

    def statuses(self, cid):
        return {r["phone"]: (r["status"], r["error_code"]) for r in self.db.all("SELECT phone, status, error_code FROM ellsms_tg_messages WHERE campaign_id = %s", (cid,))}

    def send(self):
        self.runner.last_send = 0.0
        self.runner.next_gap = 0.0
        return asyncio.run(self.runner._send_next(self.limits))

    def test_sends_resolves_hidden_numbers_by_import_and_respects_daily_cap(self):
        fake = FakeClient({"989121111111": 501, "989122222222": 502}, hidden={"989122222222"})
        self.runner.client = fake
        cid = self.campaign(["989121111111", "989122222222", "989123333333"])
        self.assertTrue(self.send())
        self.assertTrue(self.send())
        self.assertFalse(self.send(), "daily_send_limit=2 reached")
        st = self.statuses(cid)
        self.assertEqual(st["989121111111"], ("sent", None))
        self.assertEqual(st["989122222222"], ("sent", None))
        self.assertEqual(st["989123333333"], ("queued", None))
        self.assertEqual(fake.sent[0], (501, "سلام *دنیا*"), "text goes out verbatim (no markdown parsing)")
        self.assertEqual(fake.deleted, [502], "the temporary contact made for the hidden number is removed again")
        self.db.execute("UPDATE ellsms_tg_accounts SET sent_today = 0 WHERE id = %s", (self.account_id,))
        self.assertTrue(self.send())
        self.assertEqual(self.statuses(cid)["989123333333"], ("failed", "not_on_telegram"))

    # ---- AI secretary (phase 5) -------------------------------------------------------------------
    def test_an_ai_answer_goes_to_the_stored_peer_without_a_phone_lookup(self):
        fake = FakeClient({})  # nobody is findable by phone: only the stored peer can work
        self.runner.client = fake
        cid = self.campaign(["tgu:777"], content="پاسخ منشی")
        self.db.execute("UPDATE ellsms_tg_messages SET tg_peer_id = 777, tg_access_hash = 5 WHERE campaign_id = %s", (cid,))
        self.assertTrue(self.send())
        self.assertEqual(fake.sent, [(777, "پاسخ منشی")])
        self.assertEqual(self.statuses(cid)["tgu:777"], ("sent", None))

    def test_a_private_message_from_a_person_is_stored_for_the_ai(self):
        def event(sender, out=False, private=True, text="سلام، سفارشم کجاست؟", mid=55):
            async def get_sender():
                return sender
            return SimpleNamespace(is_private=private, out=out, raw_text=text, id=mid, get_sender=get_sender)

        person = types.User(id=888, access_hash=9, first_name="علی", last_name="رضایی", phone="989121234567")
        asyncio.run(self.runner._on_message(event(person)))
        asyncio.run(self.runner._on_message(event(person)))  # the same message again: ignored
        asyncio.run(self.runner._on_message(event(types.User(id=889, access_hash=1, bot=True), mid=56)))
        asyncio.run(self.runner._on_message(event(person, out=True, mid=57)))
        asyncio.run(self.runner._on_message(event(person, private=False, mid=58)))
        asyncio.run(self.runner._on_message(event(person, text="   ", mid=59)))
        rows = self.db.all("SELECT tg_peer_id, tg_access_hash, sender_phone, sender_name, text FROM ellsms_tg_inbound WHERE account_id = %s", (self.account_id,))
        self.assertEqual(rows, [{"tg_peer_id": 888, "tg_access_hash": 9, "sender_phone": "989121234567",
                                 "sender_name": "علی رضایی", "text": "سلام، سفارشم کجاست؟"}])

    def test_an_account_with_its_assistant_on_listens_and_stays_connected(self):
        self.assertFalse(self.store.ai_listening(self.account_id))
        self.assertNotIn(self.account_id, self.store.accounts_needing_runner(48, True), "nothing to do yet")
        self.db.execute("INSERT INTO ellsms_ai_assistants (line, user_id, enabled, mode) VALUES (%s, %s, 1, 'general')",
                        (f"tg:{self.account_id}", self.user_id))
        self.assertTrue(self.store.ai_listening(self.account_id))
        self.assertIn(self.account_id, self.store.accounts_needing_runner(48, True))

    def test_otp_goes_first(self):
        self.runner.client = FakeClient({"989121111111": 501, "989122222222": 502})
        notify = self.campaign(["989121111111"])
        otp = self.campaign(["989122222222"], kind="otp", content="code 1234")
        self.send()
        self.assertEqual(self.statuses(otp)["989122222222"][0], "sent")
        self.assertEqual(self.statuses(notify)["989121111111"][0], "queued")

    def test_peer_flood_limits_the_account_and_requeues_or_fails_otp(self):
        self.runner.client = FakeClient({"989121111111": 501, "989122222222": 502}, fail_send=errors.PeerFloodError(None))
        cid = self.campaign(["989121111111"])
        self.send()
        self.assertEqual(self.statuses(cid)["989121111111"][0], "queued", "a normal message waits for another account / later")
        self.assertEqual(self.store.account(self.account_id)["status"], "limited")
        self.db.execute("UPDATE ellsms_tg_accounts SET status = 'active', limited_until = NULL WHERE id = %s", (self.account_id,))
        self.db.execute("UPDATE ellsms_tg_messages SET status = 'cancelled' WHERE campaign_id = %s", (cid,))
        otp = self.campaign(["989122222222"], kind="otp")
        self.send()
        self.assertEqual(self.statuses(otp)["989122222222"], ("failed", "account_limited"), "an OTP fails at once so the caller can fall back to SMS")

    def test_campaign_pinned_to_another_account_is_not_taken(self):
        self.runner.client = FakeClient({"989121111111": 501})
        cid = self.campaign(["989121111111"], account_id=self.account_id + 100000)
        self.assertFalse(self.send())
        self.assertEqual(self.statuses(cid)["989121111111"][0], "queued")

    def test_read_sweep_marks_read(self):
        fake = FakeClient({"989121111111": 501})
        self.runner.client = fake
        cid = self.campaign(["989121111111"])
        self.send()
        msg_id = fake.next_id
        fake.read_upto[501] = msg_id
        asyncio.run(self.runner._sweep_reads())
        self.assertEqual(self.statuses(cid)["989121111111"][0], "read")

    def test_lookup_found_not_found_and_cache(self):
        fake = FakeClient({"989121111111": 501})
        self.runner.client = fake
        job = self.db.insert("INSERT INTO ellsms_tg_lookup_jobs (user_id, price_each, total, status, created_at) VALUES (%s,10,2,'queued',%s)", (self.user_id, self.store.now()))
        for p in ("989121111111", "989124444444"):
            self.db.execute("INSERT INTO ellsms_tg_lookup_items (job_id, user_id, phone, status) VALUES (%s,%s,%s,'queued')", (job, self.user_id, p))
        self.assertTrue(asyncio.run(self.runner._lookup_next(self.limits)))
        rows = {r["phone"]: r["status"] for r in self.db.all("SELECT phone, status FROM ellsms_tg_lookup_items WHERE job_id = %s", (job,))}
        self.assertEqual(rows, {"989121111111": "found", "989124444444": "not_found"})
        self.assertEqual(fake.deleted, [501])
        self.assertEqual(self.store.account_counters(self.account_id)["checked_today"], 2)
        # A second check of the same number within 30 days is answered from the first one.
        job2 = self.db.insert("INSERT INTO ellsms_tg_lookup_jobs (user_id, price_each, total, status, created_at) VALUES (%s,10,1,'queued',%s)", (self.user_id, self.store.now()))
        self.db.execute("INSERT INTO ellsms_tg_lookup_items (job_id, user_id, phone, status) VALUES (%s,%s,'989121111111','queued')", (job2, self.user_id))
        self.runner.last_lookup = 0.0
        asyncio.run(self.runner._lookup_next(self.limits))
        self.assertEqual(self.db.one("SELECT status FROM ellsms_tg_lookup_items WHERE job_id = %s", (job2,))["status"], "found")
        self.assertEqual(self.store.account_counters(self.account_id)["checked_today"], 2, "cached answers do not use the account's quota")


if __name__ == "__main__":
    unittest.main()
