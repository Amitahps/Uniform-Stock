<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/company.php';
require_once __DIR__ . '/includes/layout.php';

$ctx = us_require_company();
$user = $ctx['user'];
$company = $ctx['company'];
$companyId = (int) $company['id'];
$pdo = us_db();

$filter = (string) ($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'item', 'size'], true)) {
    $filter = 'all';
}
$itemNo = trim((string) ($_GET['item_no'] ?? ''));
$size = trim((string) ($_GET['size'] ?? ''));

$itemStmt = $pdo->prepare('SELECT DISTINCT item_no, item_name FROM skus WHERE company_id = ? ORDER BY item_name COLLATE NOCASE');
$itemStmt->execute([$companyId]);
$items = $itemStmt->fetchAll();
$sizeStmt = $pdo->prepare('SELECT DISTINCT size FROM skus WHERE company_id = ? ORDER BY size COLLATE NOCASE');
$sizeStmt->execute([$companyId]);
$sizes = $sizeStmt->fetchAll();

$rows = us_stock_rows(
    $pdo,
    $companyId,
    $filter === 'item' ? $itemNo : null,
    $filter === 'size' ? $size : null
);
$deficit = array_values(array_filter($rows, static fn(array $r): bool => (int) $r['on_hand'] < US_DEFICIT_THRESHOLD));

us_header('Deficit', $user, $company);
?>
<p class="lead">
  Company: <strong><?= us_h($company['name']) ?></strong>.
  SKUs with on-hand quantity &lt; <?= (int) US_DEFICIT_THRESHOLD ?>. Filter by all, item, or size.
</p>

<form method="get" class="grid grid-2" style="margin-bottom:1rem">
  <div>
    <label for="filter">Filter</label>
    <select id="filter" name="filter" onchange="this.form.submit()">
      <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All deficit</option>
      <option value="item" <?= $filter === 'item' ? 'selected' : '' ?>>By item</option>
      <option value="size" <?= $filter === 'size' ? 'selected' : '' ?>>By size</option>
    </select>
  </div>
  <?php if ($filter === 'item'): ?>
    <div>
      <label for="item_no">Item</label>
      <select id="item_no" name="item_no" onchange="this.form.submit()">
        <option value="">Select item…</option>
        <?php foreach ($items as $it): ?>
          <option value="<?= us_h($it['item_no']) ?>" <?= $itemNo === $it['item_no'] ? 'selected' : '' ?>>
            <?= us_h($it['item_name'] . ' (' . $it['item_no'] . ')') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  <?php elseif ($filter === 'size'): ?>
    <div>
      <label for="size">Size</label>
      <select id="size" name="size" onchange="this.form.submit()">
        <option value="">Select size…</option>
        <?php foreach ($sizes as $sz): ?>
          <option value="<?= us_h($sz['size']) ?>" <?= $size === $sz['size'] ? 'selected' : '' ?>>
            <?= us_h($sz['size']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  <?php endif; ?>
</form>

<?php if ($filter === 'item' && $itemNo === ''): ?>
  <p class="muted">Choose an item to list its deficit sizes.</p>
<?php elseif ($filter === 'size' && $size === ''): ?>
  <p class="muted">Choose a size to list deficit items.</p>
<?php elseif (!$deficit): ?>
  <p class="muted">No deficit SKUs for this filter.</p>
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
      <?php foreach ($deficit as $row): ?>
        <tr>
          <td><?= us_h($row['item_name']) ?></td>
          <td><?= us_h($row['item_no']) ?></td>
          <td><?= us_h($row['size']) ?></td>
          <td class="num low"><?= (int) $row['on_hand'] ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
<?php us_footer(); ?>
