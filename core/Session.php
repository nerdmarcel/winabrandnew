<?php
declare(strict_types=1);

/**
 * File: core/Session.php
 * Location: core/Session.php
 *
 * WinABN Session Management
 *
 * Provides secure session handling with database storage, flash messages,
 * session regeneration, and security features for the WinABN platform.
 *
 * @package WinABN\Core
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Core;

use Exception;

class Session
{
    /**
     * Session started flag
     *
     * @var bool
     */
    private static bool $started = false;

    /**
     * Flash data key prefix
     *
     * @var string
     */
    private const FLASH_PREFIX = '_flash_';

    /**
     * Old flash data key prefix
     *
     * @var string
     */
    private const OLD_FLASH_PREFIX = '_old_flash_';

    /**
     * Session configuration
     *
     * @var array<string, mixed>
     */
    private static array $config = [];

    /**
     * Constructor - starts session if not already started
     */
    public function __construct()
    {
        if (!self::$started) {
            $this->startSession();
        }
    }

    /**
     * Start session with security settings
     *
     * @return void
     * @throws Exception
     */
    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        // Load session configuration
        self::$config = [
            'name' => env('SESSION_NAME', 'WINABN_SESSION'),
            'lifetime' => (int) env('SESSION_LIFETIME', 7200), // 2 hours
            'path' => '/',
            'domain' => env('SESSION_DOMAIN', ''),
            'secure' => env('SESSION_SECURE', true),
            'httponly' => env('SESSION_HTTP_ONLY', true),
            'samesite' => env('SESSION_SAME_SITE', 'Strict'),
            'storage' => env('SESSION_STORAGE', 'file'), // file or database
        ];

        // Configure session settings
        ini_set('session.name', self::$config['name']);
        ini_set('session.gc_maxlifetime', (string) self::$config['lifetime']);
        ini_set('session.cookie_lifetime', (string) self::$config['lifetime']);
        ini_set('session.cookie_path', self::$config['path']);
        ini_set('session.cookie_domain', self::$config['domain']);
        ini_set('session.cookie_secure', self::$config['secure'] ? '1' : '0');
        ini_set('session.cookie_httponly', self::$config['httponly'] ? '1' : '0');
        ini_set('session.cookie_samesite', self::$config['samesite']);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');

        // Set custom session handler for database storage
        if (self::$config['storage'] === 'database') {
            $this->setDatabaseHandler();
        }

        // Start the session
        if (!session_start()) {
            throw new Exception('Failed to start session');
        }

        self::$started = true;

        // Regenerate session ID periodically for security
        $this->handleSessionSecurity();

        // Clean up old flash data
        $this->cleanupFlashData();
    }

    /**
     * Set database session handler
     *
     * @return void
     */
    private function setDatabaseHandler(): void
    {
        session_set_save_handler(
            [$this, 'sessionOpen'],
            [$this, 'sessionClose'],
            [$this, 'sessionRead'],
            [$this, 'sessionWrite'],
            [$this, 'sessionDestroy'],
            [$this, 'sessionGc']
        );
    }

    /**
     * Handle session security measures
     *
     * @return void
     */
    private function handleSessionSecurity(): void
    {
        // Check if session needs regeneration
        if (!$this->has('_session_started')) {
            $this->regenerateId();
            $this->set('_session_started', time());
        }

        // Regenerate ID every 30 minutes for active sessions
        $lastRegeneration = $this->get('_last_regeneration', 0);
        if (time() - $lastRegeneration > 1800) { // 30 minutes
            $this->regenerateId();
            $this->set('_last_regeneration', time());
        }

        // Validate session fingerprint
        $this->validateFingerprint();
    }

    /**
     * Validate session fingerprint to prevent hijacking
     *
     * @return void
     */
    private function validateFingerprint(): void
    {
        $currentFingerprint = $this->generateFingerprint();
        $storedFingerprint = $this->get('_fingerprint');

        if ($storedFingerprint === null) {
            $this->set('_fingerprint', $currentFingerprint);
        } elseif ($storedFingerprint !== $currentFingerprint) {
            // Potential session hijacking - destroy session
            $this->destroy();
            throw new Exception('Session security violation detected');
        }
    }

    /**
     * Generate session fingerprint
     *
     * @return string
     */
    private function generateFingerprint(): string
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $acceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        $acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';

        return hash('sha256', $userAgent . $acceptLanguage . $acceptEncoding);
    }

    /**
     * Set session value
     *
     * @param string $key Session key
     * @param mixed $value Session value
     * @return void
     */
    public function set(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Get session value
     *
     * @param string $key Session key
     * @param mixed $default Default value
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Check if session key exists
     *
     * @param string $key Session key
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    /**
     * Remove session value
     *
     * @param string $key Session key
     * @return void
     */
    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Get all session data
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $_SESSION ?? [];
    }

    /**
     * Clear all session data
     *
     * @return void
     */
    public function clear(): void
    {
        $_SESSION = [];
    }

    /**
     * Destroy session completely
     *
     * @return void
     */
    public function destroy(): void
    {
        if (self::$started) {
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
            self::$started = false;
        }
    }

    /**
     * Regenerate session ID
     *
     * @param bool $deleteOldSession Delete old session data
     * @return void
     */
    public function regenerateId(bool $deleteOldSession = true): void
    {
        if (self::$started) {
            session_regenerate_id($deleteOldSession);
        }
    }

    /**
     * Get session ID
     *
     * @return string
     */
    public function getId(): string
    {
        return session_id();
    }

    /**
     * Set flash message for next request
     *
     * @param string $key Flash message key
     * @param mixed $value Flash message value
     * @return void
     */
    public function setFlash(string $key, $value): void
    {
        $this->set(self::FLASH_PREFIX . $key, $value);
    }

    /**
     * Get flash message and remove it
     *
     * @param string $key Flash message key
     * @param mixed $default Default value
     * @return mixed
     */
    public function getFlash(string $key, $default = null)
    {
        $flashKey = self::FLASH_PREFIX . $key;
        $oldFlashKey = self::OLD_FLASH_PREFIX . $key;

        // Check for new flash data first
        if ($this->has($flashKey)) {
            $value = $this->get($flashKey);
            $this->remove($flashKey);
            return $value;
        }

        // Check for old flash data
        if ($this->has($oldFlashKey)) {
            $value = $this->get($oldFlashKey);
            $this->remove($oldFlashKey);
            return $value;
        }

        return $default;
    }

    /**
     * Check if flash message exists
     *
     * @param string $key Flash message key
     * @return bool
     */
    public function hasFlash(string $key): bool
    {
        return $this->has(self::FLASH_PREFIX . $key) || $this->has(self::OLD_FLASH_PREFIX . $key);
    }

    /**
     * Keep flash data for another request
     *
     * @param array<string>|string $keys Flash keys to keep
     * @return void
     */
    public function reflash($keys = null): void
    {
        if ($keys === null) {
            // Keep all flash data
            foreach ($_SESSION as $key => $value) {
                if (strpos($key, self::OLD_FLASH_PREFIX) === 0) {
                    $newKey = str_replace(self::OLD_FLASH_PREFIX, self::FLASH_PREFIX, $key);
                    $this->set($newKey, $value);
                }
            }
        } else {
            $keys = is_array($keys) ? $keys : [$keys];
            foreach ($keys as $key) {
                $oldKey = self::OLD_FLASH_PREFIX . $key;
                if ($this->has($oldKey)) {
                    $this->setFlash($key, $this->get($oldKey));
                }
            }
        }
    }

    /**
     * Clean up flash data
     *
     * @return void
     */
    private function cleanupFlashData(): void
    {
        // Move current flash data to old flash data
        foreach ($_SESSION as $key => $value) {
            if (strpos($key, self::FLASH_PREFIX) === 0) {
                $oldKey = str_replace(self::FLASH_PREFIX, self::OLD_FLASH_PREFIX, $key);
                $this->set($oldKey, $value);
                $this->remove($key);
            }
        }

        // Remove old flash data that wasn't accessed
        foreach ($_SESSION as $key => $value) {
            if (strpos($key, self::OLD_FLASH_PREFIX) === 0) {
                $this->remove($key);
            }
        }
    }

    /**
     * Set user authentication data
     *
     * @param int $userId User ID
     * @param string $email User email
     * @param string $role User role
     * @return void
     */
    public function login(int $userId, string $email, string $role = 'user'): void
    {
        $this->regenerateId();
        $this->set('user_id', $userId);
        $this->set('user_email', $email);
        $this->set('user_role', $role);
        $this->set('login_time', time());
    }

    /**
     * Clear user authentication data
     *
     * @return void
     */
    public function logout(): void
    {
        $this->remove('user_id');
        $this->remove('user_email');
        $this->remove('user_role');
        $this->remove('login_time');
        $this->regenerateId();
    }

    /**
     * Check if user is authenticated
     *
     * @return bool
     */
    public function isAuthenticated(): bool
    {
        return $this->has('user_id');
    }

    /**
     * Get authenticated user ID
     *
     * @return int|null
     */
    public function getUserId(): ?int
    {
        return $this->get('user_id');
    }

    /**
     * Get authenticated user email
     *
     * @return string|null
     */
    public function getUserEmail(): ?string
    {
        return $this->get('user_email');
    }

    /**
     * Get authenticated user role
     *
     * @return string|null
     */
    public function getUserRole(): ?string
    {
        return $this->get('user_role');
    }

    /**
     * Check if session is expired
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        $loginTime = $this->get('login_time');

        if (!$loginTime) {
            return false;
        }

        return (time() - $loginTime) > self::$config['lifetime'];
    }

    /**
     * Database session handlers
     */

    /**
     * Session open handler
     *
     * @param string $savePath Save path
     * @param string $sessionName Session name
     * @return bool
     */
    public function sessionOpen(string $savePath, string $sessionName): bool
    {
        return true;
    }

    /**
     * Session close handler
     *
     * @return bool
     */
    public function sessionClose(): bool
    {
        return true;
    }

    /**
     * Session read handler
     *
     * @param string $sessionId Session ID
     * @return string Session data
     */
    public function sessionRead(string $sessionId): string
    {
        try {
            $data = Database::fetchColumn(
                "SELECT data FROM sessions WHERE id = ? AND expires_at > NOW()",
                [$sessionId]
            );

            return $data ?? '';
        } catch (Exception $e) {
            app_log('error', 'Session read error', ['error' => $e->getMessage()]);
            return '';
        }
    }

    /**
     * Session write handler
     *
     * @param string $sessionId Session ID
     * @param string $data Session data
     * @return bool
     */
    public function sessionWrite(string $sessionId, string $data): bool
    {
        try {
            $expiresAt = date('Y-m-d H:i:s', time() + self::$config['lifetime']);
            $ipAddress = client_ip();
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

            Database::execute(
                "INSERT INTO sessions (id, data, expires_at, ip_address, user_agent, last_activity)
                 VALUES (?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                 data = VALUES(data),
                 expires_at = VALUES(expires_at),
                 last_activity = NOW()",
                [$sessionId, $data, $expiresAt, $ipAddress, $userAgent]
            );

            return true;
        } catch (Exception $e) {
            app_log('error', 'Session write error', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Session destroy handler
     *
     * @param string $sessionId Session ID
     * @return bool
     */
    public function sessionDestroy(string $sessionId): bool
    {
        try {
            Database::execute("DELETE FROM sessions WHERE id = ?", [$sessionId]);
            return true;
        } catch (Exception $e) {
            app_log('error', 'Session destroy error', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Session garbage collection handler
     *
     * @param int $maxLifetime Maximum lifetime
     * @return int|false Number of deleted sessions
     */
    public function sessionGc(int $maxLifetime)
    {
        try {
            $result = Database::execute("DELETE FROM sessions WHERE expires_at < NOW()");
            return $result;
        } catch (Exception $e) {
            app_log('error', 'Session GC error', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get session statistics
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        if (self::$config['storage'] !== 'database') {
            return ['message' => 'Statistics only available for database sessions'];
        }

        try {
            $total = Database::fetchColumn("SELECT COUNT(*) FROM sessions");
            $active = Database::fetchColumn("SELECT COUNT(*) FROM sessions WHERE expires_at > NOW()");
            $expired = $total - $active;

            return [
                'total_sessions' => $total,
                'active_sessions' => $active,
                'expired_sessions' => $expired,
                'current_session_id' => $this->getId(),
                'session_lifetime' => self::$config['lifetime'],
                'storage_type' => self::$config['storage']
            ];
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Clean expired sessions manually
     *
     * @return int Number of deleted sessions
     */
    public static function cleanExpiredSessions(): int
    {
        if (self::$config['storage'] !== 'database') {
            return 0;
        }

        try {
            $deleted = Database::execute("DELETE FROM sessions WHERE expires_at < NOW()");
            return $deleted;
        } catch (Exception $e) {
            app_log('error', 'Manual session cleanup error', ['error' => $e->getMessage()]);
            return 0;
        }
    }
}
