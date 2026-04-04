/**
 * db.js — SQLite database initialisation for howlr.to
 *
 * Schema overview
 * ───────────────
 * users          — registered accounts (email, handle, encrypted Resend key, PIN hash)
 * magic_links    — one-time login tokens (expire after 15 min)
 * sessions       — authenticated sessions (expire after 30 days)
 *
 * The database file lives at DATA_DIR/howlr.db (configurable via env).
 * On Fly.io, DATA_DIR should point to a persistent volume, e.g. /data.
 */

const Database = require('better-sqlite3');
const path     = require('path');
const fs       = require('fs');

const DATA_DIR = process.env.DATA_DIR || path.join(__dirname, 'data');
if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });

const db = new Database(path.join(DATA_DIR, 'howlr.db'));

// Enable WAL mode for better concurrent read performance
db.pragma('journal_mode = WAL');
db.pragma('foreign_keys = ON');

// ── Schema ──────────────────────────────────────────────────────────────────

db.exec(`
  CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE,
    handle        TEXT    NOT NULL UNIQUE COLLATE NOCASE,
    resend_key    TEXT,           -- AES-256-CBC encrypted Resend API key
    pin_hash      TEXT,           -- bcrypt hash of the 4-digit PIN
    from_email    TEXT,           -- "from" address used when sending (defaults to to@up.paulfleury.com)
    created_at    INTEGER NOT NULL DEFAULT (unixepoch()),
    updated_at    INTEGER NOT NULL DEFAULT (unixepoch())
  );

  CREATE TABLE IF NOT EXISTS magic_links (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    email      TEXT    NOT NULL,
    token      TEXT    NOT NULL UNIQUE,
    expires_at INTEGER NOT NULL,   -- unix timestamp
    used       INTEGER NOT NULL DEFAULT 0
  );

  CREATE TABLE IF NOT EXISTS sessions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token      TEXT    NOT NULL UNIQUE,
    expires_at INTEGER NOT NULL    -- unix timestamp
  );

  CREATE INDEX IF NOT EXISTS idx_magic_links_token  ON magic_links(token);
  CREATE INDEX IF NOT EXISTS idx_sessions_token     ON sessions(token);
  CREATE INDEX IF NOT EXISTS idx_users_handle       ON users(handle);
`);

module.exports = db;
