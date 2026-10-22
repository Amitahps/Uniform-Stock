<?php
declare(strict_types=1);

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
try {
    require_once __DIR__ . '/includes/db.php';
    $pdo = us_db();
    $pdo->query('SELECT 1');
    $dbOk = true;
    $dbDetail = US_DB_PATH;
} catch (Throwable $e) {
    $dbDetail = $e->getMessage();
}
$ok('SQLite database opens', $dbOk, $dbDetail);

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
  <link rel="stylesheet" href="assets/style.css">
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
      <p><a href="login.php">Go to login</a></p>
    </main>
  </div>
</body>
</html>
