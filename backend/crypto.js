/**
 * crypto.js — AES-256-CBC encryption helpers for howlr.to
 *
 * Used to encrypt/decrypt per-user Resend API keys at rest.
 * The server-side encryption secret lives in ENCRYPTION_SECRET env var.
 *
 * Algorithm: AES-256-CBC with PBKDF2-derived key (100,000 iterations, SHA-256).
 * A random 16-byte IV is generated for every encryption and stored alongside
 * the ciphertext as "iv:ciphertext" (both hex-encoded).
 */

const crypto = require('crypto');

const ALGORITHM  = 'aes-256-cbc';
const ITERATIONS = 100_000;
const KEY_LENGTH = 32; // bytes → 256 bits

/**
 * Derives a 256-bit AES key from the server secret + a salt.
 * @param {string} salt — hex-encoded 16-byte salt
 * @returns {Buffer} 32-byte key
 */
function deriveKey(salt) {
  const secret = process.env.ENCRYPTION_SECRET;
  if (!secret) throw new Error('ENCRYPTION_SECRET env var is not set');
  return crypto.pbkdf2Sync(secret, Buffer.from(salt, 'hex'), ITERATIONS, KEY_LENGTH, 'sha256');
}

/**
 * Encrypts a plaintext string.
 * @param {string} plaintext
 * @returns {string} "salt:iv:ciphertext" — all hex-encoded
 */
function encrypt(plaintext) {
  const salt = crypto.randomBytes(16).toString('hex');
  const iv   = crypto.randomBytes(16);
  const key  = deriveKey(salt);
  const cipher = crypto.createCipheriv(ALGORITHM, key, iv);
  const encrypted = Buffer.concat([cipher.update(plaintext, 'utf8'), cipher.final()]);
  return `${salt}:${iv.toString('hex')}:${encrypted.toString('hex')}`;
}

/**
 * Decrypts a string produced by encrypt().
 * @param {string} blob — "salt:iv:ciphertext"
 * @returns {string} plaintext
 */
function decrypt(blob) {
  const [salt, ivHex, cipherHex] = blob.split(':');
  const key       = deriveKey(salt);
  const iv        = Buffer.from(ivHex, 'hex');
  const decipher  = crypto.createDecipheriv(ALGORITHM, key, iv);
  const decrypted = Buffer.concat([decipher.update(Buffer.from(cipherHex, 'hex')), decipher.final()]);
  return decrypted.toString('utf8');
}

module.exports = { encrypt, decrypt };
