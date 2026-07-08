<?php
/** Sales transaction screen (Cashier + Owner). Build a cart of menu items, record sale, deduct inventory. */
require_login();
$pdo  = db();
$user = current_user();

/* ---------------- Handle POST (record sale) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $customer = trim((string) input('customer_name')) ?: 'Walk-in';
    $status   = input('payment_status') === 'paid' ? 'paid' : 'pending';
    $note     = trim((string) input('note'));
    // Order prep/progress timer in minutes. Defaults to 20; clamp to a sane range.
    $prepMinutes = (int) input('prep_minutes', 20);
    if ($prepMinutes < 1)   { $prepMinutes = 1; }
    if ($prepMinutes > 1440) { $prepMinutes = 1440; }

    $menuIds    = $_POST['menu_item_id'] ?? [];
    $quantities = $_POST['qty'] ?? [];

    // Build a clean list of [menu_item_id => qty]
    $cart = [];
    foreach ($menuIds as $i => $mid) {
        $mid = (int) $mid;
        $qty = (int) ($quantities[$i] ?? 0);
        if ($mid > 0 && $qty > 0) {
            $cart[$mid] = ($cart[$mid] ?? 0) + $qty;
        }
    }

    if (!$cart) {
        flash('Add at least one item before recording the sale.', 'error');
        redirect('sales');
    }

    try {
        $pdo->beginTransaction();

        // Load the menu items being sold
        $in   = implode(',', array_fill(0, count($cart), '?'));
        $stmt = $pdo->prepare("SELECT id, name, price, is_active FROM menu_items WHERE id IN ($in)");
        $stmt->execute(array_keys($cart));
        $menu = [];
        foreach ($stmt->fetchAll() as $m) { $menu[(int) $m['id']] = $m; }

        // Load their recipes
        $rstmt = $pdo->prepare("SELECT menu_item_id, inventory_item_id, quantity FROM menu_item_ingredients WHERE menu_item_id IN ($in)");
        $rstmt->execute(array_keys($cart));
        $recipes = [];                 // menu_id => [inv_id => qty]
        $neededInv = [];               // inv_id => total units required across the cart
        foreach ($rstmt->fetchAll() as $r) {
            $mid = (int) $r['menu_item_id'];
            $iid = (int) $r['inventory_item_id'];
            $rq  = (int) $r['quantity'];
            $recipes[$mid][$iid] = $rq;
        }

        // Validate menu items, accumulate inventory needs
        foreach ($cart as $mid => $qty) {
            if (!isset($menu[$mid])) {
                throw new RuntimeException('A selected item no longer exists.');
            }
            if (!$menu[$mid]['is_active']) {
                throw new RuntimeException('“' . $menu[$mid]['name'] . '” is no longer available.');
            }
            if (empty($recipes[$mid])) {
                throw new RuntimeException('“' . $menu[$mid]['name'] . '” has no recipe and cannot be sold.');
            }
            foreach ($recipes[$mid] as $iid => $rq) {
                $neededInv[$iid] = ($neededInv[$iid] ?? 0) + $rq * $qty;
            }
        }

        // Lock and read the inventory items we will consume
        $invIn   = implode(',', array_fill(0, count($neededInv), '?'));
        $istmt   = $pdo->prepare("SELECT id, name, stock_quantity, unit_cost FROM inventory_items WHERE id IN ($invIn) FOR UPDATE");
        $istmt->execute(array_keys($neededInv));
        $inv = [];
        foreach ($istmt->fetchAll() as $r) { $inv[(int) $r['id']] = $r; }

        // Validate stock
        foreach ($neededInv as $iid => $need) {
            if (!isset($inv[$iid])) {
                throw new RuntimeException('An ingredient no longer exists.');
            }
            if ((int) $inv[$iid]['stock_quantity'] < $need) {
                throw new RuntimeException('Not enough “' . $inv[$iid]['name'] . '” in stock (need ' . $need . ', have ' . (int) $inv[$iid]['stock_quantity'] . ').');
            }
        }

        // Build line items with cost snapshot
        $lineItems = [];
        $total = 0;
        foreach ($cart as $mid => $qty) {
            $unitPrice = (float) $menu[$mid]['price'];
            $unitCost  = 0;
            foreach ($recipes[$mid] as $iid => $rq) {
                $unitCost += (float) $inv[$iid]['unit_cost'] * $rq;
            }
            $sub = round($unitPrice * $qty, 2);
            $total += $sub;
            $lineItems[] = ['id' => $mid, 'name' => $menu[$mid]['name'], 'qty' => $qty,
                            'price' => $unitPrice, 'cost' => round($unitCost, 2), 'sub' => $sub];
        }

        // Insert sale header
        $paidAt = $status === 'paid' ? date('Y-m-d H:i:s') : null;
        $stmt = $pdo->prepare('INSERT INTO sales (customer_name, cashier_id, total_amount, payment_status, note, prep_minutes, paid_at)
                               VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$customer, $user['id'], $total, $status, $note ?: null, $prepMinutes, $paidAt]);
        $saleId = (int) $pdo->lastInsertId();

        // Insert line items
        $itemStmt = $pdo->prepare('INSERT INTO sale_items (sale_id, menu_item_id, product_name, quantity, unit_price, unit_cost, subtotal)
                                   VALUES (?,?,?,?,?,?,?)');
        foreach ($lineItems as $li) {
            $itemStmt->execute([$saleId, $li['id'], $li['name'], $li['qty'], $li['price'], $li['cost'], $li['sub']]);
        }

        // Decrement inventory stock
        $invStmt = $pdo->prepare('UPDATE inventory_items SET stock_quantity = stock_quantity - ?, updated_at=CURRENT_TIMESTAMP WHERE id = ?');
        foreach ($neededInv as $iid => $need) {
            $invStmt->execute([$need, $iid]);
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
// Active menu items that have a recipe, with how many can be made from current stock.
$products = $pdo->query("
    SELECT m.id, m.name, m.category, m.price,
           COUNT(mi.id)                                AS ingredient_count,
           MIN(FLOOR(ii.stock_quantity / mi.quantity)) AS can_make
    FROM menu_items m
    JOIN menu_item_ingredients mi ON mi.menu_item_id = m.id
    JOIN inventory_items ii       ON ii.id = mi.inventory_item_id
    WHERE m.is_active = TRUE
    GROUP BY m.id
    ORDER BY m.category, m.name
")->fetchAll();

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
      <?php foreach ($products as $p): $avail = (int) $p['can_make']; $out = $avail <= 0; ?>
        <button type="button" class="pos-item" <?= $out ? 'disabled' : '' ?>
          data-id="<?= (int) $p['id'] ?>"
          data-name="<?= e($p['name']) ?>"
          data-price="<?= e($p['price']) ?>"
          data-stock="<?= $avail ?>">
          <span class="nm"><?= e($p['name']) ?></span>
          <span class="pr"><?= money($p['price']) ?></span>
          <span class="st"><?= $out ? 'Out of stock' : ($avail . ' can make') ?></span>
        </button>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Cart / checkout -->
  <div class="card cart">
    <h3>Current Order</h3>
    <form method="post" action="<?= e(url('sales')) ?>" id="saleForm" autocomplete="off">
      <?= csrf_field() ?>
      <md-outlined-text-field label="Customer label" name="customer_name" placeholder="Walk-in" style="margin-bottom:12px"></md-outlined-text-field>

      <ul class="cart-list" id="cartList">
        <li class="empty" id="cartEmpty"><span class="material-symbols-outlined">shopping_cart</span><br>No items yet</li>
      </ul>

      <div class="cart-total"><span>Total</span><span id="cartTotal"><?= money(0) ?></span></div>

      <div class="form-row" style="margin-top:14px">
        <label class="hint">Payment status</label>
        <label><input type="radio" name="payment_status" value="paid" checked> Paid</label>
        <label><input type="radio" name="payment_status" value="pending"> Pending</label>
      </div>

      <div class="prep-timer" style="margin-top:14px">
        <label class="hint" for="prepMinutes">Prep timer (minutes)</label>
        <div class="prep-presets">
          <md-outlined-text-field id="prepMinutes" name="prep_minutes" type="number"
            value="20" min="1" max="1440" step="1" style="width:120px"></md-outlined-text-field>
          <button type="button" class="prep-chip" data-prep="10">10m</button>
          <button type="button" class="prep-chip" data-prep="20">20m</button>
          <button type="button" class="prep-chip" data-prep="30">30m</button>
          <button type="button" class="prep-chip" data-prep="45">45m</button>
        </div>
        <p class="hint" style="margin:6px 0 0">Order will be marked ready after this time. Default is 20 minutes.</p>
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
      `<input type="hidden" name="menu_item_id[]" value="${id}"><input type="hidden" name="qty[]" value="${it.qty}">`);
  }
  document.getElementById('cartTotal').textContent = fmt(total);
}

function addItem(btn){
  const id = btn.dataset.id;
  const stock = parseInt(btn.dataset.stock,10);
  const cur = cart.get(id);
  const qty = cur ? cur.qty : 0;
  if (qty + 1 > stock){ alert('Only ' + stock + ' can be made from current stock.'); return; }
  if (cur){ cur.qty++; }
  else { cart.set(id, {name:btn.dataset.name, price:parseFloat(btn.dataset.price), stock, qty:1}); }
  render();
}

document.querySelectorAll('.pos-item').forEach(b => b.addEventListener('click', () => addItem(b)));

document.getElementById('cartList').addEventListener('click', e => {
  const t = e.target.closest('button'); if (!t) return;
  if (t.dataset.inc){ const it=cart.get(t.dataset.inc); if(it.qty+1>it.stock){alert('Only '+it.stock+' can be made from current stock.');return;} it.qty++; }
  else if (t.dataset.dec){ const it=cart.get(t.dataset.dec); it.qty--; if(it.qty<=0) cart.delete(t.dataset.dec); }
  else if (t.dataset.rm){ cart.delete(t.dataset.rm); }
  render();
});

// Prep-timer preset chips
document.querySelectorAll('.prep-chip').forEach(c => c.addEventListener('click', () => {
  document.getElementById('prepMinutes').value = c.dataset.prep;
}));

document.getElementById('recordBtn').addEventListener('click', () => {
  if (cart.size === 0){ alert('Add at least one item first.'); return; }
  if (confirm('Record this sale?')) document.getElementById('saleForm').requestSubmit();
});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
