<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/company.php';
require_once __DIR__ . '/includes/layout.php';

$ctx = us_require_company();
$user = $ctx['user'];
$company = $ctx['company'];
$companyId = (int) $company['id'];
$pdo = us_db();

$lookupSkuId = isset($_GET['sku_id']) ? (int) $_GET['sku_id'] : 0;
$mode = (string) ($_GET['mode'] ?? 'lookup');
if (!in_array($mode, ['lookup', 'statement', 'movements'], true)) {
    $mode = 'lookup';
}

$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$itemNoFilter = trim((string) ($_GET['item_no'] ?? ''));
if ($dateFrom === '') {
    $dateFrom = date('Y-m-01');
}
if ($dateTo === '') {
    $dateTo = date('Y-m-d');
}

$all = us_stock_rows($pdo, $companyId);
$itemOptions = $pdo->prepare('SELECT DISTINCT item_no, item_name FROM skus WHERE company_id = ? ORDER BY item_name COLLATE NOCASE');
$itemOptions->execute([$companyId]);
$itemOptions = $itemOptions->fetchAll();

$lookup = null;
if ($lookupSkuId > 0) {
    foreach ($all as $row) {
        if ((int) $row['id'] === $lookupSkuId) {
            $lookup = $row;
            break;
        }
    }
}

$movementError = '';
$movements = [];
if ($mode === 'movements') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $movementError = 'Choose valid From and To dates.';
    } elseif ($dateFrom > $dateTo) {
        $movementError = 'From date must be on or before To date.';
    } else {
        $movements = us_movement_rows(
            $pdo,
            $companyId,
            $dateFrom,
            $dateTo,
            $itemNoFilter !== '' ? $itemNoFilter : null
        );
    }
}

us_header('Reports', $user, $company);
?>
<p class="lead">
  Company: <strong><?= us_h($company['name']) ?></strong>.
  Quantity lookup, item-wise statement, or date-range stock movements.
</p>

<div class="actions" style="margin-bottom:1rem">
  <a class="btn <?= $mode === 'lookup' ? '' : 'secondary' ?>" href="?mode=lookup">Quantity lookup</a>
  <a class="btn <?= $mode === 'statement' ? '' : 'secondary' ?>" href="?mode=statement">Item-wise statement</a>
  <a class="btn <?= $mode === 'movements' ? '' : 'secondary' ?>" href="?mode=movements">Date-range movements</a>
</div>

<?php if ($mode === 'lookup'): ?>
  <form method="get" class="grid grid-2" style="max-width:720px">
    <input type="hidden" name="mode" value="lookup">
    <div>
      <label for="sku_id">Stock item</label>
      <select id="sku_id" name="sku_id" required>
        <option value="">Select…</option>
        <?php foreach ($all as $row): ?>
          <option value="<?= (int) $row['id'] ?>" <?= (int) $row['id'] === $lookupSkuId ? 'selected' : '' ?>>
            <?= us_h($row['item_name'] . ' · ' . $row['item_no'] . ' · ' . $row['size']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="actions" style="align-self:end">
      <button type="submit">Show quantity</button>
    </div>
  </form>

  <?php if ($lookup): ?>
    <table>
      <thead>
        <tr>
          <th>Item name</th>
          <th>Item code</th>
          <th>Size</th>
          <th class="num">On hand</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><?= us_h($lookup['item_name']) ?></td>
          <td><?= us_h($lookup['item_no']) ?></td>
          <td><?= us_h($lookup['size']) ?></td>
          <td class="num <?= (int) $lookup['on_hand'] < US_DEFICIT_THRESHOLD ? 'low' : '' ?>">
            <?= (int) $lookup['on_hand'] ?>
          </td>
        </tr>
      </tbody>
    </table>
  <?php elseif ($lookupSkuId > 0): ?>
    <p class="muted">SKU not found in this company.</p>
  <?php endif; ?>

<?php elseif ($mode === 'statement'): ?>
  <?php if (!$all): ?>
    <p class="muted">No stock recorded yet for this company.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Item name</th>
          <th>Item code</th>
          <th>Size</th>
          <th class="num">On hand</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($all as $row): ?>
          <tr>
            <td><?= us_h($row['item_name']) ?></td>
            <td><?= us_h($row['item_no']) ?></td>
            <td><?= us_h($row['size']) ?></td>
            <td class="num <?= (int) $row['on_hand'] < US_DEFICIT_THRESHOLD ? 'low' : '' ?>">
              <?= (int) $row['on_hand'] ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

<?php else: ?>
  <p class="muted" style="margin-top:0">
    <strong>Qty In</strong> / <strong>Qty Out</strong> = movements with dates in the range (inclusive).
    <strong>Balance</strong> = closing on-hand as of the <em>To</em> date (all stock in/out on or before that date).
  </p>
  <form method="get" class="grid grid-2" style="max-width:820px;margin-bottom:1rem">
    <input type="hidden" name="mode" value="movements">
    <div>
      <label for="date_from">From date</label>
      <input id="date_from" name="date_from" type="date" required value="<?= us_h($dateFrom) ?>">
    </div>
    <div>
      <label for="date_to">To date</label>
      <input id="date_to" name="date_to" type="date" required value="<?= us_h($dateTo) ?>">
    </div>
    <div>
      <label for="item_no">Item (optional)</label>
      <select id="item_no" name="item_no">
        <option value="">All items</option>
        <?php foreach ($itemOptions as $it): ?>
          <option value="<?= us_h($it['item_no']) ?>" <?= $itemNoFilter === $it['item_no'] ? 'selected' : '' ?>>
            <?= us_h($it['item_name'] . ' (' . $it['item_no'] . ')') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="actions" style="align-self:end">
      <button type="submit">Show movements</button>
    </div>
  </form>

  <?php if ($movementError !== ''): ?>
    <div class="flash flash-error"><?= us_h($movementError) ?></div>
  <?php elseif (!$movements): ?>
    <p class="muted">No SKUs for this company<?= $itemNoFilter !== '' ? ' / item filter' : '' ?>.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Item name</th>
          <th>Item code</th>
          <th>Size</th>
          <th class="num">Qty in</th>
          <th class="num">Qty out</th>
          <th class="num">Balance (as of <?= us_h($dateTo) ?>)</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $sumIn = 0;
        $sumOut = 0;
        foreach ($movements as $row):
            $sumIn += (int) $row['qty_in'];
            $sumOut += (int) $row['qty_out'];
        ?>
          <tr>
            <td><?= us_h($row['item_name']) ?></td>
            <td><?= us_h($row['item_no']) ?></td>
            <td><?= us_h($row['size']) ?></td>
            <td class="num"><?= (int) $row['qty_in'] ?></td>
            <td class="num"><?= (int) $row['qty_out'] ?></td>
            <td class="num <?= (int) $row['balance'] < US_DEFICIT_THRESHOLD ? 'low' : '' ?>">
              <?= (int) $row['balance'] ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <th colspan="3">Totals (in / out)</th>
          <th class="num"><?= (int) $sumIn ?></th>
          <th class="num"><?= (int) $sumOut ?></th>
          <th></th>
        </tr>
      </tfoot>
    </table>
  <?php endif; ?>
<?php endif; ?>
<?php us_footer(); ?>
