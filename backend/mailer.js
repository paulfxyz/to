/**
 * mailer.js — Email delivery via Resend REST API for howlr.to
 *
 * Two responsibilities:
 *   1. Send magic-link login emails (uses the PLATFORM Resend key, i.e. Paul's key)
 *   2. Forward user messages via the PER-USER Resend key stored in their profile
 *
 * We call Resend directly over HTTP (no SDK) so there are zero extra deps.
 */

const https = require('https');

const RESEND_API = 'https://api.resend.com/emails';

/**
 * Low-level HTTP POST to the Resend emails endpoint.
 * @param {string} apiKey   — Resend API key (platform or per-user)
 * @param {object} payload  — Resend email payload object
 * @returns {Promise<object>} Resend response body
 */
function resendPost(apiKey, payload) {
  return new Promise((resolve, reject) => {
    const body = JSON.stringify(payload);
    const url  = new URL(RESEND_API);
    const req  = https.request({
      hostname: url.hostname,
      path:     url.pathname,
      method:   'POST',
      headers: {
        'Authorization': `Bearer ${apiKey}`,
        'Content-Type':  'application/json',
        'Content-Length': Buffer.byteLength(body),
      },
    }, (res) => {
      let raw = '';
      res.on('data', (chunk) => { raw += chunk; });
      res.on('end', () => {
        try { resolve(JSON.parse(raw)); }
        catch { resolve({ raw }); }
      });
    });
    req.on('error', reject);
    req.write(body);
    req.end();
  });
}

/**
 * Sends a magic-link login email via the platform Resend key.
 * @param {string} toEmail   — recipient email
 * @param {string} magicLink — full login URL
 */
async function sendMagicLink(toEmail, magicLink) {
  const apiKey = process.env.PLATFORM_RESEND_KEY;
  if (!apiKey) throw new Error('PLATFORM_RESEND_KEY not set');

  const html = `
    <div style="font-family:sans-serif;max-width:480px;margin:0 auto;padding:40px 24px">
      <h1 style="font-size:28px;margin-bottom:8px;color:#111">🐺 howlr</h1>
      <p style="color:#555;margin-bottom:32px">Your secure, one-time login link</p>
      <a href="${magicLink}"
         style="display:inline-block;background:#111;color:#fff;padding:14px 28px;
                border-radius:8px;text-decoration:none;font-weight:600;font-size:15px">
        Log in to howlr →
      </a>
      <p style="color:#999;font-size:13px;margin-top:32px">
        This link expires in 15 minutes and can only be used once.<br>
        If you didn't request this, you can safely ignore this email.
      </p>
    </div>`;

  return resendPost(apiKey, {
    from:    process.env.PLATFORM_FROM_EMAIL || 'howlr <hello@up.paulfleury.com>',
    to:      [toEmail],
    subject: 'Your howlr login link',
    html,
  });
}

/**
 * Forwards a user message to the handle owner via THEIR Resend key.
 *
 * @param {object} opts
 * @param {string} opts.resendKey   — decrypted Resend API key
 * @param {string} opts.fromEmail   — verified "from" address on that Resend account
 * @param {string} opts.toEmail     — handle owner's email
 * @param {string} opts.senderContact — freeform contact field (may or may not be an email)
 * @param {string} opts.message     — plaintext message body
 * @param {string[]} [opts.fileUrls]  — public URLs to uploaded files
 * @param {string} [opts.audioUrl]  — public URL to audio recording
 * @param {string} [opts.handle]    — the handle receiving the message
 */
async function forwardMessage(opts) {
  const { resendKey, fromEmail, toEmail, senderContact, message, fileUrls = [], audioUrl, handle } = opts;

  // Build HTML email body
  const filesHtml = fileUrls.length
    ? `<p style="margin-top:24px"><strong>Attached files:</strong></p><ul>${
        fileUrls.map(u => `<li><a href="${u}">${u.split('/').pop()}</a></li>`).join('')
      }</ul>`
    : '';
  const audioHtml = audioUrl
    ? `<p style="margin-top:16px"><strong>Voice recording:</strong><br>
       <a href="${audioUrl}">▶ Listen / Download</a></p>`
    : '';

  const html = `
    <div style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:40px 24px">
      <h2 style="font-size:18px;color:#111;margin-bottom:4px">
        ✉️ New message on howlr.to/${handle || ''}
      </h2>
      <p style="color:#777;font-size:13px;margin-bottom:24px">
        From: <strong>${senderContact}</strong>
      </p>
      <div style="background:#f9f9f9;border-radius:8px;padding:20px 24px;
                  border-left:4px solid #111;white-space:pre-wrap;font-size:15px;
                  color:#222;line-height:1.6">
        ${message.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
      </div>
      ${filesHtml}
      ${audioHtml}
      <p style="color:#bbb;font-size:12px;margin-top:40px">
        Sent via <a href="https://howlr.to" style="color:#bbb">howlr.to</a>
      </p>
    </div>`;

  const payload = {
    from:    fromEmail,
    to:      [toEmail],
    subject: `New message on howlr.to/${handle || ''}`,
    html,
    text:    `New message from ${senderContact}:\n\n${message}${audioUrl ? '\n\nVoice: ' + audioUrl : ''}${fileUrls.length ? '\n\nFiles:\n' + fileUrls.join('\n') : ''}`,
  };

  // Only include reply_to if senderContact looks like an email
  if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(senderContact)) {
    payload.reply_to = [senderContact];
  }

  return resendPost(resendKey, payload);
}

module.exports = { sendMagicLink, forwardMessage };
