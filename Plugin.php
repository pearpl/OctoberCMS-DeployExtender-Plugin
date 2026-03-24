<?php namespace Pear\DeployExtender;

/**
 * Deploy Extender Plugin for October CMS
 *
 * @author     Pear Interactive <hello@pear.pl>
 * @link       https://github.com/pearpl/OctoberCMS-DeployExtender-Plugin
 * @license    proprietary
 */

use Backend;
use Event;
use Flash;
use Redirect;
use RainLab\Deploy\Classes\ArchiveBuilder;
use RainLab\Deploy\Models\Server;
use Pear\DeployExtender\Classes\SyncManager;
use Pear\DeployExtender\Models\SyncLog;
use System\Classes\PluginBase;
use Exception;

class Plugin extends PluginBase
{
    public $require = ['RainLab.Deploy'];

    public function pluginDetails()
    {
        return [
            'name'        => 'pear.deployextender::lang.plugin.name',
            'description' => 'pear.deployextender::lang.plugin.description',
            'author'      => 'Pear Interactive',
            'icon'        => 'icon-exchange',
            'homepage'    => 'https://pearinteractive.com'
        ];
    }

    public function boot()
    {
        $this->extendDeployForm();
        $this->extendServersController();
    }

    public function register()
    {
        $this->registerConsoleCommand('deployextender.sync-push', \Pear\DeployExtender\Console\SyncPush::class);
        $this->registerConsoleCommand('deployextender.sync-pull', \Pear\DeployExtender\Console\SyncPull::class);
        $this->registerConsoleCommand('deployextender.deploy-uploads', \Pear\DeployExtender\Console\DeployUploads::class);
    }

    protected function extendDeployForm()
    {
        Event::listen('backend.form.extendFields', function ($widget) {
            if (!$widget->model instanceof Server) return;
            if ($widget->alias !== 'deploy') return;

            $widget->addFields([
                'deploy_uploads' => [
                    'tab'     => 'Deploy',
                    'span'    => 'auto',
                    'label'   => 'Deploy Upload Files',
                    'comment' => 'All uploaded files in the storage/app/uploads directory',
                    'type'    => 'checkbox',
                    'after'   => 'deploy_media',
                ],
            ]);
        });

        Event::listen('backend.form.extendFields', function ($widget) {
            if (!$widget->model instanceof Server) return;
            if ($widget->getContext() !== 'manage') return;

            $widget->addFields([
                '_action_sync_push' => [
                    'type' => 'partial',
                    'path' => '$/pear/deployextender/partials/_action_sync_push.htm',
                ],
                '_action_sync_pull' => [
                    'type' => 'partial',
                    'path' => '$/pear/deployextender/partials/_action_sync_pull.htm',
                ],
                '_action_backup_remote_db' => [
                    'type' => 'partial',
                    'path' => '$/pear/deployextender/partials/_action_backup_remote_db.htm',
                ],
            ]);
        });
    }

    protected function extendServersController()
    {
        \RainLab\Deploy\Controllers\Servers::extend(function ($controller) {

            $controller->addJs('/plugins/pear/deployextender/assets/js/sync-executor.js');

            $processStorageBatch = function (string $direction, string $directory, string $prefix, array &$state, SyncManager $manager, string $sessionKey): array {
                $batchSize = SyncManager::BATCH_MAX_FILES;

                if (!isset($state[$prefix . '_init'])) {
                    $info = ($direction === 'push')
                        ? $manager->getLocalStorageInfo($directory)
                        : $manager->getRemoteStorageInfo($directory);

                    if ($info['total_count'] === 0) {
                        return ['message' => 'No files to sync. Skipping.', 'skipped' => true];
                    }

                    $state[$prefix . '_init'] = true;
                    $state[$prefix . '_offset'] = 0;
                    $state[$prefix . '_total'] = $info['total_count'];
                    $state[$prefix . '_total_size'] = $info['total_size'];
                    $state[$prefix . '_synced'] = 0;
                    $state[$prefix . '_bytes'] = 0;
                    $state[$prefix . '_started'] = microtime(true);
                    session([$sessionKey => $state]);
                }

                $result = ($direction === 'push')
                    ? $manager->pushStorageBatch($directory, $state[$prefix . '_offset'], $batchSize)
                    : $manager->pullStorageBatch($directory, $state[$prefix . '_offset'], $batchSize);

                $state[$prefix . '_offset'] += $batchSize;
                $state[$prefix . '_synced'] += $result['files'];
                $state[$prefix . '_bytes'] += $result['bytes'];
                session([$sessionKey => $state]);

                $elapsed = max(0.1, microtime(true) - $state[$prefix . '_started']);
                $speed = $state[$prefix . '_bytes'] / $elapsed;
                $percent = min(100, round(($state[$prefix . '_synced'] / $state[$prefix . '_total']) * 100));

                $formatBytes = function (int $bytes) {
                    $u = ['B','KB','MB','GB'];
                    $i = 0; $s = (float)$bytes;
                    while ($s >= 1024 && $i < 3) { $s /= 1024; $i++; }
                    return round($s, 1) . ' ' . $u[$i];
                };

                if ($state[$prefix . '_offset'] < $state[$prefix . '_total']) {
                    return [
                        'message'  => "{$state[$prefix . '_synced']}/{$state[$prefix . '_total']} files",
                        'continue' => true,
                        'progress' => [
                            'percent'    => $percent,
                            'bytes'      => $formatBytes($state[$prefix . '_bytes']),
                            'totalBytes' => $formatBytes($state[$prefix . '_total_size']),
                            'speed'      => $formatBytes((int) $speed) . '/s',
                        ],
                    ];
                }

                $state['total_files'] += $state[$prefix . '_synced'];
                session([$sessionKey => $state]);
                return [
                    'message' => "{$state[$prefix . '_synced']} files synced ({$formatBytes($state[$prefix . '_bytes'])})",
                    'progress' => ['percent' => 100],
                ];
            };

            $controller->addDynamicMethod('manage_onBackupRemoteDb', function () use ($controller) {
                $server = Server::findOrFail(post('server_id'));
                $manager = new SyncManager($server);

                try {
                    $result = $manager->transmitCustomScript('db_export', [
                        'exclude_tables' => [],
                    ]);

                    if (($result['status'] ?? '') !== 'ok') {
                        throw new Exception('Remote database export failed: ' . ($result['error'] ?? 'Unknown error'));
                    }

                    $tableCount = count($result['tables'] ?? []);
                    $remoteFileSize = $result['size'] ?? 0;

                    $localPath = storage_path('app/deploy-backups/remote-' . $server->server_name . '-' . date('Y-m-d_His') . '.sql');
                    \File::makeDirectory(dirname($localPath), 0755, true, true);

                    $manager->downloadRemoteFilePublic($result['file'], $localPath, $remoteFileSize);

                    $manager->transmitCustomScript('cleanup_file', ['file' => $result['file']]);

                    $sizeFormatted = round(filesize($localPath) / 1024 / 1024, 2);
                    Flash::success("Remote database backup saved: {$localPath} ({$tableCount} tables, {$sizeFormatted} MB)");
                } catch (Exception $e) {
                    Flash::error('Remote DB backup failed: ' . $e->getMessage());
                }

                return Redirect::refresh();
            });

            $controller->addDynamicMethod('manage_onLoadSyncPush', function () use ($controller) {
                $server = Server::findOrFail(post('server_id'));
                $lastSync = SyncLog::latestForServer($server->id)
                    ->where('direction', 'push')
                    ->first();

                $controller->vars['server'] = $server;
                $controller->vars['lastSync'] = $lastSync;
                $controller->vars['actionTitle'] = 'Sync Push: Local → Remote';

                return $controller->makePartial('$/pear/deployextender/partials/_sync_push_form.htm');
            });

            $controller->addDynamicMethod('manage_onSyncPushStep', function () use ($controller, $processStorageBatch) {
                @set_time_limit(3600);
                $server = Server::findOrFail(post('server_id'));
                $step = post('step');
                $sessionKey = 'deployextender_sync_' . $server->id;
                $state = session($sessionKey, []);

                try {
                    $manager = new SyncManager($server);

                    switch ($step) {
                        case 'init':
                            $syncDb = (bool) post('sync_db', false);
                            $syncMedia = (bool) post('sync_media', false);
                            $syncUploads = (bool) post('sync_uploads', false);
                            $skipUsers = (bool) post('skip_users', false);

                            $type = 'full';
                            if ($syncDb && !$syncMedia && !$syncUploads) $type = 'database';
                            elseif (!$syncDb && $syncUploads) $type = 'uploads';
                            elseif (!$syncDb) $type = 'media';

                            $syncLog = SyncLog::start($server->id, $server->server_name, 'push', $type, $skipUsers);

                            session([$sessionKey => [
                                'sync_log_id' => $syncLog->id,
                                'skip_users' => $skipUsers,
                                'sync_db' => $syncDb,
                                'sync_media' => $syncMedia,
                                'sync_uploads' => $syncUploads,
                                'total_tables' => 0,
                                'total_files' => 0,
                                'backup_path' => null,
                            ]]);

                            return ['message' => 'Sync session initialized for ' . $server->server_name];

                        case 'database':
                            if (!post('sync_db') && empty($state['sync_db'])) {
                                return ['message' => 'Skipped (not selected)', 'skipped' => true];
                            }
                            $result = $manager->pushDatabase((bool) (post('skip_users') ?: ($state['skip_users'] ?? false)));
                            $state['total_tables'] = $result['tables'];
                            $state['backup_path'] = $result['backup_path'] ?? null;
                            session([$sessionKey => $state]);
                            return ['message' => $result['tables'] . ' tables synced'];

                        case 'media':
                            if (!post('sync_media') && empty($state['sync_media'])) {
                                return ['message' => 'Skipped (not selected)', 'skipped' => true];
                            }
                            return $processStorageBatch('push', 'app/media', 'media', $state, $manager, $sessionKey);

                        case 'uploads':
                            if (!post('sync_uploads') && empty($state['sync_uploads'])) {
                                return ['message' => 'Skipped (not selected)', 'skipped' => true];
                            }
                            return $processStorageBatch('push', 'app/uploads', 'uploads', $state, $manager, $sessionKey);

                        case 'complete':
                            $syncLog = SyncLog::find($state['sync_log_id'] ?? 0);
                            if ($syncLog) {
                                $syncLog->markSuccess(
                                    $state['total_tables'] ?? 0,
                                    $state['total_files'] ?? 0,
                                    $state['backup_path'] ?? null
                                );
                            }
                            session()->forget($sessionKey);
                            return ['message' => ($state['total_tables'] ?? 0) . ' tables, ' . ($state['total_files'] ?? 0) . ' files synced'];

                        default:
                            throw new Exception('Unknown sync step: ' . $step);
                    }
                } catch (Exception $e) {
                    if (!empty($state['sync_log_id'])) {
                        $syncLog = SyncLog::find($state['sync_log_id']);
                        if ($syncLog && $syncLog->status === 'running') {
                            $syncLog->markError($e->getMessage());
                        }
                    }
                    session()->forget($sessionKey);
                    throw $e;
                }
            });

            $controller->addDynamicMethod('manage_onLoadSyncPull', function () use ($controller) {
                $server = Server::findOrFail(post('server_id'));
                $lastSync = SyncLog::latestForServer($server->id)
                    ->where('direction', 'pull')
                    ->first();

                $controller->vars['server'] = $server;
                $controller->vars['lastSync'] = $lastSync;
                $controller->vars['actionTitle'] = 'Sync Pull: Remote → Local';

                return $controller->makePartial('$/pear/deployextender/partials/_sync_pull_form.htm');
            });

            $controller->addDynamicMethod('manage_onSyncPullStep', function () use ($controller, $processStorageBatch) {
                @set_time_limit(3600);
                $server = Server::findOrFail(post('server_id'));
                $step = post('step');
                $sessionKey = 'deployextender_sync_' . $server->id;
                $state = session($sessionKey, []);

                try {
                    $manager = new SyncManager($server);

                    switch ($step) {
                        case 'init':
                            $syncDb = (bool) post('sync_db', false);
                            $syncMedia = (bool) post('sync_media', false);
                            $syncUploads = (bool) post('sync_uploads', false);
                            $skipUsers = (bool) post('skip_users', false);

                            $type = 'full';
                            if ($syncDb && !$syncMedia && !$syncUploads) $type = 'database';
                            elseif (!$syncDb && $syncUploads) $type = 'uploads';
                            elseif (!$syncDb) $type = 'media';

                            $syncLog = SyncLog::start($server->id, $server->server_name, 'pull', $type, $skipUsers);

                            session([$sessionKey => [
                                'sync_log_id' => $syncLog->id,
                                'skip_users' => $skipUsers,
                                'sync_db' => $syncDb,
                                'sync_media' => $syncMedia,
                                'sync_uploads' => $syncUploads,
                                'total_tables' => 0,
                                'total_files' => 0,
                                'backup_path' => null,
                            ]]);

                            return ['message' => 'Sync session initialized for ' . $server->server_name];

                        case 'database':
                            if (!post('sync_db') && empty($state['sync_db'])) {
                                return ['message' => 'Skipped (not selected)', 'skipped' => true];
                            }
                            $result = $manager->pullDatabase((bool) (post('skip_users') ?: ($state['skip_users'] ?? false)));
                            $state['total_tables'] = $result['tables'];
                            $state['backup_path'] = $result['backup_path'] ?? null;
                            session([$sessionKey => $state]);
                            return ['message' => $result['tables'] . ' tables synced'];

                        case 'media':
                            if (!post('sync_media') && empty($state['sync_media'])) {
                                return ['message' => 'Skipped (not selected)', 'skipped' => true];
                            }
                            return $processStorageBatch('pull', 'app/media', 'media', $state, $manager, $sessionKey);

                        case 'uploads':
                            if (!post('sync_uploads') && empty($state['sync_uploads'])) {
                                return ['message' => 'Skipped (not selected)', 'skipped' => true];
                            }
                            return $processStorageBatch('pull', 'app/uploads', 'uploads', $state, $manager, $sessionKey);

                        case 'complete':
                            $syncLog = SyncLog::find($state['sync_log_id'] ?? 0);
                            if ($syncLog) {
                                $syncLog->markSuccess(
                                    $state['total_tables'] ?? 0,
                                    $state['total_files'] ?? 0,
                                    $state['backup_path'] ?? null
                                );
                            }
                            session()->forget($sessionKey);
                            return ['message' => ($state['total_tables'] ?? 0) . ' tables, ' . ($state['total_files'] ?? 0) . ' files synced'];

                        default:
                            throw new Exception('Unknown sync step: ' . $step);
                    }
                } catch (Exception $e) {
                    if (!empty($state['sync_log_id'])) {
                        $syncLog = SyncLog::find($state['sync_log_id']);
                        if ($syncLog && $syncLog->status === 'running') {
                            $syncLog->markError($e->getMessage());
                        }
                    }
                    session()->forget($sessionKey);
                    throw $e;
                }
            });

        });

        Event::listen('backend.page.beforeDisplay', function ($controller, $action, $params) {
            if (!$controller instanceof \RainLab\Deploy\Controllers\Servers) return;

            $controller->addDynamicMethod('manage_onSaveDeployToServer', function ($serverId) use ($controller) {
                $server = Server::findOrFail($serverId);
                $server->setDeployPreferences('deploy_config', post());
                $server->save();

                $deployActions = [];
                $useFiles = [];

                if (post('deploy_core')) {
                    $useFiles[] = self::addArchiveStep($deployActions, 'Core', 'buildCoreModules');
                    $useFiles[] = self::addArchiveStep($deployActions, 'Vendor', 'buildVendorPackages');
                }

                if (post('deploy_config')) {
                    $useFiles[] = self::addArchiveStep($deployActions, 'Config', 'buildConfigFiles');
                }

                if (post('deploy_app')) {
                    $useFiles[] = self::addArchiveStep($deployActions, 'App', 'buildAppFiles');
                }

                if (post('deploy_media')) {
                    $useFiles[] = self::addArchiveStep($deployActions, 'Media', 'buildMediaFiles');
                }

                if (post('deploy_uploads') && is_dir(storage_path('app/uploads'))) {
                    $useFiles[] = self::addUploadsStep($deployActions);
                }

                if ($plugins = post('plugins')) {
                    $useFiles[] = self::addArchiveStep($deployActions, 'Plugins', 'buildPluginsBundle', [(array) $plugins]);
                }

                if ($themes = post('themes')) {
                    $useFiles[] = self::addArchiveStep($deployActions, 'Themes', 'buildThemesBundle', [(array) $themes]);
                }

                if (count($useFiles)) {
                    $deployActions[] = [
                        'label' => 'Extracting Files',
                        'action' => 'extractFiles',
                        'files' => $useFiles
                    ];
                }

                $deployActions[] = [
                    'label' => 'Clearing Cache',
                    'action' => 'transmitScript',
                    'script' => 'clear_cache'
                ];

                $deployActions[] = [
                    'label' => 'Migrating Database',
                    'action' => 'transmitArtisan',
                    'artisan' => 'october:migrate'
                ];

                if (post('deploy_core')) {
                    $build = \System\Models\Parameter::get('system::core.build', 0);
                    $deployActions[] = [
                        'label' => 'Setting Build Number',
                        'action' => 'transmitArtisan',
                        'artisan' => 'october:util set build --value=' . $build
                    ];
                }

                $deployActions[] = [
                    'label' => 'Finishing Up',
                    'action' => 'final',
                    'files' => $useFiles,
                    'deploy_core' => post('deploy_core')
                ];

                $deployer = new \RainLab\Deploy\Widgets\Deployer($controller);
                $deployer->bindToController();

                return $deployer->executeSteps($serverId, $deployActions);
            });
        });
    }

    protected static function addArchiveStep(array &$steps, string $typeLabel, string $buildFunc, array $funcArgs = []): string
    {
        $fileId = md5(uniqid());
        $filePath = temp_path("ocbl-{$fileId}.arc");

        $steps[] = [
            'label' => __('Building :type Archive', ['type' => $typeLabel]),
            'action' => 'archiveBuilder',
            'func' => $buildFunc,
            'args' => array_merge([$filePath], $funcArgs)
        ];

        $steps[] = [
            'label' => __('Deploying :type Archive', ['type' => $typeLabel]),
            'action' => 'transmitFile',
            'file' => $filePath
        ];

        return $filePath;
    }

    protected static function addUploadsStep(array &$steps): string
    {
        $fileId = md5(uniqid());
        $filePath = temp_path("ocbl-{$fileId}.arc");
        $uploadsPath = storage_path('app/uploads');

        $steps[] = [
            'label' => __('Building :type Archive', ['type' => 'Uploads']),
            'action' => 'archiveBuilder',
            'func' => 'buildArchive',
            'args' => [$filePath, [
                'dirs' => ['storage/app/uploads'],
                'dirsSrc' => ['storage/app/uploads' => $uploadsPath],
            ]]
        ];

        $steps[] = [
            'label' => __('Deploying :type Archive', ['type' => 'Uploads']),
            'action' => 'transmitFile',
            'file' => $filePath
        ];

        return $filePath;
    }

    public function registerNavigation()
    {
        return [];
    }

    public function registerPermissions()
    {
        return [
            'pear.deployextender.manage_sync' => [
                'tab'   => 'pear.deployextender::lang.plugin.name',
                'label' => 'pear.deployextender::lang.permissions.manage_sync'
            ],
            'pear.deployextender.view_logs' => [
                'tab'   => 'pear.deployextender::lang.plugin.name',
                'label' => 'pear.deployextender::lang.permissions.view_logs'
            ]
        ];
    }
}
