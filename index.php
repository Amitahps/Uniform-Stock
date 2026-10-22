<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/company.php';
require_once __DIR__ . '/includes/layout.php';

$ctx = us_require_company();
$user = $ctx['user'];
$company = $ctx['company'];
$companyId = (int) $company['id'];
$pdo = us_db();
$rows = us_stock_rows($pdo, $companyId);
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

us_header('Dashboard', $user, $company);
?>
<p class="lead">
  Working in <strong><?= us_h($company['name']) ?></strong>.
  Receive bills, issue stock, and review on-hand balances for this company only.
  <a href="companies.php">Switch company</a>
</p>
<ul class="card-list">
  <li><strong><?= (int) $totalSkus ?></strong> SKUs · <strong><?= (int) $units ?></strong> units on hand</li>
  <li>
    Deficit (qty &lt; <?= (int) US_DEFICIT_THRESHOLD ?>):
    <strong class="<?= $low > 0 ? 'low' : '' ?>"><?= (int) $low ?></strong>
    — <a href="deficit.php">view list</a>
  </li>
</ul>
<div class="actions">
  <a class="btn secondary" href="items.php">Items</a>
  <a class="btn" href="stock_in.php">Stock in</a>
  <a class="btn secondary" href="stock_out.php">Stock out</a>
  <a class="btn secondary" href="reports.php">Reports</a>
</div>
<?php us_footer(); ?>
