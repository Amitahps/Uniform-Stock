<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/company.php';

$user = us_require_login();
$pdo = us_db();
$companyId = us_current_company_id();
$company = $companyId ? us_get_company($pdo, $companyId) : null;
if (!$company || !us_user_can_access_company($pdo, $user, (int) $company['id'])) {
    us_set_company_id(null);
    us_set_school(null);
    header('Location: ' . us_company_picker_url());
    exit;
}

$school = (string) ($_GET['school'] ?? '');
$next = us_safe_next((string) ($_GET['next'] ?? 'index.php'));
if (!us_is_valid_school($school)) {
    us_flash_set('error', 'Choose Bachpan or AHP.S.');
    header('Location: ' . us_url($next));
    exit;
}

us_set_school($school);
us_flash_set('ok', 'School: ' . $school . ' · ' . $company['name']);
header('Location: ' . us_url($next));
exit;
