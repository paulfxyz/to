# Changelog

All notable changes to this project will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

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
  - Email from `to@paulfleury.com` → `hello@paulfleury.com`
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
