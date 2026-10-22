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
$canPower = us_has_power($user);

$lookupSkuId = isset($_GET['sku_id']) ? (int) $_GET['sku_id'] : 0;
$mode = (string) ($_GET['mode'] ?? 'lookup');
$allowedModes = ['lookup', 'statement', 'movements', 'company_issues'];
if (!in_array($mode, $allowedModes, true)) {
    $mode = 'lookup';
}
if ($mode === 'company_issues' && !$canPower) {
    us_flash_set('error', 'Company-issue report needs admin or assigned power.');
    header('Location: ' . us_url('reports.php'));
    exit;
}

$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$itemPairFilter = (string) ($_GET['item'] ?? '');
$itemPair = us_parse_item_pair_key($itemPairFilter);
$itemNameFilter = $itemPair[0] ?? '';
$itemNoFilter = $itemPair[1] ?? '';
if ($dateFrom === '') {
    $dateFrom = date('Y-m-01');
}
if ($dateTo === '') {
    $dateTo = date('Y-m-d');
}

$filterFromCo = isset($_GET['from_company_id']) ? (int) $_GET['from_company_id'] : 0;
$filterToCo = isset($_GET['to_company_id']) ? (int) $_GET['to_company_id'] : 0;
$filterSchool = trim((string) ($_GET['xfer_school'] ?? ''));
if ($filterSchool !== '' && !us_is_valid_school($filterSchool)) {
    $filterSchool = '';
}

$all = us_stock_rows($pdo, $companyId, $school);
$itemOptions = $pdo->prepare(
    'SELECT DISTINCT item_name, item_no FROM skus WHERE company_id = ? AND school = ?
     ORDER BY item_name COLLATE NOCASE, item_no COLLATE NOCASE'
);
$itemOptions->execute([$companyId, $school]);
$itemOptions = $itemOptions->fetchAll();
$allCompanies = us_list_companies($pdo);

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
            $school,
            $dateFrom,
            $dateTo,
            $itemNoFilter !== '' ? $itemNoFilter : null,
            $itemNameFilter !== '' ? $itemNameFilter : null
        );
    }
}

$xferError = '';
$xferRows = [];
$xferLines = [];
if ($mode === 'company_issues') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $xferError = 'Choose valid From and To dates.';
    } elseif ($dateFrom > $dateTo) {
        $xferError = 'From date must be on or before To date.';
    } else {
        $xferRows = us_company_transfer_report(
            $pdo,
            $dateFrom,
            $dateTo,
            $filterFromCo > 0 ? $filterFromCo : null,
            $filterToCo > 0 ? $filterToCo : null,
            $filterSchool !== '' ? $filterSchool : null,
            $itemNameFilter !== '' ? $itemNameFilter : null,
            $itemNoFilter !== '' ? $itemNoFilter : null
        );
        $xferLines = us_company_transfer_lines(
            $pdo,
            $dateFrom,
            $dateTo,
            $filterFromCo > 0 ? $filterFromCo : null,
            $filterToCo > 0 ? $filterToCo : null,
            $filterSchool !== '' ? $filterSchool : null
        );
    }
}

us_header('Reports', $user, $company, $school);
?>
<p class="lead">
  Company <strong><?= us_h($company['name']) ?></strong> · School <strong><?= us_h($school) ?></strong> only — Bachpan and AHP.S never mix
  (except the company-issue report, which can show transfers across companies for a date range).
  Each stock row is one SKU: <strong>item name + item code + size</strong>.
</p>

<div class="actions" style="margin-bottom:1rem">
  <a class="btn <?= $mode === 'lookup' ? '' : 'secondary' ?>" href="?mode=lookup">Quantity lookup</a>
  <a class="btn <?= $mode === 'statement' ? '' : 'secondary' ?>" href="?mode=statement">Item-wise statement</a>
  <a class="btn <?= $mode === 'movements' ? '' : 'secondary' ?>" href="?mode=movements">Date-range movements</a>
  <?php if ($canPower): ?>
    <a class="btn <?= $mode === 'company_issues' ? '' : 'secondary' ?>" href="?mode=company_issues">Issued to companies</a>
  <?php endif; ?>
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
    <p class="muted">SKU not found for this company and school.</p>
  <?php endif; ?>

<?php elseif ($mode === 'statement'): ?>
  <p class="muted" style="margin-top:0">
    One row per item code + size. Example: name A / size 3 and name A / size 4 are two lines, each with its own on-hand.
  </p>
  <?php if (!$all): ?>
    <p class="muted">No stock recorded yet for this company and school.</p>
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

<?php elseif ($mode === 'company_issues'): ?>
  <p class="muted" style="margin-top:0">
    <strong>Issued to companies</strong> — how much was issued company → company, item-wise
    (name, code, size), filterable by date from → to, from/to company, and school.
  </p>
  <form method="get" class="grid grid-2" style="max-width:900px;margin-bottom:1rem">
    <input type="hidden" name="mode" value="company_issues">
    <div>
      <label for="date_from">From date</label>
      <input id="date_from" name="date_from" type="date" required value="<?= us_h($dateFrom) ?>">
    </div>
    <div>
      <label for="date_to">To date</label>
      <input id="date_to" name="date_to" type="date" required value="<?= us_h($dateTo) ?>">
    </div>
    <div>
      <label for="from_company_id">From company</label>
      <select id="from_company_id" name="from_company_id">
        <option value="0">All</option>
        <?php foreach ($allCompanies as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= $filterFromCo === (int) $c['id'] ? 'selected' : '' ?>>
            <?= us_h($c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="to_company_id">To company</label>
      <select id="to_company_id" name="to_company_id">
        <option value="0">All</option>
        <?php foreach ($allCompanies as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= $filterToCo === (int) $c['id'] ? 'selected' : '' ?>>
            <?= us_h($c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="xfer_school">School</label>
      <select id="xfer_school" name="xfer_school">
        <option value="">All schools</option>
        <?php foreach (us_schools() as $s): ?>
          <option value="<?= us_h($s) ?>" <?= $filterSchool === $s ? 'selected' : '' ?>><?= us_h($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="item">Item name · code (optional)</label>
      <select id="item" name="item">
        <option value="">All items</option>
        <?php
        $xferItemOpts = $pdo->query(
            "SELECT DISTINCT item_name, item_no FROM company_transfers
             ORDER BY item_name COLLATE NOCASE, item_no COLLATE NOCASE"
        )->fetchAll();
        foreach ($xferItemOpts as $it):
            $pair = us_item_pair_key((string) $it['item_name'], (string) $it['item_no']);
        ?>
          <option value="<?= us_h($pair) ?>" <?= $itemPairFilter === $pair ? 'selected' : '' ?>>
            <?= us_h($it['item_name'] . ' · ' . $it['item_no']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="actions" style="align-self:end">
      <button type="submit">Show issue report</button>
      <a class="btn secondary" href="<?= us_h(us_url('issue_company.php')) ?>">Issue to company</a>
    </div>
  </form>

  <?php if ($xferError !== ''): ?>
    <div class="flash flash-error"><?= us_h($xferError) ?></div>
  <?php elseif (!$xferRows): ?>
    <p class="muted">No company issues in this date range<?= $itemPair !== null ? ' / filters' : '' ?>.</p>
  <?php else: ?>
    <h2 style="font-size:1.1rem;margin:0 0 0.5rem">Item-wise totals</h2>
    <table>
      <thead>
        <tr>
          <th>Item name</th>
          <th>Item code</th>
          <th>Size</th>
          <th>School</th>
          <th>From company</th>
          <th>To company</th>
          <th class="num">Qty issued</th>
          <th class="num"># transfers</th>
          <th>Issued by</th>
          <th>Last date</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $sumQty = 0;
        foreach ($xferRows as $row):
            $sumQty += (int) $row['qty_issued'];
        ?>
          <tr>
            <td><?= us_h($row['item_name']) ?></td>
            <td><?= us_h($row['item_no']) ?></td>
            <td><?= us_h($row['size']) ?></td>
            <td><?= us_h($row['school']) ?></td>
            <td><?= us_h($row['from_company']) ?></td>
            <td><?= us_h($row['to_company']) ?></td>
            <td class="num"><?= (int) $row['qty_issued'] ?></td>
            <td class="num"><?= (int) $row['transfer_count'] ?></td>
            <td class="muted"><?= us_h($row['issued_by']) ?></td>
            <td class="muted"><?= us_h($row['last_date']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <th colspan="6">Total qty issued</th>
          <th class="num"><?= (int) $sumQty ?></th>
          <th colspan="3"></th>
        </tr>
      </tfoot>
    </table>

    <?php if ($xferLines): ?>
      <h2 style="font-size:1.1rem;margin:1.5rem 0 0.5rem">Transfer lines</h2>
      <table>
        <thead>
          <tr>
            <th>Date</th>
            <th>School</th>
            <th>Item name</th>
            <th>Item code</th>
            <th>Size</th>
            <th class="num">Qty</th>
            <th>From</th>
            <th>To</th>
            <th>By</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($xferLines as $line): ?>
            <tr>
              <td><?= us_h((string) $line['transfer_date']) ?></td>
              <td><?= us_h((string) $line['school']) ?></td>
              <td><?= us_h((string) $line['item_name']) ?></td>
              <td><?= us_h((string) $line['item_no']) ?></td>
              <td><?= us_h((string) $line['size']) ?></td>
              <td class="num"><?= (int) $line['quantity'] ?></td>
              <td><?= us_h((string) $line['from_company']) ?></td>
              <td><?= us_h((string) $line['to_company']) ?></td>
              <td class="muted"><?= us_h((string) $line['issued_by_username']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php endif; ?>

<?php else: ?>
  <p class="muted" style="margin-top:0">
    <strong>Qty In</strong> / <strong>Qty Out</strong> = movements with dates in the range (inclusive).
    <strong>Balance</strong> = closing on-hand as of the <em>To</em> date (all stock in/out on or before that date).
    Totals are per SKU (name + item code + size), not collapsed by name.
  </p>
  <form method="get" class="grid grid-2" style="max-width:820px;margin-bottom:1rem">
    <input type="hidden" name="mode" value="movements">
    <div>
      <label for="date_from_m">From date</label>
      <input id="date_from_m" name="date_from" type="date" required value="<?= us_h($dateFrom) ?>">
    </div>
    <div>
      <label for="date_to_m">To date</label>
      <input id="date_to_m" name="date_to" type="date" required value="<?= us_h($dateTo) ?>">
    </div>
    <div>
      <label for="item_m">Item name · code (optional)</label>
      <select id="item_m" name="item">
        <option value="">All items</option>
        <?php foreach ($itemOptions as $it):
            $pair = us_item_pair_key((string) $it['item_name'], (string) $it['item_no']);
        ?>
          <option value="<?= us_h($pair) ?>" <?= $itemPairFilter === $pair ? 'selected' : '' ?>>
            <?= us_h($it['item_name'] . ' · ' . $it['item_no']) ?>
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
    <p class="muted">No SKUs for this company and school<?= $itemPair !== null ? ' / item filter' : '' ?>.</p>
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
