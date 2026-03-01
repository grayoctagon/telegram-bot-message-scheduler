<?php
date_default_timezone_set('Europe/Vienna');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

define('APP_BASE', dirname(__DIR__));
define('CONFIG_PATH', APP_BASE . '/config/config.json');
define('DATA_DIR', APP_BASE . '/data');

require_once APP_BASE . '/lib/json_store.php';
require_once APP_BASE . '/lib/auth.php';
require_once APP_BASE . '/lib/audit.php';
require_once APP_BASE . '/lib/telegram.php';
require_once APP_BASE . '/lib/smtp.php';
require_once APP_BASE . '/lib/rate_limit.php';

function app_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    if (!file_exists(CONFIG_PATH)) {
        http_response_code(500);
        echo "Missing config.json";
        exit;
    }
    $raw = file_get_contents(CONFIG_PATH);
    $cfg = json_decode($raw, true);
    if (!is_array($cfg)) {
        http_response_code(500);
        echo "Invalid config.json";
        exit;
    }
    return $cfg;
}

function safe_public_config(array $cfg): array {
    // Only return safe keys for client-side UI.
    $groups = $cfg['telegram']['groups'] ?? [];
    $preGroups = $cfg['telegram']['preannounce_groups'] ?? [];
    return [
        'telegram' => [
            'default_group_id' => $cfg['telegram']['default_group_id'] ?? null,
            'groups' => $groups,
            'preannounce_groups' => $preGroups
        ]
    ];
}

function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function now_rfc3339(): string {
    return (new DateTimeImmutable('now'))->format(DateTime::RFC3339);
}

function parse_rfc3339(string $s): ?DateTimeImmutable {
    try {
        $dt = new DateTimeImmutable($s);
        return $dt;
    } catch (Throwable $e) {
        return null;
    }
}

function require_post_json(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) json_response(['ok' => false, 'error' => 'Invalid JSON'], 400);
    return $data;
}
