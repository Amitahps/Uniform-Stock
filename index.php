<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$user = us_require_login();
$pdo = us_db();
$rows = us_stock_rows($pdo);
$totalSkus = count($rows);
$low = 0;
$units = 0;
foreach ($rows as $r) {
    $q = (int) $r['on_hand'];
    $units += $q;
    if ($q < US_DEFICIT_THRESHOLD) {
        $low++;
    }
}

us_header('Dashboard', $user);
?>
<p class="lead">Receive bills, issue stock, and review on-hand balances.</p>
<ul class="card-list">
  <li><strong><?= (int) $totalSkus ?></strong> SKUs · <strong><?= (int) $units ?></strong> units on hand</li>
  <li>
    Deficit (qty &lt; <?= (int) US_DEFICIT_THRESHOLD ?>):
    <strong class="<?= $low > 0 ? 'low' : '' ?>"><?= (int) $low ?></strong>
    — <a href="deficit.php">view list</a>
  </li>
</ul>
<div class="actions">
  <a class="btn" href="stock_in.php">Stock in</a>
  <a class="btn secondary" href="stock_out.php">Stock out</a>
  <a class="btn secondary" href="reports.php">Reports</a>
</div>
<?php us_footer(); ?>
