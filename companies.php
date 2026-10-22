<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/company.php';
require_once __DIR__ . '/includes/layout.php';

$user = us_require_login();
$pdo = us_db();
$error = '';
$deleteId = isset($_GET['delete']) ? (int) $_GET['delete'] : 0;
$deleteCompany = $deleteId > 0 ? us_get_company($pdo, $deleteId) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create') {
        try {
            $id = us_create_company($pdo, (string) ($_POST['name'] ?? ''));
            us_set_company_id($id);
            us_flash_set('ok', 'School created and selected.');
            header('Location: ' . us_url('index.php'));
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    } elseif ($action === 'select') {
        $id = (int) ($_POST['company_id'] ?? 0);
        $company = us_get_company($pdo, $id);
        if (!$company) {
            $error = 'School not found.';
        } else {
            us_set_company_id($id);
            us_flash_set('ok', 'Working in ' . $company['name'] . '.');
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
            $error = 'School not found.';
        } elseif (!us_verify_user_password((int) $user['id'], $password)) {
            $error = 'Admin password is incorrect. School was not deleted.';
        } elseif ($confirmName === '' || strcasecmp($confirmName, (string) $target['name']) !== 0) {
            $error = 'Type the school name exactly to confirm deletion.';
        } else {
            try {
                us_delete_company($pdo, $id);
                if (us_current_company_id() === $id) {
                    us_set_company_id(null);
                }
                us_flash_set('ok', 'School "' . $target['name'] . '" and all of its stock data were permanently deleted.');
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

us_header('Schools', $user, $current);
?>
<p class="lead">
  Stock is kept <strong>separate per school</strong>. Choose <strong>Bachpan</strong> or <strong>AHP.S</strong>
  before adding items or recording stock in/out. Reports and deficit show only the selected school.
  You can add more schools later if needed.
</p>
<?php if (!$current): ?>
  <div class="flash flash-warn">
    No school selected. Choose <strong>Bachpan</strong> or <strong>AHP.S</strong> below to continue.
  </div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="flash flash-error"><?= us_h($error) ?></div>
<?php endif; ?>

<?php if ($deleteCompany): ?>
<section style="margin-bottom:1.5rem;padding:1rem;border:1px solid #f0c2c2;border-radius:10px;background:#fdeceb">
  <h2 style="font-size:1.1rem;margin:0 0 0.5rem;color:#9b2c2c">Remove school</h2>
  <p style="margin:0 0 0.75rem">
    You are about to permanently delete
    <strong><?= us_h($deleteCompany['name']) ?></strong>
    and <strong>all</strong> of its items, stock-in bills, stock-out issues, and balances.
    Other schools are not affected. This cannot be undone.
  </p>
  <form method="post" class="grid" style="max-width:420px">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="company_id" value="<?= (int) $deleteCompany['id'] ?>">
    <div>
      <label for="confirm_name">Type school name to confirm</label>
      <input id="confirm_name" name="confirm_name" required autocomplete="off" placeholder="<?= us_h($deleteCompany['name']) ?>">
    </div>
    <div>
      <label for="password">Re-enter admin password</label>
      <input id="password" name="password" type="password" required autocomplete="current-password">
    </div>
    <div class="actions">
      <button type="submit" class="danger-btn">Delete school permanently</button>
      <a class="btn secondary" href="<?= us_h(us_url('companies.php')) ?>">Cancel</a>
    </div>
  </form>
</section>
<?php endif; ?>

<section class="grid grid-2">
  <div>
    <h2 style="font-size:1.1rem;margin:0 0 0.75rem">Select school</h2>
    <?php if (!$companies): ?>
      <p class="muted">No schools yet. They are created automatically on first run — reload, or create one below.</p>
    <?php else: ?>
      <form method="post" class="grid">
        <input type="hidden" name="action" value="select">
        <div>
          <label for="company_id">School</label>
          <select id="company_id" name="company_id" required>
            <?php foreach ($companies as $c): ?>
              <option value="<?= (int) $c['id'] ?>" <?= $currentId === (int) $c['id'] ? 'selected' : '' ?>>
                <?= us_h($c['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="actions">
          <button type="submit">Work in this school</button>
        </div>
      </form>
      <p class="muted" style="margin-top:0.75rem;font-size:0.9rem">
        Seeded schools: Bachpan, AHP.S. Items and stock never mix across schools.
      </p>
    <?php endif; ?>
  </div>
  <div>
    <h2 style="font-size:1.1rem;margin:0 0 0.75rem">Add another school</h2>
    <form method="post" class="grid">
      <input type="hidden" name="action" value="create">
      <div>
        <label for="name">School name</label>
        <input id="name" name="name" required placeholder="e.g. another campus">
      </div>
      <div class="actions">
        <button type="submit">Create &amp; select</button>
      </div>
    </form>
  </div>
</section>

<?php if ($companies): ?>
  <h2 style="margin-top:1.75rem;font-size:1.15rem">All schools</h2>
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
            <a class="btn secondary danger-btn" href="<?= us_h(us_url('companies.php?delete=' . (int) $c['id'])) ?>">Remove</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
<?php us_footer(); ?>
