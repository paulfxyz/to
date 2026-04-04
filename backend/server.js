/**
 * server.js — hollr.to API Backend (v3.0.0)
 *
 * Express server powering the hollr.to SaaS platform.
 *
 * Routes overview
 * ───────────────
 *   POST /api/auth/magic-link        — request a login / signup link
 *   GET  /api/auth/verify/:token     — verify magic link, create session
 *   POST /api/auth/logout            — destroy session
 *   GET  /api/me                     — get authenticated user profile
 *
 *   POST /api/handle/check           — check if a handle is available
 *   POST /api/handle/claim           — claim a handle during onboarding
 *   POST /api/settings               — update API key + PIN (requires current PIN)
 *   POST /api/settings/change-pin    — change PIN (requires current PIN)
 *
 *   GET  /api/profile/:handle        — public profile info (for the canvas page)
 *   POST /api/send/:handle           — send a message to a handle owner
 *   POST /api/upload/:handle         — upload a file / voice recording for a handle
 *
 * Auth: session token sent as Bearer in Authorization header.
 * All routes return JSON. Errors: { error: string }.
 *
 * Environment variables (see .env.example)
 * ─────────────────────────────────────────
 *   PORT                 — HTTP port (default 3000)
 *   DATA_DIR             — path for SQLite + uploads (default ./data)
 *   ENCRYPTION_SECRET    — server-side secret for AES-256 key encryption
 *   PLATFORM_RESEND_KEY  — Resend API key for magic-link emails
 *   PLATFORM_FROM_EMAIL  — "from" address for magic-link emails
 *   BASE_URL             — public URL of this server (e.g. https://api.hollr.to)
 *   FRONTEND_URL         — public URL of the frontend (e.g. https://hollr.to)
 *   ALLOWED_ORIGINS      — comma-separated extra CORS origins
 */

require('dotenv').config();

const express    = require('express');
const cors       = require('cors');
const helmet     = require('helmet');
const rateLimit  = require('express-rate-limit');
const crypto     = require('crypto');
const path       = require('path');
const fs         = require('fs');
const { v4: uuidv4 } = require('uuid');
const bcrypt     = require('bcryptjs');
const multer     = require('multer');

const db         = require('./db');
const { encrypt, decrypt } = require('./crypto');
const { sendMagicLink, forwardMessage } = require('./mailer');

// ── Multer (file uploads) ────────────────────────────────────────────────────

const UPLOAD_DIR = path.join(process.env.DATA_DIR || path.join(__dirname, 'data'), 'uploads');
if (!fs.existsSync(UPLOAD_DIR)) fs.mkdirSync(UPLOAD_DIR, { recursive: true });

const storage = multer.diskStorage({
  destination: (_req, _file, cb) => cb(null, UPLOAD_DIR),
  destination: (req, _file, cb) => {
    const dir = path.join(UPLOAD_DIR, req.params.handle || 'anon');
    fs.mkdirSync(dir, { recursive: true });
    cb(null, dir);
  },
  filename: (_req, file, cb) => {
    const ext  = path.extname(file.originalname) || '.bin';
    const name = `${Date.now()}-${uuidv4().slice(0,8)}${ext}`;
    cb(null, name);
  },
});

const upload = multer({
  storage,
  limits: { fileSize: 50 * 1024 * 1024 }, // 50 MB
});

// ── Express setup ────────────────────────────────────────────────────────────

const app  = express();
const PORT = process.env.PORT || 3000;

// Allowed CORS origins
const allowedOrigins = [
  'https://hollr.to',
  'https://www.hollr.to',
  ...(process.env.ALLOWED_ORIGINS || '').split(',').filter(Boolean),
  // Allow localhost in dev
  ...(process.env.NODE_ENV !== 'production' ? ['http://localhost:3000', 'http://localhost:5173', 'http://127.0.0.1:5500'] : []),
];

app.use(helmet());
app.use(cors({
  origin: (origin, cb) => {
    if (!origin || allowedOrigins.includes(origin)) return cb(null, true);
    cb(new Error(`CORS: ${origin} not allowed`));
  },
  credentials: true,
}));
app.use(express.json({ limit: '1mb' }));

// Serve uploaded files publicly at /uploads/:handle/:filename
app.use('/uploads', express.static(UPLOAD_DIR));

// ── Rate limiting ────────────────────────────────────────────────────────────

const authLimiter = rateLimit({ windowMs: 15 * 60 * 1000, max: 10, message: { error: 'Too many requests' } });
const sendLimiter = rateLimit({ windowMs: 60 * 1000,      max: 5,  message: { error: 'Too many requests' } });

// ── Auth middleware ──────────────────────────────────────────────────────────

/**
 * requireAuth — injects req.user from a valid session token.
 */
function requireAuth(req, res, next) {
  const header = req.headers.authorization || '';
  const token  = header.startsWith('Bearer ') ? header.slice(7) : null;
  if (!token) return res.status(401).json({ error: 'Authentication required' });

  const session = db.prepare(`
    SELECT s.token, s.user_id, s.expires_at, u.email, u.handle, u.resend_key, u.pin_hash, u.from_email
    FROM sessions s JOIN users u ON u.id = s.user_id
    WHERE s.token = ? AND s.expires_at > unixepoch()
  `).get(token);

  if (!session) return res.status(401).json({ error: 'Invalid or expired session' });
  req.user  = session;
  req.token = token;
  next();
}

// ── Helpers ──────────────────────────────────────────────────────────────────

const HANDLE_RE = /^[a-zA-Z0-9_-]{2,30}$/;
const RESERVED  = new Set(['admin','api','app','www','mail','root','support','help','about','legal','status','cdn','static','uploads','hollr']);

function isValidHandle(h) {
  return HANDLE_RE.test(h) && !RESERVED.has(h.toLowerCase());
}

function sessionToken() { return crypto.randomBytes(32).toString('hex'); }

// ── Routes ───────────────────────────────────────────────────────────────────

// Health check
app.get('/health', (_req, res) => res.json({ ok: true, version: '3.0.0' }));

// ── Auth ─────────────────────────────────────────────────────────────────────

/**
 * POST /api/auth/magic-link
 * Body: { email }
 * Creates a magic link token and emails it. If the email doesn't exist yet,
 * this is also the first step of registration (handle is chosen on /verify).
 */
app.post('/api/auth/magic-link', authLimiter, async (req, res) => {
  const { email } = req.body;
  if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    return res.status(400).json({ error: 'Valid email required' });
  }

  const token     = uuidv4();
  const expiresAt = Math.floor(Date.now() / 1000) + 15 * 60; // 15 min

  // Clean up old tokens for this email
  db.prepare('DELETE FROM magic_links WHERE email = ?').run(email);

  db.prepare('INSERT INTO magic_links (email, token, expires_at) VALUES (?, ?, ?)').run(email, token, expiresAt);

  const frontendUrl = process.env.FRONTEND_URL || 'https://hollr.to';
  const link = `${frontendUrl}/auth/verify?token=${token}`;

  try {
    await sendMagicLink(email, link);
    res.json({ ok: true });
  } catch (err) {
    console.error('Magic link email failed:', err.message);
    res.status(500).json({ error: 'Failed to send login email' });
  }
});

/**
 * GET /api/auth/verify/:token
 * Validates magic link. Returns { session_token, is_new_user, user? }.
 * If new user, the client shows the handle-selection onboarding.
 */
app.get('/api/auth/verify/:token', (req, res) => {
  const row = db.prepare(`
    SELECT * FROM magic_links
    WHERE token = ? AND expires_at > unixepoch() AND used = 0
  `).get(req.params.token);

  if (!row) return res.status(400).json({ error: 'Invalid or expired link' });

  // Mark token as used
  db.prepare('UPDATE magic_links SET used = 1 WHERE id = ?').run(row.id);

  // Find or determine new-user status
  const user = db.prepare('SELECT * FROM users WHERE email = ?').get(row.email);

  // Create session
  const token     = sessionToken();
  const expiresAt = Math.floor(Date.now() / 1000) + 30 * 24 * 3600; // 30 days

  if (user) {
    db.prepare('INSERT INTO sessions (user_id, token, expires_at) VALUES (?, ?, ?)').run(user.id, token, expiresAt);
    return res.json({
      ok:           true,
      session_token: token,
      is_new_user:   false,
      user: {
        email:      user.email,
        handle:     user.handle,
        has_api_key: !!user.resend_key,
      },
    });
  }

  // New user — store email in a temporary table via a special "pending" session
  // We store a placeholder user entry with no handle; onboarding will fill it in.
  const inserted = db.prepare(`
    INSERT INTO users (email, handle) VALUES (?, ?)
  `).run(row.email, `__pending_${uuidv4().slice(0,8)}`);

  db.prepare('INSERT INTO sessions (user_id, token, expires_at) VALUES (?, ?, ?)').run(inserted.lastInsertRowid, token, expiresAt);

  res.json({
    ok:            true,
    session_token: token,
    is_new_user:   true,
    user: { email: row.email, handle: null, has_api_key: false },
  });
});

/**
 * POST /api/auth/logout
 * Destroys the current session.
 */
app.post('/api/auth/logout', requireAuth, (req, res) => {
  db.prepare('DELETE FROM sessions WHERE token = ?').run(req.token);
  res.json({ ok: true });
});

// ── User profile ──────────────────────────────────────────────────────────────

/**
 * GET /api/me
 * Returns authenticated user's profile (no sensitive fields).
 */
app.get('/api/me', requireAuth, (req, res) => {
  res.json({
    email:       req.user.email,
    handle:      req.user.handle?.startsWith('__pending') ? null : req.user.handle,
    has_api_key: !!req.user.resend_key,
    has_pin:     !!req.user.pin_hash,
    from_email:  req.user.from_email,
  });
});

// ── Handle management ─────────────────────────────────────────────────────────

/**
 * POST /api/handle/check
 * Body: { handle }
 * Returns { available: true/false }
 */
app.post('/api/handle/check', (req, res) => {
  const { handle } = req.body;
  if (!handle || !isValidHandle(handle)) return res.json({ available: false, reason: 'Invalid format' });
  const exists = db.prepare('SELECT id FROM users WHERE handle = ?').get(handle);
  res.json({ available: !exists });
});

/**
 * POST /api/handle/claim
 * Body: { handle, resend_key, pin, from_email }
 * Completes the onboarding for a new user.
 */
app.post('/api/handle/claim', requireAuth, async (req, res) => {
  const { handle, resend_key, pin, from_email } = req.body;

  if (!handle || !isValidHandle(handle)) return res.status(400).json({ error: 'Invalid handle format (2-30 alphanumeric/dash/underscore, not reserved)' });
  if (!pin || !/^\d{4,8}$/.test(pin))   return res.status(400).json({ error: 'PIN must be 4-8 digits' });

  // Ensure handle is not taken (race condition guard)
  const existing = db.prepare('SELECT id FROM users WHERE handle = ? AND id != ?').get(handle, req.user.user_id);
  if (existing) return res.status(409).json({ error: 'Handle already taken' });

  const pinHash      = bcrypt.hashSync(pin, 12);
  const encryptedKey = resend_key ? encrypt(resend_key) : null;

  db.prepare(`
    UPDATE users
    SET handle = ?, pin_hash = ?, resend_key = ?, from_email = ?, updated_at = unixepoch()
    WHERE id = ?
  `).run(handle, pinHash, encryptedKey, from_email || null, req.user.user_id);

  res.json({ ok: true, handle });
});

// ── Settings ──────────────────────────────────────────────────────────────────

/**
 * POST /api/settings
 * Body: { pin, resend_key?, from_email? }
 * Updates API key and/or from_email. Requires current PIN to authorise changes.
 */
app.post('/api/settings', requireAuth, (req, res) => {
  const { pin, resend_key, from_email } = req.body;
  const user = db.prepare('SELECT * FROM users WHERE id = ?').get(req.user.user_id);

  if (!user.pin_hash || !bcrypt.compareSync(String(pin), user.pin_hash)) {
    return res.status(403).json({ error: 'Incorrect PIN' });
  }

  const updates  = [];
  const params   = [];

  if (resend_key !== undefined) {
    updates.push('resend_key = ?');
    params.push(resend_key ? encrypt(resend_key) : null);
  }
  if (from_email !== undefined) {
    updates.push('from_email = ?');
    params.push(from_email || null);
  }

  if (updates.length) {
    updates.push('updated_at = unixepoch()');
    params.push(req.user.user_id);
    db.prepare(`UPDATE users SET ${updates.join(', ')} WHERE id = ?`).run(...params);
  }

  res.json({ ok: true });
});

/**
 * POST /api/settings/change-pin
 * Body: { current_pin, new_pin }
 */
app.post('/api/settings/change-pin', requireAuth, (req, res) => {
  const { current_pin, new_pin } = req.body;
  const user = db.prepare('SELECT * FROM users WHERE id = ?').get(req.user.user_id);

  if (!user.pin_hash || !bcrypt.compareSync(String(current_pin), user.pin_hash)) {
    return res.status(403).json({ error: 'Incorrect current PIN' });
  }
  if (!new_pin || !/^\d{4,8}$/.test(new_pin)) {
    return res.status(400).json({ error: 'New PIN must be 4-8 digits' });
  }

  const pinHash = bcrypt.hashSync(String(new_pin), 12);
  db.prepare('UPDATE users SET pin_hash = ?, updated_at = unixepoch() WHERE id = ?').run(pinHash, req.user.user_id);
  res.json({ ok: true });
});

// ── Public canvas API ─────────────────────────────────────────────────────────

/**
 * GET /api/profile/:handle
 * Public endpoint: returns just enough info to render the canvas.
 * Does NOT expose email, API key, PIN hash.
 */
app.get('/api/profile/:handle', (req, res) => {
  const user = db.prepare('SELECT handle, has_api_key FROM (SELECT handle, resend_key IS NOT NULL as has_api_key FROM users WHERE handle = ? COLLATE NOCASE)').get(req.params.handle);
  if (!user) return res.status(404).json({ error: 'Handle not found' });
  res.json({ handle: user.handle, active: user.has_api_key });
});

/**
 * POST /api/upload/:handle
 * Multipart upload. Returns { url } pointing to the publicly accessible file.
 */
app.post('/api/upload/:handle', sendLimiter, upload.single('file'), (req, res) => {
  if (!req.file) return res.status(400).json({ error: 'No file received' });

  const baseUrl = process.env.BASE_URL || `http://localhost:${PORT}`;
  const url = `${baseUrl}/uploads/${req.params.handle}/${req.file.filename}`;
  res.json({ ok: true, url });
});

/**
 * POST /api/send/:handle
 * Body (JSON): { contact, message, file_urls?, audio_url? }
 * Sends a message to the handle owner using THEIR Resend key.
 */
app.post('/api/send/:handle', sendLimiter, async (req, res) => {
  const { contact, message, file_urls, audio_url } = req.body;

  if (!message || message.trim().length < 1) return res.status(400).json({ error: 'Message is empty' });
  if (!contact)                               return res.status(400).json({ error: 'Contact is required' });

  const user = db.prepare('SELECT * FROM users WHERE handle = ? COLLATE NOCASE').get(req.params.handle);
  if (!user)          return res.status(404).json({ error: 'Handle not found' });
  if (!user.resend_key) return res.status(503).json({ error: 'This hollr instance is not configured yet' });
  if (!user.email)    return res.status(503).json({ error: 'No destination email configured' });

  let resendKey;
  try { resendKey = decrypt(user.resend_key); }
  catch { return res.status(500).json({ error: 'Failed to decrypt API key' }); }

  try {
    const result = await forwardMessage({
      resendKey,
      fromEmail:     user.from_email || 'hollr <noreply@up.paulfleury.com>',
      toEmail:       user.email,
      senderContact: contact,
      message:       message.trim(),
      fileUrls:      Array.isArray(file_urls) ? file_urls : [],
      audioUrl:      audio_url || null,
      handle:        user.handle,
    });

    if (result.id) {
      res.json({ ok: true });
    } else {
      console.error('Resend error:', JSON.stringify(result));
      res.status(502).json({ error: result.message || 'Email delivery failed' });
    }
  } catch (err) {
    console.error('Send error:', err.message);
    res.status(500).json({ error: 'Internal error' });
  }
});

// ── 404 fallback ──────────────────────────────────────────────────────────────

app.use((_req, res) => res.status(404).json({ error: 'Not found' }));

// ── Global error handler ──────────────────────────────────────────────────────

app.use((err, _req, res, _next) => {
  console.error(err);
  res.status(500).json({ error: err.message || 'Internal server error' });
});

// ── Start ─────────────────────────────────────────────────────────────────────

app.listen(PORT, () => {
  console.log(`🐺 hollr API running on port ${PORT} [${process.env.NODE_ENV || 'development'}]`);
});
