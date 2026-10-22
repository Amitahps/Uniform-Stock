<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

try {
    us_start_session();
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . us_url('login.php'));
        exit;
    }
} catch (Throwable $e) {
    us_fail_page('Session error', $e->getMessage());
}

try {
    require_once __DIR__ . '/includes/company.php';
    require_once __DIR__ . '/includes/layout.php';

    $ctx = us_require_scope();
    $user = $ctx['user'];
    $company = $ctx['company'];
    $school = $ctx['school'];
    $companyId = (int) $company['id'];
    $pdo = us_db();
    $rows = us_stock_rows($pdo, $companyId, $school);
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

    us_header('Dashboard', $user, $company, $school);
    ?>
<p class="lead">
  Company <strong><?= us_h($company['name']) ?></strong> · School <strong><?= us_h($school) ?></strong>.
  Stock for Bachpan and AHP.S stay separate under this company.
  <a href="<?= us_h(us_url('companies.php')) ?>">Switch company / school</a>
</p>
<ul class="card-list">
  <li><strong><?= (int) $totalSkus ?></strong> SKUs · <strong><?= (int) $units ?></strong> units on hand</li>
  <li>
    Deficit (qty &lt; <?= (int) US_DEFICIT_THRESHOLD ?>):
    <strong class="<?= $low > 0 ? 'low' : '' ?>"><?= (int) $low ?></strong>
    — <a href="<?= us_h(us_url('deficit.php')) ?>">view list</a>
  </li>
</ul>
<div class="actions">
  <a class="btn secondary" href="<?= us_h(us_url('items.php')) ?>">Items</a>
  <a class="btn" href="<?= us_h(us_url('stock_in.php')) ?>">Stock in</a>
  <a class="btn secondary" href="<?= us_h(us_url('stock_out.php')) ?>">Stock out</a>
  <a class="btn secondary" href="<?= us_h(us_url('reports.php')) ?>">Reports</a>
</div>
    <?php
    us_footer();
} catch (Throwable $e) {
    us_fail_page(
        'Database error',
        'Could not open or migrate the stock database: ' . $e->getMessage()
        . ' Open /check.php after fixing data/ permissions, or contact support if this persists.'
    );
}
