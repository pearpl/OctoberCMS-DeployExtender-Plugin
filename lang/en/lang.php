<?php

return [
    'plugin' => [
        'name'        => 'Deploy Extender',
        'description' => 'Extends RainLab Deploy with bidirectional database sync, uploads deployment, and automatic backups.',
    ],
    'navigation' => [
        'label'     => 'Deploy Extender',
        'sync_logs' => 'Sync Logs',
    ],
    'permissions' => [
        'manage_sync' => 'Manage sync operations',
        'view_logs'   => 'View sync logs',
    ],
    'synclogs' => [
        'list_title'    => 'Sync Logs',
        'direction'     => 'Direction',
        'type'          => 'Type',
        'status'        => 'Status',
        'server_name'   => 'Server',
        'tables_synced' => 'Tables',
        'files_synced'  => 'Files',
        'users_skipped' => 'Users Skipped',
        'backup_path'   => 'Backup Path',
        'started_at'    => 'Started',
        'completed_at'  => 'Completed',
        'error_message' => 'Error',
        'details'       => 'Details',
        'direction_push' => 'Push (Local → Remote)',
        'direction_pull' => 'Pull (Remote → Local)',
        'status_running' => 'Running',
        'status_success' => 'Success',
        'status_error'   => 'Error',
    ],
    'sync' => [
        'push_confirm'  => 'This will OVERWRITE the remote database and storage files. Are you sure?',
        'pull_confirm'  => 'This will OVERWRITE the local database and storage files. Are you sure?',
        'backup_created' => 'Backup created: :path',
        'sync_complete'  => 'Sync completed successfully.',
        'sync_failed'    => 'Sync failed: :error',
    ],
];
