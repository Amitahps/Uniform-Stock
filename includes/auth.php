<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

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
    $stmt = us_db()->prepare('SELECT id, username FROM users WHERE id = ?');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $user = $stmt->fetch();
    return $user ?: null;
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

function us_attempt_login(string $username, string $password): bool
{
    $stmt = us_db()->prepare('SELECT id, username, password_hash FROM users WHERE username = ?');
    $stmt->execute([trim($username)]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($password, $row['password_hash'])) {
        return false;
    }
    us_start_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $row['id'];
    return true;
}

function us_logout(): void
{
    us_start_session();
    $_SESSION = []; // clears user_id and company_id
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
    }
    session_destroy();
}
