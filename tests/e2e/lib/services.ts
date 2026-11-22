/**
 * Local service probes: the mock Source (portal.ir API) and the local Backend.
 * Only loopback URLs are ever contacted (guarded in env.assertLocalUrl).
 */
import { BACKEND_URL, MOCK_SOURCE_URL, assertLocalUrl } from './env';

assertLocalUrl(MOCK_SOURCE_URL, 'MOBO_MOCK_SOURCE_URL');
assertLocalUrl(BACKEND_URL, 'MOBO_BACKEND_URL');

async function reachable(url: string): Promise<boolean> {
  try {
    const res = await fetch(url, { signal: AbortSignal.timeout(3000) });
    return res.status > 0;
  } catch {
    return false;
  }
}

export const backendUp = (): Promise<boolean> => reachable(BACKEND_URL + '/');
export const mockSourceUp = (): Promise<boolean> => reachable(MOCK_SOURCE_URL + '/__control/log');

/**
 * Raw GET /__control/log body ({ summary, entries }) or null when unreachable.
 * The mock returns at most the LAST `limit` entries (default 200), so growth is
 * measured with `summary.total` / `sinceSeq`, never with `entries.length`.
 */
async function fetchMockLog(params: Record<string, string | number> = {}): Promise<any | null> {
  try {
    const qs = new URLSearchParams(Object.entries(params).map(([k, v]) => [k, String(v)])).toString();
    const res = await fetch(MOCK_SOURCE_URL + '/__control/log' + (qs ? '?' + qs : ''), { signal: AbortSignal.timeout(5000) });
    if (!res.ok) return null;
    return await res.json();
  } catch {
    return null;
  }
}

function logEntries(body: any): any[] {
  if (Array.isArray(body)) return body;
  for (const k of ['entries', 'items', 'log', 'requests', 'data']) {
    if (Array.isArray(body?.[k])) return body[k];
  }
  return [];
}

function logTotal(body: any, entries: any[]): number {
  const total = Number(body?.summary?.total);
  return Number.isFinite(total) ? total : entries.length;
}

/**
 * Entries recorded by the mock Source (GET /__control/log). The exact payload
 * shape is owned by the mock; accept a bare array or {entries|items|log|requests}.
 * Only the most recent entries are returned. Returns null when the mock is unreachable.
 */
export async function mockLog(): Promise<unknown[] | null> {
  const body = await fetchMockLog();
  return body === null ? null : logEntries(body);
}

/** Position in the mock request log: lifetime request total + last sequence number. */
export interface MockCursor {
  total: number;
  seq: number;
}

/** Current mock log position, or null when the mock is unreachable. */
export async function mockCursor(): Promise<MockCursor | null> {
  const body = await fetchMockLog({ limit: 1 });
  if (body === null) return null;
  const entries = logEntries(body);
  const last = entries.length > 0 ? Number(entries[entries.length - 1]?.seq) : 0;
  return { total: logTotal(body, entries), seq: Number.isFinite(last) ? last : 0 };
}

/**
 * Requests recorded after `since`: the entries with seq > since.seq (bounded by
 * `limit`), and the count derived from `summary.total` so it stays correct past
 * the mock's 200-entry window. Null when the mock is unreachable.
 */
export async function mockRequestsSince(since: MockCursor, limit = 1000): Promise<{ count: number; entries: unknown[] } | null> {
  const body = await fetchMockLog({ sinceSeq: since.seq, limit });
  if (body === null) return null;
  /* Older mocks ignore sinceSeq: filter by seq locally when entries carry one. */
  const entries = logEntries(body).filter((e: any) => !Number.isFinite(Number(e?.seq)) || Number(e.seq) > since.seq);
  const grown = Math.max(0, logTotal(body, logEntries(body)) - since.total);
  return { count: Math.max(grown, entries.length), entries };
}

/**
 * Poll the mock log until requests were recorded after `since`.
 * Returns the new entries (a non-empty list whenever `summary.total` grew,
 * even if the entries themselves rotated out); empty array on timeout.
 */
export async function waitForMockRequests(since: MockCursor, timeoutMs = 30000): Promise<unknown[]> {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const fresh = await mockRequestsSince(since);
    if (fresh && fresh.count > 0) {
      return fresh.entries.length > 0 ? fresh.entries : new Array(fresh.count).fill({ rotated: true });
    }
    await new Promise((r) => setTimeout(r, 1000));
  }
  return [];
}
