<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

session_start();
require_post();

$data = request_body();
$channel = ($data['channel'] ?? '') === 'email' ? 'email' : (($data['channel'] ?? '') === 'sms' ? 'sms' : '');
$destination = clean_destination((string)($data['destination'] ?? ''));

if ($channel === '') {
    json_response(['ok' => false, 'message' => 'Invalid OTP channel.'], 422);
}

if ($channel === 'email') {
    $destination = strtolower($destination);
    if (!valid_email($destination)) {
        json_response(['ok' => false, 'message' => 'Enter a valid email address.'], 422);
    }
} else {
    if (!valid_indian_mobile($destination)) {
        json_response(['ok' => false, 'message' => 'Enter a valid 10-digit mobile number.'], 422);
    }

    // SMS requires an SMS provider (Twilio/MSG91/etc.). Do not pretend an OTP was sent.
    json_response([
        'ok' => false,
        'message' => 'SMS OTP is not configured yet. Configure an SMS provider before enabling mobile OTP.'
    ], 503);
}

$record = read_otp_record($channel, $destination);
$now = time();

if ($record && !empty($record['last_sent_at']) && ($now - (int)$record['last_sent_at']) < RESEND_COOLDOWN_SECONDS) {
    $wait = RESEND_COOLDOWN_SECONDS - ($now - (int)$record['last_sent_at']);
    json_response(['ok' => false, 'message' => "Please wait {$wait} seconds before requesting another OTP."], 429);
}

if (rate_limited($record)) {
    json_response(['ok' => false, 'message' => 'Too many OTP requests. Please try again later.'], 429);
}

$otp = random_otp();

$record = [
    'otp_hash' => password_hash($otp, PASSWORD_DEFAULT),
    'expires_at' => $now + OTP_TTL_SECONDS,
    'attempts' => 0,
    'last_sent_at' => $now,
    'send_times' => array_merge(
        array_values(array_filter(
            $record['send_times'] ?? [],
            fn($t) => is_int($t) && ($now - $t) < 3600
        )),
        [$now]
    )
];

write_otp_record($channel, $destination, $record);

if (!send_otp_email($destination, $otp)) {
    @unlink(store_file($channel, $destination));
    json_response([
        'ok' => false,
        'message' => 'Email could not be sent. Configure FROM_EMAIL and enable PHP mail/SMTP on your hosting.'
    ], 503);
}

$_SESSION['otp_channel'] = $channel;
$_SESSION['otp_destination'] = $destination;

json_response([
    'ok' => true,
    'message' => 'OTP sent successfully.',
    'expires_in' => OTP_TTL_SECONDS,
    'resend_after' => RESEND_COOLDOWN_SECONDS
]);
