<?php
date_default_timezone_set('Europe/Vienna');
require_once __DIR__ . '/../lib/common.php';

function activate_login_common(callable $selector, callable $verifier): array {
    $sessionsPath = DATA_DIR . '/sessions.json';
    $now = new DateTimeImmutable('now');

    $res = json_store_with_lock($sessionsPath, function($current) use ($selector, $verifier, $now) {
        if (!is_array($current)) $current = ['sessions' => []];
        if (!isset($current['sessions']) || !is_array($current['sessions'])) $current['sessions'] = [];

        $idx = $selector($current['sessions']);
        if ($idx === null) return ['data' => $current, 'ok' => false, 'error' => 'Session nicht gefunden'];

        $s = $current['sessions'][$idx];

        if (!empty($s['revoked_at'])) return ['data' => $current, 'ok' => false, 'error' => 'Session widerrufen'];
        if (!empty($s['activated_at'])) return ['data' => $current, 'ok' => true, 'already' => true, 'session' => $s];

        $validTo = parse_rfc3339($s['valid_to'] ?? '');
        if (!$validTo || $validTo < $now) return ['data' => $current, 'ok' => false, 'error' => 'Code abgelaufen'];

        // Enforce "only the session in which login was requested"
        $reqSid = $s['requested_session_id'] ?? '';
        if ($reqSid === '' || $reqSid !== session_id()) {
            return ['data' => $current, 'ok' => false, 'error' => 'Falsche Browser-Session (bitte im ursprünglichen Tab aktivieren)'];
        }

        $verOk = $verifier($s);
        if (!$verOk['ok']) return ['data' => $current, 'ok' => false, 'error' => $verOk['error'] ?? 'invalid'];

        $s['activated_at'] = $now->format(DateTime::RFC3339);
        $current['sessions'][$idx] = $s;

        return ['data' => $current, 'ok' => true, 'session' => $s];
    });

    if (!$res['ok']) return ['ok' => false, 'error' => $res['error']];
    $r = $res['result'];
    if (empty($r['ok'])) return ['ok' => false, 'error' => $r['error'] ?? 'unknown'];

    $s = $r['session'] ?? null;
    if (!$s) return ['ok' => false, 'error' => 'No session data'];

    $_SESSION['auth'] = ['email' => $s['email'], 'record_id' => $s['id']];
    audit_log('login_activated', ['email' => $s['email']]);

    return ['ok' => true];
}

function activate_login_by_code(string $code): array {
    $code = trim($code);
    if (!preg_match('/^\d{8}$/', $code)) return ['ok' => false, 'error' => 'Code ungültig'];

    $sessionsPath = DATA_DIR . '/sessions.json';
    $store = json_store_read($sessionsPath, ['sessions' => []]);
    $sessions = $store['sessions'] ?? [];

    // Find newest pending request bound to THIS PHP session_id
    $sid = session_id();
    $bestId = null;
    $bestTs = '';

    foreach ($sessions as $s) {
        if (($s['requested_session_id'] ?? '') !== $sid) continue;
        if (!empty($s['revoked_at']) || !empty($s['activated_at'])) continue;
        $ts = strval($s['created_at'] ?? '');
        if ($bestId === null || strcmp($ts, $bestTs) > 0) {
            $bestId = strval($s['id'] ?? '');
            $bestTs = $ts;
        }
    }

    if ($bestId === null || $bestId === '') return ['ok' => false, 'error' => 'Keine offene Login-Anfrage in diesem Tab'];

    return activate_login_common(
        function($all) use ($bestId) {
            foreach ($all as $i => $s) {
                if (($s['id'] ?? '') === $bestId) return $i;
            }
            return null;
        },
        function($s) use ($code) {
            if (!password_verify($code, $s['code_hash'] ?? '')) {
                return ['ok' => false, 'error' => 'Code falsch'];
            }
            return ['ok' => true];
        }
    );
}

function activate_login_by_link(string $rid, string $token): array {
    $rid = trim($rid);
    $token = trim($token);
    if ($rid === '' || $token === '') return ['ok' => false, 'error' => 'Parameter fehlen'];

    return activate_login_common(
        function($all) use ($rid) {
            foreach ($all as $i => $s) {
                if (($s['id'] ?? '') === $rid) return $i;
            }
            return null;
        },
        function($s) use ($token) {
            if (!password_verify($token, $s['link_token_hash'] ?? '')) {
                return ['ok' => false, 'error' => 'Link ungültig'];
            }
            return ['ok' => true];
        }
    );
}
