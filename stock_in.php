<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$user = us_require_login();
$pdo = us_db();
$existing = us_stock_rows($pdo);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $billNo = trim((string) ($_POST['bill_no'] ?? ''));
    $receiptDate = trim((string) ($_POST['receipt_date'] ?? ''));
    $itemNos = $_POST['item_no'] ?? [];
    $itemNames = $_POST['item_name'] ?? [];
    $sizes = $_POST['size'] ?? [];
    $qtys = $_POST['quantity'] ?? [];

    if (!is_array($itemNos)) {
        $itemNos = [];
    }
    if (!is_array($itemNames)) {
        $itemNames = [];
    }
    if (!is_array($sizes)) {
        $sizes = [];
    }
    if (!is_array($qtys)) {
        $qtys = [];
    }

    $lines = [];
    $n = max(count($itemNos), count($itemNames), count($sizes), count($qtys));
    for ($i = 0; $i < $n; $i++) {
        $itemNo = trim((string) ($itemNos[$i] ?? ''));
        $itemName = trim((string) ($itemNames[$i] ?? ''));
        $size = trim((string) ($sizes[$i] ?? ''));
        $qty = (int) ($qtys[$i] ?? 0);
        if ($itemNo === '' && $itemName === '' && $size === '' && $qty === 0) {
            continue;
        }
        $lines[] = compact('itemNo', 'itemName', 'size', 'qty');
    }

    if ($billNo === '') {
        $error = 'Bill number is required.';
    } elseif ($receiptDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $receiptDate)) {
        $error = 'A valid receipt date is required.';
    } elseif ($lines === []) {
        $error = 'Add at least one stock line.';
    } else {
        foreach ($lines as $line) {
            if ($line['itemNo'] === '' || $line['itemName'] === '' || $line['size'] === '' || $line['qty'] < 1) {
                $error = 'Each line needs item name, item no, size, and quantity ≥ 1.';
                break;
            }
        }
    }

    if ($error === '') {
        try {
            $pdo->beginTransaction();
            $insR = $pdo->prepare('INSERT INTO receipts (bill_no, receipt_date) VALUES (?, ?)');
            $insR->execute([$billNo, $receiptDate]);
            $receiptId = (int) $pdo->lastInsertId();
            $insL = $pdo->prepare('INSERT INTO receipt_lines (receipt_id, sku_id, quantity) VALUES (?, ?, ?)');
            foreach ($lines as $line) {
                $skuId = us_find_or_create_sku($pdo, $line['itemNo'], $line['itemName'], $line['size']);
                $insL->execute([$receiptId, $skuId, $line['qty']]);
            }
            $pdo->commit();
            us_flash_set('ok', 'Bill ' . $billNo . ' saved (' . count($lines) . ' line(s)).');
            header('Location: stock_in.php');
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                $error = 'Bill number already exists. Use a new bill number for replenishment.';
            } else {
                $error = 'Could not save bill: ' . $e->getMessage();
            }
        }
    }
}

us_header('Stock in', $user);
?>
<p class="lead">Enter a bill number and one or more lines. Same item + size under a new bill replenishes stock.</p>
<?php if ($error !== ''): ?>
  <div class="flash flash-error"><?= us_h($error) ?></div>
<?php endif; ?>

<?php if ($existing): ?>
<details style="margin-bottom:1rem">
  <summary><strong>Fill from existing SKU</strong> (replenish)</summary>
  <div class="actions" style="margin-top:0.75rem">
    <select id="existing_sku">
      <option value="">Select item + size…</option>
      <?php foreach ($existing as $row): ?>
        <option
          value="<?= us_h($row['item_no'] . '|' . $row['item_name'] . '|' . $row['size']) ?>"
          data-item-no="<?= us_h($row['item_no']) ?>"
          data-item-name="<?= us_h($row['item_name']) ?>"
          data-size="<?= us_h($row['size']) ?>"
        >
          <?= us_h($row['item_name'] . ' · ' . $row['item_no'] . ' · ' . $row['size'] . ' (on hand ' . $row['on_hand'] . ')') ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button type="button" class="secondary" id="add_existing">Add to form</button>
  </div>
</details>
<?php endif; ?>

<form method="post" id="stock_in_form">
  <div class="grid grid-2">
    <div>
      <label for="bill_no">Bill number</label>
      <input id="bill_no" name="bill_no" required value="<?= us_h($_POST['bill_no'] ?? '') ?>">
    </div>
    <div>
      <label for="receipt_date">Receipt date</label>
      <input id="receipt_date" name="receipt_date" type="date" required value="<?= us_h($_POST['receipt_date'] ?? date('Y-m-d')) ?>">
    </div>
  </div>

  <div class="line-rows" id="line_rows">
    <?php
    $postedNos = $_POST['item_no'] ?? [''];
    if (!is_array($postedNos) || $postedNos === []) {
        $postedNos = [''];
    }
    $count = count($postedNos);
    for ($i = 0; $i < $count; $i++):
        $itemNo = (string) ($_POST['item_no'][$i] ?? '');
        $itemName = (string) ($_POST['item_name'][$i] ?? '');
        $size = (string) ($_POST['size'][$i] ?? '');
        $qty = (string) ($_POST['quantity'][$i] ?? '');
    ?>
    <div class="line-row">
      <div>
        <label>Item name</label>
        <input name="item_name[]" required value="<?= us_h($itemName) ?>">
      </div>
      <div>
        <label>Item no</label>
        <input name="item_no[]" required value="<?= us_h($itemNo) ?>">
      </div>
      <div>
        <label>Size</label>
        <input name="size[]" required value="<?= us_h($size) ?>">
      </div>
      <div>
        <label>Qty</label>
        <input name="quantity[]" type="number" min="1" step="1" required value="<?= us_h($qty !== '' ? $qty : '1') ?>">
      </div>
      <div class="remove">
        <button type="button" class="secondary remove-line">Remove</button>
      </div>
    </div>
    <?php endfor; ?>
  </div>

  <div class="actions">
    <button type="button" class="secondary" id="add_line">Add line</button>
    <button type="submit">Save bill</button>
  </div>
</form>

<template id="line_template">
  <div class="line-row">
    <div>
      <label>Item name</label>
      <input name="item_name[]" required>
    </div>
    <div>
      <label>Item no</label>
      <input name="item_no[]" required>
    </div>
    <div>
      <label>Size</label>
      <input name="size[]" required>
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
      if (rows.querySelectorAll('.line-row').length > 1) {
        row.remove();
      }
    }
  });
  const addExisting = document.getElementById('add_existing');
  if (addExisting) {
    addExisting.addEventListener('click', function () {
      const sel = document.getElementById('existing_sku');
      const opt = sel.options[sel.selectedIndex];
      if (!opt || !opt.value) return;
      const node = template.content.cloneNode(true);
      node.querySelector('input[name="item_name[]"]').value = opt.dataset.itemName || '';
      node.querySelector('input[name="item_no[]"]').value = opt.dataset.itemNo || '';
      node.querySelector('input[name="size[]"]').value = opt.dataset.size || '';
      rows.appendChild(node);
    });
  }
})();
</script>
<?php us_footer(); ?>
