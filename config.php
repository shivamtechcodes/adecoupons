<?php
/**
 * ADE Coupon Site — OTP Auth Configuration
 * -----------------------------------------
 * Edit the values below for your environment before going live.
 */

// How long an OTP stays valid (seconds)
define('OTP_EXPIRY_SECONDS', 5 * 60);

// Minimum gap between two OTP requests for the same email (seconds)
define('OTP_RESEND_COOLDOWN', 30);

// How many wrong OTP attempts are allowed before the code is invalidated
define('OTP_MAX_ATTEMPTS', 5);

// Number of digits in the OTP
define('OTP_LENGTH', 6);

// "From" address used when sending the OTP email.
// Your mail server / hosting must be configured to send as this domain,
// otherwise PHP's mail() will silently fail or land in spam.
define('OTP_MAIL_FROM', 'no-reply@yourdomain.com');
define('OTP_MAIL_FROM_NAME', 'ADE Coupons');

// Where OTP records are persisted. This file MUST NOT be web-accessible —
// keep it outside your public webroot in production, or protect it with
// a .htaccess / server rule that blocks direct requests.
define('OTP_STORE_FILE', __DIR__ . '/../storage/otp_store.json');

// Simple in-memory session is used to mark a visitor as "logged in" once
// their OTP is verified. Adjust session name/lifetime as needed.
define('OTP_SESSION_NAME', 'ade_session');

/**
 * Basic session bootstrap. Call this from any endpoint that needs to
 * read or write the logged-in state.
 */
function ade_start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(OTP_SESSION_NAME);
        session_start();
    }
}

/**
 * Send a JSON response and stop execution.
 */
function ade_json_response(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

/**
 * Read the raw JSON body of the current request as an associative array.
 */
function ade_read_json_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
