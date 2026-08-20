<?php
/**
 * POST /api/send_otp.php
 * Body (JSON): { "email": "user@example.com" }
 *
 * Validates the email, enforces a resend cooldown, generates a fresh
 * OTP, stores only its hash, and emails the plaintext code to the user.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/otp_store.php';

ade_start_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ade_json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

ade_otp_purge_expired();

$body = ade_read_json_body();
$email = isset($body['email']) ? trim((string) $body['email']) : '';

// --- Server-side email validation -----------------------------------
if ($email === '') {
    ade_json_response(['success' => false, 'field' => 'email', 'message' => 'Email address is required.'], 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    ade_json_response(['success' => false, 'field' => 'email', 'message' => 'Please enter a valid email address.'], 422);
}
if (strlen($email) > 254) {
    ade_json_response(['success' => false, 'field' => 'email', 'message' => 'Email address is too long.'], 422);
}

// --- Resend cooldown ---------------------------------------------------
$existing = ade_otp_get($email);
$now = time();
if ($existing && isset($existing['last_sent_at'])) {
    $elapsed = $now - $existing['last_sent_at'];
    if ($elapsed < OTP_RESEND_COOLDOWN) {
        $wait = OTP_RESEND_COOLDOWN - $elapsed;
        ade_json_response([
            'success' => false,
            'field' => 'cooldown',
            'message' => "Please wait {$wait}s before requesting another OTP.",
            'retry_after' => $wait,
        ], 429);
    }
}

// --- Generate OTP --------------------------------------------------------
try {
    $otp = str_pad((string) random_int(0, (10 ** OTP_LENGTH) - 1), OTP_LENGTH, '0', STR_PAD_LEFT);
} catch (Exception $e) {
    ade_json_response(['success' => false, 'message' => 'Could not generate a secure code. Please try again.'], 500);
}

$record = [
    'otp_hash'     => password_hash($otp, PASSWORD_DEFAULT),
    'created_at'   => $now,
    'expires_at'   => $now + OTP_EXPIRY_SECONDS,
    'last_sent_at' => $now,
    'attempts'     => 0,
];
ade_otp_set($email, $record);

// --- Send the email --------------------------------------------------------
$subject = 'Your ADE login code';
$message = "Your ADE verification code is: {$otp}\n\n"
         . 'This code expires in ' . (int) (OTP_EXPIRY_SECONDS / 60) . " minutes.\n"
         . "If you didn't request this, you can safely ignore this email.";

$headers = [];
$headers[] = 'From: ' . OTP_MAIL_FROM_NAME . ' <' . OTP_MAIL_FROM . '>';
$headers[] = 'Content-Type: text/plain; charset=utf-8';

$sent = @mail($email, $subject, $message, implode("\r\n", $headers));

if (!$sent) {
    // Don't leave a live OTP behind for an email that couldn't be delivered.
    ade_otp_set($email, null);
    ade_json_response([
        'success' => false,
        'message' => 'We could not send the email right now. Please check the address or try again shortly.',
    ], 502);
}

ade_json_response([
    'success' => true,
    'message' => 'A 6-digit code has been sent to your email.',
    'expires_in' => OTP_EXPIRY_SECONDS,
    'resend_cooldown' => OTP_RESEND_COOLDOWN,
]);
