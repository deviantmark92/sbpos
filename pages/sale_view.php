<?php
/** Single sale view / receipt. Allows updating payment status (transaction flow steps 4–5). */
require_login();
$pdo  = db();
$user = current_user();
$id   = (int) input('id');

/* Update payment status */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $status = input('payment_status') === 'paid' ? 'paid' : 'pending';
    $paidAt = $status === 'paid' ? date('Y-m-d H:i:s') : null;
    $pdo->prepare('UPDATE sales SET payment_status=?, paid_at=? WHERE id=?')->execute([$status, $paidAt, $id]);
    flash('Payment status updated to ' . $status . '.');
    redirect('sale_view', ['id' => $id]);
}

$stmt = $pdo->prepare('SELECT s.*, u.full_name AS cashier FROM sales s LEFT JOIN users u ON u.id=s.cashier_id WHERE s.id=?');
$stmt->execute([$id]);
$sale = $stmt->fetch();

if (!$sale) {
    flash('Sale not found.', 'error');
    redirect('dashboard');
}

$items = $pdo->prepare('SELECT * FROM sale_items WHERE sale_id=? ORDER BY id');
$items->execute([$id]);
$items = $items->fetchAll();

$activePage = '';
$pageTitle  = 'Sale #' . $id;
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h2>Sale #<?= (int) $sale['id'] ?></h2>
  <div class="spacer"></div>
  <a href="<?= e(url('sales')) ?>" style="text-decoration:none"><md-outlined-button has-icon>
    <span class="material-symbols-outlined" slot="icon">add</span> New Sale</md-outlined-button></a>
</div>

<div class="card" style="max-width:620px">
  <div class="row" style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:14px">
    <div><div class="hint">Customer</div><strong><?= e($sale['customer_name']) ?></strong></div>
    <div><div class="hint">Date</div><strong><?= e(date('M j, Y g:ia', strtotime($sale['created_at']))) ?></strong></div>
    <div><div class="hint">Cashier</div><strong><?= e($sale['cashier'] ?? '—') ?></strong></div>
    <div><div class="hint">Status</div>
      <span class="badge <?= $sale['payment_status'] ?>"><?= e(ucfirst($sale['payment_status'])) ?></span>
    </div>
  </div>

  <div class="table-wrap" style="border:none">
    <table class="data" style="min-width:0">
      <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Subtotal</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?= e($it['product_name']) ?></td>
          <td class="num"><?= (int) $it['quantity'] ?></td>
          <td class="num"><?= money($it['unit_price']) ?></td>
          <td class="num"><?= money($it['subtotal']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><th colspan="3" class="num">Total</th><th class="num"><?= money($sale['total_amount']) ?></th></tr>
      </tfoot>
    </table>
  </div>

  <?php if (!empty($sale['note'])): ?>
    <p class="hint" style="margin-top:12px">Note: <?= e($sale['note']) ?></p>
  <?php endif; ?>

  <?php if ($sale['payment_status'] === 'pending'): ?>
    <form method="post" action="<?= e(url('sale_view', ['id' => $id])) ?>" class="form-actions" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="payment_status" value="paid">
      <md-filled-button type="submit" has-icon onclick="this.closest('form').requestSubmit()">
        <span class="material-symbols-outlined" slot="icon">paid</span> Mark as Paid
      </md-filled-button>
    </form>
  <?php else: ?>
    <form method="post" action="<?= e(url('sale_view', ['id' => $id])) ?>" class="form-actions" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="payment_status" value="pending">
      <md-outlined-button type="submit" onclick="this.closest('form').requestSubmit()">Revert to Pending</md-outlined-button>
    </form>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
