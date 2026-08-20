<?php
/**
 * GET /api/session_status.php
 * Returns whether the current visitor has a verified, logged-in session.
 */

require_once __DIR__ . '/config.php';
ade_start_session();

ade_json_response([
    'logged_in' => !empty($_SESSION['ade_logged_in']),
    'email' => $_SESSION['ade_email'] ?? null,
]);
