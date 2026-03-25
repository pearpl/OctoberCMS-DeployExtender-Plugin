<?php namespace Pear\DeployExtender\Updates;

/**
 * Deploy Extender Plugin for October CMS
 *
 * @author     Pear Interactive <hello@pear.pl>
 * @link       https://github.com/pearpl/OctoberCMS-DeployExtender-Plugin
 * @license    proprietary
 */

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

class CreateSyncLogsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('pear_deployextender_sync_logs')) {
            return;
        }

        Schema::create('pear_deployextender_sync_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('server_id')->unsigned()->nullable()->index();
            $table->string('server_name')->nullable();
            $table->string('direction', 10); // push or pull
            $table->string('type', 20); // database, uploads, media, full
            $table->string('status', 20)->default('running'); // running, success, error
            $table->text('details')->nullable();
            $table->string('backup_path')->nullable();
            $table->integer('tables_synced')->unsigned()->default(0);
            $table->integer('files_synced')->unsigned()->default(0);
            $table->boolean('users_skipped')->default(false);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('pear_deployextender_sync_logs');
    }
}
