<?php
date_default_timezone_set('Europe/Vienna');
require_once __DIR__ . '/../lib/common.php';
auth_require_login();

$data = require_post_json();
$text = strval($data['text'] ?? '');
$target = strval($data['target_chat_id'] ?? '');
if (trim($text) === '') json_response(['ok' => false, 'error' => 'Text fehlt'], 400);

$cfg = app_config();
$allowedGroups = $cfg['telegram']['groups'] ?? [];
$allowedGroupIds = [];
foreach ($allowedGroups as $g) {
    if (!empty($g['id'])) $allowedGroupIds[] = strval($g['id']);
}
if ($target === '' || !in_array($target, $allowedGroupIds, true)) {
    json_response(['ok' => false, 'error' => 'target not allowed'], 400);
}

$token = $cfg['telegram']['bot_token'] ?? '';
if ($token === '') json_response(['ok' => false, 'error' => 'bot token missing'], 500);

$sendAt = now_rfc3339();
$now = new DateTimeImmutable('now');

$msg = [
    'id' => bin2hex(random_bytes(8)),
    'text' => $text,
    'send_at' => $sendAt,
    'target_chat_id' => $target,
    'preannounce_enabled' => false,
    'preannounce_hours_before' => 0,
    'preannounce_chat_id' => '',
    'status' => 'pending',
    'sent_at' => null,
    'preannounce_status' => 'off',
    'preannounce_sent_at' => null,
    'created_at' => $sendAt,
    'created_by' => auth_current_email(),
    'updated_at' => $sendAt,
    'updated_by' => auth_current_email()
];

$tg = tg_send_message($token, $target, $text);
if ($tg['ok']) {
    $msg['status'] = 'sent';
    $msg['sent_at'] = now_rfc3339();
    $msg['telegram_message_id'] = $tg['message_id'] ?? null;
} else {
    $msg['status'] = 'failed';
    $msg['last_error'] = $tg['error'] ?? 'unknown';
}

$path = DATA_DIR . '/msgs.json';
$save = json_store_with_lock($path, function($current) use ($msg) {
    if (!is_array($current)) $current = ['messages' => []];
    if (!isset($current['messages']) || !is_array($current['messages'])) $current['messages'] = [];
    $current['messages'][] = $msg;
    return ['data' => $current];
});
if (!$save['ok']) json_response(['ok' => false, 'error' => $save['error']], 500);

audit_log('quick_msg', ['msg' => $msg]);
json_response(['ok' => true, 'status' => $msg['status'], 'error' => $msg['last_error'] ?? null]);
