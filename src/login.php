<?php
date_default_timezone_set('Europe/Vienna');
require_once __DIR__ . '/lib/common.php';
if (auth_is_logged_in()) {
    header('Location: index.php');
    exit;
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login (TG Scheduler)</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main class="main">
  <section class="card">
    <h2>Login</h2>
    <div class="muted">E-Mail eingeben, dann kommt ein 8-stelliger Code (und ein Link) per Mail.</div>

    <div class="row">
      <input id="email" class="input" type="email" placeholder="name@domain.tld">
      <button id="btnRequest" class="btn primary">Code senden</button>
    </div>

    <div class="row">
      <input id="code" class="input" inputmode="numeric" pattern="[0-9]*" placeholder="8-stelliger Code">
      <button id="btnActivate" class="btn">aktivieren</button>
    </div>

    <div id="loginStatus" class="muted"></div>
  </section>
</main>

<script>
async function postJson(url, obj) {
  const r = await fetch(url, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(obj)});
  return await r.json();
}
document.getElementById('btnRequest').addEventListener('click', async () => {
  const email = document.getElementById('email').value.trim();
  document.getElementById('loginStatus').textContent = 'lädt';
  const res = await postJson('api/request_login.php', {email});
  document.getElementById('loginStatus').textContent = res.ok ? 'Mail gesendet (wenn erlaubt).' : ('Fehler: ' + (res.error || 'unknown'));
});

document.getElementById('btnActivate').addEventListener('click', async () => {
  const code = document.getElementById('code').value.trim();
  document.getElementById('loginStatus').textContent = 'lädt';
  const res = await postJson('api/activate_login.php', {code});
  if (res.ok) {
    window.location.href = 'index.php';
    return;
  }
  document.getElementById('loginStatus').textContent = 'Fehler: ' + (res.error || 'unknown');
});
</script>
</body>
</html>
