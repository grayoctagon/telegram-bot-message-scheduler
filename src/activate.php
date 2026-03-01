<?php
date_default_timezone_set('Europe/Vienna');
require_once __DIR__ . '/lib/common.php';

$rid = $_GET['rid'] ?? '';
$token = $_GET['token'] ?? '';

if ($rid === '' || $token === '') {
    http_response_code(400);
    echo "Missing parameters";
    exit;
}

require_once __DIR__ . '/api/activate_login_internal.php';
// This endpoint will enforce "same session as request".
$res = activate_login_by_link($rid, $token);
if ($res['ok']) {
    header('Location: /index.php');
    exit;
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Aktivierung fehlgeschlagen</title>
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<main class="main">
  <section class="card">
    <h2>Aktivierung fehlgeschlagen</h2>
    <p class="muted"><?php echo htmlspecialchars($res['error'] ?? 'unknown'); ?></p>
    <p class="muted">Bitte im selben Browser/Tab aktivieren, in dem du den Login angefordert hast (oder den Code dort eingeben).</p>
    <a class="btn" href="/login.php">zurück zum Login</a>
  </section>
</main>
</body>
</html>
