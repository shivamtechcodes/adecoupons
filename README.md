# ADE Coupon Site — Real Email OTP Login

## What changed vs the earlier demo

| Item | Before | Now |
|---|---|---|
| OTP | Hardcoded `1234`, checked in JS | Random 6-digit code, generated server-side |
| Storage | None (fake) | Hashed (`password_hash`) in `storage/otp_store.json`, never stored in plaintext |
| Expiry | None | 5 minutes (`OTP_EXPIRY_SECONDS` in `api/config.php`) |
| Resend | Instant, unlimited | 30-second cooldown per email |
| Attempts | Unlimited | Locked after 5 wrong tries — must request a new code |
| Email validation | Regex only, client-side | Regex client-side **and** `filter_var(FILTER_VALIDATE_EMAIL)` server-side |
| Email delivery | None (fake) | Real email via PHP's `mail()` |
| Mobile OTP | Faked as if it worked | Disabled in the UI with a "coming soon" note, since no SMS provider is wired up |
| Login state | JS variable only | Real PHP session (`$_SESSION`), checked on page load |

## File layout

```
site.html                 ← the site (calls the API below)
api/config.php            ← settings: expiry, cooldown, max attempts, mail "from"
api/otp_store.php         ← file-based OTP storage with locking
api/send_otp.php          ← POST endpoint: validates email, sends OTP
api/verify_otp.php        ← POST endpoint: checks OTP, starts session
api/session_status.php    ← GET endpoint: is this visitor logged in?
api/logout.php            ← POST endpoint: clears the session
storage/                  ← where OTP records are persisted (must be writable)
storage/.htaccess         ← blocks direct web access to storage/ (Apache only)
```

## Requirements

- PHP 7.4+ (uses `password_hash`, `random_int`, typed params — all available since PHP 7)
- A web server that actually runs PHP (Apache + mod_php, PHP-FPM + Nginx, or `php -S` for local testing)
- An outgoing mail path PHP's `mail()` can use (a configured `sendmail`/Postfix on the server, or a mail-relay set up in `php.ini`'s `sendmail_path`)

**Important:** opening `site.html` directly as a `file://` won't work — the `fetch()` calls need a real HTTP server serving the `api/` folder alongside it.

## Setup

1. Upload the whole folder (`site.html`, `api/`, `storage/`) to your PHP host, keeping the relative layout intact.
2. Make `storage/` writable by the web server user: `chmod 750 storage` (and `chown` to your web server user if needed).
3. Edit `api/config.php`:
   - Set `OTP_MAIL_FROM` to an address your server is actually allowed to send as (a mismatched From address is the #1 reason `mail()` emails get dropped or spam-filtered).
   - Adjust `OTP_EXPIRY_SECONDS`, `OTP_RESEND_COOLDOWN`, `OTP_MAX_ATTEMPTS`, `OTP_LENGTH` if you want different values than the defaults (5 min / 30 s / 5 attempts / 6 digits).
4. Confirm `mail()` actually works on your host — many local/dev environments don't have a mail transport configured. Test with a throwaway script:
   ```php
   <?php var_dump(mail('you@yourdomain.com', 'test', 'hello')); ?>
   ```
   If this returns `false` or the email never arrives, fix your server's mail setup (or switch `send_otp.php` to use an API-based provider like SendGrid/Mailgun/Postmark/PHPMailer with SMTP — swapping the one `mail()` call is all that's needed).
5. For production, move `storage/` **outside** the public webroot if your hosting layout allows it, and update `OTP_STORE_FILE` in `config.php` to point at the new path. The included `.htaccess` is a fallback for Apache setups where that isn't possible — it does nothing on Nginx, so add an equivalent `location` block there if you're on Nginx.
6. Serve over HTTPS in production — OTPs and session cookies should never travel over plain HTTP.

## Local testing (no real hosting yet)

```bash
cd /path/to/this/folder
php -S localhost:8000
```
Then open `http://localhost:8000/site.html`. Note: `mail()` typically won't actually deliver anything from a local dev machine unless you've set up a local mail relay (e.g. `mailhog`, `mailtrap`, or a configured `sendmail`) — you'll see the request succeed but no email will land unless mail delivery itself is configured.

## Mobile (SMS) OTP

The "Continue with Mobile Number" option is intentionally disabled in the UI rather than faked. To enable it for real:
1. Sign up with an SMS API provider (Twilio, MSG91, AWS SNS, etc.).
2. Duplicate `send_otp.php` / `verify_otp.php` as `send_otp_sms.php` / `verify_otp_sms.php`, keyed by phone number instead of email, calling the provider's API instead of `mail()`.
3. Re-enable the mobile button in `site.html` and point it at the new endpoints.

## Notes on the storage backend

The included storage (`otp_store.php`) is a single JSON file guarded with `flock()` — fine for low-to-moderate traffic and easy to inspect while testing. If you expect meaningful concurrent traffic, swap it for a real database table (e.g. MySQL) with a unique index on email and the same fields (`otp_hash`, `expires_at`, `attempts`, `last_sent_at`); the four functions in `otp_store.php` are the only thing that needs to change — nothing in `send_otp.php` or `verify_otp.php` needs to know the difference.

## Disclosure

These PHP files could not be executed in the environment that produced them (no PHP interpreter, no network access to install one). They were written carefully and checked for balanced braces/parens, but they have **not** been run end-to-end. Please test the full flow (send → receive email → verify → session persists) on your actual server before relying on it in production.
