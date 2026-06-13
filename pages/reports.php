<?php
/** Reports module (Owner only): daily / monthly / yearly sales + inventory summary. */
require_owner();
if (!empty($GLOBALS['__forbidden'])) { require __DIR__ . '/_forbidden.php'; return; }

$pdo = db();

$period = in_array(input('period'), ['day', 'month', 'year'], true) ? input('period') : 'day';

// Period-specific SQL fragments ($period is whitelisted above)
$periodFilter = [
    'day'   => "DATE(created_at) = CURDATE()",
    'month' => "DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')",
    'year'  => "YEAR(created_at) = YEAR(CURDATE())",
][$period];

$sPeriodFilter = [
    'day'   => "DATE(s.created_at) = CURDATE()",
    'month' => "DATE_FORMAT(s.created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')",
    'year'  => "YEAR(s.created_at) = YEAR(CURDATE())",
][$period];

$bucketExpr = [
    'day'   => "DATE(created_at)",
    'month' => "DATE_FORMAT(created_at, '%Y-%m-01')",
    'year'  => "DATE_FORMAT(created_at, '%Y-01-01')",
][$period];

// Totals for the current period
$sum = $pdo->query("
    SELECT
      COUNT(CASE WHEN payment_status='paid'    THEN 1 END)                       AS paid_count,
      COALESCE(SUM(CASE WHEN payment_status='paid'    THEN total_amount END), 0) AS paid_total,
      COUNT(CASE WHEN payment_status='pending' THEN 1 END)                       AS pending_count,
      COALESCE(SUM(CASE WHEN payment_status='pending' THEN total_amount END), 0) AS pending_total
    FROM sales
    WHERE $periodFilter
")->fetch();

// Breakdown rows over a recent window for the chosen period
$breakdown = $pdo->query("
    SELECT $bucketExpr AS bucket,
           COUNT(*) AS txns,
           COALESCE(SUM(CASE WHEN payment_status='paid'    THEN total_amount END), 0) AS paid,
           COALESCE(SUM(CASE WHEN payment_status='pending' THEN total_amount END), 0) AS pending
    FROM sales
    GROUP BY $bucketExpr
    ORDER BY $bucketExpr DESC
    LIMIT 14
")->fetchAll();

// Top selling items in the current period
$top = $pdo->query("
    SELECT si.product_name, SUM(si.quantity) AS qty, SUM(si.subtotal) AS revenue
    FROM sale_items si JOIN sales s ON s.id = si.sale_id
    WHERE $sPeriodFilter
      AND s.payment_status='paid'
    GROUP BY si.product_name
    ORDER BY qty DESC
    LIMIT 8
")->fetchAll();

// Inventory report
$inv = $pdo->query("
    SELECT COUNT(*) AS items,
           COALESCE(SUM(stock_quantity), 0)          AS units,
           COALESCE(SUM(stock_quantity * price), 0)  AS stock_value,
           COUNT(CASE WHEN stock_quantity <= low_stock_threshold THEN 1 END) AS low
    FROM products WHERE is_active = TRUE
")->fetch();

$fmtBucket = function ($b) use ($period) {
    $t = strtotime($b);
    return $period === 'day' ? date('M j, Y', $t) : ($period === 'month' ? date('F Y', $t) : date('Y', $t));
};
$label = ['day' => 'Today', 'month' => 'This Month', 'year' => 'This Year'][$period];

$activePage = 'reports';
$pageTitle  = 'Reports';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h2>Reports</h2>
  <div class="spacer"></div>
  <div style="display:flex;gap:6px">
    <?php foreach (['day' => 'Daily', 'month' => 'Monthly', 'year' => 'Yearly'] as $k => $lab): ?>
      <a href="<?= e(url('reports', ['period' => $k])) ?>" style="text-decoration:none">
        <?php if ($period === $k): ?><md-filled-button><?= $lab ?></md-filled-button>
        <?php else: ?><md-outlined-button><?= $lab ?></md-outlined-button><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<div class="stat-grid">
  <div class="card stat">
    <div class="label"><span class="material-symbols-outlined">payments</span> <?= $label ?> · Revenue (paid)</div>
    <div class="value"><?= money($sum['paid_total']) ?></div>
    <div class="hint"><?= (int) $sum['paid_count'] ?> paid transactions</div>
  </div>
  <div class="card stat">
    <div class="label"><span class="material-symbols-outlined">schedule</span> <?= $label ?> · Pending</div>
    <div class="value"><?= money($sum['pending_total']) ?></div>
    <div class="hint"><?= (int) $sum['pending_count'] ?> awaiting payment</div>
  </div>
  <div class="card stat">
    <div class="label"><span class="material-symbols-outlined">inventory_2</span> Stock Value</div>
    <div class="value"><?= money($inv['stock_value']) ?></div>
    <div class="hint"><?= (int) $inv['units'] ?> units · <?= (int) $inv['items'] ?> items</div>
  </div>
  <div class="card stat">
    <div class="label"><span class="material-symbols-outlined">warning</span> Items to Reorder</div>
    <div class="value"><?= (int) $inv['low'] ?></div>
  </div>
</div>

<div class="cards-grid" style="grid-template-columns:repeat(auto-fit,minmax(340px,1fr))">
  <div class="card">
    <h3 style="margin-top:0">Sales Breakdown (<?= ucfirst($period) ?>)</h3>
    <div class="table-wrap" style="border:none">
      <table class="data" style="min-width:0">
        <thead><tr><th><?= ucfirst($period) ?></th><th class="num">Txns</th><th class="num">Paid</th><th class="num">Pending</th></tr></thead>
        <tbody>
        <?php if (!$breakdown): ?>
          <tr><td colspan="4" class="muted">No sales yet.</td></tr>
        <?php else: foreach ($breakdown as $b): ?>
          <tr>
            <td><?= e($fmtBucket($b['bucket'])) ?></td>
            <td class="num"><?= (int) $b['txns'] ?></td>
            <td class="num"><?= money($b['paid']) ?></td>
            <td class="num muted"><?= money($b['pending']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <h3 style="margin-top:0">Top Sellers (<?= $label ?>)</h3>
    <div class="table-wrap" style="border:none">
      <table class="data" style="min-width:0">
        <thead><tr><th>Item</th><th class="num">Qty sold</th><th class="num">Revenue</th></tr></thead>
        <tbody>
        <?php if (!$top): ?>
          <tr><td colspan="3" class="muted">No paid sales in this period yet.</td></tr>
        <?php else: foreach ($top as $t): ?>
          <tr><td><?= e($t['product_name']) ?></td><td class="num"><?= (int) $t['qty'] ?></td><td class="num"><?= money($t['revenue']) ?></td></tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
