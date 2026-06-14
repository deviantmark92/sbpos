<?php
/** Menu / Product management (Owner only). Add, edit, delete, search products incl. photo. */
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
        $stmt = $pdo->prepare('SELECT photo_path FROM products WHERE id = ?');
        $stmt->execute([$id]);
        if ($ph = $stmt->fetchColumn()) {
            @unlink($cfg['upload_dir'] . '/' . basename($ph));
        }
        $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        flash('Menu item deleted.');
        redirect('products');
    }

    // Create or update
    $id          = (int) input('id');
    $name        = trim((string) input('name'));
    $description = trim((string) input('description'));
    $category    = trim((string) input('category')) ?: 'General';
    $price       = (float) input('price');
    $stock       = max(0, (int) input('stock_quantity'));
    $threshold   = max(0, (int) input('low_stock_threshold'));
    $isActive    = input('is_active') ? true : false;

    $errors = [];
    if ($name === '')      $errors[] = 'Name is required.';
    if ($price < 0)        $errors[] = 'Price cannot be negative.';

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
            $fname = 'item_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
            move_uploaded_file($_FILES['photo']['tmp_name'], $cfg['upload_dir'] . '/' . $fname);
            $photoPath = $cfg['upload_url'] . '/' . $fname;
        }
    }

    if ($errors) {
        flash(implode(' ', $errors), 'error');
        redirect('products', ['action' => $id ? 'edit' : 'new'] + ($id ? ['id' => $id] : []));
    }

    if ($id) {
        // Update; only replace photo if a new one was uploaded
        $sql = 'UPDATE products SET name=?, description=?, category=?, price=?, stock_quantity=?,
                low_stock_threshold=?, is_active=?, updated_at=CURRENT_TIMESTAMP';
        $params = [$name, $description, $category, $price, $stock, $threshold, $isActive ? 1 : 0];
        if ($photoPath) { $sql .= ', photo_path=?'; $params[] = $photoPath; }
        $sql .= ' WHERE id=?'; $params[] = $id;
        $pdo->prepare($sql)->execute($params);
        flash('“' . $name . '” updated.');
    } else {
        $pdo->prepare('INSERT INTO products (name, description, category, price, stock_quantity, low_stock_threshold, is_active, photo_path)
                       VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$name, $description, $category, $price, $stock, $threshold, $isActive ? 1 : 0, $photoPath]);
        flash('“' . $name . '” added to the menu.');
    }
    redirect('products');
}

/* ---------------- Render form (new / edit) ---------------- */
if ($action === 'new' || $action === 'edit') {
    $item = ['id' => 0, 'name' => '', 'description' => '', 'category' => '', 'price' => '',
             'stock_quantity' => 0, 'low_stock_threshold' => 5, 'is_active' => true, 'photo_path' => null];
    if ($action === 'edit') {
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([(int) input('id')]);
        $item = $stmt->fetch() ?: $item;
    }
    $activePage = 'products';
    $pageTitle  = $action === 'edit' ? 'Edit menu item' : 'New menu item';
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="page-head">
      <h2><?= $action === 'edit' ? 'Edit Menu Item' : 'Add Menu Item' ?></h2>
    </div>
    <form class="card" method="post" action="<?= e(url('products')) ?>" enctype="multipart/form-data" style="max-width:760px" autocomplete="off">
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
        <div class="form-row">
          <md-outlined-text-field label="Price (<?= e($cfg['currency_symbol']) ?>)" name="price" type="number" step="0.01" min="0"
              required value="<?= e($item['price']) ?>"></md-outlined-text-field>
        </div>
        <div class="form-row">
          <md-outlined-text-field label="Stock quantity" name="stock_quantity" type="number" min="0"
              value="<?= (int) $item['stock_quantity'] ?>"></md-outlined-text-field>
        </div>
        <div class="form-row">
          <md-outlined-text-field label="Low-stock alert at" name="low_stock_threshold" type="number" min="0"
              value="<?= (int) $item['low_stock_threshold'] ?>"></md-outlined-text-field>
        </div>
        <div class="form-row" style="grid-column:1/-1">
          <label class="hint">Photo of menu item</label>
          <?php if (!empty($item['photo_path'])): ?>
            <img src="<?= e($item['photo_path']) ?>" alt="" style="height:90px;width:120px;object-fit:cover;border-radius:10px;border:1px solid var(--line)">
            <small class="hint">Upload a new file to replace the current photo.</small>
          <?php endif; ?>
          <input type="file" name="photo" accept="image/*">
        </div>
        <div class="form-row" style="grid-column:1/-1">
          <label><input type="checkbox" name="is_active" value="1" <?= $item['is_active'] ? 'checked' : '' ?>> Available on the menu</label>
        </div>
      </div>
      <div class="form-actions">
        <md-filled-button type="button" has-icon onclick="if(confirm('Save changes to this menu item?')) this.closest('form').submit()">
          <span class="material-symbols-outlined" slot="icon">save</span> Save
        </md-filled-button>
        <a href="<?= e(url('products')) ?>" style="text-decoration:none">
          <md-outlined-button>Cancel</md-outlined-button>
        </a>
      </div>
    </form>
    <?php
    require __DIR__ . '/../includes/footer.php';
    return;
}

/* ---------------- List view ---------------- */
$q = trim((string) input('q'));
if ($q !== '') {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE name LIKE ? OR category LIKE ? ORDER BY category, name");
    $stmt->execute(["%$q%", "%$q%"]);
    $products = $stmt->fetchAll();
} else {
    $products = $pdo->query("SELECT * FROM products ORDER BY category, name")->fetchAll();
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
  <?php foreach ($products as $p): ?>
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
          <span class="price"><?= money($p['price']) ?></span>
          <span class="hint">· <?= e($p['category']) ?></span>
        </div>
        <div class="desc"><?= e($p['description'] ?: '—') ?></div>
        <div class="row">
          <span class="badge <?= $p['stock_quantity'] <= $p['low_stock_threshold'] ? 'low' : 'ok' ?>">
            <span class="material-symbols-outlined" style="font-size:16px">inventory_2</span>
            <?= (int) $p['stock_quantity'] ?> in stock
          </span>
        </div>
      </div>
      <div class=”actions”>
        <a href=”<?= e(url('products', ['action' => 'edit', 'id' => $p['id']])) ?>” style=”text-decoration:none;flex:1”>
          <md-outlined-button style=”width:100%”>Edit</md-outlined-button>
        </a>
        <form method=”post” action=”<?= e(url('products')) ?>” autocomplete=”off” style=”margin:0;padding:0;display:flex”>
          <?= csrf_field() ?>
          
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
