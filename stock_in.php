<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/company.php';
require_once __DIR__ . '/includes/layout.php';

$ctx = us_require_company();
$user = $ctx['user'];
$company = $ctx['company'];
$companyId = (int) $company['id'];
$pdo = us_db();
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
                $error = 'Each line needs item name, item code, size, and quantity ≥ 1.';
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
            header('Location: ' . us_url('stock_in.php'));
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

$skus = us_stock_rows($pdo, $companyId);

/** @var array<string, array{codes: array<string, list<array{sku_id:int,size:string,on_hand:int}>>>} $byName */
$byName = [];
foreach ($skus as $row) {
    $name = (string) $row['item_name'];
    $code = (string) $row['item_no'];
    if (!isset($byName[$name])) {
        $byName[$name] = ['codes' => []];
    }
    if (!isset($byName[$name]['codes'][$code])) {
        $byName[$name]['codes'][$code] = [];
    }
    $byName[$name]['codes'][$code][] = [
        'sku_id' => (int) $row['id'],
        'size' => (string) $row['size'],
        'on_hand' => (int) $row['on_hand'],
    ];
}
ksort($byName, SORT_NATURAL | SORT_FLAG_CASE);
$itemNames = array_keys($byName);

us_header('Stock in', $user, $company);
?>
<p class="lead">
  Company: <strong><?= us_h($company['name']) ?></strong>.
  Select <strong>item name</strong>, then choose <strong>item code</strong> and <strong>size</strong> for that name, enter quantity, and save.
  Register items under <a href="<?= us_h(us_url('items.php')) ?>">Items</a> first.
</p>
<?php if ($error !== ''): ?>
  <div class="flash flash-error"><?= us_h($error) ?></div>
<?php endif; ?>

<?php if ($itemNames === []): ?>
  <div class="flash flash-warn">
    No items registered for this company.
    <a href="<?= us_h(us_url('items.php')) ?>">Add an item</a> first, then receive stock here.
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
        $selectedName = '';
        $selectedCode = '';
        if ($selectedSku > 0) {
            $skuRow = us_get_sku($pdo, $selectedSku, $companyId);
            if ($skuRow) {
                $selectedName = (string) $skuRow['item_name'];
                $selectedCode = (string) $skuRow['item_no'];
            }
        }
    ?>
    <div class="line-row stock-in-row">
      <div>
        <label>Item name</label>
        <select class="item-name-select" required>
          <option value="">Select name…</option>
          <?php foreach ($itemNames as $name): ?>
            <option value="<?= us_h($name) ?>" <?= $selectedName === $name ? 'selected' : '' ?>>
              <?= us_h($name) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Item code</label>
        <select class="item-code-select" required>
          <option value="">Select name first…</option>
        </select>
      </div>
      <div>
        <label>Size</label>
        <select class="size-select" required>
          <option value="">Select code first…</option>
        </select>
        <input type="hidden" name="sku_id[]" class="sku-id-input" value="<?= $selectedSku > 0 ? (int) $selectedSku : '' ?>"
               data-pref-name="<?= us_h($selectedName) ?>" data-pref-code="<?= us_h($selectedCode) ?>">
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
      <select class="item-name-select" required>
        <option value="">Select name…</option>
        <?php foreach ($itemNames as $name): ?>
          <option value="<?= us_h($name) ?>"><?= us_h($name) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Item code</label>
      <select class="item-code-select" required>
        <option value="">Select name first…</option>
      </select>
    </div>
    <div>
      <label>Size</label>
      <select class="size-select" required>
        <option value="">Select code first…</option>
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
  const byName = <?= json_encode($byName, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
  const rows = document.getElementById('line_rows');
  const template = document.getElementById('line_template');

  function resetSelect(sel, placeholder) {
    sel.innerHTML = '';
    const opt = document.createElement('option');
    opt.value = '';
    opt.textContent = placeholder;
    sel.appendChild(opt);
    sel.disabled = false;
  }

  function fillCodes(row, preferredCode) {
    const nameSel = row.querySelector('.item-name-select');
    const codeSel = row.querySelector('.item-code-select');
    const sizeSel = row.querySelector('.size-select');
    const skuInput = row.querySelector('.sku-id-input');
    const name = nameSel.value;
    resetSelect(codeSel, name ? 'Select item code…' : 'Select name first…');
    resetSelect(sizeSel, 'Select code first…');
    skuInput.value = '';

    if (!name || !byName[name]) {
      return;
    }
    const codes = Object.keys(byName[name].codes || {}).sort();
    codes.forEach(function (code) {
      const opt = document.createElement('option');
      opt.value = code;
      opt.textContent = code;
      if (preferredCode && code === preferredCode) {
        opt.selected = true;
      }
      codeSel.appendChild(opt);
    });
    if (codes.length === 1) {
      codeSel.value = codes[0];
      codeSel.disabled = false;
      fillSizes(row, null);
    } else if (preferredCode) {
      fillSizes(row, null);
    }
  }

  function fillSizes(row, preferredSkuId) {
    const nameSel = row.querySelector('.item-name-select');
    const codeSel = row.querySelector('.item-code-select');
    const sizeSel = row.querySelector('.size-select');
    const skuInput = row.querySelector('.sku-id-input');
    const name = nameSel.value;
    const code = codeSel.value;
    resetSelect(sizeSel, code ? 'Select size…' : 'Select code first…');
    skuInput.value = '';

    if (!name || !code || !byName[name] || !byName[name].codes[code]) {
      return;
    }
    const sizes = byName[name].codes[code] || [];
    if (sizes.length === 0) {
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
    sizes.forEach(function (s) {
      const opt = document.createElement('option');
      opt.value = String(s.sku_id);
      opt.textContent = s.size + ' (on hand ' + s.on_hand + ')';
      if (preferredSkuId && String(s.sku_id) === String(preferredSkuId)) {
        opt.selected = true;
        skuInput.value = String(s.sku_id);
      }
      sizeSel.appendChild(opt);
    });
  }

  function bindRow(row) {
    const nameSel = row.querySelector('.item-name-select');
    const codeSel = row.querySelector('.item-code-select');
    const sizeSel = row.querySelector('.size-select');
    const skuInput = row.querySelector('.sku-id-input');
    nameSel.addEventListener('change', function () {
      fillCodes(row, null);
    });
    codeSel.addEventListener('change', function () {
      fillSizes(row, null);
    });
    sizeSel.addEventListener('change', function () {
      skuInput.value = sizeSel.value || '';
    });
  }

  function initRow(row) {
    bindRow(row);
    const skuInput = row.querySelector('.sku-id-input');
    const preferredSku = skuInput.value || null;
    const prefName = skuInput.getAttribute('data-pref-name') || row.querySelector('.item-name-select').value || '';
    const prefCode = skuInput.getAttribute('data-pref-code') || '';
    if (prefName) {
      row.querySelector('.item-name-select').value = prefName;
      fillCodes(row, prefCode || null);
      if (prefCode) {
        fillSizes(row, preferredSku);
      }
    } else {
      fillCodes(row, null);
    }
  }

  rows.querySelectorAll('.stock-in-row').forEach(initRow);

  document.getElementById('add_line').addEventListener('click', function () {
    const node = template.content.cloneNode(true);
    const row = node.querySelector('.stock-in-row');
    rows.appendChild(node);
    initRow(row);
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
      const codeSel = row.querySelector('.item-code-select');
      const skuInput = row.querySelector('.sku-id-input');
      if (sizeSel.value) {
        skuInput.value = sizeSel.value;
      }
      sizeSel.disabled = false;
      codeSel.disabled = false;
    });
  });
})();
</script>
<?php endif; ?>
<?php us_footer(); ?>
