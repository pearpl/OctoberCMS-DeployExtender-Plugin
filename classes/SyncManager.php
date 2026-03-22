<?php namespace Pear\DeployExtender\Classes;

use File as FileHelper;
use RainLab\Deploy\Classes\ArchiveBuilder;
use RainLab\Deploy\Models\Server;
use Pear\DeployExtender\Models\SyncLog;
use Exception;
use ZipArchive;

/**
 * SyncManager handles bidirectional sync operations between local and remote servers.
 */
class SyncManager
{
    protected Server $server;

    /** @var callable|null */
    protected $outputCallback = null;

    /**
     * Chunk size for file downloads from remote server (2MB).
     */
    const CHUNK_SIZE = 2097152;

    public function __construct(Server $server)
    {
        $this->server = $server;
    }

    /**
     * Set callback for output messages (used by console commands).
     */
    public function setOutputCallback(callable $callback): self
    {
        $this->outputCallback = $callback;
        return $this;
    }

    // ==========================================
    // PUSH Operations (Local → Remote)
    // ==========================================

    /**
     * Push local database to remote server.
     *
     * @param bool $skipUsers Skip backend user tables
     * @return array ['tables' => int, 'backup_path' => string]
     */
    public function pushDatabase(bool $skipUsers = false): array
    {
        $this->output('Backing up remote database...');
        $remoteBackup = $this->backupRemoteDatabase($skipUsers);
        $this->output("Remote backup created: {$remoteBackup}");

        $this->output('Exporting local database...');
        $localDumpPath = storage_path('temp/deployextender-push-' . time() . '.sql');
        $exportResult = DatabaseDumper::export($localDumpPath, $skipUsers);
        $this->output("Exported {$exportResult['tables']} tables ({$this->formatBytes($exportResult['size'])})");

        $this->output('Uploading database to remote server...');
        $uploadResult = $this->server->transmitFile($localDumpPath);
        $remoteSqlPath = base64_decode($uploadResult['path']);

        $this->output('Importing database on remote server...');
        $importResult = $this->transmitCustomScript('db_import', [
            'file' => $uploadResult['path'],
        ]);

        if (($importResult['status'] ?? '') !== 'ok') {
            throw new Exception('Remote database import failed: ' . ($importResult['error'] ?? 'Unknown error'));
        }

        // Cleanup local temp file
        @unlink($localDumpPath);

        // Cleanup remote temp file
        $this->transmitCustomScript('cleanup_file', [
            'file' => $uploadResult['path'],
        ]);

        // Clear remote cache so October picks up new settings
        $this->output('Clearing remote cache...');
        $this->transmitCustomScript('clear_full_cache');

        $this->output("Database push complete: {$exportResult['tables']} tables synced.");

        return [
            'tables' => $exportResult['tables'],
            'backup_path' => $remoteBackup,
        ];
    }

    /**
     * Push local storage directory to remote server.
     *
     * @param string $directory Relative path under storage/ (e.g., 'app/uploads')
     * @return int Number of files synced
     */
    public function pushStorage(string $directory): int
    {
        $sourcePath = storage_path($directory);
        if (!is_dir($sourcePath)) {
            $this->output("Directory does not exist locally: {$directory}. Skipping.");
            return 0;
        }

        $this->output("Building archive of storage/{$directory}...");
        $archivePath = storage_path('temp/deployextender-storage-' . str_replace('/', '-', $directory) . '-' . time() . '.zip');
        FileHelper::makeDirectory(dirname($archivePath), 0755, true, true);

        ArchiveBuilder::instance()->buildArchive($archivePath, [
            'dirsSrc' => ['storage/' . $directory => $sourcePath],
        ]);

        $fileCount = $this->countFilesInDir($sourcePath);
        $this->output("Archive built: {$this->formatBytes(filesize($archivePath))} ({$fileCount} files)");

        $this->output("Uploading storage/{$directory} to remote...");
        $uploadResult = $this->server->transmitFile($archivePath);

        $this->output("Extracting on remote server...");
        $this->server->transmitScript('extract_archive', [
            'files' => [
                $archivePath => base64_decode($uploadResult['path']),
            ],
        ]);

        // Cleanup local temp file
        @unlink($archivePath);

        // Small delay to avoid nonce collision with extract_archive call above
        usleep(150000);

        // Cleanup remote temp file
        $this->transmitCustomScript('cleanup_file', [
            'file' => $uploadResult['path'],
        ]);

        $this->output("Storage push complete: storage/{$directory}");

        return $fileCount;
    }

    // ==========================================
    // PULL Operations (Remote → Local)
    // ==========================================

    /**
     * Pull remote database to local server.
     *
     * @param bool $skipUsers Skip backend user tables
     * @return array ['tables' => int, 'backup_path' => string]
     */
    public function pullDatabase(bool $skipUsers = false): array
    {
        $this->output('Backing up local database...');
        $localBackup = $this->backupLocalDatabase($skipUsers);
        $this->output("Local backup created: {$localBackup}");

        $this->output('Exporting remote database...');
        $excludeTables = DatabaseDumper::ALWAYS_EXCLUDE;
        if ($skipUsers) {
            $excludeTables = array_merge($excludeTables, DatabaseDumper::USER_TABLES);
        }

        $exportResult = $this->transmitCustomScript('db_export', [
            'exclude_tables' => array_values($excludeTables),
        ]);

        if (($exportResult['status'] ?? '') !== 'ok') {
            throw new Exception('Remote database export failed: ' . ($exportResult['error'] ?? 'Unknown error'));
        }

        $tableCount = count($exportResult['tables'] ?? []);
        $remoteFileSize = $exportResult['size'] ?? 0;
        $this->output("Remote export: {$tableCount} tables ({$this->formatBytes($remoteFileSize)})");

        $this->output('Downloading database dump...');
        $localSqlPath = storage_path('temp/deployextender-pull-' . time() . '.sql');
        $this->downloadRemoteFile($exportResult['file'], $localSqlPath, $remoteFileSize);

        $this->output('Importing database locally...');
        DatabaseDumper::import($localSqlPath);

        // Cleanup local temp file
        @unlink($localSqlPath);

        // Cleanup remote temp file
        $this->transmitCustomScript('cleanup_file', [
            'file' => $exportResult['file'],
        ]);

        // Clear local cache so October picks up new settings
        $this->output('Clearing local cache...');
        $this->clearLocalCache();

        $this->output("Database pull complete: {$tableCount} tables synced.");

        return [
            'tables' => $tableCount,
            'backup_path' => $localBackup,
        ];
    }

    /**
     * Pull remote storage directory to local server.
     *
     * @param string $directory Relative path under storage/ (e.g., 'app/uploads')
     * @return int Number of files synced
     */
    public function pullStorage(string $directory): int
    {
        $this->output("Building archive on remote: storage/{$directory}...");

        $archiveResult = $this->transmitCustomScript('build_storage_archive', [
            'directory' => $directory,
        ]);

        if (($archiveResult['status'] ?? '') !== 'ok') {
            $error = $archiveResult['error'] ?? 'Unknown error';
            if (str_contains($error, 'does not exist')) {
                $this->output("Directory does not exist on remote: storage/{$directory}. Skipping.");
                return 0;
            }
            throw new Exception("Failed to build remote archive: {$error}");
        }

        // Empty directory — no files to sync
        if (empty($archiveResult['file']) || ($archiveResult['files'] ?? 0) === 0) {
            $this->output("No files found in remote storage/{$directory}. Skipping.");
            return 0;
        }

        $remoteFileSize = $archiveResult['size'] ?? 0;
        $this->output("Remote archive built: {$this->formatBytes($remoteFileSize)}");

        $this->output("Downloading storage/{$directory} from remote...");
        $localArchivePath = storage_path('temp/deployextender-pull-storage-' . str_replace('/', '-', $directory) . '-' . time() . '.zip');
        $this->downloadRemoteFile($archiveResult['file'], $localArchivePath, $remoteFileSize);

        $this->output("Extracting to local storage/{$directory}...");
        $fileCount = $this->extractArchive($localArchivePath, base_path());

        // Cleanup
        @unlink($localArchivePath);
        $this->transmitCustomScript('cleanup_file', [
            'file' => $archiveResult['file'],
        ]);

        $this->output("Storage pull complete: storage/{$directory}");

        return $fileCount;
    }

    // ==========================================
    // Backup Operations
    // ==========================================

    /**
     * Create a backup of the local database.
     *
     * @return string Path to backup file
     */
    public function backupLocalDatabase(bool $skipUsers = false): string
    {
        $backupDir = storage_path('app/deploy-backups');
        FileHelper::makeDirectory($backupDir, 0755, true, true);

        $backupPath = $backupDir . '/local-backup-' . date('Y-m-d_His') . '.sql';
        DatabaseDumper::export($backupPath, $skipUsers);

        return $backupPath;
    }

    /**
     * Create a backup of the remote database.
     *
     * @return string Remote path to backup file
     */
    public function backupRemoteDatabase(bool $skipUsers = false): string
    {
        $excludeTables = DatabaseDumper::ALWAYS_EXCLUDE;
        if ($skipUsers) {
            $excludeTables = array_merge($excludeTables, DatabaseDumper::USER_TABLES);
        }

        $result = $this->transmitCustomScript('db_backup', [
            'exclude_tables' => array_values($excludeTables),
        ]);

        if (($result['status'] ?? '') !== 'ok') {
            throw new Exception('Remote database backup failed: ' . ($result['error'] ?? 'Unknown error'));
        }

        return base64_decode($result['file']);
    }

    // ==========================================
    // Helper Methods
    // ==========================================

    /**
     * Clear local October CMS cache after database changes.
     */
    protected function clearLocalCache(): void
    {
        $cachePaths = [
            storage_path('framework/cache'),
            storage_path('cms/cache'),
            storage_path('cms/twig'),
            storage_path('cms/combiner'),
        ];

        foreach ($cachePaths as $path) {
            if (is_dir($path)) {
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($files as $file) {
                    if ($file->isDir()) {
                        @rmdir($file->getRealPath());
                    } elseif ($file->getFilename() !== '.gitignore') {
                        @unlink($file->getRealPath());
                    }
                }
            }
        }

        // Clear data cache from framework/cache/data
        $dataCachePath = storage_path('framework/cache/data');
        if (is_dir($dataCachePath)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dataCachePath, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getRealPath());
                } else {
                    @unlink($file->getRealPath());
                }
            }
        }
    }

    /**
     * Verify the remote beacon is reachable before starting operations.
     *
     * @throws Exception if beacon is not reachable
     */
    public function verifyBeaconConnectivity(): array
    {
        try {
            return $this->server->transmit('healthCheck');
        } catch (Exception $e) {
            throw new Exception(
                'Cannot reach the remote beacon. '
                . 'Please verify the server endpoint URL and that the beacon file is deployed. '
                . 'Original error: ' . $e->getMessage()
            );
        }
    }

    /**
     * Transmit a custom beacon script from this plugin's directory.
     * Automatically retries on nonce collisions (HTTP 200) with a short delay.
     */
    public function transmitCustomScript(string $scriptName, array $vars = []): array
    {
        $scriptPath = plugins_path('pear/deployextender/beacon/scripts/' . $scriptName . '.txt');

        if (!file_exists($scriptPath)) {
            throw new Exception("Beacon script not found: {$scriptName}");
        }

        $scriptContents = file_get_contents($scriptPath);
        $encodedScript = base64_encode($scriptContents);
        $maxRetries = 3;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return $this->server->transmit('evalScript', [
                    'script' => $encodedScript,
                    'scriptVars' => $vars,
                ]);
            } catch (Exception $e) {
                $isNonceCollision = str_contains($e->getMessage(), 'Code: 200');

                if ($isNonceCollision && $attempt < $maxRetries) {
                    // Nonce collision — beacon nonce resolution is ~100ms,
                    // wait 150ms before retry to ensure a new nonce value
                    usleep(150000);
                    $this->output("Beacon nonce collision on '{$scriptName}', retrying ({$attempt}/{$maxRetries})...");
                    continue;
                }

                if ($isNonceCollision) {
                    throw new Exception(
                        "Beacon returned HTTP 200 while executing '{$scriptName}' after {$maxRetries} retries. "
                        . 'This may indicate the beacon is not deployed or the endpoint URL is incorrect.'
                    );
                }

                throw $e;
            }
        }

        throw new Exception("Failed to execute beacon script '{$scriptName}' after {$maxRetries} attempts.");
    }

    /**
     * Download a file from the remote server in chunks (public accessor).
     */
    public function downloadRemoteFilePublic(string $remoteFileBase64, string $localPath, int $totalSize = 0): void
    {
        $this->downloadRemoteFile($remoteFileBase64, $localPath, $totalSize);
    }

    /**
     * Download a file from the remote server in chunks.
     */
    protected function downloadRemoteFile(string $remoteFileBase64, string $localPath, int $totalSize = 0): void
    {
        FileHelper::makeDirectory(dirname($localPath), 0755, true, true);
        $fp = fopen($localPath, 'wb');

        if ($fp === false) {
            throw new Exception("Cannot open file for writing: {$localPath}");
        }

        $offset = 0;
        $chunkNumber = 0;

        do {
            $result = $this->transmitCustomScript('get_file_chunk', [
                'file'   => $remoteFileBase64,
                'offset' => $offset,
                'length' => self::CHUNK_SIZE,
            ]);

            if (($result['status'] ?? '') !== 'ok') {
                fclose($fp);
                @unlink($localPath);
                throw new Exception('Failed to download chunk: ' . ($result['error'] ?? 'Unknown error'));
            }

            $chunkData = base64_decode($result['data']);
            fwrite($fp, $chunkData);

            $offset += strlen($chunkData);
            $remaining = $result['remaining'] ?? 0;
            $chunkNumber++;

            if ($totalSize > 0 && $this->outputCallback) {
                $progress = min(100, round(($offset / $totalSize) * 100));
                $this->output("  Downloaded: {$this->formatBytes($offset)} / {$this->formatBytes($totalSize)} ({$progress}%)");
            }
        } while ($remaining > 0);

        fclose($fp);
    }

    /**
     * Extract a ZIP archive to a destination directory.
     */
    protected function extractArchive(string $archivePath, string $destination): int
    {
        $zip = new ZipArchive();
        $result = $zip->open($archivePath);

        if ($result !== true) {
            throw new Exception("Failed to open archive: {$archivePath}");
        }

        $fileCount = $zip->numFiles;
        $zip->extractTo($destination);
        $zip->close();

        return $fileCount;
    }

    /**
     * Count files in a directory recursively.
     */
    protected function countFilesInDir(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }

        return $count;
    }

    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 2) . ' ' . $units[$i];
    }

    protected function output(string $message): void
    {
        if ($this->outputCallback) {
            call_user_func($this->outputCallback, $message);
        }
    }
}
