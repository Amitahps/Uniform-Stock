<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$user = us_require_login();
$pdo = us_db();

$lookupSkuId = isset($_GET['sku_id']) ? (int) $_GET['sku_id'] : 0;
$mode = (string) ($_GET['mode'] ?? 'lookup');
if (!in_array($mode, ['lookup', 'statement'], true)) {
    $mode = 'lookup';
}

$all = us_stock_rows($pdo);
$lookup = null;
if ($lookupSkuId > 0) {
    foreach ($all as $row) {
        if ((int) $row['id'] === $lookupSkuId) {
            $lookup = $row;
            break;
        }
    }
}

us_header('Reports', $user);
?>
<p class="lead">Look up quantity for one SKU, or view the full item-wise stock statement.</p>

<div class="actions" style="margin-bottom:1rem">
  <a class="btn <?= $mode === 'lookup' ? '' : 'secondary' ?>" href="?mode=lookup">Quantity lookup</a>
  <a class="btn <?= $mode === 'statement' ? '' : 'secondary' ?>" href="?mode=statement">Item-wise statement</a>
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
          <th>Item no</th>
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
    <p class="muted">SKU not found.</p>
  <?php endif; ?>

<?php else: ?>
  <?php if (!$all): ?>
    <p class="muted">No stock recorded yet.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Item name</th>
          <th>Item no</th>
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
<?php endif; ?>
<?php us_footer(); ?>
