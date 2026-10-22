<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Show a visible error page instead of a blank white screen (Hostinger display_errors off).
 */
function us_fail_page(string $title, string $message, int $status = 500): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
    }
    $css = htmlspecialchars(us_url('assets/style.css'), ENT_QUOTES, 'UTF-8');
    $login = htmlspecialchars(us_url('login.php'), ENT_QUOTES, 'UTF-8');
    $check = htmlspecialchars(us_url('check.php'), ENT_QUOTES, 'UTF-8');
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $m = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . $t . ' · ' . htmlspecialchars(US_APP_NAME, ENT_QUOTES, 'UTF-8') . '</title>';
    echo '<link rel="stylesheet" href="' . $css . '">';
    echo '<style>body{font-family:system-ui,sans-serif;margin:1.5rem;line-height:1.45;color:#1c2a24;background:#f3efe6}';
    echo '.box{max-width:36rem;padding:1.25rem;background:#fffdf8;border:1px solid #d7d0c2;border-radius:12px}';
    echo 'a{color:#0f6b4c}</style></head><body><div class="box">';
    echo '<h1>' . $t . '</h1><p>' . $m . '</p>';
    echo '<p><a href="' . $login . '">Login</a> · <a href="' . $check . '">Environment check</a></p>';
    echo '</div></body></html>';
    exit;
}
