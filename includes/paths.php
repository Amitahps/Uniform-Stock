<?php
declare(strict_types=1);

/**
 * Base URL path for this app ('' at domain root, or '/subdir' if nested).
 * Always ends without a trailing slash, except when empty.
 */
function us_base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $dir = str_replace('\\', '/', dirname($script));
    if ($dir === '/' || $dir === '.' || $dir === '') {
        $base = '';
    } else {
        $base = rtrim($dir, '/');
    }
    return $base;
}

/** Root-absolute URL path for an app asset or page, e.g. /assets/style.css */
function us_url(string $path): string
{
    $path = ltrim(str_replace('\\', '/', $path), '/');
    $base = us_base_path();
    return ($base === '' ? '' : $base) . '/' . $path;
}
