<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

if (us_user()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = (string) ($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    if (us_attempt_login($username, $password)) {
        header('Location: index.php');
        exit;
    }
    $error = 'Invalid username or password.';
}

us_header('Login');
?>
<p class="lead">Sign in to manage uniform stock.</p>
<?php if ($error !== ''): ?>
  <div class="flash flash-error"><?= us_h($error) ?></div>
<?php endif; ?>
<form method="post" class="login-box grid" autocomplete="username">
  <div>
    <label for="username">Username</label>
    <input id="username" name="username" required value="<?= us_h($_POST['username'] ?? 'admin') ?>">
  </div>
  <div>
    <label for="password">Password</label>
    <input id="password" name="password" type="password" required autocomplete="current-password">
  </div>
  <div class="actions">
    <button type="submit">Sign in</button>
  </div>
  <p class="muted">Default: admin / admin123 — change after first login.</p>
</form>
<?php us_footer(); ?>
