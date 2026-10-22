<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/company.php';
require_once __DIR__ . '/includes/layout.php';

$user = us_require_login();
$pdo = us_db();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create') {
        try {
            $id = us_create_company($pdo, (string) ($_POST['name'] ?? ''));
            us_set_company_id($id);
            us_flash_set('ok', 'Company created and selected.');
            header('Location: index.php');
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
            us_flash_set('ok', 'Working in ' . $company['name'] . '.');
            header('Location: index.php');
            exit;
        }
    }
}

$companies = us_list_companies($pdo);
$currentId = us_current_company_id();
$current = $currentId ? us_get_company($pdo, $currentId) : null;

us_header('Companies', $user, $current);
?>
<p class="lead">
  Create a company, then select it. All items, stock in/out, reports, and deficit stay inside the selected company.
</p>
<?php if ($error !== ''): ?>
  <div class="flash flash-error"><?= us_h($error) ?></div>
<?php endif; ?>

<section class="grid grid-2">
  <div>
    <h2 style="font-size:1.1rem;margin:0 0 0.75rem">Create company</h2>
    <form method="post" class="grid">
      <input type="hidden" name="action" value="create">
      <div>
        <label for="name">Company name</label>
        <input id="name" name="name" required placeholder="e.g. North Campus Vendor">
      </div>
      <div class="actions">
        <button type="submit">Create &amp; select</button>
      </div>
    </form>
  </div>
  <div>
    <h2 style="font-size:1.1rem;margin:0 0 0.75rem">Select company</h2>
    <?php if (!$companies): ?>
      <p class="muted">No companies yet. Create one to begin.</p>
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

<?php if ($companies): ?>
  <h2 style="margin-top:1.75rem;font-size:1.15rem">All companies</h2>
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
          <td>
            <?php if ($currentId !== (int) $c['id']): ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="select">
                <input type="hidden" name="company_id" value="<?= (int) $c['id'] ?>">
                <button type="submit" class="secondary">Select</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
<?php us_footer(); ?>
