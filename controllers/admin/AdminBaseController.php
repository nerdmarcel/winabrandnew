<?php
/**
 * File: /controllers/admin/AdminBaseController.php
 * Admin Base Controller for Win a Brand New application
 *
 * This is the base controller for all admin controllers.
 * Provides admin authentication, permissions, 2FA support, and audit logging.
 */

require_once __DIR__ . '/../../models/AdminUser.php';
require_once __DIR__ . '/../../models/AuditLog.php';
require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/Security.php';

class AdminBaseController
{
    protected $db;
    protected $adminUser;
    protected $auditLog;
    protected $security;
    protected $currentAdmin = null;
    protected $sessionTimeout = 7200; // 2 hours in seconds

    // Admin permission levels
    const ROLE_SUPER_ADMIN = 'super_admin';
    const ROLE_ADMIN = 'admin';
    const ROLE_MODERATOR = 'moderator';

    // Required permissions for different actions
    protected $requiredPermissions = [
        'view' => ['super_admin', 'admin', 'moderator'],
        'create' => ['super_admin', 'admin'],
        'edit' => ['super_admin', 'admin'],
        'delete' => ['super_admin'],
        'settings' => ['super_admin']
    ];

    public function __construct()
    {
        // Initialize database connection
        $this->db = new Database();

        // Initialize models
        $this->adminUser = new AdminUser($this->db);
        $this->auditLog = new AuditLog($this->db);
        $this->security = new Security();

        // Start secure session
        $this->initializeSecureSession();

        // Verify admin authentication and session
        $this->verifyAdminAuthentication();

        // Check session timeout
        $this->checkSessionTimeout();

        // Verify 2FA if enabled
        $this->verify2FA();

        // Log admin access
        $this->logAdminAccess();
    }

    /**
     * Initialize secure session with proper settings
     */
    private function initializeSecureSession()
    {
        // Set secure session parameters
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_secure', 1);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.cookie_samesite', 'Strict');

        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Regenerate session ID periodically for security
        if (!isset($_SESSION['last_regeneration']) ||
            (time() - $_SESSION['last_regeneration']) > 300) { // Every 5 minutes
            session_regenerate_id(true);
            $_SESSION['last_regeneration'] = time();
        }
    }

    /**
     * Verify admin authentication
     */
    private function verifyAdminAuthentication()
    {
        // Check if admin is logged in
        if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_logged_in'])) {
            $this->redirectToLogin('Not authenticated');
            return;
        }

        // Verify admin exists and is active
        $adminId = $_SESSION['admin_id'];
        $admin = $this->adminUser->getById($adminId);

        if (!$admin || !$admin['is_active']) {
            $this->destroySession();
            $this->redirectToLogin('Account not found or inactive');
            return;
        }

        // Verify session token
        if (!isset($_SESSION['admin_token']) ||
            !hash_equals($admin['session_token'], $_SESSION['admin_token'])) {
            $this->destroySession();
            $this->redirectToLogin('Invalid session token');
            return;
        }

        $this->currentAdmin = $admin;
    }

    /**
     * Check session timeout (2 hours)
     */
    private function checkSessionTimeout()
    {
        if (!isset($_SESSION['admin_last_activity'])) {
            $_SESSION['admin_last_activity'] = time();
            return;
        }

        $inactiveTime = time() - $_SESSION['admin_last_activity'];

        if ($inactiveTime > $this->sessionTimeout) {
            $this->auditLog->log($this->currentAdmin['id'], 'session_timeout',
                'Session expired after ' . $inactiveTime . ' seconds');

            $this->destroySession();
            $this->redirectToLogin('Session expired due to inactivity');
            return;
        }

        // Update last activity time
        $_SESSION['admin_last_activity'] = time();
    }

    /**
     * Verify 2FA if enabled for the admin
     */
    private function verify2FA()
    {
        if (!$this->currentAdmin['two_factor_enabled']) {
            return; // 2FA not enabled for this admin
        }

        // Check if 2FA was verified in this session
        if (!isset($_SESSION['admin_2fa_verified']) ||
            !$_SESSION['admin_2fa_verified']) {

            // Redirect to 2FA verification page
            $this->redirect('/admin/auth/2fa-verify');
            return;
        }

        // Check 2FA verification timeout (require re-verification every hour)
        if (isset($_SESSION['admin_2fa_verified_at'])) {
            $timeSince2FA = time() - $_SESSION['admin_2fa_verified_at'];
            if ($timeSince2FA > 3600) { // 1 hour
                $_SESSION['admin_2fa_verified'] = false;
                $this->redirect('/admin/auth/2fa-verify');
                return;
            }
        }
    }

    /**
     * Log admin access for audit trail
     */
    private function logAdminAccess()
    {
        $action = $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'];
        $details = [
            'ip_address' => $this->getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'method' => $_SERVER['REQUEST_METHOD'],
            'uri' => $_SERVER['REQUEST_URI']
        ];

        $this->auditLog->log(
            $this->currentAdmin['id'],
            'admin_access',
            $action,
            json_encode($details)
        );
    }

    /**
     * Check if current admin has required permission
     */
    protected function checkPermission($action)
    {
        if (!isset($this->requiredPermissions[$action])) {
            return false; // Unknown action
        }

        $allowedRoles = $this->requiredPermissions[$action];
        $adminRole = $this->currentAdmin['role'];

        if (!in_array($adminRole, $allowedRoles)) {
            $this->auditLog->log(
                $this->currentAdmin['id'],
                'permission_denied',
                "Attempted action: {$action}, Role: {$adminRole}"
            );

            $this->respondWithError('Insufficient permissions', 403);
            return false;
        }

        return true;
    }

    /**
     * Require specific permission or deny access
     */
    protected function requirePermission($action)
    {
        if (!$this->checkPermission($action)) {
            $this->respondWithError('Access denied: Insufficient permissions', 403);
            exit;
        }
    }

    /**
     * Get current admin user data
     */
    protected function getCurrentAdmin()
    {
        return $this->currentAdmin;
    }

    /**
     * Check if current admin is super admin
     */
    protected function isSuperAdmin()
    {
        return $this->currentAdmin['role'] === self::ROLE_SUPER_ADMIN;
    }

    /**
     * Log admin action for audit trail
     */
    protected function logAction($action, $details = '', $target_id = null, $target_type = null)
    {
        $this->auditLog->log(
            $this->currentAdmin['id'],
            $action,
            $details,
            null,
            $target_id,
            $target_type
        );
    }

    /**
     * Destroy admin session
     */
    private function destroySession()
    {
        // Clear admin session token in database
        if ($this->currentAdmin) {
            $this->adminUser->clearSessionToken($this->currentAdmin['id']);
        }

        // Destroy session
        $_SESSION = [];

        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }

        session_destroy();
    }

    /**
     * Redirect to login page
     */
    private function redirectToLogin($reason = '')
    {
        if ($reason) {
            $_SESSION['login_error'] = $reason;
        }

        // Check if it's an AJAX request
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {

            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Authentication required',
                'redirect' => '/admin/login'
            ]);
            exit;
        }

        // Regular redirect
        $this->redirect('/admin/login');
    }

    /**
     * General redirect method
     */
    protected function redirect($url)
    {
        header("Location: {$url}");
        exit;
    }

    /**
     * Get client IP address
     */
    private function getClientIP()
    {
        $ipKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];

        foreach ($ipKeys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP,
                        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '';
    }

    /**
     * Send JSON response
     */
    protected function respondWithJson($data, $statusCode = 200)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    /**
     * Send error response
     */
    protected function respondWithError($message, $statusCode = 400, $errorCode = null)
    {
        $response = [
            'success' => false,
            'error' => $message
        ];

        if ($errorCode) {
            $response['error_code'] = $errorCode;
        }

        // Check if it's an AJAX request
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {

            $this->respondWithJson($response, $statusCode);
        } else {
            // For non-AJAX requests, you might want to render an error page
            http_response_code($statusCode);
            echo "<h1>Error {$statusCode}</h1><p>{$message}</p>";
            exit;
        }
    }

    /**
     * Send success response
     */
    protected function respondWithSuccess($message = 'Success', $data = [])
    {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data
        ];

        $this->respondWithJson($response);
    }

    /**
     * Validate CSRF token
     */
    protected function validateCSRF()
    {
        if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token'])) {
            $this->respondWithError('CSRF token missing', 400);
            return false;
        }

        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $this->logAction('csrf_validation_failed', 'Invalid CSRF token provided');
            $this->respondWithError('Invalid CSRF token', 400);
            return false;
        }

        return true;
    }

    /**
     * Generate CSRF token
     */
    protected function generateCSRFToken()
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Rate limiting check
     */
    protected function checkRateLimit($action, $limit = 100, $window = 3600)
    {
        $key = "rate_limit_{$action}_" . $this->currentAdmin['id'];
        $current = $_SESSION[$key] ?? ['count' => 0, 'start' => time()];

        // Reset if window expired
        if (time() - $current['start'] > $window) {
            $current = ['count' => 0, 'start' => time()];
        }

        $current['count']++;
        $_SESSION[$key] = $current;

        if ($current['count'] > $limit) {
            $this->logAction('rate_limit_exceeded', "Action: {$action}, Limit: {$limit}");
            $this->respondWithError('Rate limit exceeded. Please try again later.', 429);
            return false;
        }

        return true;
    }

    /**
     * Clean up on destruction
     */
    public function __destruct()
    {
        // Update last activity timestamp
        if ($this->currentAdmin) {
            $this->adminUser->updateLastActivity($this->currentAdmin['id']);
        }
    }
}
?>
