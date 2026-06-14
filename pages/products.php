<?php
/** Menu management (Owner only). Menu items are recipes composed of inventory items. */
require_owner();
if (!empty($GLOBALS['__forbidden'])) { require __DIR__ . '/_forbidden.php'; return; }

$pdo = db();
$cfg = config();
$action = (string) input('action', 'list');

/* ---------------- Handle POST (save / delete) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $do = (string) input('do');

    if ($do === 'delete') {
        $id = (int) input('id');
        // remove photo file if any
        $stmt = $pdo->prepare('SELECT photo_path FROM menu_items WHERE id = ?');
        $stmt->execute([$id]);
        if ($ph = $stmt->fetchColumn()) {
            @unlink($cfg['upload_dir'] . '/' . basename($ph));
        }
        // recipe rows cascade via FK
        $pdo->prepare('DELETE FROM menu_items WHERE id = ?')->execute([$id]);
        flash('Menu item deleted.');
        redirect('products');
    }

    // Create or update
    $id          = (int) input('id');
    $name        = trim((string) input('name'));
    $description = trim((string) input('description'));
    $category    = trim((string) input('category')) ?: 'General';
    $pricingMode = in_array(input('pricing_mode'), ['percentage', 'addon', 'manual'], true) ? input('pricing_mode') : 'percentage';
    $markupValue = max(0, (float) input('markup_value'));
    $price       = 0.0; // resolved below from cost+markup (or manual input)
    $isActive    = input('is_active') ? true : false;

    // Build a clean recipe map: inventory_item_id => quantity (merge dupes)
    $ingIds  = $_POST['ingredient_id'] ?? [];
    $ingQtys = $_POST['ingredient_qty'] ?? [];
    $recipe  = [];
    foreach ($ingIds as $i => $iid) {
        $iid = (int) $iid;
        $qty = (int) ($ingQtys[$i] ?? 0);
        if ($iid > 0 && $qty > 0) {
            $recipe[$iid] = ($recipe[$iid] ?? 0) + $qty;
        }
    }

    $errors = [];
    if ($name === '')  $errors[] = 'Name is required.';
    if (!$recipe)      $errors[] = 'Add at least one inventory item to the recipe.';

    // Validate that all referenced inventory items exist (and read their cost)
    $costMap = [];
    $cost    = 0.0;
    if ($recipe) {
        $in = implode(',', array_fill(0, count($recipe), '?'));
        $stmt = $pdo->prepare("SELECT id, unit_cost FROM inventory_items WHERE id IN ($in)");
        $stmt->execute(array_keys($recipe));
        foreach ($stmt->fetchAll() as $r) { $costMap[(int) $r['id']] = (float) $r['unit_cost']; }
        foreach ($recipe as $iid => $qty) {
            if (!isset($costMap[$iid])) { $errors[] = 'A selected inventory item no longer exists.'; break; }
            $cost += $costMap[$iid] * $qty;
        }
    }

    // The selling price is authoritative server-side: derived from cost+markup for
    // percentage/add-on modes, or taken from the owner's input for manual mode.
    if ($pricingMode === 'manual') {
        $price = max(0, (float) input('price'));
    } else {
        $price = (float) suggest_price($cost, $pricingMode, $markupValue);
    }
    if ($price < 0) $errors[] = 'Price cannot be negative.';

    // Handle photo upload
    $photoPath = null;
    $hasNewPhoto = !empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK;
    if ($hasNewPhoto) {
        $maxBytes = $cfg['max_upload_mb'] * 1024 * 1024;
        $allowed  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $mime = mime_content_type($_FILES['photo']['tmp_name']);
        if (!isset($allowed[$mime])) {
            $errors[] = 'Photo must be a JPG, PNG, WEBP, or GIF image.';
        } elseif ($_FILES['photo']['size'] > $maxBytes) {
            $errors[] = 'Photo is larger than ' . $cfg['max_upload_mb'] . ' MB.';
        } else {
            if (!is_dir($cfg['upload_dir'])) { @mkdir($cfg['upload_dir'], 0775, true); }
            if (!is_writable($cfg['upload_dir'])) {
                $errors[] = 'The uploads folder is not writable by the web server.';
            } else {
                $fname = 'item_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $cfg['upload_dir'] . '/' . $fname)) {
                    $photoPath = $cfg['upload_url'] . '/' . $fname;
                } else {
                    $errors[] = 'The photo could not be saved. Please try again.';
                }
            }
        }
    }

    if ($errors) {
        flash(implode(' ', $errors), 'error');
        redirect('products', ['action' => $id ? 'edit' : 'new'] + ($id ? ['id' => $id] : []));
    }

    try {
        $pdo->beginTransaction();

        if ($id) {
            $sql = 'UPDATE menu_items SET name=?, description=?, category=?, pricing_mode=?, markup_value=?, price=?, is_active=?, updated_at=CURRENT_TIMESTAMP';
            $params = [$name, $description, $category, $pricingMode, $markupValue, $price, $isActive ? 1 : 0];
            if ($photoPath) { $sql .= ', photo_path=?'; $params[] = $photoPath; }
            $sql .= ' WHERE id=?'; $params[] = $id;
            $pdo->prepare($sql)->execute($params);
        } else {
            $pdo->prepare('INSERT INTO menu_items (name, description, category, pricing_mode, markup_value, price, is_active, photo_path)
                           VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$name, $description, $category, $pricingMode, $markupValue, $price, $isActive ? 1 : 0, $photoPath]);
            $id = (int) $pdo->lastInsertId();
        }

        // Replace recipe rows
        $pdo->prepare('DELETE FROM menu_item_ingredients WHERE menu_item_id = ?')->execute([$id]);
        $ins = $pdo->prepare('INSERT INTO menu_item_ingredients (menu_item_id, inventory_item_id, quantity) VALUES (?,?,?)');
        foreach ($recipe as $iid => $qty) {
            $ins->execute([$id, $iid, $qty]);
        }

        $pdo->commit();
        flash('“' . $name . '” saved.');
    } catch (Throwable $ex) {
        $pdo->rollBack();
        flash('Could not save menu item: ' . $ex->getMessage(), 'error');
        redirect('products', ['action' => $id ? 'edit' : 'new'] + ($id ? ['id' => $id] : []));
    }
    redirect('products');
}

/* ---------------- Render form (new / edit) ---------------- */
if ($action === 'new' || $action === 'edit') {
    $item = ['id' => 0, 'name' => '', 'description' => '', 'category' => '',
             'pricing_mode' => 'percentage', 'markup_value' => '', 'price' => '',
             'is_active' => true, 'photo_path' => null];
    $recipe = []; // [['inventory_item_id'=>, 'quantity'=>], ...]
    if ($action === 'edit') {
        $stmt = $pdo->prepare('SELECT * FROM menu_items WHERE id = ?');
        $stmt->execute([(int) input('id')]);
        $item = $stmt->fetch() ?: $item;
        $rs = $pdo->prepare('SELECT inventory_item_id, quantity FROM menu_item_ingredients WHERE menu_item_id = ? ORDER BY id');
        $rs->execute([(int) $item['id']]);
        $recipe = $rs->fetchAll();
    }

    // All active inventory items for the recipe dropdowns
    $invItems = $pdo->query('SELECT id, name, unit, unit_cost FROM inventory_items WHERE is_active = TRUE ORDER BY category, name')->fetchAll();

    $activePage = 'products';
    $pageTitle  = $action === 'edit' ? 'Edit menu item' : 'New menu item';
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="page-head">
      <h2><?= $action === 'edit' ? 'Edit Menu Item' : 'Add Menu Item' ?></h2>
    </div>
    <form class="card" method="post" action="<?= e(url('products')) ?>" enctype="multipart/form-data" style="max-width:820px" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
      <div class="form-grid">
        <div class="form-row" style="grid-column:1/-1">
          <md-outlined-text-field label="Item name" name="name" required value="<?= e($item['name']) ?>"></md-outlined-text-field>
        </div>
        <div class="form-row" style="grid-column:1/-1">
          <md-outlined-text-field type="textarea" rows="2" label="Description" name="description"
              value="<?= e($item['description']) ?>"></md-outlined-text-field>
        </div>
        <div class="form-row">
          <md-outlined-text-field label="Category" name="category" value="<?= e($item['category']) ?>"
              placeholder="e.g. Chicken"></md-outlined-text-field>
        </div>
        <div class="form-row" style="grid-column:1/-1">
          <label><input type="checkbox" name="is_active" value="1" <?= $item['is_active'] ? 'checked' : '' ?>> Available on the menu</label>
        </div>
      </div>

      <!-- ---- Recipe builder ---- -->
      <h3 style="margin:18px 0 8px">Recipe — inventory items used</h3>
      <p class="hint" style="margin-top:0">The total cost is the sum of each ingredient's cost × quantity.</p>
      <?php if (!$invItems): ?>
        <div class="flash flash-error"><span class="material-symbols-outlined">error</span>
          <span>No inventory items exist yet. <a href="<?= e(url('inventory', ['action'=>'new'])) ?>">Add raw materials</a> before building a menu item.</span></div>
      <?php endif; ?>
      <div id="recipeRows"></div>
      <md-outlined-button type="button" id="addRowBtn" has-icon style="margin-top:6px">
        <span class="material-symbols-outlined" slot="icon">add</span> Add ingredient
      </md-outlined-button>

      <div class="cart-total" style="margin-top:14px"><span>Total cost</span><span id="totalCost"><?= money(0) ?></span></div>

      <!-- ---- Pricing ---- -->
      <h3 style="margin:18px 0 8px">Pricing</h3>
      <div class="form-row" style="display:flex;gap:18px;flex-wrap:wrap;align-items:center">
        <label><input type="radio" name="pricing_mode" value="percentage" <?= $item['pricing_mode']==='percentage'?'checked':'' ?>> % markup</label>
        <label><input type="radio" name="pricing_mode" value="addon" <?= $item['pricing_mode']==='addon'?'checked':'' ?>> Cost add-on</label>
        <label><input type="radio" name="pricing_mode" value="manual" <?= $item['pricing_mode']==='manual'?'checked':'' ?>> Manual price</label>
      </div>
      <div class="form-grid" style="margin-top:10px">
        <div class="form-row" id="markupRow">
          <md-outlined-text-field id="markupField" label="Markup" name="markup_value" type="number" step="0.01" min="0"
              value="<?= e($item['markup_value'] !== '' ? $item['markup_value'] : '') ?>"></md-outlined-text-field>
          <small class="hint" id="suggestHint"></small>
        </div>
        <div class="form-row">
          <md-outlined-text-field id="priceField" label="Selling price (<?= e($cfg['currency_symbol']) ?>)" name="price" type="number" step="0.01" min="0"
              required value="<?= e($item['price']) ?>"></md-outlined-text-field>
          <small class="hint">This is the price charged at the POS. Override the suggestion if you wish.</small>
        </div>
      </div>

      <!-- ---- Photo ---- -->
      <div class="form-row" style="grid-column:1/-1;margin-top:14px">
        <label class="hint">Photo of menu item</label>
        <?php if (!empty($item['photo_path'])): ?>
          <img src="<?= e($item['photo_path']) ?>" alt="" style="height:90px;width:120px;object-fit:cover;border-radius:10px;border:1px solid var(--line)">
          <small class="hint">Upload a new file to replace the current photo.</small>
        <?php endif; ?>
        <input type="file" name="photo" accept="image/*">
      </div>

      <div class="form-actions" style="margin-top:18px">
        <md-filled-button type="button" has-icon onclick="if(confirm('Save changes to this menu item?')) this.closest('form').submit()">
          <span class="material-symbols-outlined" slot="icon">save</span> Save
        </md-filled-button>
        <a href="<?= e(url('products')) ?>" style="text-decoration:none">
          <md-outlined-button>Cancel</md-outlined-button>
        </a>
      </div>
    </form>

    <script>
    const CURRENCY = <?= json_encode($cfg['currency_symbol']) ?>;
    const INV = <?= json_encode(array_map(fn($r) => [
        'id' => (int) $r['id'], 'name' => $r['name'], 'unit' => $r['unit'], 'cost' => (float) $r['unit_cost']
    ], $invItems)) ?>;
    const EXISTING = <?= json_encode(array_map(fn($r) => [
        'id' => (int) $r['inventory_item_id'], 'qty' => (int) $r['quantity']
    ], $recipe)) ?>;

    function fmt(n){ return CURRENCY + (n||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}); }

    function optionsHtml(selectedId){
      return INV.map(i => `<option value="${i.id}" data-cost="${i.cost}" ${i.id===selectedId?'selected':''}>${i.name} (${fmt(i.cost)}/${i.unit})</option>`).join('');
    }

    function addRow(selId, qty){
      const wrap = document.getElementById('recipeRows');
      const row = document.createElement('div');
      row.className = 'recipe-row';
      row.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:8px';
      row.innerHTML = `
        <select name="ingredient_id[]" class="ing-select" style="flex:1;padding:9px;border:1px solid var(--line);border-radius:8px;background:var(--bg)">
          ${optionsHtml(selId)}
        </select>
        <input type="number" name="ingredient_qty[]" class="ing-qty" min="1" value="${qty||1}" style="width:80px;padding:9px;border:1px solid var(--line);border-radius:8px" title="Quantity">
        <span class="ing-line muted" style="width:90px;text-align:right"></span>
        <button type="button" class="ing-rm" title="Remove" style="border:none;background:none;cursor:pointer;color:var(--bad);display:flex;align-items:center"><span class="material-symbols-outlined">close</span></button>`;
      wrap.appendChild(row);
      recompute();
    }

    function recompute(){
      let total = 0;
      document.querySelectorAll('.recipe-row').forEach(row => {
        const sel = row.querySelector('.ing-select');
        const opt = sel.options[sel.selectedIndex];
        const cost = opt ? parseFloat(opt.dataset.cost) : 0;
        const qty = parseInt(row.querySelector('.ing-qty').value, 10) || 0;
        const line = cost * qty;
        total += line;
        row.querySelector('.ing-line').textContent = fmt(line);
      });
      document.getElementById('totalCost').textContent = fmt(total);
      updateSuggestion(total);
      return total;
    }

    function updateSuggestion(cost){
      const mode = document.querySelector('input[name=pricing_mode]:checked').value;
      const markupField = document.getElementById('markupField');
      const markupRow = document.getElementById('markupRow');
      const hint = document.getElementById('suggestHint');
      const priceField = document.getElementById('priceField');
      const markup = parseFloat(markupField.value) || 0;

      if (mode === 'manual'){
        // Owner sets the price directly; markup is irrelevant.
        markupRow.style.display = 'none';
        hint.textContent = '';
        priceField.readOnly = false;
        priceField.label = 'Selling price (' + CURRENCY + ')';
        return;
      }

      // Percentage / add-on: price is derived live and not hand-editable.
      markupRow.style.display = '';
      markupField.label = (mode === 'percentage') ? 'Markup %' : ('Cost add-on (' + CURRENCY + ')');
      const suggested = (mode === 'percentage') ? cost * (1 + markup/100) : cost + markup;
      const rounded = Math.round(suggested * 100) / 100;
      hint.textContent = 'Projected from cost ' + fmt(cost) + (mode === 'percentage' ? ' + ' + markup + '%' : ' + ' + fmt(markup));
      priceField.readOnly = true;
      priceField.label = 'Projected selling price (' + CURRENCY + ')';
      priceField.value = rounded.toFixed(2);
    }

    document.getElementById('addRowBtn').addEventListener('click', () => { if (INV.length) addRow(); });
    document.getElementById('recipeRows').addEventListener('input', recompute);
    document.getElementById('recipeRows').addEventListener('change', recompute);
    document.getElementById('recipeRows').addEventListener('click', e => {
      const rm = e.target.closest('.ing-rm'); if (!rm) return;
      rm.closest('.recipe-row').remove(); recompute();
    });
    document.querySelectorAll('input[name=pricing_mode]').forEach(r =>
      r.addEventListener('change', () => recompute()));
    // Re-project the price live as the owner types a markup / add-on
    document.getElementById('markupField').addEventListener('input', () => recompute());

    // Seed existing recipe rows (or one empty row for a new item)
    if (EXISTING.length){
      EXISTING.forEach(r => addRow(r.id, r.qty));
    } else if (INV.length){
      addRow();
    }
    recompute();
    </script>
    <?php
    require __DIR__ . '/../includes/footer.php';
    return;
}

/* ---------------- List view ---------------- */
$q = trim((string) input('q'));
$where = $q !== '' ? "WHERE m.name LIKE ? OR m.category LIKE ?" : "";
$sql = "
    SELECT m.*,
           COALESCE(SUM(ii.unit_cost * mi.quantity), 0) AS cost,
           COUNT(mi.id)                                 AS ingredient_count,
           MIN(FLOOR(ii.stock_quantity / mi.quantity))  AS can_make
    FROM menu_items m
    LEFT JOIN menu_item_ingredients mi ON mi.menu_item_id = m.id
    LEFT JOIN inventory_items ii       ON ii.id = mi.inventory_item_id
    $where
    GROUP BY m.id
    ORDER BY m.category, m.name";
if ($q !== '') {
    $stmt = $pdo->prepare($sql);
    $stmt->execute(["%$q%", "%$q%"]);
    $products = $stmt->fetchAll();
} else {
    $products = $pdo->query($sql)->fetchAll();
}

$activePage = 'products';
$pageTitle  = 'Menu';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h2>Menu &amp; Products</h2>
  <div class="spacer"></div>
  <form method="get" action="index.php" style="display:flex;gap:8px;align-items:center" autocomplete="off">
    <input type="hidden" name="page" value="products">
    <md-outlined-text-field label="Search" name="q" value="<?= e($q) ?>" style="min-width:200px">
      <span class="material-symbols-outlined" slot="leading-icon">search</span>
    </md-outlined-text-field>
    <md-outlined-button type="submit" onclick="this.closest('form').submit()">Search</md-outlined-button>
  </form>
  <a href="<?= e(url('products', ['action' => 'new'])) ?>" style="text-decoration:none">
    <md-filled-button has-icon>
      <span class="material-symbols-outlined" slot="icon">add</span> Add Item
    </md-filled-button>
  </a>
</div>

<?php if (!$products): ?>
  <div class="card empty"><span class="material-symbols-outlined">restaurant</span><br>No menu items yet. Add your first one.</div>
<?php else: ?>
<div class="cards-grid">
  <?php foreach ($products as $p):
      $cost   = (float) $p['cost'];
      $price  = (float) $p['price'];
      $margin = $price - $cost;
      $hasRecipe = (int) $p['ingredient_count'] > 0;
      $canMake = $hasRecipe ? (int) $p['can_make'] : 0;
  ?>
    <div class="card menu-card">
      <div class="photo">
        <?php if (!empty($p['photo_path'])): ?>
          <img src="<?= e($p['photo_path']) ?>" alt="<?= e($p['name']) ?>">
        <?php else: ?>
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="52" height="52" aria-hidden="true" style="color:var(--md-sys-color-outline)">
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
        <?php endif; ?>
      </div>
      <div class="body">
        <div class="row">
          <h3 style="flex:1"><?= e($p['name']) ?></h3>
          <?php if (!$p['is_active']): ?><span class="badge pending">Hidden</span><?php endif; ?>
        </div>
        <div class="row">
          <span class="price"><?= money($price) ?></span>
          <span class="hint">· <?= e($p['category']) ?></span>
        </div>
        <div class="desc"><?= e($p['description'] ?: '—') ?></div>
        <div class="row" style="gap:6px">
          <span class="hint">Cost <?= money($cost) ?></span>
          <span class="badge <?= $margin > 0 ? 'ok' : 'low' ?>">Margin <?= money($margin) ?></span>
        </div>
        <div class="row">
          <?php if (!$hasRecipe): ?>
            <span class="badge low"><span class="material-symbols-outlined" style="font-size:16px">warning</span> No recipe</span>
          <?php else: ?>
            <span class="badge <?= $canMake > 0 ? 'ok' : 'low' ?>">
              <span class="material-symbols-outlined" style="font-size:16px">inventory_2</span>
              <?= $canMake ?> can make
            </span>
          <?php endif; ?>
        </div>
      </div>
      <div class="actions">
        <a href="<?= e(url('products', ['action' => 'edit', 'id' => $p['id']])) ?>" style="text-decoration:none;flex:1">
          <md-outlined-button style="width:100%">Edit</md-outlined-button>
        </a>
        <form method="post" action="<?= e(url('products')) ?>" autocomplete="off" style="margin:0;padding:0;display:flex">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="delete">
          <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <button type="button" class="btn-delete-item"
            data-confirm="Delete &quot;<?= e($p['name']) ?>&quot;? This cannot be undone."
            style="border:none;background:none;cursor:pointer;padding:8px;color:var(--bad);display:flex;align-items:center;justify-content:center;border-radius:8px;line-height:1">
            <span class="material-symbols-outlined" style="font-size:22px;pointer-events:none">delete</span>
          </button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<script>
document.querySelectorAll('.btn-delete-item').forEach(function(btn) {
  btn.addEventListener('click', function() {
    if (confirm(this.dataset.confirm)) {
      this.closest('form').submit();
    }
  });
});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
