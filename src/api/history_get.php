<?php
date_default_timezone_set('Europe/Vienna');
require_once __DIR__ . '/../lib/common.php';
auth_require_login();
$entries = audit_get_entries(500);
json_response(['ok' => true, 'entries' => $entries]);
