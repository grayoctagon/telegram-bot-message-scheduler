<?php
date_default_timezone_set('Europe/Vienna');

function auth_is_logged_in(): bool {
    if (!isset($_SESSION['auth']) || !is_array($_SESSION['auth'])) return false;
    $rid = $_SESSION['auth']['record_id'] ?? null;
    $email = $_SESSION['auth']['email'] ?? null;
    if (!$rid || !$email) return false;

    $sessionsPath = DATA_DIR . '/sessions.json';
    $store = json_store_read($sessionsPath, ['sessions' => []]);
    $sessions = $store['sessions'] ?? [];
    foreach ($sessions as $s) {
        if (($s['id'] ?? '') === $rid) {
            if (($s['email'] ?? '') !== $email) return false;
            if (!empty($s['revoked_at'])) return false;
            $validTo = $s['valid_to'] ?? null;
            if (!$validTo) return false;
            $dt = parse_rfc3339($validTo);
            if (!$dt) return false;
            if ($dt < new DateTimeImmutable('now')) return false;
            if (empty($s['activated_at'])) return false;
            return true;
        }
    }
    return false;
}

function auth_require_login(): void {
    if (!auth_is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function auth_current_email(): string {
    return $_SESSION['auth']['email'] ?? '';
}

function auth_logout_current(): void {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

function auth_logout_everywhere(string $email): array {
    $sessionsPath = DATA_DIR . '/sessions.json';
    $now = now_rfc3339();

    $res = json_store_with_lock($sessionsPath, function($current) use ($email, $now) {
        if (!is_array($current)) $current = ['sessions' => []];
        if (!isset($current['sessions']) || !is_array($current['sessions'])) $current['sessions'] = [];
        $count = 0;
        foreach ($current['sessions'] as &$s) {
            if (($s['email'] ?? '') === $email && empty($s['revoked_at'])) {
                $s['revoked_at'] = $now;
                $count++;
            }
        }
        unset($s);
        return ['data' => $current, 'count' => $count];
    });

    if (!$res['ok']) return ['ok' => false, 'error' => $res['error']];

    return ['ok' => true, 'revoked' => $res['result']['count'] ?? 0];
}
