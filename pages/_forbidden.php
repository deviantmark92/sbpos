<?php
/** Shown when a cashier tries to open an owner-only page. */
$activePage = '';
$pageTitle  = 'Access denied';
require __DIR__ . '/../includes/header.php';
?>
<div class="card empty" style="margin-top:30px">
  <span class="material-symbols-outlined" style="font-size:48px;color:var(--bad)">lock</span>
  <h2>Owner access only</h2>
  <p class="muted">This section is restricted to the Owner role. As a cashier you can process sales from the
    <a class="plain" href="<?= e(url('sales')) ?>">New Sale</a> screen.</p>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
