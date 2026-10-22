<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

try {
    if (us_user()) {
        header('Location: ' . us_url('index.php'));
        exit;
    }
} catch (Throwable $e) {
    // Stale session pointing at a broken DB — clear and show login with message.
    us_logout();
    $error = 'Database error: ' . $e->getMessage() . ' See /check.php.';
}

if (!isset($error)) {
    $error = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = (string) ($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    try {
        if (us_attempt_login($username, $password)) {
            header('Location: ' . us_url('index.php'));
            exit;
        }
        $error = 'Invalid username or password.';
    } catch (Throwable $e) {
        $error = 'Database error: ' . $e->getMessage() . ' See /check.php.';
    }
}

us_header('Login');
?>
<p class="lead">Sign in to manage uniform stock.</p>
<?php if ($error !== ''): ?>
  <div class="flash flash-error"><?= us_h($error) ?></div>
<?php endif; ?>
<form method="post" class="login-box grid" autocomplete="username" action="<?= us_h(us_url('login.php')) ?>">
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
