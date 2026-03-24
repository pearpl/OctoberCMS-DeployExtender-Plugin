<?php namespace Pear\DeployExtender\Controllers;

/**
 * Deploy Extender Plugin for October CMS
 *
 * @author     Pear Interactive <hello@pear.pl>
 * @link       https://github.com/pearpl/OctoberCMS-DeployExtender-Plugin
 * @license    proprietary
 */

use BackendMenu;
use Backend\Classes\Controller;

class SyncLogs extends Controller
{
    public $implement = [
        \Backend\Behaviors\ListController::class,
    ];

    public $listConfig = 'config_list.yaml';

    public $requiredPermissions = ['pear.deployextender.view_logs'];

    public function __construct()
    {
        parent::__construct();

        BackendMenu::setContext('Pear.DeployExtender', 'deployextender', 'synclogs');
    }
}
