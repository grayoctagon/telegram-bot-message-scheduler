<?php
date_default_timezone_set('Europe/Vienna');
require_once __DIR__ . '/../lib/common.php';
auth_require_login();

$data = require_post_json();
$incoming = $data['messages'] ?? null;
if (!is_array($incoming)) json_response(['ok' => false, 'error' => 'messages missing'], 400);

$cfg = app_config();
$allowedGroups = $cfg['telegram']['groups'] ?? [];
$allowedGroupIds = [];
foreach ($allowedGroups as $g) {
    if (!empty($g['id'])) $allowedGroupIds[] = strval($g['id']);
}
$preGroups = $cfg['telegram']['preannounce_groups'] ?? [];
$allowedPreIds = [];
foreach ($preGroups as $g) {
    if (!empty($g['id'])) $allowedPreIds[] = strval($g['id']);
}

$now = new DateTimeImmutable('now');

$path = DATA_DIR . '/msgs.json';
$res = json_store_with_lock($path, function($current) use ($incoming, $allowedGroupIds, $allowedPreIds, $now) {
    if (!is_array($current)) $current = ['messages' => []];
    if (!isset($current['messages']) || !is_array($current['messages'])) $current['messages'] = [];

    $byId = [];
    foreach ($current['messages'] as $m) {
        $id = $m['id'] ?? null;
        if ($id) $byId[$id] = $m;
    }

    $updated = [];
    $changes = [];
    $errors = [];

    foreach ($incoming as $m) {
        $id = $m['id'] ?? '';
        $isNew = ($id === '' || !isset($byId[$id]));
        if ($isNew) {
            $id = bin2hex(random_bytes(8));
        }

        $text = strval($m['text'] ?? '');
        $sendAt = strval($m['send_at'] ?? '');
        $target = strval($m['target_chat_id'] ?? '');
        $preEnabled = !empty($m['preannounce_enabled']);
        $preHours = intval($m['preannounce_hours_before'] ?? 0);
        $preChat = strval($m['preannounce_chat_id'] ?? '');

        $dt = parse_rfc3339($sendAt);
        if (!$dt) {
            $errors[] = ['id' => $id, 'error' => 'send_at invalid'];
            continue;
        }

        // Basic validation
        if ($target === '' || !in_array($target, $allowedGroupIds, true)) {
            $errors[] = ['id' => $id, 'error' => 'target not allowed'];
            continue;
        }
        if ($preEnabled) {
            if ($preHours <= 0) {
                $errors[] = ['id' => $id, 'error' => 'preannounce hours invalid'];
                continue;
            }
            if ($preChat === '' || !in_array($preChat, $allowedPreIds, true)) {
                $errors[] = ['id' => $id, 'error' => 'preannounce chat not allowed'];
                continue;
            }
        } else {
            $preHours = 0;
            $preChat = '';
        }

        // Disallow creating in the past (except minimal grace)
        if ($isNew && $dt < $now->modify('-1 minute')) {
            $errors[] = ['id' => $id, 'error' => 'cannot create in past'];
            continue;
        }

        $existing = $byId[$id] ?? null;

        // Past + not sent cannot be edited
        if ($existing) {
            $exSend = parse_rfc3339(strval($existing['send_at'] ?? ''));
            $wasSent = (($existing['status'] ?? '') === 'sent');
            if ($exSend && $exSend < $now && !$wasSent) {
                // Compare if user attempted to change any editable field
                $attempted = [
                    'text' => $text,
                    'send_at' => $dt->format(DateTime::RFC3339),
                    'target_chat_id' => $target,
                    'preannounce_enabled' => $preEnabled,
                    'preannounce_hours_before' => $preHours,
                    'preannounce_chat_id' => $preChat
                ];
                $currentEditable = [
                    'text' => strval($existing['text'] ?? ''),
                    'send_at' => strval($existing['send_at'] ?? ''),
                    'target_chat_id' => strval($existing['target_chat_id'] ?? ''),
                    'preannounce_enabled' => !empty($existing['preannounce_enabled']),
                    'preannounce_hours_before' => intval($existing['preannounce_hours_before'] ?? 0),
                    'preannounce_chat_id' => strval($existing['preannounce_chat_id'] ?? '')
                ];
                if ($attempted != $currentEditable) {
                    $errors[] = ['id' => $id, 'error' => 'past unsent message is locked'];
                    continue;
                }
            }
        }

        $row = $existing ?? [];
        $before = $existing;

        $row['id'] = $id;
        $row['text'] = $text;
        $row['send_at'] = $dt->format(DateTime::RFC3339);
        $row['target_chat_id'] = $target;
        $row['preannounce_enabled'] = $preEnabled;
        $row['preannounce_hours_before'] = $preHours;
        $row['preannounce_chat_id'] = $preChat;

        if ($isNew) {
            $row['status'] = 'pending';
            $row['sent_at'] = null;
            $row['preannounce_status'] = $preEnabled ? 'pending' : 'off';
            $row['preannounce_sent_at'] = null;
            $row['created_at'] = now_rfc3339();
            $row['created_by'] = auth_current_email();
        }

        $row['updated_at'] = now_rfc3339();
        $row['updated_by'] = auth_current_email();

        $updated[$id] = $row;

        if ($before === null) {
            $changes[] = ['type' => 'create', 'id' => $id, 'after' => $row];
        } else {
            // store before/after if something changed
            if (json_encode($before) !== json_encode($row)) {
                $changes[] = ['type' => 'update', 'id' => $id, 'before' => $before, 'after' => $row];
            }
        }
    }

    if (count($errors) > 0) {
        return ['data' => $current, 'ok' => false, 'errors' => $errors];
    }

    // Merge back, preserving messages that were not present in incoming
    $merged = [];
    foreach ($current['messages'] as $m) {
        $id = $m['id'] ?? '';
        if ($id !== '' && isset($updated[$id])) {
            $merged[] = $updated[$id];
            unset($updated[$id]);
        } else {
            $merged[] = $m;
        }
    }
    foreach ($updated as $m) $merged[] = $m;

    usort($merged, function($a, $b) {
        return strcmp($a['send_at'] ?? '', $b['send_at'] ?? '');
    });

    $current['messages'] = $merged;
    return ['data' => $current, 'ok' => true, 'changes' => $changes];
});

if (!$res['ok']) json_response(['ok' => false, 'error' => $res['error']], 500);
$r = $res['result'];
if (empty($r['ok'])) {
    json_response(['ok' => false, 'error' => 'validation', 'errors' => $r['errors'] ?? []], 400);
}

$changes = $r['changes'] ?? [];
if (count($changes) > 0) audit_log('messages_saved', ['changes' => $changes]);

json_response(['ok' => true, 'changes' => count($changes)]);
