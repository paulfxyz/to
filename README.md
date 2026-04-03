# To

[![Version](https://img.shields.io/badge/version-1.2.0-000000?style=flat-square)](https://github.com/paulfxyz/to/releases/tag/v1.2.0)
[![License](https://img.shields.io/badge/license-MIT-000000?style=flat-square)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.1+-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![Resend](https://img.shields.io/badge/email-Resend-000000?style=flat-square)](https://resend.com)
[![Live](https://img.shields.io/badge/live-to.paulfleury.com-000000?style=flat-square)](https://to.paulfleury.com)
[![No dependencies](https://img.shields.io/badge/dependencies-none-brightgreen?style=flat-square)](https://github.com/paulfxyz/to)

**A personal, timed message canvas — with email delivery, voice recording, and file attachments.**

`to.paulfleury.com` is a minimal Notion-inspired writing surface that lets anyone send a direct, timed message to Paul Fleury. The clock only runs while you're actively typing — so every second counted is a second genuinely spent writing. When done, the message is delivered as a richly formatted email via [Resend](https://resend.com), with voice notes and files hosted in `/cache/` and linked directly inside the email.

Zero npm. Zero build step. Three PHP files. One HTML file. Ships on any shared host in under five minutes.

---

## Features

| Feature | Details |
|---|---|
| **Timed writing** | Session timer runs only while `Start` is active; pauses on `Esc` |
| **Voice recording** | In-browser MediaRecorder with live waveform visualiser (48-bar canvas) |
| **File attachments** | Click-or-drag drop zone — 50 MB per file, 100 MB per send |
| **Email delivery** | Server-side via Resend REST API — rich HTML + plain-text, SPF/DKIM signed |
| **Settings modal** | Cog icon in topbar → Resend API key stored AES-256 encrypted on disk |
| **PIN protection** | PBKDF2-SHA256 (100k iterations) + AES-256-CBC + HMAC verifier |
| **Keyboard shortcuts** | Mac (`⌘↵`) and Windows/Linux (`Ctrl+↵`) — auto-detected at runtime |
| **Success screen** | Stats recap + "Go to paulfleury.com" button |
| **Serverless-ready** | Pure PHP 8.1 — no composer, no cron, no database |
| **Auto cache cleanup** | Files older than 30 days deleted probabilistically (no cron needed) |

---

## Architecture

```
to/
├── index.html            ← Single-page app — all UI, state, and JS in one file
├── send.php              ← Decrypts API key → sends email via Resend REST API
├── upload.php            ← Multipart upload handler → stores files in /cache/
├── settings.php          ← PIN-protected encrypted settings CRUD
├── .htaccess             ← Blocks /config/ access, disables directory listing
├── cache/                ← Temporary file storage (auto-purged after 30 days)
│   └── index.html        ← 403 stub — prevents directory listing fallback
└── config/
    └── settings.enc.json ← AES-256 encrypted config (created on first save)
```

All frontend logic lives in a single `index.html` — no bundler, no framework, no CDN dependencies beyond Google Fonts. The PHP layer is intentionally minimal: three focused files, each with a single responsibility.

---

## Security model

The Resend API key is **never stored in plaintext**. Full encryption chain:

```
PIN (user input)
  │
  ▼  PBKDF2-SHA256 · 100,000 iterations · static salt
256-bit AES key
  │
  ├──▶  AES-256-CBC(apiKey, key, randomIV)  →  base64(IV ∥ ciphertext)  →  stored
  │
  └──▶  HMAC-SHA256("pin_verify_token", key)  →  stored alongside ciphertext
```

On every send:
1. Browser submits PIN with the message payload
2. Server derives the AES key via PBKDF2 (same parameters)
3. Verifies PIN using `hash_equals()` against the stored HMAC (constant-time — no timing attacks)
4. Decrypts the API key, calls Resend, discards key from memory
5. PIN never persists in any server-side session

**Why a static salt?** This is a single-tenant tool (one user, one config file). A static salt is acceptable because: the PIN provides the entropy variation; a per-record random salt would need to be stored in plaintext anyway; and 100k PBKDF2 iterations already make brute-force attacks expensive (~150 ms per guess on a shared host CPU).

---

## Keyboard shortcuts

| Action | macOS | Windows / Linux |
|---|---|---|
| Start / Resume | `Space` | `Space` |
| Pause | `Esc` | `Esc` |
| Open send modal | `⌘ ↵` | `Ctrl ↵` |
| Confirm send | `⌘ ↵` | `Ctrl ↵` |
| Close modal | `Esc` | `Esc` |

Platform is detected at runtime via `navigator.platform` / `navigator.userAgent`. Mac users see `⌘↵` in the UI; everyone else sees `Ctrl+↵`.

---

## Email format

Every message is sent **from** `to@up.paulfleury.com` → **to** `hello@paulfleury.com`:

```
┌─────────────────────────────────────┐
│ Black header — sender name + eyebrow │
├─────────────────────────────────────┤
│ Grey stats bar — time · words · chars│
├─────────────────────────────────────┤
│ Reply-to (if provided)               │
│ Message body (nl2br, HTML-escaped)   │
│ 🎙️ Voice card — Listen/Download btn  │
│ 📎 Files table — name · size · MIME  │
├─────────────────────────────────────┤
│ Footer — sent via to.paulfleury.com  │
└─────────────────────────────────────┘
```

Full plain-text fallback included for accessibility and spam filter compatibility.

---

## Tech stack

| Layer | Choice | Why |
|---|---|---|
| Frontend | Vanilla HTML5 / CSS3 / ES2020 | No build step, zero dependencies, loads instantly |
| Audio capture | Browser MediaRecorder API | Built-in, no library needed, outputs WebM natively |
| Canvas waveform | HTML Canvas 2D API | 48-bar live visualiser, ~20 lines of code |
| Backend | PHP 8.1+ | Ships on every shared host, `openssl` + `curl` always available |
| Email | Resend REST API | HTTPS port 443 (never blocked), auto SPF/DKIM, free tier generous |
| Encryption | AES-256-CBC + PBKDF2 | PHP `openssl_*` built-in, no library required |
| Fonts | Playfair Display + Lora (Google Fonts) | Editorial serif feel, loaded via CDN |
| Hosting | SiteGround / any cPanel host | FTP deploy, no server configuration needed |

---

## Lessons learned

This is a small project, but it surface-tested a surprising number of sharp edges. Documented here so the next deployment is painless.

### 1. Browser MediaRecorder blobs confuse PHP's finfo

**Problem:** `finfo::file()` reads magic bytes to identify MIME types. Browser-recorded audio blobs (WebM from MediaRecorder) often don't start with a recognisable magic sequence — finfo reports them as `application/octet-stream`, which is on the block-list.

**Solution:** When finfo returns `application/octet-stream` but the browser-supplied `Content-Type` header says `audio/*`, trust the browser. The worst case of this relaxation is a corrupt audio file that won't play — not a security issue.

**Lesson:** Server-side MIME validation via magic bytes is more reliable than trusting `$_FILES['type']` for most file types, but has known gaps for streaming media formats. Layer both checks.

---

### 2. Resend rejects null reply_to

**Problem:** The initial implementation passed `'reply_to' => null` in the Resend API payload when the sender left the contact field empty. Resend validates the field strictly and returns a `400 Bad Request`: *"The email address needs to follow the email@example.com format."*

**Solution:** Only include `reply_to` in the payload when a non-empty contact string is available. In PHP, simply omit the key from the array rather than setting it to null.

**Lesson:** REST APIs frequently distinguish between a missing key and a null value — don't assume null is equivalent to omission. Always read the API's validation rules for optional fields.

---

### 3. Resend domain verification must match FROM address

**Problem:** The intuitive sender address `to@paulfleury.com` fails because Resend requires the FROM domain to be verified via DNS (DKIM + SPF records). `paulfleury.com` is not a verified Resend sending domain in this deployment — but `up.paulfleury.com` is.

**Solution:** Use `to@up.paulfleury.com` as FROM_EMAIL. The email still arrives cleanly in Paul's inbox; the subdomain in the sender address is barely noticeable.

**Lesson:** Transactional email services enforce domain ownership at the DNS level. Always verify your sending domain before writing a single line of code — and document which subdomain is verified so the next developer doesn't debug a cryptic "domain not verified" error.

---

### 4. FTP root ≠ web root on SiteGround

**Problem:** The FTP account root (`/`) maps to the hosting account home directory, not to any specific site's `public_html`. Uploading files to `/` populates the account root, not `to.paulfleury.com/public_html`. The files were "uploaded successfully" but the site didn't change.

**Solution:** Always `cd to.paulfleury.com/public_html` before uploading. The correct target path on this host is `to.paulfleury.com/public_html/`.

**Lesson:** On multi-domain shared hosting, the FTP root is the account home. Each domain lives under `<domain>/public_html/`. Confirm the directory structure with `ls` before bulk uploading — never assume.

---

### 5. HTML email layout: tables or nothing

**Problem:** Modern CSS (flexbox, grid, custom properties) is stripped or ignored by virtually all major email clients — Outlook uses Word's rendering engine, Apple Mail ignores many properties, Gmail strips `<style>` tags entirely.

**Solution:** Table-based layout with 100% inline styles. Web-safe font stack (`Georgia, 'Times New Roman', serif`). No external images, no web fonts, no JavaScript. Every style attribute is duplicated inline where needed.

**Lesson:** HTML email is a 2003-era technology living inside 2026 applications. Budget double the time you think you need for email templates. Test in [Litmus](https://litmus.com) or [Email on Acid](https://www.emailonacid.com) before shipping to production.

---

### 6. Cron-free cache cleanup via probabilistic execution

**Problem:** Shared hosting rarely allows cron jobs, but uploaded files need to expire after 30 days to prevent unbounded disk usage.

**Solution:** On each upload request, generate a random number between 1 and 20. If it equals 1 (~5% probability), scan `/cache/` and delete any file older than 30 days. Over time — assuming at least a few sends per month — this provides consistent cleanup with zero infrastructure overhead.

**Lesson:** For low-frequency maintenance tasks, probabilistic inline execution is a clean alternative to cron. The cleanup doesn't need to run on every request, or even on a precise schedule — "eventually" is fine for a cache.

---

### 7. Static application salt is fine for single-tenant tools

**Problem:** Most encryption tutorials insist on per-record random salts. For a single-user tool where the salt would need to be stored in plaintext anyway, a per-record salt adds complexity without meaningful security gain.

**Solution:** Use a static application salt baked into the source code. The PIN provides the entropy variation between deployments (different users deploying this tool use different PINs). 100,000 PBKDF2 iterations still make brute-force expensive.

**Lesson:** Match your security model to your threat model. A static salt is an acceptable trade-off for a single-tenant personal tool. It would be wrong for a multi-user SaaS product storing hundreds of keys.

---

## Installation

See [INSTALL.md](INSTALL.md) for the full setup guide.

**Quick start:**
```bash
# 1. Upload all files to your web root via FTP
#    Target: <domain>/public_html/ on SiteGround / cPanel hosts

# 2. Make /cache/ writable
chmod 755 cache/

# 3. Open the page → click ⚙ → enter your Resend API key + a PIN
#    FROM address must be on a Resend-verified domain

# 4. Done — send your first message
```

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full version history.

---

## License

MIT — see [LICENSE](LICENSE)

---

*Built by [Paul Fleury](https://paulfleury.com)*
