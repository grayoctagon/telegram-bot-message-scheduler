<?php
date_default_timezone_set('Europe/Vienna');
$page = 'user';
require_once __DIR__ . '/partials/header.php';
$email = auth_current_email();
?>
<main class="main">
  <section class="card">
    <h2>User</h2>
    <p><strong><?php echo htmlspecialchars($email); ?></strong></p>

    <button id="btnLogoutAll" class="btn danger">überall abmelden</button>
    <div id="userStatus" class="muted"></div>
  </section>
</main>
<script>
  window.PAGE = 'user';
</script>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
