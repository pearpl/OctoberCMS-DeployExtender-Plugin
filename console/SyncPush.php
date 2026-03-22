<?php namespace Pear\DeployExtender\Console;

/**
 * Deploy Extender Plugin for October CMS
 *
 * @author     Pear Interactive <hello@pear.pl>
 * @link       https://github.com/pearpl/OctoberCMS-DeployExtender-Plugin
 * @license    proprietary
 */

use Illuminate\Console\Command;
use RainLab\Deploy\Models\Server;
use Pear\DeployExtender\Classes\SyncManager;
use Pear\DeployExtender\Models\SyncLog;
use Exception;

class SyncPush extends Command
{
    protected $signature = 'deployextender:sync-push
        {server : Server name or ID}
        {--skip-users : Skip backend user tables}
        {--no-db : Skip database sync}
        {--no-media : Skip storage/app/media sync}
        {--no-uploads : Skip storage/app/uploads sync}
        {--f|force : Skip confirmation prompt}';

    protected $description = 'Push local database and storage files to a remote server';

    public function handle()
    {
        $server = $this->resolveServer($this->argument('server'));

        if (!$server) {
            $this->error('Server not found.');
            return 1;
        }

        $skipUsers = $this->option('skip-users');
        $syncDb = !$this->option('no-db');
        $syncMedia = !$this->option('no-media');
        $syncUploads = !$this->option('no-uploads');

        // Show sync summary
        $this->info('=== SYNC PUSH: Local → Remote ===');
        $this->info("Server: {$server->server_name} ({$server->endpoint_url})");
        $this->line('');
        $this->info('Operations:');

        if ($syncDb) {
            $this->line('  • Database sync (push local → remote)');
            if ($skipUsers) {
                $this->line('    - Backend user tables will be SKIPPED');
            }
            $this->line('    - Deploy tables, logs, sessions, cache always skipped');
        }
        if ($syncMedia) {
            $this->line('  • Storage: storage/app/media');
        }
        if ($syncUploads) {
            $this->line('  • Storage: storage/app/uploads');
        }

        if (!$syncDb && !$syncMedia && !$syncUploads) {
            $this->warn('Nothing to sync. Remove --no-db, --no-media, or --no-uploads flags.');
            return 0;
        }

        // Show last sync info
        $lastSync = SyncLog::latestForServer($server->id)
            ->where('direction', 'push')
            ->first();

        if ($lastSync) {
            $this->line('');
            $this->info("Last push sync: {$lastSync->completed_at->format('Y-m-d H:i:s')}");
        }

        $this->line('');
        $this->warn('⚠ WARNING: This will OVERWRITE data on the remote server!');
        $this->warn('  A backup of the remote database will be created before sync.');

        if (!$this->option('force') && !$this->confirm('Are you sure you want to proceed?')) {
            $this->info('Sync cancelled.');
            return 0;
        }

        // Start sync
        $syncLog = SyncLog::start(
            $server->id,
            $server->server_name,
            'push',
            $this->determineSyncType($syncDb, $syncMedia, $syncUploads),
            $skipUsers
        );

        $manager = new SyncManager($server);
        $manager->setOutputCallback(fn($msg) => $this->line($msg));

        $totalTables = 0;
        $totalFiles = 0;
        $backupPath = null;

        try {
            if ($syncDb) {
                $this->line('');
                $this->info('--- Database Sync ---');
                $dbResult = $manager->pushDatabase($skipUsers);
                $totalTables = $dbResult['tables'];
                $backupPath = $dbResult['backup_path'];
            }

            if ($syncMedia) {
                $this->line('');
                $this->info('--- Media Sync ---');
                $totalFiles += $manager->pushStorage('app/media');
            }

            if ($syncUploads) {
                $this->line('');
                $this->info('--- Uploads Sync ---');
                $totalFiles += $manager->pushStorage('app/uploads');
            }

            $syncLog->markSuccess($totalTables, $totalFiles, $backupPath);

            $this->line('');
            $this->info('✓ Sync push completed successfully!');
            $this->line("  Tables synced: {$totalTables}");
            $this->line("  Files synced: {$totalFiles}");
            if ($backupPath) {
                $this->line("  Remote backup: {$backupPath}");
            }

            return 0;

        } catch (Exception $e) {
            $syncLog->markError($e->getMessage());
            $this->error("Sync failed: {$e->getMessage()}");
            return 1;
        }
    }

    protected function resolveServer(string $identifier): ?Server
    {
        return Server::where('id', $identifier)
            ->orWhere('server_name', $identifier)
            ->first();
    }

    protected function determineSyncType(bool $db, bool $media, bool $uploads): string
    {
        if ($db && ($media || $uploads)) {
            return 'full';
        }

        if ($db) {
            return 'database';
        }

        if ($uploads) {
            return 'uploads';
        }

        return 'media';
    }
}
