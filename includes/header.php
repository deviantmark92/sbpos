<?php
/** Shared page header + navigation. Expects $pageTitle and $activePage to be set. */
$cfg  = config();
$user = current_user();
$active = $activePage ?? '';

/** Nav items: [page, label, icon, ownerOnly] */
$nav = [
    ['dashboard', 'Dashboard',  'dashboard',     false],
    ['sales',     'New Sale',   'point_of_sale', false],
    ['products',  'Menu',       'restaurant_menu', true],
    ['inventory', 'Inventory',  'inventory_2',   true],
    ['reports',   'Reports',    'bar_chart',     true],
    ['users',     'Users',      'group',         true],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($pageTitle ?? $cfg['app_name']) ?> · <?= e($cfg['app_name']) ?></title>

<!-- Material Web (https://github.com/material-components/material-web) via ESM -->
<script type="importmap">
{
  "imports": {
    "@material/web/": "https://esm.run/@material/web/"
  }
}
</script>
<script type="module">
  import '@material/web/all.js';
  import {styles as typescaleStyles} from '@material/web/typography/md-typescale-styles.js';
  document.adoptedStyleSheets.push(typescaleStyles.styleSheet);
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Symbols+Outlined" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<input type="checkbox" id="nav-toggle" class="nav-toggle">
<header class="topbar">
  <label for="nav-toggle" class="icon-btn menu-btn" title="Menu">
    <span class="material-symbols-outlined">menu</span>
  </label>
  <span class="material-symbols-outlined brand-icon">lunch_dining</span>
  <h1 class="topbar-title"><?= e($cfg['app_name']) ?></h1>
  <div class="topbar-spacer"></div>
  <?php if ($user): ?>
    <span class="user-chip">
      <span class="material-symbols-outlined">account_circle</span>
      <span class="user-meta">
        <strong><?= e($user['full_name']) ?></strong>
        <small><?= e(ucfirst($user['role'])) ?></small>
      </span>
    </span>
    <a class="icon-btn" href="<?= e(url('logout')) ?>" title="Log out">
      <span class="material-symbols-outlined">logout</span>
    </a>
  <?php endif; ?>
</header>

<div class="shell">
  <?php if ($user): ?>
  <aside class="sidenav">
    <nav>
      <?php foreach ($nav as [$page, $label, $icon, $ownerOnly]): ?>
        <?php if ($ownerOnly && $user['role'] !== 'owner') continue; ?>
        <a class="nav-link <?= $active === $page ? 'active' : '' ?>" href="<?= e(url($page)) ?>">
          <span class="material-symbols-outlined"><?= e($icon) ?></span>
          <span><?= e($label) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <label for="nav-toggle" class="scrim"></label>
  </aside>
  <?php endif; ?>

  <main class="content">
    <?php foreach (take_flashes() as $f): ?>
      <div class="flash flash-<?= e($f['type']) ?>">
        <span class="material-symbols-outlined">
          <?= $f['type'] === 'error' ? 'error' : 'check_circle' ?>
        </span>
        <span><?= e($f['message']) ?></span>
      </div>
    <?php endforeach; ?>
