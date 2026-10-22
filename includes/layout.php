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

function us_header(string $title, ?array $user = null, ?array $company = null, ?string $school = null): void
{
    $pageTitle = $title . ' · ' . US_APP_NAME;
    $flash = us_flash_take();
    $isAdmin = $user !== null && us_is_admin($user);
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
    $next = $script . ($qs !== '' ? '?' . $qs : '');
    // Avoid nesting switch targets
    if (in_array($script, ['switch_school.php', 'select_company.php', 'login.php', 'logout.php'], true)) {
        $next = 'index.php';
    }

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
        if ($isAdmin) {
            echo '<a href="' . us_h(us_url('companies.php')) . '">Companies</a>';
        } else {
            echo '<a href="' . us_h(us_url('select_company.php')) . '">Company</a>';
        }
        echo '<a href="' . us_h(us_url('items.php')) . '">Items</a>';
        echo '<a href="' . us_h(us_url('stock_in.php')) . '">Stock in</a>';
        echo '<a href="' . us_h(us_url('stock_out.php')) . '">Stock out</a>';
        echo '<a href="' . us_h(us_url('reports.php')) . '">Reports</a>';
        echo '<a href="' . us_h(us_url('deficit.php')) . '">Deficit</a>';
        if ($isAdmin) {
            echo '<a href="' . us_h(us_url('users.php')) . '">Users</a>';
            echo '<a href="' . us_h(us_url('backup.php')) . '">Backup</a>';
            echo '<a href="' . us_h(us_url('password.php')) . '">Password</a>';
        }
        echo '<a href="' . us_h(us_url('logout.php')) . '">Logout</a>';
        echo '</nav>';
        echo '<div class="who">';
        if ($company) {
            echo '<span class="company-pill">' . us_h($company['name']) . '</span> · ';
        } else {
            echo '<span class="muted">No company selected</span> · ';
        }
        echo us_h($user['username']);
        if (!$isAdmin) {
            echo ' <span class="muted">(staff)</span>';
        }
        echo '</div>';
    }
    echo '</header>';

    // School tabs — always visible when a company is selected.
    if ($user && $company) {
        echo '<div class="school-tabs" role="tablist" aria-label="School">';
        echo '<span class="school-tabs-label">School</span>';
        foreach (us_schools() as $s) {
            $active = ($school === $s);
            $href = us_url('switch_school.php?school=' . rawurlencode($s) . '&next=' . rawurlencode($next));
            echo '<a class="school-tab' . ($active ? ' is-active' : '') . '" href="' . us_h($href) . '"';
            echo ' role="tab" aria-selected="' . ($active ? 'true' : 'false') . '">';
            echo us_h($s);
            echo '</a>';
        }
        if ($school === null) {
            echo '<span class="school-tabs-hint muted">Choose Bachpan or AHP.S</span>';
        }
        echo '</div>';
    }

    if ($flash) {
        echo '<div class="flash flash-' . us_h($flash['type']) . '">' . us_h($flash['message']) . '</div>';
    }
    echo '<main class="main">';
    echo '<h1>' . us_h($title) . '</h1>';
}

function us_footer(): void
{
    echo '</main>';
    echo '<footer class="foot"><small>School vendor stock · Hostinger / PHP + SQLite · company + school (Bachpan / AHP.S)</small></footer>';
    echo '</div></body></html>';
}
