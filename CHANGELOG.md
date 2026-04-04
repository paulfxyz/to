# Changelog

All notable changes to this project are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning follows [Semantic Versioning](https://semver.org/).

---

## [3.0.0] — 2026-04-04

### 🚀 Major — Platform relaunch as howlr.to SaaS

This is a full architectural evolution. The project was previously a single-user
canvas (`to` / `to.paulfleury.com`). It is now a **free, open-source multi-user
SaaS platform** where anyone can claim a handle at `howlr.to/:handle`.

### Added
- **Multi-user platform** — anyone can register at [howlr.to](https://howlr.to), claim a unique handle, and receive messages at `howlr.to/:handle`
- **Node.js / Express backend** deployed on Fly.io (`api.howlr.to`)
  - Magic-link email authentication (no passwords, ever)
  - SQLite + better-sqlite3 for persistent storage (Fly.io volume)
  - AES-256-CBC + PBKDF2 encryption of per-user Resend API keys
  - bcrypt PIN hashing
  - Rate limiting (express-rate-limit) on auth and send routes
  - Multer for file/audio uploads → `/data/uploads/:handle/`
  - `nodemailer` + Resend REST API for magic-link emails
  - Full REST API: `/api/auth/*`, `/api/handle/*`, `/api/settings`, `/api/send/:handle`, `/api/upload/:handle`, `/api/profile/:handle`
- **Multi-language landing page** (`howlr.to`) in 10 languages: EN, FR, DE, IT, ES, NL, ZH, HI, JA, RU
  - Language auto-detection from browser
  - Manual language picker modal
  - Dark / light theme toggle (system default)
  - Cabinet Grotesk + Satoshi font pairing
  - Animated handle demo typewriter
  - Live preview timer simulation
  - Signup form that calls the magic-link API
- **Auth verify page** (`howlr.to/auth/verify`) — handles magic link verification and new-user onboarding
  - Live handle availability check (debounced)
  - Resend key + from email + PIN collection
  - Immediate redirect to canvas after claiming
- **Per-handle canvas page** (`howlr.to/:handle`) — the full v2.0.0 canvas, adapted to:
  - Load the handle owner's profile from `/api/profile/:handle` dynamically
  - Replace all "Paul Fleury" references with the actual handle owner's name
  - Route uploads and sends through the REST API
  - Wire settings modal to REST endpoints with Bearer token auth
  - Show a friendly 404 page if the handle doesn't exist
- **Apache `.htaccess`** routing: `/` → landing, `/auth/verify` → auth, `/:handle` → canvas
- **GitHub repo renamed** from `paulfxyz/to` → `paulfxyz/howlr`
- **Fly.io deployment config** (`fly.toml`): Paris region, 256 MB shared CPU, persistent `/data` volume

### Architecture
| Layer | Tech |
|---|---|
| Backend | Node.js 20 + Express 4 on Fly.io |
| Database | SQLite via better-sqlite3 (WAL mode) |
| Auth | Magic links via Resend, sessions in SQLite |
| Encryption | AES-256-CBC + PBKDF2-SHA256 (100k iterations) |
| Email | Resend REST API (platform key for auth, per-user key for messages) |
| File uploads | Multer → Fly.io persistent volume, served as static |
| Frontend | Plain HTML/CSS/JS, no build step, deployed via FTP |
| Routing | Apache mod_rewrite on SiteGround |
| DNS | `api.howlr.to` CNAME → `howlr-api.fly.dev` |

### Changed
- Project renamed: **to** → **howlr**
- Domain: `to.paulfleury.com` → `howlr.to`
- The per-handle canvas is now fully dynamic (no hardcoded owner names)

---

## [2.0.0] — 2026-04-04

### Added
- **Full i18n system** — 10 languages: English, French, German, Italian, Spanish, Dutch, Chinese, Hindi, Japanese, Russian
- **Language picker modal** — flag icon in top-right opens a grid of 10 language options with auto-detection from browser locale
- **Dark / light / system theme** — bulb icon in top-right opens theme picker modal; CSS custom properties for all colours; `data-theme` attribute on `<html>`
- **Welcome modal flag strip** — compact flag row added to welcome card for immediate language switching

### Changed
- All CSS colours converted to `--custom-properties` with full dark/light variants
- All UI text extracted into `STRINGS[lang]` i18n map

---

## [1.2.2] — 2026-04-04

### Fixed
- `reply_to` validation: contact field now accepts any freeform text; `reply_to` only sent to Resend if value is a valid email address (Resend rejected non-email `reply_to` values)

---

## [1.2.1] — 2026-04-04

### Changed
- Page title and topbar renamed: "Message to Paul Fleury" → "Send a message to Paul Fleury"

---

## [1.2.0] — 2026-04-04

### Added
- Deep documentation pass on all PHP files (`send.php`, `upload.php`, `settings.php`)
- README expanded with detailed lessons learned, tech stack table, bottlenecks, solutions

---

## [1.1.0] — 2026-04-04

### Added
- README badges (version, license, PHP version)
- Quick-start / INSTALL.md guide
- Improved README structure (ToC, sections)

---

## [1.0.0] — 2026-04-04

### Added
- Initial release: personal message canvas at `to.paulfleury.com`
- `index.html` — Notion-inspired fullscreen canvas with:
  - Stopwatch timer (start/pause/reset)
  - Rich textarea with word/character count
  - Voice recording (MediaRecorder API, WebM)
  - File drag-and-drop upload
  - Send modal (Resend API via PHP backend)
  - Settings modal (AES-256-CBC + PBKDF2, PIN protection)
  - Keyboard shortcuts (⌘↵ to send, Space to start, Esc to close)
- `send.php` — decrypts API key, builds HTML+text email, calls Resend REST API via cURL
- `upload.php` — multipart upload to `/cache/`, probabilistic 30-day cleanup
- `settings.php` — AES-256-CBC + PBKDF2-SHA256 encryption of Resend key; PIN hashing; JSON settings file
- `.htaccess` — security headers, PHP error suppression
- GitHub repository created: `paulfxyz/to` (public)
- FTP deployed to `to.paulfleury.com`
