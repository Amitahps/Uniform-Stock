<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/company.php';
require_once __DIR__ . '/includes/layout.php';

$ctx = us_require_company();
$user = $ctx['user'];
$company = $ctx['company'];
$companyId = (int) $company['id'];
$pdo = us_db();
$catalog = us_item_catalog($pdo, $companyId);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        if ($skuId <= 0 && $qty === 0) {
            continue;
        }
        $lines[] = ['sku_id' => $skuId, 'qty' => $qty];
    }

    if ($billNo === '') {
        $error = 'Bill number is required.';
    } elseif ($receiptDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $receiptDate)) {
        $error = 'A valid receipt date is required.';
    } elseif ($lines === []) {
        $error = 'Add at least one stock line.';
    } else {
        foreach ($lines as $line) {
            if ($line['sku_id'] <= 0 || $line['qty'] < 1) {
                $error = 'Each line needs an existing item/size and quantity ≥ 1.';
                break;
            }
            if (!us_get_sku($pdo, $line['sku_id'], $companyId)) {
                $error = 'One or more selected items are not registered for this company.';
                break;
            }
        }
    }

    if ($error === '') {
        try {
            $pdo->beginTransaction();
            $insR = $pdo->prepare('INSERT INTO receipts (company_id, bill_no, receipt_date) VALUES (?, ?, ?)');
            $insR->execute([$companyId, $billNo, $receiptDate]);
            $receiptId = (int) $pdo->lastInsertId();
            $insL = $pdo->prepare('INSERT INTO receipt_lines (receipt_id, sku_id, quantity) VALUES (?, ?, ?)');
            foreach ($lines as $line) {
                $insL->execute([$receiptId, $line['sku_id'], $line['qty']]);
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
                $error = 'Bill number already exists for this company. Use a new bill number for replenishment.';
            } else {
                $error = 'Could not save bill: ' . $e->getMessage();
            }
        }
    }
}

$itemNos = array_keys($catalog);
us_header('Stock in', $user, $company);
?>
<p class="lead">
  Company: <strong><?= us_h($company['name']) ?></strong>.
  Enter bill number, select registered item code (name and size fill automatically), enter quantity only.
  Register new items under <a href="<?= us_h(us_url('items.php')) ?>">Items</a> first.
</p>
<?php if ($error !== ''): ?>
  <div class="flash flash-error"><?= us_h($error) ?></div>
<?php endif; ?>

<?php if (!$catalog): ?>
  <div class="flash flash-warn">
    No items registered for this company.
    <a href="<?= us_h(us_url('items.php')) ?>">Add an item</a> (name, item code, size) once, then receive stock here.
  </div>
<?php else: ?>
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

  <div class="line-rows stock-in-rows" id="line_rows">
    <?php
    $postedSku = $_POST['sku_id'] ?? [''];
    if (!is_array($postedSku) || $postedSku === []) {
        $postedSku = [''];
    }
    $count = count($postedSku);
    for ($i = 0; $i < $count; $i++):
        $selectedSku = (int) ($_POST['sku_id'][$i] ?? 0);
        $qty = (string) ($_POST['quantity'][$i] ?? '');
        $selectedItemNo = '';
        if ($selectedSku > 0) {
            $skuRow = us_get_sku($pdo, $selectedSku, $companyId);
            if ($skuRow) {
                $selectedItemNo = (string) $skuRow['item_no'];
            }
        }
    ?>
    <div class="line-row stock-in-row">
      <div>
        <label>Item name</label>
        <input type="text" class="item-name-display" readonly tabindex="-1" value="">
      </div>
      <div>
        <label>Item code</label>
        <select class="item-no-select" required>
          <option value="">Select…</option>
          <?php foreach ($itemNos as $no): ?>
            <option value="<?= us_h($no) ?>" <?= $selectedItemNo === $no ? 'selected' : '' ?>>
              <?= us_h($catalog[$no]['item_name'] . ' · ' . $no) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Size</label>
        <select class="size-select" required>
          <option value="">Select item code first…</option>
        </select>
        <input type="hidden" name="sku_id[]" class="sku-id-input" value="<?= $selectedSku > 0 ? (int) $selectedSku : '' ?>">
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
  <div class="line-row stock-in-row">
    <div>
      <label>Item name</label>
      <input type="text" class="item-name-display" readonly tabindex="-1" value="">
    </div>
    <div>
      <label>Item code</label>
      <select class="item-no-select" required>
        <option value="">Select…</option>
        <?php foreach ($itemNos as $no): ?>
          <option value="<?= us_h($no) ?>"><?= us_h($catalog[$no]['item_name'] . ' · ' . $no) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Size</label>
      <select class="size-select" required>
        <option value="">Select item code first…</option>
      </select>
      <input type="hidden" name="sku_id[]" class="sku-id-input" value="">
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
  const catalog = <?= json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
  const rows = document.getElementById('line_rows');
  const template = document.getElementById('line_template');

  function fillRow(row, preferredSkuId) {
    const itemSel = row.querySelector('.item-no-select');
    const nameEl = row.querySelector('.item-name-display');
    const sizeSel = row.querySelector('.size-select');
    const skuInput = row.querySelector('.sku-id-input');
    const itemNo = itemSel.value;
    sizeSel.innerHTML = '';
    skuInput.value = '';

    if (!itemNo || !catalog[itemNo]) {
      nameEl.value = '';
      const opt = document.createElement('option');
      opt.value = '';
      opt.textContent = 'Select item code first…';
      sizeSel.appendChild(opt);
      sizeSel.disabled = false;
      return;
    }

    nameEl.value = catalog[itemNo].item_name || '';
    const sizes = catalog[itemNo].sizes || [];
    if (sizes.length === 0) {
      const opt = document.createElement('option');
      opt.value = '';
      opt.textContent = 'No sizes registered';
      sizeSel.appendChild(opt);
      return;
    }

    if (sizes.length === 1) {
      const s = sizes[0];
      const opt = document.createElement('option');
      opt.value = String(s.sku_id);
      opt.textContent = s.size + ' (on hand ' + s.on_hand + ')';
      opt.selected = true;
      sizeSel.appendChild(opt);
      sizeSel.disabled = true;
      skuInput.value = String(s.sku_id);
      return;
    }

    sizeSel.disabled = false;
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Select size…';
    sizeSel.appendChild(placeholder);
    let matched = false;
    sizes.forEach(function (s) {
      const opt = document.createElement('option');
      opt.value = String(s.sku_id);
      opt.textContent = s.size + ' (on hand ' + s.on_hand + ')';
      if (preferredSkuId && String(s.sku_id) === String(preferredSkuId)) {
        opt.selected = true;
        matched = true;
        skuInput.value = String(s.sku_id);
      }
      sizeSel.appendChild(opt);
    });
    if (!matched) {
      skuInput.value = '';
    }
  }

  function bindRow(row) {
    const itemSel = row.querySelector('.item-no-select');
    const sizeSel = row.querySelector('.size-select');
    const skuInput = row.querySelector('.sku-id-input');
    itemSel.addEventListener('change', function () {
      fillRow(row, null);
    });
    sizeSel.addEventListener('change', function () {
      skuInput.value = sizeSel.value || '';
    });
  }

  rows.querySelectorAll('.stock-in-row').forEach(function (row) {
    bindRow(row);
    const preferred = row.querySelector('.sku-id-input').value;
    fillRow(row, preferred || null);
  });

  document.getElementById('add_line').addEventListener('click', function () {
    const node = template.content.cloneNode(true);
    const row = node.querySelector('.stock-in-row');
    rows.appendChild(node);
    bindRow(row);
    fillRow(row, null);
  });

  rows.addEventListener('click', function (e) {
    if (e.target.classList.contains('remove-line')) {
      const row = e.target.closest('.stock-in-row');
      if (rows.querySelectorAll('.stock-in-row').length > 1) {
        row.remove();
      }
    }
  });

  document.getElementById('stock_in_form').addEventListener('submit', function () {
    rows.querySelectorAll('.stock-in-row').forEach(function (row) {
      const sizeSel = row.querySelector('.size-select');
      const skuInput = row.querySelector('.sku-id-input');
      if (sizeSel.value) {
        skuInput.value = sizeSel.value;
      }
      sizeSel.disabled = false;
    });
  });
})();
</script>
<?php endif; ?>
<?php us_footer(); ?>
