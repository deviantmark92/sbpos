<?php
/** Inventory module (Owner only): raw materials that define the actual cost of menu items. */
require_owner();
if (!empty($GLOBALS['__forbidden'])) { require __DIR__ . '/_forbidden.php'; return; }

$pdo    = db();
$action = (string) input('action', 'list');

/* ---------------- Handle POST ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $do = (string) input('do');

    if ($do === 'delete') {
        $id = (int) input('id');
        // Block deletion if the item is used in any recipe
        $used = $pdo->prepare('SELECT COUNT(*) FROM menu_item_ingredients WHERE inventory_item_id = ?');
        $used->execute([$id]);
        if ($used->fetchColumn() > 0) {
            flash('That item is used in one or more menu recipes. Remove it from those recipes first.', 'error');
        } else {
            $pdo->prepare('DELETE FROM inventory_items WHERE id = ?')->execute([$id]);
            flash('Inventory item deleted.');
        }
        redirect('inventory');
    }

    if ($do === 'adjust') {
        // Add or remove stock by a delta
        $id    = (int) input('id');
        $delta = (int) input('delta');
        $pdo->prepare('UPDATE inventory_items SET stock_quantity = GREATEST(0, stock_quantity + ?), updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->execute([$delta, $id]);
        flash('Stock updated.');
        redirect('inventory');
    }

    if ($do === 'set') {
        // Set absolute stock + threshold
        $id    = (int) input('id');
        $stock = max(0, (int) input('stock_quantity'));
        $thr   = max(0, (int) input('low_stock_threshold'));
        $pdo->prepare('UPDATE inventory_items SET stock_quantity=?, low_stock_threshold=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->execute([$stock, $thr, $id]);
        flash('Inventory saved.');
        redirect('inventory');
    }

    // Create or update (do === 'save')
    $id        = (int) input('id');
    $name      = trim((string) input('name'));
    $category  = trim((string) input('category')) ?: 'General';
    $unit      = trim((string) input('unit')) ?: 'pc';
    $unitCost  = max(0, (float) input('unit_cost'));
    $stock     = max(0, (int) input('stock_quantity'));
    $threshold = max(0, (int) input('low_stock_threshold'));

    $errors = [];
    if ($name === '') $errors[] = 'Name is required.';

    if ($errors) {
        flash(implode(' ', $errors), 'error');
        redirect('inventory', ['action' => $id ? 'edit' : 'new'] + ($id ? ['id' => $id] : []));
    }

    if ($id) {
        $pdo->prepare('UPDATE inventory_items SET name=?, category=?, unit=?, unit_cost=?, stock_quantity=?, low_stock_threshold=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->execute([$name, $category, $unit, $unitCost, $stock, $threshold, $id]);
        flash('“' . $name . '” updated.');
    } else {
        $pdo->prepare('INSERT INTO inventory_items (name, category, unit, unit_cost, stock_quantity, low_stock_threshold) VALUES (?,?,?,?,?,?)')
            ->execute([$name, $category, $unit, $unitCost, $stock, $threshold]);
        flash('“' . $name . '” added to inventory.');
    }
    redirect('inventory');
}

/* ---------------- Render form (new / edit) ---------------- */
if ($action === 'new' || $action === 'edit') {
    $item = ['id' => 0, 'name' => '', 'category' => '', 'unit' => 'pc',
             'unit_cost' => '', 'stock_quantity' => 0, 'low_stock_threshold' => 5];
    if ($action === 'edit') {
        $stmt = $pdo->prepare('SELECT * FROM inventory_items WHERE id = ?');
        $stmt->execute([(int) input('id')]);
        $item = $stmt->fetch() ?: $item;
    }
    $activePage = 'inventory';
    $pageTitle  = $action === 'edit' ? 'Edit inventory item' : 'New inventory item';
    require __DIR__ . '/../includes/header.php';
    $cfg = config();
    ?>
    <div class="page-head">
      <h2><?= $action === 'edit' ? 'Edit Inventory Item' : 'Add Inventory Item' ?></h2>
    </div>
    <form class="card" method="post" action="<?= e(url('inventory')) ?>" style="max-width:620px" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="save">
      <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
      <div class="form-grid">
        <div class="form-row" style="grid-column:1/-1">
          <md-outlined-text-field label="Item name" name="name" required value="<?= e($item['name']) ?>"></md-outlined-text-field>
        </div>
        <div class="form-row">
          <md-outlined-text-field label="Category" name="category" value="<?= e($item['category']) ?>" placeholder="e.g. Meat"></md-outlined-text-field>
        </div>
        <div class="form-row">
          <md-outlined-text-field label="Unit (pc, cup, can…)" name="unit" value="<?= e($item['unit']) ?>"></md-outlined-text-field>
        </div>
        <div class="form-row">
          <md-outlined-text-field label="Unit cost (<?= e($cfg['currency_symbol']) ?>)" name="unit_cost" type="number" step="0.01" min="0"
              required value="<?= e($item['unit_cost']) ?>"></md-outlined-text-field>
        </div>
        <div class="form-row">
          <md-outlined-text-field label="Stock quantity" name="stock_quantity" type="number" min="0"
              value="<?= (int) $item['stock_quantity'] ?>"></md-outlined-text-field>
        </div>
        <div class="form-row">
          <md-outlined-text-field label="Low-stock alert at" name="low_stock_threshold" type="number" min="0"
              value="<?= (int) $item['low_stock_threshold'] ?>"></md-outlined-text-field>
        </div>
      </div>
      <div class="form-actions">
        <md-filled-button type="button" has-icon onclick="if(confirm('Save this inventory item?')) this.closest('form').submit()">
          <span class="material-symbols-outlined" slot="icon">save</span> Save
        </md-filled-button>
        <a href="<?= e(url('inventory')) ?>" style="text-decoration:none">
          <md-outlined-button>Cancel</md-outlined-button>
        </a>
      </div>
    </form>
    <?php
    require __DIR__ . '/../includes/footer.php';
    return;
}

/* ---------------- List view ---------------- */
$items = $pdo->query("SELECT * FROM inventory_items ORDER BY (stock_quantity <= low_stock_threshold) DESC, category, name")->fetchAll();
$lowCount = 0;
foreach ($items as $it) { if ($it['stock_quantity'] <= $it['low_stock_threshold']) $lowCount++; }

$activePage = 'inventory';
$pageTitle  = 'Inventory';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h2>Inventory Items</h2>
  <div class="spacer"></div>
  <?php if ($lowCount): ?>
    <span class="badge low"><span class="material-symbols-outlined" style="font-size:16px">warning</span> <?= $lowCount ?> low-stock</span>
  <?php else: ?>
    <span class="badge ok"><span class="material-symbols-outlined" style="font-size:16px">check_circle</span> All stocked</span>
  <?php endif; ?>
  <a href="<?= e(url('inventory', ['action' => 'new'])) ?>" style="text-decoration:none">
    <md-filled-button has-icon>
      <span class="material-symbols-outlined" slot="icon">add</span> Add Item
    </md-filled-button>
  </a>
</div>

<div class="table-wrap">
  <table class="data">
    <thead>
      <tr><th>Item</th><th>Category</th><th class="num">Unit cost</th><th class="num">In stock</th><th class="num">Reorder at</th><th>Status</th><th>Quick adjust</th><th>Set</th><th></th></tr>
    </thead>
    <tbody>
    <?php if (!$items): ?>
      <tr><td colspan="9" class="muted" style="text-align:center;padding:24px">No inventory items yet. Add your first raw material.</td></tr>
    <?php else: foreach ($items as $p): $low = $p['stock_quantity'] <= $p['low_stock_threshold']; ?>
      <tr>
        <td><?= e($p['name']) ?> <small class="muted">/ <?= e($p['unit']) ?></small></td>
        <td class="muted"><?= e($p['category']) ?></td>
        <td class="num"><?= money($p['unit_cost']) ?></td>
        <td class="num"><strong><?= (int) $p['stock_quantity'] ?></strong></td>
        <td class="num muted"><?= (int) $p['low_stock_threshold'] ?></td>
        <td><span class="badge <?= $low ? 'low' : 'ok' ?>"><?= $low ? 'Reorder' : 'OK' ?></span></td>
        <td>
          <div style="display:flex;gap:4px">
            <?php foreach ([-1, +1, +10] as $d): ?>
              <form method="post" action="<?= e(url('inventory')) ?>" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="adjust">
                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <input type="hidden" name="delta" value="<?= $d ?>">
                <button type="submit" class="qtybox" style="border:1px solid var(--line);border-radius:8px;padding:4px 8px;background:var(--bg);cursor:pointer">
                  <?= $d > 0 ? '+' . $d : $d ?>
                </button>
              </form>
            <?php endforeach; ?>
          </div>
        </td>
        <td>
          <form method="post" action="<?= e(url('inventory')) ?>" style="display:flex;gap:6px;align-items:center" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="set">
            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <input type="number" name="stock_quantity" value="<?= (int) $p['stock_quantity'] ?>" min="0" style="width:64px;padding:6px;border:1px solid var(--line);border-radius:8px" title="Stock">
            <input type="number" name="low_stock_threshold" value="<?= (int) $p['low_stock_threshold'] ?>" min="0" style="width:64px;padding:6px;border:1px solid var(--line);border-radius:8px" title="Reorder at">
            <button type="button" style="border:none;background:var(--brown);color:#fff;border-radius:8px;padding:7px 10px;cursor:pointer"
              onclick="if(confirm('Save stock changes for &quot;<?= e($p['name']) ?>&quot;?')) this.closest('form').submit()">Save</button>
          </form>
        </td>
        <td>
          <div style="display:flex;gap:4px;align-items:center">
            <a class="plain" href="<?= e(url('inventory', ['action' => 'edit', 'id' => $p['id']])) ?>">Edit</a>
            <form method="post" action="<?= e(url('inventory')) ?>" autocomplete="off">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="delete">
              <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <button type="button" title="Delete <?= e($p['name']) ?>"
                onclick="if(confirm('Delete &quot;<?= e($p['name']) ?>&quot;? This cannot be undone.')) this.closest('form').submit()"
                style="border:none;background:none;cursor:pointer;color:var(--bad);padding:6px;border-radius:8px;display:flex;align-items:center">
                <span class="material-symbols-outlined">delete</span>
              </button>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
