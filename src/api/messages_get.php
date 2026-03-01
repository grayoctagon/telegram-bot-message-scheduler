<?php
date_default_timezone_set('Europe/Vienna');
require_once __DIR__ . '/../lib/common.php';
auth_require_login();

$path = DATA_DIR . '/msgs.json';
$store = json_store_read($path, ['messages' => []]);
$msgs = $store['messages'] ?? [];
usort($msgs, function($a, $b) {
    return strcmp($a['send_at'] ?? '', $b['send_at'] ?? '');
});
json_response(['ok' => true, 'messages' => $msgs]);
