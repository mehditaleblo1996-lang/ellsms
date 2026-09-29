// Runs the shared SDK conformance scenario against a real ELLSMS API (same env as sdk/php/tests/conformance.php).
// Prints "OK <checks>" and exits 0, or prints the failure and exits 1. Uses the ESM entry point on purpose.
import { EllsmsClient, EllsmsError, verifyWebhook, newIdempotencyKey } from '../index.mjs';

let checks = 0;
function check(condition, what) {
  if (!condition) { console.error(`FAILED: ${what}`); process.exit(1); }
  checks++;
}
async function expectError(fn, status, code, what) {
  try { await fn(); } catch (e) {
    check(e instanceof EllsmsError && e.status === status && e.code === code, `${what} (got ${e.status} ${e.code})`);
    check(!!e.requestId, `${what}: request id`);
    return;
  }
  check(false, `${what}: no error raised`);
}

try {
  const env = process.env;
  const sms = new EllsmsClient({ apiKey: env.ELLSMS_API_KEY, baseUrl: env.ELLSMS_BASE_URL, maxRetries: 0 });
  const mobile = env.ELLSMS_MOBILE;

  check((await sms.me()).organization_id !== undefined, 'me() has organization_id');
  check(Number.isInteger((await sms.balance()).available), 'balance() available');

  const created = await sms.createContact({ mobile, name: 'SDK', group: 'sdk-test' });
  const id = String(created.id);
  check(id !== '', 'createContact id');
  await expectError(() => sms.createContact({ mobile, name: 'SDK', group: 'sdk-test' }), 409, 'conflict', 'duplicate contact');
  check((await sms.getContact(id)).name === 'SDK', 'getContact');
  check((await sms.updateContact(id, { name: 'سلام' })).name === 'سلام', 'updateContact keeps unicode');
  let found = false, seen = 0;
  for await (const c of sms.eachContact(1)) { seen++; if (String(c.id) === id) found = true; }
  check(found && seen >= 2, 'eachContact pages through everything');
  await sms.deleteContact(id);
  await expectError(() => sms.getContact(id), 404, 'not_found', 'deleted contact');

  const job = { type: 'p2p', title: 'sdk', originator: env.ELLSMS_ORIGINATOR, items: [{ mobile, content: 'hi' }] };
  const key = newIdempotencyKey();
  const first = await sms.createBulkJob(job, key);
  const again = await sms.createBulkJob(job, key);
  check(first.id === again.id, 'same idempotency key replays the same job');
  check((await sms.getBulkJob(first.id)).status !== undefined, 'getBulkJob');
  await expectError(() => sms.createBulkJob({ type: 'nope', items: [] }), 422, 'validation_failed', 'invalid bulk job');

  const bad = new EllsmsClient({ apiKey: 'ellsms_live_000000000000_' + 'x'.repeat(40), baseUrl: env.ELLSMS_BASE_URL, maxRetries: 0 });
  await expectError(() => bad.me(), 401, 'unauthenticated', 'bad key');

  const { ELLSMS_WH_SECRET: secret, ELLSMS_WH_TS: ts, ELLSMS_WH_BODY: body, ELLSMS_WH_SIG: sig } = env;
  check(verifyWebhook(secret, ts, body, sig), 'webhook signature verifies');
  check(!verifyWebhook(secret, ts, body + ' ', sig), 'tampered body rejected');
  check(!verifyWebhook(secret, String(Number(ts) - 3600), body, sig), 'stale timestamp rejected');
} catch (e) {
  console.error(`FAILED: ${e && e.stack ? e.stack : e}`);
  process.exit(1);
}
console.log(`OK ${checks}`);
