<?php namespace Pear\DeployExtender;

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

    /**
     * Extend the deploy form with uploads checkbox
     */
    protected function extendDeployForm()
    {
        Event::listen('backend.form.extendFields', function ($widget) {
            if (!$widget->model instanceof Server) {
                return;
            }

            if ($widget->alias !== 'deploy') {
                return;
            }

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

        // Extend the manage form with sync action partials
        Event::listen('backend.form.extendFields', function ($widget) {
            if (!$widget->model instanceof Server) {
                return;
            }

            if ($widget->getContext() !== 'manage') {
                return;
            }

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

    /**
     * Extend the Servers controller with sync handlers and uploads deploy.
     */
    protected function extendServersController()
    {
        \RainLab\Deploy\Controllers\Servers::extend(function ($controller) {

            $controller->addJs('/plugins/pear/deployextender/assets/js/sync-executor.js');

            // Handler: backup remote database (full dump download)
            $controller->addDynamicMethod('manage_onBackupRemoteDb', function () use ($controller) {
                $server = Server::findOrFail(post('server_id'));
                $manager = new SyncManager($server);

                try {
                    // Export full remote database (no exclusions)
                    $result = $manager->transmitCustomScript('db_export', [
                        'exclude_tables' => [],
                    ]);

                    if (($result['status'] ?? '') !== 'ok') {
                        throw new Exception('Remote database export failed: ' . ($result['error'] ?? 'Unknown error'));
                    }

                    $tableCount = count($result['tables'] ?? []);
                    $remoteFileSize = $result['size'] ?? 0;

                    // Download to local storage
                    $localPath = storage_path('app/deploy-backups/remote-' . $server->server_name . '-' . date('Y-m-d_His') . '.sql');
                    \File::makeDirectory(dirname($localPath), 0755, true, true);

                    $manager->downloadRemoteFilePublic($result['file'], $localPath, $remoteFileSize);

                    // Cleanup remote temp file
                    $manager->transmitCustomScript('cleanup_file', [
                        'file' => $result['file'],
                    ]);

                    $sizeFormatted = round(filesize($localPath) / 1024 / 1024, 2);
                    Flash::success("Remote database backup saved: {$localPath} ({$tableCount} tables, {$sizeFormatted} MB)");
                } catch (Exception $e) {
                    Flash::error('Remote DB backup failed: ' . $e->getMessage());
                }

                return Redirect::refresh();
            });

            // Handler: load sync push confirmation popup
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

            // Handler: step-based sync push
            $controller->addDynamicMethod('manage_onSyncPushStep', function () use ($controller) {
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
                            if (empty($state['sync_db'])) {
                                return ['message' => 'Skipped (not selected)', 'skipped' => true];
                            }
                            $result = $manager->pushDatabase($state['skip_users'] ?? false);
                            $state['total_tables'] = $result['tables'];
                            $state['backup_path'] = $result['backup_path'] ?? null;
                            session([$sessionKey => $state]);
                            return ['message' => $result['tables'] . ' tables synced'];

                        case 'media':
                            if (empty($state['sync_media'])) {
                                return ['message' => 'Skipped (not selected)', 'skipped' => true];
                            }
                            $files = $manager->pushStorage('app/media');
                            $state['total_files'] += $files;
                            session([$sessionKey => $state]);
                            return ['message' => $files . ' files synced'];

                        case 'uploads':
                            if (empty($state['sync_uploads'])) {
                                return ['message' => 'Skipped (not selected)', 'skipped' => true];
                            }
                            $files = $manager->pushStorage('app/uploads');
                            $state['total_files'] += $files;
                            session([$sessionKey => $state]);
                            return ['message' => $files . ' files synced'];

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

            // Handler: load sync pull confirmation popup
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

            // Handler: step-based sync pull
            $controller->addDynamicMethod('manage_onSyncPullStep', function () use ($controller) {
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
                            if (empty($state['sync_db'])) {
                                return ['message' => 'Skipped (not selected)', 'skipped' => true];
                            }
                            $result = $manager->pullDatabase($state['skip_users'] ?? false);
                            $state['total_tables'] = $result['tables'];
                            $state['backup_path'] = $result['backup_path'] ?? null;
                            session([$sessionKey => $state]);
                            return ['message' => $result['tables'] . ' tables synced'];

                        case 'media':
                            if (empty($state['sync_media'])) {
                                return ['message' => 'Skipped (not selected)', 'skipped' => true];
                            }
                            $files = $manager->pullStorage('app/media');
                            $state['total_files'] += $files;
                            session([$sessionKey => $state]);
                            return ['message' => $files . ' files synced'];

                        case 'uploads':
                            if (empty($state['sync_uploads'])) {
                                return ['message' => 'Skipped (not selected)', 'skipped' => true];
                            }
                            $files = $manager->pullStorage('app/uploads');
                            $state['total_files'] += $files;
                            session([$sessionKey => $state]);
                            return ['message' => $files . ' files synced'];

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

        // Intercept the deploy save to inject uploads step
        Event::listen('backend.page.beforeDisplay', function ($controller, $action, $params) {
            if (!$controller instanceof \RainLab\Deploy\Controllers\Servers) {
                return;
            }

            // Override the deploy handler to add uploads support
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

                // ---- DEPLOY EXTENDER: Uploads support ----
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

    /**
     * Build a standard archive deploy step.
     */
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

    /**
     * Build the uploads archive deploy step.
     */
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
