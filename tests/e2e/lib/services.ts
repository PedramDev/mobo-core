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
 * Entries recorded by the mock Source (GET /__control/log). The exact payload
 * shape is owned by the mock; accept a bare array or {entries|items|log|requests}.
 * Returns null when the mock is unreachable.
 */
export async function mockLog(): Promise<unknown[] | null> {
  try {
    const res = await fetch(MOCK_SOURCE_URL + '/__control/log', { signal: AbortSignal.timeout(5000) });
    if (!res.ok) return null;
    const body: any = await res.json();
    if (Array.isArray(body)) return body;
    for (const k of ['entries', 'items', 'log', 'requests', 'data']) {
      if (Array.isArray(body?.[k])) return body[k];
    }
    return [];
  } catch {
    return null;
  }
}

/**
 * Poll the mock log until it has grown past `before` entries.
 * Returns the new entries (empty array on timeout).
 */
export async function waitForMockRequests(before: number, timeoutMs = 30000): Promise<unknown[]> {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const log = await mockLog();
    if (log && log.length > before) return log.slice(before);
    await new Promise((r) => setTimeout(r, 1000));
  }
  return [];
}
