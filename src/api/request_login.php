<?php
date_default_timezone_set('Europe/Vienna');
require_once __DIR__ . '/../lib/common.php';

$data = require_post_json();
$email = trim(strtolower($data['email'] ?? ''));
if ($email === '') json_response(['ok' => false, 'error' => 'E-Mail fehlt'], 400);

$cfg = app_config();
$allowed = $cfg['auth']['allowed_emails'] ?? [];
if (!in_array($email, $allowed, true)) {
    // Same response to avoid email enumeration.
    json_response(['ok' => true]);
}

// Rate limit: max once per 5 minutes, max 5 per day
$rl = rate_limit_check_and_record($email, 300, 5);
if (!$rl['ok']) json_response(['ok' => false, 'error' => $rl['error']], 500);
if (!$rl['allowed']) {
    $msg = ($rl['reason'] ?? '') === 'too_soon'
        ? ('Bitte warten (' . intval($rl['wait_seconds'] ?? 0) . 's).')
        : 'Tageslimit erreicht.';
    json_response(['ok' => false, 'error' => $msg], 429);
}

$code = strval(random_int(10000000, 99999999));
$rid = bin2hex(random_bytes(16));
$token = bin2hex(random_bytes(24));

$now = new DateTimeImmutable('now');
$validTo = $now->modify('+2 hours')->format(DateTime::RFC3339);

$entry = [
    'id' => $rid,
    'email' => $email,
    'code_hash' => password_hash($code, PASSWORD_DEFAULT),
    'link_token_hash' => password_hash($token, PASSWORD_DEFAULT),
    'requested_session_id' => session_id(),
    'created_at' => $now->format(DateTime::RFC3339),
    'valid_to' => $validTo,
    'activated_at' => null,
    'revoked_at' => null,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'ua' => $_SERVER['HTTP_USER_AGENT'] ?? ''
];

$sessionsPath = DATA_DIR . '/sessions.json';
$res = json_store_with_lock($sessionsPath, function($current) use ($entry) {
    if (!is_array($current)) $current = ['sessions' => []];
    if (!isset($current['sessions']) || !is_array($current['sessions'])) $current['sessions'] = [];
    $current['sessions'][] = $entry;
    return ['data' => $current];
});
if (!$res['ok']) json_response(['ok' => false, 'error' => $res['error']], 500);

// Send email with code and activation link
$host = $cfg['app']['public_base_url'] ?? '';
$link = rtrim($host, '/') . '/activate.php?rid=' . urlencode($rid) . '&token=' . urlencode($token);

$subject = $cfg['auth']['mail_subject'] ?? 'Login Code';
$body = "Dein Login Code: {$code}\n\nLink (nur im selben Browser/Tab aktivierbar, in dem der Login angefordert wurde):\n{$link}\n\nGueltig bis: {$validTo}\n";

$send = send_email($cfg, $email, $subject, $body);
if (!$send['ok']) {
    audit_log('login_mail_failed', ['email' => $email, 'error' => $send['error'] ?? '']);
    // Still return ok to user
    json_response(['ok' => true]);
}

audit_log('login_requested', ['email' => $email]);
json_response(['ok' => true]);
