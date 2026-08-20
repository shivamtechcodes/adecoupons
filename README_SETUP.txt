# ADE — Real Email OTP Setup

The previous error happened because the frontend was calling `/api/auth/send-otp`, but no backend endpoint existed. A browser-opened HTML file (`content://...`) also cannot send real email OTPs by itself.

## Folder structure

Upload these to the same hosting folder:

- `index.html`
- `api/config.php`
- `api/send-otp.php`
- `api/verify-otp.php`

## Hostinger setup

1. Upload `index.html` to `public_html`.
2. Create `public_html/api/`.
3. Upload the three PHP files into `public_html/api/`.
4. In Hostinger, create an email address such as `no-reply@yourdomain.com`.
5. Open `api/config.php`.
6. Change:
   `FROM_EMAIL = 'no-reply@YOUR-DOMAIN.com';`
   to your real domain email.
7. Open your ADE website through `https://yourdomain.com/`.
   Do NOT open the HTML through `content://...` or a local file viewer.
8. Test the Email → Send OTP flow.

## Important

This package sends email OTP through PHP `mail()`. If your hosting does not allow PHP mail, use SMTP/PHPMailer instead.

Mobile OTP is intentionally NOT faked. The included endpoint returns a clear configuration error until an SMS provider such as Twilio/MSG91/etc. is connected.

The OTP is generated and verified server-side, stored as a password hash, expires after 5 minutes, has a resend cooldown, and limits incorrect attempts.

Before going live, also add:
- a real user database
- HTTPS
- CSRF protection for authenticated account-changing actions
- a transactional email/SMS provider
- payment gateway integration for ADE membership
