<?php
/**
 * POST /api/verify_otp.php
 * Body (JSON): { "email": "user@example.com", "otp": "123456" }
 *
 * Verifies the submitted code against the stored hash, enforces the
 * expiry window and the max-attempts limit, and starts a logged-in
 * session on success.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/otp_store.php';

ade_start_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ade_json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$body = ade_read_json_body();
$email = isset($body['email']) ? trim((string) $body['email']) : '';
$otp   = isset($body['otp']) ? trim((string) $body['otp']) : '';

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    ade_json_response(['success' => false, 'message' => 'Invalid email address.'], 422);
}
if ($otp === '' || !ctype_digit($otp)) {
    ade_json_response(['success' => false, 'message' => 'Please enter the numeric OTP.'], 422);
}

$record = ade_otp_get($email);

if (!$record) {
    ade_json_response([
        'success' => false,
        'message' => 'No pending OTP for this email. Please request a new one.',
        'code' => 'NOT_FOUND',
    ], 404);
}

$now = time();

if ($now > $record['expires_at']) {
    ade_otp_set($email, null);
    ade_json_response([
        'success' => false,
        'message' => 'This OTP has expired. Please request a new one.',
        'code' => 'EXPIRED',
    ], 410);
}

if ($record['attempts'] >= OTP_MAX_ATTEMPTS) {
    ade_otp_set($email, null);
    ade_json_response([
        'success' => false,
        'message' => 'Too many incorrect attempts. Please request a new OTP.',
        'code' => 'LOCKED',
    ], 429);
}

if (!password_verify($otp, $record['otp_hash'])) {
    $record['attempts'] += 1;
    $remaining = OTP_MAX_ATTEMPTS - $record['attempts'];

    if ($remaining <= 0) {
        ade_otp_set($email, null);
        ade_json_response([
            'success' => false,
            'message' => 'Too many incorrect attempts. Please request a new OTP.',
            'code' => 'LOCKED',
        ], 429);
    }

    ade_otp_set($email, $record);
    ade_json_response([
        'success' => false,
        'message' => "Incorrect OTP. {$remaining} attempt(s) remaining.",
        'code' => 'WRONG_OTP',
        'attempts_remaining' => $remaining,
    ], 401);
}

// --- Success: consume the OTP and start the session ---------------------
ade_otp_set($email, null);

$_SESSION['ade_logged_in'] = true;
$_SESSION['ade_email'] = $email;
$_SESSION['ade_login_at'] = $now;
session_regenerate_id(true);

ade_json_response([
    'success' => true,
    'message' => 'Login successful.',
    'email' => $email,
]);
