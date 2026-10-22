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

function us_company_picker_url(): string
{
    return us_is_admin() ? us_url('companies.php') : us_url('select_company.php');
}

/**
 * Require login + active company the user may access.
 * @return array{user: array, company: array}
 */
function us_require_company(): array
{
    $user = us_require_login();
    $pdo = us_db();
    $id = us_current_company_id();
    $company = $id ? us_get_company($pdo, $id) : null;
    if (!$company || !us_user_can_access_company($pdo, $user, (int) $company['id'])) {
        us_set_company_id(null);
        us_set_school(null);
        $picker = us_is_admin($user) ? 'companies.php' : 'select_company.php';
        us_flash_set(
            'warn',
            us_is_admin($user)
                ? 'Create or select a company before continuing.'
                : 'Select one of your assigned companies before continuing.'
        );
        header('Location: ' . us_url($picker));
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
        us_flash_set('warn', 'Choose Bachpan or AHP.S using the school tabs at the top.');
        header('Location: ' . us_url('index.php'));
        exit;
    }
    return [
        'user' => $ctx['user'],
        'company' => $ctx['company'],
        'school' => $school,
    ];
}
