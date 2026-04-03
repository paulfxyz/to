# To

**A personal, timed message canvas — with email delivery, voice recording, and file attachments.**

`to.paulfleury.com` is a minimal Notion-inspired writing surface that lets anyone send a direct message to Paul Fleury. The timer only runs while you're actively typing, so every second counted is a second genuinely spent writing. When you're done, the message is sent as a beautifully formatted email via [Resend](https://resend.com), with any voice notes and files hosted in `/cache/` and linked inside the email.

---

## Features

| Feature | Details |
|---|---|
| **Timed writing** | Session timer runs only while `Start` is active; pauses on `Esc` |
| **Voice recording** | In-browser MediaRecorder with live waveform visualiser |
| **File attachments** | Click-or-drag drop zone, up to 50 MB per file / 100 MB total |
| **Email delivery** | Server-side via Resend API — full HTML + plain-text email with download links |
| **Settings modal** | Cog icon in the topbar → stores your Resend API key AES-256 encrypted on disk |
| **PIN protection** | API key is encrypted with a user-chosen PIN (PBKDF2 + AES-256-CBC) |
| **Keyboard shortcuts** | Mac (`⌘↵` to send) and Windows/Linux (`Ctrl+↵` to send) — auto-detected |
| **Success screen** | Confirmation modal with writing stats + "Go to paulfleury.com" button |
| **Serverless-friendly** | Pure PHP — works on any shared host (SiteGround, cPanel, etc.) |
| **Cache auto-cleanup** | Uploaded files older than 30 days are deleted automatically |

---

## Architecture

```
to/
├── index.html       ← Single-page app (HTML + vanilla JS, no build step)
├── send.php         ← Decrypts API key, uploads email via Resend REST API
├── upload.php       ← Handles multipart file & audio uploads → /cache/
├── settings.php     ← Encrypted settings CRUD (AES-256-CBC + PBKDF2)
├── cache/           ← Temporary file storage (auto-cleaned after 30 days)
│   └── index.html   ← Prevents directory listing
└── config/
    └── settings.enc.json  ← AES-256 encrypted config (auto-created)
```

All frontend logic is contained in `index.html` — no bundler, no framework, no CDN dependencies beyond Google Fonts. The PHP layer is minimal: three files that handle upload, send, and settings.

---

## Encryption model

The Resend API key is **never stored in plaintext**. Here's what happens under the hood:

1. You enter your API key and a PIN in the Settings modal.
2. The PIN is run through **PBKDF2-SHA256** (100,000 iterations) with a static salt to derive a 256-bit key.
3. The API key is encrypted with **AES-256-CBC** (random IV per save) and stored in `config/settings.enc.json`.
4. A **HMAC-SHA256** of a known token is stored alongside the ciphertext to allow fast PIN verification without full decryption.
5. On every send, the PIN (default `1234`) is used to decrypt the key server-side — it never touches the client in plaintext.

---

## Keyboard shortcuts

| Action | macOS | Windows / Linux |
|---|---|---|
| Start / Resume | `Space` | `Space` |
| Pause | `Esc` | `Esc` |
| Send message | `⌘ ↵` | `Ctrl ↵` |
| Close modal | `Esc` | `Esc` |

The UI auto-detects the platform via `navigator.platform` / `navigator.userAgent` and shows the correct shortcut label.

---

## Email format

The outgoing email is sent **from** `to@paulfleury.com` **to** `hello@paulfleury.com` and includes:

- Sender name and reply-to contact
- Writing session stats (active time, word count, character count)
- Full message body (HTML + plain-text fallback)
- A direct **Listen / Download** button for voice messages
- Linked file list with filename, size, and MIME type
- Sent via Resend — fully deliverable, no spam folder issues

---

## Tech stack

- **Frontend:** Vanilla HTML5 / CSS3 / JavaScript (ES2020+) — zero dependencies
- **Backend:** PHP 8.1+ — `openssl`, `curl` extensions required
- **Email:** [Resend](https://resend.com) REST API
- **Fonts:** Playfair Display + Lora via Google Fonts
- **Hosting:** Any PHP-capable shared host (SiteGround, cPanel, etc.)

---

## License

MIT — see [LICENSE](LICENSE)

---

*Built by [Paul Fleury](https://paulfleury.com)*
