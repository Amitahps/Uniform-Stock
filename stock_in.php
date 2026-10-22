<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/company.php';
require_once __DIR__ . '/includes/layout.php';

$ctx = us_require_scope();
$user = $ctx['user'];
$company = $ctx['company'];
$school = $ctx['school'];
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

    // Sum quantities if the same SKU appears more than once (e.g. two blocks).
    $totals = [];
    $n = max(count($skuIds), count($qtys));
    for ($i = 0; $i < $n; $i++) {
        $skuId = (int) ($skuIds[$i] ?? 0);
        $qtyRaw = trim((string) ($qtys[$i] ?? ''));
        if ($skuId <= 0 || $qtyRaw === '') {
            continue;
        }
        $qty = (int) $qtyRaw;
        if ($qty < 1) {
            continue;
        }
        if (!isset($totals[$skuId])) {
            $totals[$skuId] = 0;
        }
        $totals[$skuId] += $qty;
    }

    $lines = [];
    foreach ($totals as $skuId => $qty) {
        $lines[] = ['sku_id' => (int) $skuId, 'qty' => (int) $qty];
    }

    if ($billNo === '') {
        $error = 'Bill number is required.';
    } elseif ($receiptDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $receiptDate)) {
        $error = 'A valid receipt date is required.';
    } elseif ($lines === []) {
        $error = 'Enter quantity for at least one item code/size.';
    } else {
        foreach ($lines as $line) {
            if (!us_get_sku($pdo, $line['sku_id'], $companyId, $school)) {
                $error = 'One or more selected items are not registered for this company and school.';
                break;
            }
        }
    }

    if ($error === '') {
        try {
            $pdo->beginTransaction();
            $insR = $pdo->prepare('INSERT INTO receipts (company_id, school, bill_no, receipt_date) VALUES (?, ?, ?, ?)');
            $insR->execute([$companyId, $school, $billNo, $receiptDate]);
            $receiptId = (int) $pdo->lastInsertId();
            $insL = $pdo->prepare('INSERT INTO receipt_lines (receipt_id, sku_id, quantity) VALUES (?, ?, ?)');
            foreach ($lines as $line) {
                $insL->execute([$receiptId, $line['sku_id'], $line['qty']]);
            }
            $pdo->commit();
            us_flash_set('ok', 'Bill ' . $billNo . ' saved (' . count($lines) . ' SKU line(s)).');
            header('Location: ' . us_url('stock_in.php'));
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                $error = 'Bill number already exists for this company and school. Use a new bill number for replenishment.';
            } else {
                $error = 'Could not save bill: ' . $e->getMessage();
            }
        }
    }
}

$skus = us_stock_rows($pdo, $companyId, $school);

/** @var array<string, list<array{sku_id:int,item_no:string,size:string,on_hand:int}>> $byName */
$byName = [];
foreach ($skus as $row) {
    $name = (string) $row['item_name'];
    if (!isset($byName[$name])) {
        $byName[$name] = [];
    }
    $byName[$name][] = [
        'sku_id' => (int) $row['id'],
        'item_no' => (string) $row['item_no'],
        'size' => (string) $row['size'],
        'on_hand' => (int) $row['on_hand'],
    ];
}
ksort($byName, SORT_NATURAL | SORT_FLAG_CASE);
foreach ($byName as $name => $list) {
    usort($byName[$name], static function (array $a, array $b): int {
        $c = strnatcasecmp($a['item_no'], $b['item_no']);
        return $c !== 0 ? $c : strnatcasecmp($a['size'], $b['size']);
    });
}
$itemNames = array_keys($byName);

$canEdit = us_has_power($user);
$recent = us_list_receipts($pdo, $companyId, $school);

us_header('Stock in', $user, $company, $school);
?>
<?php if ($error !== ''): ?>
  <div class="flash flash-error"><?= us_h($error) ?></div>
<?php endif; ?>

<?php if ($itemNames === []): ?>
  <div class="flash flash-warn">No items. <a href="<?= us_h(us_url('items.php')) ?>">Items</a></div>
<?php else: ?>
<form method="post" id="stock_in_form">
  <div class="grid grid-2" style="margin-bottom:1rem">
    <div>
      <label for="bill_no">Bill number</label>
      <input id="bill_no" name="bill_no" required value="<?= us_h($_POST['bill_no'] ?? '') ?>">
    </div>
    <div>
      <label for="receipt_date">Receipt date</label>
      <input id="receipt_date" name="receipt_date" type="date" required value="<?= us_h($_POST['receipt_date'] ?? date('Y-m-d')) ?>">
    </div>
  </div>

  <div id="item_blocks" class="item-blocks"></div>

  <div class="actions">
    <button type="button" class="secondary" id="add_item_block">Add item name</button>
    <button type="submit">Save bill</button>
  </div>
</form>

<template id="item_block_template">
  <section class="item-block">
    <div class="item-block-head">
      <div style="flex:1;min-width:200px">
        <label>Item name</label>
        <select class="item-name-select" required>
          <option value="">Select item name…</option>
          <?php foreach ($itemNames as $name): ?>
            <option value="<?= us_h($name) ?>"><?= us_h($name) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="button" class="secondary remove-block">Remove item</button>
    </div>
    <div class="sku-table-wrap muted" style="margin-top:0.75rem">Select an item name to show its codes and sizes.</div>
  </section>
</template>

<script>
(function () {
  const byName = <?= json_encode($byName, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
  const blocks = document.getElementById('item_blocks');
  const template = document.getElementById('item_block_template');

  function renderSkuTable(block, name) {
    const wrap = block.querySelector('.sku-table-wrap');
    wrap.innerHTML = '';
    wrap.classList.remove('muted');
    if (!name || !byName[name] || byName[name].length === 0) {
      wrap.classList.add('muted');
      wrap.textContent = 'Select an item name to show its codes and sizes.';
      return;
    }
    const table = document.createElement('table');
    table.innerHTML = '<thead><tr><th>Item code</th><th>Size</th><th class="num">On hand</th><th class="num">Qty in</th></tr></thead>';
    const tbody = document.createElement('tbody');
    byName[name].forEach(function (sku) {
      const tr = document.createElement('tr');
      tr.innerHTML =
        '<td></td><td></td><td class="num"></td>' +
        '<td class="num"><input type="hidden" name="sku_id[]" value="">' +
        '<input name="quantity[]" type="number" min="0" step="1" placeholder="0" class="qty-input"></td>';
      tr.cells[0].textContent = sku.item_no;
      tr.cells[1].textContent = sku.size;
      tr.cells[2].textContent = String(sku.on_hand);
      tr.querySelector('input[name="sku_id[]"]').value = String(sku.sku_id);
      tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    wrap.appendChild(table);
  }

  function usedNames(exceptSel) {
    const names = [];
    blocks.querySelectorAll('.item-name-select').forEach(function (sel) {
      if (sel !== exceptSel && sel.value) {
        names.push(sel.value);
      }
    });
    return names;
  }

  function refreshNameOptions(block) {
    const sel = block.querySelector('.item-name-select');
    const current = sel.value;
    const taken = usedNames(sel);
    Array.prototype.forEach.call(sel.options, function (opt) {
      if (!opt.value) return;
      opt.disabled = taken.indexOf(opt.value) !== -1 && opt.value !== current;
    });
  }

  function refreshAllNameOptions() {
    blocks.querySelectorAll('.item-block').forEach(refreshNameOptions);
  }

  function bindBlock(block) {
    const sel = block.querySelector('.item-name-select');
    sel.addEventListener('change', function () {
      renderSkuTable(block, sel.value);
      refreshAllNameOptions();
    });
    block.querySelector('.remove-block').addEventListener('click', function () {
      if (blocks.querySelectorAll('.item-block').length <= 1) {
        sel.value = '';
        renderSkuTable(block, '');
        refreshAllNameOptions();
        return;
      }
      block.remove();
      refreshAllNameOptions();
    });
  }

  function addBlock() {
    const node = template.content.cloneNode(true);
    const block = node.querySelector('.item-block');
    blocks.appendChild(node);
    bindBlock(block);
    refreshAllNameOptions();
    return block;
  }

  document.getElementById('add_item_block').addEventListener('click', function () {
    addBlock();
  });

  addBlock();
})();
</script>
<?php endif; ?>

<?php if ($recent): ?>
  <h2 style="margin-top:1.5rem;font-size:1.1rem">Recent bills</h2>
  <table>
    <thead>
      <tr>
        <th>Bill</th>
        <th>Date</th>
        <th class="num">Lines</th>
        <th class="num">Qty</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($recent as $r): ?>
        <tr>
          <td><?= us_h((string) $r['bill_no']) ?></td>
          <td><?= us_h((string) $r['receipt_date']) ?></td>
          <td class="num"><?= (int) $r['line_count'] ?></td>
          <td class="num"><?= (int) $r['qty_total'] ?></td>
          <td class="row-actions">
            <?php if ($canEdit): ?>
              <a class="btn secondary" href="<?= us_h(us_url('stock_in_edit.php?id=' . (int) $r['id'])) ?>">Edit</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
<?php us_footer(); ?>
