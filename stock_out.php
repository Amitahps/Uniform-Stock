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
$stock = us_stock_rows($pdo, $companyId, $school);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $issueDate = trim((string) ($_POST['issue_date'] ?? ''));
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

    if ($issueDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate)) {
        $error = 'A valid issue date is required.';
    } elseif ($lines === []) {
        $error = 'Add at least one issue line.';
    } else {
        foreach ($lines as $line) {
            if ($line['sku_id'] <= 0 || $line['qty'] < 1) {
                $error = 'Each line needs an item/size and quantity ≥ 1.';
                break;
            }
            if (!us_get_sku($pdo, $line['sku_id'], $companyId, $school)) {
                $error = 'One or more selected items are not in this company and school.';
                break;
            }
        }
    }

    if ($error === '') {
        try {
            $pdo->beginTransaction();
            foreach ($lines as $line) {
                $onHand = us_on_hand($pdo, $line['sku_id']);
                if ($line['qty'] > $onHand) {
                    $sku = us_get_sku($pdo, $line['sku_id'], $companyId, $school) ?: ['item_name' => '?', 'item_no' => '?', 'size' => '?'];
                    throw new RuntimeException(
                        'Cannot issue ' . $line['qty'] . ' of '
                        . $sku['item_name'] . ' / ' . $sku['item_no'] . ' / ' . $sku['size']
                        . ' — only ' . $onHand . ' on hand.'
                    );
                }
            }
            $insI = $pdo->prepare('INSERT INTO issues (company_id, school, issue_date) VALUES (?, ?, ?)');
            $insI->execute([$companyId, $school, $issueDate]);
            $issueId = (int) $pdo->lastInsertId();
            $insL = $pdo->prepare('INSERT INTO issue_lines (issue_id, sku_id, quantity) VALUES (?, ?, ?)');
            foreach ($lines as $line) {
                $insL->execute([$issueId, $line['sku_id'], $line['qty']]);
            }
            $pdo->commit();
            us_flash_set('ok', 'Issue saved (' . count($lines) . ' line(s)).');
            header('Location: ' . us_url('stock_out.php'));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

/** Map sku id → on_hand for the form (current company + school). */
$onHandById = [];
foreach ($stock as $row) {
    $onHandById[(int) $row['id']] = (int) $row['on_hand'];
}

$canEdit = us_has_power($user);
$recent = us_list_issues($pdo, $companyId, $school);

us_header('Stock out', $user, $company, $school);
?>
<?php if ($error !== ''): ?>
  <div class="flash flash-error"><?= us_h($error) ?></div>
<?php endif; ?>

<?php if (!$stock): ?>
  <p class="muted">No stock. <a href="<?= us_h(us_url('stock_in.php')) ?>">Stock in</a></p>
<?php else: ?>
<form method="post" id="stock_out_form">
  <div style="max-width:280px">
    <label for="issue_date">Issue date</label>
    <input id="issue_date" name="issue_date" type="date" required value="<?= us_h($_POST['issue_date'] ?? date('Y-m-d')) ?>">
  </div>

  <div class="line-rows" id="line_rows" style="margin-top:1rem">
    <?php
    $postedSku = $_POST['sku_id'] ?? [''];
    if (!is_array($postedSku) || $postedSku === []) {
        $postedSku = [''];
    }
    $count = count($postedSku);
    for ($i = 0; $i < $count; $i++):
        $selected = (string) ($_POST['sku_id'][$i] ?? '');
        $qty = (string) ($_POST['quantity'][$i] ?? '');
        $onHandShow = $selected !== '' && isset($onHandById[(int) $selected])
            ? (string) $onHandById[(int) $selected]
            : '';
    ?>
    <div class="line-row stock-out-row">
      <div>
        <label>Item / size</label>
        <select name="sku_id[]" class="sku-select" required>
          <option value="" data-on-hand="">Select…</option>
          <?php foreach ($stock as $row): ?>
            <option
              value="<?= (int) $row['id'] ?>"
              data-on-hand="<?= (int) $row['on_hand'] ?>"
              <?= (string) $row['id'] === $selected ? 'selected' : '' ?>
            >
              <?= us_h($row['item_name'] . ' · ' . $row['item_no'] . ' · ' . $row['size']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>On hand</label>
        <input type="text" class="on-hand-display" readonly tabindex="-1" value="<?= us_h($onHandShow) ?>" placeholder="—">
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
    <button type="submit">Save issue</button>
  </div>
</form>

<template id="line_template">
  <div class="line-row stock-out-row">
    <div>
      <label>Item / size</label>
      <select name="sku_id[]" class="sku-select" required>
        <option value="" data-on-hand="">Select…</option>
        <?php foreach ($stock as $row): ?>
          <option value="<?= (int) $row['id'] ?>" data-on-hand="<?= (int) $row['on_hand'] ?>">
            <?= us_h($row['item_name'] . ' · ' . $row['item_no'] . ' · ' . $row['size']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>On hand</label>
      <input type="text" class="on-hand-display" readonly tabindex="-1" value="" placeholder="—">
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

  function syncOnHand(row) {
    const sel = row.querySelector('.sku-select');
    const display = row.querySelector('.on-hand-display');
    if (!sel || !display) return;
    const opt = sel.options[sel.selectedIndex];
    const val = opt && opt.getAttribute('data-on-hand') !== null
      ? opt.getAttribute('data-on-hand')
      : '';
    display.value = sel.value && val !== '' ? val : '';
  }

  function bindRow(row) {
    const sel = row.querySelector('.sku-select');
    if (!sel || sel.dataset.bound === '1') return;
    sel.dataset.bound = '1';
    sel.addEventListener('change', function () {
      syncOnHand(row);
    });
    syncOnHand(row);
  }

  rows.querySelectorAll('.line-row').forEach(bindRow);

  document.getElementById('add_line').addEventListener('click', function () {
    const node = template.content.cloneNode(true);
    const row = node.querySelector('.line-row');
    rows.appendChild(node);
    bindRow(row);
  });

  rows.addEventListener('click', function (e) {
    if (e.target.classList.contains('remove-line')) {
      const row = e.target.closest('.line-row');
      if (rows.querySelectorAll('.line-row').length > 1) {
        row.remove();
      }
    }
  });
})();
</script>
<?php endif; ?>

<?php if ($recent): ?>
  <h2 style="margin-top:1.5rem;font-size:1.1rem">Recent stock out</h2>
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th class="num">Lines</th>
        <th class="num">Qty</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($recent as $r): ?>
        <tr>
          <td><?= us_h((string) $r['issue_date']) ?></td>
          <td class="num"><?= (int) $r['line_count'] ?></td>
          <td class="num"><?= (int) $r['qty_total'] ?></td>
          <td class="row-actions">
            <?php if ($canEdit): ?>
              <a class="btn secondary" href="<?= us_h(us_url('stock_out_edit.php?id=' . (int) $r['id'])) ?>">Edit</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
<?php us_footer(); ?>
