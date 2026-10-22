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
$currentCompanyId = us_current_company_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'create') {
            $companyIds = $_POST['company_ids'] ?? [];
            if (!is_array($companyIds)) {
                $companyIds = [];
            }
            if ($companyIds === [] && count($allCompanies) === 1) {
                $companyIds = [(int) $allCompanies[0]['id']];
            }
            $username = trim((string) ($_POST['username'] ?? ''));
            $hasPower = !empty($_POST['has_power']);
            us_create_staff_user(
                $pdo,
                $username,
                (string) ($_POST['password'] ?? ''),
                $companyIds,
                $hasPower
            );
            us_flash_set(
                'ok',
                'Staff user "' . $username . '" created'
                . ($hasPower ? ' with power (can remove global items + issue to company).' : '.')
                . ' Log out, then sign in as that username to test.'
            );
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
            us_set_staff_power($pdo, $uid, !empty($_POST['has_power']));
            us_flash_set('ok', 'Company assignments and power updated.');
            header('Location: ' . us_url('users.php?edit=' . $uid));
            exit;
        }
        if ($action === 'reset_password') {
            $uid = (int) ($_POST['user_id'] ?? 0);
            $target = us_get_user($pdo, $uid);
            us_reset_user_password($pdo, $uid, (string) ($_POST['password'] ?? ''));
            $name = $target['username'] ?? ('#' . $uid);
            us_flash_set('ok', 'Password reset for "' . $name . '". Give them the new password securely.');
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
        if (in_array($action, ['update_assignments', 'reset_password'], true)) {
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
<?php if ($error !== ''): ?>
  <div class="flash flash-error"><?= us_h($error) ?></div>
<?php endif; ?>

<?php if ($editUser): ?>
<section style="margin-bottom:1.5rem;padding:1rem;border:2px solid var(--accent);border-radius:12px;background:#fff">
  <h2 style="font-size:1.2rem;margin:0 0 0.75rem">Edit staff: <?= us_h($editUser['username']) ?></h2>
  <form method="post" class="grid" style="margin-bottom:1.25rem">
    <input type="hidden" name="action" value="update_assignments">
    <input type="hidden" name="user_id" value="<?= (int) $editUser['id'] ?>">
    <fieldset class="assign-box">
      <legend>Assigned companies (required)</legend>
      <?php if ($allCompanies === []): ?>
        <p class="muted">Create a company first on the Companies page.</p>
      <?php else: ?>
        <?php foreach ($allCompanies as $c): ?>
          <label class="assign-row">
            <input type="checkbox" name="company_ids[]" value="<?= (int) $c['id'] ?>"
              <?= in_array((int) $c['id'], $editCompanyIds, true) ? 'checked' : '' ?>>
            <span><?= us_h($c['name']) ?></span>
          </label>
        <?php endforeach; ?>
      <?php endif; ?>
    </fieldset>
    <fieldset class="assign-box">
      <legend>Power</legend>
      <label class="assign-row">
        <input type="checkbox" name="has_power" value="1"
          <?= (int) ($editUser['has_power'] ?? 0) === 1 ? 'checked' : '' ?>>
<span><strong>Power</strong> (remove items · issue to company · issue report)</span>
      </label>
    </fieldset>
    <div class="actions">
      <button type="submit">Save assignments</button>
      <a class="btn secondary" href="<?= us_h(us_url('users.php')) ?>">Back to list</a>
    </div>
  </form>
  <form method="post" class="grid" style="max-width:420px">
    <input type="hidden" name="action" value="reset_password">
    <input type="hidden" name="user_id" value="<?= (int) $editUser['id'] ?>">
    <div>
      <label for="password">New password (reset)</label>
      <input id="password" name="password" type="password" required minlength="6" autocomplete="new-password"
             placeholder="at least 6 characters">
    </div>
    <div class="actions">
      <button type="submit">Set new password</button>
    </div>
  </form>
</section>
<?php endif; ?>

<section class="grid grid-2">
  <div style="padding:1rem;border:1px solid var(--line);border-radius:12px;background:#fff">
    <h2 style="font-size:1.2rem;margin:0 0 0.75rem">Create staff user</h2>
    <form method="post" class="grid">
      <input type="hidden" name="action" value="create">
      <div>
        <label for="username">Username (you choose)</label>
        <input id="username" name="username" required autocomplete="off" placeholder="e.g. ravi"
               value="<?= us_h((string) ($_POST['username'] ?? '')) ?>">
      </div>
      <div>
        <label for="new_password">Password</label>
        <input id="new_password" name="password" type="password" required minlength="6" autocomplete="new-password"
               placeholder="at least 6 characters">
      </div>
      <fieldset class="assign-box">
        <legend>Assign companies (tick at least one)</legend>
        <?php if ($allCompanies === []): ?>
          <p class="muted">Create a company first on the Companies page, then return here.</p>
        <?php else: ?>
          <?php foreach ($allCompanies as $c):
              $cid = (int) $c['id'];
              $checked = false;
              if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'create') {
                  $posted = $_POST['company_ids'] ?? [];
                  $checked = is_array($posted) && in_array((string) $cid, array_map('strval', $posted), true);
              } else {
                  $checked = ($currentCompanyId === $cid) || count($allCompanies) === 1;
              }
          ?>
            <label class="assign-row">
              <input type="checkbox" name="company_ids[]" value="<?= $cid ?>" <?= $checked ? 'checked' : '' ?>>
              <span><?= us_h($c['name']) ?><?= $currentCompanyId === $cid ? ' (current)' : '' ?></span>
            </label>
          <?php endforeach; ?>
        <?php endif; ?>
      </fieldset>
      <fieldset class="assign-box">
        <legend>Power (optional)</legend>
        <label class="assign-row">
          <input type="checkbox" name="has_power" value="1"
            <?= !empty($_POST['has_power']) ? 'checked' : '' ?>>
          <span>Power</span>
        </label>
      </fieldset>
      <div class="actions">
        <button type="submit" <?= $allCompanies === [] ? 'disabled' : '' ?>>Create staff</button>
      </div>
    </form>
  </div>
  <div style="padding:1rem;border:1px solid var(--line);border-radius:12px;background:#fff">
    <h2 style="font-size:1.2rem;margin:0 0 0.75rem">Staff users (<?= count($staff) ?>)</h2>
    <?php if ($staff === []): ?>
      <p class="muted">No staff users yet. Use the form on the left — that is how staff accounts are created.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Username</th><th>Companies</th><th>Power</th><th></th></tr>
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
              $powered = (int) ($s['has_power'] ?? 0) === 1;
          ?>
            <tr>
              <td><strong><?= us_h($s['username']) ?></strong></td>
              <td class="muted"><?= us_h($names === [] ? '—' : implode(', ', $names)) ?></td>
              <td><?= $powered ? 'Yes' : '—' ?></td>
              <td class="row-actions">
                <a class="btn secondary" href="<?= us_h(us_url('users.php?edit=' . (int) $s['id'])) ?>">Edit / reset password</a>
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
