<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function us_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!is_dir(US_DATA_DIR)) {
        mkdir(US_DATA_DIR, 0755, true);
    }

    $pdo = new PDO('sqlite:' . US_DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Keep FKs off while migrating — SQLite refuses DROP/ALTER of parents when FKs are on.
    $pdo->exec('PRAGMA foreign_keys = OFF');
    us_migrate($pdo);
    $pdo->exec('PRAGMA foreign_keys = ON');

    return $pdo;
}

function us_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function us_table_columns(PDO $pdo, string $table): array
{
    if (!us_table_exists($pdo, $table)) {
        return [];
    }
    $cols = [];
    foreach ($pdo->query('PRAGMA table_info(' . $table . ')') as $row) {
        $cols[] = $row['name'];
    }
    return $cols;
}

function us_ensure_default_company(PDO $pdo): int
{
    $pdo->exec("INSERT INTO companies (name) SELECT 'Default' WHERE NOT EXISTS (SELECT 1 FROM companies LIMIT 1)");
    $id = (int) $pdo->query('SELECT id FROM companies ORDER BY id LIMIT 1')->fetchColumn();
    if ($id <= 0) {
        $pdo->exec("INSERT INTO companies (name) VALUES ('Default')");
        $id = (int) $pdo->lastInsertId();
    }
    return $id;
}

function us_migrate(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS companies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
SQL);

    $companyId = us_ensure_default_company($pdo);

    // SKUs
    $skuCols = us_table_columns($pdo, 'skus');
    if ($skuCols === []) {
        $pdo->exec(<<<'SQL'
CREATE TABLE skus (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL,
    item_no TEXT NOT NULL,
    item_name TEXT NOT NULL,
    size TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(company_id, item_no, size),
    FOREIGN KEY (company_id) REFERENCES companies(id)
);
SQL);
    } elseif (!in_array('company_id', $skuCols, true)) {
        $created = in_array('created_at', $skuCols, true) ? 'created_at' : "datetime('now')";
        us_rebuild_table_with_company($pdo, 'skus', <<<'SQL'
CREATE TABLE skus_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL,
    item_no TEXT NOT NULL,
    item_name TEXT NOT NULL,
    size TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(company_id, item_no, size),
    FOREIGN KEY (company_id) REFERENCES companies(id)
);
SQL, 'id, company_id, item_no, item_name, size, created_at', "id, {$companyId}, item_no, item_name, size, {$created}");
    }

    // Receipts
    $receiptCols = us_table_columns($pdo, 'receipts');
    if ($receiptCols === []) {
        $pdo->exec(<<<'SQL'
CREATE TABLE receipts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL,
    bill_no TEXT NOT NULL,
    receipt_date TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(company_id, bill_no),
    FOREIGN KEY (company_id) REFERENCES companies(id)
);
SQL);
    } elseif (!in_array('company_id', $receiptCols, true)) {
        $created = in_array('created_at', $receiptCols, true) ? 'created_at' : "datetime('now')";
        us_rebuild_table_with_company($pdo, 'receipts', <<<'SQL'
CREATE TABLE receipts_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL,
    bill_no TEXT NOT NULL,
    receipt_date TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(company_id, bill_no),
    FOREIGN KEY (company_id) REFERENCES companies(id)
);
SQL, 'id, company_id, bill_no, receipt_date, created_at', "id, {$companyId}, bill_no, receipt_date, {$created}");
    }

    // Issues
    $issueCols = us_table_columns($pdo, 'issues');
    if ($issueCols === []) {
        $pdo->exec(<<<'SQL'
CREATE TABLE issues (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL,
    issue_date TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (company_id) REFERENCES companies(id)
);
SQL);
    } elseif (!in_array('company_id', $issueCols, true)) {
        $created = in_array('created_at', $issueCols, true) ? 'created_at' : "datetime('now')";
        us_rebuild_table_with_company($pdo, 'issues', <<<'SQL'
CREATE TABLE issues_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL,
    issue_date TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (company_id) REFERENCES companies(id)
);
SQL, 'id, company_id, issue_date, created_at', "id, {$companyId}, issue_date, {$created}");
    }

    // Line tables (no company_id; create if missing)
    if (!us_table_exists($pdo, 'receipt_lines')) {
        $pdo->exec(<<<'SQL'
CREATE TABLE receipt_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    receipt_id INTEGER NOT NULL,
    sku_id INTEGER NOT NULL,
    quantity INTEGER NOT NULL CHECK (quantity > 0),
    FOREIGN KEY (receipt_id) REFERENCES receipts(id) ON DELETE CASCADE,
    FOREIGN KEY (sku_id) REFERENCES skus(id)
);
SQL);
    }
    if (!us_table_exists($pdo, 'issue_lines')) {
        $pdo->exec(<<<'SQL'
CREATE TABLE issue_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    issue_id INTEGER NOT NULL,
    sku_id INTEGER NOT NULL,
    quantity INTEGER NOT NULL CHECK (quantity > 0),
    FOREIGN KEY (issue_id) REFERENCES issues(id) ON DELETE CASCADE,
    FOREIGN KEY (sku_id) REFERENCES skus(id)
);
SQL);
    }

    // Repair orphan company_id values if any
    $pdo->exec('UPDATE skus SET company_id = ' . (int) $companyId . ' WHERE company_id IS NULL OR company_id = 0 OR company_id NOT IN (SELECT id FROM companies)');
    $pdo->exec('UPDATE receipts SET company_id = ' . (int) $companyId . ' WHERE company_id IS NULL OR company_id = 0 OR company_id NOT IN (SELECT id FROM companies)');
    $pdo->exec('UPDATE issues SET company_id = ' . (int) $companyId . ' WHERE company_id IS NULL OR company_id = 0 OR company_id NOT IN (SELECT id FROM companies)');

    $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)');
        $stmt->execute([US_DEFAULT_USER, password_hash(US_DEFAULT_PASS, PASSWORD_DEFAULT)]);
    }
}

/**
 * Rebuild a table adding company_id. Caller must have PRAGMA foreign_keys = OFF.
 */
function us_rebuild_table_with_company(
    PDO $pdo,
    string $table,
    string $createNewSql,
    string $insertCols,
    string $selectExpr
): void {
    $newName = $table . '_new';
    // Drop leftover from a previous failed migrate.
    $pdo->exec('DROP TABLE IF EXISTS ' . $newName);
    $pdo->exec($createNewSql);
    $pdo->exec("INSERT INTO {$newName} ({$insertCols}) SELECT {$selectExpr} FROM {$table}");
    $pdo->exec('DROP TABLE ' . $table);
    $pdo->exec("ALTER TABLE {$newName} RENAME TO {$table}");
}

function us_list_companies(PDO $pdo): array
{
    return $pdo->query('SELECT id, name, created_at FROM companies ORDER BY name COLLATE NOCASE')->fetchAll();
}

function us_get_company(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT id, name FROM companies WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function us_create_company(PDO $pdo, string $name): int
{
    $name = trim($name);
    if ($name === '') {
        throw new RuntimeException('Company name is required.');
    }
    try {
        $stmt = $pdo->prepare('INSERT INTO companies (name) VALUES (?)');
        $stmt->execute([$name]);
        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) {
            throw new RuntimeException('A company with that name already exists.');
        }
        throw $e;
    }
}

function us_on_hand(PDO $pdo, int $skuId): int
{
    $in = $pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM receipt_lines WHERE sku_id = ?');
    $in->execute([$skuId]);
    $out = $pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM issue_lines WHERE sku_id = ?');
    $out->execute([$skuId]);
    return (int) $in->fetchColumn() - (int) $out->fetchColumn();
}

function us_sku_has_movements(PDO $pdo, int $skuId): bool
{
    $r = $pdo->prepare('SELECT 1 FROM receipt_lines WHERE sku_id = ? LIMIT 1');
    $r->execute([$skuId]);
    if ($r->fetch()) {
        return true;
    }
    $i = $pdo->prepare('SELECT 1 FROM issue_lines WHERE sku_id = ? LIMIT 1');
    $i->execute([$skuId]);
    return (bool) $i->fetch();
}

function us_stock_rows(PDO $pdo, int $companyId, ?string $itemNo = null, ?string $size = null): array
{
    $sql = <<<'SQL'
SELECT
    s.id,
    s.company_id,
    s.item_no,
    s.item_name,
    s.size,
    COALESCE((SELECT SUM(rl.quantity) FROM receipt_lines rl WHERE rl.sku_id = s.id), 0)
      - COALESCE((SELECT SUM(il.quantity) FROM issue_lines il WHERE il.sku_id = s.id), 0) AS on_hand
FROM skus s
WHERE s.company_id = ?
SQL;
    $params = [$companyId];
    if ($itemNo !== null && $itemNo !== '') {
        $sql .= ' AND s.item_no = ?';
        $params[] = $itemNo;
    }
    if ($size !== null && $size !== '') {
        $sql .= ' AND s.size = ?';
        $params[] = $size;
    }
    $sql .= ' ORDER BY s.item_name COLLATE NOCASE, s.item_no COLLATE NOCASE, s.size COLLATE NOCASE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function us_create_sku(PDO $pdo, int $companyId, string $itemNo, string $itemName, string $size): int
{
    $itemNo = trim($itemNo);
    $itemName = trim($itemName);
    $size = trim($size);

    $find = $pdo->prepare('SELECT id FROM skus WHERE company_id = ? AND item_no = ? AND size = ?');
    $find->execute([$companyId, $itemNo, $size]);
    if ($find->fetch()) {
        throw new RuntimeException('Item no "' . $itemNo . '" with size "' . $size . '" is already registered for this company.');
    }

    $nameStmt = $pdo->prepare('SELECT item_name FROM skus WHERE company_id = ? AND item_no = ? ORDER BY id LIMIT 1');
    $nameStmt->execute([$companyId, $itemNo]);
    $existingName = $nameStmt->fetchColumn();
    if ($existingName !== false && $existingName !== '') {
        $itemName = (string) $existingName;
    }

    $ins = $pdo->prepare('INSERT INTO skus (company_id, item_no, item_name, size) VALUES (?, ?, ?, ?)');
    $ins->execute([$companyId, $itemNo, $itemName, $size]);
    return (int) $pdo->lastInsertId();
}

function us_update_sku(PDO $pdo, int $companyId, int $skuId, string $itemNo, string $itemName, string $size): void
{
    $itemNo = trim($itemNo);
    $itemName = trim($itemName);
    $size = trim($size);
    if ($itemNo === '' || $itemName === '' || $size === '') {
        throw new RuntimeException('Item name, item no, and size are all required.');
    }

    $sku = us_get_sku($pdo, $skuId, $companyId);
    if (!$sku) {
        throw new RuntimeException('Item not found in this company.');
    }

    $dup = $pdo->prepare('SELECT id FROM skus WHERE company_id = ? AND item_no = ? AND size = ? AND id != ?');
    $dup->execute([$companyId, $itemNo, $size, $skuId]);
    if ($dup->fetch()) {
        throw new RuntimeException('Another item already uses item no "' . $itemNo . '" with size "' . $size . '".');
    }

    $oldItemNo = (string) $sku['item_no'];
    $upd = $pdo->prepare('UPDATE skus SET item_no = ?, item_name = ?, size = ? WHERE id = ? AND company_id = ?');
    $upd->execute([$itemNo, $itemName, $size, $skuId, $companyId]);

    if ($itemNo === $oldItemNo) {
        $sync = $pdo->prepare('UPDATE skus SET item_name = ? WHERE company_id = ? AND item_no = ?');
        $sync->execute([$itemName, $companyId, $itemNo]);
    }
}

function us_delete_sku(PDO $pdo, int $companyId, int $skuId): void
{
    $sku = us_get_sku($pdo, $skuId, $companyId);
    if (!$sku) {
        throw new RuntimeException('Item not found in this company.');
    }
    if (us_sku_has_movements($pdo, $skuId)) {
        throw new RuntimeException(
            'Cannot delete this item — it has stock-in or stock-out history. Delete is only allowed for unused SKUs.'
        );
    }
    $del = $pdo->prepare('DELETE FROM skus WHERE id = ? AND company_id = ?');
    $del->execute([$skuId, $companyId]);
}

/**
 * @return array<string, array{item_name: string, sizes: list<array{sku_id: int, size: string, on_hand: int}>}>
 */
function us_item_catalog(PDO $pdo, int $companyId): array
{
    $catalog = [];
    foreach (us_stock_rows($pdo, $companyId) as $row) {
        $itemNo = (string) $row['item_no'];
        if (!isset($catalog[$itemNo])) {
            $catalog[$itemNo] = [
                'item_name' => (string) $row['item_name'],
                'sizes' => [],
            ];
        }
        $catalog[$itemNo]['sizes'][] = [
            'sku_id' => (int) $row['id'],
            'size' => (string) $row['size'],
            'on_hand' => (int) $row['on_hand'],
        ];
    }
    return $catalog;
}

function us_get_sku(PDO $pdo, int $skuId, ?int $companyId = null): ?array
{
    if ($companyId !== null) {
        $stmt = $pdo->prepare('SELECT id, company_id, item_no, item_name, size FROM skus WHERE id = ? AND company_id = ?');
        $stmt->execute([$skuId, $companyId]);
    } else {
        $stmt = $pdo->prepare('SELECT id, company_id, item_no, item_name, size FROM skus WHERE id = ?');
        $stmt->execute([$skuId]);
    }
    $row = $stmt->fetch();
    return $row ?: null;
}
