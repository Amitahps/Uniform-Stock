<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/company.php';
require_once __DIR__ . '/includes/layout.php';

$user = us_require_login();
$companyId = us_current_company_id();
$company = $companyId ? us_get_company(us_db(), $companyId) : null;
$school = us_current_school();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = (string) ($_POST['current'] ?? '');
    $next = (string) ($_POST['next'] ?? '');
    $confirm = (string) ($_POST['confirm'] ?? '');

    $stmt = us_db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([(int) $user['id']]);
    $hash = (string) $stmt->fetchColumn();

    if (!password_verify($current, $hash)) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($next) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($next !== $confirm) {
        $error = 'New password and confirmation do not match.';
    } else {
        $upd = us_db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $upd->execute([password_hash($next, PASSWORD_DEFAULT), (int) $user['id']]);
        us_flash_set('ok', 'Password updated.');
        header('Location: password.php');
        exit;
    }
}

us_header('Change password', $user, $company, $school);
?>
<p class="lead">Update the admin login password for this site.</p>
<?php if ($error !== ''): ?>
  <div class="flash flash-error"><?= us_h($error) ?></div>
<?php endif; ?>
<form method="post" class="grid" style="max-width:420px">
  <div>
    <label for="current">Current password</label>
    <input id="current" name="current" type="password" required autocomplete="current-password">
  </div>
  <div>
    <label for="next">New password</label>
    <input id="next" name="next" type="password" required autocomplete="new-password">
  </div>
  <div>
    <label for="confirm">Confirm new password</label>
    <input id="confirm" name="confirm" type="password" required autocomplete="new-password">
  </div>
  <div class="actions">
    <button type="submit">Save password</button>
  </div>
</form>
<?php us_footer(); ?>
