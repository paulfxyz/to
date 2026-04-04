# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.2.2] — 2026-04-04

### Fixed
- `reply_to` field now only sent to Resend when the contact input is a valid email address. Freeform text (Telegram handle, phone, URL, etc.) is accepted in the contact field and included in the email body — Resend never sees it as `reply_to`, preventing the validation error.

---

## [1.2.1] — 2026-04-03

### Fixed
- Topbar and page title updated from "Message to Paul Fleury" to "Send a message to Paul Fleury"

---

## [1.2.0] — 2026-04-03

### Added
- Deep inline documentation across all three PHP files (`settings.php`, `upload.php`, `send.php`):
  - File-level docblocks explaining design rationale, security model, and flow
  - Per-function PHPDoc with `@param` / `@return` types
  - Inline comments on every non-obvious decision
  - Named constants with explanatory comments (`CONFIG_FILE`, `CIPHER`, `RESEND_API_URL`, etc.)
- "Lessons learned" section in README covering 7 real issues encountered during development:
  1. Browser MediaRecorder blobs confuse PHP `finfo`
  2. Resend rejects `null` `reply_to` (vs. omitting the key)
  3. Resend domain verification must match the FROM address
  4. FTP root ≠ web root on SiteGround multi-domain hosting
  5. HTML email layout requires tables, not modern CSS
  6. Cron-free cache cleanup via probabilistic execution
  7. Static salt is acceptable for single-tenant encryption
- Tech stack table in README with rationale column for each choice
- Email layout ASCII diagram in README

### Changed
- README version badge bumped to `1.2.0`
- `send.php`: extracted `RESEND_API_URL` and `CURL_TIMEOUT` as named constants
- `send.php`: `reply_to` now omitted entirely from Resend payload when no contact provided (previously sent as `null`, which caused a Resend 400 error)
- `settings.php`: extracted `PIN_SENTINEL` and `PIN_MIN_LEN` as named constants; `pinHmac()` helper extracted for reuse

---

## [1.1.0] — 2026-04-03

### Added
- README badges: version, license, PHP version, Resend, live URL, no-dependencies
- Quick-start section in README
- Architecture diagram updated to include `.htaccess`
- `INSTALL.md` reference added to README

### Changed
- README rewritten with badges, improved prose, and tighter structure
- Version bumped to `1.1.0` across all files

---

## [1.0.0] — 2026-04-03

### Added

- Initial release of **To** — a timed, personal message canvas for `to.paulfleury.com`
- Timed writing session with Start / Pause / Resume / Reset controls
- Session state machine: `idle → running → paused → ready`
- Live word and character count in the writing footer
- **Voice recording** via browser MediaRecorder API with:
  - Live waveform visualiser (canvas, 48 bars)
  - Record / Stop / Play / Discard / Attach flow
  - Audio attached as `voice_message_<timestamp>.webm`
- **File attachments** — click-or-drag drop zone with:
  - MIME type detection and icon mapping
  - Per-file size display
  - Multi-file support (up to 50 MB per file, 100 MB total)
  - Remove individual files before sending
- **Settings modal** (⚙ cog in topbar):
  - Resend API key input with PIN protection
  - AES-256-CBC encryption via PBKDF2-SHA256 (100k iterations)
  - PIN verification without full decryption (HMAC-SHA256 verifier)
  - Change PIN flow with re-encryption
  - Tab interface: API Key / Change PIN
- **Send flow** (`send.php`):
  - Uploads audio and files to `/cache/` first via `upload.php`
  - Sends rich HTML + plain-text email via Resend REST API
  - Email from `to@up.paulfleury.com` → `hello@paulfleury.com`
  - Includes writing stats, full message, voice download button, and file list
- **Upload handler** (`upload.php`):
  - Multipart file upload with MIME validation via `finfo`
  - Random filename prefix to prevent collisions
  - Auto-cleanup of cache files older than 30 days (5% chance per request)
  - Directory listing blocked via `cache/index.html`
- **Success modal** with writing stats recap and **"Go to paulfleury.com"** button
- **Error modal** with retry button
- **Sending progress** spinner while uploading and emailing
- **Keyboard shortcuts** — platform-aware (Mac vs Windows/Linux):
  - `Space` — Start / Resume
  - `Esc` — Pause / Close modal
  - `⌘↵` / `Ctrl+↵` — Open send modal / confirm send
- Topbar simplified — removed "Workspace /" breadcrumb prefix
- Welcome screen with 3-step onboarding
- Mobile bottom bar (visible on screens ≤768px)
- Toast notification system
- Responsive layout — sidebar hidden on mobile, replaced by bottom bar
- `.htaccess` — blocks directory listing, protects `/config/`, sets PHP upload limits
