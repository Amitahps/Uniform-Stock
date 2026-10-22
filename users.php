<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/company.php';
require_once __DIR__ . '/includes/layout.php';

$user = us_require_admin();
$pdo = us_db();
$error = '';
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editUser = $editId > 0 ? us_get_user($pdo, $editId) : null;
if ($editUser && ($editUser['role'] ?? '') !== 'staff') {
    $editUser = null;
    $editId = 0;
}
$editCompanyIds = $editUser ? us_user_company_ids($pdo, (int) $editUser['id']) : [];

$allCompanies = us_list_companies($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'create') {
            $companyIds = $_POST['company_ids'] ?? [];
            if (!is_array($companyIds)) {
                $companyIds = [];
            }
            us_create_staff_user(
                $pdo,
                (string) ($_POST['username'] ?? ''),
                (string) ($_POST['password'] ?? ''),
                $companyIds
            );
            us_flash_set('ok', 'Staff user created.');
            header('Location: ' . us_url('users.php'));
            exit;
        }
        if ($action === 'update_assignments') {
            $uid = (int) ($_POST['user_id'] ?? 0);
            $companyIds = $_POST['company_ids'] ?? [];
            if (!is_array($companyIds)) {
                $companyIds = [];
            }
            us_update_staff_assignments($pdo, $uid, $companyIds);
            us_flash_set('ok', 'Company assignments updated.');
            header('Location: ' . us_url('users.php'));
            exit;
        }
        if ($action === 'reset_password') {
            $uid = (int) ($_POST['user_id'] ?? 0);
            us_reset_user_password($pdo, $uid, (string) ($_POST['password'] ?? ''));
            us_flash_set('ok', 'Password reset.');
            header('Location: ' . us_url('users.php?edit=' . $uid));
            exit;
        }
        if ($action === 'delete') {
            $uid = (int) ($_POST['user_id'] ?? 0);
            us_delete_staff_user($pdo, $uid);
            us_flash_set('ok', 'Staff user deleted.');
            header('Location: ' . us_url('users.php'));
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($action === 'update_assignments' || $action === 'reset_password') {
            $editId = (int) ($_POST['user_id'] ?? 0);
            $editUser = $editId > 0 ? us_get_user($pdo, $editId) : null;
            if ($editUser && ($editUser['role'] ?? '') === 'staff') {
                $editCompanyIds = us_user_company_ids($pdo, (int) $editUser['id']);
            } else {
                $editUser = null;
            }
        }
    }
}

$staff = us_list_staff_users($pdo);
$companyId = us_current_company_id();
$company = $companyId ? us_get_company($pdo, $companyId) : null;
$school = us_current_school();

us_header('Users', $user, $company, $school);
?>
<p class="lead">
  Create <strong>staff</strong> logins, assign them to one or more companies, and reset passwords.
  Staff cannot access Companies, Backup, Password, or Users.
</p>
<?php if ($error !== ''): ?>
  <div class="flash flash-error"><?= us_h($error) ?></div>
<?php endif; ?>

<?php if ($editUser): ?>
<section style="margin-bottom:1.5rem;padding:1rem;border:1px solid var(--line);border-radius:12px;background:#fff">
  <h2 style="font-size:1.1rem;margin:0 0 0.75rem">Edit <?= us_h($editUser['username']) ?></h2>
  <form method="post" class="grid" style="margin-bottom:1.25rem">
    <input type="hidden" name="action" value="update_assignments">
    <input type="hidden" name="user_id" value="<?= (int) $editUser['id'] ?>">
    <fieldset style="border:1px solid var(--line);border-radius:10px;padding:0.75rem 1rem">
      <legend>Assigned companies</legend>
      <?php if ($allCompanies === []): ?>
        <p class="muted">Create a company first.</p>
      <?php else: ?>
        <?php foreach ($allCompanies as $c): ?>
          <label style="font-weight:500;display:flex;gap:0.5rem;align-items:center;margin:0.35rem 0">
            <input type="checkbox" name="company_ids[]" value="<?= (int) $c['id'] ?>"
              <?= in_array((int) $c['id'], $editCompanyIds, true) ? 'checked' : '' ?>
              style="width:auto">
            <?= us_h($c['name']) ?>
          </label>
        <?php endforeach; ?>
      <?php endif; ?>
    </fieldset>
    <div class="actions">
      <button type="submit">Save assignments</button>
      <a class="btn secondary" href="<?= us_h(us_url('users.php')) ?>">Done</a>
    </div>
  </form>
  <form method="post" class="grid" style="max-width:420px">
    <input type="hidden" name="action" value="reset_password">
    <input type="hidden" name="user_id" value="<?= (int) $editUser['id'] ?>">
    <div>
      <label for="password">Reset password</label>
      <input id="password" name="password" type="password" required minlength="6" autocomplete="new-password">
    </div>
    <div class="actions">
      <button type="submit">Set new password</button>
    </div>
  </form>
</section>
<?php endif; ?>

<section class="grid grid-2">
  <div>
    <h2 style="font-size:1.1rem;margin:0 0 0.75rem">Create staff user</h2>
    <form method="post" class="grid">
      <input type="hidden" name="action" value="create">
      <div>
        <label for="username">Username</label>
        <input id="username" name="username" required autocomplete="off" placeholder="e.g. ravi">
      </div>
      <div>
        <label for="new_password">Password</label>
        <input id="new_password" name="password" type="password" required minlength="6" autocomplete="new-password">
      </div>
      <fieldset style="border:1px solid var(--line);border-radius:10px;padding:0.75rem 1rem">
        <legend>Assign companies</legend>
        <?php if ($allCompanies === []): ?>
          <p class="muted">Create a company first on the Companies page.</p>
        <?php else: ?>
          <?php foreach ($allCompanies as $c): ?>
            <label style="font-weight:500;display:flex;gap:0.5rem;align-items:center;margin:0.35rem 0">
              <input type="checkbox" name="company_ids[]" value="<?= (int) $c['id'] ?>" style="width:auto">
              <?= us_h($c['name']) ?>
            </label>
          <?php endforeach; ?>
        <?php endif; ?>
      </fieldset>
      <div class="actions">
        <button type="submit" <?= $allCompanies === [] ? 'disabled' : '' ?>>Create staff</button>
      </div>
    </form>
  </div>
  <div>
    <h2 style="font-size:1.1rem;margin:0 0 0.75rem">Staff users</h2>
    <?php if ($staff === []): ?>
      <p class="muted">No staff users yet.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Username</th><th>Companies</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($staff as $s):
              $cids = us_user_company_ids($pdo, (int) $s['id']);
              $names = [];
              foreach ($allCompanies as $c) {
                  if (in_array((int) $c['id'], $cids, true)) {
                      $names[] = (string) $c['name'];
                  }
              }
          ?>
            <tr>
              <td><?= us_h($s['username']) ?></td>
              <td class="muted"><?= us_h($names === [] ? '—' : implode(', ', $names)) ?></td>
              <td class="row-actions">
                <a class="btn secondary" href="<?= us_h(us_url('users.php?edit=' . (int) $s['id'])) ?>">Edit</a>
                <form method="post" style="display:inline" onsubmit="return confirm('Delete staff user <?= us_h($s['username']) ?>?');">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="user_id" value="<?= (int) $s['id'] ?>">
                  <button type="submit" class="secondary danger-btn">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>
<?php us_footer(); ?>
