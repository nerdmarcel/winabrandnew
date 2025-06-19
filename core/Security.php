<?php
declare(strict_types=1);

/**
 * File: core/Security.php
 * Location: core/Security.php
 *
 * WinABN Security Utilities
 *
 * Provides comprehensive security features including CSRF protection, XSS prevention,
 * rate limiting, input validation, password hashing, and middleware for the WinABN platform.
 *
 * @package WinABN\Core
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Core;

use Exception;

class Security
{
    /**
     * CSRF token session key
     *
     * @var string
     */
    private const CSRF_TOKEN_KEY = '_csrf_token';

    /**
     * Rate limit cache
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $rateLimitCache = [];

    /**
     * Security configuration
     *
     * @var array<string, mixed>
     */
    private static array $config = [];

    /**
     * Initialize security configuration
     *
     * @return void
     */
    public static function init(): void
    {
        self::$config = [
            'csrf_enabled' => env('CSRF_ENABLED', true),
            'rate_limit_enabled' => env('RATE_LIMIT_ENABLED', true),
            'password_min_length' => env('PASSWORD_MIN_LENGTH', 8),
            'password_require_special' => env('PASSWORD_REQUIRE_SPECIAL', true),
            'max_login_attempts' => env('MAX_LOGIN_ATTEMPTS', 5),
            'login_lockout_duration' => env('LOGIN_LOCKOUT_DURATION', 900), // 15 minutes
            'fraud_detection_enabled' => env('FRAUD_DETECTION_ENABLED', true),
            'ip_whitelist' => explode(',', env('IP_WHITELIST', '')),
            'ip_blacklist' => explode(',', env('IP_BLACKLIST', ''))
        ];
    }

    /**
     * Generate CSRF token
     *
     * @return string CSRF token
     */
    public static function generateCsrfToken(): string
    {
        $session = new Session();

        if (!$session->has(self::CSRF_TOKEN_KEY)) {
            $token = bin2hex(random_bytes(32));
            $session->set(self::CSRF_TOKEN_KEY, $token);
        }

        return $session->get(self::CSRF_TOKEN_KEY);
    }

    /**
     * Verify CSRF token
     *
     * @param string|null $token Token to verify
     * @return bool True if valid
     */
    public static function verifyCsrfToken(?string $token): bool
    {
        if (!self::$config['csrf_enabled']) {
            return true;
        }

        if (!$token) {
            return false;
        }

        $session = new Session();
        $sessionToken = $session->get(self::CSRF_TOKEN_KEY);

        return $sessionToken && hash_equals($sessionToken, $token);
    }

    /**
     * CSRF middleware
     *
     * @param callable $next Next middleware function
     * @return mixed
     * @throws Exception
     */
    public static function csrfMiddleware(callable $next)
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // Only check CSRF for state-changing methods
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            $token = $_POST['csrf_token'] ?? $_POST['_token'] ??
                    $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_SERVER['HTTP_X_XSRF_TOKEN'];

            if (!self::verifyCsrfToken($token)) {
                http_response_code(403);

                if (self::isJsonRequest()) {
                    json_response(['error' => 'CSRF token mismatch'], 403);
                } else {
                    throw new Exception('CSRF token mismatch');
                }
            }
        }

        return $next();
    }

    /**
     * Hash password securely
     *
     * @param string $password Plain text password
     * @return string Hashed password
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536, // 64 MB
            'time_cost' => 4,       // 4 iterations
            'threads' => 3          // 3 threads
        ]);
    }

    /**
     * Verify password against hash
     *
     * @param string $password Plain text password
     * @param string $hash Hashed password
     * @return bool True if password matches
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Validate password strength
     *
     * @param string $password Password to validate
     * @return array<string> Array of error messages (empty if valid)
     */
    public static function validatePassword(string $password): array
    {
        $errors = [];
        $minLength = self::$config['password_min_length'];

        if (strlen($password) < $minLength) {
            $errors[] = "Password must be at least {$minLength} characters long";
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = "Password must contain at least one uppercase letter";
        }

        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = "Password must contain at least one lowercase letter";
        }

        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = "Password must contain at least one number";
        }

        if (self::$config['password_require_special'] && !preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = "Password must contain at least one special character";
        }

        // Check for common weak passwords
        $weakPasswords = [
            'password', '123456', 'qwerty', 'abc123', 'letmein',
            'welcome', 'monkey', '1234567890', 'password123'
        ];

        if (in_array(strtolower($password), $weakPasswords)) {
            $errors[] = "Password is too common and weak";
        }

        return $errors;
    }

    /**
     * Sanitize input to prevent XSS
     *
     * @param mixed $input Input to sanitize
     * @param bool $allowHtml Allow HTML tags
     * @return mixed Sanitized input
     */
    public static function sanitizeInput($input, bool $allowHtml = false)
    {
        if (is_array($input)) {
            return array_map(function($item) use ($allowHtml) {
                return self::sanitizeInput($item, $allowHtml);
            }, $input);
        }

        if (!is_string($input)) {
            return $input;
        }

        // Remove null bytes
        $input = str_replace("\0", '', $input);

        if ($allowHtml) {
            // Allow specific HTML tags but sanitize attributes
            $input = strip_tags($input, '<p><br><strong><em><ul><ol><li><a><h1><h2><h3><h4><h5><h6>');
            $input = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $input);
        } else {
            // Remove all HTML tags
            $input = strip_tags($input);
        }

        return trim($input);
    }

    /**
     * Escape output for HTML
     *
     * @param mixed $value Value to escape
     * @param string $encoding Character encoding
     * @return string Escaped value
     */
    public static function escapeHtml($value, string $encoding = 'UTF-8'): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, $encoding, false);
    }

    /**
     * Rate limiting check
     *
     * @param string $action Action identifier
     * @param string $identifier Client identifier (IP, user ID, etc.)
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $timeWindow Time window in seconds
     * @return bool True if under limit
     */
    public static function checkRateLimit(string $action, string $identifier, int $maxAttempts = 5, int $timeWindow = 300): bool
    {
        if (!self::$config['rate_limit_enabled']) {
            return true;
        }

        $key = "rate_limit_{$action}_{$identifier}";
        $now = time();

        // Check database for rate limit data
        try {
            $attempts = Database::fetchAll(
                "SELECT created_at FROM rate_limits WHERE action = ? AND identifier = ? AND created_at > ?",
                [$action, $identifier, date('Y-m-d H:i:s', $now - $timeWindow)]
            );

            if (count($attempts) >= $maxAttempts) {
                return false;
            }

            // Record this attempt
            Database::execute(
                "INSERT INTO rate_limits (action, identifier, ip_address, created_at) VALUES (?, ?, ?, NOW())",
                [$action, $identifier, client_ip()]
            );

            return true;

        } catch (Exception $e) {
            // Fallback to in-memory rate limiting
            return self::checkMemoryRateLimit($action, $identifier, $maxAttempts, $timeWindow);
        }
    }

    /**
     * In-memory rate limiting fallback
     *
     * @param string $action Action identifier
     * @param string $identifier Client identifier
     * @param int $maxAttempts Maximum attempts
     * @param int $timeWindow Time window in seconds
     * @return bool True if under limit
     */
    private static function checkMemoryRateLimit(string $action, string $identifier, int $maxAttempts, int $timeWindow): bool
    {
        $key = "rate_limit_{$action}_{$identifier}";
        $now = time();

        if (!isset(self::$rateLimitCache[$key])) {
            self::$rateLimitCache[$key] = [];
        }

        // Clean old attempts
        self::$rateLimitCache[$key] = array_filter(
            self::$rateLimitCache[$key],
            function($timestamp) use ($now, $timeWindow) {
                return ($now - $timestamp) <= $timeWindow;
            }
        );

        // Check if over limit
        if (count(self::$rateLimitCache[$key]) >= $maxAttempts) {
            return false;
        }

        // Record this attempt
        self::$rateLimitCache[$key][] = $now;
        return true;
    }

    /**
     * Rate limit middleware
     *
     * @param callable $next Next middleware function
     * @return mixed
     */
    public static function rateLimitMiddleware(callable $next)
    {
        $ip = client_ip();
        $action = 'general_request';

        if (!self::checkRateLimit($action, $ip, 60, 60)) { // 60 requests per minute
            http_response_code(429);

            if (self::isJsonRequest()) {
                json_response(['error' => 'Rate limit exceeded'], 429);
            } else {
                throw new Exception('Rate limit exceeded');
            }
        }

        return $next();
    }

    /**
     * Authentication middleware
     *
     * @param callable $next Next middleware function
     * @return mixed
     */
    public static function authMiddleware(callable $next)
    {
        $session = new Session();

        if (!$session->isAuthenticated()) {
            if (self::isJsonRequest()) {
                json_response(['error' => 'Authentication required'], 401);
            } else {
                redirect('/login');
            }
        }

        // Check session expiry
        if ($session->isExpired()) {
            $session->logout();

            if (self::isJsonRequest()) {
                json_response(['error' => 'Session expired'], 401);
            } else {
                redirect('/login?expired=1');
            }
        }

        return $next();
    }

    /**
     * Admin authentication middleware
     *
     * @param callable $next Next middleware function
     * @return mixed
     */
    public static function adminMiddleware(callable $next)
    {
        $session = new Session();

        if (!$session->isAuthenticated() || $session->getUserRole() !== 'admin') {
            if (self::isJsonRequest()) {
                json_response(['error' => 'Admin access required'], 403);
            } else {
                redirect('/adminportal/login');
            }
        }

        return $next();
    }

    /**
     * Validate login attempts and handle lockouts
     *
     * @param string $identifier User identifier (email/username)
     * @param string $ip IP address
     * @return bool True if login attempt allowed
     */
    public static function validateLoginAttempt(string $identifier, string $ip): bool
    {
        $maxAttempts = self::$config['max_login_attempts'];
        $lockoutDuration = self::$config['login_lockout_duration'];

        // Check both identifier and IP-based rate limiting
        $identifierAllowed = self::checkRateLimit('login_attempt_user', $identifier, $maxAttempts, $lockoutDuration);
        $ipAllowed = self::checkRateLimit('login_attempt_ip', $ip, $maxAttempts * 3, $lockoutDuration); // Allow more attempts per IP

        return $identifierAllowed && $ipAllowed;
    }

    /**
     * Record failed login attempt
     *
     * @param string $identifier User identifier
     * @param string $ip IP address
     * @param string $reason Failure reason
     * @return void
     */
    public static function recordFailedLogin(string $identifier, string $ip, string $reason = 'invalid_credentials'): void
    {
        try {
            Database::execute(
                "INSERT INTO security_log (ip_address, event_type, details_json, created_at) VALUES (?, ?, ?, NOW())",
                [$ip, 'login_attempt', json_encode([
                    'identifier' => $identifier,
                    'reason' => $reason,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
                ])]
            );
        } catch (Exception $e) {
            app_log('error', 'Failed to record security event', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Check IP address against whitelist/blacklist
     *
     * @param string $ip IP address to check
     * @return bool True if IP is allowed
     */
    public static function validateIpAddress(string $ip): bool
    {
        // Check blacklist first
        if (!empty(self::$config['ip_blacklist'])) {
            foreach (self::$config['ip_blacklist'] as $blacklistedIp) {
                if (trim($blacklistedIp) && self::ipMatches($ip, trim($blacklistedIp))) {
                    return false;
                }
            }
        }

        // Check whitelist if configured
        if (!empty(self::$config['ip_whitelist']) && !empty(self::$config['ip_whitelist'][0])) {
            foreach (self::$config['ip_whitelist'] as $whitelistedIp) {
                if (trim($whitelistedIp) && self::ipMatches($ip, trim($whitelistedIp))) {
                    return true;
                }
            }
            return false; // IP not in whitelist
        }

        return true; // No restrictions or IP allowed
    }

    /**
     * Check if IP matches pattern (supports CIDR notation)
     *
     * @param string $ip IP address to check
     * @param string $pattern IP pattern or CIDR block
     * @return bool True if IP matches pattern
     */
    private static function ipMatches(string $ip, string $pattern): bool
    {
        if ($ip === $pattern) {
            return true;
        }

        // Check CIDR notation
        if (str_contains($pattern, '/')) {
            [$subnet, $bits] = explode('/', $pattern);
            $ip = ip2long($ip);
            $subnet = ip2long($subnet);
            $mask = -1 << (32 - (int)$bits);
            $subnet &= $mask;

            return ($ip & $mask) === $subnet;
        }

        return false;
    }

    /**
     * Generate secure random string
     *
     * @param int $length String length
     * @param string $characters Character set
     * @return string Random string
     */
    public static function generateRandomString(int $length = 32, string $characters = ''): string
    {
        if (empty($characters)) {
            $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        }

        $randomString = '';
        $charactersLength = strlen($characters);

        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }

        return $randomString;
    }

    /**
     * Generate secure token for password resets, etc.
     *
     * @param int $length Token length
     * @return string Secure token
     */
    public static function generateSecureToken(int $length = 64): string
    {
        return bin2hex(random_bytes($length / 2));
    }

    /**
     * Validate email format
     *
     * @param string $email Email to validate
     * @return bool True if valid email
     */
    public static function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate phone number format
     *
     * @param string $phone Phone number to validate
     * @return bool True if valid phone number
     */
    public static function validatePhone(string $phone): bool
    {
        // Remove all non-digit characters except +
        $cleanPhone = preg_replace('/[^\d+]/', '', $phone);

        // Check length and format
        return preg_match('/^\+?[1-9]\d{7,14}$/', $cleanPhone) === 1;
    }

    /**
     * Check if request is JSON
     *
     * @return bool True if JSON request
     */
    private static function isJsonRequest(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') ||
               str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
    }

    /**
     * Fraud detection for gaming behavior
     *
     * @param array<string, mixed> $participantData Participant data
     * @return array<string> Array of fraud indicators
     */
    public static function detectFraud(array $participantData): array
    {
        if (!self::$config['fraud_detection_enabled']) {
            return [];
        }

        $indicators = [];

        // Check answer timing (too fast indicates automation)
        if (isset($participantData['average_answer_time'])) {
            $avgTime = (float) $participantData['average_answer_time'];
            if ($avgTime < 0.5) { // Less than 500ms per question
                $indicators[] = 'answers_too_fast';
            }
        }

        // Check for multiple participations from same IP
        if (isset($participantData['ip_address'])) {
            try {
                $ipCount = Database::fetchColumn(
                    "SELECT COUNT(*) FROM participants WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)",
                    [$participantData['ip_address']]
                );

                if ($ipCount > 5) { // More than 5 participations per day from same IP
                    $indicators[] = 'multiple_ip_participations';
                }
            } catch (Exception $e) {
                // Ignore database errors in fraud detection
            }
        }

        // Check device fingerprint
        if (isset($participantData['device_fingerprint'])) {
            try {
                $deviceCount = Database::fetchColumn(
                    "SELECT COUNT(*) FROM participants WHERE device_fingerprint = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)",
                    [$participantData['device_fingerprint']]
                );

                if ($deviceCount > 3) { // More than 3 participations per day from same device
                    $indicators[] = 'multiple_device_participations';
                }
            } catch (Exception $e) {
                // Ignore database errors in fraud detection
            }
        }

        // Check for pattern in wrong answers (random clicking)
        if (isset($participantData['answers'])) {
            $answers = $participantData['answers'];
            if (is_array($answers) && count($answers) >= 3) {
                // Check if all answers are the same (A, B, or C)
                $uniqueAnswers = array_unique($answers);
                if (count($uniqueAnswers) === 1) {
                    $indicators[] = 'identical_answers_pattern';
                }
            }
        }

        return $indicators;
    }

    /**
     * Log security event
     *
     * @param string $eventType Event type
     * @param array<string, mixed> $details Event details
     * @param string $severity Event severity
     * @return void
     */
    public static function logSecurityEvent(string $eventType, array $details = [], string $severity = 'medium'): void
    {
        try {
            Database::execute(
                "INSERT INTO security_log (ip_address, event_type, details_json, severity, created_at) VALUES (?, ?, ?, ?, NOW())",
                [client_ip(), $eventType, json_encode($details), $severity]
            );
        } catch (Exception $e) {
            app_log('error', 'Failed to log security event', [
                'event_type' => $eventType,
                'details' => $details,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Clean up old rate limit and security log entries
     *
     * @param int $days Days to keep entries
     * @return int Number of deleted entries
     */
    public static function cleanupSecurityLogs(int $days = 30): int
    {
        try {
            $deleted = 0;

            // Clean rate limits
            $deleted += Database::execute(
                "DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$days]
            );

            // Clean security logs
            $deleted += Database::execute(
                "DELETE FROM security_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$days]
            );

            return $deleted;
        } catch (Exception $e) {
            app_log('error', 'Failed to cleanup security logs', ['error' => $e->getMessage()]);
            return 0;
        }
    }
}

// Initialize security configuration
Security::init();
