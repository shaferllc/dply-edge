import { DurableObject } from 'cloudflare:workers';
import { nextRun, parseCron } from './parse';
import type { Env, Limits, NewMessage, StoredMessage, Schedule, NewSchedule } from './types';
import { DEFAULT_LIMITS } from './types';

// One per organization (idFromName(orgId)). SQLite holds every message until
// it is delivered or dead-lettered, schedules, dedup ids and usage. Delivery
// itself runs on the Queue (index.ts queue()): this object only hands due
// messages over, holding the ones further out than the Queue's 12 h delay
// cap, and firing cron schedules, with its alarm.

type Row = Record<string, SqlStorageValue>;

export const QUEUE_MAX_DELAY_MS = 12 * 3_600_000;
const DEDUP_WINDOW_MS = 10 * 60_000;

const id = (prefix: string) => prefix + '_' + crypto.randomUUID().replace(/-/g, '').slice(0, 24);

export class OrgState extends DurableObject<Env> {
  private window = { second: 0, count: 0 };

  constructor(ctx: DurableObjectState, env: Env) {
    super(ctx, env);
    ctx.blockConcurrencyWhile(async () => {
      const sql = ctx.storage.sql;
      sql.exec(`CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT)`);
      sql.exec(`CREATE TABLE IF NOT EXISTS messages (
        id TEXT PRIMARY KEY, url TEXT, method TEXT, headers TEXT, body TEXT,
        not_before INTEGER, retries INTEGER, callback TEXT, failure_callback TEXT,
        timeout_ms INTEGER, attempts INTEGER DEFAULT 0, state TEXT, created_at INTEGER,
        schedule_id TEXT, dedup_id TEXT, dlq_id TEXT, response_status INTEGER, response_body TEXT, error TEXT)`);
      sql.exec(`CREATE INDEX IF NOT EXISTS messages_due ON messages (state, not_before)`);
      sql.exec(`CREATE INDEX IF NOT EXISTS messages_dlq ON messages (dlq_id)`);
      sql.exec(`CREATE TABLE IF NOT EXISTS schedules (
        id TEXT PRIMARY KEY, cron TEXT, url TEXT, method TEXT, headers TEXT, body TEXT, retries INTEGER,
        delay_ms INTEGER, callback TEXT, failure_callback TEXT, timeout_ms INTEGER, paused INTEGER DEFAULT 0,
        created_at INTEGER, next_run INTEGER)`);
      sql.exec(`CREATE TABLE IF NOT EXISTS dedup (id TEXT PRIMARY KEY, message_id TEXT, expires_at INTEGER)`);
      sql.exec(`CREATE TABLE IF NOT EXISTS usage (k TEXT PRIMARY KEY, n INTEGER)`);
    });
  }

  // ---- publishing ----

  /** Store a message and queue it, or hold it for the alarm when it is due in more than 12 h. */
  async publish(org: string, msg: NewMessage, limits: Limits = DEFAULT_LIMITS): Promise<{ messageId: string; deduplicated?: boolean } | { error: string; status: number }> {
    this.setOrg(org);
    const now = Date.now();
    const second = Math.floor(now / 1000);
    if (this.window.second !== second) this.window = { second, count: 0 };
    if (++this.window.count > limits.maxPerSecond) return { error: `Rate limit: ${limits.maxPerSecond} messages per second`, status: 429 };

    const sql = this.ctx.storage.sql;
    sql.exec(`DELETE FROM dedup WHERE expires_at < ?`, now);
    if (msg.dedupId) {
      const seen = sql.exec<{ message_id: string }>(`SELECT message_id FROM dedup WHERE id = ?`, msg.dedupId).toArray()[0];
      if (seen) return { messageId: seen.message_id, deduplicated: true };
    }
    const messageId = id('msg');
    const notBefore = Math.max(now, msg.notBefore);
    const queueNow = notBefore - now <= QUEUE_MAX_DELAY_MS;
    sql.exec(
      `INSERT INTO messages (id, url, method, headers, body, not_before, retries, callback, failure_callback, timeout_ms, state, created_at, schedule_id, dedup_id)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      messageId, msg.url, msg.method, JSON.stringify(msg.headers), msg.body, notBefore, msg.retries,
      msg.callback ?? null, msg.failureCallback ?? null, msg.timeoutMs, queueNow ? 'queued' : 'scheduled', now,
      msg.scheduleId ?? null, msg.dedupId ?? null,
    );
    if (msg.dedupId) sql.exec(`INSERT OR REPLACE INTO dedup (id, message_id, expires_at) VALUES (?, ?, ?)`, msg.dedupId, messageId, now + DEDUP_WINDOW_MS);
    this.count('published');
    if (queueNow) {
      await this.env.DELIVERY.send({ org, id: messageId }, { delaySeconds: Math.ceil((notBefore - now) / 1000) });
    } else {
      await this.armAlarm();
    }
    return { messageId };
  }

  // ---- delivery bookkeeping (called by the queue consumer) ----

  /** The message to deliver now, or null when it was cancelled, delivered or dead-lettered meanwhile. */
  async claim(messageId: string): Promise<StoredMessage | null> {
    const row = this.row(messageId);
    return row && row.state === 'queued' ? row : null;
  }

  async delivered(messageId: string, status: number): Promise<void> {
    this.ctx.storage.sql.exec(`DELETE FROM messages WHERE id = ?`, messageId);
    this.count('delivered');
    void status;
  }

  /** Count a failed attempt; returns the attempts so far. */
  async failedAttempt(messageId: string, status: number | null, error: string): Promise<number> {
    this.count('attempts_failed');
    const sql = this.ctx.storage.sql;
    sql.exec(`UPDATE messages SET attempts = attempts + 1, response_status = ?, error = ? WHERE id = ?`, status, error.slice(0, 500), messageId);
    return this.row(messageId)?.attempts ?? 0;
  }

  async deadLetter(messageId: string, status: number | null, body: string, error: string): Promise<string> {
    const dlqId = id('dlq');
    this.ctx.storage.sql.exec(
      `UPDATE messages SET state = 'failed', dlq_id = ?, response_status = ?, response_body = ?, error = ? WHERE id = ?`,
      dlqId, status, body.slice(0, 8192), error.slice(0, 500), messageId,
    );
    this.count('dead_lettered');
    return dlqId;
  }

  // ---- messages API ----

  async get(messageId: string): Promise<StoredMessage | null> {
    const row = this.row(messageId);
    return row && row.state !== 'failed' ? row : null;
  }

  /** Cancel a message that has not been delivered yet. */
  async cancel(messageId: string): Promise<boolean> {
    const row = this.row(messageId);
    if (!row || row.state === 'failed') return false;
    this.ctx.storage.sql.exec(`DELETE FROM messages WHERE id = ?`, messageId);
    return true;
  }

  async listDlq(cursor: string | null, limit: number): Promise<{ messages: StoredMessage[]; cursor?: string }> {
    const rows = this.ctx.storage.sql.exec<Row>(
      `SELECT * FROM messages WHERE state = 'failed' AND dlq_id > ? ORDER BY dlq_id LIMIT ?`, cursor ?? '', limit + 1,
    ).toArray().map(toMessage);
    const more = rows.length > limit;
    const page = rows.slice(0, limit);
    return { messages: page, ...(more ? { cursor: page[page.length - 1].dlqId } : {}) };
  }

  async deleteDlq(dlqIds: string[]): Promise<number> {
    let n = 0;
    for (const d of dlqIds) n += this.ctx.storage.sql.exec(`DELETE FROM messages WHERE dlq_id = ?`, d).rowsWritten;
    return n;
  }

  /** Send dead-lettered messages again, as new attempts. */
  async retryDlq(org: string, dlqIds: string[]): Promise<{ messageId: string; dlqId: string }[]> {
    const out: { messageId: string; dlqId: string }[] = [];
    for (const d of dlqIds) {
      const row = this.ctx.storage.sql.exec<Row>(`SELECT * FROM messages WHERE dlq_id = ?`, d).toArray()[0];
      if (!row) continue;
      this.ctx.storage.sql.exec(`UPDATE messages SET state = 'queued', attempts = 0, dlq_id = NULL WHERE dlq_id = ?`, d);
      await this.env.DELIVERY.send({ org, id: String(row.id) });
      out.push({ messageId: String(row.id), dlqId: d });
    }
    return out;
  }

  // ---- schedules ----

  async createSchedule(org: string, s: NewSchedule, limits: Limits = DEFAULT_LIMITS): Promise<{ scheduleId: string } | { error: string; status: number }> {
    this.setOrg(org);
    let cron;
    try { cron = parseCron(s.cron); } catch (e) { return { error: String((e as Error).message), status: 400 }; }
    const sql = this.ctx.storage.sql;
    const existing = s.scheduleId ? sql.exec(`SELECT id FROM schedules WHERE id = ?`, s.scheduleId).toArray()[0] : undefined;
    const count = Number(sql.exec<{ n: number }>(`SELECT COUNT(*) AS n FROM schedules`).one().n);
    if (!existing && count >= limits.maxSchedules) return { error: `This organization has its ${limits.maxSchedules} schedules. Delete one first.`, status: 400 };
    const scheduleId = s.scheduleId && /^[\w-]{1,64}$/.test(s.scheduleId) ? s.scheduleId : id('scd');
    sql.exec(
      `INSERT OR REPLACE INTO schedules (id, cron, url, method, headers, body, retries, delay_ms, callback, failure_callback, timeout_ms, paused, created_at, next_run)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)`,
      scheduleId, s.cron, s.url, s.method, JSON.stringify(s.headers), s.body, s.retries, s.delayMs,
      s.callback ?? null, s.failureCallback ?? null, s.timeoutMs, Date.now(), nextRun(cron, Date.now()),
    );
    await this.armAlarm();
    return { scheduleId };
  }

  async listSchedules(): Promise<Schedule[]> {
    return this.ctx.storage.sql.exec<Row>(`SELECT * FROM schedules ORDER BY created_at`).toArray().map(toSchedule);
  }

  async getSchedule(scheduleId: string): Promise<Schedule | null> {
    const row = this.ctx.storage.sql.exec<Row>(`SELECT * FROM schedules WHERE id = ?`, scheduleId).toArray()[0];
    return row ? toSchedule(row) : null;
  }

  async deleteSchedule(scheduleId: string): Promise<boolean> {
    return this.ctx.storage.sql.exec(`DELETE FROM schedules WHERE id = ?`, scheduleId).rowsWritten > 0;
  }

  async pauseSchedule(scheduleId: string, paused: boolean): Promise<boolean> {
    const sql = this.ctx.storage.sql;
    const row = sql.exec<{ cron: string }>(`SELECT cron FROM schedules WHERE id = ?`, scheduleId).toArray()[0];
    if (!row) return false;
    // Resuming picks up from now, not from every run missed while paused.
    sql.exec(`UPDATE schedules SET paused = ?, next_run = ? WHERE id = ?`, paused ? 1 : 0, nextRun(parseCron(row.cron), Date.now()), scheduleId);
    await this.armAlarm();
    return true;
  }

  // ---- alarm: long delays and cron ----

  async alarm(): Promise<void> {
    const org = this.orgId();
    if (!org) return;
    const now = Date.now();
    const sql = this.ctx.storage.sql;
    for (const row of sql.exec<{ id: string; not_before: number }>(
      `SELECT id, not_before FROM messages WHERE state = 'scheduled' AND not_before <= ? LIMIT 500`, now + QUEUE_MAX_DELAY_MS,
    ).toArray()) {
      sql.exec(`UPDATE messages SET state = 'queued' WHERE id = ?`, row.id);
      await this.env.DELIVERY.send({ org, id: row.id }, { delaySeconds: Math.max(0, Math.ceil((row.not_before - now) / 1000)) });
    }
    const due = sql.exec<Row>(`SELECT * FROM schedules WHERE paused = 0 AND next_run <= ?`, now).toArray();
    if (due.length > 0) {
      // A paused organization (billing) must not keep calling URLs on cron.
      const account = await this.env.ACCOUNTS.get<{ enabled?: boolean; limits?: Partial<Limits> }>(`org:${org}`, 'json');
      for (const row of due) {
        const s = toSchedule(row);
        sql.exec(`UPDATE schedules SET next_run = ? WHERE id = ?`, nextRun(parseCron(s.cron), now), s.scheduleId);
        if (!account?.enabled) continue;
        await this.publish(org, {
          url: s.destination, method: s.method, headers: JSON.parse(String(row.headers)), body: String(row.body ?? ''),
          notBefore: now + (s.delay ?? 0) * 1000, retries: s.retries, callback: s.callback, failureCallback: s.failureCallback,
          timeoutMs: Number(row.timeout_ms), scheduleId: s.scheduleId,
        }, { ...DEFAULT_LIMITS, ...(account.limits ?? {}), maxPerSecond: Number.MAX_SAFE_INTEGER });
      }
    }
    await this.armAlarm();
  }

  // ---- operator ----

  /** Running totals, for per-message billing (dply reads the change since its last run). */
  async usage(): Promise<Record<string, number>> {
    const out: Record<string, number> = {};
    for (const r of this.ctx.storage.sql.exec<{ k: string; n: number }>(`SELECT k, n FROM usage`).toArray()) out[r.k] = Number(r.n);
    return out;
  }

  async purge(): Promise<void> {
    await this.ctx.storage.deleteAlarm();
    await this.ctx.storage.deleteAll();
  }

  // ---- helpers ----

  private async armAlarm(): Promise<void> {
    const sql = this.ctx.storage.sql;
    const msg = sql.exec<{ t: number | null }>(`SELECT MIN(not_before) AS t FROM messages WHERE state = 'scheduled'`).one().t;
    const sched = sql.exec<{ t: number | null }>(`SELECT MIN(next_run) AS t FROM schedules WHERE paused = 0`).one().t;
    const times = [msg === null ? null : Number(msg) - QUEUE_MAX_DELAY_MS, sched === null ? null : Number(sched)].filter((t): t is number => t !== null);
    if (times.length === 0) {
      await this.ctx.storage.deleteAlarm();
      return;
    }
    await this.ctx.storage.setAlarm(Math.max(Date.now() + 1000, Math.min(...times)));
  }

  private count(k: string): void {
    this.ctx.storage.sql.exec(`INSERT INTO usage (k, n) VALUES (?, 1) ON CONFLICT(k) DO UPDATE SET n = n + 1`, k);
  }

  private row(messageId: string): StoredMessage | null {
    const r = this.ctx.storage.sql.exec<Row>(`SELECT * FROM messages WHERE id = ?`, messageId).toArray()[0];
    return r ? toMessage(r) : null;
  }

  private setOrg(org: string): void {
    this.ctx.storage.sql.exec(`INSERT OR IGNORE INTO meta (k, v) VALUES ('org', ?)`, org);
  }

  private orgId(): string | null {
    return (this.ctx.storage.sql.exec<{ v: string }>(`SELECT v FROM meta WHERE k = 'org'`).toArray()[0]?.v) ?? null;
  }
}

function toMessage(r: Row): StoredMessage {
  return {
    messageId: String(r.id),
    url: String(r.url),
    method: String(r.method),
    headers: JSON.parse(String(r.headers ?? '{}')),
    body: String(r.body ?? ''),
    notBefore: Number(r.not_before),
    maxRetries: Number(r.retries),
    callback: r.callback ? String(r.callback) : undefined,
    failureCallback: r.failure_callback ? String(r.failure_callback) : undefined,
    timeoutMs: Number(r.timeout_ms),
    attempts: Number(r.attempts ?? 0),
    state: String(r.state) as StoredMessage['state'],
    createdAt: Number(r.created_at),
    scheduleId: r.schedule_id ? String(r.schedule_id) : undefined,
    dlqId: r.dlq_id ? String(r.dlq_id) : undefined,
    responseStatus: r.response_status === null || r.response_status === undefined ? undefined : Number(r.response_status),
    responseBody: r.response_body ? String(r.response_body) : undefined,
    error: r.error ? String(r.error) : undefined,
  };
}

function toSchedule(r: Row): Schedule {
  return {
    scheduleId: String(r.id),
    cron: String(r.cron),
    destination: String(r.url),
    method: String(r.method),
    createdAt: Number(r.created_at),
    retries: Number(r.retries),
    delay: Number(r.delay_ms) > 0 ? Math.round(Number(r.delay_ms) / 1000) : undefined,
    callback: r.callback ? String(r.callback) : undefined,
    failureCallback: r.failure_callback ? String(r.failure_callback) : undefined,
    isPaused: Number(r.paused) === 1,
    nextScheduleTime: Number(r.next_run),
    headersRaw: String(r.headers ?? '{}'),
    bodyBase64: String(r.body ?? ''),
    timeoutMs: Number(r.timeout_ms),
  };
}
