<?php
date_default_timezone_set('Europe/Vienna');
$page = 'history';
require_once __DIR__ . '/partials/header.php';
?>
<main class="main">
  <section class="card">
    <h2>Verlauf</h2>
    <div id="historyLoading" class="muted">lädt</div>
    <div id="historyList"></div>
  </section>
</main>
<script>
  window.PAGE = 'history';
</script>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
