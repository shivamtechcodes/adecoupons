<?php
declare(strict_types=1);

/*
 * ADE OTP configuration
 * ----------------------
 * 1. Create an email address on your ADE domain in your hosting panel.
 * 2. Put that address in FROM_EMAIL below.
 * 3. PHP mail() must be enabled by your hosting provider.
 *
 * For production, SMTP via a transactional email provider is recommended.
 */

const FROM_EMAIL = 'no-reply@YOUR-DOMAIN.com';
const FROM_NAME  = 'ADE Coupons';

const OTP_TTL_SECONDS = 300;          // 5 minutes
const RESEND_COOLDOWN_SECONDS = 30;
const MAX_VERIFY_ATTEMPTS = 5;
const MAX_SENDS_PER_HOUR = 5;

function json_response(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function request_body(): array {
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function clean_destination(string $value): string {
    return trim($value);
}

function valid_email(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function valid_indian_mobile(string $phone): bool {
    return preg_match('/^[6-9][0-9]{9}$/', $phone) === 1;
}

function otp_store_dir(): string {
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'otp_store';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function store_file(string $channel, string $destination): string {
    $key = hash('sha256', $channel . '|' . strtolower($destination));
    return otp_store_dir() . DIRECTORY_SEPARATOR . $key . '.json';
}

function read_otp_record(string $channel, string $destination): ?array {
    $file = store_file($channel, $destination);
    if (!is_file($file)) return null;
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function write_otp_record(string $channel, string $destination, array $record): void {
    file_put_contents(
        store_file($channel, $destination),
        json_encode($record, JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function random_otp(): string {
    return (string)random_int(100000, 999999);
}

function rate_limited(?array $record): bool {
    if (!$record) return false;
    $now = time();
    $recent = array_values(array_filter(
        $record['send_times'] ?? [],
        fn($t) => is_int($t) && ($now - $t) < 3600
    ));
    return count($recent) >= MAX_SENDS_PER_HOUR;
}

function send_otp_email(string $email, string $otp): bool {
    if (strpos(FROM_EMAIL, 'YOUR-DOMAIN.com') !== false) {
        return false;
    }

    $subject = 'Your ADE verification OTP';
    $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');

    $message = '<!doctype html><html><body style="font-family:Arial,sans-serif;background:#f7f5fc;padding:24px">'
             . '<div style="max-width:520px;margin:auto;background:#fff;border-radius:16px;padding:28px">'
             . '<h2 style="color:#6c3ce0;margin-top:0">ADE Email Verification</h2>'
             . '<p>Your one-time password is:</p>'
             . '<div style="font-size:32px;font-weight:800;letter-spacing:8px;color:#241a3d;margin:20px 0">'
             . $safeOtp . '</div>'
             . '<p style="color:#777">This OTP expires in 5 minutes. Do not share it with anyone.</p>'
             . '</div></body></html>';

    $headers  = 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-type: text/html; charset=UTF-8' . "\r\n";
    $headers .= 'From: ' . FROM_NAME . ' <' . FROM_EMAIL . '>' . "\r\n";
    $headers .= 'Reply-To: ' . FROM_EMAIL . "\r\n";

    return @mail($email, $subject, $message, $headers);
}

function require_post(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_response(['ok' => false, 'message' => 'POST request required.'], 405);
    }
}
