<?php
declare(strict_types=1);

/**
 * File: core/Migration.php
 * Location: core/Migration.php
 *
 * WinABN Database Migration System
 *
 * Handles database schema migrations, version tracking, and rollback functionality
 * for the WinABN platform. Ensures consistent database state across environments.
 *
 * @package WinABN\Core
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Core;

use Exception;
use DirectoryIterator;

class Migration
{
    /**
     * Migration directory path
     *
     * @var string
     */
    private string $migrationPath;

    /**
     * Database instance
     *
     * @var Database
     */
    private Database $db;

    /**
     * Migration table name
     *
     * @var string
     */
    private string $migrationTable = 'migrations';

    /**
     * Constructor
     *
     * @param string|null $migrationPath Path to migration files
     */
    public function __construct(?string $migrationPath = null)
    {
        $this->migrationPath = $migrationPath ?? WINABN_ROOT_DIR . '/migrations';
        $this->db = new Database();
        $this->createMigrationTable();
    }

    /**
     * Run all pending migrations
     *
     * @return array<string, mixed> Migration results
     * @throws Exception
     */
    public function migrate(): array
    {
        $pendingMigrations = $this->getPendingMigrations();
        $results = [];

        if (empty($pendingMigrations)) {
            return ['message' => 'No pending migrations', 'executed' => []];
        }

        foreach ($pendingMigrations as $migration) {
            $results[] = $this->executeMigration($migration);
        }

        return [
            'message' => 'Migrations completed successfully',
            'executed' => $results
        ];
    }

    /**
     * Rollback last migration
     *
     * @param int $steps Number of migrations to rollback
     * @return array<string, mixed> Rollback results
     * @throws Exception
     */
    public function rollback(int $steps = 1): array
    {
        $executedMigrations = $this->getExecutedMigrations($steps);
        $results = [];

        if (empty($executedMigrations)) {
            return ['message' => 'No migrations to rollback', 'rolled_back' => []];
        }

        foreach (array_reverse($executedMigrations) as $migration) {
            $results[] = $this->rollbackMigration($migration);
        }

        return [
            'message' => 'Rollback completed successfully',
            'rolled_back' => $results
        ];
    }

    /**
     * Get migration status
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $allMigrations = $this->getAllMigrationFiles();
        $executedMigrations = $this->getExecutedMigrationNames();

        $status = [];
        foreach ($allMigrations as $migration) {
            $filename = basename($migration, '.sql');
            $status[] = [
                'migration' => $filename,
                'status' => in_array($filename, $executedMigrations) ? 'executed' : 'pending',
                'file' => $migration
            ];
        }

        return [
            'total_migrations' => count($allMigrations),
            'executed_count' => count($executedMigrations),
            'pending_count' => count($allMigrations) - count($executedMigrations),
            'migrations' => $status
        ];
    }

    /**
     * Create migration table if it doesn't exist
     *
     * @return void
     * @throws Exception
     */
    private function createMigrationTable(): void
    {
        $query = "
            CREATE TABLE IF NOT EXISTS `{$this->migrationTable}` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `migration` varchar(255) NOT NULL,
                `batch` int unsigned NOT NULL,
                `executed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_migration_name` (`migration`),
                KEY `idx_migration_batch` (`batch`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        Database::exec($query);
    }

    /**
     * Get all migration files
     *
     * @return array<string>
     */
    private function getAllMigrationFiles(): array
    {
        if (!is_dir($this->migrationPath)) {
            throw new Exception("Migration directory does not exist: {$this->migrationPath}");
        }

        $migrations = [];
        $iterator = new DirectoryIterator($this->migrationPath);

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'sql') {
                $migrations[] = $file->getPathname();
            }
        }

        sort($migrations);
        return $migrations;
    }

    /**
     * Get pending migrations
     *
     * @return array<string>
     */
    private function getPendingMigrations(): array
    {
        $allMigrations = $this->getAllMigrationFiles();
        $executedMigrations = $this->getExecutedMigrationNames();

        $pending = [];
        foreach ($allMigrations as $migration) {
            $filename = basename($migration, '.sql');
            if (!in_array($filename, $executedMigrations)) {
                $pending[] = $migration;
            }
        }

        return $pending;
    }

    /**
     * Get executed migration names
     *
     * @return array<string>
     */
    private function getExecutedMigrationNames(): array
    {
        $query = "SELECT migration FROM `{$this->migrationTable}` ORDER BY id";
        $results = Database::fetchAll($query);

        return array_column($results, 'migration');
    }

    /**
     * Get executed migrations for rollback
     *
     * @param int $limit Number of migrations to get
     * @return array<array<string, mixed>>
     */
    private function getExecutedMigrations(int $limit): array
    {
        $query = "
            SELECT migration, batch
            FROM `{$this->migrationTable}`
            ORDER BY id DESC
            LIMIT ?
        ";

        return Database::fetchAll($query, [$limit]);
    }

    /**
     * Execute a single migration
     *
     * @param string $migrationFile Path to migration file
     * @return array<string, mixed>
     * @throws Exception
     */
    private function executeMigration(string $migrationFile): array
    {
        $filename = basename($migrationFile, '.sql');
        $startTime = microtime(true);

        try {
            Database::beginTransaction();

            // Read and execute migration SQL
            $sql = file_get_contents($migrationFile);
            if ($sql === false) {
                throw new Exception("Could not read migration file: $migrationFile");
            }

            // Split SQL into individual statements
            $statements = $this->splitSqlStatements($sql);

            foreach ($statements as $statement) {
                if (trim($statement)) {
                    Database::exec($statement);
                }
            }

            // Record migration execution
            $batch = $this->getNextBatchNumber();
            $this->recordMigration($filename, $batch);

            Database::commit();

            $executionTime = microtime(true) - $startTime;

            return [
                'migration' => $filename,
                'status' => 'success',
                'execution_time' => round($executionTime, 4),
                'statements_executed' => count($statements)
            ];

        } catch (Exception $e) {
            Database::rollback();

            return [
                'migration' => $filename,
                'status' => 'error',
                'error' => $e->getMessage(),
                'execution_time' => round(microtime(true) - $startTime, 4)
            ];
        }
    }

    /**
     * Rollback a single migration
     *
     * @param array<string, mixed> $migration Migration record
     * @return array<string, mixed>
     * @throws Exception
     */
    private function rollbackMigration(array $migration): array
    {
        $migrationName = $migration['migration'];
        $startTime = microtime(true);

        try {
            Database::beginTransaction();

            // Look for rollback file
            $rollbackFile = $this->migrationPath . '/' . $migrationName . '_rollback.sql';

            if (file_exists($rollbackFile)) {
                $sql = file_get_contents($rollbackFile);
                if ($sql === false) {
                    throw new Exception("Could not read rollback file: $rollbackFile");
                }

                $statements = $this->splitSqlStatements($sql);

                foreach ($statements as $statement) {
                    if (trim($statement)) {
                        Database::exec($statement);
                    }
                }
            } else {
                // Try to auto-generate rollback for simple operations
                $this->autoRollback($migrationName);
            }

            // Remove migration record
            $this->removeMigrationRecord($migrationName);

            Database::commit();

            return [
                'migration' => $migrationName,
                'status' => 'success',
                'execution_time' => round(microtime(true) - $startTime, 4)
            ];

        } catch (Exception $e) {
            Database::rollback();

            return [
                'migration' => $migrationName,
                'status' => 'error',
                'error' => $e->getMessage(),
                'execution_time' => round(microtime(true) - $startTime, 4)
            ];
        }
    }

    /**
     * Split SQL file into individual statements
     *
     * @param string $sql SQL content
     * @return array<string>
     */
    private function splitSqlStatements(string $sql): array
    {
        // Remove comments
        $sql = preg_replace('/--.*$/m', '', $sql);
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

        // Split by semicolons (basic approach)
        $statements = explode(';', $sql);

        // Filter out empty statements
        return array_filter(array_map('trim', $statements));
    }

    /**
     * Record migration execution
     *
     * @param string $migration Migration name
     * @param int $batch Batch number
     * @return void
     */
    private function recordMigration(string $migration, int $batch): void
    {
        $query = "
            INSERT INTO `{$this->migrationTable}` (migration, batch)
            VALUES (?, ?)
        ";

        Database::execute($query, [$migration, $batch]);
    }

    /**
     * Remove migration record
     *
     * @param string $migration Migration name
     * @return void
     */
    private function removeMigrationRecord(string $migration): void
    {
        $query = "DELETE FROM `{$this->migrationTable}` WHERE migration = ?";
        Database::execute($query, [$migration]);
    }

    /**
     * Get next batch number
     *
     * @return int
     */
    private function getNextBatchNumber(): int
    {
        $query = "SELECT COALESCE(MAX(batch), 0) + 1 as next_batch FROM `{$this->migrationTable}`";
        $result = Database::fetchColumn($query);

        return (int) $result;
    }

    /**
     * Attempt auto-rollback for simple operations
     *
     * @param string $migrationName Migration name
     * @return void
     * @throws Exception
     */
    private function autoRollback(string $migrationName): void
    {
        // This is a simplified auto-rollback
        // In practice, you'd want more sophisticated logic
        throw new Exception("No rollback file found for migration: $migrationName");
    }

    /**
     * Create a new migration file
     *
     * @param string $name Migration name
     * @param string $type Migration type (create, alter, etc.)
     * @return string Path to created migration file
     */
    public function createMigration(string $name, string $type = 'alter'): string
    {
        $timestamp = date('Y_m_d_His');
        $filename = sprintf('%s_%s_%s.sql', $timestamp, $type, $name);
        $filepath = $this->migrationPath . '/' . $filename;

        $template = $this->getMigrationTemplate($name, $type);

        if (file_put_contents($filepath, $template) === false) {
            throw new Exception("Could not create migration file: $filepath");
        }

        return $filepath;
    }

    /**
     * Get migration template
     *
     * @param string $name Migration name
     * @param string $type Migration type
     * @return string
     */
    private function getMigrationTemplate(string $name, string $type): string
    {
        $timestamp = date('Y-m-d H:i:s');

        return "-- File: migrations/{$name}.sql
-- Location: migrations/{$name}.sql
--
-- WinABN Migration: {$name}
-- Type: {$type}
-- Created: {$timestamp}

-- Migration SQL goes here
-- Remember to create a corresponding rollback file if needed

-- Example CREATE TABLE:
-- CREATE TABLE example_table (
--     id int unsigned NOT NULL AUTO_INCREMENT,
--     name varchar(255) NOT NULL,
--     created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
--     PRIMARY KEY (id)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Example ALTER TABLE:
-- ALTER TABLE existing_table
--     ADD COLUMN new_column varchar(100) NULL,
--     ADD INDEX idx_new_column (new_column);

-- Example INSERT DATA:
-- INSERT INTO table_name (column1, column2) VALUES
--     ('value1', 'value2'),
--     ('value3', 'value4');
";
    }

    /**
     * Refresh migrations (rollback all and re-run)
     *
     * @return array<string, mixed>
     * @throws Exception
     */
    public function refresh(): array
    {
        // Get all executed migrations
        $allExecuted = Database::fetchAll("SELECT migration FROM `{$this->migrationTable}` ORDER BY id DESC");

        // Rollback all
        $rollbackResults = $this->rollback(count($allExecuted));

        // Re-run all migrations
        $migrationResults = $this->migrate();

        return [
            'message' => 'Database refreshed successfully',
            'rollback_results' => $rollbackResults,
            'migration_results' => $migrationResults
        ];
    }

    /**
     * Reset migrations (drop all tables and re-run)
     * WARNING: This will destroy all data!
     *
     * @return array<string, mixed>
     * @throws Exception
     */
    public function reset(): array
    {
        if (env('APP_ENV') === 'production') {
            throw new Exception('Cannot reset database in production environment');
        }

        // Drop all tables
        $tables = Database::fetchAll("SHOW TABLES");
        $tableColumn = 'Tables_in_' . Database::getConnection()->query('SELECT DATABASE()')->fetchColumn();

        Database::exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($tables as $table) {
            $tableName = $table[$tableColumn];
            Database::exec("DROP TABLE IF EXISTS `$tableName`");
        }

        Database::exec('SET FOREIGN_KEY_CHECKS = 1');

        // Re-create migration table and run all migrations
        $this->createMigrationTable();

        return $this->migrate();
    }
}
