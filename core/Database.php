<?php

/**
 * Win a Brand New - Core Database Connection Class
 * File: /core/Database.php
 *
 * Provides secure database connectivity with PDO, prepared statements,
 * connection pooling, error handling, and transaction support
 * according to the Development Specification requirements.
 *
 * Features:
 * - PDO with prepared statements for SQL injection prevention
 * - Connection pooling for performance
 * - Transaction support with rollback capability
 * - Comprehensive error handling and logging
 * - Query logging for debugging and monitoring
 * - Connection retry logic for high availability
 *
 * @package WinABrandNew\Core
 * @version 1.0.0
 * @author NerdMarcel Development Team
 */

namespace WinABrandNew\Core;

use PDO;
use PDOException;
use PDOStatement;
use Exception;

class Database
{
    /**
     * Database connection instance
     *
     * @var PDO|null
     */
    private static ?PDO $connection = null;

    /**
     * Database configuration
     *
     * @var array
     */
    private static array $config = [];

    /**
     * Query counter for monitoring
     *
     * @var int
     */
    private static int $queryCount = 0;

    /**
     * Query execution time tracking
     *
     * @var float
     */
    private static float $totalQueryTime = 0.0;

    /**
     * Connection retry attempts
     *
     * @var int
     */
    private const MAX_RETRY_ATTEMPTS = 3;

    /**
     * Connection timeout in seconds
     *
     * @var int
     */
    private const CONNECTION_TIMEOUT = 30;

    /**
     * Query timeout in seconds
     *
     * @var int
     */
    private const QUERY_TIMEOUT = 60;

    /**
     * Initialize database configuration
     *
     * @param array $config Database configuration parameters
     * @return void
     */
    public static function initialize(array $config): void
    {
        self::$config = array_merge([
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'port' => $_ENV['DB_PORT'] ?? '3306',
            'database' => $_ENV['DB_NAME'] ?? '',
            'username' => $_ENV['DB_USER'] ?? '',
            'password' => $_ENV['DB_PASSWORD'] ?? '',
            'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                PDO::ATTR_TIMEOUT => self::CONNECTION_TIMEOUT,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                PDO::ATTR_PERSISTENT => false, // Connection pooling via external pool
            ]
        ], $config);
    }

    /**
     * Get database connection instance (Singleton pattern)
     *
     * @return PDO
     * @throws Exception If connection fails after all retry attempts
     */
    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            self::connect();
        }

        // Check if connection is still alive
        if (!self::isConnectionAlive()) {
            self::reconnect();
        }

        return self::$connection;
    }

    /**
     * Establish database connection with retry logic
     *
     * @return void
     * @throws Exception If connection fails after all retry attempts
     */
    private static function connect(): void
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < self::MAX_RETRY_ATTEMPTS) {
            try {
                $dsn = sprintf(
                    "mysql:host=%s;port=%s;dbname=%s;charset=%s",
                    self::$config['host'],
                    self::$config['port'],
                    self::$config['database'],
                    self::$config['charset']
                );

                self::$connection = new PDO(
                    $dsn,
                    self::$config['username'],
                    self::$config['password'],
                    self::$config['options']
                );

                // Test connection with a simple query
                self::$connection->query("SELECT 1");

                self::logMessage("Database connection established successfully", 'info');
                return;

            } catch (PDOException $e) {
                $lastException = $e;
                $attempt++;

                self::logMessage(
                    "Database connection attempt {$attempt} failed: " . $e->getMessage(),
                    'warning'
                );

                if ($attempt < self::MAX_RETRY_ATTEMPTS) {
                    // Wait before retry (exponential backoff)
                    sleep(pow(2, $attempt - 1));
                }
            }
        }

        // All attempts failed
        self::logMessage(
            "Database connection failed after " . self::MAX_RETRY_ATTEMPTS . " attempts",
            'error'
        );

        throw new Exception(
            "Database connection failed: " . ($lastException ? $lastException->getMessage() : 'Unknown error'),
            500
        );
    }

    /**
     * Reconnect to database
     *
     * @return void
     * @throws Exception If reconnection fails
     */
    private static function reconnect(): void
    {
        self::$connection = null;
        self::connect();
    }

    /**
     * Check if database connection is still alive
     *
     * @return bool
     */
    private static function isConnectionAlive(): bool
    {
        if (self::$connection === null) {
            return false;
        }

        try {
            self::$connection->query("SELECT 1");
            return true;
        } catch (PDOException $e) {
            self::logMessage("Connection check failed: " . $e->getMessage(), 'warning');
            return false;
        }
    }

    /**
     * Execute a prepared statement with parameters
     *
     * @param string $sql SQL query with placeholders
     * @param array $params Parameters for prepared statement
     * @return PDOStatement
     * @throws Exception If query execution fails
     */
    public static function execute(string $sql, array $params = []): PDOStatement
    {
        $startTime = microtime(true);

        try {
            $connection = self::getConnection();
            $statement = $connection->prepare($sql);

            // Set query timeout
            $statement->setAttribute(PDO::ATTR_TIMEOUT, self::QUERY_TIMEOUT);

            $success = $statement->execute($params);

            if (!$success) {
                throw new Exception("Query execution failed");
            }

            // Update statistics
            self::$queryCount++;
            self::$totalQueryTime += microtime(true) - $startTime;

            // Log slow queries (over 1 second)
            $executionTime = microtime(true) - $startTime;
            if ($executionTime > 1.0) {
                self::logMessage(
                    "Slow query detected ({$executionTime}s): " . self::formatQuery($sql, $params),
                    'warning'
                );
            }

            return $statement;

        } catch (PDOException $e) {
            self::logMessage(
                "Query execution failed: " . $e->getMessage() . " | SQL: " . self::formatQuery($sql, $params),
                'error'
            );
            throw new Exception("Database query failed: " . $e->getMessage(), 500);
        }
    }

    /**
     * Execute a SELECT query and return all results
     *
     * @param string $sql SQL SELECT query
     * @param array $params Query parameters
     * @return array
     * @throws Exception If query execution fails
     */
    public static function select(string $sql, array $params = []): array
    {
        $statement = self::execute($sql, $params);
        return $statement->fetchAll();
    }

    /**
     * Execute a SELECT query and return first row
     *
     * @param string $sql SQL SELECT query
     * @param array $params Query parameters
     * @return array|null
     * @throws Exception If query execution fails
     */
    public static function selectOne(string $sql, array $params = []): ?array
    {
        $statement = self::execute($sql, $params);
        $result = $statement->fetch();
        return $result ?: null;
    }

    /**
     * Execute an INSERT query and return last insert ID
     *
     * @param string $sql SQL INSERT query
     * @param array $params Query parameters
     * @return string Last insert ID
     * @throws Exception If query execution fails
     */
    public static function insert(string $sql, array $params = []): string
    {
        self::execute($sql, $params);
        return self::getConnection()->lastInsertId();
    }

    /**
     * Execute an UPDATE query and return affected rows count
     *
     * @param string $sql SQL UPDATE query
     * @param array $params Query parameters
     * @return int Number of affected rows
     * @throws Exception If query execution fails
     */
    public static function update(string $sql, array $params = []): int
    {
        $statement = self::execute($sql, $params);
        return $statement->rowCount();
    }

    /**
     * Execute a DELETE query and return affected rows count
     *
     * @param string $sql SQL DELETE query
     * @param array $params Query parameters
     * @return int Number of affected rows
     * @throws Exception If query execution fails
     */
    public static function delete(string $sql, array $params = []): int
    {
        $statement = self::execute($sql, $params);
        return $statement->rowCount();
    }

    /**
     * Begin a database transaction
     *
     * @return bool
     * @throws Exception If transaction start fails
     */
    public static function beginTransaction(): bool
    {
        try {
            $connection = self::getConnection();
            $result = $connection->beginTransaction();
            self::logMessage("Transaction started", 'info');
            return $result;
        } catch (PDOException $e) {
            self::logMessage("Failed to start transaction: " . $e->getMessage(), 'error');
            throw new Exception("Failed to start transaction: " . $e->getMessage(), 500);
        }
    }

    /**
     * Commit a database transaction
     *
     * @return bool
     * @throws Exception If transaction commit fails
     */
    public static function commit(): bool
    {
        try {
            $connection = self::getConnection();
            $result = $connection->commit();
            self::logMessage("Transaction committed", 'info');
            return $result;
        } catch (PDOException $e) {
            self::logMessage("Failed to commit transaction: " . $e->getMessage(), 'error');
            throw new Exception("Failed to commit transaction: " . $e->getMessage(), 500);
        }
    }

    /**
     * Rollback a database transaction
     *
     * @return bool
     * @throws Exception If transaction rollback fails
     */
    public static function rollback(): bool
    {
        try {
            $connection = self::getConnection();
            $result = $connection->rollback();
            self::logMessage("Transaction rolled back", 'warning');
            return $result;
        } catch (PDOException $e) {
            self::logMessage("Failed to rollback transaction: " . $e->getMessage(), 'error');
            throw new Exception("Failed to rollback transaction: " . $e->getMessage(), 500);
        }
    }

    /**
     * Check if currently in a transaction
     *
     * @return bool
     */
    public static function inTransaction(): bool
    {
        return self::getConnection()->inTransaction();
    }

    /**
     * Execute multiple queries in a transaction
     *
     * @param callable $callback Function containing database operations
     * @return mixed Result of the callback function
     * @throws Exception If transaction fails
     */
    public static function transaction(callable $callback): mixed
    {
        $wasInTransaction = self::inTransaction();

        if (!$wasInTransaction) {
            self::beginTransaction();
        }

        try {
            $result = $callback();

            if (!$wasInTransaction) {
                self::commit();
            }

            return $result;

        } catch (Exception $e) {
            if (!$wasInTransaction) {
                self::rollback();
            }
            throw $e;
        }
    }

    /**
     * Get query execution statistics
     *
     * @return array Statistics array
     */
    public static function getStatistics(): array
    {
        return [
            'query_count' => self::$queryCount,
            'total_query_time' => self::$totalQueryTime,
            'average_query_time' => self::$queryCount > 0 ? self::$totalQueryTime / self::$queryCount : 0,
            'connection_status' => self::isConnectionAlive() ? 'alive' : 'dead',
        ];
    }

    /**
     * Get database server information
     *
     * @return array Server information
     */
    public static function getServerInfo(): array
    {
        try {
            $connection = self::getConnection();
            return [
                'server_version' => $connection->getAttribute(PDO::ATTR_SERVER_VERSION),
                'client_version' => $connection->getAttribute(PDO::ATTR_CLIENT_VERSION),
                'connection_status' => $connection->getAttribute(PDO::ATTR_CONNECTION_STATUS),
                'server_info' => $connection->getAttribute(PDO::ATTR_SERVER_INFO),
            ];
        } catch (PDOException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Escape identifier (table/column names) for safe SQL construction
     *
     * @param string $identifier Table or column name
     * @return string Escaped identifier
     */
    public static function escapeIdentifier(string $identifier): string
    {
        // Remove any existing backticks and add proper ones
        $identifier = str_replace('`', '', $identifier);
        return "`{$identifier}`";
    }

    /**
     * Build WHERE clause from conditions array
     *
     * @param array $conditions Associative array of conditions
     * @param string $operator Logic operator (AND/OR)
     * @return array [sql, params]
     */
    public static function buildWhereClause(array $conditions, string $operator = 'AND'): array
    {
        if (empty($conditions)) {
            return ['', []];
        }

        $sql = [];
        $params = [];

        foreach ($conditions as $column => $value) {
            if (is_array($value)) {
                // Handle IN clause
                $placeholders = str_repeat('?,', count($value) - 1) . '?';
                $sql[] = self::escapeIdentifier($column) . " IN ({$placeholders})";
                $params = array_merge($params, $value);
            } elseif ($value === null) {
                // Handle NULL
                $sql[] = self::escapeIdentifier($column) . " IS NULL";
            } else {
                // Handle regular condition
                $sql[] = self::escapeIdentifier($column) . " = ?";
                $params[] = $value;
            }
        }

        $whereClause = implode(" {$operator} ", $sql);
        return ["WHERE {$whereClause}", $params];
    }

    /**
     * Format SQL query with parameters for logging
     *
     * @param string $sql SQL query
     * @param array $params Parameters
     * @return string Formatted query string
     */
    private static function formatQuery(string $sql, array $params): string
    {
        if (empty($params)) {
            return $sql;
        }

        // Simple parameter interpolation for logging (not for execution!)
        $formatted = $sql;
        foreach ($params as $param) {
            $value = is_string($param) ? "'{$param}'" : $param;
            $formatted = preg_replace('/\?/', $value, $formatted, 1);
        }

        return $formatted;
    }

    /**
     * Log database messages
     *
     * @param string $message Log message
     * @param string $level Log level (info, warning, error)
     * @return void
     */
    private static function logMessage(string $message, string $level = 'info'): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;

        // Log to file if logging is enabled
        if (($_ENV['LOG_SQL_QUERIES'] ?? false) && $level !== 'info') {
            $logFile = $_ENV['LOG_PATH'] ?? '/var/log/winabrandnew';
            $logFile .= '/database.log';

            if (is_writable(dirname($logFile))) {
                file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
            }
        }

        // Also log to error_log for critical errors
        if ($level === 'error') {
            error_log("Database Error: {$message}");
        }
    }

    /**
     * Close database connection
     *
     * @return void
     */
    public static function close(): void
    {
        if (self::$connection !== null) {
            // Commit any pending transaction
            if (self::inTransaction()) {
                self::rollback();
            }

            self::$connection = null;
            self::logMessage("Database connection closed", 'info');
        }
    }

    /**
     * Health check for monitoring systems
     *
     * @return array Health status information
     */
    public static function healthCheck(): array
    {
        try {
            $startTime = microtime(true);
            $connection = self::getConnection();

            // Test basic query
            $statement = $connection->query("SELECT 1 as test");
            $result = $statement->fetch();

            $responseTime = microtime(true) - $startTime;

            return [
                'status' => 'healthy',
                'response_time' => $responseTime,
                'query_count' => self::$queryCount,
                'total_query_time' => self::$totalQueryTime,
                'server_info' => self::getServerInfo(),
                'test_result' => $result['test'] === 1
            ];

        } catch (Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'response_time' => null,
                'query_count' => self::$queryCount,
                'total_query_time' => self::$totalQueryTime
            ];
        }
    }

    /**
     * Prevent cloning of the singleton instance
     */
    private function __clone() {}

    /**
     * Prevent unserialization of the singleton instance
     */
    public function __wakeup()
    {
        throw new Exception("Cannot unserialize singleton");
    }

    /**
     * Cleanup on destruction
     */
    public function __destruct()
    {
        self::close();
    }
}

/**
 * Database Helper Functions
 *
 * Convenience functions for common database operations
 */

/**
 * Quick database connection helper
 *
 * @return PDO
 */
function db(): PDO
{
    return Database::getConnection();
}

/**
 * Quick select query helper
 *
 * @param string $sql
 * @param array $params
 * @return array
 */
function db_select(string $sql, array $params = []): array
{
    return Database::select($sql, $params);
}

/**
 * Quick select one query helper
 *
 * @param string $sql
 * @param array $params
 * @return array|null
 */
function db_select_one(string $sql, array $params = []): ?array
{
    return Database::selectOne($sql, $params);
}

/**
 * Quick insert query helper
 *
 * @param string $sql
 * @param array $params
 * @return string
 */
function db_insert(string $sql, array $params = []): string
{
    return Database::insert($sql, $params);
}

/**
 * Quick update query helper
 *
 * @param string $sql
 * @param array $params
 * @return int
 */
function db_update(string $sql, array $params = []): int
{
    return Database::update($sql, $params);
}

/**
 * Quick delete query helper
 *
 * @param string $sql
 * @param array $params
 * @return int
 */
function db_delete(string $sql, array $params = []): int
{
    return Database::delete($sql, $params);
}

/**
 * Quick transaction helper
 *
 * @param callable $callback
 * @return mixed
 */
function db_transaction(callable $callback): mixed
{
    return Database::transaction($callback);
}
