<?php namespace Pear\DeployExtender\Classes;

/**
 * Deploy Extender Plugin for October CMS
 *
 * @author     Pear Interactive <hello@pear.pl>
 * @link       https://github.com/pearpl/OctoberCMS-DeployExtender-Plugin
 * @license    MIT
 */

use File as FileHelper;
use RainLab\Deploy\Classes\ArchiveBuilder;
use RainLab\Deploy\Models\Server;
use Pear\DeployExtender\Models\SyncLog;
use Exception;
use ZipArchive;

class SyncManager
{
    protected Server $server;

    protected $outputCallback = null;

    const CHUNK_SIZE = 2097152;
    const BATCH_MAX_FILES = 200;

    public function __construct(Server $server)
    {
        $this->server = $server;
    }

    public function setOutputCallback(callable $callback): self
    {
        $this->outputCallback = $callback;
        return $this;
    }

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
        $uploadResult = $this->transmitWithRetry(function () use ($localDumpPath) {
            return $this->server->transmitFile($localDumpPath);
        }, 'transmitFile');
        $remoteSqlPath = base64_decode($uploadResult['path']);

        $this->output('Importing database on remote server...');
        $importResult = $this->transmitCustomScript('db_import', [
            'file' => $uploadResult['path'],
        ]);

        if (($importResult['status'] ?? '') !== 'ok') {
            throw new Exception('Remote database import failed: ' . ($importResult['error'] ?? 'Unknown error'));
        }

        @unlink($localDumpPath);

        $this->transmitCustomScript('cleanup_file', [
            'file' => $uploadResult['path'],
        ]);

        $this->output('Clearing remote cache...');
        $this->transmitCustomScript('clear_full_cache');

        $this->output("Database push complete: {$exportResult['tables']} tables synced.");

        return [
            'tables' => $exportResult['tables'],
            'backup_path' => $remoteBackup,
        ];
    }

    public function pushStorage(string $directory): int
    {
        $info = $this->getLocalStorageInfo($directory);
        if ($info['total_count'] === 0) {
            $this->output("No files found in local storage/{$directory}. Skipping.");
            return 0;
        }

        $this->output("Found {$info['total_count']} files ({$this->formatBytes($info['total_size'])}) in storage/{$directory}");

        $totalBatches = (int) ceil($info['total_count'] / self::BATCH_MAX_FILES);
        $totalSynced = 0;

        for ($batch = 0; $batch < $totalBatches; $batch++) {
            $offset = $batch * self::BATCH_MAX_FILES;
            $this->output("Processing batch " . ($batch + 1) . "/{$totalBatches}...");
            $synced = $this->pushStorageBatch($directory, $offset, self::BATCH_MAX_FILES);
            $totalSynced += $synced;
        }

        $this->output("Storage push complete: storage/{$directory} ({$totalSynced} files)");
        return $totalSynced;
    }

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

        @unlink($localSqlPath);

        $this->transmitCustomScript('cleanup_file', [
            'file' => $exportResult['file'],
        ]);

        $this->output('Clearing local cache...');
        $this->clearLocalCache();

        $this->output("Database pull complete: {$tableCount} tables synced.");

        return [
            'tables' => $tableCount,
            'backup_path' => $localBackup,
        ];
    }

    public function pullStorage(string $directory): int
    {
        $info = $this->getRemoteStorageInfo($directory);
        if ($info['total_count'] === 0) {
            $this->output("No files found in remote storage/{$directory}. Skipping.");
            return 0;
        }

        $this->output("Found {$info['total_count']} files ({$this->formatBytes($info['total_size'])}) on remote storage/{$directory}");

        $totalBatches = (int) ceil($info['total_count'] / self::BATCH_MAX_FILES);
        $totalSynced = 0;

        for ($batch = 0; $batch < $totalBatches; $batch++) {
            $offset = $batch * self::BATCH_MAX_FILES;
            $this->output("Processing batch " . ($batch + 1) . "/{$totalBatches}...");
            $synced = $this->pullStorageBatch($directory, $offset, self::BATCH_MAX_FILES);
            $totalSynced += $synced;
        }

        $this->output("Storage pull complete: storage/{$directory} ({$totalSynced} files)");
        return $totalSynced;
    }

    public function backupLocalDatabase(bool $skipUsers = false): string
    {
        $backupDir = storage_path('app/deploy-backups');
        FileHelper::makeDirectory($backupDir, 0755, true, true);

        $backupPath = $backupDir . '/local-backup-' . date('Y-m-d_His') . '.sql';
        DatabaseDumper::export($backupPath, $skipUsers);

        return $backupPath;
    }

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

    protected function transmitWithRetry(callable $callback, string $label = 'transmit', int $maxRetries = 3)
    {
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return $callback();
            } catch (Exception $e) {
                $isNonceCollision = str_contains($e->getMessage(), 'Code: 200');

                if ($isNonceCollision && $attempt < $maxRetries) {
                    usleep(200000);
                    $this->output("Nonce collision on '{$label}', retrying ({$attempt}/{$maxRetries})...");
                    continue;
                }

                throw $e;
            }
        }
    }

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

    public function downloadRemoteFilePublic(string $remoteFileBase64, string $localPath, int $totalSize = 0): void
    {
        $this->downloadRemoteFile($remoteFileBase64, $localPath, $totalSize);
    }

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

    public function getLocalStorageInfo(string $directory): array
    {
        $sourcePath = storage_path($directory);
        if (!is_dir($sourcePath)) {
            return ['total_count' => 0, 'total_size' => 0];
        }

        $count = 0;
        $totalSize = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourcePath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $count++;
                $totalSize += $file->getSize();
            }
        }

        return ['total_count' => $count, 'total_size' => $totalSize];
    }

    public function getRemoteStorageInfo(string $directory): array
    {
        $result = $this->transmitCustomScript('list_storage_files', [
            'directory' => $directory,
        ]);

        if (($result['status'] ?? '') !== 'ok') {
            if (str_contains($result['error'] ?? '', 'does not exist')) {
                return ['total_count' => 0, 'total_size' => 0];
            }
            throw new Exception('Failed to list remote files: ' . ($result['error'] ?? 'Unknown error'));
        }

        return [
            'total_count' => $result['total_count'] ?? 0,
            'total_size'  => $result['total_size'] ?? 0,
        ];
    }

    public function pushStorageBatch(string $directory, int $offset, int $limit): int
    {
        $sourcePath = storage_path($directory);
        $files = $this->getLocalFileBatch($directory, $offset, $limit);
        if (empty($files)) return 0;

        $archivePath = storage_path('temp/deployextender-batch-' . time() . '-' . mt_rand(1000, 9999) . '.zip');
        FileHelper::makeDirectory(dirname($archivePath), 0755, true, true);

        $zip = new ZipArchive();
        $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $count = 0;
        foreach ($files as $relativePath) {
            $fullPath = $sourcePath . '/' . $relativePath;
            if (file_exists($fullPath)) {
                $zip->addFile($fullPath, 'storage/' . $directory . '/' . $relativePath);
                $count++;
            }
        }

        if ($count === 0) {
            $zip->close();
            @unlink($archivePath);
            return 0;
        }

        $zip->close();

        $this->output("Uploading batch ({$count} files, {$this->formatBytes(filesize($archivePath))})...");

        $uploadResult = $this->transmitWithRetry(function () use ($archivePath) {
            return $this->server->transmitFile($archivePath);
        }, 'transmitFile');

        $this->transmitWithRetry(function () use ($archivePath, $uploadResult) {
            return $this->server->transmitScript('extract_archive', [
                'files' => [$archivePath => base64_decode($uploadResult['path'])],
            ]);
        }, 'extract_archive');

        @unlink($archivePath);
        usleep(100000);

        $this->transmitCustomScript('cleanup_file', ['file' => $uploadResult['path']]);

        return $count;
    }

    public function pullStorageBatch(string $directory, int $offset, int $limit): int
    {
        $archiveResult = $this->transmitCustomScript('build_storage_batch', [
            'directory' => $directory,
            'offset'    => $offset,
            'limit'     => $limit,
        ]);

        if (($archiveResult['status'] ?? '') !== 'ok') {
            throw new Exception('Failed to build remote batch: ' . ($archiveResult['error'] ?? 'Unknown error'));
        }

        if (empty($archiveResult['file']) || ($archiveResult['files'] ?? 0) === 0) {
            return 0;
        }

        $this->output("Downloading batch ({$archiveResult['files']} files, {$this->formatBytes($archiveResult['size'] ?? 0)})...");

        $localPath = storage_path('temp/deployextender-pull-batch-' . time() . '-' . mt_rand(1000, 9999) . '.zip');
        $this->downloadRemoteFile($archiveResult['file'], $localPath, $archiveResult['size'] ?? 0);

        $this->extractArchive($localPath, base_path());

        @unlink($localPath);
        $this->transmitCustomScript('cleanup_file', ['file' => $archiveResult['file']]);

        return $archiveResult['files'] ?? 0;
    }

    protected function getLocalFileBatch(string $directory, int $offset, int $limit): array
    {
        $sourcePath = storage_path($directory);
        if (!is_dir($sourcePath)) return [];

        $allFiles = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourcePath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $allFiles[] = substr($file->getPathname(), strlen($sourcePath) + 1);
            }
        }

        sort($allFiles);
        return array_slice($allFiles, $offset, $limit);
    }

    protected function extractArchive(string $archivePath, string $destination): int
    {
        $zip = new ZipArchive();
        $result = $zip->open($archivePath);

        if ($result !== true) {
            throw new Exception("Failed to open archive: {$archivePath}");
        }

        $fileCount = $zip->numFiles;

        for ($i = 0; $i < $fileCount; $i++) {
            $entryName = $zip->getNameIndex($i);
            if ($entryName === false) continue;

            $targetPath = $destination . '/' . $entryName;

            if (substr($entryName, -1) === '/') {
                FileHelper::makeDirectory($targetPath, 0755, true, true);
                continue;
            }

            FileHelper::makeDirectory(dirname($targetPath), 0755, true, true);

            if (file_exists($targetPath)) {
                @chmod($targetPath, 0644);
                @unlink($targetPath);
            }

            $stream = $zip->getStream($entryName);
            if ($stream === false) continue;

            $fp = fopen($targetPath, 'wb');
            if ($fp === false) {
                fclose($stream);
                continue;
            }

            stream_copy_to_stream($stream, $fp);
            fclose($fp);
            fclose($stream);
        }

        $zip->close();

        return $fileCount;
    }

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
