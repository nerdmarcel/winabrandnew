<?php
declare(strict_types=1);

/**
 * File: core/Database.php
 * Location: core/Database.php
 *
 * WinABN Database Configuration and Connection Management
 *
 * Provides secure, optimized database connections with connection pooling,
 * transaction management, and performance monitoring for the WinABN platform.
 *
 * @package WinABN\Core
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Core;

use PDO;
use PDOException;
use PDOStatement;
use Exception;

class Database
{
    /**
     * PDO connection instance
     *
     * @var PDO|null
     */
    private static ?PDO $connection = null;

    /**
     * Database configuration
     *
     * @var array<string, mixed>
     */
    private static array $config = [];

    /**
     * Connection pool for multiple connections
     *
     * @var array<PDO>
     */
    private static array $connectionPool = [];

    /**
     * Active transaction count for nested transactions
     *
     * @var int
     */
    private static int $transactionCount = 0;

    /**
     * Query performance monitoring
     *
     * @var array<array>
     */
    private static array $queryLog = [];

    /**
     * Enable query logging
     *
     * @var bool
     */
    private static bool $enableQueryLogging = false;

    /**
     * Initialize database configuration
     *
     * @param array<string, mixed>|null $config Custom configuration
     * @return void
     */
    public static function init(?array $config = null): void
    {
        if ($config === null) {
            self::$config = [
                'host' => env('DB_HOST', 'localhost'),
                'port' => (int) env('DB_PORT', 3306),
                'database' => env('DB_NAME', 'winabn_db'),
                'username' => env('DB_USER', 'winabn_user'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => env('DB_CHARSET', 'utf8mb4'),
                'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
                'options' => [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                    PDO::ATTR_TIMEOUT => (int) env('DB_CONNECTION_TIMEOUT', 30),
                    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                    PDO::ATTR_PERSISTENT => false, // Disable persistent connections for better control
                ]
            ];
        } else {
            self::$config = array_merge(self::$config, $config);
        }

        self::$enableQueryLogging = env('APP_DEBUG', false) === true;
    }

    /**
     * Get database connection
     *
     * @param bool $forceNew Force new connection
     * @return PDO
     * @throws Exception
     */
    public static function getConnection(bool $forceNew = false): PDO
    {
        if (empty(self::$config)) {
            self::init();
        }

        if (self::$connection === null || $forceNew) {
            self::$connection = self::createConnection();
        }

        return self::$connection;
    }

    /**
     * Create new database connection
     *
     * @return PDO
     * @throws Exception
     */
    private static function createConnection(): PDO
    {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                self::$config['host'],
                self::$config['port'],
                self::$config['database'],
                self::$config['charset']
            );

            $connection = new PDO(
                $dsn,
                self::$config['username'],
                self::$config['password'],
                self::$config['options']
            );

            // Set SQL mode for strict data integrity
            $connection->exec("SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION'");

            // Set timezone to UTC for consistent data storage
            $connection->exec("SET time_zone = '+00:00'");

            // Optimize for read-heavy workloads
            $connection->exec("SET SESSION transaction_isolation = 'READ-COMMITTED'");

            return $connection;

        } catch (PDOException $e) {
            self::logError('Database connection failed', [
                'error' => $e->getMessage(),
                'host' => self::$config['host'],
                'database' => self::$config['database']
            ]);

            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }

    /**
     * Execute prepared statement with parameters
     *
     * @param string $query SQL query with placeholders
     * @param array<mixed> $params Parameters for the query
     * @return PDOStatement
     * @throws Exception
     */
    public static function execute(string $query, array $params = []): PDOStatement
    {
        $startTime = microtime(true);

        try {
            $connection = self::getConnection();
            $statement = $connection->prepare($query);
            $statement->execute($params);

            if (self::$enableQueryLogging) {
                self::logQuery($query, $params, microtime(true) - $startTime);
            }

            return $statement;

        } catch (PDOException $e) {
            self::logError('Query execution failed', [
                'query' => $query,
                'params' => $params,
                'error' => $e->getMessage()
            ]);

            throw new Exception("Query execution failed: " . $e->getMessage());
        }
    }

    /**
     * Fetch single row
     *
     * @param string $query SQL query
     * @param array<mixed> $params Query parameters
     * @return array<string, mixed>|null
     */
    public static function fetchOne(string $query, array $params = []): ?array
    {
        $statement = self::execute($query, $params);
        $result = $statement->fetch();

        return $result === false ? null : $result;
    }

    /**
     * Fetch all rows
     *
     * @param string $query SQL query
     * @param array<mixed> $params Query parameters
     * @return array<array<string, mixed>>
     */
    public static function fetchAll(string $query, array $params = []): array
    {
        $statement = self::execute($query, $params);
        return $statement->fetchAll();
    }

    /**
     * Fetch single column value
     *
     * @param string $query SQL query
     * @param array<mixed> $params Query parameters
     * @return mixed
     */
    public static function fetchColumn(string $query, array $params = [])
    {
        $statement = self::execute($query, $params);
        return $statement->fetchColumn();
    }

    /**
     * Get last inserted ID
     *
     * @param string|null $name Sequence name (optional)
     * @return int
     */
    public static function lastInsertId(?string $name = null): int
    {
        return (int) self::getConnection()->lastInsertId($name);
    }

    /**
     * Begin database transaction
     *
     * @return bool
     * @throws Exception
     */
    public static function beginTransaction(): bool
    {
        $connection = self::getConnection();

        if (self::$transactionCount === 0) {
            $result = $connection->beginTransaction();
            if ($result) {
                self::$transactionCount++;
            }
            return $result;
        } else {
            // Nested transaction - use savepoint
            $savepoint = 'sp_' . self::$transactionCount;
            $connection->exec("SAVEPOINT $savepoint");
            self::$transactionCount++;
            return true;
        }
    }

    /**
     * Commit database transaction
     *
     * @return bool
     * @throws Exception
     */
    public static function commit(): bool
    {
        $connection = self::getConnection();

        if (self::$transactionCount === 1) {
            $result = $connection->commit();
            if ($result) {
                self::$transactionCount = 0;
            }
            return $result;
        } else if (self::$transactionCount > 1) {
            // Nested transaction - release savepoint
            self::$transactionCount--;
            $savepoint = 'sp_' . self::$transactionCount;
            $connection->exec("RELEASE SAVEPOINT $savepoint");
            return true;
        }

        return false;
    }

    /**
     * Rollback database transaction
     *
     * @return bool
     * @throws Exception
     */
    public static function rollback(): bool
    {
        $connection = self::getConnection();

        if (self::$transactionCount === 1) {
            $result = $connection->rollback();
            self::$transactionCount = 0;
            return $result;
        } else if (self::$transactionCount > 1) {
            // Nested transaction - rollback to savepoint
            self::$transactionCount--;
            $savepoint = 'sp_' . self::$transactionCount;
            $connection->exec("ROLLBACK TO SAVEPOINT $savepoint");
            return true;
        }

        return false;
    }

    /**
     * Execute transaction with callback
     *
     * @param callable $callback Transaction callback
     * @return mixed Return value from callback
     * @throws Exception
     */
    public static function transaction(callable $callback)
    {
        self::beginTransaction();

        try {
            $result = $callback();
            self::commit();
            return $result;
        } catch (Exception $e) {
            self::rollback();
            throw $e;
        }
    }

    /**
     * Check if currently in transaction
     *
     * @return bool
     */
    public static function inTransaction(): bool
    {
        return self::$transactionCount > 0;
    }

    /**
     * Execute raw SQL query (use with caution)
     *
     * @param string $query Raw SQL query
     * @return int Number of affected rows
     * @throws Exception
     */
    public static function exec(string $query): int
    {
        try {
            $connection = self::getConnection();
            return $connection->exec($query);
        } catch (PDOException $e) {
            self::logError('Raw query execution failed', [
                'query' => $query,
                'error' => $e->getMessage()
            ]);

            throw new Exception("Raw query execution failed: " . $e->getMessage());
        }
    }

    /**
     * Check database connection health
     *
     * @return bool
     */
    public static function healthCheck(): bool
    {
        try {
            $result = self::fetchColumn("SELECT 1");
            return $result === 1;
        } catch (Exception $e) {
            self::logError('Database health check failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get database connection info
     *
     * @return array<string, mixed>
     */
    public static function getConnectionInfo(): array
    {
        try {
            $connection = self::getConnection();
            return [
                'server_version' => $connection->getAttribute(PDO::ATTR_SERVER_VERSION),
                'connection_status' => $connection->getAttribute(PDO::ATTR_CONNECTION_STATUS),
                'autocommit' => $connection->getAttribute(PDO::ATTR_AUTOCOMMIT),
                'in_transaction' => self::inTransaction(),
                'transaction_count' => self::$transactionCount
            ];
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get query performance statistics
     *
     * @return array<string, mixed>
     */
    public static function getQueryStats(): array
    {
        if (empty(self::$queryLog)) {
            return ['total_queries' => 0];
        }

        $totalQueries = count(self::$queryLog);
        $totalTime = array_sum(array_column(self::$queryLog, 'execution_time'));
        $avgTime = $totalTime / $totalQueries;
        $slowestQuery = max(self::$queryLog, function($a, $b) {
            return $a['execution_time'] <=> $b['execution_time'];
        });

        return [
            'total_queries' => $totalQueries,
            'total_execution_time' => round($totalTime, 4),
            'average_execution_time' => round($avgTime, 4),
            'slowest_query' => [
                'query' => $slowestQuery['query'],
                'time' => round($slowestQuery['execution_time'], 4)
            ]
        ];
    }

    /**
     * Clear query log
     *
     * @return void
     */
    public static function clearQueryLog(): void
    {
        self::$queryLog = [];
    }

    /**
     * Close database connection
     *
     * @return void
     */
    public static function closeConnection(): void
    {
        self::$connection = null;
        self::$transactionCount = 0;
        self::$connectionPool = [];
    }

    /**
     * Log database query for debugging
     *
     * @param string $query SQL query
     * @param array<mixed> $params Query parameters
     * @param float $executionTime Execution time in seconds
     * @return void
     */
    private static function logQuery(string $query, array $params, float $executionTime): void
    {
        self::$queryLog[] = [
            'query' => $query,
            'params' => $params,
            'execution_time' => $executionTime,
            'timestamp' => microtime(true)
        ];

        // Keep only last 100 queries in memory
        if (count(self::$queryLog) > 100) {
            array_shift(self::$queryLog);
        }

        // Log slow queries
        if ($executionTime > 1.0) { // Queries slower than 1 second
            self::logError('Slow query detected', [
                'query' => $query,
                'params' => $params,
                'execution_time' => $executionTime
            ]);
        }
    }

    /**
     * Log database errors
     *
     * @param string $message Error message
     * @param array<string, mixed> $context Error context
     * @return void
     */
    private static function logError(string $message, array $context = []): void
    {
        if (function_exists('app_log')) {
            app_log('error', $message, $context);
        } else {
            error_log("Database Error: $message " . json_encode($context));
        }
    }

    /**
     * Prevent cloning
     *
     * @return void
     */
    private function __clone() {}

    /**
     * Prevent unserialization
     *
     * @return void
     */
    public function __wakeup() {}
}
