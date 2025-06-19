<?php
declare(strict_types=1);

/**
 * File: models/Admin.php
 * Location: models/Admin.php
 *
 * WinABN Admin Model
 *
 * Handles admin user authentication, 2FA management, and session handling
 * for the WinABN administration portal.
 *
 * @package WinABN\Models
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Models;

use WinABN\Core\{Model, Security, Database};
use Exception;

class Admin extends Model
{
    /**
     * Table name
     *
     * @var string
     */
    protected string $table = 'admins';

    /**
     * Primary key
     *
     * @var string
     */
    protected string $primaryKey = 'id';

    /**
     * Admin roles
     *
     * @var array<string>
     */
    public const ROLES = [
        'super_admin' => 'Super Administrator',
        'admin' => 'Administrator',
        'manager' => 'Manager',
        'viewer' => 'Viewer'
    ];

    /**
     * Role permissions mapping
     *
     * @var array<string, array<string>>
     */
    public const PERMISSIONS = [
        'super_admin' => ['*'], // All permissions
        'admin' => [
            'games.view', 'games.create', 'games.edit', 'games.delete',
            'rounds.view', 'rounds.manage',
            'participants.view', 'participants.manage',
            'fulfillment.view', 'fulfillment.manage',
            'analytics.view',
            'settings.view', 'settings.edit'
        ],
        'manager' => [
            'games.view', 'games.edit',
            'rounds.view', 'rounds.manage',
            'participants.view', 'participants.manage',
            'fulfillment.view', 'fulfillment.manage',
            'analytics.view'
        ],
        'viewer' => [
            'games.view',
            'rounds.view',
            'participants.view',
            'fulfillment.view',
            'analytics.view'
        ]
    ];

    /**
     * Create admin table if not exists
     *
     * @return void
     */
    public static function createTable(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS `admins` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `username` varchar(50) NOT NULL,
                `email` varchar(255) NOT NULL,
                `password_hash` varchar(255) NOT NULL,
                `first_name` varchar(100) NOT NULL,
                `last_name` varchar(100) NOT NULL,
                `role` enum('super_admin','admin','manager','viewer') NOT NULL DEFAULT 'viewer',
                `is_active` boolean NOT NULL DEFAULT true,
                `two_factor_enabled` boolean NOT NULL DEFAULT false,
                `two_factor_secret` varchar(32) NULL,
                `backup_codes` json NULL,
                `last_login_at` timestamp NULL,
                `last_login_ip` varchar(45) NULL,
                `failed_login_attempts` int unsigned NOT NULL DEFAULT 0,
                `locked_until` timestamp NULL,
                `password_changed_at` timestamp NULL,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_admins_username` (`username`),
                UNIQUE KEY `idx_admins_email` (`email`),
                KEY `idx_admins_role` (`role`),
                KEY `idx_admins_active` (`is_active`),
                KEY `idx_admins_locked` (`locked_until`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        Database::exec($sql);
    }

    /**
     * Find admin by username or email
     *
     * @param string $identifier Username or email
     * @return array<string, mixed>|null
     */
    public function findByIdentifier(string $identifier): ?array
    {
        $sql = "
            SELECT * FROM {$this->table}
            WHERE (username = ? OR email = ?)
            AND is_active = 1
        ";

        return Database::fetchOne($sql, [$identifier, $identifier]);
    }

    /**
     * Authenticate admin login
     *
     * @param string $identifier Username or email
     * @param string $password Plain text password
     * @param string|null $totpCode 2FA TOTP code (if enabled)
     * @return array<string, mixed> Authentication result
     */
    public function authenticate(string $identifier, string $password, ?string $totpCode = null): array
    {
        try {
            $admin = $this->findByIdentifier($identifier);

            if (!$admin) {
                $this->logSecurityEvent('login_attempt', [
                    'identifier' => $identifier,
                    'result' => 'user_not_found',
                    'ip' => client_ip()
                ]);

                return [
                    'success' => false,
                    'message' => 'Invalid credentials',
                    'code' => 'INVALID_CREDENTIALS'
                ];
            }

            // Check if account is locked
            if ($this->isAccountLocked($admin)) {
                return [
                    'success' => false,
                    'message' => 'Account temporarily locked due to failed login attempts',
                    'code' => 'ACCOUNT_LOCKED'
                ];
            }

            // Verify password
            if (!password_verify($password, $admin['password_hash'])) {
                $this->incrementFailedAttempts($admin['id']);

                $this->logSecurityEvent('login_attempt', [
                    'admin_id' => $admin['id'],
                    'identifier' => $identifier,
                    'result' => 'invalid_password',
                    'ip' => client_ip()
                ]);

                return [
                    'success' => false,
                    'message' => 'Invalid credentials',
                    'code' => 'INVALID_CREDENTIALS'
                ];
            }

            // Check 2FA if enabled
            if ($admin['two_factor_enabled']) {
                if (!$totpCode) {
                    return [
                        'success' => false,
                        'message' => '2FA code required',
                        'code' => 'TOTP_REQUIRED',
                        'admin_id' => $admin['id']
                    ];
                }

                if (!$this->verifyTotpCode($admin['two_factor_secret'], $totpCode)) {
                    $this->incrementFailedAttempts($admin['id']);

                    $this->logSecurityEvent('login_attempt', [
                        'admin_id' => $admin['id'],
                        'identifier' => $identifier,
                        'result' => 'invalid_2fa',
                        'ip' => client_ip()
                    ]);

                    return [
                        'success' => false,
                        'message' => 'Invalid 2FA code',
                        'code' => 'INVALID_TOTP'
                    ];
                }
            }

            // Successful login
            $this->updateLoginSuccess($admin['id']);

            $this->logSecurityEvent('login_success', [
                'admin_id' => $admin['id'],
                'identifier' => $identifier,
                'ip' => client_ip()
            ]);

            return [
                'success' => true,
                'admin' => $this->sanitizeAdminData($admin),
                'message' => 'Authentication successful'
            ];

        } catch (Exception $e) {
            app_log('error', 'Admin authentication error', [
                'identifier' => $identifier,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Authentication system error',
                'code' => 'SYSTEM_ERROR'
            ];
        }
    }

    /**
     * Create new admin account
     *
     * @param array<string, mixed> $data Admin data
     * @return array<string, mixed>
     */
    public function createAdmin(array $data): array
    {
        try {
            // Validate required fields
            $required = ['username', 'email', 'password', 'first_name', 'last_name', 'role'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return [
                        'success' => false,
                        'message' => "Missing required field: $field"
                    ];
                }
            }

            // Validate role
            if (!array_key_exists($data['role'], self::ROLES)) {
                return [
                    'success' => false,
                    'message' => 'Invalid role specified'
                ];
            }

            // Check for existing username/email
            $existing = Database::fetchOne(
                "SELECT id FROM {$this->table} WHERE username = ? OR email = ?",
                [$data['username'], $data['email']]
            );

            if ($existing) {
                return [
                    'success' => false,
                    'message' => 'Username or email already exists'
                ];
            }

            // Hash password
            $passwordHash = password_hash($data['password'], PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost' => 4,
                'threads' => 3
            ]);

            $adminData = [
                'username' => $data['username'],
                'email' => $data['email'],
                'password_hash' => $passwordHash,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'role' => $data['role'],
                'is_active' => $data['is_active'] ?? true,
                'password_changed_at' => date('Y-m-d H:i:s')
            ];

            $adminId = $this->create($adminData);

            return [
                'success' => true,
                'admin_id' => $adminId,
                'message' => 'Admin account created successfully'
            ];

        } catch (Exception $e) {
            app_log('error', 'Admin creation error', [
                'data' => $data,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to create admin account'
            ];
        }
    }

    /**
     * Enable 2FA for admin
     *
     * @param int $adminId Admin ID
     * @return array<string, mixed>
     */
    public function enable2FA(int $adminId): array
    {
        try {
            $secret = $this->generateTotpSecret();
            $backupCodes = $this->generateBackupCodes();

            $updated = $this->update($adminId, [
                'two_factor_secret' => $secret,
                'backup_codes' => json_encode($backupCodes),
                'two_factor_enabled' => false // Will be enabled after verification
            ]);

            if (!$updated) {
                return [
                    'success' => false,
                    'message' => 'Failed to initialize 2FA'
                ];
            }

            return [
                'success' => true,
                'secret' => $secret,
                'backup_codes' => $backupCodes,
                'qr_code_url' => $this->generateQrCodeUrl($secret, $adminId)
            ];

        } catch (Exception $e) {
            app_log('error', '2FA setup error', [
                'admin_id' => $adminId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to setup 2FA'
            ];
        }
    }

    /**
     * Verify and activate 2FA
     *
     * @param int $adminId Admin ID
     * @param string $totpCode TOTP code from authenticator
     * @return array<string, mixed>
     */
    public function verify2FA(int $adminId, string $totpCode): array
    {
        try {
            $admin = $this->find($adminId);
            if (!$admin || !$admin['two_factor_secret']) {
                return [
                    'success' => false,
                    'message' => '2FA not initialized'
                ];
            }

            if (!$this->verifyTotpCode($admin['two_factor_secret'], $totpCode)) {
                return [
                    'success' => false,
                    'message' => 'Invalid verification code'
                ];
            }

            $this->update($adminId, ['two_factor_enabled' => true]);

            return [
                'success' => true,
                'message' => '2FA enabled successfully'
            ];

        } catch (Exception $e) {
            app_log('error', '2FA verification error', [
                'admin_id' => $adminId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to verify 2FA'
            ];
        }
    }

    /**
     * Disable 2FA for admin
     *
     * @param int $adminId Admin ID
     * @param string $currentPassword Current password for verification
     * @return array<string, mixed>
     */
    public function disable2FA(int $adminId, string $currentPassword): array
    {
        try {
            $admin = $this->find($adminId);
            if (!$admin) {
                return [
                    'success' => false,
                    'message' => 'Admin not found'
                ];
            }

            // Verify current password
            if (!password_verify($currentPassword, $admin['password_hash'])) {
                return [
                    'success' => false,
                    'message' => 'Invalid password'
                ];
            }

            $this->update($adminId, [
                'two_factor_enabled' => false,
                'two_factor_secret' => null,
                'backup_codes' => null
            ]);

            return [
                'success' => true,
                'message' => '2FA disabled successfully'
            ];

        } catch (Exception $e) {
            app_log('error', '2FA disable error', [
                'admin_id' => $adminId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to disable 2FA'
            ];
        }
    }

    /**
     * Check if admin has permission
     *
     * @param int $adminId Admin ID
     * @param string $permission Permission to check
     * @return bool
     */
    public function hasPermission(int $adminId, string $permission): bool
    {
        $admin = $this->find($adminId);
        if (!$admin || !$admin['is_active']) {
            return false;
        }

        $role = $admin['role'];
        $permissions = self::PERMISSIONS[$role] ?? [];

        // Super admin has all permissions
        if (in_array('*', $permissions)) {
            return true;
        }

        return in_array($permission, $permissions);
    }

    /**
     * Update admin password
     *
     * @param int $adminId Admin ID
     * @param string $currentPassword Current password
     * @param string $newPassword New password
     * @return array<string, mixed>
     */
    public function updatePassword(int $adminId, string $currentPassword, string $newPassword): array
    {
        try {
            $admin = $this->find($adminId);
            if (!$admin) {
                return [
                    'success' => false,
                    'message' => 'Admin not found'
                ];
            }

            // Verify current password
            if (!password_verify($currentPassword, $admin['password_hash'])) {
                return [
                    'success' => false,
                    'message' => 'Current password is incorrect'
                ];
            }

            // Validate new password strength
            if (strlen($newPassword) < 8) {
                return [
                    'success' => false,
                    'message' => 'Password must be at least 8 characters long'
                ];
            }

            $newPasswordHash = password_hash($newPassword, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost' => 4,
                'threads' => 3
            ]);

            $this->update($adminId, [
                'password_hash' => $newPasswordHash,
                'password_changed_at' => date('Y-m-d H:i:s')
            ]);

            return [
                'success' => true,
                'message' => 'Password updated successfully'
            ];

        } catch (Exception $e) {
            app_log('error', 'Password update error', [
                'admin_id' => $adminId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to update password'
            ];
        }
    }

    /**
     * Check if account is locked
     *
     * @param array<string, mixed> $admin Admin data
     * @return bool
     */
    private function isAccountLocked(array $admin): bool
    {
        if (!$admin['locked_until']) {
            return false;
        }

        return strtotime($admin['locked_until']) > time();
    }

    /**
     * Increment failed login attempts
     *
     * @param int $adminId Admin ID
     * @return void
     */
    private function incrementFailedAttempts(int $adminId): void
    {
        $maxAttempts = (int) env('ADMIN_LOGIN_ATTEMPTS', 5);
        $lockoutDuration = (int) env('ADMIN_LOGIN_LOCKOUT_DURATION', 900); // 15 minutes

        $sql = "
            UPDATE {$this->table}
            SET
                failed_login_attempts = failed_login_attempts + 1,
                locked_until = CASE
                    WHEN failed_login_attempts + 1 >= ?
                    THEN DATE_ADD(NOW(), INTERVAL ? SECOND)
                    ELSE locked_until
                END
            WHERE id = ?
        ";

        Database::execute($sql, [$maxAttempts, $lockoutDuration, $adminId]);
    }

    /**
     * Update successful login
     *
     * @param int $adminId Admin ID
     * @return void
     */
    private function updateLoginSuccess(int $adminId): void
    {
        $this->update($adminId, [
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => client_ip()
        ]);
    }

    /**
     * Generate TOTP secret
     *
     * @return string
     */
    private function generateTotpSecret(): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';

        for ($i = 0; $i < 32; $i++) {
            $secret .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $secret;
    }

    /**
     * Generate backup codes
     *
     * @return array<string>
     */
    private function generateBackupCodes(): array
    {
        $codes = [];

        for ($i = 0; $i < 10; $i++) {
            $codes[] = sprintf('%04d-%04d', random_int(1000, 9999), random_int(1000, 9999));
        }

        return $codes;
    }

    /**
     * Verify TOTP code
     *
     * @param string $secret TOTP secret
     * @param string $code User provided code
     * @return bool
     */
    private function verifyTotpCode(string $secret, string $code): bool
    {
        // Simple TOTP implementation - in production, use a proper library like RobThree/TwoFactorAuth
        $timeStep = 30;
        $currentTime = floor(time() / $timeStep);

        // Check current time window and adjacent windows for clock skew
        for ($i = -1; $i <= 1; $i++) {
            $timeCounter = $currentTime + $i;
            $expectedCode = $this->generateTotpCode($secret, $timeCounter);

            if (hash_equals($expectedCode, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate TOTP code for time counter
     *
     * @param string $secret TOTP secret
     * @param int $timeCounter Time counter
     * @return string
     */
    private function generateTotpCode(string $secret, int $timeCounter): string
    {
        // Simplified TOTP generation - use proper library in production
        $secretBytes = $this->base32Decode($secret);
        $timeBytes = pack('N*', 0, $timeCounter);

        $hash = hash_hmac('sha1', $timeBytes, $secretBytes, true);
        $offset = ord($hash[19]) & 0xf;

        $code = (
            ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        ) % 1000000;

        return sprintf('%06d', $code);
    }

    /**
     * Base32 decode
     *
     * @param string $input Base32 encoded string
     * @return string
     */
    private function base32Decode(string $input): string
    {
        $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $input = strtoupper($input);
        $output = '';
        $buffer = 0;
        $bitsLeft = 0;

        for ($i = 0; $i < strlen($input); $i++) {
            $val = strpos($map, $input[$i]);
            if ($val === false) continue;

            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $output .= chr(($buffer >> ($bitsLeft - 8)) & 255);
                $bitsLeft -= 8;
            }
        }

        return $output;
    }

    /**
     * Generate QR code URL for 2FA setup
     *
     * @param string $secret TOTP secret
     * @param int $adminId Admin ID
     * @return string
     */
    private function generateQrCodeUrl(string $secret, int $adminId): string
    {
        $admin = $this->find($adminId);
        $issuer = env('APP_NAME', 'WinABN');
        $account = $admin['email'];

        $uri = sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s',
            urlencode($issuer),
            urlencode($account),
            $secret,
            urlencode($issuer)
        );

        return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($uri);
    }

    /**
     * Sanitize admin data for frontend
     *
     * @param array<string, mixed> $admin Admin data
     * @return array<string, mixed>
     */
    private function sanitizeAdminData(array $admin): array
    {
        unset(
            $admin['password_hash'],
            $admin['two_factor_secret'],
            $admin['backup_codes']
        );

        return $admin;
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
            $sql = "
                INSERT INTO security_log (ip_address, event_type, details_json, user_agent, request_uri)
                VALUES (?, ?, ?, ?, ?)
            ";

            Database::execute($sql, [
                client_ip(),
                $eventType,
                json_encode($details),
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $_SERVER['REQUEST_URI'] ?? null
            ]);
        } catch (Exception $e) {
            app_log('error', 'Failed to log security event', [
                'event_type' => $eventType,
                'error' => $e->getMessage()
            ]);
        }
    }
}
