<?php
date_default_timezone_set('Europe/Vienna');
require_once __DIR__ . '/../lib/common.php';
auth_require_login();

$email = auth_current_email();
$res = auth_logout_everywhere($email);
if (!$res['ok']) json_response(['ok' => false, 'error' => $res['error']], 500);

auth_logout_current();
json_response(['ok' => true, 'revoked' => $res['revoked'] ?? 0]);
