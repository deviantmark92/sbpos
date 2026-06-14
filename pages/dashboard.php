<?php
/** Dashboard: today's snapshot, pending payments, low-stock alerts. */
require_login();
$user = current_user();

$pdo = db();

// Today's paid sales
$today = $pdo->query("
    SELECT COALESCE(SUM(total_amount),0) AS total, COUNT(*) AS cnt
    FROM sales
    WHERE payment_status='paid' AND DATE(created_at) = CURDATE()
")->fetch();

// Pending payments
$pending = $pdo->query("
    SELECT s.id, s.customer_name, s.total_amount, s.created_at, u.full_name AS cashier
    FROM sales s LEFT JOIN users u ON u.id = s.cashier_id
    WHERE s.payment_status='pending'
    ORDER BY s.created_at DESC
")->fetchAll();
$pendingTotal = array_sum(array_column($pending, 'total_amount'));

// Low stock items (raw materials)
$low = $pdo->query("
    SELECT name, category, unit, stock_quantity, low_stock_threshold
    FROM inventory_items
    WHERE is_active = TRUE AND stock_quantity <= low_stock_threshold
    ORDER BY stock_quantity ASC
")->fetchAll();

$activePage = 'dashboard';
$pageTitle  = 'Dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h2>Welcome, <?= e(explode(' ', $user['full_name'])[0]) ?> 👋</h2>
  <div class="spacer"></div>
  <a href="<?= e(url('sales')) ?>" style="text-decoration:none">
    <md-filled-button has-icon>
      <span class="material-symbols-outlined" slot="icon">point_of_sale</span>
      New Sale
    </md-filled-button>
  </a>
</div>

<div class="stat-grid">
  <div class="card stat">
    <div class="label"><span class="material-symbols-outlined">payments</span> Today's Sales</div>
    <div class="value"><?= money($today['total']) ?></div>
  </div>
  <div class="card stat">
    <div class="label"><span class="material-symbols-outlined">receipt_long</span> Transactions Today</div>
    <div class="value"><?= (int) $today['cnt'] ?></div>
  </div>
  <div class="card stat">
    <div class="label"><span class="material-symbols-outlined">schedule</span> Pending Payments</div>
    <div class="value"><?= money($pendingTotal) ?></div>
  </div>
  <div class="card stat">
    <div class="label"><span class="material-symbols-outlined">warning</span> Low-stock Items</div>
    <div class="value"><?= count($low) ?></div>
  </div>
</div>

<div class="cards-grid" style="grid-template-columns:repeat(auto-fit,minmax(320px,1fr))">
  <!-- Pending payments -->
  <div class="card">
    <div class="page-head" style="margin-bottom:10px">
      <h2 style="font-size:1.15rem">Pending Payments</h2>
    </div>
    <?php if (!$pending): ?>
      <div class="empty"><span class="material-symbols-outlined">task_alt</span><br>All settled. No pending payments.</div>
    <?php else: ?>
      <div class="table-wrap" style="border:none">
        <table class="data" style="min-width:0">
          <thead><tr><th>Customer</th><th class="num">Amount</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($pending as $p): ?>
            <tr>
              <td><?= e($p['customer_name']) ?><br><small class="muted"><?= e(date('M j, g:ia', strtotime($p['created_at']))) ?></small></td>
              <td class="num"><span class="badge pending"><?= money($p['total_amount']) ?></span></td>
              <td><a class="plain" href="<?= e(url('sale_view', ['id' => $p['id']])) ?>">View</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Low stock -->
  <div class="card">
    <div class="page-head" style="margin-bottom:10px">
      <h2 style="font-size:1.15rem">Low-stock Alerts</h2>
    </div>
    <?php if (!$low): ?>
      <div class="empty"><span class="material-symbols-outlined">inventory</span><br>Inventory looks healthy.</div>
    <?php else: ?>
      <div class="table-wrap" style="border:none">
        <table class="data" style="min-width:0">
          <thead><tr><th>Item</th><th class="num">In stock</th><th class="num">Reorder at</th></tr></thead>
          <tbody>
          <?php foreach ($low as $l): ?>
            <tr>
              <td><?= e($l['name']) ?><br><small class="muted"><?= e($l['category']) ?></small></td>
              <td class="num"><span class="badge low"><?= (int) $l['stock_quantity'] ?></span></td>
              <td class="num muted"><?= (int) $l['low_stock_threshold'] ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($user['role'] === 'owner'): ?>
        <div style="margin-top:14px"><a class="plain" href="<?= e(url('inventory')) ?>">Manage inventory →</a></div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
