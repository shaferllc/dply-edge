import type { OrgState } from './org';

export interface Env {
  ACCOUNTS: KVNamespace;
  ORG: DurableObjectNamespace<OrgState>;
  DELIVERY: Queue<{ org: string; id: string }>;
  OPERATOR_TOKEN: string;
  BLOCKED_HOST_SUFFIXES?: string;
  /** Local tests only: let messages go to 127.0.0.1. Never set in production. */
  ALLOW_PRIVATE_DESTINATIONS?: string;
}

/** Per-organization caps (org record `limits`, else these). */
export type Limits = {
  maxBodyBytes: number;
  maxDelayMs: number;
  maxRetries: number;
  maxSchedules: number;
  maxPerSecond: number;
  maxTimeoutMs: number;
};

export const DEFAULT_LIMITS: Limits = {
  maxBodyBytes: 1_048_576,
  maxDelayMs: 7 * 86_400_000,
  maxRetries: 5,
  maxSchedules: 100,
  maxPerSecond: 100,
  maxTimeoutMs: 5 * 60_000,
};

export type Account = {
  enabled: boolean;
  currentSigningKey: string;
  nextSigningKey: string;
  limits?: Partial<Limits>;
};

export type NewMessage = {
  url: string;
  method: string;
  /** Headers set on delivery (the Upstash-Forward-* ones without the prefix, and Content-Type). */
  headers: Record<string, string>;
  /** Base64. */
  body: string;
  notBefore: number;
  retries: number;
  callback?: string;
  failureCallback?: string;
  timeoutMs: number;
  scheduleId?: string;
  dedupId?: string;
};

export type StoredMessage = {
  messageId: string;
  url: string;
  method: string;
  headers: Record<string, string>;
  body: string;
  notBefore: number;
  maxRetries: number;
  callback?: string;
  failureCallback?: string;
  timeoutMs: number;
  attempts: number;
  state: 'queued' | 'scheduled' | 'failed';
  createdAt: number;
  scheduleId?: string;
  dlqId?: string;
  responseStatus?: number;
  responseBody?: string;
  error?: string;
};

export type NewSchedule = Omit<NewMessage, 'notBefore' | 'dedupId' | 'scheduleId'> & { cron: string; delayMs: number; scheduleId?: string };

export type Schedule = {
  scheduleId: string;
  cron: string;
  destination: string;
  method: string;
  createdAt: number;
  retries: number;
  delay?: number;
  callback?: string;
  failureCallback?: string;
  isPaused: boolean;
  nextScheduleTime: number;
  headersRaw: string;
  bodyBase64: string;
  timeoutMs: number;
};
