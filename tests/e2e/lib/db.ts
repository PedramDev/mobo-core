/**
 * Read-only state verification helpers. Queries the staging MariaDB through the
 * mysql CLI. Only SELECT/SHOW statements are accepted, and every statement runs
 * inside a READ ONLY transaction as a second line of defense.
 */
import { execFileSync } from 'child_process';
import { DB_HOST, DB_PORT, MYSQL_BIN, dbCredentials, siteInfo, isLocalHost } from './env';

export type Row = Record<string, string | null>;

const READ_ONLY = /^\s*(select|show|describe|desc|explain)\b/i;

/** Escape a string literal for MariaDB (inputs here are test constants, but stay safe). */
export function lit(value: string | number): string {
  if (typeof value === 'number') return String(Math.trunc(value));
  return "'" + String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/\0/g, '\\0') + "'";
}

function unescapeBatch(field: string): string | null {
  if (field === 'NULL') return null;
  return field.replace(/\\(.)/g, (_m, c: string) => (c === 'n' ? '\n' : c === 't' ? '\t' : c === '0' ? '\0' : c === 'r' ? '\r' : c));
}

export function query(sql: string): Row[] {
  const trimmed = sql.trim().replace(/;\s*$/, '');
  if (!READ_ONLY.test(trimmed) || trimmed.includes(';')) {
    throw new Error(`[mobo-e2e] db.query accepts a single read-only SELECT/SHOW statement only: ${trimmed.slice(0, 80)}`);
  }
  if (!isLocalHost(DB_HOST)) throw new Error('[mobo-e2e] DB host must be local.');
  const { user, password } = dbCredentials();
  const { db } = siteInfo();
  const script = `SET SESSION TRANSACTION READ ONLY; START TRANSACTION READ ONLY; ${trimmed}; ROLLBACK;`;
  const out = execFileSync(
    MYSQL_BIN,
    [`--host=${DB_HOST}`, `--port=${DB_PORT}`, `--user=${user}`, '--protocol=TCP', '--default-character-set=utf8mb4', '--batch', `--database=${db}`, '-e', script],
    { env: { ...process.env, MYSQL_PWD: password }, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, windowsHide: true, timeout: 30000 },
  );
  const lines = out.split(/\r?\n/).filter((l) => l.length > 0);
  if (lines.length === 0) return [];
  const headers = lines[0].split('\t');
  return lines.slice(1).map((line) => {
    const cells = line.split('\t');
    const row: Row = {};
    headers.forEach((h, i) => (row[h] = unescapeBatch(cells[i] ?? 'NULL')));
    return row;
  });
}

export function scalar(sql: string): string | null {
  const rows = query(sql);
  if (rows.length === 0) return null;
  const first = rows[0];
  return first[Object.keys(first)[0]];
}

export function count(sql: string): number {
  return Number(scalar(sql) || 0);
}

export function t(name: string): string {
  return '`' + siteInfo().prefix + name + '`';
}

export function tableExists(name: string): boolean {
  return query(`SHOW TABLES LIKE ${lit(siteInfo().prefix + name)}`).length > 0;
}

export function getOption(name: string): string | null {
  return scalar(`SELECT option_value FROM ${t('options')} WHERE option_name = ${lit(name)} LIMIT 1`);
}

/** Default (is_default=1) Connection id, or 0 when the custom storage is absent. */
export function defaultConnectionId(): number {
  if (!tableExists('mobo_connections')) return 0;
  return Number(scalar(`SELECT id FROM ${t('mobo_connections')} WHERE is_default = 1 ORDER BY id ASC LIMIT 1`) || 0);
}

/**
 * Read a MoboCore setting through the same ownership boundary as
 * Mobo_Core_Settings::get(): a Connection-scoped row wins, else wp_options.
 */
export function getSetting(key: string, connectionId = defaultConnectionId()): string | null {
  if (connectionId > 0 && tableExists('mobo_connection_settings')) {
    const rows = query(
      `SELECT setting_value FROM ${t('mobo_connection_settings')} WHERE connection_id = ${lit(connectionId)} AND setting_key = ${lit(key)} LIMIT 1`,
    );
    if (rows.length > 0) return rows[0].setting_value;
  }
  return getOption(key);
}

export function columnExists(table: string, column: string): boolean {
  return tableExists(table) && query(`SHOW COLUMNS FROM ${t(table)} LIKE ${lit(column)}`).length > 0;
}

/** Legacy wp_options names used before the durable state store existed. */
const LEGACY_STATE_OPTION: Record<string, string> = { sync_state: 'mobo_core_sync_state' };

/**
 * Raw serialized value of a durable state-store key (wp_mobo_state), falling
 * back to the legacy wp_options copy on installs without the state store.
 */
export function getState(key: string, connectionId = defaultConnectionId()): string | null {
  if (tableExists('mobo_state') && columnExists('mobo_state', 'connection_id')) {
    if (connectionId <= 0) return null;
    return scalar(`SELECT state_value FROM ${t('mobo_state')} WHERE connection_id = ${lit(connectionId)} AND state_key = ${lit(key)} LIMIT 1`);
  }
  if (tableExists('mobo_state')) {
    const v = scalar(`SELECT state_value FROM ${t('mobo_state')} WHERE state_key = ${lit(key)} LIMIT 1`);
    if (v !== null) return v;
  }
  return getOption(LEGACY_STATE_OPTION[key] || key);
}

/**
 * Extract a scalar field from a PHP-serialized array (first occurrence of the
 * key, which is the top-level key for the state shapes used here).
 */
export function serializedField(serialized: string | null, key: string): string | null {
  if (!serialized) return null;
  const k = key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const re = new RegExp(`s:\\d+:"${k}";(?:s:\\d+:"([^"]*)"|i:(-?\\d+)|b:([01])|d:([-0-9.eE]+)|N;)`);
  const m = serialized.match(re);
  if (!m) return null;
  return m[1] ?? m[2] ?? m[3] ?? m[4] ?? null;
}

/** HPOS-aware order helpers. */
export function hposEnabled(): boolean {
  return getOption('woocommerce_custom_orders_table_enabled') === 'yes' && tableExists('wc_orders');
}

export function orderMeta(orderId: number, key: string): string | null {
  if (hposEnabled()) {
    return scalar(`SELECT meta_value FROM ${t('wc_orders_meta')} WHERE order_id = ${lit(orderId)} AND meta_key = ${lit(key)} ORDER BY id DESC LIMIT 1`);
  }
  return scalar(`SELECT meta_value FROM ${t('postmeta')} WHERE post_id = ${lit(orderId)} AND meta_key = ${lit(key)} ORDER BY meta_id DESC LIMIT 1`);
}

export function orderNoteCount(orderId: number): number {
  return count(`SELECT COUNT(*) FROM ${t('comments')} WHERE comment_post_ID = ${lit(orderId)} AND comment_type = 'order_note'`);
}

export function latestOrderNote(orderId: number): string | null {
  return scalar(`SELECT comment_content FROM ${t('comments')} WHERE comment_post_ID = ${lit(orderId)} AND comment_type = 'order_note' ORDER BY comment_ID DESC LIMIT 1`);
}

/**
 * Single post/product meta value. Since the 10.34.77 storage migration MoboCore
 * keeps its product meta (e.g. _mobo_pricing_override_*) in wp_mobo_entity_meta,
 * read the same way the plugin does (sort_order, id); fall back to wp_postmeta
 * for keys/installs that are not migrated. A Mobo-managed key (_mobo_*, mobo_*)
 * of an object that already lives in the custom storage never falls back, so a
 * deleted override is not shadowed by a retired wp_postmeta leftover.
 */
export function postMeta(postId: number, key: string): string | null {
  if (tableExists('mobo_entity_meta')) {
    const rows = query(
      `SELECT meta_value FROM ${t('mobo_entity_meta')} WHERE woo_object_id = ${lit(postId)} AND meta_key = ${lit(key)} ORDER BY sort_order ASC, id ASC LIMIT 1`,
    );
    if (rows.length > 0) return rows[0].meta_value;
    const managed = key.startsWith('_mobo_') || key.startsWith('mobo_');
    if (managed && count(`SELECT COUNT(*) FROM ${t('mobo_entity_meta')} WHERE woo_object_id = ${lit(postId)}`) > 0) return null;
  }
  return scalar(`SELECT meta_value FROM ${t('postmeta')} WHERE post_id = ${lit(postId)} AND meta_key = ${lit(key)} ORDER BY meta_id DESC LIMIT 1`);
}

/** True when the DB is reachable with the configured credentials. */
export function dbReachable(): boolean {
  try {
    return scalar('SELECT 1') === '1';
  } catch {
    return false;
  }
}
