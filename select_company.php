<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/company.php';
require_once __DIR__ . '/includes/layout.php';

$user = us_require_login();
$pdo = us_db();
$error = '';

// Admins manage companies on Companies page; this page is the staff picker (admins may use it too).
$companies = us_companies_for_user($pdo, $user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['company_id'] ?? 0);
    if (!us_user_can_access_company($pdo, $user, $id)) {
        $error = 'You are not assigned to that company.';
    } else {
        $company = us_get_company($pdo, $id);
        if (!$company) {
            $error = 'Company not found.';
        } else {
            us_set_company_id($id);
            // Keep school if already set; tabs can switch anytime.
            us_flash_set('ok', 'Working in ' . $company['name'] . '.');
            header('Location: ' . us_url('index.php'));
            exit;
        }
    }
}

$currentId = us_current_company_id();
$current = $currentId ? us_get_company($pdo, $currentId) : null;
$school = us_current_school();

us_header('Select company', $user, $current, $school);
?>
<p class="lead">
  Choose a company you are assigned to. Then use the <strong>Bachpan</strong> / <strong>AHP.S</strong> tabs at the top to pick a school.
</p>
<?php if ($error !== ''): ?>
  <div class="flash flash-error"><?= us_h($error) ?></div>
<?php endif; ?>

<?php if ($companies === []): ?>
  <div class="flash flash-warn">
    No companies are assigned to your account. Ask an admin to assign you on the Users page.
  </div>
<?php else: ?>
  <form method="post" class="grid" style="max-width:420px">
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
<?php us_footer(); ?>
