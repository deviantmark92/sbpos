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
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="26" height="26" aria-hidden="true" style="flex-shrink:0">
    <path d="M74,44 Q88,28 90,40 Q83,46 75,50 Z" fill="#b05a00"/>
    <path d="M75,52 Q92,44 91,57 Q84,59 75,57 Z" fill="#c06810"/>
    <ellipse cx="48" cy="65" rx="28" ry="22" fill="currentColor"/>
    <ellipse cx="44" cy="68" rx="18" ry="11" fill="#6b2c00"/>
    <ellipse cx="24" cy="51" rx="10" ry="14" fill="currentColor"/>
    <circle cx="22" cy="34" r="15" fill="currentColor"/>
    <path d="M15,21 Q17,12 20,20 Q22,12 25,20 Q27,12 30,21" fill="#d63b2f"/>
    <ellipse cx="13" cy="41" rx="5" ry="7" fill="#d63b2f"/>
    <path d="M5,33 L18,30 L18,37 Z" fill="#e8a020"/>
    <circle cx="24" cy="30" r="4" fill="#1a0800"/>
    <circle cx="23" cy="29" r="1.5" fill="white"/>
    <rect x="38" y="85" width="6" height="9" rx="3" fill="#e8a020"/>
    <rect x="52" y="85" width="6" height="9" rx="3" fill="#e8a020"/>
    <path d="M35,94 L47,94 M41,94 L41,98" stroke="#e8a020" stroke-width="3" stroke-linecap="round"/>
    <path d="M49,94 L61,94 M55,94 L55,98" stroke="#e8a020" stroke-width="3" stroke-linecap="round"/>
  </svg>
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
    <a class="icon-btn" href="<?= e(url('logout')) ?>" title="Log out"
       onclick="return confirm('Are you sure you want to log out?')">
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
