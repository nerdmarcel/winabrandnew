<?php
declare(strict_types=1);

/**
 * File: core/AdminMiddleware.php
 * Location: core/AdminMiddleware.php
 *
 * WinABN Admin Route Protection Middleware
 *
 * Handles authentication verification, permission checking, session timeout,
 * and security measures for admin routes.
 *
 * @package WinABN\Core
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Core;

use WinABN\Controllers\AdminController;
use Exception;

class AdminMiddleware
{
    /**
     * Admin controller instance
     *
     * @var AdminController
     */
    private AdminController $adminController;

    /**
     * Session timeout in seconds
     *
     * @var int
     */
    private int $sessionTimeout;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->adminController = new AdminController();
        $this->sessionTimeout = (int) env('ADMIN_SESSION_TIMEOUT', 7200); // 2 hours
    }

    /**
     * Handle admin authentication middleware
     *
     * @param callable $next Next middleware or route handler
     * @param array<string> $permissions Required permissions (optional)
     * @return mixed
     */
    public function handle(callable $next, array $permissions = [])
    {
        try {
            // Check authentication
            if (!$this->adminController->isLoggedIn()) {
                return $this->handleUnauthenticated();
            }

            // Verify session security
            if (!$this->verifySessionSecurity()) {
                Session::destroy();
                return $this->handleUnauthenticated('Session security violation detected');
            }

            // Check session timeout
            if ($this->isSessionExpired()) {
                Session::destroy();
                return $this->handleUnauthenticated('Session has expired');
            }

            // Update last activity
            $this->updateLastActivity();

            // Check permissions if required
            if (!empty($permissions)) {
                if (!$this->checkPermissions($permissions)) {
                    return $this->handleUnauthorized();
                }
            }

            // Log admin activity
            $this->logAdminActivity();

            // Continue to next middleware or route handler
            return $next();

        } catch (Exception $e) {
            app_log('error', 'Admin middleware error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'admin_id' => Session::get('admin')['id'] ?? null,
                'ip' => client_ip()
            ]);

            return $this->handleError();
        }
    }

    /**
     * Require authentication only
     *
     * @param callable $next Next middleware or route handler
     * @return mixed
     */
    public function requireAuth(callable $next)
    {
        return $this->handle($next);
    }

    /**
     * Require specific permission
     *
     * @param string $permission Required permission
     * @param callable $next Next middleware or route handler
     * @return mixed
     */
    public function requirePermission(string $permission, callable $next)
    {
        return $this->handle($next, [$permission]);
    }

    /**
     * Require multiple permissions (all must be satisfied)
     *
     * @param array<string> $permissions Required permissions
     * @param callable $next Next middleware or route handler
     * @return mixed
     */
    public function requirePermissions(array $permissions, callable $next)
    {
        return $this->handle($next, $permissions);
    }

    /**
     * Require any of the specified permissions
     *
     * @param array<string> $permissions Any of these permissions
     * @param callable $next Next middleware or route handler
     * @return mixed
     */
    public function requireAnyPermission(array $permissions, callable $next)
    {
        if (!$this->adminController->isLoggedIn()) {
            return $this->handleUnauthenticated();
        }

        $hasAnyPermission = false;
        foreach ($permissions as $permission) {
            if ($this->adminController->hasPermission($permission)) {
                $hasAnyPermission = true;
                break;
            }
        }

        if (!$hasAnyPermission) {
            return $this->handleUnauthorized();
        }

        return $this->handle($next);
    }

    /**
     * Require super admin role
     *
     * @param callable $next Next middleware or route handler
     * @return mixed
     */
    public function requireSuperAdmin(callable $next)
    {
        if (!$this->adminController->isLoggedIn()) {
            return $this->handleUnauthenticated();
        }

        $admin = $this->adminController->getCurrentAdmin();
        if (!$admin || $admin['role'] !== 'super_admin') {
            return $this->handleUnauthorized('Super admin access required');
        }

        return $this->handle($next);
    }

    /**
     * Rate limiting middleware for admin actions
     *
     * @param int $maxRequests Maximum requests per time window
     * @param int $timeWindow Time window in seconds
     * @param callable $next Next middleware or route handler
     * @return mixed
     */
    public function rateLimit(int $maxRequests, int $timeWindow, callable $next)
    {
        $admin = $this->adminController->getCurrentAdmin();
        if (!$admin) {
            return $this->handleUnauthenticated();
        }

        $key = 'admin_rate_limit_' . $admin['id'];
        $requests = Session::get($key, []);
        $now = time();

        // Clean old requests
        $requests = array_filter($requests, function($timestamp) use ($now, $timeWindow) {
            return ($now - $timestamp) < $timeWindow;
        });

        // Check rate limit
        if (count($requests) >= $maxRequests) {
            return $this->handleRateLimited();
        }

        // Add current request
        $requests[] = $now;
        Session::set($key, $requests);

        return $this->handle($next);
    }

    /**
     * CSRF protection middleware
     *
     * @param callable $next Next middleware or route handler
     * @return mixed
     */
    public function csrfProtection(callable $next)
    {
        if (request_method() === 'POST') {
            $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

            if (!csrf_verify($token)) {
                return $this->handleCsrfViolation();
            }
        }

        return $this->handle($next);
    }

    /**
     * Verify session security
     *
     * @return bool
     */
    private function verifySessionSecurity(): bool
    {
        $admin = Session::get('admin');
        $sessionIp = Session::get('admin_ip');
        $currentIp = client_ip();

        if (!$admin || !$sessionIp) {
            return false;
        }

        // Check IP consistency (allow for proxy/load balancer scenarios)
        if ($sessionIp !== $currentIp && !$this->isValidIpChange($sessionIp, $currentIp)) {
            $this->logSecurityEvent('session_ip_mismatch', [
                'admin_id' => $admin['id'],
                'session_ip' => $sessionIp,
                'current_ip' => $currentIp
            ]);
            return false;
        }

        // Check user agent consistency (basic check)
        $sessionUserAgent = Session::get('admin_user_agent');
        $currentUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if ($sessionUserAgent && $sessionUserAgent !== $currentUserAgent) {
            // Allow minor user agent variations but flag major changes
            if (!$this->isValidUserAgentChange($sessionUserAgent, $currentUserAgent)) {
                $this->logSecurityEvent('session_user_agent_mismatch', [
                    'admin_id' => $admin['id'],
                    'session_ua' => $sessionUserAgent,
                    'current_ua' => $currentUserAgent
                ]);
                return false;
            }
        }

        return true;
    }

    /**
     * Check if session has expired
     *
     * @return bool
     */
    private function isSessionExpired(): bool
    {
        $lastActivity = Session::get('admin_last_activity');

        if (!$lastActivity) {
            return true;
        }

        return (time() - $lastActivity) > $this->sessionTimeout;
    }

    /**
     * Update last activity timestamp
     *
     * @return void
     */
    private function updateLastActivity(): void
    {
        Session::set('admin_last_activity', time());

        // Store user agent if not already stored
        if (!Session::get('admin_user_agent')) {
            Session::set('admin_user_agent', $_SERVER['HTTP_USER_AGENT'] ?? '');
        }
    }

    /**
     * Check if admin has required permissions
     *
     * @param array<string> $permissions Required permissions
     * @return bool
     */
    private function checkPermissions(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->adminController->hasPermission($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Log admin activity
     *
     * @return void
     */
    private function logAdminActivity(): void
    {
        $admin = $this->adminController->getCurrentAdmin();
        if (!$admin) return;

        // Only log significant activities, not every page view
        $significantRoutes = [
            '/adminportal/games',
            '/adminportal/participants',
            '/adminportal/settings',
            '/adminportal/fulfillment'
        ];

        $currentRoute = $_SERVER['REQUEST_URI'] ?? '';
        $method = request_method();

        // Log POST requests and access to significant routes
        if ($method === 'POST' || $this->isSignificantRoute($currentRoute, $significantRoutes)) {
            try {
                Database::execute(
                    "INSERT INTO admin_activity_log (admin_id, action, route, method, ip_address, user_agent, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())",
                    [
                        $admin['id'],
                        $this->getActionFromRoute($currentRoute, $method),
                        $currentRoute,
                        $method,
                        client_ip(),
                        $_SERVER['HTTP_USER_AGENT'] ?? null
                    ]
                );
            } catch (Exception $e) {
                // Don't fail the request if logging fails
                app_log('warning', 'Failed to log admin activity', [
                    'admin_id' => $admin['id'],
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Handle unauthenticated access
     *
     * @param string|null $message Custom message
     * @return never
     */
    private function handleUnauthenticated(?string $message = null): never
    {
        $message = $message ?? 'Authentication required';

        if ($this->isAjaxRequest()) {
            json_response([
                'success' => false,
                'message' => $message,
                'redirect_url' => url('adminportal/login'),
                'code' => 'AUTHENTICATION_REQUIRED'
            ], 401);
        } else {
            redirect(url('adminportal/login'));
        }
    }

    /**
     * Handle unauthorized access
     *
     * @param string|null $message Custom message
     * @return never
     */
    private function handleUnauthorized(?string $message = null): never
    {
        $admin = $this->adminController->getCurrentAdmin();
        $message = $message ?? 'Insufficient permissions';

        // Log unauthorized access attempt
        if ($admin) {
            $this->logSecurityEvent('unauthorized_access', [
                'admin_id' => $admin['id'],
                'requested_route' => $_SERVER['REQUEST_URI'] ?? '',
                'message' => $message
            ]);
        }

        if ($this->isAjaxRequest()) {
            json_response([
                'success' => false,
                'message' => $message,
                'code' => 'INSUFFICIENT_PERMISSIONS'
            ], 403);
        } else {
            // Render access denied page
            http_response_code(403);
            echo $this->renderAccessDeniedPage($message);
            exit;
        }
    }

    /**
     * Handle rate limiting
     *
     * @return never
     */
    private function handleRateLimited(): never
    {
        $admin = $this->adminController->getCurrentAdmin();

        if ($admin) {
            $this->logSecurityEvent('rate_limit_exceeded', [
                'admin_id' => $admin['id'],
                'route' => $_SERVER['REQUEST_URI'] ?? ''
            ]);
        }

        if ($this->isAjaxRequest()) {
            json_response([
                'success' => false,
                'message' => 'Too many requests. Please slow down.',
                'code' => 'RATE_LIMITED'
            ], 429);
        } else {
            http_response_code(429);
            echo 'Too many requests. Please slow down.';
            exit;
        }
    }

    /**
     * Handle CSRF violation
     *
     * @return never
     */
    private function handleCsrfViolation(): never
    {
        $admin = $this->adminController->getCurrentAdmin();

        if ($admin) {
            $this->logSecurityEvent('csrf_violation', [
                'admin_id' => $admin['id'],
                'route' => $_SERVER['REQUEST_URI'] ?? '',
                'referer' => $_SERVER['HTTP_REFERER'] ?? ''
            ]);
        }

        if ($this->isAjaxRequest()) {
            json_response([
                'success' => false,
                'message' => 'Security token mismatch. Please refresh and try again.',
                'code' => 'CSRF_VIOLATION'
            ], 403);
        } else {
            http_response_code(403);
            echo 'Security token mismatch. Please refresh and try again.';
            exit;
        }
    }

    /**
     * Handle middleware errors
     *
     * @return never
     */
    private function handleError(): never
    {
        if ($this->isAjaxRequest()) {
            json_response([
                'success' => false,
                'message' => 'System error occurred',
                'code' => 'SYSTEM_ERROR'
            ], 500);
        } else {
            http_response_code(500);
            echo 'System error occurred. Please try again.';
            exit;
        }
    }

    /**
     * Check if IP change is valid (proxy/load balancer scenario)
     *
     * @param string $sessionIp Original IP
     * @param string $currentIp Current IP
     * @return bool
     */
    private function isValidIpChange(string $sessionIp, string $currentIp): bool
    {
        // Allow changes within the same subnet for corporate networks
        $sessionParts = explode('.', $sessionIp);
        $currentParts = explode('.', $currentIp);

        if (count($sessionParts) === 4 && count($currentParts) === 4) {
            // Allow changes in the same /24 subnet
            return $sessionParts[0] === $currentParts[0] &&
                   $sessionParts[1] === $currentParts[1] &&
                   $sessionParts[2] === $currentParts[2];
        }

        return false;
    }

    /**
     * Check if user agent change is valid
     *
     * @param string $sessionUA Original user agent
     * @param string $currentUA Current user agent
     * @return bool
     */
    private function isValidUserAgentChange(string $sessionUA, string $currentUA): bool
    {
        // Allow minor version changes in the same browser
        $sessionBrowser = $this->extractBrowserName($sessionUA);
        $currentBrowser = $this->extractBrowserName($currentUA);

        return $sessionBrowser === $currentBrowser;
    }

    /**
     * Extract browser name from user agent
     *
     * @param string $userAgent User agent string
     * @return string
     */
    private function extractBrowserName(string $userAgent): string
    {
        if (strpos($userAgent, 'Chrome') !== false) return 'Chrome';
        if (strpos($userAgent, 'Firefox') !== false) return 'Firefox';
        if (strpos($userAgent, 'Safari') !== false) return 'Safari';
        if (strpos($userAgent, 'Edge') !== false) return 'Edge';
        if (strpos($userAgent, 'Opera') !== false) return 'Opera';

        return 'Unknown';
    }

    /**
     * Check if current request is AJAX
     *
     * @return bool
     */
    private function isAjaxRequest(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' ||
               strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
    }

    /**
     * Check if route is significant for logging
     *
     * @param string $route Current route
     * @param array<string> $significantRoutes Significant routes
     * @return bool
     */
    private function isSignificantRoute(string $route, array $significantRoutes): bool
    {
        foreach ($significantRoutes as $significantRoute) {
            if (strpos($route, $significantRoute) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get action description from route and method
     *
     * @param string $route Route path
     * @param string $method HTTP method
     * @return string
     */
    private function getActionFromRoute(string $route, string $method): string
    {
        $actions = [
            'GET /adminportal/games' => 'view_games',
            'POST /adminportal/games' => 'manage_games',
            'GET /adminportal/participants' => 'view_participants',
            'POST /adminportal/participants' => 'manage_participants',
            'GET /adminportal/settings' => 'view_settings',
            'POST /adminportal/settings' => 'update_settings',
            'GET /adminportal/fulfillment' => 'view_fulfillment',
            'POST /adminportal/fulfillment' => 'manage_fulfillment'
        ];

        $key = $method . ' ' . explode('?', $route)[0];
        return $actions[$key] ?? 'unknown_action';
    }

    /**
     * Log security event
     *
     * @param string $eventType Event type
     * @param array<string, mixed> $details Event details
     * @return void
     */
    private function logSecurityEvent(string $eventType, array $details): void
    {
        try {
            Database::execute(
                "INSERT INTO security_log (ip_address, event_type, details_json, user_agent, request_uri, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())",
                [
                    client_ip(),
                    $eventType,
                    json_encode($details),
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    $_SERVER['REQUEST_URI'] ?? null
                ]
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
     * Render access denied page
     *
     * @param string $message Denial message
     * @return string
     */
    private function renderAccessDeniedPage(string $message): string
    {
        return '<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied - WinABN Admin</title>
    <style>
        body { font-family: Arial, sans-serif; text-align: center; margin-top: 100px; }
        .error-container { max-width: 500px; margin: 0 auto; }
        .error-code { font-size: 72px; color: #dc3545; font-weight: bold; }
        .error-message { font-size: 24px; margin: 20px 0; }
        .error-description { color: #6c757d; margin-bottom: 30px; }
        .btn { background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-code">403</div>
        <div class="error-message">Access Denied</div>
        <div class="error-description">' . htmlspecialchars($message) . '</div>
        <a href="' . url('adminportal/dashboard') . '" class="btn">Return to Dashboard</a>
    </div>
</body>
</html>';
    }

    /**
     * Create admin activity log table if not exists
     *
     * @return void
     */
    public static function createActivityLogTable(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS `admin_activity_log` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `admin_id` int unsigned NOT NULL,
                `action` varchar(100) NOT NULL,
                `route` varchar(500) NOT NULL,
                `method` varchar(10) NOT NULL,
                `ip_address` varchar(45) NOT NULL,
                `user_agent` text NULL,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_admin_activity_admin` (`admin_id`),
                KEY `idx_admin_activity_action` (`action`),
                KEY `idx_admin_activity_created` (`created_at`),
                CONSTRAINT `fk_admin_activity_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        Database::exec($sql);
    }
}
