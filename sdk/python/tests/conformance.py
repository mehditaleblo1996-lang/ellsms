"""Runs the shared SDK conformance scenario against a real ELLSMS API (same env as the PHP/JS ones).
Prints "OK <checks>" and exits 0, or prints the failure and exits 1."""

import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from ellsms import Client, EllsmsError, new_idempotency_key, verify_webhook  # noqa: E402

checks = 0


def check(condition, what):
    global checks
    if not condition:
        print("FAILED: " + what, file=sys.stderr)
        sys.exit(1)
    checks += 1


def expect_error(fn, status, code, what):
    try:
        fn()
    except EllsmsError as e:
        check(e.status == status and e.code == code, "%s (got %s %s)" % (what, e.status, e.code))
        check(bool(e.request_id), what + ": request id")
        return
    check(False, what + ": no error raised")


def main():
    env = os.environ
    sms = Client(env["ELLSMS_API_KEY"], env["ELLSMS_BASE_URL"], max_retries=0)
    mobile = env["ELLSMS_MOBILE"]

    check("organization_id" in sms.me(), "me() has organization_id")
    check(isinstance(sms.balance().get("available"), int), "balance() available")

    created = sms.create_contact(mobile, name="SDK", group="sdk-test")
    cid = str(created["id"])
    check(cid != "", "create_contact id")
    expect_error(lambda: sms.create_contact(mobile, name="SDK", group="sdk-test"), 409, "conflict", "duplicate contact")
    check(sms.get_contact(cid)["name"] == "SDK", "get_contact")
    check(sms.update_contact(cid, name="سلام")["name"] == "سلام", "update_contact keeps unicode")
    seen = [str(c["id"]) for c in sms.each_contact(page_size=1)]
    check(cid in seen and len(seen) >= 2, "each_contact pages through everything")
    sms.delete_contact(cid)
    expect_error(lambda: sms.get_contact(cid), 404, "not_found", "deleted contact")

    job = {"type": "p2p", "title": "sdk", "originator": env["ELLSMS_ORIGINATOR"], "items": [{"mobile": mobile, "content": "hi"}]}
    key = new_idempotency_key()
    first = sms.create_bulk_job(job, key)
    again = sms.create_bulk_job(job, key)
    check(first["id"] == again["id"], "same idempotency key replays the same job")
    check("status" in sms.get_bulk_job(first["id"]), "get_bulk_job")
    expect_error(lambda: sms.create_bulk_job({"type": "nope", "items": []}), 422, "validation_failed", "invalid bulk job")

    bad = Client("ellsms_live_000000000000_" + "x" * 40, env["ELLSMS_BASE_URL"], max_retries=0)
    expect_error(bad.me, 401, "unauthenticated", "bad key")

    secret, ts, body, sig = env["ELLSMS_WH_SECRET"], env["ELLSMS_WH_TS"], env["ELLSMS_WH_BODY"], env["ELLSMS_WH_SIG"]
    check(verify_webhook(secret, ts, body, sig), "webhook signature verifies")
    check(not verify_webhook(secret, ts, body + " ", sig), "tampered body rejected")
    check(not verify_webhook(secret, str(int(ts) - 3600), body, sig), "stale timestamp rejected")


if __name__ == "__main__":
    try:
        main()
    except SystemExit:
        raise
    except Exception as e:  # noqa: BLE001
        print("FAILED: %r" % e, file=sys.stderr)
        sys.exit(1)
    print("OK %d" % checks)
