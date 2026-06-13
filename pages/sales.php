<?php
/** Sales transaction screen (Cashier + Owner). Build a cart, set payment status, record sale. */
require_login();
$pdo  = db();
$user = current_user();

/* ---------------- Handle POST (record sale) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $customer = trim((string) input('customer_name')) ?: 'Walk-in';
    $status   = input('payment_status') === 'paid' ? 'paid' : 'pending';
    $note     = trim((string) input('note'));

    $productIds = $_POST['product_id'] ?? [];
    $quantities = $_POST['qty'] ?? [];

    // Build a clean list of [id => qty]
    $cart = [];
    foreach ($productIds as $i => $pid) {
        $pid = (int) $pid;
        $qty = (int) ($quantities[$i] ?? 0);
        if ($pid > 0 && $qty > 0) {
            $cart[$pid] = ($cart[$pid] ?? 0) + $qty;
        }
    }

    if (!$cart) {
        flash('Add at least one item before recording the sale.', 'error');
        redirect('sales');
    }

    try {
        $pdo->beginTransaction();

        // Lock the products we're selling and read current price/stock
        $in   = implode(',', array_fill(0, count($cart), '?'));
        $stmt = $pdo->prepare("SELECT id, name, price, stock_quantity FROM products WHERE id IN ($in) FOR UPDATE");
        $stmt->execute(array_keys($cart));
        $rows = [];
        foreach ($stmt->fetchAll() as $r) { $rows[$r['id']] = $r; }

        // Validate stock
        $lineItems = [];
        $total = 0;
        foreach ($cart as $pid => $qty) {
            if (!isset($rows[$pid])) {
                throw new RuntimeException('A selected item no longer exists.');
            }
            $p = $rows[$pid];
            if ((int) $p['stock_quantity'] < $qty) {
                throw new RuntimeException('Not enough stock for “' . $p['name'] . '” (only ' . $p['stock_quantity'] . ' left).');
            }
            $sub = round((float) $p['price'] * $qty, 2);
            $total += $sub;
            $lineItems[] = ['id' => $pid, 'name' => $p['name'], 'qty' => $qty, 'price' => $p['price'], 'sub' => $sub];
        }

        // Insert sale header
        $paidAt = $status === 'paid' ? date('Y-m-d H:i:s') : null;
        $stmt = $pdo->prepare('INSERT INTO sales (customer_name, cashier_id, total_amount, payment_status, note, paid_at)
                               VALUES (?,?,?,?,?,?)');
        $stmt->execute([$customer, $user['id'], $total, $status, $note ?: null, $paidAt]);
        $saleId = (int) $pdo->lastInsertId();

        // Insert line items + decrement stock
        $itemStmt = $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, product_name, quantity, unit_price, subtotal)
                                   VALUES (?,?,?,?,?,?)');
        $stockStmt = $pdo->prepare('UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?');
        foreach ($lineItems as $li) {
            $itemStmt->execute([$saleId, $li['id'], $li['name'], $li['qty'], $li['price'], $li['sub']]);
            $stockStmt->execute([$li['qty'], $li['id']]);
        }

        $pdo->commit();
        flash('Sale #' . $saleId . ' recorded (' . money($total) . ', ' . $status . ').');
        redirect('sale_view', ['id' => $saleId]);

    } catch (Throwable $ex) {
        $pdo->rollBack();
        flash($ex->getMessage(), 'error');
        redirect('sales');
    }
}

/* ---------------- Render POS screen ---------------- */
$products = $pdo->query("SELECT id, name, category, price, stock_quantity
                         FROM products
                         WHERE is_active = TRUE
                         ORDER BY category, name")->fetchAll();

$activePage = 'sales';
$pageTitle  = 'New Sale';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head"><h2>New Sale</h2></div>

<div class="pos-layout">
  <!-- Menu picker -->
  <div>
    <p class="hint">Tap an item to add it to the order.</p>
    <div class="pos-menu">
      <?php foreach ($products as $p): $out = $p['stock_quantity'] <= 0; ?>
        <button type="button" class="pos-item" <?= $out ? 'disabled' : '' ?>
          data-id="<?= (int) $p['id'] ?>"
          data-name="<?= e($p['name']) ?>"
          data-price="<?= e($p['price']) ?>"
          data-stock="<?= (int) $p['stock_quantity'] ?>">
          <span class="nm"><?= e($p['name']) ?></span>
          <span class="pr"><?= money($p['price']) ?></span>
          <span class="st"><?= $out ? 'Out of stock' : ((int) $p['stock_quantity'] . ' in stock') ?></span>
        </button>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Cart / checkout -->
  <div class="card cart">
    <h3>Current Order</h3>
    <form method="post" action="<?= e(url('sales')) ?>" id="saleForm" autocomplete="off">
      <?= csrf_field() ?>
      <md-outlined-text-field label="Customer name" name="customer_name" placeholder="Walk-in" style="margin-bottom:12px"></md-outlined-text-field>

      <ul class="cart-list" id="cartList">
        <li class="empty" id="cartEmpty"><span class="material-symbols-outlined">shopping_cart</span><br>No items yet</li>
      </ul>

      <div class="cart-total"><span>Total</span><span id="cartTotal"><?= money(0) ?></span></div>

      <div class="form-row" style="margin-top:14px">
        <label class="hint">Payment status</label>
        <label><input type="radio" name="payment_status" value="paid" checked> Paid</label>
        <label><input type="radio" name="payment_status" value="pending"> Pending</label>
      </div>

      <md-outlined-text-field label="Note (optional)" name="note" type="textarea" rows="2" style="margin-top:12px;width:100%"></md-outlined-text-field>

      <div id="hiddenInputs"></div>

      <div class="form-actions">
        <md-filled-button type="button" id="recordBtn" has-icon style="width:100%">
          <span class="material-symbols-outlined" slot="icon">check_circle</span>
          Record Sale
        </md-filled-button>
      </div>
    </form>
  </div>
</div>

<script>
const CURRENCY = <?= json_encode($cfg['currency_symbol']) ?>;
const cart = new Map(); // id -> {name, price, stock, qty}

function fmt(n){ return CURRENCY + n.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}); }

function render(){
  const list = document.getElementById('cartList');
  const hidden = document.getElementById('hiddenInputs');
  list.innerHTML = ''; hidden.innerHTML = '';
  let total = 0;
  if (cart.size === 0){
    list.innerHTML = '<li class="empty" id="cartEmpty"><span class="material-symbols-outlined">shopping_cart</span><br>No items yet</li>';
  }
  for (const [id, it] of cart){
    const line = it.price * it.qty; total += line;
    const li = document.createElement('li');
    li.className = 'cart-row';
    li.innerHTML = `
      <span class="nm">${it.name}</span>
      <span class="qtybox">
        <button type="button" data-dec="${id}">−</button>
        <span>${it.qty}</span>
        <button type="button" data-inc="${id}">+</button>
      </span>
      <span class="ln">${fmt(line)}</span>
      <button type="button" class="rm" data-rm="${id}"><span class="material-symbols-outlined">close</span></button>`;
    list.appendChild(li);
    hidden.insertAdjacentHTML('beforeend',
      `<input type="hidden" name="product_id[]" value="${id}"><input type="hidden" name="qty[]" value="${it.qty}">`);
  }
  document.getElementById('cartTotal').textContent = fmt(total);
}

function addItem(btn){
  const id = btn.dataset.id;
  const stock = parseInt(btn.dataset.stock,10);
  const cur = cart.get(id);
  const qty = cur ? cur.qty : 0;
  if (qty + 1 > stock){ alert('Only ' + stock + ' in stock.'); return; }
  if (cur){ cur.qty++; }
  else { cart.set(id, {name:btn.dataset.name, price:parseFloat(btn.dataset.price), stock, qty:1}); }
  render();
}

document.querySelectorAll('.pos-item').forEach(b => b.addEventListener('click', () => addItem(b)));

document.getElementById('cartList').addEventListener('click', e => {
  const t = e.target.closest('button'); if (!t) return;
  if (t.dataset.inc){ const it=cart.get(t.dataset.inc); if(it.qty+1>it.stock){alert('Only '+it.stock+' in stock.');return;} it.qty++; }
  else if (t.dataset.dec){ const it=cart.get(t.dataset.dec); it.qty--; if(it.qty<=0) cart.delete(t.dataset.dec); }
  else if (t.dataset.rm){ cart.delete(t.dataset.rm); }
  render();
});

document.getElementById('recordBtn').addEventListener('click', () => {
  if (cart.size === 0){ alert('Add at least one item first.'); return; }
  document.getElementById('saleForm').requestSubmit();
});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
