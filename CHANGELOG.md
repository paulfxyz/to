# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [2.0.0] — 2026-04-04

### Added
- **i18n system** — Full internationalisation engine built into `index.html`:
  - 10 languages: English 🇬🇧, French 🇫🇷, German 🇩🇪, Italian 🇮🇹, Spanish 🇪🇸, Dutch 🇳🇱, Chinese 🇨🇳, Hindi 🇮🇳, Japanese 🇯🇵, Russian 🇷🇺
  - All UI strings keyed via a `T` translation object; `data-i18n` attributes on every element
  - Active language persisted to `localStorage` (`to_lang`)
  - Flag icon in topbar updates to reflect the active language
- **Language picker modal** — flag icon (🌐) in topbar opens a 2-column grid of all supported languages; active language shows a ✓ badge
- **Dark mode** — full CSS custom property overhaul:
  - Light mode: existing warm-paper palette (`--bg: #fff`, `--fg: rgb(55,53,47)`)
  - Dark mode: soft-dark palette (`--bg: rgb(25,25,23)`, `--fg: rgb(229,225,219)`)
  - `[data-theme="dark"]` attribute set on `<html>` by JS
  - Smooth 200ms transitions on all colour changes
  - Voice waveform canvas background adapts to active theme
- **Theme picker modal** — bulb icon (💡) in topbar opens a modal with three choices: Light, Dark, System (follows OS `prefers-color-scheme`)
  - OS-level dark/light changes detected in real time via `matchMedia` listener when System is selected
  - Active theme persisted to `localStorage` (`to_theme`)
- New topbar icon cluster: theme bulb → language flag → settings cog (all using shared `.topbar-btn` style)
- `color-scheme` CSS property applied per theme for native scroll bars and form element styling
- Keyboard shortcut `Esc` now also closes language and theme modals

### Changed
- All hardcoded colour values (`#000`, `#fff`, `rgba(0,0,0,.x)`) replaced with CSS custom properties throughout CSS, enabling seamless dark mode
- `updateState()` now calls `applyTranslations()` so state labels (Idle / Running / Paused) update immediately when language changes
- `start()`, `pause()`, `reset()` calls `applyTranslations()` to keep dynamic UI strings (textarea placeholder, mobile toggle label) in sync with current language
- Toast messages now use translated strings from `T[currentLang]`
- Word/character counter pluralisation now resolves via `t('word')` / `t('words')` per language
- Sidebar footer, welcome modal, send modal, error modal — all fully translated
- Settings modal CSS converted to CSS variables (was partially hardcoded)
- Theme initialisation runs before first paint to avoid flash of wrong theme

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
