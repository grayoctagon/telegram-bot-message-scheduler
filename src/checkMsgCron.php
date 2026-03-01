<?php
date_default_timezone_set('Europe/Vienna');
require_once __DIR__ . '/lib/common.php';

$cfg = app_config();
$secret = $cfg['cron']['secret'] ?? '';
$allow = false;

if (php_sapi_name() === 'cli') {
    $allow = true;
} else {
    $key = $_GET['key'] ?? '';
    if ($secret !== '' && hash_equals($secret, $key)) $allow = true;
}

if (!$allow) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

$token = $cfg['telegram']['bot_token'] ?? '';
if ($token === '') {
    echo "Missing bot_token\n";
    exit;
}

$path = DATA_DIR . '/msgs.json';
$now = new DateTimeImmutable('now');

$res = json_store_with_lock($path, function($current) use ($now, $token) {
    if (!is_array($current)) $current = ['messages' => []];
    if (!isset($current['messages']) || !is_array($current['messages'])) $current['messages'] = [];

    $sentCount = 0;
    $preCount = 0;
    foreach ($current['messages'] as &$m) {
        $status = $m['status'] ?? 'pending';
        $sendAt = parse_rfc3339(strval($m['send_at'] ?? ''));
        if (!$sendAt) continue;

        // Pre-announce
        $preEnabled = !empty($m['preannounce_enabled']);
        if ($preEnabled) {
            $preStatus = $m['preannounce_status'] ?? 'pending';
            if ($preStatus !== 'sent') {
                $hours = intval($m['preannounce_hours_before'] ?? 0);
                $preChat = strval($m['preannounce_chat_id'] ?? '');
                if ($hours > 0 && $preChat !== '') {
                    $preAt = $sendAt->modify('-' . $hours . ' hours');
                    if ($now >= $preAt) {
                        $txt = "[Ankuendigung] Diese Nachricht geht um " . $sendAt->format(DateTime::RFC3339) . " raus:\n\n" . strval($m['text'] ?? '');
                        $tg = tg_send_message($token, $preChat, $txt);
                        if ($tg['ok']) {
                            $m['preannounce_status'] = 'sent';
                            $m['preannounce_sent_at'] = (new DateTimeImmutable('now'))->format(DateTime::RFC3339);
                            $preCount++;
                        } else {
                            $m['preannounce_status'] = 'failed';
                            $m['preannounce_last_error'] = $tg['error'] ?? 'unknown';
                        }
                    }
                }
            }
        } else {
            $m['preannounce_status'] = 'off';
        }

        // Main send
        if ($status === 'pending' && $now >= $sendAt) {
            $chatId = strval($m['target_chat_id'] ?? '');
            $text = strval($m['text'] ?? '');
            $tg = tg_send_message($token, $chatId, $text);
            if ($tg['ok']) {
                $m['status'] = 'sent';
                $m['sent_at'] = (new DateTimeImmutable('now'))->format(DateTime::RFC3339);
                $m['telegram_message_id'] = $tg['message_id'] ?? null;
                $sentCount++;
            } else {
                $m['status'] = 'failed';
                $m['last_error'] = $tg['error'] ?? 'unknown';
                $m['retry_count'] = intval($m['retry_count'] ?? 0) + 1;
            }
        }
    }
    unset($m);

    return ['data' => $current, 'sentCount' => $sentCount, 'preCount' => $preCount];
});

if (!$res['ok']) {
    echo "Error: " . $res['error'] . "\n";
    exit;
}

$r = $res['result'];
audit_log('cron_run', ['sent' => $r['sentCount'] ?? 0, 'pre_sent' => $r['preCount'] ?? 0]);
echo "OK sent=" . intval($r['sentCount'] ?? 0) . " pre=" . intval($r['preCount'] ?? 0) . "\n";
