<?php
/** Inventory module (Owner only): monitor stock, adjust quantities & thresholds. */
require_owner();
if (!empty($GLOBALS['__forbidden'])) { require __DIR__ . '/_forbidden.php'; return; }

$pdo = db();
$cfg = config();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $do = (string) input('do');

    if ($do === 'delete') {
        $id = (int) input('id');
        $stmt = $pdo->prepare('SELECT photo_path FROM products WHERE id = ?');
        $stmt->execute([$id]);
        if ($ph = $stmt->fetchColumn()) {
            @unlink($cfg['upload_dir'] . '/' . basename($ph));
        }
        $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        flash('Item deleted.');
        redirect('inventory');
    } elseif ($do === 'adjust') {
        // Add or remove stock by a delta
        $id    = (int) input('id');
        $delta = (int) input('delta');
        $pdo->prepare('UPDATE products SET stock_quantity = GREATEST(0, stock_quantity + ?), updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->execute([$delta, $id]);
        flash('Stock updated.');
    } elseif ($do === 'set') {
        // Set absolute stock + threshold
        $id    = (int) input('id');
        $stock = max(0, (int) input('stock_quantity'));
        $thr   = max(0, (int) input('low_stock_threshold'));
        $pdo->prepare('UPDATE products SET stock_quantity=?, low_stock_threshold=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')
            ->execute([$stock, $thr, $id]);
        flash('Inventory saved.');
    }
    redirect('inventory');
}

$products = $pdo->query("SELECT * FROM products ORDER BY (stock_quantity <= low_stock_threshold) DESC, category, name")->fetchAll();
$lowCount = 0;
foreach ($products as $p) { if ($p['stock_quantity'] <= $p['low_stock_threshold']) $lowCount++; }

$activePage = 'inventory';
$pageTitle  = 'Inventory';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h2>Inventory</h2>
  <div class="spacer"></div>
  <?php if ($lowCount): ?>
    <span class="badge low"><span class="material-symbols-outlined" style="font-size:16px">warning</span> <?= $lowCount ?> low-stock</span>
  <?php else: ?>
    <span class="badge ok"><span class="material-symbols-outlined" style="font-size:16px">check_circle</span> All stocked</span>
  <?php endif; ?>
</div>

<div class="table-wrap">
  <table class="data">
    <thead>
      <tr><th>Item</th><th>Category</th><th class="num">In stock</th><th class="num">Reorder at</th><th>Status</th><th>Quick adjust</th><th>Set</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($products as $p): $low = $p['stock_quantity'] <= $p['low_stock_threshold']; ?>
      <tr>
        <td><?= e($p['name']) ?></td>
        <td class="muted"><?= e($p['category']) ?></td>
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
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
