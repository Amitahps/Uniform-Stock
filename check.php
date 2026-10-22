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
      <p class="lead"><?= $allPass ? 'Ready for Hostinger deploy.' : 'Fix the failing checks below.' ?></p>
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
      <p><a href="<?= htmlspecialchars(us_url('login.php'), ENT_QUOTES, 'UTF-8') ?>">Go to login</a></p>
    </main>
  </div>
</body>
</html>
