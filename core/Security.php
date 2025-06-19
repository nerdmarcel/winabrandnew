<?php

/**
 * Win a Brand New - Core Security Helper Class
 * File: /core/Security.php
 *
 * Provides comprehensive security functions including CSRF protection,
 * password hashing, XSS protection, and session management according
 * to the Development Specification requirements.
 *
 * Features:
 * - CSRF protection with secure token generation and validation
 * - Argon2ID password hashing for maximum security
 * - XSS protection with htmlspecialchars() sanitization
 * - Session fixation protection and secure session management
 * - Rate limiting for login attempts and sensitive operations
 * - Input validation and sanitization helpers
 * - Secure random token generation
 *
 * Security Requirements:
 * - Password hashing: password_hash() with PASSWORD_ARGON2ID
 * - CSRF protection on all forms
 * - SQL injection prevention via prepared statements (handled in Database class)
 * - XSS protection via htmlspecialchars()
 * - Session fixation protection
 * - Rate limiting on admin login (5 attempts per 15 minutes)
 *
 * @package WinABrandNew\Core
 * @version 1.0.0
 * @author NerdMarcel Development Team
 */

namespace WinABrandNew\Core;

use Exception;
use InvalidArgumentException;

class Security
{
    /**
     * CSRF token lifetime in seconds (default: 1 hour)
     */
    private const CSRF_TOKEN_LIFETIME = 3600;

    /**
     * Maximum CSRF tokens per session
     */
    private const MAX_CSRF_TOKENS = 10;

    /**
     * Rate limiting configuration
     */
    private const DEFAULT_RATE_LIMIT_ATTEMPTS = 5;
    private const DEFAULT_RATE_LIMIT_WINDOW = 900; // 15 minutes

    /**
     * Session security configuration
     */
    private const SESSION_TIMEOUT = 7200; // 2 hours
    private const SESSION_REGENERATE_INTERVAL = 300; // 5 minutes

    /**
     * Password strength requirements
     */
    private const MIN_PASSWORD_LENGTH = 8;
    private const MAX_PASSWORD_LENGTH = 128;

    /**
     * Initialize security configurations
     *
     * @return void
     */
    public static function initialize(): void
    {
        // Configure session security settings
        self::configureSession();

        // Start secure session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            self::startSecureSession();
        }

        // Initialize CSRF protection
        self::initializeCSRF();

        // Set security headers
        self::setSecurityHeaders();
    }

    /**
     * Configure secure session settings
     *
     * @return void
     */
    private static function configureSession(): void
    {
        // Session configuration
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? '1' : '0');
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', self::SESSION_TIMEOUT);

        // Prevent session fixation
        ini_set('session.use_trans_sid', '0');
        ini_set('session.use_only_cookies', '1');

        // Strong session ID generation
        ini_set('session.hash_function', 'sha256');
        ini_set('session.hash_bits_per_character', '6');
        ini_set('session.entropy_length', '256');
    }

    /**
     * Start secure session with additional protection
     *
     * @return bool
     */
    public static function startSecureSession(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }

        // Generate secure session name
        $sessionName = 'WABNSESSID_' . substr(hash('sha256', $_SERVER['HTTP_HOST'] ?? 'localhost'), 0, 8);
        session_name($sessionName);

        $started = session_start();

        if ($started) {
            // Initialize session security
            self::validateSession();
            self::regenerateSessionIfNeeded();
        }

        return $started;
    }

    /**
     * Validate session security and detect hijacking attempts
     *
     * @return void
     */
    private static function validateSession(): void
    {
        $currentIP = $_SERVER['REMOTE_ADDR'] ?? '';
        $currentUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Check for session hijacking
        if (isset($_SESSION['security_ip']) && $_SESSION['security_ip'] !== $currentIP) {
            self::destroySession();
            throw new Exception('Session hijacking detected - IP mismatch', 403);
        }

        if (isset($_SESSION['security_user_agent']) && $_SESSION['security_user_agent'] !== $currentUserAgent) {
            self::destroySession();
            throw new Exception('Session hijacking detected - User Agent mismatch', 403);
        }

        // Check session timeout
        if (isset($_SESSION['security_last_activity'])) {
            if (time() - $_SESSION['security_last_activity'] > self::SESSION_TIMEOUT) {
                self::destroySession();
                throw new Exception('Session expired', 401);
            }
        }

        // Set/update security markers
        $_SESSION['security_ip'] = $currentIP;
        $_SESSION['security_user_agent'] = $currentUserAgent;
        $_SESSION['security_last_activity'] = time();
    }

    /**
     * Regenerate session ID if needed (protection against session fixation)
     *
     * @return void
     */
    private static function regenerateSessionIfNeeded(): void
    {
        $lastRegeneration = $_SESSION['security_last_regeneration'] ?? 0;

        if (time() - $lastRegeneration > self::SESSION_REGENERATE_INTERVAL) {
            session_regenerate_id(true);
            $_SESSION['security_last_regeneration'] = time();
        }
    }

    /**
     * Destroy session securely
     *
     * @return void
     */
    public static function destroySession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];

            // Delete session cookie
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params['path'],
                    $params['domain'],
                    $params['secure'],
                    $params['httponly']
                );
            }

            session_destroy();
        }
    }

    /**
     * Set security headers to prevent common attacks
     *
     * @return void
     */
    public static function setSecurityHeaders(): void
    {
        if (!headers_sent()) {
            // Prevent clickjacking
            header('X-Frame-Options: DENY');

            // Prevent MIME type sniffing
            header('X-Content-Type-Options: nosniff');

            // Enable XSS protection
            header('X-XSS-Protection: 1; mode=block');

            // Content Security Policy
            $csp = "default-src 'self'; " .
                   "script-src 'self' https://cdnjs.cloudflare.com 'unsafe-inline'; " .
                   "style-src 'self' https://cdnjs.cloudflare.com 'unsafe-inline'; " .
                   "img-src 'self' data: https:; " .
                   "connect-src 'self'; " .
                   "font-src 'self' https://cdnjs.cloudflare.com; " .
                   "object-src 'none'; " .
                   "base-uri 'self'; " .
                   "form-action 'self';";
            header("Content-Security-Policy: {$csp}");

            // Strict Transport Security (only if HTTPS)
            if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
            }

            // Referrer Policy
            header('Referrer-Policy: strict-origin-when-cross-origin');

            // Prevent caching of sensitive pages
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
    }

    /**
     * Initialize CSRF protection system
     *
     * @return void
     */
    private static function initializeCSRF(): void
    {
        if (!isset($_SESSION['csrf_tokens'])) {
            $_SESSION['csrf_tokens'] = [];
        }

        // Clean expired tokens
        self::cleanExpiredCSRFTokens();
    }

    /**
     * Generate a new CSRF token
     *
     * @param string $action Action identifier for the token
     * @return string CSRF token
     */
    public static function generateCSRFToken(string $action = 'default'): string
    {
        if (!isset($_SESSION['csrf_tokens'])) {
            $_SESSION['csrf_tokens'] = [];
        }

        // Clean expired tokens first
        self::cleanExpiredCSRFTokens();

        // Limit number of tokens per session
        if (count($_SESSION['csrf_tokens']) >= self::MAX_CSRF_TOKENS) {
            // Remove oldest token
            $oldestKey = array_key_first($_SESSION['csrf_tokens']);
            unset($_SESSION['csrf_tokens'][$oldestKey]);
        }

        // Generate secure token
        $token = bin2hex(random_bytes(32));
        $tokenData = [
            'token' => $token,
            'action' => $action,
            'created_at' => time(),
            'expires_at' => time() + self::CSRF_TOKEN_LIFETIME
        ];

        $_SESSION['csrf_tokens'][$token] = $tokenData;

        return $token;
    }

    /**
     * Validate CSRF token
     *
     * @param string $token Token to validate
     * @param string $action Action to validate against
     * @param bool $removeOnUse Remove token after successful validation
     * @return bool True if valid, false otherwise
     */
    public static function validateCSRFToken(string $token, string $action = 'default', bool $removeOnUse = true): bool
    {
        if (!isset($_SESSION['csrf_tokens'][$token])) {
            return false;
        }

        $tokenData = $_SESSION['csrf_tokens'][$token];

        // Check if token is expired
        if (time() > $tokenData['expires_at']) {
            unset($_SESSION['csrf_tokens'][$token]);
            return false;
        }

        // Check if action matches
        if ($tokenData['action'] !== $action) {
            return false;
        }

        // Remove token if requested (one-time use)
        if ($removeOnUse) {
            unset($_SESSION['csrf_tokens'][$token]);
        }

        return true;
    }

    /**
     * Get CSRF token from request (POST, GET, or headers)
     *
     * @return string|null
     */
    public static function getCSRFTokenFromRequest(): ?string
    {
        // Check POST data
        if (isset($_POST['csrf_token'])) {
            return $_POST['csrf_token'];
        }

        // Check GET parameters
        if (isset($_GET['csrf_token'])) {
            return $_GET['csrf_token'];
        }

        // Check headers
        $headers = getallheaders();
        if (isset($headers['X-CSRF-Token'])) {
            return $headers['X-CSRF-Token'];
        }

        return null;
    }

    /**
     * Verify CSRF protection for current request
     *
     * @param string $action Action to verify
     * @return bool
     * @throws Exception If CSRF validation fails
     */
    public static function verifyCSRFProtection(string $action = 'default'): bool
    {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            return true; // GET requests don't need CSRF protection
        }

        $token = self::getCSRFTokenFromRequest();

        if (!$token) {
            throw new Exception('CSRF token missing', 403);
        }

        if (!self::validateCSRFToken($token, $action)) {
            throw new Exception('Invalid CSRF token', 403);
        }

        return true;
    }

    /**
     * Clean expired CSRF tokens
     *
     * @return void
     */
    private static function cleanExpiredCSRFTokens(): void
    {
        if (!isset($_SESSION['csrf_tokens'])) {
            return;
        }

        $currentTime = time();
        $_SESSION['csrf_tokens'] = array_filter(
            $_SESSION['csrf_tokens'],
            fn($tokenData) => $currentTime <= $tokenData['expires_at']
        );
    }

    /**
     * Hash password using Argon2ID algorithm
     *
     * @param string $password Plain text password
     * @return string Hashed password
     * @throws InvalidArgumentException If password is invalid
     */
    public static function hashPassword(string $password): string
    {
        self::validatePasswordStrength($password);

        $options = [
            'memory_cost' => 65536, // 64 MB
            'time_cost' => 4,       // 4 iterations
            'threads' => 3,         // 3 threads
        ];

        $hash = password_hash($password, PASSWORD_ARGON2ID, $options);

        if ($hash === false) {
            throw new Exception('Password hashing failed');
        }

        return $hash;
    }

    /**
     * Verify password against hash
     *
     * @param string $password Plain text password
     * @param string $hash Hashed password
     * @return bool True if password matches, false otherwise
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Check if password needs rehashing (algorithm upgrade)
     *
     * @param string $hash Password hash to check
     * @return bool True if rehashing is needed
     */
    public static function needsRehash(string $hash): bool
    {
        $options = [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3,
        ];

        return password_needs_rehash($hash, PASSWORD_ARGON2ID, $options);
    }

    /**
     * Validate password strength
     *
     * @param string $password Password to validate
     * @return void
     * @throws InvalidArgumentException If password doesn't meet requirements
     */
    private static function validatePasswordStrength(string $password): void
    {
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new InvalidArgumentException("Password must be at least " . self::MIN_PASSWORD_LENGTH . " characters long");
        }

        if (strlen($password) > self::MAX_PASSWORD_LENGTH) {
            throw new InvalidArgumentException("Password must not exceed " . self::MAX_PASSWORD_LENGTH . " characters");
        }

        // Check for at least one uppercase letter, one lowercase letter, one number, and one special character
        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/', $password)) {
            throw new InvalidArgumentException("Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character");
        }
    }

    /**
     * Generate secure random token
     *
     * @param int $length Token length in bytes
     * @return string Hexadecimal token
     */
    public static function generateSecureToken(int $length = 32): string
    {
        if ($length < 16 || $length > 128) {
            throw new InvalidArgumentException('Token length must be between 16 and 128 bytes');
        }

        return bin2hex(random_bytes($length));
    }

    /**
     * Generate secure random string with specific character sets
     *
     * @param int $length String length
     * @param bool $includeUppercase Include uppercase letters
     * @param bool $includeLowercase Include lowercase letters
     * @param bool $includeNumbers Include numbers
     * @param bool $includeSymbols Include symbols
     * @return string Random string
     */
    public static function generateRandomString(
        int $length = 32,
        bool $includeUppercase = true,
        bool $includeLowercase = true,
        bool $includeNumbers = true,
        bool $includeSymbols = false
    ): string {
        if ($length < 1 || $length > 1024) {
            throw new InvalidArgumentException('String length must be between 1 and 1024 characters');
        }

        $characters = '';
        if ($includeUppercase) $characters .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        if ($includeLowercase) $characters .= 'abcdefghijklmnopqrstuvwxyz';
        if ($includeNumbers) $characters .= '0123456789';
        if ($includeSymbols) $characters .= '!@#$%^&*()_+-=[]{}|;:,.<>?';

        if (empty($characters)) {
            throw new InvalidArgumentException('At least one character set must be enabled');
        }

        $charactersLength = strlen($characters);
        $randomString = '';

        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }

        return $randomString;
    }

    /**
     * Sanitize input to prevent XSS attacks
     *
     * @param mixed $input Input to sanitize
     * @param int $flags htmlspecialchars flags
     * @param string $encoding Character encoding
     * @return mixed Sanitized input
     */
    public static function sanitizeInput($input, int $flags = ENT_QUOTES | ENT_HTML5, string $encoding = 'UTF-8')
    {
        if (is_array($input)) {
            return array_map(fn($item) => self::sanitizeInput($item, $flags, $encoding), $input);
        }

        if (is_string($input)) {
            return htmlspecialchars($input, $flags, $encoding);
        }

        return $input;
    }

    /**
     * Sanitize output for safe display
     *
     * @param string $output Output to sanitize
     * @param bool $allowHtml Allow safe HTML tags
     * @return string Sanitized output
     */
    public static function sanitizeOutput(string $output, bool $allowHtml = false): string
    {
        if ($allowHtml) {
            // Allow only safe HTML tags
            $allowedTags = '<p><br><strong><em><u><ol><ul><li><a><h1><h2><h3><h4><h5><h6>';
            return strip_tags($output, $allowedTags);
        }

        return htmlspecialchars($output, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Rate limiting functionality
     *
     * @param string $identifier Unique identifier (IP, user ID, etc.)
     * @param string $action Action being rate limited
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $timeWindow Time window in seconds
     * @return bool True if within limits, false if rate limited
     */
    public static function checkRateLimit(
        string $identifier,
        string $action = 'default',
        int $maxAttempts = self::DEFAULT_RATE_LIMIT_ATTEMPTS,
        int $timeWindow = self::DEFAULT_RATE_LIMIT_WINDOW
    ): bool {
        $key = "rate_limit_{$action}_{$identifier}";

        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [
                'attempts' => 0,
                'reset_time' => time() + $timeWindow
            ];
        }

        $rateLimitData = $_SESSION[$key];

        // Check if time window has expired
        if (time() > $rateLimitData['reset_time']) {
            $_SESSION[$key] = [
                'attempts' => 0,
                'reset_time' => time() + $timeWindow
            ];
            $rateLimitData = $_SESSION[$key];
        }

        // Check if within limits
        return $rateLimitData['attempts'] < $maxAttempts;
    }

    /**
     * Record rate limit attempt
     *
     * @param string $identifier Unique identifier
     * @param string $action Action being rate limited
     * @param int $timeWindow Time window in seconds
     * @return void
     */
    public static function recordRateLimitAttempt(
        string $identifier,
        string $action = 'default',
        int $timeWindow = self::DEFAULT_RATE_LIMIT_WINDOW
    ): void {
        $key = "rate_limit_{$action}_{$identifier}";

        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [
                'attempts' => 0,
                'reset_time' => time() + $timeWindow
            ];
        }

        $_SESSION[$key]['attempts']++;
    }

    /**
     * Get remaining rate limit attempts
     *
     * @param string $identifier Unique identifier
     * @param string $action Action being rate limited
     * @param int $maxAttempts Maximum attempts allowed
     * @return int Remaining attempts
     */
    public static function getRemainingRateLimitAttempts(
        string $identifier,
        string $action = 'default',
        int $maxAttempts = self::DEFAULT_RATE_LIMIT_ATTEMPTS
    ): int {
        $key = "rate_limit_{$action}_{$identifier}";

        if (!isset($_SESSION[$key])) {
            return $maxAttempts;
        }

        $rateLimitData = $_SESSION[$key];

        // Check if time window has expired
        if (time() > $rateLimitData['reset_time']) {
            return $maxAttempts;
        }

        return max(0, $maxAttempts - $rateLimitData['attempts']);
    }

    /**
     * Reset rate limit for identifier
     *
     * @param string $identifier Unique identifier
     * @param string $action Action being rate limited
     * @return void
     */
    public static function resetRateLimit(string $identifier, string $action = 'default'): void
    {
        $key = "rate_limit_{$action}_{$identifier}";
        unset($_SESSION[$key]);
    }

    /**
     * Validate email format
     *
     * @param string $email Email to validate
     * @return bool True if valid email format
     */
    public static function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate phone number format (international)
     *
     * @param string $phone Phone number to validate
     * @return bool True if valid phone format
     */
    public static function validatePhone(string $phone): bool
    {
        // Remove all non-digit characters except + at the beginning
        $cleanPhone = preg_replace('/[^\d+]/', '', $phone);

        // Check for valid international format
        return preg_match('/^\+?[1-9]\d{1,14}$/', $cleanPhone);
    }

    /**
     * Validate URL format
     *
     * @param string $url URL to validate
     * @return bool True if valid URL format
     */
    public static function validateUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Create secure hash for comparison (timing attack resistant)
     *
     * @param string $data Data to hash
     * @param string $salt Optional salt
     * @return string Secure hash
     */
    public static function createSecureHash(string $data, string $salt = ''): string
    {
        return hash_hmac('sha256', $data, $salt ?: $_SESSION['security_salt'] ?? 'default_salt');
    }

    /**
     * Compare hashes in constant time (timing attack resistant)
     *
     * @param string $hash1 First hash
     * @param string $hash2 Second hash
     * @return bool True if hashes match
     */
    public static function compareHashes(string $hash1, string $hash2): bool
    {
        return hash_equals($hash1, $hash2);
    }

    /**
     * Generate and set security salt for session
     *
     * @return string Generated salt
     */
    public static function generateSecuritySalt(): string
    {
        if (!isset($_SESSION['security_salt'])) {
            $_SESSION['security_salt'] = self::generateSecureToken(32);
        }

        return $_SESSION['security_salt'];
    }

    /**
     * Log security event
     *
     * @param string $event Event type
     * @param string $message Event message
     * @param array $context Additional context
     * @return void
     */
    public static function logSecurityEvent(string $event, string $message, array $context = []): void
    {
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'event' => $event,
            'message' => $message,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'session_id' => session_id(),
            'context' => $context
        ];

        $logMessage = json_encode($logData) . PHP_EOL;

        // Log to security log file
        $logFile = $_ENV['LOG_PATH'] ?? '/var/log/winabrandnew';
        $logFile .= '/security.log';

        if (is_writable(dirname($logFile))) {
            file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
        }

        // Also log critical events to error_log
        if (in_array($event, ['csrf_attack', 'session_hijack', 'brute_force', 'rate_limit_exceeded'])) {
            error_log("Security Event [{$event}]: {$message}");
        }
    }

    /**
     * Get client IP address (considering proxies)
     *
     * @return string Client IP address
     */
    public static function getClientIpAddress(): string
    {
        $ipKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];

        foreach ($ipKeys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = explode(',', $ip)[0];
                }
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Security helper for admin login rate limiting
     *
     * @param string $identifier Admin identifier (username or IP)
     * @return bool True if within admin login limits
     */
    public static function checkAdminLoginRateLimit(string $identifier): bool
    {
        return self::checkRateLimit($identifier, 'admin_login', 5, 900); // 5 attempts per 15 minutes
    }

    /**
     * Record admin login attempt
     *
     * @param string $identifier Admin identifier
     * @return void
     */
    public static function recordAdminLoginAttempt(string $identifier): void
    {
        self::recordRateLimitAttempt($identifier, 'admin_login', 900);
        self::logSecurityEvent('admin_login_attempt', "Admin login attempt from: {$identifier}", [
            'identifier' => $identifier,
            'ip' => self::getClientIpAddress()
        ]);
    }

    /**
     * Clear all security data (cleanup)
     *
     * @return void
     */
    public static function cleanup(): void
    {
        // Clean expired CSRF tokens
        self::cleanExpiredCSRFTokens();

        // Clean expired rate limit data
        if (isset($_SESSION)) {
            foreach ($_SESSION as $key => $value) {
                if (strpos($key, 'rate_limit_') === 0 && isset($value['reset_time'])) {
                    if (time() > $value['reset_time']) {
                        unset($_SESSION[$key]);
                    }
                }
            }
        }
    }
}

/**
 * Security Helper Functions
 *
 * Convenience functions for common security operations
 */

/**
 * Quick CSRF token generation
 *
 * @param string $action
 * @return string
 */
function csrf_token(string $action = 'default'): string
{
    return Security::generateCSRFToken($action);
}

/**
 * Quick CSRF validation
 *
 * @param string $token
 * @param string $action
 * @return bool
 */
function csrf_validate(string $token, string $action = 'default'): bool
{
    return Security::validateCSRFToken($token, $action);
}

/**
 * Quick input sanitization
 *
 * @param mixed $input
 * @return mixed
 */
function sanitize($input)
{
    return Security::sanitizeInput($input);
}

/**
 * Quick output sanitization
 *
 * @param string $output
 * @param bool $allowHtml
 * @return string
 */
function escape_output(string $output, bool $allowHtml = false): string
{
    return Security::sanitizeOutput($output, $allowHtml);
}

/**
 * Quick password hashing
 *
 * @param string $password
 * @return string
 */
function hash_password(string $password): string
{
    return Security::hashPassword($password);
}

/**
 * Quick password verification
 *
 * @param string $password
 * @param string $hash
 * @return bool
 */
function verify_password(string $password, string $hash): bool
{
    return Security::verifyPassword($password, $hash);
}

/**
 * Quick secure token generation
 *
 * @param int $length
 * @return string
 */
function secure_token(int $length = 32): string
{
    return Security::generateSecureToken($length);
}

/**
 * Quick random string generation
 *
 * @param int $length
 * @param bool $includeSymbols
 * @return string
 */
function random_string(int $length = 32, bool $includeSymbols = false): string
{
    return Security::generateRandomString($length, true, true, true, $includeSymbols);
}

/**
 * Quick rate limit check
 *
 * @param string $identifier
 * @param string $action
 * @return bool
 */
function check_rate_limit(string $identifier, string $action = 'default'): bool
{
    return Security::checkRateLimit($identifier, $action);
}

/**
 * Quick email validation
 *
 * @param string $email
 * @return bool
 */
function validate_email(string $email): bool
{
    return Security::validateEmail($email);
}

/**
 * Quick phone validation
 *
 * @param string $phone
 * @return bool
 */
function validate_phone(string $phone): bool
{
    return Security::validatePhone($phone);
}

/**
 * Quick URL validation
 *
 * @param string $url
 * @return bool
 */
function validate_url(string $url): bool
{
    return Security::validateUrl($url);
}

/**
 * Get client IP address
 *
 * @return string
 */
function get_client_ip(): string
{
    return Security::getClientIpAddress();
}

/**
 * Log security event
 *
 * @param string $event
 * @param string $message
 * @param array $context
 * @return void
 */
function log_security_event(string $event, string $message, array $context = []): void
{
    Security::logSecurityEvent($event, $message, $context);
}
