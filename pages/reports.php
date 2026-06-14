<?php
/** Reports module (Owner only): daily / monthly / yearly sales + inventory summary. */
require_owner();
if (!empty($GLOBALS['__forbidden'])) { require __DIR__ . '/_forbidden.php'; return; }

$pdo = db();

$period = in_array(input('period'), ['day', 'month', 'year'], true) ? input('period') : 'day';
$view   = input('view') === 'sales' ? 'sales' : 'summary';

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

// ---- Optional calendar filter: a single date or a date range -------------
// Strictly validate to YYYY-MM-DD so the values are safe to inline in SQL.
$validDate = static function ($s): ?string {
    $d = DateTime::createFromFormat('Y-m-d', (string) $s);
    return ($d && $d->format('Y-m-d') === $s) ? $s : null;
};
$mode   = input('date_mode') === 'range' ? 'range' : 'single';
$from   = $validDate(input('from'));
$to     = $mode === 'range' ? $validDate(input('to')) : null;
if ($from !== null && $to !== null && $to < $from) {
    [$from, $to] = [$to, $from];   // tolerate a reversed range
}
$dateActive = $from !== null;

// When a calendar filter is active it replaces the period filter everywhere.
if ($dateActive) {
    if ($to !== null) {
        $periodFilter  = "DATE(created_at)   BETWEEN '$from' AND '$to'";
        $sPeriodFilter = "DATE(s.created_at) BETWEEN '$from' AND '$to'";
    } else {
        $periodFilter  = "DATE(created_at)   = '$from'";
        $sPeriodFilter = "DATE(s.created_at) = '$from'";
    }
}

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

// Profit on paid sales in the current period (revenue - cost snapshot)
$profit = $pdo->query("
    SELECT COALESCE(SUM((si.unit_price - si.unit_cost) * si.quantity), 0) AS profit
    FROM sale_items si JOIN sales s ON s.id = si.sale_id
    WHERE $sPeriodFilter
      AND s.payment_status='paid'
")->fetch();

// Inventory report (raw materials, valued at actual cost)
$inv = $pdo->query("
    SELECT COUNT(*) AS items,
           COALESCE(SUM(stock_quantity), 0)              AS units,
           COALESCE(SUM(stock_quantity * unit_cost), 0)  AS stock_value,
           COUNT(CASE WHEN stock_quantity <= low_stock_threshold THEN 1 END) AS low
    FROM inventory_items WHERE is_active = TRUE
")->fetch();

// Individual sales list (used when $view === 'sales')
$salesList = [];
if ($view === 'sales') {
    $salesList = $pdo->query("
        SELECT s.id, s.customer_name, s.total_amount, s.payment_status,
               s.created_at, u.full_name AS cashier,
               COUNT(si.id) AS item_count,
               COALESCE(SUM((si.unit_price - si.unit_cost) * si.quantity), 0) AS profit
        FROM sales s
        LEFT JOIN users u ON u.id = s.cashier_id
        LEFT JOIN sale_items si ON si.sale_id = s.id
        WHERE $sPeriodFilter
        GROUP BY s.id
        ORDER BY s.created_at DESC
        LIMIT 200
    ")->fetchAll();
}

// Excel export of the sales list for the active selection (runs before any output).
if ($view === 'sales' && input('export') === 'xlsx') {
    $rows = $pdo->query("
        SELECT s.id, s.customer_name, s.total_amount, s.payment_status,
               s.created_at, u.full_name AS cashier,
               COUNT(si.id) AS item_count,
               COALESCE(SUM((si.unit_price - si.unit_cost) * si.quantity), 0) AS profit
        FROM sales s
        LEFT JOIN users u ON u.id = s.cashier_id
        LEFT JOIN sale_items si ON si.sale_id = s.id
        WHERE $sPeriodFilter
        GROUP BY s.id
        ORDER BY s.created_at DESC
    ")->fetchAll();

    $headers = ['Sale #', 'Date', 'Time', 'Customer', 'Cashier', 'Items', 'Total', 'Profit', 'Status'];
    $data = [];
    foreach ($rows as $s) {
        $data[] = [
            (int) $s['id'],
            date('Y-m-d', strtotime($s['created_at'])),
            date('g:i a', strtotime($s['created_at'])),
            $s['customer_name'],
            $s['cashier'] ?? '',
            (int) $s['item_count'],
            round((float) $s['total_amount'], 2),
            round((float) $s['profit'], 2),
            ucfirst($s['payment_status']),
        ];
    }

    $tag = $dateActive
        ? ($to !== null ? "{$from}_to_{$to}" : $from)
        : $period . '_' . date('Y-m-d');
    xlsx_stream('sales_' . $tag . '.xlsx', $headers, $data);
}

$fmtBucket = function ($b) use ($period) {
    $t = strtotime($b);
    return $period === 'day' ? date('M j, Y', $t) : ($period === 'month' ? date('F Y', $t) : date('Y', $t));
};
$label = ['day' => 'Today', 'month' => 'This Month', 'year' => 'This Year'][$period];
if ($dateActive) {
    $label = $to !== null
        ? date('M j, Y', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to))
        : date('M j, Y', strtotime($from));
}

// Keep the active calendar filter when flipping between Summary / Sales List.
$dateParams = $dateActive
    ? array_filter(['date_mode' => $mode, 'from' => $from, 'to' => $to], fn ($v) => $v !== null && $v !== '')
    : [];

$activePage = 'reports';
$pageTitle  = 'Reports';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h2>Reports</h2>
  <div class="spacer"></div>
  <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
    <?php foreach (['day' => 'Daily', 'month' => 'Monthly', 'year' => 'Yearly'] as $k => $lab): ?>
      <a href="<?= e(url('reports', ['view' => $view, 'period' => $k])) ?>" style="text-decoration:none">
        <?php if ($period === $k): ?><md-filled-button><?= $lab ?></md-filled-button>
        <?php else: ?><md-outlined-button><?= $lab ?></md-outlined-button><?php endif; ?>
      </a>
    <?php endforeach; ?>
    <span style="display:inline-block;width:1px;height:28px;background:var(--line);margin:0 2px"></span>
    <a href="<?= e(url('reports', ['view' => 'summary', 'period' => $period] + $dateParams)) ?>" style="text-decoration:none">
      <?php if ($view === 'summary'): ?><md-filled-button>Summary</md-filled-button>
      <?php else: ?><md-outlined-button>Summary</md-outlined-button><?php endif; ?>
    </a>
    <a href="<?= e(url('reports', ['view' => 'sales', 'period' => $period] + $dateParams)) ?>" style="text-decoration:none">
      <?php if ($view === 'sales'): ?><md-filled-button>Sales List</md-filled-button>
      <?php else: ?><md-outlined-button>Sales List</md-outlined-button><?php endif; ?>
    </a>
  </div>
</div>

<!-- Calendar filter: query a single date or a date range -->
<form method="get" action="index.php" class="card"
      style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;padding:14px 16px;margin-bottom:16px">
  <input type="hidden" name="page" value="reports">
  <input type="hidden" name="view" value="<?= e($view) ?>">
  <input type="hidden" name="period" value="<?= e($period) ?>">

  <div style="display:flex;flex-direction:column;gap:4px">
    <span class="muted" style="font-size:.8rem">Filter by date</span>
    <div style="display:flex;gap:14px;align-items:center;height:40px">
      <label style="display:flex;gap:5px;align-items:center;cursor:pointer">
        <input type="radio" name="date_mode" value="single" id="dm-single"
               <?= $mode !== 'range' ? 'checked' : '' ?>> Single date
      </label>
      <label style="display:flex;gap:5px;align-items:center;cursor:pointer">
        <input type="radio" name="date_mode" value="range" id="dm-range"
               <?= $mode === 'range' ? 'checked' : '' ?>> Date range
      </label>
    </div>
  </div>

  <div style="display:flex;flex-direction:column;gap:4px">
    <label class="muted" for="from" style="font-size:.8rem" id="from-label"><?= $mode === 'range' ? 'From' : 'On date' ?></label>
    <input type="date" name="from" id="from" value="<?= e($from ?? '') ?>"
           style="padding:8px 10px;border:1px solid var(--line);border-radius:8px">
  </div>

  <div style="flex-direction:column;gap:4px;display:<?= $mode === 'range' ? 'flex' : 'none' ?>" id="to-field">
    <label class="muted" for="to" style="font-size:.8rem">To</label>
    <input type="date" name="to" id="to" value="<?= e($to ?? '') ?>"
           style="padding:8px 10px;border:1px solid var(--line);border-radius:8px">
  </div>

  <md-filled-button type="submit" has-icon style="--md-filled-button-container-height:40px">
    <span class="material-symbols-outlined" slot="icon">calendar_month</span>Apply
  </md-filled-button>
  <?php if ($dateActive): ?>
    <a href="<?= e(url('reports', ['view' => $view, 'period' => $period])) ?>" style="text-decoration:none">
      <md-text-button type="button" style="--md-text-button-container-height:40px">Clear</md-text-button>
    </a>
  <?php endif; ?>
</form>

<script>
  (function () {
    function init() {
      var rangeRadio = document.getElementById('dm-range');
      var toField    = document.getElementById('to-field');
      var fromLabel  = document.getElementById('from-label');
      if (!rangeRadio || !toField || !fromLabel) { return; }

      function sync() {
        var isRange = rangeRadio.checked;
        toField.style.display = isRange ? 'flex' : 'none';
        fromLabel.textContent = isRange ? 'From' : 'On date';
      }

      var radios = document.querySelectorAll('input[name="date_mode"]');
      for (var i = 0; i < radios.length; i++) {
        radios[i].addEventListener('change', sync);
        radios[i].addEventListener('click', sync);
      }
      sync(); // reflect the current selection on load
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', init);
    } else {
      init();
    }
  })();
</script>

<?php if ($view === 'summary'): ?>
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
    <div class="label"><span class="material-symbols-outlined">trending_up</span> <?= $label ?> · Profit (paid)</div>
    <div class="value"><?= money($profit['profit']) ?></div>
    <div class="hint">revenue − ingredient cost</div>
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

<?php else: /* sales list view */ ?>
<div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
  <span class="muted"><?= $label ?> · <?= count($salesList) ?> sale<?= count($salesList) === 1 ? '' : 's' ?></span>
  <div class="spacer" style="flex:1"></div>
  <?php if ($salesList): ?>
    <a href="<?= e(url('reports', ['view' => 'sales', 'period' => $period, 'export' => 'xlsx'] + $dateParams)) ?>"
       style="text-decoration:none">
      <md-filled-button has-icon>
        <span class="material-symbols-outlined" slot="icon">download</span>Export to Excel
      </md-filled-button>
    </a>
  <?php endif; ?>
</div>
<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <th>#</th>
        <th>Date</th>
        <th>Customer</th>
        <th>Cashier</th>
        <th class="num">Items</th>
        <th class="num">Total</th>
        <th class="num">Profit</th>
        <th>Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$salesList): ?>
      <tr><td colspan="9" class="muted" style="text-align:center;padding:24px">No sales recorded for this selection.</td></tr>
    <?php else: foreach ($salesList as $s): ?>
      <tr>
        <td class="muted">#<?= (int) $s['id'] ?></td>
        <td>
          <?= e(date('M j, Y', strtotime($s['created_at']))) ?><br>
          <small class="muted"><?= e(date('g:i a', strtotime($s['created_at']))) ?></small>
        </td>
        <td><?= e($s['customer_name']) ?></td>
        <td class="muted"><?= e($s['cashier'] ?? '—') ?></td>
        <td class="num"><?= (int) $s['item_count'] ?></td>
        <td class="num"><strong><?= money($s['total_amount']) ?></strong></td>
        <td class="num <?= $s['payment_status'] === 'paid' ? '' : 'muted' ?>"><?= money($s['profit']) ?></td>
        <td><span class="badge <?= e($s['payment_status']) ?>"><?= e(ucfirst($s['payment_status'])) ?></span></td>
        <td>
          <a href="<?= e(url('sale_view', ['id' => $s['id']])) ?>" style="text-decoration:none">
            <md-outlined-button>View</md-outlined-button>
          </a>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
