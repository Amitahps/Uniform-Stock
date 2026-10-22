<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Paths under the app root to exclude from a source download.
 *
 * @return list<string> relative path prefixes / names
 */
function us_backup_source_excludes(): array
{
    return [
        '.git',
        'data/stock.db',
        'data/stock.db-wal',
        'data/stock.db-shm',
        'data/stock.db-journal',
    ];
}

function us_backup_should_exclude(string $relPath): bool
{
    $relPath = str_replace('\\', '/', $relPath);
    if ($relPath === '' || $relPath === '.') {
        return true;
    }
    foreach (us_backup_source_excludes() as $ex) {
        if ($relPath === $ex || str_starts_with($relPath, rtrim($ex, '/') . '/')) {
            return true;
        }
        // Also skip any *.db / wal / shm under data/
        if (preg_match('#^data/.*\.(db|sqlite)(-wal|-shm|-journal)?$#i', $relPath)) {
            return true;
        }
    }
    // Skip hidden editor junk at root of walk
    $base = basename($relPath);
    if ($base === '.DS_Store' || $base === 'Thumbs.db') {
        return true;
    }
    return false;
}

/**
 * @return list<string> relative file paths under app root
 */
function us_backup_list_source_files(string $root): array
{
    $root = rtrim($root, '/');
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        /** @var SplFileInfo $fileInfo */
        if (!$fileInfo->isFile()) {
            continue;
        }
        $full = $fileInfo->getPathname();
        $rel = substr($full, strlen($root) + 1);
        $rel = str_replace('\\', '/', $rel);
        if (us_backup_should_exclude($rel)) {
            continue;
        }
        $files[] = $rel;
    }
    sort($files, SORT_STRING);
    return $files;
}

/**
 * Build a downloadable archive of app source (no SQLite DBs).
 * Prefers ZipArchive, then system zip, then .tar.gz via PharData.
 *
 * @return array{path:string,filename:string,mime:string}
 */
function us_backup_build_source_archive(): array
{
    $root = dirname(__DIR__);
    $stamp = date('Ymd-Hi');
    $baseName = 'uniform-stock-source-' . $stamp;
    $tmpDir = sys_get_temp_dir();
    $files = us_backup_list_source_files($root);
    if ($files === []) {
        throw new RuntimeException('No source files found to archive.');
    }

    if (class_exists('ZipArchive')) {
        $zipPath = $tmpDir . '/' . $baseName . '.zip';
        @unlink($zipPath);
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create ZIP archive.');
        }
        foreach ($files as $rel) {
            $zip->addFile($root . '/' . $rel, $baseName . '/' . $rel);
        }
        $zip->close();
        return [
            'path' => $zipPath,
            'filename' => $baseName . '.zip',
            'mime' => 'application/zip',
        ];
    }

    $zipBin = trim((string) shell_exec('command -v zip 2>/dev/null'));
    if ($zipBin !== '') {
        $zipPath = $tmpDir . '/' . $baseName . '.zip';
        @unlink($zipPath);
        $staging = $tmpDir . '/us-src-' . bin2hex(random_bytes(4));
        mkdir($staging . '/' . $baseName, 0700, true);
        foreach ($files as $rel) {
            $dest = $staging . '/' . $baseName . '/' . $rel;
            $destDir = dirname($dest);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0700, true);
            }
            if (!copy($root . '/' . $rel, $dest)) {
                throw new RuntimeException('Could not stage file for ZIP: ' . $rel);
            }
        }
        $cmd = 'cd ' . escapeshellarg($staging)
            . ' && ' . escapeshellarg($zipBin) . ' -qr ' . escapeshellarg($zipPath) . ' ' . escapeshellarg($baseName);
        exec($cmd, $out, $code);
        // cleanup staging
        $rm = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($staging, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($rm as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($staging);
        if ($code !== 0 || !is_file($zipPath)) {
            throw new RuntimeException('System zip failed (exit ' . $code . ').');
        }
        return [
            'path' => $zipPath,
            'filename' => $baseName . '.zip',
            'mime' => 'application/zip',
        ];
    }

    // Last resort: tar.gz via PharData
    $tarPath = $tmpDir . '/' . $baseName . '.tar';
    $gzPath = $tarPath . '.gz';
    @unlink($tarPath);
    @unlink($gzPath);
    $phar = new PharData($tarPath);
    foreach ($files as $rel) {
        $phar->addFile($root . '/' . $rel, $baseName . '/' . $rel);
    }
    $phar->compress(Phar::GZ);
    unset($phar);
    @unlink($tarPath);
    if (!is_file($gzPath)) {
        throw new RuntimeException('Could not create tar.gz source archive.');
    }
    return [
        'path' => $gzPath,
        'filename' => $baseName . '.tar.gz',
        'mime' => 'application/gzip',
    ];
}

/**
 * Consistent SQLite snapshot for download.
 *
 * @return array{path:string,filename:string,mime:string}
 */
function us_backup_build_data_file(): array
{
    $pdo = us_db();
    // Flush WAL so the main file is consistent before copy / VACUUM INTO.
    try {
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    } catch (Throwable $e) {
        // Ignore if WAL not in use.
    }

    $stamp = date('Ymd-Hi');
    $filename = 'uniform-stock-backup-' . $stamp . '.db';
    $tmp = sys_get_temp_dir() . '/' . $filename;
    @unlink($tmp);

    try {
        // SQLite 3.27+ — safest consistent copy while DB may be open.
        $pdo->exec('VACUUM INTO ' . $pdo->quote($tmp));
    } catch (Throwable $e) {
        $src = US_DB_PATH;
        if (!is_file($src)) {
            throw new RuntimeException('Database file not found yet. Use the app once to create it.');
        }
        if (!copy($src, $tmp)) {
            throw new RuntimeException('Could not copy database for backup: ' . $e->getMessage());
        }
    }

    if (!is_file($tmp) || filesize($tmp) === 0) {
        throw new RuntimeException('Backup file is empty or missing.');
    }

    return [
        'path' => $tmp,
        'filename' => $filename,
        'mime' => 'application/octet-stream',
    ];
}

/**
 * Stream a temp file as attachment, then delete it.
 */
function us_backup_send_file(string $path, string $filename, string $mime): void
{
    if (!is_file($path)) {
        throw new RuntimeException('Download file missing.');
    }
    $size = filesize($path);
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    header('Content-Length: ' . (string) $size);
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    // Clear any prior output buffers.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    readfile($path);
    @unlink($path);
    exit;
}
