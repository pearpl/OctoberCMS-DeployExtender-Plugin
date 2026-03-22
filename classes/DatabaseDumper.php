<?php namespace Pear\DeployExtender\Classes;

/**
 * Deploy Extender Plugin for October CMS
 *
 * @author     Pear Interactive <hello@pear.pl>
 * @link       https://github.com/pearpl/OctoberCMS-DeployExtender-Plugin
 * @license    MIT
 */

use Db;
use PDO;
use Exception;
use File as FileHelper;

class DatabaseDumper
{
    const ALWAYS_EXCLUDE = [
        'rainlab_deploy_servers',
        'rainlab_deploy_server_keys',
        'pear_deployextender_sync_logs',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'failed_jobs',
        'system_event_logs',
        'system_request_logs',
    ];

    const USER_TABLES = [
        'backend_users',
        'backend_user_groups',
        'backend_user_roles',
        'backend_user_preferences',
        'backend_user_throttle',
        'backend_users_groups',
        'backend_users_roles',
    ];

    public static function export(string $outputPath, bool $skipUsers = false, array $extraExclude = []): array
    {
        $pdo = Db::connection()->getPdo();
        $excludeTables = array_merge(self::ALWAYS_EXCLUDE, $extraExclude);

        if ($skipUsers) {
            $excludeTables = array_merge($excludeTables, self::USER_TABLES);
        }

        $tables = self::getTables($pdo, $excludeTables);

        FileHelper::makeDirectory(dirname($outputPath), 0755, true, true);

        $fp = fopen($outputPath, 'w');
        if ($fp === false) {
            throw new Exception("Cannot open file for writing: {$outputPath}");
        }

        fwrite($fp, "-- Deploy Extender Database Dump\n");
        fwrite($fp, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
        fwrite($fp, "-- Tables: " . count($tables) . "\n\n");
        fwrite($fp, "SET FOREIGN_KEY_CHECKS = 0;\n");
        fwrite($fp, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
        fwrite($fp, "SET NAMES utf8mb4;\n\n");

        foreach ($tables as $table) {
            self::dumpTable($pdo, $fp, $table);
        }

        fwrite($fp, "SET FOREIGN_KEY_CHECKS = 1;\n");
        fclose($fp);

        return [
            'tables' => count($tables),
            'size' => filesize($outputPath),
        ];
    }

    public static function import(string $sqlPath): int
    {
        if (!file_exists($sqlPath)) {
            throw new Exception("SQL file not found: {$sqlPath}");
        }

        $pdo = Db::connection()->getPdo();
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

        $sql = file_get_contents($sqlPath);
        $statements = self::splitStatements($sql);
        $executed = 0;

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (empty($statement) || strpos($statement, '--') === 0) {
                continue;
            }
            $pdo->exec($statement);
            $executed++;
        }

        return $executed;
    }

    public static function getTables(PDO $pdo = null, array $excludeTables = []): array
    {
        $pdo = $pdo ?: Db::connection()->getPdo();
        $tables = [];
        $stmt = $pdo->query("SHOW TABLES");

        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            if (!in_array($row[0], $excludeTables, true)) {
                $tables[] = $row[0];
            }
        }

        return $tables;
    }

    protected static function dumpTable(PDO $pdo, $fp, string $table): void
    {
        // Structure
        $stmt = $pdo->query("SHOW CREATE TABLE " . self::quoteIdentifier($table));
        $create = $stmt->fetch(PDO::FETCH_NUM);

        fwrite($fp, "-- Table: {$table}\n");
        fwrite($fp, "DROP TABLE IF EXISTS " . self::quoteIdentifier($table) . ";\n");
        fwrite($fp, $create[1] . ";\n\n");

        // Data
        $stmt = $pdo->query("SELECT * FROM " . self::quoteIdentifier($table));
        $columnCount = $stmt->columnCount();

        if ($columnCount === 0) {
            fwrite($fp, "\n");
            return;
        }

        $batchSize = 100;
        $rowCount = 0;
        $batchRows = [];

        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $values = [];
            foreach ($row as $value) {
                if ($value === null) {
                    $values[] = 'NULL';
                } else {
                    $values[] = $pdo->quote($value);
                }
            }
            $batchRows[] = '(' . implode(',', $values) . ')';
            $rowCount++;

            if (count($batchRows) >= $batchSize) {
                fwrite($fp, "INSERT INTO " . self::quoteIdentifier($table) . " VALUES\n");
                fwrite($fp, implode(",\n", $batchRows) . ";\n");
                $batchRows = [];
            }
        }

        if (!empty($batchRows)) {
            fwrite($fp, "INSERT INTO " . self::quoteIdentifier($table) . " VALUES\n");
            fwrite($fp, implode(",\n", $batchRows) . ";\n");
        }

        fwrite($fp, "\n");
    }

    protected static function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $inString = false;
        $stringChar = '';
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            // Handle string boundaries
            if ($inString) {
                $current .= $char;
                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $sql[++$i];
                    continue;
                }
                if ($char === $stringChar) {
                    $inString = false;
                }
                continue;
            }

            // Skip single-line comments
            if ($char === '-' && $i + 1 < $length && $sql[$i + 1] === '-') {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            // Detect string start
            if ($char === '\'' || $char === '"') {
                $inString = true;
                $stringChar = $char;
                $current .= $char;
                continue;
            }

            // Statement terminator
            if ($char === ';') {
                $trimmed = trim($current);
                if (!empty($trimmed)) {
                    $statements[] = $trimmed;
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $trimmed = trim($current);
        if (!empty($trimmed)) {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    protected static function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
