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

$receiptId = (int) ($_GET['id'] ?? $_POST['receipt_id'] ?? 0);
$receipt = $receiptId > 0 ? us_get_receipt($pdo, $receiptId, $companyId, $school) : null;
if (!$receipt) {
    us_flash_set('error', 'Stock-in bill not found.');
    header('Location: ' . us_url('stock_in.php'));
    exit;
}

$skus = us_stock_rows($pdo, $companyId, $school);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save');
    try {
        if ($action === 'delete') {
            us_delete_receipt($pdo, $companyId, $school, $receiptId);
            us_flash_set('ok', 'Bill deleted.');
            header('Location: ' . us_url('stock_in.php'));
            exit;
        }
        $billNo = trim((string) ($_POST['bill_no'] ?? ''));
        $receiptDate = trim((string) ($_POST['receipt_date'] ?? ''));
        $skuIds = $_POST['sku_id'] ?? [];
        $qtys = $_POST['quantity'] ?? [];
        if (!is_array($skuIds)) {
            $skuIds = [];
        }
        if (!is_array($qtys)) {
            $qtys = [];
        }
        $lines = [];
        $n = max(count($skuIds), count($qtys));
        for ($i = 0; $i < $n; $i++) {
            $skuId = (int) ($skuIds[$i] ?? 0);
            $qty = (int) ($qtys[$i] ?? 0);
            if ($skuId <= 0 || $qty < 1) {
                continue;
            }
            $lines[] = ['sku_id' => $skuId, 'qty' => $qty];
        }
        us_update_receipt($pdo, $companyId, $school, $receiptId, $billNo, $receiptDate, $lines);
        us_flash_set('ok', 'Bill updated.');
        header('Location: ' . us_url('stock_in.php'));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$lines = us_get_receipt_lines($pdo, $receiptId);
$postedSku = $_POST['sku_id'] ?? null;
$postedQty = $_POST['quantity'] ?? null;
if (!is_array($postedSku)) {
    $postedSku = [];
    $postedQty = [];
    foreach ($lines as $line) {
        $postedSku[] = (string) $line['sku_id'];
        $postedQty[] = (string) $line['quantity'];
    }
    if ($postedSku === []) {
        $postedSku = [''];
        $postedQty = ['1'];
    }
}

us_header('Edit stock in', $user, $company, $school);
?>
<?php if ($error !== ''): ?>
  <div class="flash flash-error"><?= us_h($error) ?></div>
<?php endif; ?>

<form method="post" id="edit_stock_in">
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="receipt_id" value="<?= (int) $receiptId ?>">
  <div class="grid grid-2" style="max-width:640px">
    <div>
      <label for="bill_no">Bill number</label>
      <input id="bill_no" name="bill_no" required value="<?= us_h((string) ($_POST['bill_no'] ?? $receipt['bill_no'])) ?>">
    </div>
    <div>
      <label for="receipt_date">Receipt date</label>
      <input id="receipt_date" name="receipt_date" type="date" required
             value="<?= us_h((string) ($_POST['receipt_date'] ?? $receipt['receipt_date'])) ?>">
    </div>
  </div>

  <div class="line-rows" id="line_rows" style="margin-top:0.85rem">
    <?php
    $count = count($postedSku);
    for ($i = 0; $i < $count; $i++):
        $selected = (string) ($postedSku[$i] ?? '');
        $qty = (string) ($postedQty[$i] ?? '1');
    ?>
    <div class="line-row stock-in-row">
      <div style="grid-column:1 / -2">
        <label>Item</label>
        <select name="sku_id[]" required>
          <option value="">Select…</option>
          <?php foreach ($skus as $row): ?>
            <option value="<?= (int) $row['id'] ?>" <?= (string) $row['id'] === $selected ? 'selected' : '' ?>>
              <?= us_h($row['item_name'] . ' · ' . $row['item_no'] . ' · ' . $row['size']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Qty</label>
        <input name="quantity[]" type="number" min="1" step="1" required value="<?= us_h($qty) ?>">
      </div>
      <div class="remove">
        <button type="button" class="secondary remove-line">Remove</button>
      </div>
    </div>
    <?php endfor; ?>
  </div>

  <div class="actions">
    <button type="button" class="secondary" id="add_line">Add line</button>
    <button type="submit">Save</button>
    <a class="btn secondary" href="<?= us_h(us_url('stock_in.php')) ?>">Cancel</a>
  </div>
</form>

<form method="post" style="margin-top:1rem" onsubmit="return confirm('Delete this bill?');">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="receipt_id" value="<?= (int) $receiptId ?>">
  <button type="submit" class="secondary danger-btn">Delete bill</button>
</form>

<template id="line_template">
  <div class="line-row stock-in-row">
    <div style="grid-column:1 / -2">
      <label>Item</label>
      <select name="sku_id[]" required>
        <option value="">Select…</option>
        <?php foreach ($skus as $row): ?>
          <option value="<?= (int) $row['id'] ?>">
            <?= us_h($row['item_name'] . ' · ' . $row['item_no'] . ' · ' . $row['size']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Qty</label>
      <input name="quantity[]" type="number" min="1" step="1" required value="1">
    </div>
    <div class="remove">
      <button type="button" class="secondary remove-line">Remove</button>
    </div>
  </div>
</template>
<script>
(function () {
  const rows = document.getElementById('line_rows');
  const template = document.getElementById('line_template');
  document.getElementById('add_line').addEventListener('click', function () {
    rows.appendChild(template.content.cloneNode(true));
  });
  rows.addEventListener('click', function (e) {
    if (e.target.classList.contains('remove-line')) {
      const row = e.target.closest('.line-row');
      if (rows.querySelectorAll('.line-row').length > 1) row.remove();
    }
  });
})();
</script>
<?php us_footer(); ?>
