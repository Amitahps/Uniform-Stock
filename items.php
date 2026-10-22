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
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editSku = $editId > 0 ? us_get_sku($pdo, $editId, $companyId, $school) : null;
$postedMode = (string) ($_POST['name_mode'] ?? 'existing');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'create');
    try {
        if ($action === 'create') {
            $nameMode = (string) ($_POST['name_mode'] ?? 'existing');
            $itemNo = trim((string) ($_POST['item_no'] ?? ''));
            $size = trim((string) ($_POST['size'] ?? ''));

            if ($nameMode === 'existing') {
                $itemName = trim((string) ($_POST['existing_item_name'] ?? ''));
            } else {
                $itemName = trim((string) ($_POST['item_name'] ?? ''));
            }

            if ($itemNo === '' || $itemName === '' || $size === '') {
                throw new RuntimeException('Item name, item code, and size are all required.');
            }
            us_create_sku($pdo, $companyId, $school, $itemNo, $itemName, $size);
            us_flash_set('ok', 'Registered ' . $itemName . ' · ' . $itemNo . ' · ' . $size . '.');
            header('Location: ' . us_url('items.php'));
            exit;
        }
        if ($action === 'update') {
            $skuId = (int) ($_POST['sku_id'] ?? 0);
            us_update_sku(
                $pdo,
                $companyId,
                $school,
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
            us_delete_sku($pdo, $companyId, $school, $skuId);
            us_flash_set('ok', 'Item deleted.');
            header('Location: ' . us_url('items.php'));
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($action === 'update') {
            $editId = (int) ($_POST['sku_id'] ?? 0);
            $editSku = $editId > 0 ? us_get_sku($pdo, $editId, $companyId, $school) : null;
            if ($editSku) {
                $editSku['item_no'] = (string) ($_POST['item_no'] ?? $editSku['item_no']);
                $editSku['item_name'] = (string) ($_POST['item_name'] ?? $editSku['item_name']);
                $editSku['size'] = (string) ($_POST['size'] ?? $editSku['size']);
            }
        }
    }
}

$skus = us_stock_rows($pdo, $companyId, $school);
$existingNames = [];
foreach ($skus as $row) {
    $name = (string) $row['item_name'];
    if ($name !== '' && !in_array($name, $existingNames, true)) {
        $existingNames[] = $name;
    }
}
natcasesort($existingNames);
$existingNames = array_values($existingNames);
$defaultMode = $existingNames === [] ? 'new' : (($postedMode === 'new') ? 'new' : 'existing');

us_header('Items', $user, $company, $school);
?>
<p class="lead">
  Company <strong><?= us_h($company['name']) ?></strong> · School <strong><?= us_h($school) ?></strong>.
  Select an existing item name and enter code + size, or create a brand-new name.
  Uniqueness is <strong>item name + item code + size</strong> within this company + school
  (different names may reuse the same code and size).
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
<form method="post" class="grid" style="max-width:720px" id="item_form">
  <input type="hidden" name="action" value="create">

  <fieldset style="border:1px solid var(--line);border-radius:10px;padding:0.85rem 1rem;margin:0">
    <legend style="font-weight:700;padding:0 0.35rem">How to add</legend>
    <div class="actions" style="margin:0;gap:1rem">
      <label style="font-weight:600;display:flex;align-items:center;gap:0.4rem">
        <input type="radio" name="name_mode" value="existing" id="mode_existing"
               <?= $defaultMode === 'existing' ? 'checked' : '' ?>
               <?= $existingNames === [] ? 'disabled' : '' ?>>
        Use existing item name
      </label>
      <label style="font-weight:600;display:flex;align-items:center;gap:0.4rem">
        <input type="radio" name="name_mode" value="new" id="mode_new"
               <?= $defaultMode === 'new' ? 'checked' : '' ?>>
        Create new item name
      </label>
    </div>
    <?php if ($existingNames === []): ?>
      <p class="muted" style="margin:0.6rem 0 0;font-size:0.9rem">No names yet — create the first item name below.</p>
    <?php endif; ?>
  </fieldset>

  <div id="panel_existing" style="<?= $defaultMode === 'existing' ? '' : 'display:none' ?>">
    <label for="existing_item_name">Item name</label>
    <select id="existing_item_name" name="existing_item_name" <?= $defaultMode === 'existing' ? 'required' : '' ?>>
      <option value="">Select existing name…</option>
      <?php foreach ($existingNames as $name): ?>
        <option value="<?= us_h($name) ?>" <?= (string) ($_POST['existing_item_name'] ?? '') === $name ? 'selected' : '' ?>>
          <?= us_h($name) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div id="panel_new" style="<?= $defaultMode === 'new' ? '' : 'display:none' ?>">
    <label for="item_name">Item name</label>
    <input id="item_name" name="item_name"
           value="<?= us_h($_POST['item_name'] ?? '') ?>"
           placeholder="e.g. Shirt"
           <?= $defaultMode === 'new' ? 'required' : '' ?>>
  </div>

  <div class="grid grid-2">
    <div>
      <label for="item_no">Item code</label>
      <input id="item_no" name="item_no" required
             value="<?= us_h($_POST['item_no'] ?? '') ?>"
             placeholder="e.g. U-01">
      <p class="muted" style="margin:0.35rem 0 0;font-size:0.9rem">
        Type the code. Same code + new size adds another size; same code + same size is rejected.
      </p>
    </div>
    <div>
      <label for="size">Size</label>
      <input id="size" name="size" required value="<?= us_h($_POST['size'] ?? '') ?>" placeholder="e.g. 32">
    </div>
  </div>

  <div class="actions">
    <button type="submit">Save item / size</button>
    <a class="btn secondary" href="<?= us_h(us_url('stock_in.php')) ?>">Go to stock in</a>
  </div>
</form>

<script>
(function () {
  const modeExisting = document.getElementById('mode_existing');
  const modeNew = document.getElementById('mode_new');
  const panelExisting = document.getElementById('panel_existing');
  const panelNew = document.getElementById('panel_new');
  const nameSel = document.getElementById('existing_item_name');
  const newNameInput = document.getElementById('item_name');

  function setMode(mode) {
    const existing = mode === 'existing';
    panelExisting.style.display = existing ? '' : 'none';
    panelNew.style.display = existing ? 'none' : '';
    nameSel.required = existing;
    newNameInput.required = !existing;
  }

  modeExisting.addEventListener('change', function () { if (modeExisting.checked) setMode('existing'); });
  modeNew.addEventListener('change', function () { if (modeNew.checked) setMode('new'); });
  setMode(modeExisting.checked ? 'existing' : 'new');
})();
</script>
<?php endif; ?>

<h2 style="margin-top:1.75rem;font-size:1.15rem">Registered SKUs</h2>
<p class="muted" style="margin-top:0">Each row is name + item code + size. Delete is blocked when the SKU has stock history.</p>
<?php if (!$skus): ?>
  <p class="muted">No items yet for this company and school.</p>
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
