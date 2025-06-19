<?php
declare(strict_types=1);

/**
 * File: core/Config.php
 * Location: core/Config.php
 *
 * WinABN Configuration Management
 *
 * Provides centralized configuration management with environment variable support,
 * cached loading, validation, and secure access to application settings.
 *
 * @package WinABN\Core
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Core;

use Exception;

class Config
{
    /**
     * Configuration data cache
     *
     * @var array<string, mixed>
     */
    private static array $config = [];

    /**
     * Configuration files loaded
     *
     * @var array<string>
     */
    private static array $loadedFiles = [];

    /**
     * Configuration directory path
     *
     * @var string
     */
    private static string $configPath;

    /**
     * Environment-specific overrides
     *
     * @var array<string, mixed>
     */
    private static array $environmentOverrides = [];

    /**
     * Constructor
     *
     * @param string|null $configPath Configuration directory path
     */
    public function __construct(?string $configPath = null)
    {
        self::$configPath = $configPath ?? WINABN_CONFIG_DIR;
        $this->loadAllConfigurations();
    }

    /**
     * Get configuration value using dot notation
     *
     * @param string $key Configuration key (e.g., 'database.host')
     * @param mixed $default Default value if key not found
     * @return mixed Configuration value
     */
    public function get(string $key, $default = null)
    {
        // Check environment overrides first
        if (isset(self::$environmentOverrides[$key])) {
            return self::$environmentOverrides[$key];
        }

        return $this->getFromArray(self::$config, $key, $default);
    }

    /**
     * Set configuration value using dot notation
     *
     * @param string $key Configuration key
     * @param mixed $value Configuration value
     * @return void
     */
    public function set(string $key, $value): void
    {
        $this->setInArray(self::$config, $key, $value);
    }

    /**
     * Check if configuration key exists
     *
     * @param string $key Configuration key
     * @return bool True if key exists
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Get all configuration data
     *
     * @return array<string, mixed> All configuration
     */
    public function all(): array
    {
        return self::$config;
    }

    /**
     * Load all configuration files
     *
     * @return void
     */
    private function loadAllConfigurations(): void
    {
        if (!is_dir(self::$configPath)) {
            throw new Exception("Configuration directory not found: " . self::$configPath);
        }

        $configFiles = [
            'app.php',
            'database.php',
            'email.php',
            'payment.php',
            'security.php'
        ];

        foreach ($configFiles as $file) {
            $this->loadConfigurationFile($file);
        }

        // Load environment-specific overrides
        $this->loadEnvironmentOverrides();
    }

    /**
     * Load specific configuration file
     *
     * @param string $filename Configuration filename
     * @return array<string, mixed> Configuration data
     */
    private function loadConfigurationFile(string $filename): array
    {
        $filepath = self::$configPath . '/' . $filename;

        if (!file_exists($filepath)) {
            return [];
        }

        if (in_array($filename, self::$loadedFiles)) {
            return self::$config[basename($filename, '.php')] ?? [];
        }

        try {
            $config = require $filepath;

            if (!is_array($config)) {
                throw new Exception("Configuration file must return an array: $filename");
            }

            $configKey = basename($filename, '.php');
            self::$config[$configKey] = $config;
            self::$loadedFiles[] = $filename;

            return $config;

        } catch (Exception $e) {
            throw new Exception("Failed to load configuration file '$filename': " . $e->getMessage());
        }
    }

    /**
     * Load environment-specific configuration overrides
     *
     * @return void
     */
    private function loadEnvironmentOverrides(): void
    {
        $environment = env('APP_ENV', 'production');
        $envConfigFile = self::$configPath . "/env/{$environment}.php";

        if (file_exists($envConfigFile)) {
            try {
                $envConfig = require $envConfigFile;
                if (is_array($envConfig)) {
                    self::$environmentOverrides = $envConfig;
                }
            } catch (Exception $e) {
                // Silently fail for environment overrides
                app_log('warning', 'Failed to load environment config', [
                    'file' => $envConfigFile,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Apply environment variables as overrides
        $this->applyEnvironmentVariables();
    }

    /**
     * Apply environment variables as configuration overrides
     *
     * @return void
     */
    private function applyEnvironmentVariables(): void
    {
        $envMappings = [
            // Database
            'DB_HOST' => 'database.host',
            'DB_PORT' => 'database.port',
            'DB_NAME' => 'database.name',
            'DB_USER' => 'database.username',
            'DB_PASSWORD' => 'database.password',
            'DB_CHARSET' => 'database.charset',

            // Application
            'APP_NAME' => 'app.name',
            'APP_URL' => 'app.url',
            'APP_ENV' => 'app.environment',
            'APP_DEBUG' => 'app.debug',
            'APP_TIMEZONE' => 'app.timezone',

            // Email
            'MAIL_HOST' => 'email.host',
            'MAIL_PORT' => 'email.port',
            'MAIL_USERNAME' => 'email.username',
            'MAIL_PASSWORD' => 'email.password',
            'MAIL_ENCRYPTION' => 'email.encryption',
            'MAIL_FROM_ADDRESS' => 'email.from.address',
            'MAIL_FROM_NAME' => 'email.from.name',

            // Payment
            'MOLLIE_API_KEY' => 'payment.mollie.api_key',
            'MOLLIE_WEBHOOK_SECRET' => 'payment.mollie.webhook_secret',
            'STRIPE_API_KEY' => 'payment.stripe.api_key',
            'STRIPE_WEBHOOK_SECRET' => 'payment.stripe.webhook_secret',

            // Security
            'ENCRYPTION_KEY' => 'security.encryption_key',
            'JWT_SECRET' => 'security.jwt_secret',
            'SESSION_LIFETIME' => 'security.session_lifetime'
        ];

        foreach ($envMappings as $envKey => $configKey) {
            $envValue = env($envKey);
            if ($envValue !== null) {
                self::$environmentOverrides[$configKey] = $this->castValue($envValue);
            }
        }
    }

    /**
     * Get value from nested array using dot notation
     *
     * @param array<string, mixed> $array Array to search
     * @param string $key Dot notation key
     * @param mixed $default Default value
     * @return mixed Found value or default
     */
    private function getFromArray(array $array, string $key, $default = null)
    {
        if (strpos($key, '.') === false) {
            return $array[$key] ?? $default;
        }

        $keys = explode('.', $key);
        $current = $array;

        foreach ($keys as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Set value in nested array using dot notation
     *
     * @param array<string, mixed> &$array Array to modify
     * @param string $key Dot notation key
     * @param mixed $value Value to set
     * @return void
     */
    private function setInArray(array &$array, string $key, $value): void
    {
        if (strpos($key, '.') === false) {
            $array[$key] = $value;
            return;
        }

        $keys = explode('.', $key);
        $current = &$array;

        foreach ($keys as $segment) {
            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }
            $current = &$current[$segment];
        }

        $current = $value;
    }

    /**
     * Cast string values to appropriate types
     *
     * @param string $value String value to cast
     * @return mixed Casted value
     */
    private function castValue(string $value)
    {
        // Boolean values
        if (in_array(strtolower($value), ['true', '1', 'yes', 'on'])) {
            return true;
        }
        if (in_array(strtolower($value), ['false', '0', 'no', 'off', ''])) {
            return false;
        }

        // Numeric values
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        // JSON arrays/objects
        if (($value[0] ?? '') === '{' || ($value[0] ?? '') === '[') {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        // Comma-separated arrays
        if (str_contains($value, ',')) {
            return array_map('trim', explode(',', $value));
        }

        return $value;
    }

    /**
     * Validate configuration values
     *
     * @return array<string> Array of validation errors
     */
    public function validate(): array
    {
        $errors = [];

        // Required configuration keys
        $required = [
            'app.name',
            'app.url',
            'database.host',
            'database.name',
            'database.username',
            'security.encryption_key'
        ];

        foreach ($required as $key) {
            if (!$this->has($key) || empty($this->get($key))) {
                $errors[] = "Required configuration missing: $key";
            }
        }

        // Validate specific configurations
        $errors = array_merge($errors, $this->validateDatabase());
        $errors = array_merge($errors, $this->validateEmail());
        $errors = array_merge($errors, $this->validatePayment());
        $errors = array_merge($errors, $this->validateSecurity());

        return $errors;
    }

    /**
     * Validate database configuration
     *
     * @return array<string> Validation errors
     */
    private function validateDatabase(): array
    {
        $errors = [];

        $port = $this->get('database.port');
        if ($port && (!is_numeric($port) || $port < 1 || $port > 65535)) {
            $errors[] = "Invalid database port: $port";
        }

        return $errors;
    }

    /**
     * Validate email configuration
     *
     * @return array<string> Validation errors
     */
    private function validateEmail(): array
    {
        $errors = [];

        $fromAddress = $this->get('email.from.address');
        if ($fromAddress && !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email from address: $fromAddress";
        }

        $port = $this->get('email.port');
        if ($port && (!is_numeric($port) || $port < 1 || $port > 65535)) {
            $errors[] = "Invalid email port: $port";
        }

        return $errors;
    }

    /**
     * Validate payment configuration
     *
     * @return array<string> Validation errors
     */
    private function validatePayment(): array
    {
        $errors = [];

        $mollieKey = $this->get('payment.mollie.api_key');
        if ($mollieKey && !str_starts_with($mollieKey, 'test_') && !str_starts_with($mollieKey, 'live_')) {
            $errors[] = "Invalid Mollie API key format";
        }

        $stripeKey = $this->get('payment.stripe.api_key');
        if ($stripeKey && !str_starts_with($stripeKey, 'sk_test_') && !str_starts_with($stripeKey, 'sk_live_')) {
            $errors[] = "Invalid Stripe API key format";
        }

        return $errors;
    }

    /**
     * Validate security configuration
     *
     * @return array<string> Validation errors
     */
    private function validateSecurity(): array
    {
        $errors = [];

        $encryptionKey = $this->get('security.encryption_key');
        if ($encryptionKey && strlen($encryptionKey) < 32) {
            $errors[] = "Encryption key must be at least 32 characters long";
        }

        $sessionLifetime = $this->get('security.session_lifetime');
        if ($sessionLifetime && (!is_numeric($sessionLifetime) || $sessionLifetime < 300)) {
            $errors[] = "Session lifetime must be at least 300 seconds (5 minutes)";
        }

        return $errors;
    }

    /**
     * Export configuration for debugging (sensitive data masked)
     *
     * @return array<string, mixed> Safe configuration export
     */
    public function export(): array
    {
        $config = self::$config;

        // Mask sensitive data
        $sensitiveKeys = [
            'database.password',
            'email.password',
            'payment.mollie.api_key',
            'payment.mollie.webhook_secret',
            'payment.stripe.api_key',
            'payment.stripe.webhook_secret',
            'security.encryption_key',
            'security.jwt_secret'
        ];

        foreach ($sensitiveKeys as $key) {
            if ($this->has($key)) {
                $this->setInArray($config, $key, '***MASKED***');
            }
        }

        return $config;
    }

    /**
     * Cache configuration to file for performance
     *
     * @return bool True on success
     */
    public function cache(): bool
    {
        $cacheFile = self::$configPath . '/../cache/config.cache';
        $cacheDir = dirname($cacheFile);

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $cacheData = [
            'config' => self::$config,
            'overrides' => self::$environmentOverrides,
            'timestamp' => time(),
            'checksum' => $this->generateChecksum()
        ];

        return file_put_contents($cacheFile, serialize($cacheData), LOCK_EX) !== false;
    }

    /**
     * Load configuration from cache if valid
     *
     * @return bool True if loaded from cache
     */
    public function loadFromCache(): bool
    {
        $cacheFile = self::$configPath . '/../cache/config.cache';

        if (!file_exists($cacheFile)) {
            return false;
        }

        $cacheData = unserialize(file_get_contents($cacheFile));

        if (!$cacheData || !isset($cacheData['checksum'])) {
            return false;
        }

        // Verify cache is still valid
        if ($cacheData['checksum'] !== $this->generateChecksum()) {
            unlink($cacheFile);
            return false;
        }

        self::$config = $cacheData['config'];
        self::$environmentOverrides = $cacheData['overrides'];

        return true;
    }

    /**
     * Generate checksum for cache validation
     *
     * @return string Configuration checksum
     */
    private function generateChecksum(): string
    {
        $configFiles = glob(self::$configPath . '/*.php');
        $checksumData = '';

        foreach ($configFiles as $file) {
            $checksumData .= filemtime($file) . filesize($file);
        }

        // Include environment variables in checksum
        $envVars = [
            'APP_ENV', 'APP_DEBUG', 'DB_HOST', 'DB_NAME',
            'MAIL_HOST', 'MOLLIE_API_KEY', 'STRIPE_API_KEY'
        ];

        foreach ($envVars as $var) {
            $checksumData .= env($var, '');
        }

        return md5($checksumData);
    }

    /**
     * Clear configuration cache
     *
     * @return bool True on success
     */
    public function clearCache(): bool
    {
        $cacheFile = self::$configPath . '/../cache/config.cache';

        if (file_exists($cacheFile)) {
            return unlink($cacheFile);
        }

        return true;
    }

    /**
     * Get configuration for specific environment
     *
     * @param string $environment Environment name
     * @return array<string, mixed> Environment-specific configuration
     */
    public function getEnvironmentConfig(string $environment): array
    {
        $envConfigFile = self::$configPath . "/env/{$environment}.php";

        if (!file_exists($envConfigFile)) {
            return [];
        }

        try {
            $config = require $envConfigFile;
            return is_array($config) ? $config : [];
        } catch (Exception $e) {
            app_log('error', 'Failed to load environment config', [
                'environment' => $environment,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get configuration statistics
     *
     * @return array<string, mixed> Configuration statistics
     */
    public function getStats(): array
    {
        return [
            'loaded_files' => count(self::$loadedFiles),
            'config_keys' => $this->countConfigKeys(self::$config),
            'environment_overrides' => count(self::$environmentOverrides),
            'cache_exists' => file_exists(self::$configPath . '/../cache/config.cache'),
            'config_path' => self::$configPath,
            'validation_errors' => count($this->validate())
        ];
    }

    /**
     * Count total configuration keys recursively
     *
     * @param array<string, mixed> $array Configuration array
     * @return int Total key count
     */
    private function countConfigKeys(array $array): int
    {
        $count = 0;

        foreach ($array as $value) {
            $count++;
            if (is_array($value)) {
                $count += $this->countConfigKeys($value);
            }
        }

        return $count;
    }

    /**
     * Magic method to get configuration as property
     *
     * @param string $key Configuration key
     * @return mixed Configuration value
     */
    public function __get(string $key)
    {
        return $this->get($key);
    }

    /**
     * Magic method to set configuration as property
     *
     * @param string $key Configuration key
     * @param mixed $value Configuration value
     * @return void
     */
    public function __set(string $key, $value): void
    {
        $this->set($key, $value);
    }

    /**
     * Magic method to check if configuration exists
     *
     * @param string $key Configuration key
     * @return bool True if key exists
     */
    public function __isset(string $key): bool
    {
        return $this->has($key);
    }
}
