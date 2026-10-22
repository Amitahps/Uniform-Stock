<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/company.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/backup.php';

$user = us_require_login();
$companyId = us_current_company_id();
$company = $companyId ? us_get_company(us_db(), $companyId) : null;
$school = us_current_school();
$error = '';

$download = (string) ($_GET['download'] ?? '');
if ($download === 'source' || $download === 'data') {
    try {
        if ($download === 'source') {
            $file = us_backup_build_source_archive();
        } else {
            $file = us_backup_build_data_file();
        }
        us_backup_send_file($file['path'], $file['filename'], $file['mime']);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$dbPath = US_DB_PATH;
$dbExists = is_file($dbPath);
$dbSize = $dbExists ? (int) filesize($dbPath) : 0;
$dbMtime = $dbExists ? date('Y-m-d H:i', (int) filemtime($dbPath)) : '';

us_header('Backup', $user, $company, $school);
?>
<p class="lead">
  Download a copy of the <strong>source code</strong> or a <strong>data backup</strong> (SQLite) to save on your laptop.
  Both require login. The <code>data/</code> folder is blocked from public URLs — use these buttons only.
</p>
<?php if ($error !== ''): ?>
  <div class="flash flash-error"><?= us_h($error) ?></div>
<?php endif; ?>

<section class="grid grid-2" style="margin-top:0.5rem">
  <div style="padding:1.1rem;border:1px solid var(--line);border-radius:12px;background:#fff">
    <h2 style="font-size:1.15rem;margin:0 0 0.5rem">Source code</h2>
    <p class="muted" style="margin:0 0 1rem">
      ZIP of this site’s PHP, assets, and config (excludes <code>data/*.db</code> and <code>.git</code>).
      Generated on the server so it works from Hostinger without GitHub.
    </p>
    <div class="actions" style="margin:0">
      <a class="btn" href="<?= us_h(us_url('backup.php?download=source')) ?>">Download source code</a>
    </div>
    <p class="muted" style="margin:0.9rem 0 0;font-size:0.9rem">
      Optional (GitHub):
      <a href="https://github.com/Amitahps/Uniform-Stock/archive/refs/heads/main.zip" rel="noopener" target="_blank">
        main.zip from GitHub
      </a>
    </p>
  </div>

  <div style="padding:1.1rem;border:1px solid var(--line);border-radius:12px;background:#fff">
    <h2 style="font-size:1.15rem;margin:0 0 0.5rem">Data backup</h2>
    <p class="muted" style="margin:0 0 1rem">
      Download a timestamped copy of the SQLite database
      (<code>uniform-stock-backup-YYYYMMDD-HHMM.db</code>). Admin login required.
    </p>
    <?php if ($dbExists): ?>
      <p style="margin:0 0 1rem;font-size:0.95rem">
        Current DB: <strong><?= number_format($dbSize / 1024, 1) ?> KB</strong>
        · updated <?= us_h($dbMtime) ?>
      </p>
      <div class="actions" style="margin:0">
        <a class="btn" href="<?= us_h(us_url('backup.php?download=data')) ?>">Download data backup</a>
      </div>
    <?php else: ?>
      <div class="flash flash-warn" style="margin:0">
        No database file yet. Use the app once (login / companies) so <code>data/stock.db</code> is created, then return here.
      </div>
    <?php endif; ?>
  </div>
</section>

<p class="muted" style="margin-top:1.5rem;font-size:0.9rem">
  Tip: keep source and data backups together. To restore data later, replace <code>data/stock.db</code> on the server
  (and remove any <code>stock.db-wal</code> / <code>stock.db-shm</code>), then reload <code>/check.php</code>.
</p>
<?php us_footer(); ?>
