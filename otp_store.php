<?php
/**
 * Minimal file-based storage for OTP records, guarded with flock() so
 * concurrent requests don't corrupt the store. Swap this out for a real
 * database table (recommended for anything beyond low traffic) by
 * re-implementing the four functions below with the same signatures.
 *
 * Record shape (keyed by lowercased email address):
 * [
 *   'otp_hash'     => string   // password_hash() of the OTP
 *   'created_at'   => int      // unix timestamp
 *   'expires_at'   => int      // unix timestamp
 *   'last_sent_at' => int      // unix timestamp, used for resend cooldown
 *   'attempts'     => int      // wrong-attempt counter
 * ]
 */

require_once __DIR__ . '/config.php';

function ade_otp_load_all(): array {
    $file = OTP_STORE_FILE;
    if (!file_exists($file)) {
        return [];
    }
    $fp = fopen($file, 'r');
    if (!$fp) {
        return [];
    }
    $data = [];
    if (flock($fp, LOCK_SH)) {
        $contents = stream_get_contents($fp);
        $decoded = json_decode($contents, true);
        $data = is_array($decoded) ? $decoded : [];
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    return $data;
}

function ade_otp_save_all(array $all): void {
    $file = OTP_STORE_FILE;
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $fp = fopen($file, 'c+');
    if (!$fp) {
        throw new RuntimeException('Unable to open OTP store for writing.');
    }
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($all, JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

function ade_otp_get(string $email): ?array {
    $all = ade_otp_load_all();
    $key = strtolower($email);
    return $all[$key] ?? null;
}

function ade_otp_set(string $email, ?array $record): void {
    $all = ade_otp_load_all();
    $key = strtolower($email);
    if ($record === null) {
        unset($all[$key]);
    } else {
        $all[$key] = $record;
    }
    ade_otp_save_all($all);
}

/**
 * Opportunistic cleanup of expired records so the store file doesn't
 * grow forever. Cheap to call on every request given typical traffic;
 * move to a cron job if the site gets large.
 */
function ade_otp_purge_expired(): void {
    $all = ade_otp_load_all();
    $now = time();
    $changed = false;
    foreach ($all as $key => $record) {
        if (!isset($record['expires_at']) || $record['expires_at'] < $now) {
            unset($all[$key]);
            $changed = true;
        }
    }
    if ($changed) {
        ade_otp_save_all($all);
    }
}
