<?php
date_default_timezone_set('Europe/Vienna');
require_once __DIR__ . '/../lib/common.php';
auth_require_login();
$cfg = app_config();
$publicCfg = safe_public_config($cfg);
$page = $page ?? 'messages';
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Telegram Bot Scheduler (V1)</title>
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="topbar">
  <div class="brand">TG Scheduler</div>
  <nav class="nav">
    <a class="<?php echo $page==='messages'?'active':''; ?>" href="/index.php">Nachrichten</a>
    <a class="<?php echo $page==='history'?'active':''; ?>" href="/history.php">Verlauf</a>
    <a class="<?php echo $page==='user'?'active':''; ?>" href="/user.php">User</a>
  </nav>

  <div class="actions">
    <?php if ($page === 'messages'): ?>
      <label class="chk"><input type="checkbox" id="showOld"> alte Nachrichten anzeigen</label>
      <button id="btnSave" class="btn primary">speichern</button>
      <span id="loading" class="loading hidden">lädt</span>
    <?php endif; ?>
    <a class="btn" href="/logout.php">logout</a>
  </div>
</header>

<script>
  window.PUBLIC_CFG = <?php echo json_encode($publicCfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>
