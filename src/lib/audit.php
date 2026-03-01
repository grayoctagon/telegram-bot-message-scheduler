<?php
date_default_timezone_set('Europe/Vienna');

function audit_log(string $action, array $payload): void {
    $path = DATA_DIR . '/audit.json';
    $entry = [
        'id' => bin2hex(random_bytes(8)),
        'ts' => now_rfc3339(),
        'user' => auth_current_email(),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'action' => $action,
        'payload' => $payload
    ];

    json_store_with_lock($path, function($current) use ($entry) {
        if (!is_array($current)) $current = ['entries' => []];
        if (!isset($current['entries']) || !is_array($current['entries'])) $current['entries'] = [];
        $current['entries'][] = $entry;
        return ['data' => $current];
    });
}

function audit_get_entries(int $limit = 500): array {
    $path = DATA_DIR . '/audit.json';
    $store = json_store_read($path, ['entries' => []]);
    $entries = $store['entries'] ?? [];
    usort($entries, function($a, $b) {
        return strcmp($b['ts'] ?? '', $a['ts'] ?? '');
    });
    return array_slice($entries, 0, $limit);
}
