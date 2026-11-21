/**
 * Environment / configuration for the MoboCore admin E2E suite.
 *
 * Secrets are read at runtime from env vars, falling back to the staging
 * dotenv-style files. Values are never logged.
 */
import * as fs from 'fs';
import * as path from 'path';

export const STAGING_ROOT = process.env.MOBO_STAGING_ROOT || 'C:\\MOBO-WORK\\staging';
export const WP_ADMIN_ENV_FILE = process.env.MOBO_WP_ADMIN_ENV || path.join(STAGING_ROOT, 'secrets', 'wp-admin.env');
export const DB_ENV_FILE = process.env.MOBO_DB_ENV || path.join(STAGING_ROOT, 'secrets', 'db.env');

export const BASE_URL = (process.env.BASE_URL || 'http://127.0.0.1:8081').replace(/\/+$/, '');
export const MOCK_SOURCE_URL = (process.env.MOBO_MOCK_SOURCE_URL || 'http://127.0.0.1:5090').replace(/\/+$/, '');
export const BACKEND_URL = (process.env.MOBO_BACKEND_URL || 'http://127.0.0.1:5080').replace(/\/+$/, '');

export const MYSQL_BIN = process.env.MOBO_MYSQL_BIN || 'C:\\wamp64\\bin\\mariadb\\mariadb11.5.2\\bin\\mysql.exe';
export const DB_HOST = process.env.MOBO_DB_HOST || '127.0.0.1';
export const DB_PORT = Number(process.env.MOBO_DB_PORT || 3310);

const LOCAL_HOSTS = new Set(['127.0.0.1', 'localhost', '[::1]', '::1']);

/** True when the host is a loopback host this suite may contact. */
export function isLocalHost(hostname: string): boolean {
  return LOCAL_HOSTS.has(hostname.toLowerCase());
}

/**
 * Hard guard: the suite performs destructive admin actions (reset, quarantine,
 * clear logs, delete pending connection). It must never run against anything
 * but a local staging site. Throws at config-load time, failing the whole run.
 */
export function assertLocalUrl(url: string, label: string): void {
  let parsed: URL;
  try {
    parsed = new URL(url);
  } catch {
    throw new Error(`[mobo-e2e] ${label} is not a valid URL: ${url}`);
  }
  if (!/^https?:$/.test(parsed.protocol) || !isLocalHost(parsed.hostname)) {
    throw new Error(`[mobo-e2e] Refusing to run: ${label} must be http(s)://127.0.0.1 or localhost, got "${parsed.origin}".`);
  }
}

/** Staging site map (from C:\MOBO-WORK\staging\scripts\write_site_configs.py). */
const SITES: Record<number, { name: string; db: string; prefix: string }> = {
  8081: { name: 'clean', db: 'mobo_stage_clean', prefix: 'wp_' },
  8086: { name: 'clean (PHP 7.4)', db: 'mobo_stage_clean', prefix: 'wp_' },
  8082: { name: 'bazhirca', db: 'mobo_stage_bazhirca', prefix: 'wp_' },
  8083: { name: 'casemobilee', db: 'mobo_stage_casemobilee', prefix: 'adminsadra' },
  8084: { name: 'ghaborg', db: 'mobo_stage_ghaborg', prefix: 'wp_' },
  8085: { name: 'griffinstore', db: 'mobo_stage_griffinstore', prefix: 'wp_' },
};

export function siteInfo(): { name: string; db: string; prefix: string } {
  const port = Number(new URL(BASE_URL).port || 80);
  const known = SITES[port];
  const db = process.env.MOBO_DB_NAME || known?.db;
  const prefix = process.env.MOBO_TABLE_PREFIX ?? known?.prefix;
  if (!db || prefix === undefined) {
    throw new Error(`[mobo-e2e] Unknown staging port ${port}; set MOBO_DB_NAME and MOBO_TABLE_PREFIX.`);
  }
  return { name: known?.name || `port-${port}`, db, prefix };
}

/** Minimal dotenv parser (KEY=VALUE, # comments, optional quotes). */
export function parseEnvFile(file: string): Record<string, string> {
  const out: Record<string, string> = {};
  if (!fs.existsSync(file)) return out;
  for (const raw of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
    const line = raw.trim();
    if (!line || line.startsWith('#')) continue;
    const eq = line.indexOf('=');
    if (eq <= 0) continue;
    const key = line.slice(0, eq).trim().replace(/^export\s+/, '');
    let value = line.slice(eq + 1).trim();
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
      value = value.slice(1, -1);
    }
    out[key] = value;
  }
  return out;
}

function pick(keys: string[], fileVars: Record<string, string>): string | undefined {
  for (const k of keys) {
    if (process.env[k]) return process.env[k];
  }
  for (const k of keys) {
    if (fileVars[k]) return fileVars[k];
  }
  return undefined;
}

export function adminCredentials(): { user: string; password: string } {
  const vars = parseEnvFile(WP_ADMIN_ENV_FILE);
  const user = pick(['WP_ADMIN_USER'], vars) || 'mobo_test_admin';
  const password = pick(['WP_ADMIN_PASSWORD'], vars);
  if (!password) {
    throw new Error(`[mobo-e2e] WP admin password not found (env WP_ADMIN_PASSWORD or ${WP_ADMIN_ENV_FILE}).`);
  }
  return { user, password };
}

export function dbCredentials(): { user: string; password: string } {
  const vars = parseEnvFile(DB_ENV_FILE);
  const user = pick(['MOBO_DB_USER', 'WP_DB_USER'], vars);
  const password = pick(['MOBO_DB_PASSWORD', 'WP_DB_PASSWORD'], vars);
  if (!user || password === undefined) {
    throw new Error(`[mobo-e2e] DB credentials not found (env MOBO_DB_USER/MOBO_DB_PASSWORD or WP_DB_USER/WP_DB_PASSWORD in ${DB_ENV_FILE}).`);
  }
  return { user, password };
}

export const STORAGE_STATE = path.join(__dirname, '..', '.auth', 'admin.json');
