# 📯 hollr

[![Version](https://img.shields.io/badge/version-3.0.0-ff6b35?style=flat-square)](https://github.com/paulfxyz/howlr/releases)
[![License: MIT](https://img.shields.io/badge/license-MIT-black?style=flat-square)](LICENSE)
[![Node](https://img.shields.io/badge/node-20-green?style=flat-square)](https://nodejs.org)
[![Fly.io](https://img.shields.io/badge/backend-Fly.io-6366f1?style=flat-square)](https://fly.io)
[![Open Source](https://img.shields.io/badge/open_source-yes-orange?style=flat-square)](https://github.com/paulfxyz/howlr)

**howlr** is a free, open-source SaaS platform where anyone can claim a personal handle and receive timed, distraction-free messages at `howlr.to/:handle`.

You sign up with your email, pick a handle, add your [Resend](https://resend.com) API key, set a PIN — and you're done. Messages land directly in your inbox, files and voice recordings included, all sent via your own Resend account.

> **Live:** [howlr.to](https://howlr.to) · **Example:** [howlr.to/paulfxyz](https://howlr.to/paulfxyz)

---

## What it does

| For message senders | For handle owners |
|---|---|
| Visit `howlr.to/yourfriend` | Claim `howlr.to/yourname` |
| Compose on a distraction-free timed canvas | Receive messages in your own inbox |
| Attach files or record a voice note | Messages delivered via your Resend key |
| Hit send — that's it | Full control, no middleman |

---

## Table of Contents

- [Features](#features)
- [Architecture](#architecture)
- [Project structure](#project-structure)
- [Getting started (self-host)](#getting-started-self-host)
- [API reference](#api-reference)
- [Stack](#stack)
- [Lessons learned](#lessons-learned)
- [Contributing](#contributing)
- [License](#license)
- [Disclaimer](#disclaimer)

---

## Features

- **Magic-link auth** — no passwords, ever. Enter email → get link → you're in.
- **Handle claiming** — `howlr.to/:handle` is yours forever. First come, first served.
- **Timed canvas** — a live stopwatch reminds senders how long they've been writing.
- **Voice recording** — record directly in the browser; audio delivered as a download link.
- **File uploads** — drag-and-drop any file type; uploaded to the server and linked in the email.
- **10 languages** — EN, FR, DE, IT, ES, NL, ZH, HI, JA, RU with auto-detection.
- **Dark / light / system theme** — CSS custom properties, zero flicker.
- **AES-256-CBC encryption** — your Resend API key is encrypted at rest with PBKDF2 key derivation.
- **Per-user Resend** — messages go through your own Resend account. We see nothing.
- **Keyboard shortcuts** — `⌘↵` / `Ctrl+Enter` to open send modal, `Space` to start timer, `Esc` to close modals.
- **Fully open source** — MIT. Fork it, self-host it, extend it.

---

## Architecture

```
┌─────────────────────────────────┐   CNAME    ┌────────────────────────┐
│   howlr.to (SiteGround)         │ ─────────► │  api.howlr.to          │
│                                 │            │  (Fly.io, Paris)        │
│   landing/index.html            │            │                         │
│   landing/auth/verify.html      │  REST API  │  Node.js / Express      │
│   landing/handle/index.html     │ ◄────────► │  SQLite (WAL)           │
│   .htaccess (mod_rewrite)       │            │  Fly persistent volume  │
└─────────────────────────────────┘            └────────────────────────┘
                                                         │
                                               ┌─────────▼──────────┐
                                               │   Resend API        │
                                               │  (magic links +     │
                                               │   user messages)    │
                                               └─────────────────────┘
```

**Routing (Apache .htaccess):**
- `/` → landing page
- `/auth/verify` → magic-link verification + onboarding
- `/:handle` → canvas for that handle (served from `handle/index.html`)

**Backend routes (Fly.io):**
- `POST /api/auth/magic-link` — request login email
- `GET  /api/auth/verify/:token` — consume magic link, create session
- `POST /api/auth/logout`
- `GET  /api/me` — authenticated profile
- `POST /api/handle/check` — availability check
- `POST /api/handle/claim` — claim handle during onboarding
- `POST /api/settings` — update Resend key (PIN required)
- `POST /api/settings/change-pin`
- `GET  /api/profile/:handle` — public profile (for canvas)
- `POST /api/send/:handle` — send a message to a handle owner
- `POST /api/upload/:handle` — upload file or audio

---

## Project structure

```
howlr/
├── backend/                  # Node.js/Express API (deploys to Fly.io)
│   ├── server.js             # Express routes and middleware
│   ├── db.js                 # SQLite schema and initialization
│   ├── crypto.js             # AES-256-CBC helpers
│   ├── mailer.js             # Resend email helpers (magic links + messages)
│   ├── fly.toml              # Fly.io deployment configuration
│   ├── package.json
│   └── .env.example          # Environment variable template
│
├── landing/                  # Static frontend (deploys to howlr.to via FTP)
│   ├── index.html            # Multi-language landing page
│   ├── .htaccess             # Apache routing (handle → canvas)
│   ├── auth/
│   │   └── verify.html       # Magic-link verification + onboarding
│   └── handle/
│       └── index.html        # Per-handle canvas (adapted from v2.0.0)
│
├── index.html                # (legacy) Single-user canvas at to.paulfleury.com
├── send.php                  # (legacy) PHP email handler
├── upload.php                # (legacy) PHP file upload handler
├── settings.php              # (legacy) PHP settings handler
├── CHANGELOG.md
├── INSTALL.md
├── LICENSE
└── README.md
```

---

## Getting started (self-host)

### Prerequisites

- Node.js 20+
- A [Resend](https://resend.com) account with a verified domain
- A [Fly.io](https://fly.io) account
- A web host with Apache + PHP (for the static frontend, any CDN works too)

### 1. Clone

```bash
git clone https://github.com/paulfxyz/howlr.git
cd howlr
```

### 2. Backend

```bash
cd backend
cp .env.example .env
# Fill in ENCRYPTION_SECRET, PLATFORM_RESEND_KEY, PLATFORM_FROM_EMAIL, etc.
npm install
npm start
```

#### Deploy to Fly.io

```bash
# Install Fly CLI
curl -L https://fly.io/install.sh | sh

# Authenticate
fly auth login

# Create app (first time)
fly launch --name howlr-api --region cdg

# Create persistent volume for SQLite + uploads
fly volumes create howlr_data --size 3 --region cdg

# Set secrets
fly secrets set ENCRYPTION_SECRET="$(openssl rand -hex 32)"
fly secrets set PLATFORM_RESEND_KEY="re_yourkey"
fly secrets set PLATFORM_FROM_EMAIL="howlr <you@yourdomain.com>"
fly secrets set FRONTEND_URL="https://yourdomain.com"
fly secrets set BASE_URL="https://api.yourdomain.com"

# Deploy
fly deploy
```

After deploying, note your app URL (e.g. `howlr-api.fly.dev`) and add a CNAME:
```
api.yourdomain.com → howlr-api.fly.dev
```

### 3. Frontend

Update `API_BASE` in `landing/handle/index.html` and `landing/auth/verify.html` and `landing/index.html` to point to your backend.

Upload `landing/` contents to your web host's document root via FTP.

---

## Stack

| Component | Technology | Why |
|---|---|---|
| Backend | Node.js 20 + Express | Lightweight, fast, great ecosystem |
| Database | SQLite + better-sqlite3 | Zero config, synchronous, Fly.io volume |
| Auth | Magic links via Resend | No password management, frictionless UX |
| Encryption | AES-256-CBC + PBKDF2 | Industry standard for at-rest key storage |
| PIN hashing | bcrypt (12 rounds) | Standard for credential storage |
| Email | Resend REST API | Dead simple, reliable, excellent deliverability |
| File uploads | Multer | Best-in-class multipart handling for Express |
| Rate limiting | express-rate-limit | Prevents brute force and spam |
| Security headers | helmet | One-liner for essential HTTP headers |
| Frontend | Vanilla HTML/CSS/JS | No build step, no dependencies, instant deploy |
| Fonts | Cabinet Grotesk + Satoshi (Fontshare) | Distinctive, refined, free |
| Backend hosting | Fly.io | Free tier, EU region, persistent volumes |
| Frontend hosting | SiteGround (Apache) | Paul's existing hosting, mod_rewrite routing |

---

## Lessons learned

These are real issues encountered during the development of this project across v1.0.0 → v3.0.0.

### 1. Resend `reply_to` must be omitted if not a valid email
Resend's API rejects the `reply_to` field if it's not a valid email address. The contact field accepts any freeform text (Telegram handle, phone number, etc.) — so we check the value with a regex before including `reply_to` in the payload.

### 2. `finfo` misidentifies WebM blobs as `application/octet-stream`
The PHP `finfo` extension fails to detect the MIME type of WebM audio blobs recorded in the browser. The fix: fall back to the browser-reported MIME type for `audio/*` content types.

### 3. Resend FROM address must be on a verified domain
The `from` field must be an address on a domain you've verified in your Resend dashboard. Sending from an unverified domain silently fails or returns a 403. For howlr.to, the platform key uses `to@up.paulfleury.com`.

### 4. SiteGround FTP root ≠ web root
The FTP root on SiteGround is one level above the public web root. You must `cd` into `howlr.to/public_html/` before uploading files.

### 5. JSON parsing crashes on empty PHP responses
If a PHP file exits without calling `jsonOut()`, the response body is empty. `JSON.parse('')` throws a SyntaxError. Always ensure every PHP code path calls `jsonOut()`.

### 6. `better-sqlite3` is synchronous
Unlike most Node.js database drivers, `better-sqlite3` is entirely synchronous. Do not use it inside async code with `await` — use `.get()`, `.all()`, and `.run()` directly. The async/await overhead is not needed and can cause confusion.

### 7. Apache `.htaccess` rewrite order matters
When routing `/:handle` to `handle/index.html`, the rule must appear after the static file / directory rules. Otherwise Apache rewrites the path to `index.html` even for existing files (CSS, images, etc.).

### 8. sessionStorage vs localStorage in sandboxed environments
Some hosting environments (iframes, browser extensions, certain CDNs) block `localStorage`. Using `sessionStorage` is more portable for short-lived auth tokens. The canvas and auth pages use `sessionStorage.setItem('howlr_session', token)`.

### 9. CORS must explicitly list origins
Wildcard `*` CORS doesn't work with `Authorization` headers and credentials. The backend maintains an explicit allowlist of origins and validates the `Origin` header on every request.

### 10. Building a SaaS by adapting a single-user app
Retrofitting a hardcoded single-user canvas into a dynamic multi-user system requires careful surgery: replacing static strings with `data-i18n` keys that get overridden per-profile, replacing PHP calls with REST API calls, and injecting a bootstrap script that knows the handle before the page renders.

### 11. Fly.io free tier "stop on idle"
Fly.io can stop machines that receive no traffic for a while. For a platform where users need instant response, set `auto_stop_machines = false` and `min_machines_running = 1` in `fly.toml`. This keeps the API warm at all times.

### 12. PBKDF2 is slow by design
100,000 PBKDF2 iterations for key derivation adds ~100ms per settings save/load. This is intentional — it makes brute force attacks impractical. Don't "optimise" this away.

---

## Contributing

PRs are welcome. Please:
1. Open an issue first for large changes
2. Keep the backend as thin as possible (storage + email, nothing else)
3. Keep the frontend dependency-free (no npm, no bundler)
4. Document your changes in CHANGELOG.md

---

## License

[MIT](LICENSE) — use it, fork it, ship it.

---

## Disclaimer

This project is **100% vibe coding**. I'm not a software engineer, an artist, or a coder. I'm a former hacker turned entrepreneur who has a very good relationship with AI and tools like Claude and Perplexity Computer. This codebase was built in a series of AI-assisted sessions. It works, it's reasonably secure, and it's open source — but approach it as the output of an enthusiastic human + AI collaboration, not a production-hardened software team. Use at your own risk, and please contribute improvements if you find them.

— [@paulfxyz](https://github.com/paulfxyz)
