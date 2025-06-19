<?php
declare(strict_types=1);

/**
 * File: public/bootstrap.php
 * Location: public/bootstrap.php
 *
 * WinABN Application Bootstrap
 *
 * Initializes the application environment, loads dependencies,
 * and sets up the core services for the WinABN platform.
 *
 * @package WinABN
 * @author WinABN Development Team
 * @version 1.0
 */

// Define application constants
define('WINABN_START_TIME', microtime(true));
define('WINABN_ROOT_DIR', dirname(__DIR__));
define('WINABN_PUBLIC_DIR', __DIR__);
define('WINABN_CORE_DIR', WINABN_ROOT_DIR . '/core');
define('WINABN_CONFIG_DIR', WINABN_ROOT_DIR . '/config');
define('WINABN_LOGS_DIR', WINABN_ROOT_DIR . '/logs');

// Error reporting for development
if (!defined('WINABN_ENV')) {
    define('WINABN_ENV', 'development');
}

if (WINABN_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    error_reporting(E_ERROR | E_WARNING | E_PARSE);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', WINABN_LOGS_DIR . '/php_errors.log');
}

// Set timezone
date_default_timezone_set('Europe/London');

// Include Composer autoloader if available
$composerAutoloader = WINABN_ROOT_DIR . '/vendor/autoload.php';
if (file_exists($composerAutoloader)) {
    require_once $composerAutoloader;
    define('WINABN_COMPOSER_LOADED', true);
} else {
    define('WINABN_COMPOSER_LOADED', false);
    // Fallback to custom autoloader
    require_once WINABN_CORE_DIR . '/Autoloader.php';
}

// Load environment variables
$envFile = WINABN_ROOT_DIR . '/.env';
if (file_exists($envFile)) {
    if (WINABN_COMPOSER_LOADED && class_exists('\Dotenv\Dotenv')) {
        $dotenv = \Dotenv\Dotenv::createImmutable(WINABN_ROOT_DIR);
        $dotenv->load();
    } else {
        // Fallback simple .env loader
        loadEnvironmentVariables($envFile);
    }
}

use WinABN\Core\{Config, Database, Session, Security, Router};

/**
 * Simple .env file loader fallback
 *
 * @param string $filePath Path to .env file
 * @return void
 */
function loadEnvironmentVariables(string $filePath): void
{
    if (!file_exists($filePath)) {
        return;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        if (strpos($line, '#') === 0) {
            continue; // Skip comments
        }

        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, '"\'');

            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}

/**
 * Get environment variable with default value
 *
 * @param string $key Environment variable key
 * @param mixed $default Default value if not found
 * @return mixed
 */
function env(string $key, $default = null)
{
    $value = $_ENV[$key] ?? getenv($key);

    if ($value === false) {
        return $default;
    }

    // Convert string representations to proper types
    switch (strtolower($value)) {
        case 'true':
        case '(true)':
            return true;
        case 'false':
        case '(false)':
            return false;
        case 'empty':
        case '(empty)':
            return '';
        case 'null':
        case '(null)':
            return null;
    }

    // Remove quotes if present
    if (strlen($value) > 1 &&
        (($value[0] === '"' && $value[-1] === '"') ||
         ($value[0] === "'" && $value[-1] === "'"))) {
        return substr($value, 1, -1);
    }

    return $value;
}

/**
 * Application helper functions
 */

/**
 * Get application configuration
 *
 * @param string|null $key Configuration key (dot notation supported)
 * @param mixed $default Default value
 * @return mixed
 */
function config(?string $key = null, $default = null)
{
    static $config = null;

    if ($config === null) {
        $config = new Config();
    }

    return $key ? $config->get($key, $default) : $config;
}

/**
 * Get database instance
 *
 * @return Database
 */
function db(): Database
{
    static $database = null;

    if ($database === null) {
        $database = new Database();
    }

    return $database;
}

/**
 * Get current session instance
 *
 * @return Session
 */
function session(): Session
{
    static $session = null;

    if ($session === null) {
        $session = new Session();
    }

    return $session;
}

/**
 * Generate CSRF token
 *
 * @return string
 */
function csrf_token(): string
{
    return Security::generateCsrfToken();
}

/**
 * Verify CSRF token
 *
 * @param string $token Token to verify
 * @return bool
 */
function csrf_verify(string $token): bool
{
    return Security::verifyCsrfToken($token);
}

/**
 * Escape output for XSS prevention
 *
 * @param mixed $value Value to escape
 * @param string $encoding Character encoding
 * @return string
 */
function e($value, string $encoding = 'UTF-8'): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, $encoding, false);
}

/**
 * Generate application URL
 *
 * @param string $path URL path
 * @param array $params Query parameters
 * @return string
 */
function url(string $path = '', array $params = []): string
{
    $baseUrl = rtrim(env('APP_URL', 'http://localhost'), '/');
    $path = ltrim($path, '/');
    $url = $baseUrl . '/' . $path;

    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    return $url;
}

/**
 * Redirect to URL
 *
 * @param string $url Target URL
 * @param int $statusCode HTTP status code
 * @return never
 */
function redirect(string $url, int $statusCode = 302): never
{
    header("Location: $url", true, $statusCode);
    exit;
}

/**
 * Return JSON response
 *
 * @param mixed $data Response data
 * @param int $statusCode HTTP status code
 * @param array $headers Additional headers
 * @return never
 */
function json_response($data, int $statusCode = 200, array $headers = []): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json');

    foreach ($headers as $key => $value) {
        header("$key: $value");
    }

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Log message to application log
 *
 * @param string $level Log level (error, warning, info, debug)
 * @param string $message Log message
 * @param array $context Additional context
 * @return void
 */
function app_log(string $level, string $message, array $context = []): void
{
    $logFile = WINABN_LOGS_DIR . '/app.log';
    $timestamp = date('Y-m-d H:i:s');
    $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
    $logLine = "[$timestamp] $level: $message$contextStr" . PHP_EOL;

    // Ensure logs directory exists
    if (!is_dir(WINABN_LOGS_DIR)) {
        mkdir(WINABN_LOGS_DIR, 0755, true);
    }

    file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
}

/**
 * Check if application is in debug mode
 *
 * @return bool
 */
function is_debug(): bool
{
    return env('APP_DEBUG', false) === true;
}

/**
 * Get current request method
 *
 * @return string
 */
function request_method(): string
{
    return $_SERVER['REQUEST_METHOD'] ?? 'GET';
}

/**
 * Get current request URI
 *
 * @return string
 */
function request_uri(): string
{
    return $_SERVER['REQUEST_URI'] ?? '/';
}

/**
 * Get client IP address
 *
 * @return string
 */
function client_ip(): string
{
    $headers = [
        'HTTP_CF_CONNECTING_IP',     // Cloudflare
        'HTTP_CLIENT_IP',            // Proxy
        'HTTP_X_FORWARDED_FOR',      // Load balancer/proxy
        'HTTP_X_FORWARDED',          // Proxy
        'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster
        'HTTP_FORWARDED_FOR',        // Proxy
        'HTTP_FORWARDED',            // Proxy
        'REMOTE_ADDR'                // Standard
    ];

    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ips = explode(',', $_SERVER[$header]);
            $ip = trim($ips[0]);

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }

    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

/**
 * Generate unique ID
 *
 * @param int $length ID length
 * @return string
 */
function unique_id(int $length = 32): string
{
    return bin2hex(random_bytes($length / 2));
}

// Initialize error handler for uncaught exceptions
set_exception_handler(function (Throwable $exception) {
    app_log('error', 'Uncaught exception: ' . $exception->getMessage(), [
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString()
    ]);

    if (is_debug()) {
        echo '<pre>' . $exception . '</pre>';
    } else {
        http_response_code(500);
        echo 'An error occurred. Please try again later.';
    }

    exit(1);
});

// Initialize shutdown handler for fatal errors
register_shutdown_function(function () {
    $error = error_get_last();

    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        app_log('error', 'Fatal error: ' . $error['message'], [
            'file' => $error['file'],
            'line' => $error['line']
        ]);
    }
});

// Set security headers
if (!is_debug() && !defined('WINABN_SKIP_HEADERS')) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    if (env('APP_URL') && strpos(env('APP_URL'), 'https://') === 0) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}
