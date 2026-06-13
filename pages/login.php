<?php
/** Login screen. */
if (is_logged_in()) {
    redirect('dashboard');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim((string) input('username'));
    $password = (string) input('password');
    if (attempt_login($username, $password)) {
        redirect('dashboard');
    }
    $error = 'Incorrect username or password.';
}

$cfg = config();
$pageTitle = 'Sign in';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · <?= e($cfg['app_name']) ?></title>
<script type="importmap">
{ "imports": { "@material/web/": "https://esm.run/@material/web/" } }
</script>
<script type="module">
  import '@material/web/all.js';
  import {styles as typescaleStyles} from '@material/web/typography/md-typescale-styles.js';
  document.adoptedStyleSheets.push(typescaleStyles.styleSheet);
</script>
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Symbols+Outlined" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="login-wrap">
  <div class="card login-card">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="50" height="50" class="logo" aria-hidden="true">
      <!-- tail feathers -->
      <path d="M74,44 Q88,28 90,40 Q83,46 75,50 Z" fill="#b05a00"/>
      <path d="M75,52 Q92,44 91,57 Q84,59 75,57 Z" fill="#c06810"/>
      <!-- body -->
      <ellipse cx="48" cy="65" rx="28" ry="22" fill="currentColor"/>
      <!-- wing -->
      <ellipse cx="44" cy="68" rx="18" ry="11" fill="#6b2c00"/>
      <!-- neck -->
      <ellipse cx="24" cy="51" rx="10" ry="14" fill="currentColor"/>
      <!-- head -->
      <circle cx="22" cy="34" r="15" fill="currentColor"/>
      <!-- comb -->
      <path d="M15,21 Q17,12 20,20 Q22,12 25,20 Q27,12 30,21" fill="#d63b2f"/>
      <!-- wattle -->
      <ellipse cx="13" cy="41" rx="5" ry="7" fill="#d63b2f"/>
      <!-- beak -->
      <path d="M5,33 L18,30 L18,37 Z" fill="#e8a020"/>
      <!-- eye -->
      <circle cx="24" cy="30" r="4" fill="#1a0800"/>
      <circle cx="23" cy="29" r="1.5" fill="white"/>
      <!-- legs -->
      <rect x="38" y="85" width="6" height="9" rx="3" fill="#e8a020"/>
      <rect x="52" y="85" width="6" height="9" rx="3" fill="#e8a020"/>
      <!-- feet -->
      <path d="M35,94 L47,94 M41,94 L41,98" stroke="#e8a020" stroke-width="3" stroke-linecap="round"/>
      <path d="M49,94 L61,94 M55,94 L55,98" stroke="#e8a020" stroke-width="3" stroke-linecap="round"/>
    </svg>
    <h2><?= e($cfg['app_name']) ?></h2>
    <p class="muted">Point of Sale &mdash; sign in to continue</p>

    <?php if ($error): ?>
      <div class="flash flash-error" style="justify-content:center">
        <span class="material-symbols-outlined">error</span><span><?= e($error) ?></span>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('login')) ?>" autocomplete="off">
      <?= csrf_field() ?>
      <md-outlined-text-field label="Username" name="username" required autofocus
          value="<?= e(input('username')) ?>" autocomplete="off">
        <span class="material-symbols-outlined" slot="leading-icon">person</span>
      </md-outlined-text-field>
      <md-outlined-text-field label="Password" name="password" type="password" required>
        <span class="material-symbols-outlined" slot="leading-icon">lock</span>
      </md-outlined-text-field>
      <md-filled-button type="submit" has-icon>
        <span class="material-symbols-outlined" slot="icon">login</span>
        Sign in
      </md-filled-button>
    </form>

    <div class="login-hint">
      <strong>Demo logins</strong><br>
      Owner — <code>owner</code> / <code>owner123</code><br>
      Cashier — <code>cashier</code> / <code>cashier123</code>
    </div>
  </div>
</div>
<script>
  // Native form submit from a Material button
  document.querySelector('md-filled-button[type=submit]')
    ?.addEventListener('click', () => document.querySelector('form').requestSubmit());
</script>
</body>
</html>
