<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';

function us_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name(US_SESSION_NAME);
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}

function us_user(): ?array
{
    us_start_session();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = us_db()->prepare('SELECT id, username, role FROM users WHERE id = ?');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        return null;
    }
    if (($user['role'] ?? '') !== 'admin' && ($user['role'] ?? '') !== 'staff') {
        $user['role'] = 'admin';
    }
    return $user;
}

function us_is_admin(?array $user = null): bool
{
    $u = $user ?? us_user();
    return $u !== null && ($u['role'] ?? '') === 'admin';
}

function us_require_login(): array
{
    $user = us_user();
    if (!$user) {
        header('Location: ' . us_url('login.php'));
        exit;
    }
    return $user;
}

function us_require_admin(): array
{
    $user = us_require_login();
    if (!us_is_admin($user)) {
        us_flash_set('error', 'That page is for admin only.');
        header('Location: ' . us_url('index.php'));
        exit;
    }
    return $user;
}

function us_attempt_login(string $username, string $password): bool
{
    $stmt = us_db()->prepare('SELECT id, username, password_hash, role FROM users WHERE username = ?');
    $stmt->execute([trim($username)]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($password, $row['password_hash'])) {
        return false;
    }
    us_start_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $row['id'];
    unset($_SESSION['company_id'], $_SESSION['school']);

    // Staff with exactly one company: select it automatically.
    $user = [
        'id' => (int) $row['id'],
        'username' => (string) $row['username'],
        'role' => (string) ($row['role'] ?? 'admin'),
    ];
    if (!us_is_admin($user)) {
        $companies = us_companies_for_user(us_db(), $user);
        if (count($companies) === 1) {
            $_SESSION['company_id'] = (int) $companies[0]['id'];
        }
    }
    return true;
}

/** Re-check the logged-in user's password (e.g. before deleting a company). */
function us_verify_user_password(int $userId, string $password): bool
{
    $stmt = us_db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $hash = $stmt->fetchColumn();
    return is_string($hash) && $hash !== '' && password_verify($password, $hash);
}

function us_logout(): void
{
    us_start_session();
    $_SESSION = []; // clears user_id, company_id, school
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
    }
    session_destroy();
}

/** Safe relative redirect target for school/company switchers. */
function us_safe_next(string $next, string $fallback = 'index.php'): string
{
    $next = trim($next);
    if ($next === '' || str_contains($next, '://') || str_starts_with($next, '//') || str_contains($next, '..')) {
        return $fallback;
    }
    // Allow only app-relative paths like items.php or items.php?x=1
    if (!preg_match('/^[a-zA-Z0-9_\-]+\.php(?:\?[a-zA-Z0-9_\-=&%.]*)?$/', $next)) {
        return $fallback;
    }
    return $next;
}
