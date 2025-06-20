<?php
/**
 * File: /controllers/admin/AuthController.php
 * Admin Authentication Controller for Win a Brand New
 * Handles admin login, 2FA, session management, and security features
 */

require_once __DIR__ . '/../BaseController.php';
require_once __DIR__ . '/../../models/AdminUser.php';
require_once __DIR__ . '/../../core/Security.php';
require_once __DIR__ . '/../../core/Logger.php';

class AuthController extends BaseController
{
    private $adminUserModel;
    private $security;
    private $logger;

    // Rate limiting configuration
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_DURATION = 900; // 15 minutes in seconds
    private const SESSION_TIMEOUT = 7200; // 2 hours in seconds

    public function __construct()
    {
        parent::__construct();
        $this->adminUserModel = new AdminUser();
        $this->security = new Security();
        $this->logger = new Logger();

        // Set admin-specific security headers
        $this->setSecurityHeaders();
    }

    /**
     * Display admin login form
     */
    public function showLogin()
    {
        // Redirect if already authenticated
        if ($this->isAdminAuthenticated()) {
            $this->redirect('/admin/dashboard');
            return;
        }

        $data = [
            'page_title' => 'Admin Login - Win a Brand New',
            'csrf_token' => $this->generateCSRFToken(),
            'login_attempts' => $this->getFailedAttempts(),
            'lockout_remaining' => $this->getLockoutRemaining(),
            'two_factor_required' => false
        ];

        $this->loadView('admin/login', $data);
    }

    /**
     * Process admin login attempt
     */
    public function login()
    {
        try {
            // Validate CSRF token
            if (!$this->validateCSRFToken()) {
                $this->logSecurity('Invalid CSRF token in admin login attempt');
                return $this->jsonError('Security token invalid', 403);
            }

            // Check if IP is locked out
            if ($this->isLockedOut()) {
                $remaining = $this->getLockoutRemaining();
                $this->logSecurity('Login attempt from locked out IP: ' . $this->getClientIP());
                return $this->jsonError("Too many failed attempts. Try again in {$remaining} minutes.", 429);
            }

            // Get and validate input
            $email = $this->getInput('email', FILTER_VALIDATE_EMAIL);
            $password = $this->getInput('password');
            $remember_me = $this->getInput('remember_me', FILTER_VALIDATE_BOOLEAN);

            if (!$email || !$password) {
                $this->recordFailedAttempt();
                return $this->jsonError('Email and password are required', 400);
            }

            // Attempt authentication
            $admin = $this->adminUserModel->authenticate($email, $password);

            if (!$admin) {
                $this->recordFailedAttempt();
                $this->logSecurity("Failed admin login attempt for email: {$email}");
                return $this->jsonError('Invalid credentials', 401);
            }

            // Check if admin account is active
            if (!$admin['is_active']) {
                $this->logSecurity("Login attempt for inactive admin: {$email}");
                return $this->jsonError('Account is disabled', 401);
            }

            // Check if 2FA is required
            if ($admin['two_factor_enabled']) {
                // Store partial session for 2FA
                $_SESSION['admin_2fa_pending'] = [
                    'admin_id' => $admin['id'],
                    'email' => $admin['email'],
                    'timestamp' => time(),
                    'remember_me' => $remember_me
                ];

                return $this->jsonSuccess([
                    'requires_2fa' => true,
                    'message' => 'Please enter your 2FA code'
                ]);
            }

            // Complete login without 2FA
            $this->completeLogin($admin, $remember_me);

            return $this->jsonSuccess([
                'message' => 'Login successful',
                'redirect' => '/admin/dashboard'
            ]);

        } catch (Exception $e) {
            $this->logger->error('Admin login error: ' . $e->getMessage());
            return $this->jsonError('Login failed. Please try again.', 500);
        }
    }

    /**
     * Verify 2FA code and complete login
     */
    public function verify2FA()
    {
        try {
            // Check if 2FA verification is pending
            if (!isset($_SESSION['admin_2fa_pending'])) {
                return $this->jsonError('No 2FA verification pending', 400);
            }

            $pending = $_SESSION['admin_2fa_pending'];

            // Check if 2FA session hasn't expired (5 minutes)
            if (time() - $pending['timestamp'] > 300) {
                unset($_SESSION['admin_2fa_pending']);
                return $this->jsonError('2FA session expired. Please login again.', 400);
            }

            // Validate CSRF token
            if (!$this->validateCSRFToken()) {
                return $this->jsonError('Security token invalid', 403);
            }

            $totp_code = $this->getInput('totp_code');

            if (!$totp_code || !preg_match('/^\d{6}$/', $totp_code)) {
                return $this->jsonError('Invalid 2FA code format', 400);
            }

            // Get admin details
            $admin = $this->adminUserModel->getById($pending['admin_id']);

            if (!$admin) {
                unset($_SESSION['admin_2fa_pending']);
                return $this->jsonError('Admin account not found', 404);
            }

            // Verify TOTP code
            if (!$this->security->verifyTOTP($admin['totp_secret'], $totp_code)) {
                $this->recordFailedAttempt();
                $this->logSecurity("Failed 2FA attempt for admin: {$admin['email']}");
                return $this->jsonError('Invalid 2FA code', 401);
            }

            // Complete login
            $this->completeLogin($admin, $pending['remember_me']);

            // Clear 2FA pending session
            unset($_SESSION['admin_2fa_pending']);

            return $this->jsonSuccess([
                'message' => '2FA verification successful',
                'redirect' => '/admin/dashboard'
            ]);

        } catch (Exception $e) {
            $this->logger->error('2FA verification error: ' . $e->getMessage());
            return $this->jsonError('Verification failed. Please try again.', 500);
        }
    }

    /**
     * Complete the login process
     */
    private function completeLogin($admin, $remember_me = false)
    {
        // Clear failed attempts
        $this->clearFailedAttempts();

        // Update last login
        $this->adminUserModel->updateLastLogin($admin['id']);

        // Create admin session
        $_SESSION['admin'] = [
            'id' => $admin['id'],
            'email' => $admin['email'],
            'name' => $admin['name'],
            'role' => $admin['role'],
            'permissions' => json_decode($admin['permissions'], true),
            'login_time' => time(),
            'last_activity' => time(),
            'ip_address' => $this->getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ];

        // Set remember me cookie if requested
        if ($remember_me) {
            $this->setRememberMeCookie($admin['id']);
        }

        // Log successful login
        $this->logSecurity("Successful admin login: {$admin['email']}");
    }

    /**
     * Admin logout
     */
    public function logout()
    {
        if (isset($_SESSION['admin'])) {
            $email = $_SESSION['admin']['email'];
            $this->logSecurity("Admin logout: {$email}");
        }

        // Clear admin session
        unset($_SESSION['admin']);
        unset($_SESSION['admin_2fa_pending']);

        // Clear remember me cookie
        if (isset($_COOKIE['admin_remember'])) {
            setcookie('admin_remember', '', time() - 3600, '/', '', true, true);
        }

        // Regenerate session ID
        session_regenerate_id(true);

        $this->redirect('/admin/login?logged_out=1');
    }

    /**
     * Password reset request
     */
    public function requestPasswordReset()
    {
        try {
            // Validate CSRF token
            if (!$this->validateCSRFToken()) {
                return $this->jsonError('Security token invalid', 403);
            }

            $email = $this->getInput('email', FILTER_VALIDATE_EMAIL);

            if (!$email) {
                return $this->jsonError('Valid email address is required', 400);
            }

            // Check if admin exists
            $admin = $this->adminUserModel->getByEmail($email);

            // Always return success to prevent email enumeration
            if ($admin && $admin['is_active']) {
                // Generate reset token
                $token = $this->security->generateSecureToken(32);
                $expires = time() + 3600; // 1 hour

                // Store reset token
                $this->adminUserModel->storePasswordResetToken($admin['id'], $token, $expires);

                // Send reset email (implementation depends on email system)
                $this->sendPasswordResetEmail($admin, $token);

                $this->logSecurity("Password reset requested for admin: {$email}");
            }

            return $this->jsonSuccess([
                'message' => 'If an account with that email exists, a password reset link has been sent.'
            ]);

        } catch (Exception $e) {
            $this->logger->error('Password reset request error: ' . $e->getMessage());
            return $this->jsonError('Reset request failed. Please try again.', 500);
        }
    }

    /**
     * Process password reset
     */
    public function resetPassword()
    {
        try {
            $token = $this->getInput('token');
            $password = $this->getInput('password');
            $confirm_password = $this->getInput('confirm_password');

            if (!$token || !$password || !$confirm_password) {
                return $this->jsonError('All fields are required', 400);
            }

            if ($password !== $confirm_password) {
                return $this->jsonError('Passwords do not match', 400);
            }

            // Validate password strength
            if (!$this->security->isStrongPassword($password)) {
                return $this->jsonError('Password does not meet security requirements', 400);
            }

            // Verify and consume reset token
            $admin = $this->adminUserModel->verifyPasswordResetToken($token);

            if (!$admin) {
                return $this->jsonError('Invalid or expired reset token', 400);
            }

            // Update password
            $this->adminUserModel->updatePassword($admin['id'], $password);

            // Log password reset
            $this->logSecurity("Password reset completed for admin: {$admin['email']}");

            return $this->jsonSuccess([
                'message' => 'Password reset successful. You can now login.'
            ]);

        } catch (Exception $e) {
            $this->logger->error('Password reset error: ' . $e->getMessage());
            return $this->jsonError('Password reset failed. Please try again.', 500);
        }
    }

    /**
     * Check if admin is authenticated
     */
    public function isAdminAuthenticated()
    {
        if (!isset($_SESSION['admin'])) {
            return false;
        }

        $admin = $_SESSION['admin'];

        // Check session timeout
        if (time() - $admin['last_activity'] > self::SESSION_TIMEOUT) {
            $this->logout();
            return false;
        }

        // Update last activity
        $_SESSION['admin']['last_activity'] = time();

        return true;
    }

    /**
     * Record failed login attempt
     */
    private function recordFailedAttempt()
    {
        $ip = $this->getClientIP();
        $key = "admin_login_attempts_{$ip}";

        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [];
        }

        $_SESSION[$key][] = time();

        // Keep only attempts within lockout duration
        $_SESSION[$key] = array_filter($_SESSION[$key], function($timestamp) {
            return (time() - $timestamp) <= self::LOCKOUT_DURATION;
        });
    }

    /**
     * Get number of failed attempts
     */
    private function getFailedAttempts()
    {
        $ip = $this->getClientIP();
        $key = "admin_login_attempts_{$ip}";

        if (!isset($_SESSION[$key])) {
            return 0;
        }

        return count($_SESSION[$key]);
    }

    /**
     * Check if IP is locked out
     */
    private function isLockedOut()
    {
        return $this->getFailedAttempts() >= self::MAX_LOGIN_ATTEMPTS;
    }

    /**
     * Get remaining lockout time in minutes
     */
    private function getLockoutRemaining()
    {
        if (!$this->isLockedOut()) {
            return 0;
        }

        $ip = $this->getClientIP();
        $key = "admin_login_attempts_{$ip}";

        if (!isset($_SESSION[$key]) || empty($_SESSION[$key])) {
            return 0;
        }

        $oldest_attempt = min($_SESSION[$key]);
        $remaining_seconds = self::LOCKOUT_DURATION - (time() - $oldest_attempt);

        return max(0, ceil($remaining_seconds / 60));
    }

    /**
     * Clear failed attempts for IP
     */
    private function clearFailedAttempts()
    {
        $ip = $this->getClientIP();
        $key = "admin_login_attempts_{$ip}";
        unset($_SESSION[$key]);
    }

    /**
     * Set remember me cookie
     */
    private function setRememberMeCookie($admin_id)
    {
        $token = $this->security->generateSecureToken(32);
        $expires = time() + (30 * 24 * 3600); // 30 days

        // Store remember token in database
        $this->adminUserModel->storeRememberToken($admin_id, $token, $expires);

        // Set cookie
        setcookie('admin_remember', $token, $expires, '/', '', true, true);
    }

    /**
     * Send password reset email
     */
    private function sendPasswordResetEmail($admin, $token)
    {
        // Implementation would depend on your email system
        // This is a placeholder for the email sending functionality

        $reset_url = "https://{$_SERVER['HTTP_HOST']}/admin/reset-password?token={$token}";

        // Add to email queue or send directly
        // For now, we'll log the reset URL
        $this->logger->info("Password reset URL for {$admin['email']}: {$reset_url}");
    }

    /**
     * Set admin-specific security headers
     */
    private function setSecurityHeaders()
    {
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    }

    /**
     * Log security events
     */
    private function logSecurity($message)
    {
        $ip = $this->getClientIP();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

        $this->logger->security("ADMIN_AUTH: {$message} | IP: {$ip} | UA: {$user_agent}");
    }
}
?>
