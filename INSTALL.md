# Installation

## Requirements

- PHP **8.1+** with `openssl` and `curl` extensions enabled
- Any web server with PHP support (Apache, Nginx, LiteSpeed)
- A [Resend](https://resend.com) account with a verified sending domain
- FTP or SSH access to your hosting

> Works out of the box on SiteGround, cPanel hosts, and any standard shared hosting environment.

---

## 1. Deploy the files

Upload the entire repository to your web root (or a subdirectory):

```
index.html
send.php
upload.php
settings.php
cache/         ← must be writable by the web server
cache/index.html
```

**FTP example:**
```bash
# Using lftp
lftp -u ftp2@paulfleury.com ftp.paulfleury.com
> mirror -R ./to /public_html
```

**File permissions:**
```bash
chmod 755 cache/
chmod 644 index.html send.php upload.php settings.php
```

The `config/` directory is created automatically by `settings.php` on first save. If it fails, create it manually and make it writable:

```bash
mkdir config && chmod 755 config
```

---

## 2. Configure Resend

1. Go to [resend.com](https://resend.com) and create a free account.
2. Add and verify your sending domain (e.g. `paulfleury.com`).
3. Create an API key under **API Keys** → **Create API Key**.
4. Open `https://to.paulfleury.com` in your browser.
5. Click the **⚙ cog icon** in the top-right corner.
6. Enter your Resend API key and choose a PIN (default is `1234`).
7. Click **Save & Enable**.

The key is encrypted immediately — it is never stored in plaintext anywhere on disk.

---

## 3. Verify sending addresses

In `send.php`, confirm these constants match your setup:

```php
define('TO_EMAIL',   'hello@paulfleury.com');  // Where messages are delivered
define('FROM_EMAIL', 'to@paulfleury.com');     // Must be on your verified domain
define('FROM_NAME',  'Message to Paul');
```

If you change `FROM_EMAIL`, make sure the domain is verified in Resend.

---

## 4. Test the setup

1. Open the page, click **Start**, write a short message.
2. Press `⌘↵` (Mac) or `Ctrl+↵` (Windows) to open the send modal.
3. Enter a name and click **Send email to Paul**.
4. Check `hello@paulfleury.com` — the email should arrive within seconds.

If you get an error, check:
- `config/` directory exists and is writable
- PHP `curl` extension is enabled (most shared hosts enable it by default)
- Your Resend API key is valid and the sending domain is verified
- `FROM_EMAIL` domain matches a verified Resend domain

---

## 5. Updating the PIN or API key

1. Click the **⚙ cog icon**.
2. Enter your current PIN to unlock.
3. Switch between the **API Key** and **Change PIN** tabs as needed.
4. Save.

---

## Optional: protect the config directory

Add a `.htaccess` inside `config/` to block direct HTTP access:

```apache
# config/.htaccess
Order deny,allow
Deny from all
```

This prevents anyone from downloading the encrypted config file directly, even though it's AES-256 encrypted.

---

## Upgrading

Simply re-upload the new files over FTP. The `config/settings.enc.json` is preserved — no re-configuration needed.
