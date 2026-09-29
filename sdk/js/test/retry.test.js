'use strict';
// Retry rules, offline (fake fetch): a request that could send twice is NEVER retried.
const test = require('node:test');
const assert = require('node:assert');
const { EllsmsClient, EllsmsError } = require('..');

function fakeFetch(answers) {
  const calls = [];
  const fn = async (url, init) => {
    calls.push({ url, init });
    const next = answers.shift();
    if (next instanceof Error) throw next;
    return new Response(JSON.stringify(next.body || {}), { status: next.status, headers: next.headers || {} });
  };
  return { fn, calls };
}
const client = (fetch, sleeps) => new EllsmsClient({ apiKey: 'k', baseUrl: 'https://x.test', maxRetries: 2, fetch, sleep: async (s) => sleeps.push(s) });
const err = (status, code) => ({ status, body: { error: { code, message: code, request_id: 'r1' } } });

test('429 is retried after Retry-After, then succeeds', async () => {
  const sleeps = [];
  const f = fakeFetch([{ ...err(429, 'rate_limited'), headers: { 'Retry-After': '3' } }, { status: 200, body: { data: { available: 5 } } }]);
  assert.deepStrictEqual(await client(f.fn, sleeps).balance(), { available: 5 });
  assert.deepStrictEqual(sleeps, [3]);
});

test('a plain send is never retried on 5xx or a network error', async () => {
  for (const failure of [err(503, 'service_unavailable'), new TypeError('socket hang up')]) {
    const f = fakeFetch([failure, { status: 200, body: { data: {} } }]);
    await assert.rejects(client(f.fn, []).sendMessage(['0912'], 'x'), EllsmsError);
    assert.strictEqual(f.calls.length, 1);
  }
});

test('a send with client_message_id and a bulk job (idempotency key) are retried', async () => {
  const f1 = fakeFetch([err(502, 'bad_gateway'), { status: 200, body: { data: { id: '1' } } }]);
  assert.strictEqual((await client(f1.fn, []).sendMessage(['0912'], 'x', { client_message_id: 'a' })).id, '1');
  const f2 = fakeFetch([new TypeError('reset'), { status: 201, body: { data: { id: '9' } } }]);
  assert.strictEqual((await client(f2.fn, []).createBulkJob({ type: 'p2p', items: [] }, 'key-1')).id, '9');
  assert.strictEqual(f2.calls[0].init.headers['Idempotency-Key'], 'key-1');
  assert.strictEqual(f2.calls[1].init.headers['Idempotency-Key'], 'key-1', 'the SAME key on the retry');
});

test('4xx other than 429 is never retried and carries the error details', async () => {
  const f = fakeFetch([{ status: 422, body: { error: { code: 'validation_failed', message: 'm', fields: { mobile: ['invalid_format'] }, request_id: 'r9' } } }]);
  await assert.rejects(client(f.fn, []).getContact('1'), (e) => e.status === 422 && e.fields.mobile[0] === 'invalid_format' && e.requestId === 'r9');
  assert.strictEqual(f.calls.length, 1);
});

test('gives up after maxRetries', async () => {
  const f = fakeFetch([err(500, 'internal_error'), err(500, 'internal_error'), err(500, 'internal_error'), err(500, 'internal_error')]);
  await assert.rejects(client(f.fn, []).me(), (e) => e.status === 500);
  assert.strictEqual(f.calls.length, 3);
});
