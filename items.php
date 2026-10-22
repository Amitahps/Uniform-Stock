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
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editSku = $editId > 0 ? us_get_sku($pdo, $editId, $companyId) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'create');
    try {
        if ($action === 'create') {
            $itemNo = trim((string) ($_POST['item_no'] ?? ''));
            $itemName = trim((string) ($_POST['item_name'] ?? ''));
            $size = trim((string) ($_POST['size'] ?? ''));
            if ($itemNo === '' || $itemName === '' || $size === '') {
                throw new RuntimeException('Item name, item code, and size are all required.');
            }
            us_create_sku($pdo, $companyId, $itemNo, $itemName, $size);
            us_flash_set('ok', 'Registered ' . $itemName . ' · ' . $itemNo . ' · ' . $size . '.');
            header('Location: ' . us_url('items.php'));
            exit;
        }
        if ($action === 'update') {
            $skuId = (int) ($_POST['sku_id'] ?? 0);
            us_update_sku(
                $pdo,
                $companyId,
                $skuId,
                (string) ($_POST['item_no'] ?? ''),
                (string) ($_POST['item_name'] ?? ''),
                (string) ($_POST['size'] ?? '')
            );
            us_flash_set('ok', 'Item updated.');
            header('Location: ' . us_url('items.php'));
            exit;
        }
        if ($action === 'delete') {
            $skuId = (int) ($_POST['sku_id'] ?? 0);
            us_delete_sku($pdo, $companyId, $skuId);
            us_flash_set('ok', 'Item deleted.');
            header('Location: ' . us_url('items.php'));
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($action === 'update') {
            $editId = (int) ($_POST['sku_id'] ?? 0);
            $editSku = $editId > 0 ? us_get_sku($pdo, $editId, $companyId) : null;
            if ($editSku) {
                $editSku['item_no'] = (string) ($_POST['item_no'] ?? $editSku['item_no']);
                $editSku['item_name'] = (string) ($_POST['item_name'] ?? $editSku['item_name']);
                $editSku['size'] = (string) ($_POST['size'] ?? $editSku['size']);
            }
        }
    }
}

$catalog = us_item_catalog($pdo, $companyId);
$skus = us_stock_rows($pdo, $companyId);

us_header('Items', $user, $company);
?>
<p class="lead">
  Register item name, item code, and size once for <strong><?= us_h($company['name']) ?></strong>.
  Stock-in bills only select these and enter quantity. Edit anytime; delete only if the SKU has no stock history.
</p>
<?php if ($error !== ''): ?>
  <div class="flash flash-error"><?= us_h($error) ?></div>
<?php endif; ?>

<?php if ($editSku): ?>
<section style="margin-bottom:1.5rem;padding:1rem;border:1px solid var(--line);border-radius:10px;background:#fff">
  <h2 style="font-size:1.1rem;margin:0 0 0.75rem">Edit item</h2>
  <form method="post" class="grid" style="max-width:640px">
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="sku_id" value="<?= (int) $editSku['id'] ?>">
    <div class="grid grid-2">
      <div>
        <label for="edit_item_name">Item name</label>
        <input id="edit_item_name" name="item_name" required value="<?= us_h($editSku['item_name']) ?>">
      </div>
      <div>
        <label for="edit_item_no">Item code</label>
        <input id="edit_item_no" name="item_no" required value="<?= us_h($editSku['item_no']) ?>">
      </div>
    </div>
    <div style="max-width:240px">
      <label for="edit_size">Size</label>
      <input id="edit_size" name="size" required value="<?= us_h($editSku['size']) ?>">
    </div>
    <div class="actions">
      <button type="submit">Save changes</button>
      <a class="btn secondary" href="<?= us_h(us_url('items.php')) ?>">Cancel</a>
    </div>
  </form>
</section>
<?php else: ?>
<form method="post" class="grid" style="max-width:640px" id="item_form">
  <input type="hidden" name="action" value="create">
  <div class="grid grid-2">
    <div>
      <label for="item_name">Item name</label>
      <input id="item_name" name="item_name" required
             value="<?= us_h($_POST['item_name'] ?? '') ?>"
             placeholder="e.g. Shirt">
    </div>
    <div>
      <label for="item_no">Item code</label>
      <input id="item_no" name="item_no" list="item_no_list" required
             value="<?= us_h($_POST['item_no'] ?? '') ?>"
             placeholder="e.g. U001">
      <datalist id="item_no_list">
        <?php foreach ($catalog as $no => $meta): ?>
          <option value="<?= us_h($no) ?>"><?= us_h($meta['item_name']) ?></option>
        <?php endforeach; ?>
      </datalist>
      <p class="muted" style="margin:0.35rem 0 0;font-size:0.9rem">
        If the item code already exists, its name is kept; you are adding another size.
      </p>
    </div>
  </div>
  <div style="max-width:240px">
    <label for="size">Size</label>
    <input id="size" name="size" required value="<?= us_h($_POST['size'] ?? '') ?>" placeholder="e.g. M">
  </div>
  <div class="actions">
    <button type="submit">Add item / size</button>
    <a class="btn secondary" href="<?= us_h(us_url('stock_in.php')) ?>">Go to stock in</a>
  </div>
</form>
<script>
(function () {
  const catalog = <?= json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
  const itemNo = document.getElementById('item_no');
  const itemName = document.getElementById('item_name');
  itemNo.addEventListener('change', syncName);
  itemNo.addEventListener('blur', syncName);
  function syncName() {
    const key = itemNo.value.trim();
    if (catalog[key] && catalog[key].item_name) {
      itemName.value = catalog[key].item_name;
    }
  }
})();
</script>
<?php endif; ?>

<h2 style="margin-top:1.75rem;font-size:1.15rem">Registered SKUs</h2>
<p class="muted" style="margin-top:0">Delete is blocked when the SKU has any stock-in or stock-out lines.</p>
<?php if (!$skus): ?>
  <p class="muted">No items yet for this company.</p>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th>Item name</th>
        <th>Item code</th>
        <th>Size</th>
        <th class="num">On hand</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($skus as $row): ?>
        <?php $hasMoves = us_sku_has_movements($pdo, (int) $row['id']); ?>
        <tr>
          <td><?= us_h($row['item_name']) ?></td>
          <td><?= us_h($row['item_no']) ?></td>
          <td><?= us_h($row['size']) ?></td>
          <td class="num"><?= (int) $row['on_hand'] ?></td>
          <td class="row-actions">
            <a class="btn secondary" href="<?= us_h(us_url('items.php?edit=' . (int) $row['id'])) ?>">Edit</a>
            <?php if ($hasMoves): ?>
              <span class="muted" title="Has stock history">Locked</span>
            <?php else: ?>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete this unused item/size?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="sku_id" value="<?= (int) $row['id'] ?>">
                <button type="submit" class="secondary danger-btn">Delete</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
<?php us_footer(); ?>
