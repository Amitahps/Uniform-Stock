<?php
declare(strict_types=1);

define('US_APP_NAME', 'Uniform Stock');
/** Bump when shipping UI/feature changes — shown on check.php + footer to verify Hostinger deploy. */
define('US_APP_VERSION', '20261022-xfer-v4');
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

/** Encode name+code for filter selects (codes may repeat across names). */
function us_item_pair_key(string $itemName, string $itemNo): string
{
    return $itemName . "\t" . $itemNo;
}

/**
 * @return array{0:string,1:string}|null [item_name, item_no]
 */
function us_parse_item_pair_key(string $key): ?array
{
    if ($key === '' || !str_contains($key, "\t")) {
        return null;
    }
    $parts = explode("\t", $key, 2);
    $name = trim($parts[0]);
    $no = trim($parts[1] ?? '');
    if ($name === '' || $no === '') {
        return null;
    }
    return [$name, $no];
}
