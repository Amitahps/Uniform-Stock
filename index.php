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

    $ctx = us_require_company();
    $user = $ctx['user'];
    $company = $ctx['company'];
    $school = us_current_school();
    $companyId = (int) $company['id'];
    $pdo = us_db();

    us_header('Dashboard', $user, $company, $school);

    if ($school === null) {
        ?>
<p class="lead">
  Company <strong><?= us_h($company['name']) ?></strong>.
  Choose <strong>Bachpan</strong> or <strong>AHP.S</strong> using the school tabs above to continue.
</p>
        <?php
        us_footer();
        exit;
    }

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
    ?>
<p class="lead">
  Company <strong><?= us_h($company['name']) ?></strong> · School <strong><?= us_h($school) ?></strong>.
  Switch school anytime with the tabs above.
  <?php if (us_is_admin($user)): ?>
    <a href="<?= us_h(us_url('companies.php')) ?>">Manage companies</a>
  <?php else: ?>
    <a href="<?= us_h(us_url('select_company.php')) ?>">Switch company</a>
  <?php endif; ?>
</p>
<?php if (us_is_admin($user)): ?>
<div class="flash flash-warn" style="margin-bottom:1rem">
  <strong>Staff users:</strong> open <a href="<?= us_h(us_url('users.php')) ?>"><strong>Users</strong></a>
  to create a staff username/password and assign companies.
  Staff log in on the Login page; they will <strong>not</strong> see Companies, Users, Backup, or Password.
</div>
<?php else: ?>
<div class="flash flash-ok" style="margin-bottom:1rem">
  Signed in as <strong>staff</strong>. You can work Items / Stock / Reports for your assigned companies only.
</div>
<?php endif; ?>
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
  <?php if (us_is_admin($user)): ?>
    <a class="btn secondary" href="<?= us_h(us_url('users.php')) ?>">Users (staff)</a>
  <?php endif; ?>
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
