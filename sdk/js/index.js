'use strict';
/**
 * ELLSMS public API v1 client for Node.js 18+ (built-in fetch, no dependencies).
 *
 *   const { EllsmsClient } = require('ellsms');
 *   const sms = new EllsmsClient({ apiKey: 'ellsms_live_…', baseUrl: 'https://panel.example.com' });
 *   await sms.sendMessage(['09121234567'], 'سلام');
 *
 * Every method resolves to the "data" part of the answer and rejects with EllsmsError on any error.
 * 429 answers, and transport/5xx failures of SAFE requests (GET, previews, or a POST carrying an
 * idempotency key / client_message_id) are retried up to maxRetries times; nothing that could send
 * twice is ever retried.
 */
const crypto = require('node:crypto');

const VERSION = '1.0.0';

class EllsmsError extends Error {
  constructor(status, code, message, { fields = {}, requestId = null, retryAfter = null } = {}) {
    super(message);
    this.name = 'EllsmsError';
    /** HTTP status; 0 when no answer arrived */
    this.status = status;
    /** ELLSMS error code, e.g. "validation_failed", "not_found", "rate_limited", "network_error" */
    this.code = code;
    this.fields = fields;
    this.requestId = requestId;
    this.retryAfter = retryAfter;
  }
}

function newIdempotencyKey() {
  return 'sdk-js-' + crypto.randomBytes(16).toString('hex');
}

/**
 * Verifies an ELLSMS webhook delivery. Pass the RAW body (string or Buffer), exactly as received.
 */
function verifyWebhook(secret, timestamp, rawBody, signature, toleranceSeconds = 300, now = Date.now() / 1000) {
  timestamp = String(timestamp || '');
  if (!/^\d+$/.test(timestamp) || Math.abs(now - Number(timestamp)) > toleranceSeconds) return false;
  const body = Buffer.isBuffer(rawBody) ? rawBody.toString('utf8') : String(rawBody);
  const expected = Buffer.from(crypto.createHmac('sha256', secret).update(`${timestamp}.${body}`).digest('hex'), 'utf8');
  const given = Buffer.from(String(signature || '').trim().toLowerCase(), 'utf8');
  return expected.length === given.length && crypto.timingSafeEqual(expected, given);
}

class EllsmsClient {
  /**
   * @param {{apiKey: string, baseUrl: string, timeoutMs?: number, maxRetries?: number, fetch?: Function, sleep?: Function}} options
   */
  constructor({ apiKey, baseUrl, timeoutMs = 30000, maxRetries = 2, fetch: fetchImpl, sleep } = {}) {
    if (!apiKey || !String(apiKey).trim()) throw new TypeError('An API key is required.');
    if (!baseUrl) throw new TypeError('baseUrl is required, e.g. https://panel.example.com');
    this.apiKey = String(apiKey).trim();
    let base = String(baseUrl).replace(/\/+$/, '');
    if (!base.endsWith('/api/v1')) base += '/api/v1';
    this.baseUrl = base;
    this.timeoutMs = Math.max(1000, timeoutMs);
    this.maxRetries = Math.max(0, maxRetries);
    this._fetch = fetchImpl || globalThis.fetch;
    if (typeof this._fetch !== 'function') throw new Error('No fetch available: use Node.js 18 or newer.');
    this._sleep = sleep || ((seconds) => new Promise((r) => setTimeout(r, seconds * 1000)));
  }

  // ---------------- account ----------------
  me() { return this._request('GET', '/me'); }
  organization() { return this._request('GET', '/organization'); }
  balance() { return this._request('GET', '/balance'); }

  // ---------------- messages ----------------
  /**
   * Sends one text to up to 100 numbers, synchronously.
   * options: { originator, channel ('sms'|'bale'|'bale_sms'), client_message_id }
   */
  sendMessage(destinations, content, options = {}) {
    const body = { ...options, destinations: [...destinations], content };
    return this._request('POST', '/messages', { body, replaySafe: options.client_message_id != null });
  }
  getMessage(id) { return this._request('GET', `/messages/${encodeURIComponent(id)}`); }
  previewMessage(destinations, content, options = {}) {
    return this._request('POST', '/messages/preview', { body: { ...options, destinations: [...destinations], content }, replaySafe: true });
  }

  // ---------------- bulk jobs ----------------
  /** job: { type: 'p2p'|'smart'|'gradual', title, originator, items: [{mobile, content}], throttle_count?, throttle_minutes? } */
  createBulkJob(job, idempotencyKey = newIdempotencyKey()) {
    return this._request('POST', '/bulk-jobs', { body: job, idempotencyKey });
  }
  getBulkJob(id) { return this._request('GET', `/bulk-jobs/${encodeURIComponent(id)}`); }
  previewBulkJob(job) { return this._request('POST', '/bulk-jobs/preview', { body: job, replaySafe: true }); }

  // ---------------- contacts ----------------
  /** One page: { data, nextCursor } */
  async listContacts({ limit = 50, after = null } = {}) {
    const query = new URLSearchParams({ limit: String(limit) });
    if (after != null) query.set('after', String(after));
    const answer = await this._request('GET', `/contacts?${query}`, { full: true });
    return { data: answer.data || [], nextCursor: (answer.meta && answer.meta.next_cursor) ?? null };
  }
  /** for await (const c of sms.eachContact()) … */
  async *eachContact(pageSize = 200) {
    let after = null;
    do {
      const page = await this.listContacts({ limit: pageSize, after });
      for (const c of page.data) yield c;
      after = page.nextCursor;
    } while (after != null);
  }
  createContact(contact) { return this._request('POST', '/contacts', { body: contact }); }
  getContact(id) { return this._request('GET', `/contacts/${encodeURIComponent(id)}`); }
  updateContact(id, changes) { return this._request('PATCH', `/contacts/${encodeURIComponent(id)}`, { body: changes }); }
  async deleteContact(id) { await this._request('DELETE', `/contacts/${encodeURIComponent(id)}`); }

  // ---------------- webhooks ----------------
  listWebhooks() { return this._request('GET', '/webhooks'); }
  /** Resolves to { id, secret } — the secret is shown only this once. */
  createWebhook(url, eventTypes, description = '') {
    return this._request('POST', '/webhooks', { body: { url, event_types: [...eventTypes], description } });
  }
  getWebhook(id) { return this._request('GET', `/webhooks/${encodeURIComponent(id)}`); }
  updateWebhook(id, changes) { return this._request('PATCH', `/webhooks/${encodeURIComponent(id)}`, { body: changes }); }
  async deleteWebhook(id) { await this._request('DELETE', `/webhooks/${encodeURIComponent(id)}`); }
  rotateWebhookSecret(id) { return this._request('POST', `/webhooks/${encodeURIComponent(id)}/rotate-secret`, { body: {} }); }
  testWebhook(id) { return this._request('POST', `/webhooks/${encodeURIComponent(id)}/test`, { body: {} }); }

  // ---------------- transport ----------------
  async _request(method, path, { body = null, idempotencyKey = null, replaySafe = false, full = false } = {}) {
    const retrySafe = method === 'GET' || idempotencyKey != null || replaySafe;
    for (let attempt = 1; ; attempt++) {
      try {
        const answer = await this._once(method, path, body, idempotencyKey);
        return full ? answer : (answer.data ?? {});
      } catch (e) {
        const retryable = e instanceof EllsmsError && (e.status === 429 || (retrySafe && (e.status === 0 || e.status >= 500)));
        if (!retryable || attempt > this.maxRetries) throw e;
        await this._sleep(e.retryAfter ?? Math.min(8, 2 ** (attempt - 1)));
      }
    }
  }

  async _once(method, path, body, idempotencyKey) {
    const headers = {
      Authorization: `Bearer ${this.apiKey}`,
      Accept: 'application/json',
      'User-Agent': `ellsms-js/${VERSION}`,
    };
    if (idempotencyKey != null) headers['Idempotency-Key'] = idempotencyKey;
    const init = { method, headers, redirect: 'manual', signal: AbortSignal.timeout(this.timeoutMs) };
    if (body != null && method !== 'GET' && method !== 'DELETE') {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(body);
    }
    let response;
    try {
      response = await this._fetch(this.baseUrl + path, init);
    } catch (e) {
      throw new EllsmsError(0, 'network_error', `Could not reach ELLSMS: ${e && e.message ? e.message : e}`);
    }
    const text = await response.text();
    let decoded = null;
    try { decoded = text ? JSON.parse(text) : {}; } catch { decoded = null; }
    if (response.status >= 200 && response.status < 300) return decoded || {};
    const error = decoded && decoded.error ? decoded.error : {};
    const retryAfterHeader = response.headers.get('retry-after');
    throw new EllsmsError(response.status, error.code || `http_${response.status}`, error.message || `HTTP ${response.status}`, {
      fields: error.fields || {},
      requestId: error.request_id || response.headers.get('x-request-id'),
      retryAfter: retryAfterHeader && /^\d+$/.test(retryAfterHeader) ? Number(retryAfterHeader) : null,
    });
  }
}

module.exports = { EllsmsClient, EllsmsError, verifyWebhook, newIdempotencyKey, VERSION };
