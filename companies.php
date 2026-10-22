<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/company.php';
require_once __DIR__ . '/includes/layout.php';

$user = us_require_admin();
$pdo = us_db();
$error = '';
$deleteId = isset($_GET['delete']) ? (int) $_GET['delete'] : 0;
$deleteCompany = $deleteId > 0 ? us_get_company($pdo, $deleteId) : null;
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editCompany = $editId > 0 ? us_get_company($pdo, $editId) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create') {
        try {
            $id = us_create_company($pdo, (string) ($_POST['name'] ?? ''));
            us_set_company_id($id);
            us_set_school(null);
            us_flash_set('ok', 'Company created.');
            header('Location: ' . us_url('companies.php'));
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    } elseif ($action === 'rename') {
        $id = (int) ($_POST['company_id'] ?? 0);
        $editId = $id;
        $editCompany = us_get_company($pdo, $id);
        try {
            us_rename_company($pdo, $id, (string) ($_POST['name'] ?? ''));
            us_flash_set('ok', 'Company renamed.');
            header('Location: ' . us_url('companies.php'));
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    } elseif ($action === 'select') {
        $id = (int) ($_POST['company_id'] ?? 0);
        $company = us_get_company($pdo, $id);
        if (!$company) {
            $error = 'Company not found.';
        } else {
            us_set_company_id($id);
            us_set_school(null);
            us_flash_set('ok', 'Company selected: ' . $company['name']);
            header('Location: ' . us_url('companies.php'));
            exit;
        }
    } elseif ($action === 'select_school') {
        $id = us_current_company_id();
        $company = $id ? us_get_company($pdo, $id) : null;
        $school = (string) ($_POST['school'] ?? '');
        if (!$company) {
            $error = 'Select a company first.';
        } elseif (!us_is_valid_school($school)) {
            $error = 'Choose Bachpan or AHP.S.';
        } else {
            us_set_school($school);
            us_flash_set('ok', $company['name'] . ' · ' . $school);
            header('Location: ' . us_url('index.php'));
            exit;
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['company_id'] ?? 0);
        $password = (string) ($_POST['password'] ?? '');
        $confirmName = trim((string) ($_POST['confirm_name'] ?? ''));
        $target = us_get_company($pdo, $id);
        $deleteId = $id;
        $deleteCompany = $target;

        if (!$target) {
            $error = 'Company not found.';
        } elseif (!us_verify_user_password((int) $user['id'], $password)) {
            $error = 'Admin password is incorrect. Company was not deleted.';
        } elseif ($confirmName === '' || strcasecmp($confirmName, (string) $target['name']) !== 0) {
            $error = 'Type the company name exactly to confirm deletion.';
        } else {
            try {
                us_delete_company($pdo, $id);
                if (us_current_company_id() === $id) {
                    us_set_company_id(null);
                    us_set_school(null);
                }
                us_flash_set('ok', 'Company "' . $target['name'] . '" deleted.');
                header('Location: ' . us_url('companies.php'));
                exit;
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

$companies = us_list_companies($pdo);
$currentId = us_current_company_id();
$current = $currentId ? us_get_company($pdo, $currentId) : null;
$currentSchool = us_current_school();

us_header('Companies', $user, $current, $currentSchool);
?>
<?php if ($error !== ''): ?>
  <div class="flash flash-error"><?= us_h($error) ?></div>
<?php endif; ?>

<?php if ($editCompany): ?>
<section style="margin-bottom:1rem;padding:0.85rem;border:1px solid var(--line);border-radius:10px;background:#fff">
  <h2 style="font-size:1.05rem;margin:0 0 0.65rem">Rename company</h2>
  <form method="post" class="grid" style="max-width:420px">
    <input type="hidden" name="action" value="rename">
    <input type="hidden" name="company_id" value="<?= (int) $editCompany['id'] ?>">
    <div>
      <label for="rename_name">Company name</label>
      <input id="rename_name" name="name" required value="<?= us_h(
          (string) ($_POST['name'] ?? $editCompany['name'])
      ) ?>">
    </div>
    <div class="actions">
      <button type="submit">Save name</button>
      <a class="btn secondary" href="<?= us_h(us_url('companies.php')) ?>">Cancel</a>
    </div>
  </form>
</section>
<?php endif; ?>

<?php if ($deleteCompany): ?>
<section style="margin-bottom:1rem;padding:0.85rem;border:1px solid #f0c2c2;border-radius:10px;background:#fdeceb">
  <h2 style="font-size:1.05rem;margin:0 0 0.5rem;color:#9b2c2c">Remove company</h2>
  <form method="post" class="grid" style="max-width:420px">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="company_id" value="<?= (int) $deleteCompany['id'] ?>">
    <div>
      <label for="confirm_name">Type company name</label>
      <input id="confirm_name" name="confirm_name" required autocomplete="off" placeholder="<?= us_h($deleteCompany['name']) ?>">
    </div>
    <div>
      <label for="password">Admin password</label>
      <input id="password" name="password" type="password" required autocomplete="current-password">
    </div>
    <div class="actions">
      <button type="submit" class="danger-btn">Delete permanently</button>
      <a class="btn secondary" href="<?= us_h(us_url('companies.php')) ?>">Cancel</a>
    </div>
  </form>
</section>
<?php endif; ?>

<section class="grid grid-2">
  <div>
    <h2 style="font-size:1.05rem;margin:0 0 0.65rem">Create company</h2>
    <form method="post" class="grid">
      <input type="hidden" name="action" value="create">
      <div>
        <label for="name">Company name</label>
        <input id="name" name="name" required>
      </div>
      <div class="actions">
        <button type="submit">Create &amp; select</button>
      </div>
    </form>
  </div>
  <div>
    <h2 style="font-size:1.05rem;margin:0 0 0.65rem">Select company</h2>
    <?php if (!$companies): ?>
      <p class="muted">No companies yet.</p>
    <?php else: ?>
      <form method="post" class="grid">
        <input type="hidden" name="action" value="select">
        <div>
          <label for="company_id">Company</label>
          <select id="company_id" name="company_id" required>
            <?php foreach ($companies as $c): ?>
              <option value="<?= (int) $c['id'] ?>" <?= $currentId === (int) $c['id'] ? 'selected' : '' ?>>
                <?= us_h($c['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="actions">
          <button type="submit">Work in this company</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</section>

<?php if ($current): ?>
<section class="school-bar" style="margin-top:1.25rem">
  <div class="school-bar-title"><?= us_h($current['name']) ?></div>
  <form method="post" class="school-bar-buttons" style="margin:0">
    <input type="hidden" name="action" value="select_school">
    <?php foreach (us_schools() as $school): ?>
      <button
        type="submit"
        name="school"
        value="<?= us_h($school) ?>"
        class="school-tab-btn <?= $currentSchool === $school ? 'is-active' : 'secondary' ?>"
      ><?= us_h($school) ?><?= $currentSchool === $school ? ' ✓' : '' ?></button>
    <?php endforeach; ?>
  </form>
</section>
<?php endif; ?>

<?php if ($companies): ?>
  <h2 style="margin-top:1.5rem;font-size:1.1rem">All companies</h2>
  <table>
    <thead>
      <tr><th>Name</th><th>Created</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($companies as $c): ?>
        <tr>
          <td>
            <?= us_h($c['name']) ?>
            <?php if ($currentId === (int) $c['id']): ?>
              <span class="muted">(selected)</span>
            <?php endif; ?>
          </td>
          <td class="muted"><?= us_h($c['created_at']) ?></td>
          <td class="row-actions">
            <?php if ($currentId !== (int) $c['id']): ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="select">
                <input type="hidden" name="company_id" value="<?= (int) $c['id'] ?>">
                <button type="submit" class="secondary">Select</button>
              </form>
            <?php endif; ?>
            <a class="btn secondary" href="<?= us_h(us_url('companies.php?edit=' . (int) $c['id'])) ?>">Rename</a>
            <a class="btn secondary danger-btn" href="<?= us_h(us_url('companies.php?delete=' . (int) $c['id'])) ?>">Remove</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
<?php us_footer(); ?>
