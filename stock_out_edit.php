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

$issueId = (int) ($_GET['id'] ?? $_POST['issue_id'] ?? 0);
$issue = $issueId > 0 ? us_get_issue($pdo, $issueId, $companyId, $school) : null;
if (!$issue) {
    us_flash_set('error', 'Stock-out entry not found.');
    header('Location: ' . us_url('stock_out.php'));
    exit;
}

$stock = us_stock_rows($pdo, $companyId, $school);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save');
    try {
        if ($action === 'delete') {
            us_delete_issue($pdo, $companyId, $school, $issueId);
            us_flash_set('ok', 'Stock-out deleted.');
            header('Location: ' . us_url('stock_out.php'));
            exit;
        }
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
            if ($skuId <= 0 || $qty < 1) {
                continue;
            }
            $lines[] = ['sku_id' => $skuId, 'qty' => $qty];
        }
        us_update_issue($pdo, $companyId, $school, $issueId, $issueDate, $lines);
        us_flash_set('ok', 'Stock-out updated.');
        header('Location: ' . us_url('stock_out.php'));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$lines = us_get_issue_lines($pdo, $issueId);
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

$availById = [];
foreach ($stock as $row) {
    $sid = (int) $row['id'];
    $availById[$sid] = us_on_hand_for_issue_edit($pdo, $sid, $issueId);
}

us_header('Edit stock out', $user, $company, $school);
?>
<?php if ($error !== ''): ?>
  <div class="flash flash-error"><?= us_h($error) ?></div>
<?php endif; ?>

<form method="post" id="edit_stock_out">
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="issue_id" value="<?= (int) $issueId ?>">
  <div style="max-width:280px">
    <label for="issue_date">Issue date</label>
    <input id="issue_date" name="issue_date" type="date" required
           value="<?= us_h((string) ($_POST['issue_date'] ?? $issue['issue_date'])) ?>">
  </div>

  <div class="line-rows" id="line_rows" style="margin-top:0.85rem">
    <?php
    $count = count($postedSku);
    for ($i = 0; $i < $count; $i++):
        $selected = (string) ($postedSku[$i] ?? '');
        $qty = (string) ($postedQty[$i] ?? '1');
        $availShow = $selected !== '' && isset($availById[(int) $selected])
            ? (string) $availById[(int) $selected]
            : '';
    ?>
    <div class="line-row stock-out-row">
      <div>
        <label>Item / size</label>
        <select name="sku_id[]" class="sku-select" required>
          <option value="" data-on-hand="">Select…</option>
          <?php foreach ($stock as $row):
              $sid = (int) $row['id'];
              $avail = $availById[$sid] ?? (int) $row['on_hand'];
          ?>
            <option value="<?= $sid ?>" data-on-hand="<?= (int) $avail ?>"
              <?= (string) $sid === $selected ? 'selected' : '' ?>>
              <?= us_h($row['item_name'] . ' · ' . $row['item_no'] . ' · ' . $row['size']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Available</label>
        <input type="text" class="on-hand-display" readonly tabindex="-1" value="<?= us_h($availShow) ?>">
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
    <a class="btn secondary" href="<?= us_h(us_url('stock_out.php')) ?>">Cancel</a>
  </div>
</form>

<form method="post" style="margin-top:1rem" onsubmit="return confirm('Delete this stock-out?');">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="issue_id" value="<?= (int) $issueId ?>">
  <button type="submit" class="secondary danger-btn">Delete stock-out</button>
</form>

<template id="line_template">
  <div class="line-row stock-out-row">
    <div>
      <label>Item / size</label>
      <select name="sku_id[]" class="sku-select" required>
        <option value="" data-on-hand="">Select…</option>
        <?php foreach ($stock as $row):
            $sid = (int) $row['id'];
            $avail = $availById[$sid] ?? (int) $row['on_hand'];
        ?>
          <option value="<?= $sid ?>" data-on-hand="<?= (int) $avail ?>">
            <?= us_h($row['item_name'] . ' · ' . $row['item_no'] . ' · ' . $row['size']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Available</label>
      <input type="text" class="on-hand-display" readonly tabindex="-1" value="">
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
    const val = opt && opt.getAttribute('data-on-hand') !== null ? opt.getAttribute('data-on-hand') : '';
    display.value = sel.value && val !== '' ? val : '';
  }
  function bindRow(row) {
    const sel = row.querySelector('.sku-select');
    if (!sel || sel.dataset.bound === '1') return;
    sel.dataset.bound = '1';
    sel.addEventListener('change', function () { syncOnHand(row); });
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
      if (rows.querySelectorAll('.line-row').length > 1) row.remove();
    }
  });
})();
</script>
<?php us_footer(); ?>
