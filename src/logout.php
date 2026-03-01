<?php
date_default_timezone_set('Europe/Vienna');
require_once __DIR__ . '/lib/common.php';
auth_logout_current();
header('Location: /login.php');
exit;
