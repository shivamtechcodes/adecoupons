<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

session_start();
require_post();

$data = request_body();
$channel = ($data['channel'] ?? '') === 'email' ? 'email' : (($data['channel'] ?? '') === 'sms' ? 'sms' : '');
$destination = clean_destination((string)($data['destination'] ?? ''));
$otp = preg_replace('/\D/', '', (string)($data['otp'] ?? ''));

if ($channel === '') {
    json_response(['ok' => false, 'message' => 'Invalid OTP channel.'], 422);
}
if ($channel === 'email') {
    $destination = strtolower($destination);
    if (!valid_email($destination)) {
        json_response(['ok' => false, 'message' => 'Invalid email address.'], 422);
    }
} else {
    if (!valid_indian_mobile($destination)) {
        json_response(['ok' => false, 'message' => 'Invalid mobile number.'], 422);
    }
}
if (!preg_match('/^\d{6}$/', $otp)) {
    json_response(['ok' => false, 'message' => 'Enter the complete 6-digit OTP.'], 422);
}

$record = read_otp_record($channel, $destination);

if (!$record) {
    json_response(['ok' => false, 'message' => 'No active OTP found. Request a new OTP.'], 400);
}

if (time() > (int)($record['expires_at'] ?? 0)) {
    @unlink(store_file($channel, $destination));
    json_response(['ok' => false, 'message' => 'OTP expired. Request a new OTP.'], 400);
}

if ((int)($record['attempts'] ?? 0) >= MAX_VERIFY_ATTEMPTS) {
    @unlink(store_file($channel, $destination));
    json_response(['ok' => false, 'message' => 'Too many incorrect attempts. Request a new OTP.'], 429);
}

if (!password_verify($otp, (string)($record['otp_hash'] ?? ''))) {
    $record['attempts'] = ((int)($record['attempts'] ?? 0)) + 1;
    write_otp_record($channel, $destination, $record);
    $remaining = max(0, MAX_VERIFY_ATTEMPTS - (int)$record['attempts']);
    json_response([
        'ok' => false,
        'message' => 'Incorrect OTP.',
        'attempts_remaining' => $remaining
    ], 400);
}

@unlink(store_file($channel, $destination));

$_SESSION['ade_user'] = [
    'verified' => true,
    'channel' => $channel,
    'destination' => $destination,
    'verified_at' => time()
];

json_response([
    'ok' => true,
    'message' => 'OTP verified successfully.',
    'authenticated' => true
]);
