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
                $codeChoice = trim((string) ($_POST['code_choice'] ?? ''));
                if ($codeChoice === '__new__') {
                    $itemNo = trim((string) ($_POST['new_item_no'] ?? ''));
                } else {
                    $itemNo = $codeChoice;
                }
            } else {
                $itemName = trim((string) ($_POST['item_name'] ?? ''));
            }

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

$skus = us_stock_rows($pdo, $companyId);

/** @var array<string, array{codes: array<string, list<string>>}> $byName */
$byName = [];
foreach ($skus as $row) {
    $name = (string) $row['item_name'];
    $code = (string) $row['item_no'];
    $size = (string) $row['size'];
    if (!isset($byName[$name])) {
        $byName[$name] = ['codes' => []];
    }
    if (!isset($byName[$name]['codes'][$code])) {
        $byName[$name]['codes'][$code] = [];
    }
    $byName[$name]['codes'][$code][] = $size;
}
ksort($byName, SORT_NATURAL | SORT_FLAG_CASE);
$existingNames = array_keys($byName);
$defaultMode = $existingNames === [] ? 'new' : (($postedMode === 'new') ? 'new' : 'existing');

us_header('Items', $user, $company);
?>
<p class="lead">
  For <strong><?= us_h($company['name']) ?></strong>: create a new item name, or <strong>add a size</strong> by selecting a name already created
  (no need to type the name again). Uniqueness is <strong>item code + size</strong>; the same name may use different codes.
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
        Add size to existing item name
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

  <div id="panel_existing" class="grid" style="<?= $defaultMode === 'existing' ? '' : 'display:none' ?>">
    <div>
      <label for="existing_item_name">Item name (already created)</label>
      <select id="existing_item_name" name="existing_item_name" <?= $defaultMode === 'existing' ? 'required' : '' ?>>
        <option value="">Select name…</option>
        <?php foreach ($existingNames as $name): ?>
          <option value="<?= us_h($name) ?>" <?= (string) ($_POST['existing_item_name'] ?? '') === $name ? 'selected' : '' ?>>
            <?= us_h($name) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="grid grid-2">
      <div>
        <label for="code_choice">Item code</label>
        <select id="code_choice" name="code_choice" <?= $defaultMode === 'existing' ? 'required' : '' ?>>
          <option value="">Select name first…</option>
        </select>
        <p class="muted" style="margin:0.35rem 0 0;font-size:0.9rem">
          Pick an existing code to add another size, or choose “New item code…”.
        </p>
      </div>
      <div id="new_code_wrap" style="display:none">
        <label for="new_item_no">New item code</label>
        <input id="new_item_no" name="new_item_no" value="<?= us_h($_POST['new_item_no'] ?? '') ?>" placeholder="e.g. U-02">
      </div>
    </div>
  </div>

  <div id="panel_new" class="grid" style="<?= $defaultMode === 'new' ? '' : 'display:none' ?>">
    <div class="grid grid-2">
      <div>
        <label for="item_name">New item name</label>
        <input id="item_name" name="item_name"
               value="<?= us_h($_POST['item_name'] ?? '') ?>"
               placeholder="e.g. Shirt"
               <?= $defaultMode === 'new' ? 'required' : '' ?>>
      </div>
      <div>
        <label for="item_no">Item code</label>
        <input id="item_no" name="item_no"
               value="<?= us_h($_POST['item_no'] ?? '') ?>"
               placeholder="e.g. U-01"
               <?= $defaultMode === 'new' ? 'required' : '' ?>>
      </div>
    </div>
  </div>

  <div style="max-width:240px">
    <label for="size">Size</label>
    <input id="size" name="size" required value="<?= us_h($_POST['size'] ?? '') ?>" placeholder="e.g. 32">
  </div>
  <div class="actions">
    <button type="submit">Save item / size</button>
    <a class="btn secondary" href="<?= us_h(us_url('stock_in.php')) ?>">Go to stock in</a>
  </div>
</form>

<script>
(function () {
  const byName = <?= json_encode($byName, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
  const modeExisting = document.getElementById('mode_existing');
  const modeNew = document.getElementById('mode_new');
  const panelExisting = document.getElementById('panel_existing');
  const panelNew = document.getElementById('panel_new');
  const nameSel = document.getElementById('existing_item_name');
  const codeSel = document.getElementById('code_choice');
  const newCodeWrap = document.getElementById('new_code_wrap');
  const newCodeInput = document.getElementById('new_item_no');
  const newNameInput = document.getElementById('item_name');
  const newNoInput = document.getElementById('item_no');
  const postedCode = <?= json_encode((string) ($_POST['code_choice'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;

  function setMode(mode) {
    const existing = mode === 'existing';
    panelExisting.style.display = existing ? '' : 'none';
    panelNew.style.display = existing ? 'none' : '';
    nameSel.required = existing;
    codeSel.required = existing;
    newNameInput.required = !existing;
    newNoInput.required = !existing;
    if (!existing) {
      newCodeInput.required = false;
      newCodeWrap.style.display = 'none';
    } else {
      fillCodes();
    }
  }

  function fillCodes() {
    const name = nameSel.value;
    const prev = codeSel.value || postedCode;
    codeSel.innerHTML = '';
    const ph = document.createElement('option');
    ph.value = '';
    ph.textContent = name ? 'Select item code…' : 'Select name first…';
    codeSel.appendChild(ph);
    if (!name || !byName[name]) {
      newCodeWrap.style.display = 'none';
      newCodeInput.required = false;
      return;
    }
    const codes = Object.keys(byName[name].codes || {}).sort();
    codes.forEach(function (code) {
      const sizes = (byName[name].codes[code] || []).join(', ');
      const opt = document.createElement('option');
      opt.value = code;
      opt.textContent = code + (sizes ? ' (sizes: ' + sizes + ')' : '');
      if (code === prev) opt.selected = true;
      codeSel.appendChild(opt);
    });
    const neu = document.createElement('option');
    neu.value = '__new__';
    neu.textContent = 'New item code…';
    if (prev === '__new__') neu.selected = true;
    codeSel.appendChild(neu);
    toggleNewCode();
  }

  function toggleNewCode() {
    const isNew = codeSel.value === '__new__';
    newCodeWrap.style.display = isNew ? '' : 'none';
    newCodeInput.required = isNew && modeExisting.checked;
  }

  modeExisting.addEventListener('change', function () { if (modeExisting.checked) setMode('existing'); });
  modeNew.addEventListener('change', function () { if (modeNew.checked) setMode('new'); });
  nameSel.addEventListener('change', fillCodes);
  codeSel.addEventListener('change', toggleNewCode);

  setMode(modeExisting.checked ? 'existing' : 'new');
})();
</script>
<?php endif; ?>

<h2 style="margin-top:1.75rem;font-size:1.15rem">Registered SKUs</h2>
<p class="muted" style="margin-top:0">Each row is name + item code + size. Delete is blocked when the SKU has stock history.</p>
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
