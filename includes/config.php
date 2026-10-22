<?php
declare(strict_types=1);

define('US_APP_NAME', 'Uniform Stock');
define('US_SESSION_NAME', 'uniform_stock_sess');
define('US_DEFICIT_THRESHOLD', 5);
define('US_DEFAULT_USER', 'admin');
define('US_DEFAULT_PASS', 'admin123');
define('US_DATA_DIR', dirname(__DIR__) . '/data');
define('US_DB_PATH', US_DATA_DIR . '/stock.db');

/** Fixed schools every company stocks for (not company rows). */
define('US_SCHOOL_BACHPAN', 'Bachpan');
define('US_SCHOOL_AHPS', 'AHP.S');

require_once __DIR__ . '/paths.php';

/** @return list<string> */
function us_schools(): array
{
    return [US_SCHOOL_BACHPAN, US_SCHOOL_AHPS];
}

function us_is_valid_school(?string $school): bool
{
    return $school !== null && in_array($school, us_schools(), true);
}

function us_is_reserved_school_name(string $name): bool
{
    $n = trim($name);
    foreach (us_schools() as $school) {
        if (strcasecmp($n, $school) === 0) {
            return true;
        }
    }
    return false;
}
