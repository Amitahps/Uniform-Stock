<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/company.php';
require_once __DIR__ . '/includes/layout.php';

$user = us_require_power();
$ctx = us_require_scope();
$company = $ctx['company'];
$school = $ctx['school'];
$companyId = (int) $company['id'];
$pdo = us_db();
$error = '';

$stock = us_stock_rows($pdo, $companyId, $school);
$allCompanies = us_list_companies($pdo);
$otherCompanies = array_values(array_filter(
    $allCompanies,
    static fn(array $c): bool => (int) $c['id'] !== $companyId
));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $toId = (int) ($_POST['to_company_id'] ?? 0);
        $skuId = (int) ($_POST['sku_id'] ?? 0);
        $qty = (int) ($_POST['quantity'] ?? 0);
        $date = trim((string) ($_POST['transfer_date'] ?? ''));
        us_issue_to_company($pdo, $companyId, $toId, $school, $skuId, $qty, $date, $user);
        $to = us_get_company($pdo, $toId);
        $sku = us_get_sku($pdo, $skuId) ?: ['item_name' => '?', 'item_no' => '?', 'size' => '?'];
        us_flash_set(
            'ok',
            'Issued ' . $qty . ' × ' . $sku['item_name'] . ' · ' . $sku['item_no'] . ' · ' . $sku['size']
            . ' from ' . $company['name'] . ' → ' . ($to['name'] ?? 'company')
            . ' (' . $school . ').'
        );
        header('Location: ' . us_url('issue_company.php'));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$onHandById = [];
foreach ($stock as $row) {
    $onHandById[(int) $row['id']] = (int) $row['on_hand'];
}

us_header('Issue to company', $user, $company, $school);
?>
<p class="lead">
  <strong>Issue to company</strong> (inter-company transfer) — not the same as normal Stock out.
  Deducts from <strong><?= us_h($company['name']) ?></strong> · <strong><?= us_h($school) ?></strong>
  and adds the same qty to another company for the <strong>same school</strong> and item name/code/size
  (creates the SKU on the recipient if missing).
  Admin or power-assigned staff only.
</p>
<?php if ($error !== ''): ?>
  <div class="flash flash-error"><?= us_h($error) ?></div>
<?php endif; ?>

<?php if (count($otherCompanies) === 0): ?>
  <div class="flash flash-warn">
    Create at least one other company on the Companies page before you can issue stock across companies.
  </div>
<?php elseif (!$stock): ?>
  <p class="muted">
    No stock in this company/school.
    <a href="<?= us_h(us_url('stock_in.php')) ?>">Stock in</a> first, then return here.
  </p>
<?php else: ?>
<form method="post" class="grid" style="max-width:640px" id="issue_company_form">
  <div>
    <label for="to_company_id">Issue to company</label>
    <select id="to_company_id" name="to_company_id" required>
      <option value="">Select recipient company…</option>
      <?php foreach ($otherCompanies as $c): ?>
        <option value="<?= (int) $c['id'] ?>"
          <?= (string) ($_POST['to_company_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>>
          <?= us_h($c['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label for="transfer_date">Issue date</label>
    <input id="transfer_date" name="transfer_date" type="date" required
           value="<?= us_h($_POST['transfer_date'] ?? date('Y-m-d')) ?>">
  </div>
  <div>
    <label for="sku_id">Item (name · code · size)</label>
    <select id="sku_id" name="sku_id" class="sku-select" required>
      <option value="" data-on-hand="">Select…</option>
      <?php
      $selSku = (string) ($_POST['sku_id'] ?? '');
      foreach ($stock as $row):
          $oh = (int) $row['on_hand'];
      ?>
        <option
          value="<?= (int) $row['id'] ?>"
          data-on-hand="<?= $oh ?>"
          <?= (string) $row['id'] === $selSku ? 'selected' : '' ?>
          <?= $oh < 1 ? 'disabled' : '' ?>
        >
          <?= us_h($row['item_name'] . ' · ' . $row['item_no'] . ' · ' . $row['size']) ?>
          (on hand <?= $oh ?>)
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="grid grid-2">
    <div>
      <label for="on_hand_display">On hand (source)</label>
      <input id="on_hand_display" type="text" readonly tabindex="-1" value="" placeholder="—">
    </div>
    <div>
      <label for="quantity">Qty to issue</label>
      <input id="quantity" name="quantity" type="number" min="1" step="1" required
             value="<?= us_h((string) ($_POST['quantity'] ?? '1')) ?>">
    </div>
  </div>
  <div class="actions">
    <button type="submit">Issue to company</button>
    <a class="btn secondary" href="<?= us_h(us_url('stock_out.php')) ?>">Normal stock out instead</a>
    <a class="btn secondary" href="<?= us_h(us_url('reports.php?mode=company_issues')) ?>">Issue report</a>
  </div>
</form>
<script>
(function () {
  const sel = document.getElementById('sku_id');
  const display = document.getElementById('on_hand_display');
  function sync() {
    const opt = sel.options[sel.selectedIndex];
    const val = opt && opt.getAttribute('data-on-hand') !== null ? opt.getAttribute('data-on-hand') : '';
    display.value = sel.value && val !== '' ? val : '';
  }
  sel.addEventListener('change', sync);
  sync();
})();
</script>
<?php endif; ?>
<?php us_footer(); ?>
