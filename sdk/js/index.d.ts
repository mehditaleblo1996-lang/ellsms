/// <reference types="node" />

export declare const VERSION: string;

export interface ClientOptions {
  apiKey: string;
  /** e.g. https://panel.example.com (the /api/v1 suffix is added when missing) */
  baseUrl: string;
  timeoutMs?: number;
  maxRetries?: number;
  fetch?: typeof fetch;
  sleep?: (seconds: number) => Promise<void>;
}

export declare class EllsmsError extends Error {
  /** HTTP status; 0 when no answer arrived */
  status: number;
  /** e.g. "validation_failed", "not_found", "rate_limited", "network_error" */
  code: string;
  fields: Record<string, string[]>;
  requestId: string | null;
  retryAfter: number | null;
}

export type Channel = 'sms' | 'bale' | 'bale_sms';

export interface SendOptions {
  originator?: string;
  channel?: Channel;
  /** makes a retry within 24 hours safe: the server replays instead of re-sending */
  client_message_id?: string;
}

export interface MessageResult {
  id: string;
  status: 'sent' | 'partially_sent' | 'failed';
  sent_count: number;
  total_count: number;
  message: string;
  channel?: Channel;
  sent_by_channel?: { bale: number; sms: number };
}

export interface BulkJobInput {
  type: 'p2p' | 'smart' | 'gradual';
  title?: string;
  originator?: string;
  items: { mobile: string; content: string }[];
  throttle_count?: number;
  throttle_minutes?: number;
}

export interface BulkJob {
  id: string;
  type?: string;
  title?: string;
  status: string;
  sent_rows?: number;
  failed_rows?: number;
  total_rows: number;
  created_at?: string;
  message?: string;
}

export interface Contact {
  id: string;
  mobile: string;
  name: string;
  group: string;
  [key: string]: unknown;
}

export interface Balance {
  available: number;
  reserved: number;
  total: number;
  unit: string;
}

export interface Webhook {
  id: string;
  url: string;
  description: string;
  enabled: boolean;
  event_types: string[];
  consecutive_failures: number;
  last_success_at: string | null;
  last_failure_at: string | null;
  disabled_reason: string | null;
  created_at: string;
}

export declare class EllsmsClient {
  constructor(options: ClientOptions);
  me(): Promise<Record<string, unknown>>;
  organization(): Promise<Record<string, unknown>>;
  balance(): Promise<Balance>;

  sendMessage(destinations: string[], content: string, options?: SendOptions): Promise<MessageResult>;
  getMessage(id: string): Promise<MessageResult>;
  previewMessage(destinations: string[], content: string, options?: SendOptions): Promise<Record<string, unknown>>;

  createBulkJob(job: BulkJobInput, idempotencyKey?: string): Promise<BulkJob>;
  getBulkJob(id: string): Promise<BulkJob>;
  previewBulkJob(job: BulkJobInput): Promise<Record<string, unknown>>;

  listContacts(options?: { limit?: number; after?: string | null }): Promise<{ data: Contact[]; nextCursor: string | null }>;
  eachContact(pageSize?: number): AsyncGenerator<Contact>;
  createContact(contact: { mobile: string; name?: string; group?: string }): Promise<Contact>;
  getContact(id: string): Promise<Contact>;
  updateContact(id: string, changes: Partial<{ mobile: string; name: string; group: string }>): Promise<Contact>;
  deleteContact(id: string): Promise<void>;

  listWebhooks(): Promise<Webhook[]>;
  createWebhook(url: string, eventTypes: string[], description?: string): Promise<{ id: string; secret: string }>;
  getWebhook(id: string): Promise<Webhook>;
  updateWebhook(id: string, changes: Partial<{ url: string; description: string; event_types: string[]; enabled: boolean }>): Promise<Webhook>;
  deleteWebhook(id: string): Promise<void>;
  rotateWebhookSecret(id: string): Promise<{ id: string; secret: string }>;
  testWebhook(id: string): Promise<Record<string, unknown>>;
}

export declare function verifyWebhook(
  secret: string,
  timestamp: string,
  rawBody: string | Buffer,
  signature: string,
  toleranceSeconds?: number,
  now?: number
): boolean;

export declare function newIdempotencyKey(): string;
