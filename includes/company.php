<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

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

/**
 * Require login + an active company. Redirects to companies.php if none selected.
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
        header('Location: ' . us_url('companies.php'));
        exit;
    }
    return ['user' => $user, 'company' => $company];
}
