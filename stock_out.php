<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$user = us_require_login();
$pdo = us_db();
$stock = us_stock_rows($pdo);
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
        }
    }

    if ($error === '') {
        try {
            $pdo->beginTransaction();
            foreach ($lines as $line) {
                $onHand = us_on_hand($pdo, $line['sku_id']);
                if ($line['qty'] > $onHand) {
                    $info = $pdo->prepare('SELECT item_name, item_no, size FROM skus WHERE id = ?');
                    $info->execute([$line['sku_id']]);
                    $sku = $info->fetch() ?: ['item_name' => '?', 'item_no' => '?', 'size' => '?'];
                    throw new RuntimeException(
                        'Cannot issue ' . $line['qty'] . ' of '
                        . $sku['item_name'] . ' / ' . $sku['item_no'] . ' / ' . $sku['size']
                        . ' — only ' . $onHand . ' on hand.'
                    );
                }
            }
            $insI = $pdo->prepare('INSERT INTO issues (issue_date) VALUES (?)');
            $insI->execute([$issueDate]);
            $issueId = (int) $pdo->lastInsertId();
            $insL = $pdo->prepare('INSERT INTO issue_lines (issue_id, sku_id, quantity) VALUES (?, ?, ?)');
            foreach ($lines as $line) {
                $insL->execute([$issueId, $line['sku_id'], $line['qty']]);
            }
            $pdo->commit();
            us_flash_set('ok', 'Issue saved (' . count($lines) . ' line(s)).');
            header('Location: stock_out.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

us_header('Stock out', $user);
?>
<p class="lead">Issue stock by date and quantity. Saving deducts on-hand; oversell is blocked.</p>
<?php if ($error !== ''): ?>
  <div class="flash flash-error"><?= us_h($error) ?></div>
<?php endif; ?>

<?php if (!$stock): ?>
  <p class="muted">No stock yet. <a href="stock_in.php">Receive a bill</a> first.</p>
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
    ?>
    <div class="line-row" style="grid-template-columns: 2fr 0.7fr auto;">
      <div>
        <label>Item / size</label>
        <select name="sku_id[]" required>
          <option value="">Select…</option>
          <?php foreach ($stock as $row): ?>
            <option value="<?= (int) $row['id'] ?>" <?= (string) $row['id'] === $selected ? 'selected' : '' ?>>
              <?= us_h($row['item_name'] . ' · ' . $row['item_no'] . ' · ' . $row['size'] . ' (on hand ' . $row['on_hand'] . ')') ?>
            </option>
          <?php endforeach; ?>
        </select>
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
  <div class="line-row" style="grid-template-columns: 2fr 0.7fr auto;">
    <div>
      <label>Item / size</label>
      <select name="sku_id[]" required>
        <option value="">Select…</option>
        <?php foreach ($stock as $row): ?>
          <option value="<?= (int) $row['id'] ?>">
            <?= us_h($row['item_name'] . ' · ' . $row['item_no'] . ' · ' . $row['size'] . ' (on hand ' . $row['on_hand'] . ')') ?>
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
      if (rows.querySelectorAll('.line-row').length > 1) {
        row.remove();
      }
    }
  });
})();
</script>
<?php endif; ?>
<?php us_footer(); ?>
