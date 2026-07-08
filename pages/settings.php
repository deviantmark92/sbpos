<?php
/** Settings (Owner only): app branding — change the logo or reset to the default. */
require_owner();
if (!empty($GLOBALS['__forbidden'])) { require __DIR__ . '/_forbidden.php'; return; }

$cfg = config();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $do = (string) input('do');

    if ($do === 'reset_logo') {
        foreach (glob($cfg['upload_dir'] . '/logo.*') ?: [] as $f) {
            @unlink($f);
        }
        flash('Logo reset to the default ' . $cfg['app_name'] . ' logo.');
        redirect('settings');
    }

    if ($do === 'theme') {
        $choice = (string) input('theme');
        if (!isset(color_themes()[$choice])) {
            flash('That color scheme is not available.', 'error');
        } elseif (save_app_setting('theme', $choice)) {
            flash('Color scheme updated to ' . color_themes()[$choice]['label'] . '.');
        } else {
            flash('The color scheme could not be saved. Is the uploads folder writable?', 'error');
        }
        redirect('settings');
    }

    // Upload a custom logo (replaces any previous one)
    $errors = [];
    if (empty($_FILES['logo']['name']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Please choose an image file to upload.';
    } else {
        $maxBytes = $cfg['max_upload_mb'] * 1024 * 1024;
        $allowed  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $mime = mime_content_type($_FILES['logo']['tmp_name']);
        if (!isset($allowed[$mime])) {
            $errors[] = 'Logo must be a JPG, PNG, WEBP, or GIF image.';
        } elseif ($_FILES['logo']['size'] > $maxBytes) {
            $errors[] = 'Logo is larger than ' . $cfg['max_upload_mb'] . ' MB.';
        } else {
            if (!is_dir($cfg['upload_dir'])) { @mkdir($cfg['upload_dir'], 0775, true); }
            if (!is_writable($cfg['upload_dir'])) {
                $errors[] = 'The uploads folder is not writable by the web server.';
            } else {
                foreach (glob($cfg['upload_dir'] . '/logo.*') ?: [] as $f) {
                    @unlink($f);
                }
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $cfg['upload_dir'] . '/logo.' . $allowed[$mime])) {
                    flash('Logo updated.');
                } else {
                    $errors[] = 'The logo could not be saved. Please try again.';
                }
            }
        }
    }
    if ($errors) {
        flash(implode(' ', $errors), 'error');
    }
    redirect('settings');
}

$isCustom  = app_logo_file() !== null;
$themes    = color_themes();
$curTheme  = app_theme_key();

$activePage = 'settings';
$pageTitle  = 'Settings';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head"><h2>Settings</h2></div>

<div class="card" style="max-width:560px">
  <h3 style="margin-top:0">Logo</h3>
  <p class="muted">Shown on the sign-in screen and in the top bar of every page.</p>

  <div class="logo-setting">
    <img class="logo-preview" src="<?= e(app_logo_url()) ?>" alt="Current logo">
    <div>
      <strong><?= $isCustom ? 'Custom logo' : 'Default ' . e($cfg['app_name']) . ' logo' ?></strong><br>
      <span class="hint"><?= $isCustom
          ? 'You can upload a new one or reset to the default logo.'
          : 'Upload an image below to replace it with your own.' ?></span>
    </div>
  </div>

  <form method="post" action="<?= e(url('settings')) ?>" enctype="multipart/form-data" autocomplete="off">
    <?= csrf_field() ?>
    <div class="form-row" style="margin:14px 0">
      <input type="file" name="logo" accept="image/jpeg,image/png,image/webp,image/gif">
      <div class="hint">Square images look best. JPG, PNG, WEBP, or GIF, up to <?= (int) $cfg['max_upload_mb'] ?> MB.</div>
    </div>
    <div class="form-actions">
      <md-filled-button type="button" has-icon
          onclick="const f=this.closest('form'); if(!f.logo.files.length){alert('Choose an image file first.');return;} if(confirm('Replace the current logo?')) f.submit()">
        <span class="material-symbols-outlined" slot="icon">upload</span> Save Logo</md-filled-button>
      <?php if ($isCustom): ?>
      <md-outlined-button type="button" has-icon
          onclick="if(confirm('Reset to the default <?= e($cfg['app_name']) ?> logo?')){const f=document.getElementById('reset-logo-form');f.submit()}">
        <span class="material-symbols-outlined" slot="icon">restart_alt</span> Reset to Default</md-outlined-button>
      <?php endif; ?>
    </div>
  </form>
  <?php if ($isCustom): ?>
  <form id="reset-logo-form" method="post" action="<?= e(url('settings')) ?>" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="do" value="reset_logo">
  </form>
  <?php endif; ?>
</div>

<div class="card" style="max-width:560px;margin-top:18px">
  <h3 style="margin-top:0">Color scheme</h3>
  <p class="muted">Sets the accent color used across the top bar, buttons, and highlights.</p>

  <form method="post" action="<?= e(url('settings')) ?>" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="theme">
    <div class="theme-grid">
      <?php foreach ($themes as $key => $t): ?>
        <label class="theme-option <?= $key === $curTheme ? 'selected' : '' ?>">
          <input type="radio" name="theme" value="<?= e($key) ?>" <?= $key === $curTheme ? 'checked' : '' ?>
                 onchange="this.form.submit()">
          <span class="theme-swatch" style="background:<?= e($t['primary']) ?>"></span>
          <span class="theme-name"><?= e($t['label']) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <noscript><div class="form-actions" style="margin-top:12px">
      <md-filled-button type="submit">Apply</md-filled-button>
    </div></noscript>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
