<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

function us_current_company_id(): ?int
{
    us_start_session();
    if (empty($_SESSION['company_id'])) {
        return null;
    }
    return (int) $_SESSION['company_id'];
}

function us_set_company_id(?int $companyId): void
{
    us_start_session();
    if ($companyId === null || $companyId <= 0) {
        unset($_SESSION['company_id']);
        return;
    }
    $_SESSION['company_id'] = $companyId;
}

function us_current_school(): ?string
{
    us_start_session();
    $school = isset($_SESSION['school']) ? (string) $_SESSION['school'] : '';
    return us_is_valid_school($school) ? $school : null;
}

function us_set_school(?string $school): void
{
    us_start_session();
    if (!us_is_valid_school($school)) {
        unset($_SESSION['school']);
        return;
    }
    $_SESSION['school'] = $school;
}

/**
 * Require login + active company (vendor). Redirects to companies.php if none.
 * @return array{user: array, company: array}
 */
function us_require_company(): array
{
    $user = us_require_login();
    $pdo = us_db();
    $id = us_current_company_id();
    $company = $id ? us_get_company($pdo, $id) : null;
    if (!$company) {
        us_set_company_id(null);
        us_set_school(null);
        us_flash_set('warn', 'Create or select a company before continuing.');
        header('Location: ' . us_url('companies.php'));
        exit;
    }
    return ['user' => $user, 'company' => $company];
}

/**
 * Require login + company + school (Bachpan | AHP.S).
 * @return array{user: array, company: array, school: string}
 */
function us_require_scope(): array
{
    $ctx = us_require_company();
    $school = us_current_school();
    if ($school === null) {
        us_flash_set('warn', 'Choose a school (Bachpan or AHP.S) for this company before Items, Stock, Reports, or Deficit.');
        header('Location: ' . us_url('companies.php'));
        exit;
    }
    return [
        'user' => $ctx['user'],
        'company' => $ctx['company'],
        'school' => $school,
    ];
}
