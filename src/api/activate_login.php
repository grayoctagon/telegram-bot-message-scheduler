<?php
date_default_timezone_set('Europe/Vienna');
require_once __DIR__ . '/../lib/common.php';
require_once __DIR__ . '/activate_login_internal.php';

$data = require_post_json();
$code = $data['code'] ?? '';
$res = activate_login_by_code($code);
if (!$res['ok']) json_response(['ok' => false, 'error' => $res['error'] ?? 'unknown'], 400);
json_response(['ok' => true]);
