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
<link rel="icon" href="<?= e(app_logo_url()) ?>">
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
<?= theme_style_tag() ?>
</head>
<body>
<div class="login-wrap">
  <div class="card login-card">
    <img class="logo" src="<?= e(app_logo_url()) ?>" alt="<?= e($cfg['app_name']) ?> logo">
    <h2><?= e($cfg['app_name']) ?></h2>
    <p class="muted tagline"><?= e($cfg['app_tagline'] ?? 'sign in to continue') ?></p>

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
      <md-outlined-text-field label="Password" name="password" type="password" required autocomplete="new-password">
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
  const form = document.querySelector('form');
  // Submit on button click
  document.querySelector('md-filled-button[type=submit]')
    ?.addEventListener('click', () => form.requestSubmit());
  // Submit on Enter key inside Material text fields
  form.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); form.requestSubmit(); }
  });
</script>
</body>
</html>
