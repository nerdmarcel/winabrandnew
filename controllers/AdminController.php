<?php
declare(strict_types=1);

/**
 * File: controllers/AdminController.php
 * Location: controllers/AdminController.php
 *
 * WinABN Admin Controller
 *
 * Handles admin authentication, session management, and basic admin operations
 * for the WinABN administration portal.
 *
 * @package WinABN\Controllers
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Controllers;

use WinABN\Core\{Controller, Security, Session, Database};
use WinABN\Models\Admin;
use Exception;

class AdminController extends Controller
{
    /**
     * Admin model instance
     *
     * @var Admin
     */
    private Admin $adminModel;

    /**
     * Session timeout in seconds (2 hours)
     *
     * @var int
     */
    private int $sessionTimeout;

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
        $this->adminModel = new Admin();
        $this->sessionTimeout = (int) env('ADMIN_SESSION_TIMEOUT', 7200); // 2 hours

        // Create admin table if it doesn't exist
        Admin::createTable();
    }

    /**
     * Display login form
     *
     * @return void
     */
    public function showLogin(): void
    {
        // Redirect if already logged in
        if ($this->isLoggedIn()) {
            redirect(url('adminportal/dashboard'));
        }

        $data = [
            'title' => 'Admin Login - WinABN',
            'csrf_token' => csrf_token(),
            'app_name' => env('APP_NAME', 'WinABN'),
            'login_attempts_remaining' => $this->getLoginAttemptsRemaining(),
            'is_locked' => $this->isCurrentIpLocked()
        ];

        $this->view->render('admin/login', $data);
    }

    /**
     * Process login request
     *
     * @return void
     */
    public function processLogin(): void
    {
        try {
            // Verify CSRF token
            if (!csrf_verify($_POST['csrf_token'] ?? '')) {
                json_response([
                    'success' => false,
                    'message' => 'Invalid security token'
                ], 403);
            }

            // Check if IP is locked
            if ($this->isCurrentIpLocked()) {
                json_response([
                    'success' => false,
                    'message' => 'Too many failed attempts. Please try again later.'
                ], 429);
            }

            // Validate input
            $identifier = trim($_POST['identifier'] ?? '');
            $password = $_POST['password'] ?? '';
            $totpCode = trim($_POST['totp_code'] ?? '');
            $rememberMe = !empty($_POST['remember_me']);

            if (empty($identifier) || empty($password)) {
                json_response([
                    'success' => false,
                    'message' => 'Username/email and password are required'
                ], 400);
            }

            // Rate limiting
            $this->enforceRateLimit();

            // Attempt authentication
            $authResult = $this->adminModel->authenticate($identifier, $password, $totpCode ?: null);

            if (!$authResult['success']) {
                // Log failed attempt
                $this->logFailedAttempt($identifier);

                // Handle specific error codes
                if ($authResult['code'] === 'TOTP_REQUIRED') {
                    json_response([
                        'success' => false,
                        'message' => $authResult['message'],
                        'requires_2fa' => true,
                        'admin_id' => $authResult['admin_id']
                    ], 200);
                }

                json_response([
                    'success' => false,
                    'message' => $authResult['message']
                ], 401);
            }

            // Successful authentication
            $admin = $authResult['admin'];
            $this->createAdminSession($admin, $rememberMe);

            // Clear failed attempts
            $this->clearFailedAttempts();

            json_response([
                'success' => true,
                'message' => 'Login successful',
                'redirect_url' => url('adminportal/dashboard'),
                'admin' => [
                    'id' => $admin['id'],
                    'username' => $admin['username'],
                    'email' => $admin['email'],
                    'first_name' => $admin['first_name'],
                    'last_name' => $admin['last_name'],
                    'role' => $admin['role'],
                    'two_factor_enabled' => $admin['two_factor_enabled']
                ]
            ]);

        } catch (Exception $e) {
            app_log('error', 'Admin login error', [
                'identifier' => $identifier ?? 'unknown',
                'ip' => client_ip(),
                'error' => $e->getMessage()
            ]);

            json_response([
                'success' => false,
                'message' => 'Login system error. Please try again.'
            ], 500);
        }
    }

    /**
     * Display admin dashboard
     *
     * @return void
     */
    public function dashboard(): void
    {
        $this->requireAuth();

        $admin = $this->getCurrentAdmin();

        // Get dashboard statistics
        $stats = $this->getDashboardStats();

        $data = [
            'title' => 'Dashboard - WinABN Admin',
            'admin' => $admin,
            'stats' => $stats,
            'recent_activities' => $this->getRecentActivities()
        ];

        $this->view->render('admin/dashboard', $data);
    }

    /**
     * Process logout
     *
     * @return void
     */
    public function logout(): void
    {
        $admin = $this->getCurrentAdmin();

        if ($admin) {
            app_log('info', 'Admin logout', [
                'admin_id' => $admin['id'],
                'username' => $admin['username'],
                'ip' => client_ip()
            ]);
        }

        // Clear admin session
        Session::destroy();

        redirect(url('adminportal/login'));
    }

    /**
     * Enable 2FA setup
     *
     * @return void
     */
    public function setup2FA(): void
    {
        $this->requireAuth();

        if (request_method() === 'POST') {
            if (!csrf_verify($_POST['csrf_token'] ?? '')) {
                json_response([
                    'success' => false,
                    'message' => 'Invalid security token'
                ], 403);
            }

            $admin = $this->getCurrentAdmin();
            $result = $this->adminModel->enable2FA($admin['id']);

            json_response($result);
        }

        // Show 2FA setup page
        $admin = $this->getCurrentAdmin();

        $data = [
            'title' => '2FA Setup - WinABN Admin',
            'admin' => $admin,
            'csrf_token' => csrf_token()
        ];

        $this->view->render('admin/2fa_setup', $data);
    }

    /**
     * Verify 2FA setup
     *
     * @return void
     */
    public function verify2FA(): void
    {
        $this->requireAuth();

        if (!csrf_verify($_POST['csrf_token'] ?? '')) {
            json_response([
                'success' => false,
                'message' => 'Invalid security token'
            ], 403);
        }

        $totpCode = trim($_POST['totp_code'] ?? '');

        if (empty($totpCode)) {
            json_response([
                'success' => false,
                'message' => 'Verification code is required'
            ], 400);
        }

        $admin = $this->getCurrentAdmin();
        $result = $this->adminModel->verify2FA($admin['id'], $totpCode);

        if ($result['success']) {
            // Update session to reflect 2FA status
            $updatedAdmin = $this->adminModel->find($admin['id']);
            Session::set('admin', $updatedAdmin);
        }

        json_response($result);
    }

    /**
     * Disable 2FA
     *
     * @return void
     */
    public function disable2FA(): void
    {
        $this->requireAuth();

        if (!csrf_verify($_POST['csrf_token'] ?? '')) {
            json_response([
                'success' => false,
                'message' => 'Invalid security token'
            ], 403);
        }

        $currentPassword = $_POST['current_password'] ?? '';

        if (empty($currentPassword)) {
            json_response([
                'success' => false,
                'message' => 'Current password is required'
            ], 400);
        }

        $admin = $this->getCurrentAdmin();
        $result = $this->adminModel->disable2FA($admin['id'], $currentPassword);

        if ($result['success']) {
            // Update session to reflect 2FA status
            $updatedAdmin = $this->adminModel->find($admin['id']);
            Session::set('admin', $updatedAdmin);
        }

        json_response($result);
    }

    /**
     * Change password
     *
     * @return void
     */
    public function changePassword(): void
    {
        $this->requireAuth();

        if (!csrf_verify($_POST['csrf_token'] ?? '')) {
            json_response([
                'success' => false,
                'message' => 'Invalid security token'
            ], 403);
        }

        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        // Validate input
        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            json_response([
                'success' => false,
                'message' => 'All password fields are required'
            ], 400);
        }

        if ($newPassword !== $confirmPassword) {
            json_response([
                'success' => false,
                'message' => 'New password confirmation does not match'
            ], 400);
        }

        $admin = $this->getCurrentAdmin();
        $result = $this->adminModel->updatePassword($admin['id'], $currentPassword, $newPassword);

        json_response($result);
    }

    /**
     * Get current admin profile
     *
     * @return void
     */
    public function profile(): void
    {
        $this->requireAuth();

        if (request_method() === 'POST') {
            // Update profile
            if (!csrf_verify($_POST['csrf_token'] ?? '')) {
                json_response([
                    'success' => false,
                    'message' => 'Invalid security token'
                ], 403);
            }

            $admin = $this->getCurrentAdmin();
            $updateData = [
                'first_name' => trim($_POST['first_name'] ?? ''),
                'last_name' => trim($_POST['last_name'] ?? ''),
                'email' => trim($_POST['email'] ?? '')
            ];

            // Validate required fields
            foreach (['first_name', 'last_name', 'email'] as $field) {
                if (empty($updateData[$field])) {
                    json_response([
                        'success' => false,
                        'message' => ucfirst(str_replace('_', ' ', $field)) . ' is required'
                    ], 400);
                }
            }

            // Validate email format
            if (!filter_var($updateData['email'], FILTER_VALIDATE_EMAIL)) {
                json_response([
                    'success' => false,
                    'message' => 'Invalid email format'
                ], 400);
            }

            try {
                $updated = $this->adminModel->update($admin['id'], $updateData);

                if ($updated) {
                    // Update session data
                    $updatedAdmin = $this->adminModel->find($admin['id']);
                    Session::set('admin', $updatedAdmin);

                    json_response([
                        'success' => true,
                        'message' => 'Profile updated successfully'
                    ]);
                } else {
                    json_response([
                        'success' => false,
                        'message' => 'Failed to update profile'
                    ], 500);
                }
            } catch (Exception $e) {
                app_log('error', 'Admin profile update error', [
                    'admin_id' => $admin['id'],
                    'error' => $e->getMessage()
                ]);

                json_response([
                    'success' => false,
                    'message' => 'Profile update failed'
                ], 500);
            }
        }

        // Show profile page
        $admin = $this->getCurrentAdmin();

        $data = [
            'title' => 'Profile - WinABN Admin',
            'admin' => $admin,
            'csrf_token' => csrf_token()
        ];

        $this->view->render('admin/profile', $data);
    }

    /**
     * Check if user is logged in
     *
     * @return bool
     */
    public function isLoggedIn(): bool
    {
        $admin = Session::get('admin');
        $loginTime = Session::get('admin_login_time');

        if (!$admin || !$loginTime) {
            return false;
        }

        // Check session timeout
        if (time() - $loginTime > $this->sessionTimeout) {
            Session::destroy();
            return false;
        }

        // Update last activity
        Session::set('admin_last_activity', time());

        return true;
    }

    /**
     * Get current admin data
     *
     * @return array<string, mixed>|null
     */
    public function getCurrentAdmin(): ?array
    {
        if (!$this->isLoggedIn()) {
            return null;
        }

        return Session::get('admin');
    }

    /**
     * Require authentication
     *
     * @return void
     */
    public function requireAuth(): void
    {
        if (!$this->isLoggedIn()) {
            if (request_method() === 'POST' || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
                json_response([
                    'success' => false,
                    'message' => 'Authentication required',
                    'redirect_url' => url('adminportal/login')
                ], 401);
            } else {
                redirect(url('adminportal/login'));
            }
        }
    }

    /**
     * Check permission
     *
     * @param string $permission Permission to check
     * @return bool
     */
    public function hasPermission(string $permission): bool
    {
        $admin = $this->getCurrentAdmin();

        if (!$admin) {
            return false;
        }

        return $this->adminModel->hasPermission($admin['id'], $permission);
    }

    /**
     * Require specific permission
     *
     * @param string $permission Permission required
     * @return void
     */
    public function requirePermission(string $permission): void
    {
        if (!$this->hasPermission($permission)) {
            if (request_method() === 'POST' || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
                json_response([
                    'success' => false,
                    'message' => 'Insufficient permissions'
                ], 403);
            } else {
                $this->view->render('admin/error', [
                    'title' => 'Access Denied',
                    'message' => 'You do not have permission to access this resource.'
                ]);
            }
        }
    }

    /**
     * Create admin session
     *
     * @param array<string, mixed> $admin Admin data
     * @param bool $rememberMe Whether to extend session
     * @return void
     */
    private function createAdminSession(array $admin, bool $rememberMe = false): void
    {
        // Regenerate session ID for security
        Session::regenerate();

        // Store admin data (without sensitive information)
        unset($admin['password_hash'], $admin['two_factor_secret'], $admin['backup_codes']);

        Session::set('admin', $admin);
        Session::set('admin_login_time', time());
        Session::set('admin_last_activity', time());
        Session::set('admin_ip', client_ip());

        // Set longer session for remember me
        if ($rememberMe) {
            ini_set('session.cookie_lifetime', '86400'); // 24 hours
        }
    }

    /**
     * Get dashboard statistics
     *
     * @return array<string, mixed>
     */
    private function getDashboardStats(): array
    {
        try {
            $stats = [];

            // Active games count
            $stats['active_games'] = Database::fetchColumn(
                "SELECT COUNT(*) FROM games WHERE status = 'active'"
            );

            // Active rounds count
            $stats['active_rounds'] = Database::fetchColumn(
                "SELECT COUNT(*) FROM rounds WHERE status = 'active'"
            );

            // Today's participants
            $stats['todays_participants'] = Database::fetchColumn(
                "SELECT COUNT(*) FROM participants WHERE DATE(created_at) = CURDATE()"
            );

            // Today's revenue
            $stats['todays_revenue'] = Database::fetchColumn(
                "SELECT COALESCE(SUM(payment_amount), 0) FROM participants
                 WHERE payment_status = 'paid' AND DATE(payment_completed_at) = CURDATE()"
            );

            // Pending fulfillments
            $stats['pending_fulfillments'] = Database::fetchColumn(
                "SELECT COUNT(*) FROM prize_fulfillments WHERE status = 'pending'"
            );

            return $stats;

        } catch (Exception $e) {
            app_log('error', 'Dashboard stats error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get recent activities
     *
     * @return array<array<string, mixed>>
     */
    private function getRecentActivities(): array
    {
        try {
            $sql = "
                SELECT
                    event_type,
                    details_json,
                    created_at,
                    ip_address
                FROM security_log
                WHERE event_type IN ('login_success', 'login_attempt')
                ORDER BY created_at DESC
                LIMIT 10
            ";

            return Database::fetchAll($sql);

        } catch (Exception $e) {
            app_log('error', 'Recent activities error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Enforce rate limiting
     *
     * @return void
     */
    private function enforceRateLimit(): void
    {
        $ip = client_ip();
        $timeWindow = 900; // 15 minutes
        $maxAttempts = 10;

        $attempts = Database::fetchColumn(
            "SELECT COUNT(*) FROM security_log
             WHERE ip_address = ?
             AND event_type = 'login_attempt'
             AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$ip, $timeWindow]
        );

        if ($attempts >= $maxAttempts) {
            json_response([
                'success' => false,
                'message' => 'Too many login attempts. Please try again later.'
            ], 429);
        }
    }

    /**
     * Log failed login attempt
     *
     * @param string $identifier Username/email attempted
     * @return void
     */
    private function logFailedAttempt(string $identifier): void
    {
        try {
            Database::execute(
                "INSERT INTO security_log (ip_address, event_type, details_json, user_agent, request_uri)
                 VALUES (?, 'login_attempt', ?, ?, ?)",
                [
                    client_ip(),
                    json_encode(['identifier' => $identifier, 'result' => 'failed']),
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    $_SERVER['REQUEST_URI'] ?? null
                ]
            );
        } catch (Exception $e) {
            app_log('error', 'Failed to log failed attempt', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Clear failed attempts for current IP
     *
     * @return void
     */
    private function clearFailedAttempts(): void
    {
        try {
            Database::execute(
                "UPDATE security_log SET is_resolved = 1
                 WHERE ip_address = ? AND event_type = 'login_attempt' AND is_resolved = 0",
                [client_ip()]
            );
        } catch (Exception $e) {
            app_log('error', 'Failed to clear failed attempts', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get remaining login attempts for current IP
     *
     * @return int
     */
    private function getLoginAttemptsRemaining(): int
    {
        $ip = client_ip();
        $timeWindow = 900; // 15 minutes
        $maxAttempts = 10;

        $attempts = Database::fetchColumn(
            "SELECT COUNT(*) FROM security_log
             WHERE ip_address = ?
             AND event_type = 'login_attempt'
             AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$ip, $timeWindow]
        );

        return max(0, $maxAttempts - (int) $attempts);
    }

    /**
     * Check if current IP is locked
     *
     * @return bool
     */
    private function isCurrentIpLocked(): bool
    {
        return $this->getLoginAttemptsRemaining() <= 0;
    }
}
