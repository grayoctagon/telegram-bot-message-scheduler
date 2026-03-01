<?php
date_default_timezone_set('Europe/Vienna');
require_once __DIR__ . '/../lib/common.php';
auth_require_login();
$cfg = app_config();
json_response(['ok' => true, 'config' => safe_public_config($cfg)]);
