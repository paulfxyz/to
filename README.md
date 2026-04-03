# 📬 _to_

[![Version](https://img.shields.io/badge/version-1.1.0-000000?style=flat-square)](https://github.com/paulfxyz/to/releases/tag/v1.1.0)
[![License](https://img.shields.io/badge/license-MIT-000000?style=flat-square)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.1+-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![Resend](https://img.shields.io/badge/email-Resend-000000?style=flat-square)](https://resend.com)
[![Live](https://img.shields.io/badge/live-to.paulfleury.com-000000?style=flat-square)](https://to.paulfleury.com)
[![No dependencies](https://img.shields.io/badge/dependencies-none-brightgreen?style=flat-square)](package.json)

**A personal, timed message canvas — with email delivery, voice recording, and file attachments.**

`to.paulfleury.com` is a minimal Notion-inspired writing surface that lets anyone send a direct message to Paul Fleury. The timer only runs while you're actively typing, so every second counted is a second genuinely spent writing. When you're done, the message is sent as a beautifully formatted email via [Resend](https://resend.com), with any voice notes and files hosted in `/cache/` and linked directly inside the email.

---

## Features

| Feature | Details |
|---|---|
| **Timed writing** | Session timer runs only while `Start` is active; pauses on `Esc` |
| **Voice recording** | In-browser MediaRecorder with live waveform visualiser |
| **File attachments** | Click-or-drag drop zone, up to 50 MB per file / 100 MB total |
| **Email delivery** | Server-side via Resend API — rich HTML + plain-text email with download links |
| **Settings modal** | Cog icon in the topbar → stores your Resend API key AES-256 encrypted on disk |
| **PIN protection** | API key encrypted with a user-chosen PIN (PBKDF2-SHA256 + AES-256-CBC) |
| **Keyboard shortcuts** | Mac (`⌘↵` to send) and Windows/Linux (`Ctrl+↵` to send) — auto-detected |
| **Success screen** | Confirmation modal with writing stats + "Go to paulfleury.com" button |
| **Serverless-friendly** | Pure PHP — works on any shared host (SiteGround, cPanel, etc.) |
| **Cache auto-cleanup** | Uploaded files older than 30 days are deleted automatically |

---

## Architecture

```
to/
├── index.html       ← Single-page app (HTML + vanilla JS, no build step)
├── send.php         ← Decrypts API key, sends email via Resend REST API
├── upload.php       ← Handles multipart file & audio uploads → /cache/
├── settings.php     ← Encrypted settings CRUD (AES-256-CBC + PBKDF2)
├── .htaccess        ← Blocks directory listing + protects /config/
├── cache/           ← Temporary file storage (auto-cleaned after 30 days)
│   └── index.html   ← Prevents directory listing
└── config/
    └── settings.enc.json  ← AES-256 encrypted config (auto-created on first save)
```

All frontend logic lives in a single `index.html` — no bundler, no framework, no CDN dependencies beyond Google Fonts. The PHP layer is intentionally minimal: three focused files handling upload, send, and settings.

---

## Encryption model

The Resend API key is **never stored in plaintext**. Here's exactly what happens:

1. You enter your API key and a PIN in the Settings modal.
2. The PIN is stretched through **PBKDF2-SHA256** (100,000 iterations + static salt) to derive a 256-bit encryption key.
3. The API key is encrypted with **AES-256-CBC** (fresh random IV on every save) and written to `config/settings.enc.json`.
4. A **HMAC-SHA256** of a known sentinel string is stored alongside the ciphertext — this allows fast PIN verification without decrypting the payload.
5. On every send request, the PIN is submitted alongside the message and used server-side to decrypt the key — the plaintext API key never touches the client.

---

## Keyboard shortcuts

| Action | macOS | Windows / Linux |
|---|---|---|
| Start / Resume | `Space` | `Space` |
| Pause | `Esc` | `Esc` |
| Send message | `⌘ ↵` | `Ctrl ↵` |
| Close modal | `Esc` | `Esc` |

The shortcut labels are auto-detected at runtime via `navigator.platform` / `navigator.userAgent` — Mac users see `⌘↵`, everyone else sees `Ctrl+↵`.

---

## Email format

Every outgoing email is sent **from** `to@paulfleury.com` → **to** `hello@paulfleury.com` and includes:

- Sender name and reply-to contact
- Writing session stats (active time, word count, character count)
- Full message body (HTML with `nl2br` + plain-text fallback)
- A **Listen / Download** button for voice messages, linked to `/cache/`
- A linked file list with filename, size, and MIME type
- Delivered via Resend — proper SPF/DKIM, no spam folder

---

## Tech stack

- **Frontend:** Vanilla HTML5 / CSS3 / JavaScript (ES2020+) — zero runtime dependencies
- **Backend:** PHP 8.1+ — requires `openssl` and `curl` extensions
- **Email:** [Resend](https://resend.com) REST API
- **Fonts:** Playfair Display + Lora via Google Fonts
- **Hosting:** Any PHP-capable shared host (SiteGround, cPanel, Nginx + PHP-FPM, etc.)

---

## Installation

See [INSTALL.md](INSTALL.md) for the full setup guide.

**Quick start:**
```bash
# 1. Upload all files to your web root
# 2. Make /cache/ writable
chmod 755 cache/

# 3. Open the page, click the ⚙ cog, enter your Resend API key + a PIN
# 4. Done — email delivery is live
```

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full version history.

---

## License

MIT — see [LICENSE](LICENSE)

---

*Built by [Paul Fleury](https://paulfleury.com)*
