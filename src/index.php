<?php
date_default_timezone_set('Europe/Vienna');
$page = 'messages';
require_once __DIR__ . '/partials/header.php';
?>
<main class="main">
  <section class="card">
    <h2>Quick Msg</h2>
    <div class="row">
      <select id="quickTarget" class="input"></select>
      <button id="btnQuickSend" class="btn">sofort senden</button>
    </div>
    <textarea id="quickText" class="textarea" rows="3" placeholder="Nachricht (einfacher Text, Emoji per Copy/Paste)"></textarea>
    <div id="quickStatus" class="muted"></div>
  </section>

  <section class="card">
    <div class="row">
      <button id="btnAdd" class="btn">+ neue Nachricht</button>
      <div id="saveStatus" class="muted"></div>
    </div>
    <div id="msgList"></div>
  </section>
</main>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
