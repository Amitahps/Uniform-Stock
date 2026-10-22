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
    role TEXT NOT NULL DEFAULT 'admin',
    has_power INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS companies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS user_companies (
    user_id INTEGER NOT NULL,
    company_id INTEGER NOT NULL,
    PRIMARY KEY (user_id, company_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS company_transfers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    from_company_id INTEGER NOT NULL,
    to_company_id INTEGER NOT NULL,
    school TEXT NOT NULL,
    item_name TEXT NOT NULL,
    item_no TEXT NOT NULL,
    size TEXT NOT NULL,
    quantity INTEGER NOT NULL CHECK (quantity > 0),
    issued_by_user_id INTEGER NOT NULL,
    issued_by_username TEXT NOT NULL,
    transfer_date TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    from_issue_id INTEGER,
    to_receipt_id INTEGER,
    FOREIGN KEY (from_company_id) REFERENCES companies(id),
    FOREIGN KEY (to_company_id) REFERENCES companies(id),
    FOREIGN KEY (issued_by_user_id) REFERENCES users(id)
);
SQL);

    us_migrate_users_roles($pdo);
    us_migrate_company_transfers($pdo);

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
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, password_hash, role, has_power) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            US_DEFAULT_USER,
            password_hash(US_DEFAULT_PASS, PASSWORD_DEFAULT),
            'admin',
            1,
        ]);
    }

    // Catalog alignment for new companies happens in us_create_company.
    // Item create uses us_create_sku_all_companies. Avoid re-syncing every request
    // so a company-local edit is not undone by other companies' SKU copies.
    us_purge_by_school_skus_from_bachpan_hsp($pdo);
}

/** Ensure users.role / has_power exist; user_companies table ready. */
function us_migrate_users_roles(PDO $pdo): void
{
    $cols = us_table_columns($pdo, 'users');
    if ($cols !== [] && !in_array('role', $cols, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'admin'");
    }
    if ($cols !== [] && !in_array('has_power', $cols, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN has_power INTEGER NOT NULL DEFAULT 0');
    }
    if (us_table_exists($pdo, 'users')) {
        $pdo->exec(
            "UPDATE users SET role = 'admin'
             WHERE role IS NULL OR TRIM(role) = '' OR role NOT IN ('admin', 'staff')"
        );
        // Admins always treated as powered.
        $pdo->exec("UPDATE users SET has_power = 1 WHERE role = 'admin'");
    }
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS user_companies (
    user_id INTEGER NOT NULL,
    company_id INTEGER NOT NULL,
    PRIMARY KEY (user_id, company_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);
SQL);
}

function us_migrate_company_transfers(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS company_transfers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    from_company_id INTEGER NOT NULL,
    to_company_id INTEGER NOT NULL,
    school TEXT NOT NULL,
    item_name TEXT NOT NULL,
    item_no TEXT NOT NULL,
    size TEXT NOT NULL,
    quantity INTEGER NOT NULL CHECK (quantity > 0),
    issued_by_user_id INTEGER NOT NULL,
    issued_by_username TEXT NOT NULL,
    transfer_date TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    from_issue_id INTEGER,
    to_receipt_id INTEGER,
    FOREIGN KEY (from_company_id) REFERENCES companies(id),
    FOREIGN KEY (to_company_id) REFERENCES companies(id),
    FOREIGN KEY (issued_by_user_id) REFERENCES users(id)
);
SQL);
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
        $id = (int) $pdo->lastInsertId();
        // New company inherits the full catalog for both schools
        // (Bachpan HSP never receives By School–only items).
        us_sync_catalog_to_company($pdo, $id);
        return $id;
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) {
            throw new RuntimeException('A company with that name already exists.');
        }
        throw $e;
    }
}

function us_rename_company(PDO $pdo, int $companyId, string $newName): void
{
    $newName = trim($newName);
    if ($newName === '') {
        throw new RuntimeException('Company name is required.');
    }
    if (us_is_reserved_school_name($newName)) {
        throw new RuntimeException(
            '"' . $newName . '" is a school, not a company.'
        );
    }
    $company = us_get_company($pdo, $companyId);
    if (!$company) {
        throw new RuntimeException('Company not found.');
    }
    if (us_company_name_equals((string) $company['name'], $newName)) {
        // Same name (case/spacing only) — still allow exact rename for casing cleanup.
        if (trim((string) $company['name']) === $newName) {
            return;
        }
    }
    try {
        $stmt = $pdo->prepare('UPDATE companies SET name = ? WHERE id = ?');
        $stmt->execute([$newName, $companyId]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) {
            throw new RuntimeException('A company with that name already exists.');
        }
        throw $e;
    }
    // After rename, heal catalog rules (e.g. newly named Bachpan HSP).
    us_purge_by_school_skus_from_bachpan_hsp($pdo);
    us_sync_catalog_to_company($pdo, $companyId);
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

        $pdo->prepare(
            'DELETE FROM company_transfers WHERE from_company_id = ? OR to_company_id = ?'
        )->execute([$companyId, $companyId]);
        $pdo->prepare('DELETE FROM user_companies WHERE company_id = ?')->execute([$companyId]);
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

/**
 * Find SKU id for company + school + name + code + size, or null.
 */
function us_find_sku_id(
    PDO $pdo,
    int $companyId,
    string $school,
    string $itemName,
    string $itemNo,
    string $size
): ?int {
    $find = $pdo->prepare(
        'SELECT id FROM skus
         WHERE company_id = ? AND school = ? AND item_name = ? AND item_no = ? AND size = ?'
    );
    $find->execute([$companyId, $school, $itemName, $itemNo, $size]);
    $id = $find->fetchColumn();
    return $id === false ? null : (int) $id;
}

/**
 * Insert SKU if missing; return existing or new id. Does not throw on duplicate.
 */
function us_ensure_sku(
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
    $existing = us_find_sku_id($pdo, $companyId, $school, $itemName, $itemNo, $size);
    if ($existing !== null) {
        return $existing;
    }
    $ins = $pdo->prepare(
        'INSERT INTO skus (company_id, school, item_no, item_name, size) VALUES (?, ?, ?, ?, ?)'
    );
    try {
        $ins->execute([$companyId, $school, $itemNo, $itemName, $size]);
        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) {
            $again = us_find_sku_id($pdo, $companyId, $school, $itemName, $itemNo, $size);
            if ($again !== null) {
                return $again;
            }
        }
        throw $e;
    }
}

/**
 * Create one SKU in a single company (throws if already exists there).
 */
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

    if (us_find_sku_id($pdo, $companyId, $school, $itemName, $itemNo, $size) !== null) {
        throw new RuntimeException(
            'Item "' . $itemName . '" with code "' . $itemNo . '" and size "' . $size
            . '" already exists for this school. Change the name, code, or size.'
        );
    }

    return us_ensure_sku($pdo, $companyId, $school, $itemNo, $itemName, $size);
}

/**
 * Create the same SKU (school + name + code + size) in every company.
 * Schools never mix. Returns the SKU id for $companyId.
 *
 * @return array{sku_id:int, created_count:int, company_count:int}
 */
function us_create_sku_all_companies(
    PDO $pdo,
    int $companyId,
    string $school,
    string $itemNo,
    string $itemName,
    string $size
): array {
    if (!us_is_valid_school($school)) {
        throw new RuntimeException('Choose Bachpan or AHP.S as the school.');
    }
    $itemNo = trim($itemNo);
    $itemName = trim($itemName);
    $size = trim($size);
    if ($itemNo === '' || $itemName === '' || $size === '') {
        throw new RuntimeException('Item name, item code, and size are all required.');
    }

    // Reject if already exists in the active company (clear UX).
    if (us_find_sku_id($pdo, $companyId, $school, $itemName, $itemNo, $size) !== null) {
        throw new RuntimeException(
            'Item "' . $itemName . '" with code "' . $itemNo . '" and size "' . $size
            . '" already exists for this school. Change the name, code, or size.'
        );
    }

    $source = us_get_company($pdo, $companyId);
    if (!$source) {
        throw new RuntimeException('Company not found.');
    }
    $sourceName = (string) $source['name'];

    $companies = us_list_companies($pdo);
    if ($companies === []) {
        throw new RuntimeException('Create a company first.');
    }

    $created = 0;
    $activeSkuId = 0;
    $targets = 0;
    $pdo->beginTransaction();
    try {
        foreach ($companies as $c) {
            $cid = (int) $c['id'];
            $targetName = (string) $c['name'];
            if (us_catalog_skip_target($sourceName, $targetName)) {
                continue;
            }
            $targets++;
            $before = us_find_sku_id($pdo, $cid, $school, $itemName, $itemNo, $size);
            $skuId = us_ensure_sku($pdo, $cid, $school, $itemNo, $itemName, $size);
            if ($before === null) {
                $created++;
            }
            if ($cid === $companyId) {
                $activeSkuId = $skuId;
            }
        }
        // Always ensure the active company has the SKU even if somehow skipped.
        if ($activeSkuId <= 0) {
            $activeSkuId = us_ensure_sku($pdo, $companyId, $school, $itemNo, $itemName, $size);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'sku_id' => $activeSkuId,
        'created_count' => $created,
        'company_count' => $targets,
    ];
}

/**
 * Distinct catalog rows (school + name + code + size) across all companies.
 *
 * @return list<array{school:string,item_name:string,item_no:string,size:string}>
 */
function us_catalog_blueprint(PDO $pdo, bool $excludeBySchoolOnly = false): array
{
    if ($excludeBySchoolOnly) {
        // SKUs present in at least one company that is not "By School".
        $stmt = $pdo->prepare(
            "SELECT DISTINCT s.school, s.item_name, s.item_no, s.size
             FROM skus s
             INNER JOIN companies c ON c.id = s.company_id
             WHERE s.school IN ('Bachpan', 'AHP.S')
               AND LOWER(TRIM(c.name)) != LOWER(?)
             ORDER BY s.school, s.item_name COLLATE NOCASE, s.item_no COLLATE NOCASE, s.size COLLATE NOCASE"
        );
        $stmt->execute([trim(US_CATALOG_COMPANY_BY_SCHOOL)]);
        return $stmt->fetchAll();
    }
    return $pdo->query(
        "SELECT DISTINCT school, item_name, item_no, size
         FROM skus
         WHERE school IN ('Bachpan', 'AHP.S')
         ORDER BY school, item_name COLLATE NOCASE, item_no COLLATE NOCASE, size COLLATE NOCASE"
    )->fetchAll();
}

/** Copy missing catalog SKUs into one company for both schools. */
function us_sync_catalog_to_company(PDO $pdo, int $companyId): int
{
    $company = us_get_company($pdo, $companyId);
    if (!$company) {
        return 0;
    }
    // Bachpan HSP must not receive items that exist only under By School.
    $excludeBySchool = us_is_bachpan_hsp_company((string) $company['name']);
    $added = 0;
    foreach (us_catalog_blueprint($pdo, $excludeBySchool) as $row) {
        $school = (string) $row['school'];
        if (!us_is_valid_school($school)) {
            continue;
        }
        $before = us_find_sku_id(
            $pdo,
            $companyId,
            $school,
            (string) $row['item_name'],
            (string) $row['item_no'],
            (string) $row['size']
        );
        us_ensure_sku(
            $pdo,
            $companyId,
            $school,
            (string) $row['item_no'],
            (string) $row['item_name'],
            (string) $row['size']
        );
        if ($before === null) {
            $added++;
        }
    }
    return $added;
}

/**
 * Remove unused By School–only SKUs from Bachpan HSP so they stay invisible.
 * Keeps rows that have stock history (cannot silently delete movements).
 */
function us_purge_by_school_skus_from_bachpan_hsp(PDO $pdo): void
{
    if (!us_table_exists($pdo, 'skus') || !us_table_exists($pdo, 'companies')) {
        return;
    }
    $hsp = null;
    $bySchoolId = null;
    foreach (us_list_companies($pdo) as $c) {
        if (us_is_bachpan_hsp_company((string) $c['name'])) {
            $hsp = (int) $c['id'];
        }
        if (us_is_by_school_company((string) $c['name'])) {
            $bySchoolId = (int) $c['id'];
        }
    }
    if ($hsp === null || $bySchoolId === null) {
        return;
    }

    $stmt = $pdo->prepare(
        'SELECT id, school, item_name, item_no, size FROM skus WHERE company_id = ?'
    );
    $stmt->execute([$hsp]);
    $del = $pdo->prepare('DELETE FROM skus WHERE id = ?');
    foreach ($stmt->fetchAll() as $sku) {
        $skuId = (int) $sku['id'];
        if (us_sku_has_movements($pdo, $skuId)) {
            continue;
        }
        // Present under By School?
        $inBySchool = us_find_sku_id(
            $pdo,
            $bySchoolId,
            (string) $sku['school'],
            (string) $sku['item_name'],
            (string) $sku['item_no'],
            (string) $sku['size']
        );
        if ($inBySchool === null) {
            continue;
        }
        // Also present in any non–By School / non–Bachpan HSP company?
        $elsewhere = $pdo->prepare(
            "SELECT 1 FROM skus s
             INNER JOIN companies c ON c.id = s.company_id
             WHERE s.school = ? AND s.item_name = ? AND s.item_no = ? AND s.size = ?
               AND s.company_id NOT IN (?, ?)
             LIMIT 1"
        );
        $elsewhere->execute([
            (string) $sku['school'],
            (string) $sku['item_name'],
            (string) $sku['item_no'],
            (string) $sku['size'],
            $bySchoolId,
            $hsp,
        ]);
        if ($elsewhere->fetchColumn()) {
            continue; // shared catalog item — keep on Bachpan HSP
        }
        $del->execute([$skuId]);
    }
}

/** Align every company's catalog to the union of all SKUs (both schools). */
function us_sync_all_company_catalogs(PDO $pdo): void
{
    if (!us_table_exists($pdo, 'skus') || !us_table_exists($pdo, 'companies')) {
        return;
    }
    $cols = us_table_columns($pdo, 'skus');
    if (!in_array('school', $cols, true)) {
        return;
    }
    us_purge_by_school_skus_from_bachpan_hsp($pdo);
    foreach (us_list_companies($pdo) as $c) {
        us_sync_catalog_to_company($pdo, (int) $c['id']);
    }
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
 * Remove name+code+size for the active school from every company.
 * Blocked if any company copy has stock history.
 *
 * @return int number of SKU rows deleted
 */
function us_delete_sku_all_companies(
    PDO $pdo,
    string $school,
    string $itemName,
    string $itemNo,
    string $size
): int {
    if (!us_is_valid_school($school)) {
        throw new RuntimeException('Choose Bachpan or AHP.S as the school.');
    }
    $itemName = trim($itemName);
    $itemNo = trim($itemNo);
    $size = trim($size);

    $stmt = $pdo->prepare(
        'SELECT id, company_id FROM skus
         WHERE school = ? AND item_name = ? AND item_no = ? AND size = ?'
    );
    $stmt->execute([$school, $itemName, $itemNo, $size]);
    $rows = $stmt->fetchAll();
    if ($rows === []) {
        throw new RuntimeException('Item not found for this school.');
    }

    foreach ($rows as $row) {
        if (us_sku_has_movements($pdo, (int) $row['id'])) {
            $co = us_get_company($pdo, (int) $row['company_id']);
            $coName = $co ? (string) $co['name'] : ('#' . $row['company_id']);
            throw new RuntimeException(
                'Cannot remove "' . $itemName . '" · ' . $itemNo . ' · ' . $size
                . ' — company "' . $coName . '" has stock-in or stock-out history for this SKU.'
            );
        }
    }

    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare(
            'DELETE FROM skus WHERE school = ? AND item_name = ? AND item_no = ? AND size = ?'
        );
        $del->execute([$school, $itemName, $itemNo, $size]);
        $count = $del->rowCount();
        $pdo->commit();
        return $count;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Issue stock from one company to another (same school + name/code/size).
 * Deducts source via issue; credits destination via receipt; records company_transfers.
 *
 * @return int transfer id
 */
function us_issue_to_company(
    PDO $pdo,
    int $fromCompanyId,
    int $toCompanyId,
    string $school,
    int $fromSkuId,
    int $quantity,
    string $transferDate,
    array $issuer
): int {
    if ($fromCompanyId === $toCompanyId) {
        throw new RuntimeException('Choose a different company to issue to.');
    }
    if (!us_is_valid_school($school)) {
        throw new RuntimeException('Choose Bachpan or AHP.S as the school.');
    }
    if ($quantity < 1) {
        throw new RuntimeException('Quantity must be at least 1.');
    }
    if ($transferDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $transferDate)) {
        throw new RuntimeException('A valid issue date is required.');
    }
    if (!us_get_company($pdo, $fromCompanyId) || !us_get_company($pdo, $toCompanyId)) {
        throw new RuntimeException('Company not found.');
    }

    $sku = us_get_sku($pdo, $fromSkuId, $fromCompanyId, $school);
    if (!$sku) {
        throw new RuntimeException('Item not found in the issuing company for this school.');
    }
    $itemName = (string) $sku['item_name'];
    $itemNo = (string) $sku['item_no'];
    $size = (string) $sku['size'];

    $onHand = us_on_hand($pdo, $fromSkuId);
    if ($quantity > $onHand) {
        throw new RuntimeException(
            'Cannot issue ' . $quantity . ' of ' . $itemName . ' / ' . $itemNo . ' / ' . $size
            . ' — only ' . $onHand . ' on hand in the issuing company.'
        );
    }

    $issuerId = (int) ($issuer['id'] ?? 0);
    $issuerName = trim((string) ($issuer['username'] ?? ''));
    if ($issuerId <= 0 || $issuerName === '') {
        throw new RuntimeException('Issuer user is required.');
    }

    $pdo->beginTransaction();
    try {
        // Deduct from source company.
        $insI = $pdo->prepare(
            'INSERT INTO issues (company_id, school, issue_date) VALUES (?, ?, ?)'
        );
        $insI->execute([$fromCompanyId, $school, $transferDate]);
        $issueId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO issue_lines (issue_id, sku_id, quantity) VALUES (?, ?, ?)'
        )->execute([$issueId, $fromSkuId, $quantity]);

        // Ensure destination has the same SKU; credit stock via receipt.
        $toSkuId = us_ensure_sku($pdo, $toCompanyId, $school, $itemNo, $itemName, $size);
        $billNo = 'XFER-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
        $insR = $pdo->prepare(
            'INSERT INTO receipts (company_id, school, bill_no, receipt_date) VALUES (?, ?, ?, ?)'
        );
        $insR->execute([$toCompanyId, $school, $billNo, $transferDate]);
        $receiptId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO receipt_lines (receipt_id, sku_id, quantity) VALUES (?, ?, ?)'
        )->execute([$receiptId, $toSkuId, $quantity]);

        $insT = $pdo->prepare(
            'INSERT INTO company_transfers (
                from_company_id, to_company_id, school,
                item_name, item_no, size, quantity,
                issued_by_user_id, issued_by_username, transfer_date,
                from_issue_id, to_receipt_id
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insT->execute([
            $fromCompanyId,
            $toCompanyId,
            $school,
            $itemName,
            $itemNo,
            $size,
            $quantity,
            $issuerId,
            $issuerName,
            $transferDate,
            $issueId,
            $receiptId,
        ]);
        $transferId = (int) $pdo->lastInsertId();
        $pdo->commit();
        return $transferId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Item-wise company-to-company issue report (date from → to, optional filters).
 *
 * @return list<array{
 *   item_name:string,item_no:string,size:string,
 *   qty_issued:int,transfer_count:int,
 *   from_company:string,to_company:string,
 *   issued_by:string,last_date:string
 * }>
 */
function us_company_transfer_report(
    PDO $pdo,
    string $dateFrom,
    string $dateTo,
    ?int $fromCompanyId = null,
    ?int $toCompanyId = null,
    ?string $school = null,
    ?string $itemName = null,
    ?string $itemNo = null
): array {
    $sql = <<<'SQL'
SELECT
    t.item_name,
    t.item_no,
    t.size,
    t.school,
    fc.name AS from_company,
    tc.name AS to_company,
    SUM(t.quantity) AS qty_issued,
    COUNT(*) AS transfer_count,
    GROUP_CONCAT(DISTINCT t.issued_by_username) AS issued_by,
    MAX(t.transfer_date) AS last_date
FROM company_transfers t
INNER JOIN companies fc ON fc.id = t.from_company_id
INNER JOIN companies tc ON tc.id = t.to_company_id
WHERE t.transfer_date >= ? AND t.transfer_date <= ?
SQL;
    $params = [$dateFrom, $dateTo];
    if ($fromCompanyId !== null && $fromCompanyId > 0) {
        $sql .= ' AND t.from_company_id = ?';
        $params[] = $fromCompanyId;
    }
    if ($toCompanyId !== null && $toCompanyId > 0) {
        $sql .= ' AND t.to_company_id = ?';
        $params[] = $toCompanyId;
    }
    if ($school !== null && us_is_valid_school($school)) {
        $sql .= ' AND t.school = ?';
        $params[] = $school;
    }
    if ($itemName !== null && $itemName !== '') {
        $sql .= ' AND t.item_name = ?';
        $params[] = $itemName;
    }
    if ($itemNo !== null && $itemNo !== '') {
        $sql .= ' AND t.item_no = ?';
        $params[] = $itemNo;
    }
    $sql .= <<<'SQL'
 GROUP BY t.item_name, t.item_no, t.size, t.school, t.from_company_id, t.to_company_id
 ORDER BY t.item_name COLLATE NOCASE, t.item_no COLLATE NOCASE, t.size COLLATE NOCASE,
          fc.name COLLATE NOCASE, tc.name COLLATE NOCASE
SQL;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'item_name' => (string) $row['item_name'],
            'item_no' => (string) $row['item_no'],
            'size' => (string) $row['size'],
            'school' => (string) $row['school'],
            'from_company' => (string) $row['from_company'],
            'to_company' => (string) $row['to_company'],
            'qty_issued' => (int) $row['qty_issued'],
            'transfer_count' => (int) $row['transfer_count'],
            'issued_by' => (string) ($row['issued_by'] ?? ''),
            'last_date' => (string) ($row['last_date'] ?? ''),
        ];
    }
    return $rows;
}

/**
 * Line-level transfer history for detail views.
 *
 * @return list<array<string,mixed>>
 */
function us_company_transfer_lines(
    PDO $pdo,
    string $dateFrom,
    string $dateTo,
    ?int $fromCompanyId = null,
    ?int $toCompanyId = null,
    ?string $school = null
): array {
    $sql = <<<'SQL'
SELECT
    t.id,
    t.transfer_date,
    t.created_at,
    t.school,
    t.item_name,
    t.item_no,
    t.size,
    t.quantity,
    t.issued_by_username,
    fc.name AS from_company,
    tc.name AS to_company
FROM company_transfers t
INNER JOIN companies fc ON fc.id = t.from_company_id
INNER JOIN companies tc ON tc.id = t.to_company_id
WHERE t.transfer_date >= ? AND t.transfer_date <= ?
SQL;
    $params = [$dateFrom, $dateTo];
    if ($fromCompanyId !== null && $fromCompanyId > 0) {
        $sql .= ' AND t.from_company_id = ?';
        $params[] = $fromCompanyId;
    }
    if ($toCompanyId !== null && $toCompanyId > 0) {
        $sql .= ' AND t.to_company_id = ?';
        $params[] = $toCompanyId;
    }
    if ($school !== null && us_is_valid_school($school)) {
        $sql .= ' AND t.school = ?';
        $params[] = $school;
    }
    $sql .= ' ORDER BY t.transfer_date DESC, t.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
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

/** @return list<array{id:int,bill_no:string,receipt_date:string,created_at:string,line_count:int,qty_total:int}> */
function us_list_receipts(PDO $pdo, int $companyId, string $school, int $limit = 40): array
{
    $limit = max(1, min(200, $limit));
    $stmt = $pdo->prepare(
        "SELECT r.id, r.bill_no, r.receipt_date, r.created_at,
                COUNT(rl.id) AS line_count,
                COALESCE(SUM(rl.quantity), 0) AS qty_total
         FROM receipts r
         LEFT JOIN receipt_lines rl ON rl.receipt_id = r.id
         WHERE r.company_id = ? AND r.school = ?
         GROUP BY r.id
         ORDER BY r.receipt_date DESC, r.id DESC
         LIMIT {$limit}"
    );
    $stmt->execute([$companyId, $school]);
    return $stmt->fetchAll();
}

function us_get_receipt(PDO $pdo, int $receiptId, int $companyId, string $school): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, company_id, school, bill_no, receipt_date, created_at
         FROM receipts WHERE id = ? AND company_id = ? AND school = ?'
    );
    $stmt->execute([$receiptId, $companyId, $school]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** @return list<array{id:int,sku_id:int,quantity:int,item_name:string,item_no:string,size:string}> */
function us_get_receipt_lines(PDO $pdo, int $receiptId): array
{
    $stmt = $pdo->prepare(
        'SELECT rl.id, rl.sku_id, rl.quantity, s.item_name, s.item_no, s.size
         FROM receipt_lines rl
         INNER JOIN skus s ON s.id = rl.sku_id
         WHERE rl.receipt_id = ?
         ORDER BY s.item_name COLLATE NOCASE, s.item_no COLLATE NOCASE, s.size COLLATE NOCASE'
    );
    $stmt->execute([$receiptId]);
    return $stmt->fetchAll();
}

/**
 * Replace a receipt's bill/date/lines. $lines = list of [sku_id, qty].
 *
 * @param list<array{sku_id:int,qty:int}> $lines
 */
function us_update_receipt(
    PDO $pdo,
    int $companyId,
    string $school,
    int $receiptId,
    string $billNo,
    string $receiptDate,
    array $lines
): void {
    $billNo = trim($billNo);
    if ($billNo === '') {
        throw new RuntimeException('Bill number is required.');
    }
    if ($receiptDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $receiptDate)) {
        throw new RuntimeException('A valid receipt date is required.');
    }
    $receipt = us_get_receipt($pdo, $receiptId, $companyId, $school);
    if (!$receipt) {
        throw new RuntimeException('Stock-in bill not found.');
    }
    if ($lines === []) {
        throw new RuntimeException('Enter quantity for at least one line.');
    }
    foreach ($lines as $line) {
        if ((int) $line['sku_id'] <= 0 || (int) $line['qty'] < 1) {
            throw new RuntimeException('Each line needs an item and quantity ≥ 1.');
        }
        if (!us_get_sku($pdo, (int) $line['sku_id'], $companyId, $school)) {
            throw new RuntimeException('One or more items are not in this company and school.');
        }
    }

    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare(
            'UPDATE receipts SET bill_no = ?, receipt_date = ? WHERE id = ? AND company_id = ? AND school = ?'
        );
        $upd->execute([$billNo, $receiptDate, $receiptId, $companyId, $school]);
        $pdo->prepare('DELETE FROM receipt_lines WHERE receipt_id = ?')->execute([$receiptId]);
        $ins = $pdo->prepare(
            'INSERT INTO receipt_lines (receipt_id, sku_id, quantity) VALUES (?, ?, ?)'
        );
        foreach ($lines as $line) {
            $ins->execute([$receiptId, (int) $line['sku_id'], (int) $line['qty']]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (str_contains($e->getMessage(), 'UNIQUE')) {
            throw new RuntimeException('Bill number already exists for this company and school.');
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function us_delete_receipt(PDO $pdo, int $companyId, string $school, int $receiptId): void
{
    $receipt = us_get_receipt($pdo, $receiptId, $companyId, $school);
    if (!$receipt) {
        throw new RuntimeException('Stock-in bill not found.');
    }
    // Block delete if this receipt was created by a company transfer credit.
    $xfer = $pdo->prepare('SELECT 1 FROM company_transfers WHERE to_receipt_id = ? LIMIT 1');
    $xfer->execute([$receiptId]);
    if ($xfer->fetchColumn()) {
        throw new RuntimeException(
            'This bill came from Issue to company — delete the transfer from the source company instead.'
        );
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM receipt_lines WHERE receipt_id = ?')->execute([$receiptId]);
        $pdo->prepare(
            'DELETE FROM receipts WHERE id = ? AND company_id = ? AND school = ?'
        )->execute([$receiptId, $companyId, $school]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** @return list<array{id:int,issue_date:string,created_at:string,line_count:int,qty_total:int}> */
function us_list_issues(PDO $pdo, int $companyId, string $school, int $limit = 40): array
{
    $limit = max(1, min(200, $limit));
    $stmt = $pdo->prepare(
        "SELECT i.id, i.issue_date, i.created_at,
                COUNT(il.id) AS line_count,
                COALESCE(SUM(il.quantity), 0) AS qty_total
         FROM issues i
         LEFT JOIN issue_lines il ON il.issue_id = i.id
         WHERE i.company_id = ? AND i.school = ?
         GROUP BY i.id
         ORDER BY i.issue_date DESC, i.id DESC
         LIMIT {$limit}"
    );
    $stmt->execute([$companyId, $school]);
    return $stmt->fetchAll();
}

function us_get_issue(PDO $pdo, int $issueId, int $companyId, string $school): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, company_id, school, issue_date, created_at
         FROM issues WHERE id = ? AND company_id = ? AND school = ?'
    );
    $stmt->execute([$issueId, $companyId, $school]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** @return list<array{id:int,sku_id:int,quantity:int,item_name:string,item_no:string,size:string}> */
function us_get_issue_lines(PDO $pdo, int $issueId): array
{
    $stmt = $pdo->prepare(
        'SELECT il.id, il.sku_id, il.quantity, s.item_name, s.item_no, s.size
         FROM issue_lines il
         INNER JOIN skus s ON s.id = il.sku_id
         WHERE il.issue_id = ?
         ORDER BY s.item_name COLLATE NOCASE, s.item_no COLLATE NOCASE, s.size COLLATE NOCASE'
    );
    $stmt->execute([$issueId]);
    return $stmt->fetchAll();
}

/** On-hand available when editing an issue (current on-hand + qty already on this issue for the SKU). */
function us_on_hand_for_issue_edit(PDO $pdo, int $skuId, int $issueId): int
{
    $base = us_on_hand($pdo, $skuId);
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(quantity), 0) FROM issue_lines WHERE issue_id = ? AND sku_id = ?'
    );
    $stmt->execute([$issueId, $skuId]);
    return $base + (int) $stmt->fetchColumn();
}

/**
 * @param list<array{sku_id:int,qty:int}> $lines
 */
function us_update_issue(
    PDO $pdo,
    int $companyId,
    string $school,
    int $issueId,
    string $issueDate,
    array $lines
): void {
    if ($issueDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate)) {
        throw new RuntimeException('A valid issue date is required.');
    }
    $issue = us_get_issue($pdo, $issueId, $companyId, $school);
    if (!$issue) {
        throw new RuntimeException('Stock-out entry not found.');
    }
    $xfer = $pdo->prepare('SELECT 1 FROM company_transfers WHERE from_issue_id = ? LIMIT 1');
    $xfer->execute([$issueId]);
    if ($xfer->fetchColumn()) {
        throw new RuntimeException(
            'This stock-out is from Issue to company — edit/delete that transfer instead.'
        );
    }
    if ($lines === []) {
        throw new RuntimeException('Add at least one issue line.');
    }
    foreach ($lines as $line) {
        $skuId = (int) $line['sku_id'];
        $qty = (int) $line['qty'];
        if ($skuId <= 0 || $qty < 1) {
            throw new RuntimeException('Each line needs an item and quantity ≥ 1.');
        }
        if (!us_get_sku($pdo, $skuId, $companyId, $school)) {
            throw new RuntimeException('One or more items are not in this company and school.');
        }
        $avail = us_on_hand_for_issue_edit($pdo, $skuId, $issueId);
        if ($qty > $avail) {
            $sku = us_get_sku($pdo, $skuId, $companyId, $school) ?: ['item_name' => '?', 'item_no' => '?', 'size' => '?'];
            throw new RuntimeException(
                'Cannot issue ' . $qty . ' of ' . $sku['item_name'] . ' / ' . $sku['item_no'] . ' / ' . $sku['size']
                . ' — only ' . $avail . ' available.'
            );
        }
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE issues SET issue_date = ? WHERE id = ? AND company_id = ? AND school = ?'
        )->execute([$issueDate, $issueId, $companyId, $school]);
        $pdo->prepare('DELETE FROM issue_lines WHERE issue_id = ?')->execute([$issueId]);
        $ins = $pdo->prepare(
            'INSERT INTO issue_lines (issue_id, sku_id, quantity) VALUES (?, ?, ?)'
        );
        foreach ($lines as $line) {
            $ins->execute([$issueId, (int) $line['sku_id'], (int) $line['qty']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function us_delete_issue(PDO $pdo, int $companyId, string $school, int $issueId): void
{
    $issue = us_get_issue($pdo, $issueId, $companyId, $school);
    if (!$issue) {
        throw new RuntimeException('Stock-out entry not found.');
    }
    $xfer = $pdo->prepare('SELECT 1 FROM company_transfers WHERE from_issue_id = ? LIMIT 1');
    $xfer->execute([$issueId]);
    if ($xfer->fetchColumn()) {
        throw new RuntimeException(
            'This stock-out is from Issue to company — cannot delete here.'
        );
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM issue_lines WHERE issue_id = ?')->execute([$issueId]);
        $pdo->prepare(
            'DELETE FROM issues WHERE id = ? AND company_id = ? AND school = ?'
        )->execute([$issueId, $companyId, $school]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function us_get_user(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, username, role, has_power, created_at FROM users WHERE id = ?'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $row['has_power'] = (int) ($row['has_power'] ?? 0);
    return $row;
}

/** @return list<array{id:int,username:string,role:string,has_power:int,created_at:string}> */
function us_list_staff_users(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT id, username, role, has_power, created_at FROM users
         WHERE role = 'staff' ORDER BY username COLLATE NOCASE"
    )->fetchAll();
    foreach ($rows as &$row) {
        $row['has_power'] = (int) ($row['has_power'] ?? 0);
    }
    unset($row);
    return $rows;
}

function us_set_staff_power(PDO $pdo, int $userId, bool $hasPower): void
{
    $user = us_get_user($pdo, $userId);
    if (!$user || ($user['role'] ?? '') !== 'staff') {
        throw new RuntimeException('Staff user not found.');
    }
    $stmt = $pdo->prepare('UPDATE users SET has_power = ? WHERE id = ? AND role = ?');
    $stmt->execute([$hasPower ? 1 : 0, $userId, 'staff']);
}

/** @return list<int> */
function us_user_company_ids(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT company_id FROM user_companies WHERE user_id = ? ORDER BY company_id');
    $stmt->execute([$userId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Companies a user may work in (all for admin; assigned only for staff). */
function us_companies_for_user(PDO $pdo, array $user): array
{
    if (($user['role'] ?? '') === 'admin') {
        return us_list_companies($pdo);
    }
    $ids = us_user_company_ids($pdo, (int) $user['id']);
    if ($ids === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, name, created_at FROM companies WHERE id IN ({$placeholders}) ORDER BY name COLLATE NOCASE"
    );
    $stmt->execute($ids);
    return $stmt->fetchAll();
}

function us_user_can_access_company(PDO $pdo, array $user, int $companyId): bool
{
    if ($companyId <= 0) {
        return false;
    }
    if (($user['role'] ?? '') === 'admin') {
        return us_get_company($pdo, $companyId) !== null;
    }
    $stmt = $pdo->prepare(
        'SELECT 1 FROM user_companies WHERE user_id = ? AND company_id = ? LIMIT 1'
    );
    $stmt->execute([(int) $user['id'], $companyId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * @param list<int> $companyIds
 */
function us_create_staff_user(
    PDO $pdo,
    string $username,
    string $password,
    array $companyIds,
    bool $hasPower = false
): int {
    $username = trim($username);
    if ($username === '') {
        throw new RuntimeException('Username is required.');
    }
    if (strlen($password) < 6) {
        throw new RuntimeException('Password must be at least 6 characters.');
    }
    $companyIds = array_values(array_unique(array_filter(array_map('intval', $companyIds), static fn(int $id): bool => $id > 0)));
    if ($companyIds === []) {
        throw new RuntimeException('Assign the staff user to at least one company.');
    }
    foreach ($companyIds as $cid) {
        if (!us_get_company($pdo, $cid)) {
            throw new RuntimeException('One or more selected companies were not found.');
        }
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, password_hash, role, has_power) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $username,
            password_hash($password, PASSWORD_DEFAULT),
            'staff',
            $hasPower ? 1 : 0,
        ]);
        $userId = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO user_companies (user_id, company_id) VALUES (?, ?)');
        foreach ($companyIds as $cid) {
            $ins->execute([$userId, $cid]);
        }
        $pdo->commit();
        return $userId;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (str_contains($e->getMessage(), 'UNIQUE')) {
            throw new RuntimeException('That username is already taken.');
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * @param list<int> $companyIds
 */
function us_update_staff_assignments(PDO $pdo, int $userId, array $companyIds): void
{
    $user = us_get_user($pdo, $userId);
    if (!$user || ($user['role'] ?? '') !== 'staff') {
        throw new RuntimeException('Staff user not found.');
    }
    $companyIds = array_values(array_unique(array_filter(array_map('intval', $companyIds), static fn(int $id): bool => $id > 0)));
    if ($companyIds === []) {
        throw new RuntimeException('Assign the staff user to at least one company.');
    }
    foreach ($companyIds as $cid) {
        if (!us_get_company($pdo, $cid)) {
            throw new RuntimeException('One or more selected companies were not found.');
        }
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM user_companies WHERE user_id = ?')->execute([$userId]);
        $ins = $pdo->prepare('INSERT INTO user_companies (user_id, company_id) VALUES (?, ?)');
        foreach ($companyIds as $cid) {
            $ins->execute([$userId, $cid]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function us_reset_user_password(PDO $pdo, int $userId, string $newPassword): void
{
    $user = us_get_user($pdo, $userId);
    if (!$user) {
        throw new RuntimeException('User not found.');
    }
    if (strlen($newPassword) < 6) {
        throw new RuntimeException('Password must be at least 6 characters.');
    }
    $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
}

function us_delete_staff_user(PDO $pdo, int $userId): void
{
    $user = us_get_user($pdo, $userId);
    if (!$user || ($user['role'] ?? '') !== 'staff') {
        throw new RuntimeException('Staff user not found.');
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM user_companies WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM users WHERE id = ? AND role = ?')->execute([$userId, 'staff']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
