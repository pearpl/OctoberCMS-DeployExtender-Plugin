<?php namespace Pear\DeployExtender\Console;

use Illuminate\Console\Command;
use RainLab\Deploy\Models\Server;
use RainLab\Deploy\Classes\ArchiveBuilder;
use Exception;

class DeployUploads extends Command
{
    protected $signature = 'deployextender:deploy-uploads
        {server : Server name or ID}
        {--f|force : Skip confirmation prompt}';

    protected $description = 'Deploy storage/app/uploads directory to a remote server';

    public function handle()
    {
        $server = $this->resolveServer($this->argument('server'));

        if (!$server) {
            $this->error('Server not found.');
            return 1;
        }

        $uploadsPath = storage_path('app/uploads');
        if (!is_dir($uploadsPath)) {
            $this->warn('No storage/app/uploads directory found locally. Nothing to deploy.');
            return 0;
        }

        $this->info('=== DEPLOY UPLOADS ===');
        $this->info("Server: {$server->server_name} ({$server->endpoint_url})");
        $this->info("Source: storage/app/uploads");
        $this->line('');

        if (!$this->option('force') && !$this->confirm('Deploy uploads to remote server?')) {
            $this->info('Deploy cancelled.');
            return 0;
        }

        try {
            // Build archive
            $this->line('Building uploads archive...');
            $archivePath = storage_path('temp/deployextender-uploads-' . time() . '.zip');

            ArchiveBuilder::instance()->buildArchive($archivePath, [
                'dirsSrc' => ['storage/app/uploads' => $uploadsPath],
            ]);

            $archiveSize = filesize($archivePath);
            $this->line("Archive size: {$this->formatBytes($archiveSize)}");

            // Upload
            $this->line('Uploading to remote server...');
            $uploadResult = $server->transmitFile($archivePath);

            // Extract on remote
            $this->line('Extracting on remote server...');
            $server->transmitScript('extract_archive', [
                'files' => [
                    $archivePath => base64_decode($uploadResult['path']),
                ],
            ]);

            // Cleanup
            @unlink($archivePath);

            $this->info('✓ Uploads deployed successfully!');
            return 0;

        } catch (Exception $e) {
            $this->error("Deploy failed: {$e->getMessage()}");
            return 1;
        }
    }

    protected function resolveServer(string $identifier): ?Server
    {
        return Server::where('id', $identifier)
            ->orWhere('server_name', $identifier)
            ->first();
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
}
