<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');

$checks = [];
$ok = static function (string $label, bool $pass, string $detail = '') use (&$checks): void {
    $checks[] = ['label' => $label, 'pass' => $pass, 'detail' => $detail];
};

$ok('PHP version ≥ 8.0', version_compare(PHP_VERSION, '8.0.0', '>='), PHP_VERSION);
$ok('PDO extension', extension_loaded('pdo'));
$ok('pdo_sqlite extension', extension_loaded('pdo_sqlite'));

$dataDir = __DIR__ . '/data';
$ok('data/ directory exists', is_dir($dataDir), $dataDir);
$ok('data/ is writable', is_dir($dataDir) && is_writable($dataDir));

$dbOk = false;
$dbDetail = '';
$fkOk = false;
$fkDetail = '';
try {
    require_once __DIR__ . '/includes/db.php';
    $pdo = us_db();
    $pdo->query('SELECT 1');
    $companies = (int) $pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn();
    $dbOk = true;
    $dbDetail = US_DB_PATH . ' · companies=' . $companies;

    $fk = (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn();
    $fkOk = $fk === 1;
    $fkDetail = $fk === 1 ? 'ON' : 'OFF';

    // Integrity probe: insert+rollback is too heavy; check orphan company_ids instead.
    $orphans = (int) $pdo->query(
        'SELECT COUNT(*) FROM skus WHERE company_id NOT IN (SELECT id FROM companies)'
    )->fetchColumn();
    $ok('No orphan SKU company_id', $orphans === 0, $orphans === 0 ? 'ok' : (string) $orphans);
} catch (Throwable $e) {
    $dbDetail = $e->getMessage();
}
$ok('SQLite database opens', $dbOk, $dbDetail);
$ok('Foreign keys enabled after migrate', $fkOk, $fkDetail);

$cssPath = __DIR__ . '/assets/style.css';
$ok('assets/style.css present', is_file($cssPath), us_url('assets/style.css'));

$featureFiles = [
    'users.php' => 'Users (staff admin)',
    'switch_school.php' => 'School switcher',
    'select_company.php' => 'Staff company picker',
    'issue_company.php' => 'Issue to company',
    'includes/layout.php' => 'Layout / school bar',
];
foreach ($featureFiles as $rel => $label) {
    $path = __DIR__ . '/' . $rel;
    $ok($label . ' file present', is_file($path), $rel);
}
$cssBody = is_file($cssPath) ? (string) file_get_contents($cssPath) : '';
$ok('School bar CSS (.school-bar) present', str_contains($cssBody, '.school-bar'), 'assets/style.css');
$layoutBody = is_file(__DIR__ . '/includes/layout.php') ? (string) file_get_contents(__DIR__ . '/includes/layout.php') : '';
$ok('School bar markup in layout', str_contains($layoutBody, 'school-bar'), 'includes/layout.php');
$dbBody = is_file(__DIR__ . '/includes/db.php') ? (string) file_get_contents(__DIR__ . '/includes/db.php') : '';
$ok('Catalog sync helpers present', str_contains($dbBody, 'us_create_sku_all_companies'), 'includes/db.php');
$ok('Company transfer helpers present', str_contains($dbBody, 'us_issue_to_company'), 'includes/db.php');

if ($dbOk) {
    try {
        $userCols = us_table_columns($pdo, 'users');
        $ok('users.role column', in_array('role', $userCols, true), implode(',', $userCols));
        $ok('users.has_power column', in_array('has_power', $userCols, true), implode(',', $userCols));
        $ok('user_companies table', us_table_exists($pdo, 'user_companies'));
        $ok('company_transfers table', us_table_exists($pdo, 'company_transfers'));
        $staffCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'staff'")->fetchColumn();
        $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        $powerStaff = (int) $pdo->query(
            "SELECT COUNT(*) FROM users WHERE role = 'staff' AND has_power = 1"
        )->fetchColumn();
        $ok(
            'Admin + staff roles readable',
            $adminCount >= 1,
            'admin=' . $adminCount . ' staff=' . $staffCount . ' power_staff=' . $powerStaff
        );
    } catch (Throwable $e) {
        $ok('Staff / power / transfer schema', false, $e->getMessage());
    }
}

$allPass = true;
foreach ($checks as $c) {
    if (!$c['pass']) {
        $allPass = false;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Environment check · Uniform Stock</title>
  <link rel="stylesheet" href="<?= htmlspecialchars(us_url('assets/style.css'), ENT_QUOTES, 'UTF-8') ?>">
  <style>
    body { font-family: system-ui, sans-serif; margin: 1.25rem; color: #1c2a24; background: #f3efe6; }
    .wrap { max-width: 42rem; }
    .ok { color: #0f6b4c; } .bad { color: #9b2c2c; }
    .muted { color: #5b6b63; }
  </style>
</head>
<body>
  <div class="wrap">
    <main class="main">
      <h1>Environment check</h1>
      <p class="lead">
        App version: <strong class="app-version"><?= htmlspecialchars(US_APP_VERSION, ENT_QUOTES, 'UTF-8') ?></strong>
        <?php if ($allPass): ?>
          — Ready for Hostinger. If the live site looks old, Redeploy <code>main</code> and hard-refresh (Ctrl+F5).
        <?php else: ?>
          — Fix the failing checks below (missing files often mean Hostinger Git is stale).
        <?php endif; ?>
      </p>
      <ul class="checks">
        <?php foreach ($checks as $c): ?>
          <li class="<?= $c['pass'] ? 'ok' : 'bad' ?>">
            <?= $c['pass'] ? 'OK' : 'FAIL' ?> — <?= htmlspecialchars($c['label'], ENT_QUOTES, 'UTF-8') ?>
            <?php if ($c['detail'] !== ''): ?>
              <span class="muted">(<?= htmlspecialchars($c['detail'], ENT_QUOTES, 'UTF-8') ?>)</span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if (!$dbOk): ?>
        <div class="flash flash-error" style="margin-top:1rem;padding:0.85rem 1rem;border:1px solid #f0c2c2;background:#fdeceb;border-radius:10px;color:#9b2c2c">
          <strong>If SQLite still fails after a Redeploy</strong>
          <ol style="margin:0.5rem 0 0;padding-left:1.2rem">
            <li>Confirm Hostinger Git deployed the latest <code>main</code> commit.</li>
            <li>In File Manager, open the site <code>data/</code> folder and <strong>delete only</strong> <code>stock.db</code> (and <code>stock.db-*</code> if present). Keep the <code>data/</code> folder.</li>
            <li>Reload this page — the app recreates a fresh database automatically.</li>
          </ol>
          <p style="margin:0.6rem 0 0">Do not delete files from school-fee-erp or Badminton.</p>
        </div>
      <?php endif; ?>
      <div class="flash flash-warn" style="margin-top:1rem;padding:0.85rem 1rem;border:1px solid #f0d9a0;background:#fff6e5;border-radius:10px;color:#8a5a00">
        <strong>Hostinger Redeploy (if UI looks unchanged)</strong>
        <ol style="margin:0.5rem 0 0;padding-left:1.2rem">
          <li>Hostinger → this Uniform-Stock website → <strong>Git</strong> (or Deployments).</li>
          <li>Confirm branch is <code>main</code> and repo is <code>Amitahps/Uniform-Stock</code> (not ERP/Badminton).</li>
          <li>Click <strong>Redeploy</strong> / Deploy and wait until files finish syncing.</li>
          <li>In File Manager, confirm <code>users.php</code>, <code>issue_company.php</code>, <code>switch_school.php</code>, and <code>includes/layout.php</code> exist in <code>public_html</code>.</li>
          <li>Reload <code>/check.php</code> — version must show <code><?= htmlspecialchars(US_APP_VERSION, ENT_QUOTES, 'UTF-8') ?></code> and school-bar + staff + <code>has_power</code> + <code>company_transfers</code> checks OK.</li>
          <li>Login as admin → create item (syncs all companies) → <strong>Issue to company</strong> → Reports → <strong>Issued to companies</strong>. Assign Power on Users for staff who may remove/issue. Hard-refresh (Ctrl+F5) if CSS looks old.</li>
        </ol>
      </div>
      <p><a href="<?= htmlspecialchars(us_url('login.php'), ENT_QUOTES, 'UTF-8') ?>">Go to login</a></p>
      <p class="muted" style="margin-top:1rem;font-size:0.9rem">Footer on logged-in pages also shows <code>app <?= htmlspecialchars(US_APP_VERSION, ENT_QUOTES, 'UTF-8') ?></code>.</p>
    </main>
  </div>
</body>
</html>
