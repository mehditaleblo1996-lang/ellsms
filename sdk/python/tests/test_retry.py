"""Retry rules, offline: a request that could send twice is NEVER retried."""

import os
import sys
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from ellsms import Client, EllsmsError  # noqa: E402


class FakeClient(Client):
    def __init__(self, answers):
        self.sleeps = []
        super().__init__("k", "https://x.test", max_retries=2, sleep=self.sleeps.append)
        self.answers = list(answers)
        self.calls = []

    def _once(self, method, path, body, idempotency_key):
        self.calls.append((method, path, idempotency_key))
        answer = self.answers.pop(0)
        if isinstance(answer, EllsmsError):
            raise answer
        return answer


def err(status, code, retry_after=None):
    return EllsmsError(status, code, code, request_id="r1", retry_after=retry_after)


class RetryTest(unittest.TestCase):
    def test_429_is_retried_after_retry_after(self):
        c = FakeClient([err(429, "rate_limited", 3), {"data": {"available": 5}}])
        self.assertEqual(c.balance(), {"available": 5})
        self.assertEqual(c.sleeps, [3])

    def test_plain_send_is_never_retried(self):
        for failure in (err(503, "service_unavailable"), err(0, "network_error")):
            c = FakeClient([failure, {"data": {}}])
            with self.assertRaises(EllsmsError):
                c.send_message(["0912"], "x")
            self.assertEqual(len(c.calls), 1)

    def test_idempotent_requests_are_retried_with_the_same_key(self):
        c = FakeClient([err(502, "bad_gateway"), {"data": {"id": "1"}}])
        self.assertEqual(c.send_message(["0912"], "x", client_message_id="a")["id"], "1")
        c = FakeClient([err(0, "network_error"), {"data": {"id": "9"}}])
        self.assertEqual(c.create_bulk_job({"type": "p2p", "items": []}, "key-1")["id"], "9")
        self.assertEqual([k for _, _, k in c.calls], ["key-1", "key-1"])

    def test_other_4xx_is_not_retried(self):
        c = FakeClient([err(422, "validation_failed"), {"data": {}}])
        with self.assertRaises(EllsmsError):
            c.get_contact("1")
        self.assertEqual(len(c.calls), 1)

    def test_gives_up_after_max_retries(self):
        c = FakeClient([err(500, "internal_error")] * 4)
        with self.assertRaises(EllsmsError):
            c.me()
        self.assertEqual(len(c.calls), 3)


if __name__ == "__main__":
    unittest.main()
