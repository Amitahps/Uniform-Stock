<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function us_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function us_flash_set(string $type, string $message): void
{
    us_start_session();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function us_flash_take(): ?array
{
    us_start_session();
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function us_header(string $title, ?array $user = null, ?array $company = null): void
{
    $pageTitle = $title . ' · ' . US_APP_NAME;
    $flash = us_flash_take();
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . us_h($pageTitle) . '</title>';
    echo '<link rel="stylesheet" href="' . us_h(us_url('assets/style.css')) . '">';
    echo '</head><body>';
    echo '<div class="wrap">';
    echo '<header class="top">';
    echo '<div class="brand"><a href="' . us_h(us_url('index.php')) . '">' . us_h(US_APP_NAME) . '</a></div>';
    if ($user) {
        echo '<nav class="nav">';
        echo '<a href="' . us_h(us_url('companies.php')) . '">Schools</a>';
        echo '<a href="' . us_h(us_url('items.php')) . '">Items</a>';
        echo '<a href="' . us_h(us_url('stock_in.php')) . '">Stock in</a>';
        echo '<a href="' . us_h(us_url('stock_out.php')) . '">Stock out</a>';
        echo '<a href="' . us_h(us_url('reports.php')) . '">Reports</a>';
        echo '<a href="' . us_h(us_url('deficit.php')) . '">Deficit</a>';
        echo '<a href="' . us_h(us_url('password.php')) . '">Password</a>';
        echo '<a href="' . us_h(us_url('logout.php')) . '">Logout</a>';
        echo '</nav>';
        echo '<div class="who">';
        if ($company) {
            echo '<span class="company-pill">School: ' . us_h($company['name']) . '</span> · ';
        } else {
            echo '<span class="muted">No school selected</span> · ';
        }
        echo us_h($user['username']);
        echo '</div>';
    }
    echo '</header>';
    if ($flash) {
        echo '<div class="flash flash-' . us_h($flash['type']) . '">' . us_h($flash['message']) . '</div>';
    }
    echo '<main class="main">';
    echo '<h1>' . us_h($title) . '</h1>';
}

function us_footer(): void
{
    echo '</main>';
    echo '<footer class="foot"><small>School vendor stock · Hostinger / PHP + SQLite · per-school inventory (Bachpan / AHP.S)</small></footer>';
    echo '</div></body></html>';
}
