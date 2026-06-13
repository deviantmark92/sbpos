<?php
/** User management (Owner only): create/manage owner & cashier accounts. */
require_owner();
if (!empty($GLOBALS['__forbidden'])) { require __DIR__ . '/_forbidden.php'; return; }

$pdo  = db();
$me   = current_user();
$action = (string) input('action', 'list');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $do = (string) input('do');

    if ($do === 'delete') {
        $id = (int) input('id');
        if ($id === (int) $me['id']) {
            flash('You cannot delete your own account.', 'error');
        } else {
            $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
            flash('User removed.');
        }
        redirect('users');
    }

    if ($do === 'toggle') {
        $id = (int) input('id');
        if ($id !== (int) $me['id']) {
            $pdo->prepare('UPDATE users SET is_active = NOT is_active WHERE id=?')->execute([$id]);
            flash('User status changed.');
        }
        redirect('users');
    }

    // create / update
    $id       = (int) input('id');
    $username = strtolower(trim((string) input('username')));
    $fullName = trim((string) input('full_name'));
    $role     = input('role') === 'owner' ? 'owner' : 'cashier';
    $password = (string) input('password');

    $errors = [];
    if ($username === '' || !preg_match('/^[a-z0-9_.]{3,50}$/', $username)) {
        $errors[] = 'Username must be 3–50 chars (letters, numbers, _ or .).';
    }
    if ($fullName === '') $errors[] = 'Full name is required.';
    if (!$id && strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';

    // unique username
    $chk = $pdo->prepare('SELECT id FROM users WHERE username=? AND id <> ?');
    $chk->execute([$username, $id]);
    if ($chk->fetch()) $errors[] = 'That username is already taken.';

    if ($errors) {
        flash(implode(' ', $errors), 'error');
        redirect('users', ['action' => $id ? 'edit' : 'new'] + ($id ? ['id' => $id] : []));
    }

    if ($id) {
        if (strlen($password) >= 6) {
            $pdo->prepare('UPDATE users SET username=?, full_name=?, role=?, password_hash=? WHERE id=?')
                ->execute([$username, $fullName, $role, password_hash($password, PASSWORD_BCRYPT), $id]);
        } else {
            $pdo->prepare('UPDATE users SET username=?, full_name=?, role=? WHERE id=?')
                ->execute([$username, $fullName, $role, $id]);
        }
        flash('User updated.');
    } else {
        $pdo->prepare('INSERT INTO users (username, full_name, role, password_hash) VALUES (?,?,?,?)')
            ->execute([$username, $fullName, $role, password_hash($password, PASSWORD_BCRYPT)]);
        flash('User “' . $username . '” created.');
    }
    redirect('users');
}

/* ---- Form view ---- */
if ($action === 'new' || $action === 'edit') {
    $u = ['id' => 0, 'username' => '', 'full_name' => '', 'role' => 'cashier'];
    if ($action === 'edit') {
        $stmt = $pdo->prepare('SELECT id, username, full_name, role FROM users WHERE id=?');
        $stmt->execute([(int) input('id')]);
        $u = $stmt->fetch() ?: $u;
    }
    $activePage = 'users';
    $pageTitle  = $action === 'edit' ? 'Edit user' : 'New user';
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="page-head"><h2><?= $action === 'edit' ? 'Edit User' : 'Add User' ?></h2></div>
    <form class="card" method="post" action="<?= e(url('users')) ?>" style="max-width:560px" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
      <div class="form-grid">
        <div class="form-row"><md-outlined-text-field label="Full name" name="full_name" required value="<?= e($u['full_name']) ?>"></md-outlined-text-field></div>
        <div class="form-row"><md-outlined-text-field label="Username" name="username" required value="<?= e($u['username']) ?>"></md-outlined-text-field></div>
        <div class="form-row">
          <label class="hint">Role</label>
          <label><input type="radio" name="role" value="cashier" <?= $u['role']==='cashier'?'checked':'' ?>> Cashier</label>
          <label><input type="radio" name="role" value="owner" <?= $u['role']==='owner'?'checked':'' ?>> Owner</label>
        </div>
        <div class="form-row">
          <md-outlined-text-field label="<?= $action==='edit' ? 'New password (leave blank to keep)' : 'Password' ?>"
              name="password" type="password" <?= $action==='edit' ? '' : 'required' ?>></md-outlined-text-field>
        </div>
      </div>
      <div class="form-actions">
        <md-filled-button type="submit" has-icon onclick="this.closest('form').requestSubmit()">
          <span class="material-symbols-outlined" slot="icon">save</span> Save</md-filled-button>
        <a href="<?= e(url('users')) ?>" style="text-decoration:none"><md-outlined-button>Cancel</md-outlined-button></a>
      </div>
    </form>
    <?php require __DIR__ . '/../includes/footer.php'; return;
}

/* ---- List ---- */
$users = $pdo->query('SELECT id, username, full_name, role, is_active, created_at FROM users ORDER BY role, username')->fetchAll();

$activePage = 'users';
$pageTitle  = 'Users';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h2>User Management</h2>
  <div class="spacer"></div>
  <a href="<?= e(url('users', ['action' => 'new'])) ?>" style="text-decoration:none">
    <md-filled-button has-icon><span class="material-symbols-outlined" slot="icon">person_add</span> Add User</md-filled-button>
  </a>
</div>

<div class="table-wrap">
  <table class="data">
    <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= e($u['full_name']) ?><?= $u['id']==$me['id'] ? ' <span class="hint">(you)</span>' : '' ?></td>
        <td class="muted"><?= e($u['username']) ?></td>
        <td><span class="badge <?= $u['role']==='owner'?'paid':'ok' ?>"><?= e(ucfirst($u['role'])) ?></span></td>
        <td><?= $u['is_active'] ? '<span class="badge ok">Active</span>' : '<span class="badge low">Disabled</span>' ?></td>
        <td>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <a class="plain" href="<?= e(url('users', ['action'=>'edit','id'=>$u['id']])) ?>">Edit</a>
            <?php if ($u['id'] != $me['id']): ?>
              <form method="post" action="<?= e(url('users')) ?>" style="display:inline" autocomplete="off">
                <?= csrf_field() ?><input type="hidden" name="do" value="toggle"><input type="hidden" name="id" value="<?= $u['id'] ?>">
                <button type="submit" class="plain" style="border:none;background:none;cursor:pointer;color:var(--brown)"><?= $u['is_active']?'Disable':'Enable' ?></button>
              </form>
              <form method="post" action="<?= e(url('users')) ?>" style="display:inline" autocomplete="off" onsubmit="return confirm('Delete this user?')">
                <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $u['id'] ?>">
                <button type="submit" class="plain" style="border:none;background:none;cursor:pointer;color:var(--bad)">Delete</button>
              </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
