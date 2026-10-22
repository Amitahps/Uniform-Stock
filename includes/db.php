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

    // Keep FKs off while migrating — SQLite refuses DROP of parents when FKs are on.
    $pdo->exec('PRAGMA foreign_keys = OFF');
    try {
        us_migrate($pdo);
    } catch (Throwable $first) {
        us_drop_migrate_leftovers($pdo);
        try {
            us_migrate($pdo);
        } catch (Throwable $second) {
            throw new RuntimeException(
                'Database migrate failed: ' . $second->getMessage()
                . ' If this persists on Hostinger, delete only data/stock.db (keep the data/ folder),'
                . ' then reload /check.php — a fresh DB will be created. Original error: '
                . $first->getMessage(),
                0,
                $second
            );
        }
    }
    $pdo->exec('PRAGMA foreign_keys = ON');

    try {
        $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    } catch (Throwable $e) {
        // Ignore — some hosts dislike this pragma.
    }

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

function us_table_row_count(PDO $pdo, string $table): int
{
    if (!us_table_exists($pdo, $table)) {
        return 0;
    }
    return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}

function us_table_create_sql(PDO $pdo, string $table): string
{
    $stmt = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?");
    $stmt->execute([$table]);
    return (string) ($stmt->fetchColumn() ?: '');
}

/**
 * Ensure at least one real vendor company exists (not Bachpan/AHP.S).
 * Returns a vendor company id for orphan backfill.
 */
function us_ensure_vendor_company(PDO $pdo): int
{
    $rows = $pdo->query('SELECT id, name FROM companies ORDER BY id')->fetchAll();
    foreach ($rows as $row) {
        if (!us_is_reserved_school_name((string) $row['name'])) {
            return (int) $row['id'];
        }
    }

    // Only school-named companies (legacy) or empty — create a vendor shell.
    $pdo->exec("INSERT INTO companies (name) SELECT 'My Company' WHERE NOT EXISTS (SELECT 1 FROM companies WHERE name = 'My Company')");
    $id = (int) $pdo->query("SELECT id FROM companies WHERE name = 'My Company' LIMIT 1")->fetchColumn();
    if ($id <= 0) {
        $pdo->exec("INSERT INTO companies (name) VALUES ('My Company')");
        $id = (int) $pdo->lastInsertId();
    }
    return $id;
}

/** @deprecated */
function us_ensure_default_company(PDO $pdo): int
{
    return us_ensure_vendor_company($pdo);
}

/** @deprecated */
function us_ensure_schools(PDO $pdo): int
{
    return us_ensure_vendor_company($pdo);
}

/** Drop leftover *_new tables from a failed migrate (FK must be OFF). */
function us_drop_migrate_leftovers(PDO $pdo): void
{
    foreach (['skus_new', 'receipts_new', 'issues_new', 'skus_old', 'receipts_old', 'issues_old'] as $t) {
        $pdo->exec('DROP TABLE IF EXISTS ' . $t);
    }
}

/**
 * Recover mid-DROP / mid-RENAME leftovers before normal migrate.
 */
function us_recover_half_broken(PDO $pdo): void
{
    foreach (['skus', 'receipts', 'issues'] as $table) {
        $newName = $table . '_new';
        $has = us_table_exists($pdo, $table);
        $hasNew = us_table_exists($pdo, $newName);

        if (!$has && $hasNew) {
            $pdo->exec("ALTER TABLE {$newName} RENAME TO {$table}");
            continue;
        }

        if ($has && $hasNew) {
            $newCols = us_table_columns($pdo, $newName);
            $oldCols = us_table_columns($pdo, $table);
            $newHasCompany = in_array('company_id', $newCols, true);
            $oldHasCompany = in_array('company_id', $oldCols, true);
            $newCount = us_table_row_count($pdo, $newName);
            $oldCount = us_table_row_count($pdo, $table);

            if ($newHasCompany && (!$oldHasCompany || $newCount >= $oldCount)) {
                $pdo->exec('DROP TABLE ' . $table);
                $pdo->exec("ALTER TABLE {$newName} RENAME TO {$table}");
            } else {
                $pdo->exec('DROP TABLE ' . $newName);
            }
        }
    }
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

    us_recover_half_broken($pdo);
    $companyId = us_ensure_vendor_company($pdo);

    us_ensure_company_scoped_table(
        $pdo,
        'skus',
        $companyId,
        <<<'SQL'
CREATE TABLE skus (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL,
    school TEXT NOT NULL DEFAULT 'Bachpan',
    item_no TEXT NOT NULL,
    item_name TEXT NOT NULL,
    size TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(company_id, school, item_name, item_no, size),
    FOREIGN KEY (company_id) REFERENCES companies(id)
);
SQL,
        <<<'SQL'
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
SQL,
        'id, company_id, item_no, item_name, size, created_at',
        static function (array $cols, int $companyId): string {
            $created = in_array('created_at', $cols, true) ? 'created_at' : "datetime('now')";
            $itemNo = in_array('item_no', $cols, true) ? 'item_no' : "''";
            $itemName = in_array('item_name', $cols, true) ? 'item_name' : "''";
            $size = in_array('size', $cols, true) ? 'size' : "''";
            return "id, {$companyId}, {$itemNo}, {$itemName}, {$size}, {$created}";
        }
    );

    us_ensure_company_scoped_table(
        $pdo,
        'receipts',
        $companyId,
        <<<'SQL'
CREATE TABLE receipts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL,
    school TEXT NOT NULL DEFAULT 'Bachpan',
    bill_no TEXT NOT NULL,
    receipt_date TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(company_id, school, bill_no),
    FOREIGN KEY (company_id) REFERENCES companies(id)
);
SQL,
        <<<'SQL'
CREATE TABLE receipts_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL,
    bill_no TEXT NOT NULL,
    receipt_date TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(company_id, bill_no),
    FOREIGN KEY (company_id) REFERENCES companies(id)
);
SQL,
        'id, company_id, bill_no, receipt_date, created_at',
        static function (array $cols, int $companyId): string {
            $created = in_array('created_at', $cols, true) ? 'created_at' : "datetime('now')";
            $bill = in_array('bill_no', $cols, true) ? 'bill_no' : "''";
            $date = in_array('receipt_date', $cols, true) ? 'receipt_date' : "date('now')";
            return "id, {$companyId}, {$bill}, {$date}, {$created}";
        }
    );

    us_ensure_company_scoped_table(
        $pdo,
        'issues',
        $companyId,
        <<<'SQL'
CREATE TABLE issues (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL,
    school TEXT NOT NULL DEFAULT 'Bachpan',
    issue_date TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (company_id) REFERENCES companies(id)
);
SQL,
        <<<'SQL'
CREATE TABLE issues_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL,
    issue_date TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (company_id) REFERENCES companies(id)
);
SQL,
        'id, company_id, issue_date, created_at',
        static function (array $cols, int $companyId): string {
            $created = in_array('created_at', $cols, true) ? 'created_at' : "datetime('now')";
            $date = in_array('issue_date', $cols, true) ? 'issue_date' : "date('now')";
            return "id, {$companyId}, {$date}, {$created}";
        }
    );

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

    // Add school dimension (company + Bachpan|AHP.S) if missing or unique key outdated.
    us_ensure_school_columns($pdo);

    // Map legacy companies named Bachpan / AHP.S into school field under a vendor company.
    us_migrate_legacy_school_companies($pdo);

    $companyId = us_ensure_vendor_company($pdo);

    // Backfill / repair orphan company_id / school values.
    foreach (['skus', 'receipts', 'issues'] as $t) {
        $cols = us_table_columns($pdo, $t);
        if (in_array('company_id', $cols, true)) {
            $pdo->exec(
                "UPDATE {$t} SET company_id = " . (int) $companyId
                . " WHERE company_id IS NULL OR company_id = 0 OR company_id NOT IN (SELECT id FROM companies)"
            );
        }
        if (in_array('school', $cols, true)) {
            $pdo->exec(
                "UPDATE {$t} SET school = 'Bachpan'
                 WHERE school IS NULL OR TRIM(school) = '' OR school NOT IN ('Bachpan', 'AHP.S')"
            );
        }
    }

    $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)');
        $stmt->execute([US_DEFAULT_USER, password_hash(US_DEFAULT_PASS, PASSWORD_DEFAULT)]);
    }
}

/**
 * Ensure skus/receipts/issues have school column and correct uniqueness.
 */
function us_ensure_school_columns(PDO $pdo): void
{
    us_rebuild_if_school_needed(
        $pdo,
        'skus',
        <<<'SQL'
CREATE TABLE skus_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL,
    school TEXT NOT NULL DEFAULT 'Bachpan',
    item_no TEXT NOT NULL,
    item_name TEXT NOT NULL,
    size TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(company_id, school, item_name, item_no, size),
    FOREIGN KEY (company_id) REFERENCES companies(id)
);
SQL,
        'id, company_id, school, item_no, item_name, size, created_at',
        true
    );

    us_rebuild_if_school_needed(
        $pdo,
        'receipts',
        <<<'SQL'
CREATE TABLE receipts_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL,
    school TEXT NOT NULL DEFAULT 'Bachpan',
    bill_no TEXT NOT NULL,
    receipt_date TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(company_id, school, bill_no),
    FOREIGN KEY (company_id) REFERENCES companies(id)
);
SQL,
        'id, company_id, school, bill_no, receipt_date, created_at',
        true
    );

    us_rebuild_if_school_needed(
        $pdo,
        'issues',
        <<<'SQL'
CREATE TABLE issues_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL,
    school TEXT NOT NULL DEFAULT 'Bachpan',
    issue_date TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (company_id) REFERENCES companies(id)
);
SQL,
        'id, company_id, school, issue_date, created_at',
        false
    );
}

function us_rebuild_if_school_needed(
    PDO $pdo,
    string $table,
    string $createNewSql,
    string $insertCols,
    bool $needsSchoolInUnique
): void {
    if (!us_table_exists($pdo, $table)) {
        return;
    }
    $cols = us_table_columns($pdo, $table);
    $sql = us_table_create_sql($pdo, $table);
    $hasSchool = in_array('school', $cols, true);
    // SKUs must be unique on company + school + item_name + item_no + size
    // (same code/size allowed for different names).
    $uniqueOk = !$needsSchoolInUnique
        || ($table === 'skus' && str_contains($sql, 'UNIQUE(company_id, school, item_name, item_no, size)'))
        || ($table === 'receipts' && str_contains($sql, 'UNIQUE(company_id, school, bill_no)'))
        || ($table !== 'skus' && $table !== 'receipts');

    if ($hasSchool && $uniqueOk) {
        return;
    }

    $newName = $table . '_new';
    $pdo->exec('DROP TABLE IF EXISTS ' . $newName);
    $pdo->exec($createNewSql);

    if ($table === 'skus') {
        $select = <<<'SQL'
SELECT
    s.id,
    s.company_id,
    CASE
      WHEN COALESCE(s.school, '') IN ('Bachpan', 'AHP.S') THEN s.school
      WHEN c.name = 'AHP.S' THEN 'AHP.S'
      WHEN c.name = 'Bachpan' THEN 'Bachpan'
      ELSE 'Bachpan'
    END,
    s.item_no,
    s.item_name,
    s.size,
    COALESCE(s.created_at, datetime('now'))
FROM skus s
LEFT JOIN companies c ON c.id = s.company_id
SQL;
        if (!in_array('school', $cols, true)) {
            $select = <<<'SQL'
SELECT
    s.id,
    s.company_id,
    CASE
      WHEN c.name = 'AHP.S' THEN 'AHP.S'
      WHEN c.name = 'Bachpan' THEN 'Bachpan'
      ELSE 'Bachpan'
    END,
    s.item_no,
    s.item_name,
    s.size,
    COALESCE(s.created_at, datetime('now'))
FROM skus s
LEFT JOIN companies c ON c.id = s.company_id
SQL;
        }
    } elseif ($table === 'receipts') {
        if (in_array('school', $cols, true)) {
            $select = <<<'SQL'
SELECT
    r.id,
    r.company_id,
    CASE
      WHEN COALESCE(r.school, '') IN ('Bachpan', 'AHP.S') THEN r.school
      WHEN c.name = 'AHP.S' THEN 'AHP.S'
      WHEN c.name = 'Bachpan' THEN 'Bachpan'
      ELSE 'Bachpan'
    END,
    r.bill_no,
    r.receipt_date,
    COALESCE(r.created_at, datetime('now'))
FROM receipts r
LEFT JOIN companies c ON c.id = r.company_id
SQL;
        } else {
            $select = <<<'SQL'
SELECT
    r.id,
    r.company_id,
    CASE
      WHEN c.name = 'AHP.S' THEN 'AHP.S'
      WHEN c.name = 'Bachpan' THEN 'Bachpan'
      ELSE 'Bachpan'
    END,
    r.bill_no,
    r.receipt_date,
    COALESCE(r.created_at, datetime('now'))
FROM receipts r
LEFT JOIN companies c ON c.id = r.company_id
SQL;
        }
    } else {
        if (in_array('school', $cols, true)) {
            $select = <<<'SQL'
SELECT
    i.id,
    i.company_id,
    CASE
      WHEN COALESCE(i.school, '') IN ('Bachpan', 'AHP.S') THEN i.school
      WHEN c.name = 'AHP.S' THEN 'AHP.S'
      WHEN c.name = 'Bachpan' THEN 'Bachpan'
      ELSE 'Bachpan'
    END,
    i.issue_date,
    COALESCE(i.created_at, datetime('now'))
FROM issues i
LEFT JOIN companies c ON c.id = i.company_id
SQL;
        } else {
            $select = <<<'SQL'
SELECT
    i.id,
    i.company_id,
    CASE
      WHEN c.name = 'AHP.S' THEN 'AHP.S'
      WHEN c.name = 'Bachpan' THEN 'Bachpan'
      ELSE 'Bachpan'
    END,
    i.issue_date,
    COALESCE(i.created_at, datetime('now'))
FROM issues i
LEFT JOIN companies c ON c.id = i.company_id
SQL;
        }
    }

    $pdo->exec("INSERT INTO {$newName} ({$insertCols}) {$select}");
    $pdo->exec('DROP TABLE ' . $table);
    $pdo->exec("ALTER TABLE {$newName} RENAME TO {$table}");
}

/**
 * Move data from companies named Bachpan/AHP.S into a vendor company with school set,
 * then delete those legacy company rows.
 */
function us_migrate_legacy_school_companies(PDO $pdo): void
{
    $vendorId = us_ensure_vendor_company($pdo);

    foreach (us_schools() as $school) {
        $stmt = $pdo->prepare('SELECT id FROM companies WHERE name = ?');
        $stmt->execute([$school]);
        $oldId = (int) $stmt->fetchColumn();
        if ($oldId <= 0) {
            continue;
        }
        if ($oldId === $vendorId) {
            // Vendor row was wrongly named after a school — rename it first.
            $pdo->prepare("UPDATE companies SET name = 'My Company' WHERE id = ?")->execute([$oldId]);
            // Keep data under this id; school column already inferred.
            continue;
        }

        foreach (['skus', 'receipts', 'issues'] as $t) {
            if (!in_array('school', us_table_columns($pdo, $t), true)) {
                continue;
            }
            $pdo->prepare(
                "UPDATE {$t} SET company_id = ?, school = ? WHERE company_id = ?"
            )->execute([$vendorId, $school, $oldId]);
        }

        // Drop empty legacy school-as-company row.
        $pdo->prepare('DELETE FROM companies WHERE id = ?')->execute([$oldId]);
    }
}

/**
 * @param callable(array,int):string $selectBuilder
 */
function us_ensure_company_scoped_table(
    PDO $pdo,
    string $table,
    int $companyId,
    string $createFreshSql,
    string $createNewSql,
    string $insertCols,
    callable $selectBuilder
): void {
    $cols = us_table_columns($pdo, $table);
    if ($cols === []) {
        $pdo->exec($createFreshSql);
        return;
    }
    if (in_array('company_id', $cols, true)) {
        return;
    }
    $selectExpr = $selectBuilder($cols, $companyId);
    us_rebuild_table_with_company($pdo, $table, $createNewSql, $insertCols, $selectExpr);
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
    $pdo->exec('DROP TABLE IF EXISTS ' . $newName);
    $pdo->exec($createNewSql);
    $pdo->exec("INSERT INTO {$newName} ({$insertCols}) SELECT {$selectExpr} FROM {$table}");
    $pdo->exec('DROP TABLE ' . $table);
    $pdo->exec("ALTER TABLE {$newName} RENAME TO {$table}");
}

function us_list_companies(PDO $pdo): array
{
    return $pdo->query(
        'SELECT id, name, created_at FROM companies ORDER BY name COLLATE NOCASE'
    )->fetchAll();
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
    if (us_is_reserved_school_name($name)) {
        throw new RuntimeException(
            '"' . $name . '" is a school, not a company. Create your business/vendor company, then choose Bachpan or AHP.S for stock.'
        );
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

/**
 * Permanently delete one company and only its stock data (all schools under it).
 * Other companies are untouched. Caller must verify admin password first.
 */
function us_delete_company(PDO $pdo, int $companyId): void
{
    $company = us_get_company($pdo, $companyId);
    if (!$company) {
        throw new RuntimeException('Company not found.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'DELETE FROM issue_lines WHERE issue_id IN (SELECT id FROM issues WHERE company_id = ?)'
        )->execute([$companyId]);
        $pdo->prepare(
            'DELETE FROM receipt_lines WHERE receipt_id IN (SELECT id FROM receipts WHERE company_id = ?)'
        )->execute([$companyId]);
        $pdo->prepare(
            'DELETE FROM issue_lines WHERE sku_id IN (SELECT id FROM skus WHERE company_id = ?)'
        )->execute([$companyId]);
        $pdo->prepare(
            'DELETE FROM receipt_lines WHERE sku_id IN (SELECT id FROM skus WHERE company_id = ?)'
        )->execute([$companyId]);

        $pdo->prepare('DELETE FROM issues WHERE company_id = ?')->execute([$companyId]);
        $pdo->prepare('DELETE FROM receipts WHERE company_id = ?')->execute([$companyId]);
        $pdo->prepare('DELETE FROM skus WHERE company_id = ?')->execute([$companyId]);
        $pdo->prepare('DELETE FROM companies WHERE id = ?')->execute([$companyId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
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

/** On-hand for a SKU counting only movements with dates on or before $asOf (Y-m-d). */
function us_on_hand_as_of(PDO $pdo, int $skuId, string $asOf): int
{
    $in = $pdo->prepare(
        'SELECT COALESCE(SUM(rl.quantity), 0)
         FROM receipt_lines rl
         INNER JOIN receipts r ON r.id = rl.receipt_id
         WHERE rl.sku_id = ? AND r.receipt_date <= ?'
    );
    $in->execute([$skuId, $asOf]);
    $out = $pdo->prepare(
        'SELECT COALESCE(SUM(il.quantity), 0)
         FROM issue_lines il
         INNER JOIN issues i ON i.id = il.issue_id
         WHERE il.sku_id = ? AND i.issue_date <= ?'
    );
    $out->execute([$skuId, $asOf]);
    return (int) $in->fetchColumn() - (int) $out->fetchColumn();
}

/**
 * Date-range movement report for one company + school.
 *
 * @return list<array{id:int,item_no:string,item_name:string,size:string,qty_in:int,qty_out:int,balance:int}>
 */
function us_movement_rows(
    PDO $pdo,
    int $companyId,
    string $school,
    string $dateFrom,
    string $dateTo,
    ?string $itemNo = null,
    ?string $itemName = null
): array {
    $skus = us_stock_rows($pdo, $companyId, $school, $itemNo, null, $itemName);
    $inStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(rl.quantity), 0)
         FROM receipt_lines rl
         INNER JOIN receipts r ON r.id = rl.receipt_id
         WHERE rl.sku_id = ? AND r.company_id = ? AND r.school = ?
           AND r.receipt_date >= ? AND r.receipt_date <= ?'
    );
    $outStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(il.quantity), 0)
         FROM issue_lines il
         INNER JOIN issues i ON i.id = il.issue_id
         WHERE il.sku_id = ? AND i.company_id = ? AND i.school = ?
           AND i.issue_date >= ? AND i.issue_date <= ?'
    );

    $rows = [];
    foreach ($skus as $sku) {
        $skuId = (int) $sku['id'];
        $inStmt->execute([$skuId, $companyId, $school, $dateFrom, $dateTo]);
        $outStmt->execute([$skuId, $companyId, $school, $dateFrom, $dateTo]);
        $qtyIn = (int) $inStmt->fetchColumn();
        $qtyOut = (int) $outStmt->fetchColumn();
        $rows[] = [
            'id' => $skuId,
            'item_no' => (string) $sku['item_no'],
            'item_name' => (string) $sku['item_name'],
            'size' => (string) $sku['size'],
            'qty_in' => $qtyIn,
            'qty_out' => $qtyOut,
            'balance' => us_on_hand_as_of($pdo, $skuId, $dateTo),
        ];
    }
    return $rows;
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

function us_stock_rows(
    PDO $pdo,
    int $companyId,
    string $school,
    ?string $itemNo = null,
    ?string $size = null,
    ?string $itemName = null
): array {
    if (!us_is_valid_school($school)) {
        return [];
    }
    $sql = <<<'SQL'
SELECT
    s.id,
    s.company_id,
    s.school,
    s.item_no,
    s.item_name,
    s.size,
    COALESCE((SELECT SUM(rl.quantity) FROM receipt_lines rl WHERE rl.sku_id = s.id), 0)
      - COALESCE((SELECT SUM(il.quantity) FROM issue_lines il WHERE il.sku_id = s.id), 0) AS on_hand
FROM skus s
WHERE s.company_id = ? AND s.school = ?
SQL;
    $params = [$companyId, $school];
    if ($itemName !== null && $itemName !== '') {
        $sql .= ' AND s.item_name = ?';
        $params[] = $itemName;
    }
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

function us_create_sku(
    PDO $pdo,
    int $companyId,
    string $school,
    string $itemNo,
    string $itemName,
    string $size
): int {
    if (!us_is_valid_school($school)) {
        throw new RuntimeException('Choose Bachpan or AHP.S as the school.');
    }
    $itemNo = trim($itemNo);
    $itemName = trim($itemName);
    $size = trim($size);
    if ($itemNo === '' || $itemName === '' || $size === '') {
        throw new RuntimeException('Item name, item code, and size are all required.');
    }

    // Unique: company + school + name + code + size.
    // Same code/size is allowed when the item name differs (e.g. Shirt U-01/32 and Trouser U-01/32).
    $find = $pdo->prepare(
        'SELECT id FROM skus
         WHERE company_id = ? AND school = ? AND item_name = ? AND item_no = ? AND size = ?'
    );
    $find->execute([$companyId, $school, $itemName, $itemNo, $size]);
    if ($find->fetch()) {
        throw new RuntimeException(
            'Item "' . $itemName . '" with code "' . $itemNo . '" and size "' . $size
            . '" already exists for this school. Change the name, code, or size.'
        );
    }

    $ins = $pdo->prepare(
        'INSERT INTO skus (company_id, school, item_no, item_name, size) VALUES (?, ?, ?, ?, ?)'
    );
    $ins->execute([$companyId, $school, $itemNo, $itemName, $size]);
    return (int) $pdo->lastInsertId();
}

function us_update_sku(
    PDO $pdo,
    int $companyId,
    string $school,
    int $skuId,
    string $itemNo,
    string $itemName,
    string $size
): void {
    $itemNo = trim($itemNo);
    $itemName = trim($itemName);
    $size = trim($size);
    if ($itemNo === '' || $itemName === '' || $size === '') {
        throw new RuntimeException('Item name, item code, and size are all required.');
    }

    $sku = us_get_sku($pdo, $skuId, $companyId, $school);
    if (!$sku) {
        throw new RuntimeException('Item not found for this company and school.');
    }

    $dup = $pdo->prepare(
        'SELECT id FROM skus
         WHERE company_id = ? AND school = ? AND item_name = ? AND item_no = ? AND size = ? AND id != ?'
    );
    $dup->execute([$companyId, $school, $itemName, $itemNo, $size, $skuId]);
    if ($dup->fetch()) {
        throw new RuntimeException(
            'Another item already uses name "' . $itemName . '", code "' . $itemNo
            . '", and size "' . $size . '".'
        );
    }

    $upd = $pdo->prepare(
        'UPDATE skus SET item_no = ?, item_name = ?, size = ? WHERE id = ? AND company_id = ? AND school = ?'
    );
    $upd->execute([$itemNo, $itemName, $size, $skuId, $companyId, $school]);
}

function us_delete_sku(PDO $pdo, int $companyId, string $school, int $skuId): void
{
    $sku = us_get_sku($pdo, $skuId, $companyId, $school);
    if (!$sku) {
        throw new RuntimeException('Item not found for this company and school.');
    }
    if (us_sku_has_movements($pdo, $skuId)) {
        throw new RuntimeException(
            'Cannot delete this item — it has stock-in or stock-out history. Delete is only allowed for unused SKUs.'
        );
    }
    $del = $pdo->prepare('DELETE FROM skus WHERE id = ? AND company_id = ? AND school = ?');
    $del->execute([$skuId, $companyId, $school]);
}

/**
 * Catalog keyed by item_name, then item_no (codes may repeat across names).
 *
 * @return array<string, array<string, list<array{sku_id: int, size: string, on_hand: int}>>>
 */
function us_item_catalog(PDO $pdo, int $companyId, string $school): array
{
    $catalog = [];
    foreach (us_stock_rows($pdo, $companyId, $school) as $row) {
        $name = (string) $row['item_name'];
        $itemNo = (string) $row['item_no'];
        if (!isset($catalog[$name])) {
            $catalog[$name] = [];
        }
        if (!isset($catalog[$name][$itemNo])) {
            $catalog[$name][$itemNo] = [];
        }
        $catalog[$name][$itemNo][] = [
            'sku_id' => (int) $row['id'],
            'size' => (string) $row['size'],
            'on_hand' => (int) $row['on_hand'],
        ];
    }
    return $catalog;
}

function us_get_sku(
    PDO $pdo,
    int $skuId,
    ?int $companyId = null,
    ?string $school = null
): ?array {
    if ($companyId !== null && $school !== null) {
        $stmt = $pdo->prepare(
            'SELECT id, company_id, school, item_no, item_name, size FROM skus
             WHERE id = ? AND company_id = ? AND school = ?'
        );
        $stmt->execute([$skuId, $companyId, $school]);
    } elseif ($companyId !== null) {
        $stmt = $pdo->prepare(
            'SELECT id, company_id, school, item_no, item_name, size FROM skus WHERE id = ? AND company_id = ?'
        );
        $stmt->execute([$skuId, $companyId]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, company_id, school, item_no, item_name, size FROM skus WHERE id = ?'
        );
        $stmt->execute([$skuId]);
    }
    $row = $stmt->fetch();
    return $row ?: null;
}
