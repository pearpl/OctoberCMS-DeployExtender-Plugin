<?php namespace Pear\DeployExtender\Models;

/**
 * Deploy Extender Plugin for October CMS
 *
 * @author     Pear Interactive <hello@pear.pl>
 * @link       https://github.com/pearpl/OctoberCMS-DeployExtender-Plugin
 * @license    MIT
 */

use Model;

class SyncLog extends Model
{
    use \October\Rain\Database\Traits\Validation;

    public $table = 'pear_deployextender_sync_logs';

    protected $fillable = [
        'server_id',
        'server_name',
        'direction',
        'type',
        'status',
        'details',
        'backup_path',
        'tables_synced',
        'files_synced',
        'users_skipped',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $dates = ['started_at', 'completed_at'];

    public $rules = [
        'direction' => 'required|in:push,pull',
        'type'      => 'required|in:database,uploads,media,full',
        'status'    => 'required|in:running,success,error',
    ];

    public function getDirectionLabelAttribute()
    {
        return $this->direction === 'push'
            ? 'Local → Remote'
            : 'Remote → Local';
    }

    public function scopeLatestForServer($query, $serverId)
    {
        return $query->where('server_id', $serverId)
            ->where('status', 'success')
            ->orderBy('completed_at', 'desc');
    }

    public static function start($serverId, $serverName, $direction, $type, $skipUsers = false)
    {
        return static::create([
            'server_id'     => $serverId,
            'server_name'   => $serverName,
            'direction'     => $direction,
            'type'          => $type,
            'status'        => 'running',
            'users_skipped' => $skipUsers,
            'started_at'    => now(),
        ]);
    }

    public function markSuccess($tableCount = 0, $fileCount = 0, $backupPath = null, $details = null)
    {
        $this->update([
            'status'        => 'success',
            'tables_synced' => $tableCount,
            'files_synced'  => $fileCount,
            'backup_path'   => $backupPath,
            'details'       => $details,
            'completed_at'  => now(),
        ]);
    }

    public function markError($message)
    {
        $this->update([
            'status'        => 'error',
            'error_message' => $message,
            'completed_at'  => now(),
        ]);
    }
}
