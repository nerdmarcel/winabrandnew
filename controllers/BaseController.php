<?php

/**
 * Win a Brand New - Base Controller Class
 * File: /controllers/BaseController.php
 *
 * Provides common functionality for all controllers including:
 * - CSRF protection and validation
 * - Input validation and sanitization
 * - Session management and security
 * - JSON response formatting
 * - Authentication and authorization helpers
 * - Error handling and logging
 * - Request rate limiting
 *
 * All other controllers should extend this base class to inherit
 * security features and common functionality according to the
 * Development Specification requirements.
 *
 * @package WinABrandNew\Controllers
 * @version 1.0.0
 * @author NerdMarcel Development Team
 */

namespace WinABrandNew\Controllers;

use WinABrandNew\Core\Database;
use WinABrandNew\Core\Security;
use WinABrandNew\Core\Config;
use Exception;

abstract class BaseController
{
    /**
     * Request data (cleaned and validated)
     *
     * @var array
     */
    protected array $request = [];

    /**
     * Response data to be returned
     *
     * @var array
     */
    protected array $response = [];

    /**
     * HTTP status code for response
     *
     * @var int
     */
    protected int $statusCode = 200;

    /**
     * Session instance
     *
     * @var array
     */
    protected array $session = [];

    /**
     * Current user data (if authenticated)
     *
     * @var array|null
     */
    protected ?array $currentUser = null;

    /**
     * CSRF token for the current request
     *
     * @var string|null
     */
    protected ?string $csrfToken = null;

    /**
     * Rate limiting data
     *
     * @var array
     */
    protected array $rateLimiting = [
        'enabled' => true,
        'max_requests' => 100,
        'time_window' => 3600, // 1 hour
        'identifier' => null
    ];

    /**
     * Validation errors
     *
     * @var array
     */
    protected array $validationErrors = [];

    /**
     * Request start time for performance monitoring
     *
     * @var float
     */
    protected float $requestStartTime;

    /**
     * Constructor - Initialize base controller functionality
     */
    public function __construct()
    {
        $this->requestStartTime = microtime(true);
        $this->initializeSession();
        $this->loadRequest();
        $this->initializeSecurity();
        $this->initializeRateLimiting();
    }

    /**
     * Initialize session management
     *
     * @return void
     */
    protected function initializeSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Configure secure session settings
            ini_set('session.cookie_httponly', 1);
            ini_set('session.use_only_cookies', 1);
            ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? 1 : 0);
            ini_set('session.cookie_samesite', 'Strict');
            ini_set('session.gc_maxlifetime', Config::get('SESSION_LIFETIME', 7200));

            session_start();

            // Session fixation protection
            if (!isset($_SESSION['initialized'])) {
                session_regenerate_id(true);
                $_SESSION['initialized'] = true;
                $_SESSION['created_at'] = time();
            }

            // Session timeout check
            if (isset($_SESSION['last_activity']) &&
                (time() - $_SESSION['last_activity'] > Config::get('SESSION_LIFETIME', 7200))) {
                $this->destroySession();
                $this->jsonResponse(['error' => 'Session expired'], 401);
                exit;
            }

            $_SESSION['last_activity'] = time();
        }

        $this->session = $_SESSION ?? [];
    }

    /**
     * Load and sanitize request data
     *
     * @return void
     */
    protected function loadRequest(): void
    {
        // Get request method
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // Load request data based on method and content type
        $this->request = [
            'method' => $method,
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'ip' => $this->getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'timestamp' => time(),
            'params' => []
        ];

        // Load GET parameters
        if (!empty($_GET)) {
            $this->request['params'] = array_merge($this->request['params'], $this->sanitizeInput($_GET));
        }

        // Load POST data
        if ($method === 'POST') {
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

            if (strpos($contentType, 'application/json') !== false) {
                // Handle JSON requests
                $jsonData = json_decode(file_get_contents('php://input'), true);
                if ($jsonData !== null) {
                    $this->request['params'] = array_merge($this->request['params'], $this->sanitizeInput($jsonData));
                }
            } else {
                // Handle form data
                if (!empty($_POST)) {
                    $this->request['params'] = array_merge($this->request['params'], $this->sanitizeInput($_POST));
                }
            }
        }

        // Load file uploads
        if (!empty($_FILES)) {
            $this->request['files'] = $_FILES;
        }
    }

    /**
     * Initialize security features
     *
     * @return void
     */
    protected function initializeSecurity(): void
    {
        // Generate or validate CSRF token
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCsrfToken();
        } else {
            $this->generateCsrfToken();
        }

        // Set security headers
        $this->setSecurityHeaders();

        // Log security-relevant request information
        $this->logSecurityEvent('request_received', [
            'ip' => $this->request['ip'],
            'user_agent' => $this->request['user_agent'],
            'uri' => $this->request['uri'],
            'method' => $this->request['method']
        ]);
    }

    /**
     * Initialize rate limiting
     *
     * @return void
     */
    protected function initializeRateLimiting(): void
    {
        if (!$this->rateLimiting['enabled']) {
            return;
        }

        $identifier = $this->rateLimiting['identifier'] ?? $this->request['ip'];
        $cacheKey = "rate_limit:{$identifier}";

        // Check current request count
        $requestCount = $this->getCacheValue($cacheKey, 0);

        if ($requestCount >= $this->rateLimiting['max_requests']) {
            $this->logSecurityEvent('rate_limit_exceeded', [
                'identifier' => $identifier,
                'requests' => $requestCount,
                'limit' => $this->rateLimiting['max_requests']
            ]);

            $this->jsonResponse([
                'error' => 'Rate limit exceeded',
                'retry_after' => $this->rateLimiting['time_window']
            ], 429);
            exit;
        }

        // Increment request counter
        $this->setCacheValue($cacheKey, $requestCount + 1, $this->rateLimiting['time_window']);
    }

    /**
     * Generate CSRF token
     *
     * @return string
     */
    protected function generateCsrfToken(): string
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        $this->csrfToken = $_SESSION['csrf_token'];
        return $this->csrfToken;
    }

    /**
     * Validate CSRF token
     *
     * @return void
     * @throws Exception If CSRF validation fails
     */
    protected function validateCsrfToken(): void
    {
        $submittedToken = $this->request['params']['csrf_token'] ??
                         $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

        if (empty($submittedToken) || !isset($_SESSION['csrf_token'])) {
            $this->logSecurityEvent('csrf_token_missing', [
                'ip' => $this->request['ip'],
                'uri' => $this->request['uri']
            ]);

            $this->jsonResponse(['error' => 'CSRF token missing'], 403);
            exit;
        }

        if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
            $this->logSecurityEvent('csrf_token_invalid', [
                'ip' => $this->request['ip'],
                'uri' => $this->request['uri'],
                'submitted_token' => substr($submittedToken, 0, 8) . '...'
            ]);

            $this->jsonResponse(['error' => 'CSRF token invalid'], 403);
            exit;
        }

        $this->csrfToken = $_SESSION['csrf_token'];
    }

    /**
     * Set security headers
     *
     * @return void
     */
    protected function setSecurityHeaders(): void
    {
        // Prevent XSS attacks
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Content Security Policy
        $csp = "default-src 'self'; " .
               "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; " .
               "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; " .
               "img-src 'self' data: https:; " .
               "font-src 'self' https://cdnjs.cloudflare.com; " .
               "connect-src 'self'; " .
               "frame-ancestors 'none';";

        header("Content-Security-Policy: {$csp}");

        // HSTS for HTTPS connections
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
    }

    /**
     * Sanitize input data
     *
     * @param mixed $data Input data to sanitize
     * @return mixed Sanitized data
     */
    protected function sanitizeInput($data)
    {
        if (is_array($data)) {
            return array_map([$this, 'sanitizeInput'], $data);
        }

        if (is_string($data)) {
            // Remove null bytes
            $data = str_replace("\0", '', $data);

            // Trim whitespace
            $data = trim($data);

            // Convert special characters to HTML entities for XSS protection
            $data = htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $data;
    }

    /**
     * Validate input against rules
     *
     * @param array $data Data to validate
     * @param array $rules Validation rules
     * @return bool True if validation passes
     */
    protected function validateInput(array $data, array $rules): bool
    {
        $this->validationErrors = [];

        foreach ($rules as $field => $ruleSet) {
            $value = $data[$field] ?? null;
            $fieldRules = explode('|', $ruleSet);

            foreach ($fieldRules as $rule) {
                if (strpos($rule, ':') !== false) {
                    [$ruleName, $ruleValue] = explode(':', $rule, 2);
                } else {
                    $ruleName = $rule;
                    $ruleValue = null;
                }

                if (!$this->applyValidationRule($field, $value, $ruleName, $ruleValue)) {
                    break; // Stop on first validation failure for this field
                }
            }
        }

        return empty($this->validationErrors);
    }

    /**
     * Apply a single validation rule
     *
     * @param string $field Field name
     * @param mixed $value Field value
     * @param string $rule Rule name
     * @param mixed $ruleValue Rule parameter
     * @return bool True if validation passes
     */
    protected function applyValidationRule(string $field, $value, string $rule, $ruleValue = null): bool
    {
        switch ($rule) {
            case 'required':
                if (empty($value) && $value !== '0') {
                    $this->validationErrors[$field] = "The {$field} field is required.";
                    return false;
                }
                break;

            case 'email':
                if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->validationErrors[$field] = "The {$field} field must be a valid email address.";
                    return false;
                }
                break;

            case 'min':
                if (!empty($value) && strlen($value) < (int)$ruleValue) {
                    $this->validationErrors[$field] = "The {$field} field must be at least {$ruleValue} characters.";
                    return false;
                }
                break;

            case 'max':
                if (!empty($value) && strlen($value) > (int)$ruleValue) {
                    $this->validationErrors[$field] = "The {$field} field may not be greater than {$ruleValue} characters.";
                    return false;
                }
                break;

            case 'numeric':
                if (!empty($value) && !is_numeric($value)) {
                    $this->validationErrors[$field] = "The {$field} field must be numeric.";
                    return false;
                }
                break;

            case 'phone':
                if (!empty($value) && !preg_match('/^[\+]?[1-9][\d]{0,15}$/', $value)) {
                    $this->validationErrors[$field] = "The {$field} field must be a valid phone number.";
                    return false;
                }
                break;

            case 'alpha_numeric':
                if (!empty($value) && !preg_match('/^[a-zA-Z0-9]+$/', $value)) {
                    $this->validationErrors[$field] = "The {$field} field may only contain letters and numbers.";
                    return false;
                }
                break;
        }

        return true;
    }

    /**
     * Get validation errors
     *
     * @return array
     */
    protected function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Send JSON response
     *
     * @param array $data Response data
     * @param int $statusCode HTTP status code
     * @param array $headers Additional headers
     * @return void
     */
    protected function jsonResponse(array $data = [], int $statusCode = 200, array $headers = []): void
    {
        // Set response code
        http_response_code($statusCode);

        // Set content type
        header('Content-Type: application/json; charset=utf-8');

        // Set additional headers
        foreach ($headers as $name => $value) {
            header("{$name}: {$value}");
        }

        // Add execution time for monitoring
        $executionTime = microtime(true) - $this->requestStartTime;
        $data['_meta'] = [
            'execution_time' => round($executionTime, 4),
            'timestamp' => date('c'),
            'status' => $statusCode
        ];

        // Include CSRF token in response for frontend
        if ($this->csrfToken) {
            $data['_meta']['csrf_token'] = $this->csrfToken;
        }

        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Redirect to URL
     *
     * @param string $url Redirect URL
     * @param int $statusCode HTTP status code
     * @return void
     */
    protected function redirect(string $url, int $statusCode = 302): void
    {
        header("Location: {$url}", true, $statusCode);
        exit;
    }

    /**
     * Get client IP address
     *
     * @return string Client IP address
     */
    protected function getClientIp(): string
    {
        $ipHeaders = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_CLIENT_IP',            // Proxy
            'HTTP_X_FORWARDED_FOR',      // Load balancer/proxy
            'HTTP_X_FORWARDED',          // Proxy
            'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster
            'HTTP_FORWARDED_FOR',        // Proxy
            'HTTP_FORWARDED',            // Proxy
            'REMOTE_ADDR'                // Standard
        ];

        foreach ($ipHeaders as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);

                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Get user session data
     *
     * @param string|null $key Session key (null for all data)
     * @return mixed Session data
     */
    protected function getSession(?string $key = null)
    {
        if ($key === null) {
            return $this->session;
        }

        return $this->session[$key] ?? null;
    }

    /**
     * Set user session data
     *
     * @param string $key Session key
     * @param mixed $value Session value
     * @return void
     */
    protected function setSession(string $key, $value): void
    {
        $_SESSION[$key] = $value;
        $this->session[$key] = $value;
    }

    /**
     * Destroy session
     *
     * @return void
     */
    protected function destroySession(): void
    {
        $_SESSION = [];
        $this->session = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }

        session_destroy();
    }

    /**
     * Log security event
     *
     * @param string $event Event type
     * @param array $data Event data
     * @return void
     */
    protected function logSecurityEvent(string $event, array $data = []): void
    {
        $logData = [
            'event' => $event,
            'timestamp' => date('c'),
            'ip' => $this->request['ip'] ?? '0.0.0.0',
            'user_agent' => $this->request['user_agent'] ?? '',
            'session_id' => session_id(),
            'data' => $data
        ];

        $logMessage = json_encode($logData) . PHP_EOL;
        $logFile = Config::get('LOG_PATH', '/var/log/winabrandnew') . '/security.log';

        if (is_writable(dirname($logFile))) {
            file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * Get cache value (simplified implementation)
     *
     * @param string $key Cache key
     * @param mixed $default Default value
     * @return mixed Cached value or default
     */
    protected function getCacheValue(string $key, $default = null)
    {
        // Simple file-based cache implementation
        $cacheFile = sys_get_temp_dir() . '/' . md5($key) . '.cache';

        if (file_exists($cacheFile)) {
            $data = unserialize(file_get_contents($cacheFile));
            if ($data['expires'] > time()) {
                return $data['value'];
            } else {
                unlink($cacheFile);
            }
        }

        return $default;
    }

    /**
     * Set cache value (simplified implementation)
     *
     * @param string $key Cache key
     * @param mixed $value Cache value
     * @param int $ttl Time to live in seconds
     * @return void
     */
    protected function setCacheValue(string $key, $value, int $ttl = 3600): void
    {
        $cacheFile = sys_get_temp_dir() . '/' . md5($key) . '.cache';
        $data = [
            'value' => $value,
            'expires' => time() + $ttl
        ];

        file_put_contents($cacheFile, serialize($data), LOCK_EX);
    }

    /**
     * Check if request is AJAX
     *
     * @return bool
     */
    protected function isAjax(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    /**
     * Check if request is POST
     *
     * @return bool
     */
    protected function isPost(): bool
    {
        return $this->request['method'] === 'POST';
    }

    /**
     * Check if request is GET
     *
     * @return bool
     */
    protected function isGet(): bool
    {
        return $this->request['method'] === 'GET';
    }

    /**
     * Get request parameter
     *
     * @param string $key Parameter key
     * @param mixed $default Default value
     * @return mixed Parameter value
     */
    protected function getParam(string $key, $default = null)
    {
        return $this->request['params'][$key] ?? $default;
    }

    /**
     * Check if parameter exists
     *
     * @param string $key Parameter key
     * @return bool
     */
    protected function hasParam(string $key): bool
    {
        return isset($this->request['params'][$key]);
    }

    /**
     * Get all request parameters
     *
     * @return array
     */
    protected function getParams(): array
    {
        return $this->request['params'];
    }

    /**
     * Require authentication for this controller action
     *
     * @return void
     */
    protected function requireAuth(): void
    {
        if (!$this->isAuthenticated()) {
            $this->jsonResponse(['error' => 'Authentication required'], 401);
            exit;
        }
    }

    /**
     * Check if user is authenticated
     *
     * @return bool
     */
    protected function isAuthenticated(): bool
    {
        return !empty($this->getSession('user_id'));
    }

    /**
     * Get current authenticated user data
     *
     * @return array|null
     */
    protected function getCurrentUser(): ?array
    {
        if ($this->currentUser === null && $this->isAuthenticated()) {
            $userId = $this->getSession('user_id');
            // This would typically load user data from database
            // For now, return session data
            $this->currentUser = [
                'id' => $userId,
                'email' => $this->getSession('user_email'),
                'name' => $this->getSession('user_name')
            ];
        }

        return $this->currentUser;
    }

    /**
     * Handle errors and exceptions
     *
     * @param Exception $e Exception to handle
     * @return void
     */
    protected function handleError(Exception $e): void
    {
        $errorData = [
            'error' => 'Internal server error',
            'message' => Config::get('APP_DEBUG', false) ? $e->getMessage() : 'An error occurred'
        ];

        // Log the error
        error_log("Controller Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());

        $this->jsonResponse($errorData, 500);
    }

    /**
     * Clean up resources on destruction
     */
    public function __destruct()
    {
        // Log request completion time if needed
        if (Config::get('PERFORMANCE_MONITORING_ENABLED', false)) {
            $executionTime = microtime(true) - $this->requestStartTime;
            if ($executionTime > 2.0) { // Log slow requests over 2 seconds
                error_log("Slow request: {$this->request['uri']} took {$executionTime}s");
            }
        }
    }
}
