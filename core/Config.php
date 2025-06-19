<?php

/**
 * Win a Brand New - Configuration Management Class
 * File: /core/Config.php
 *
 * Provides centralized configuration management with environment variable
 * handling, validation, caching, and secure access according to the
 * Development Specification requirements.
 *
 * Features:
 * - Singleton pattern for configuration access
 * - Environment variable loading with .env file support
 * - Configuration validation and type casting
 * - Cached configuration loading for performance
 * - Secure configuration defaults and validation
 * - Runtime configuration override capability
 * - Nested configuration access with dot notation
 *
 * @package WinABrandNew\Core
 * @version 1.0.0
 * @author NerdMarcel Development Team
 */

namespace WinABrandNew\Core;

use Exception;
use InvalidArgumentException;

class Config
{
    /**
     * Configuration instance (Singleton)
     *
     * @var Config|null
     */
    private static ?Config $instance = null;

    /**
     * Configuration data storage
     *
     * @var array
     */
    private array $config = [];

    /**
     * Default configuration values
     *
     * @var array
     */
    private array $defaults = [];

    /**
     * Required configuration keys
     *
     * @var array
     */
    private array $required = [];

    /**
     * Configuration cache status
     *
     * @var bool
     */
    private bool $cached = false;

    /**
     * Environment file path
     *
     * @var string
     */
    private string $envPath = '';

    /**
     * Configuration validation rules
     *
     * @var array
     */
    private array $validationRules = [];

    /**
     * Private constructor for singleton pattern
     */
    private function __construct()
    {
        $this->setDefaults();
        $this->setRequiredKeys();
        $this->setValidationRules();
    }

    /**
     * Get configuration instance (Singleton pattern)
     *
     * @return Config
     */
    public static function getInstance(): Config
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Initialize configuration with environment file
     *
     * @param string $envPath Path to .env file
     * @return Config
     * @throws Exception If configuration loading fails
     */
    public static function initialize(string $envPath = ''): Config
    {
        $instance = self::getInstance();

        if (empty($envPath)) {
            // Default to root directory .env file
            $envPath = dirname(__DIR__) . '/.env';
        }

        $instance->envPath = $envPath;
        $instance->loadConfiguration();
        $instance->validateConfiguration();

        return $instance;
    }

    /**
     * Load configuration from environment file and system environment
     *
     * @return void
     * @throws Exception If configuration loading fails
     */
    private function loadConfiguration(): void
    {
        // Start with default values
        $this->config = $this->defaults;

        // Load from .env file if it exists
        if (file_exists($this->envPath) && is_readable($this->envPath)) {
            $this->loadFromEnvFile($this->envPath);
        }

        // Override with system environment variables
        $this->loadFromSystemEnv();

        // Apply type casting and normalization
        $this->normalizeConfiguration();

        $this->cached = true;
    }

    /**
     * Load configuration from .env file
     *
     * @param string $filePath Path to .env file
     * @return void
     * @throws Exception If .env file cannot be parsed
     */
    private function loadFromEnvFile(string $filePath): void
    {
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            throw new Exception("Cannot read .env file: {$filePath}");
        }

        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);

            // Skip comments and empty lines
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }

            // Parse key=value pairs
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Remove quotes from value
                $value = $this->removeQuotes($value);

                // Set environment variable
                if (!empty($key)) {
                    $_ENV[$key] = $value;
                    putenv("{$key}={$value}");
                }
            }
        }
    }

    /**
     * Load configuration from system environment variables
     *
     * @return void
     */
    private function loadFromSystemEnv(): void
    {
        // Database configuration
        $this->config['database'] = [
            'host' => $_ENV['DB_HOST'] ?? $this->defaults['database']['host'],
            'port' => (int)($_ENV['DB_PORT'] ?? $this->defaults['database']['port']),
            'name' => $_ENV['DB_NAME'] ?? $this->defaults['database']['name'],
            'user' => $_ENV['DB_USER'] ?? $this->defaults['database']['user'],
            'password' => $_ENV['DB_PASSWORD'] ?? $this->defaults['database']['password'],
            'charset' => $_ENV['DB_CHARSET'] ?? $this->defaults['database']['charset'],
        ];

        // Application configuration
        $this->config['app'] = [
            'url' => $_ENV['APP_URL'] ?? $this->defaults['app']['url'],
            'debug' => $this->parseBoolean($_ENV['APP_DEBUG'] ?? $this->defaults['app']['debug']),
            'timezone' => $_ENV['APP_TIMEZONE'] ?? $this->defaults['app']['timezone'],
        ];

        // Payment providers
        $this->config['payments'] = [
            'mollie' => [
                'api_key' => $_ENV['MOLLIE_API_KEY'] ?? '',
                'webhook_secret' => $_ENV['MOLLIE_WEBHOOK_SECRET'] ?? '',
            ],
            'stripe' => [
                'api_key' => $_ENV['STRIPE_API_KEY'] ?? '',
                'webhook_secret' => $_ENV['STRIPE_WEBHOOK_SECRET'] ?? '',
                'enabled' => $this->parseBoolean($_ENV['STRIPE_ENABLED'] ?? 'false'),
            ],
        ];

        // Email configuration
        $this->config['mail'] = [
            'host' => $_ENV['MAIL_HOST'] ?? '',
            'port' => (int)($_ENV['MAIL_PORT'] ?? 587),
            'username' => $_ENV['MAIL_USERNAME'] ?? '',
            'password' => $_ENV['MAIL_PASSWORD'] ?? '',
            'from' => $_ENV['MAIL_FROM'] ?? '',
            'from_name' => $_ENV['MAIL_FROM_NAME'] ?? 'Win a Brand New',
            'encryption' => $_ENV['MAIL_ENCRYPTION'] ?? 'tls',
        ];

        // WhatsApp configuration
        $this->config['whatsapp'] = [
            'access_token' => $_ENV['WHATSAPP_ACCESS_TOKEN'] ?? '',
            'phone_number_id' => $_ENV['WHATSAPP_PHONE_NUMBER_ID'] ?? '',
            'webhook_verify_token' => $_ENV['WHATSAPP_WEBHOOK_VERIFY_TOKEN'] ?? '',
            'rate_limit_per_minute' => (int)($_ENV['WHATSAPP_RATE_LIMIT_PER_MINUTE'] ?? 10),
        ];

        // Exchange rates
        $this->config['exchange'] = [
            'api_key' => $_ENV['EXCHANGE_API_KEY'] ?? '',
            'api_url' => $_ENV['EXCHANGE_API_URL'] ?? 'https://api.exchangerate-api.com/v4/latest/',
            'provider' => $_ENV['EXCHANGE_API_PROVIDER'] ?? 'exchangerate-api',
        ];

        // SSO providers
        $this->config['sso'] = [
            'google' => [
                'client_id' => $_ENV['GOOGLE_CLIENT_ID'] ?? '',
                'client_secret' => $_ENV['GOOGLE_CLIENT_SECRET'] ?? '',
            ],
            'facebook' => [
                'app_id' => $_ENV['FACEBOOK_APP_ID'] ?? '',
                'app_secret' => $_ENV['FACEBOOK_APP_SECRET'] ?? '',
            ],
            'apple' => [
                'service_id' => $_ENV['APPLE_SERVICE_ID'] ?? '',
                'team_id' => $_ENV['APPLE_TEAM_ID'] ?? '',
                'key_id' => $_ENV['APPLE_KEY_ID'] ?? '',
                'private_key' => $_ENV['APPLE_PRIVATE_KEY'] ?? '',
            ],
        ];

        // Fraud detection
        $this->config['fraud'] = [
            'enabled' => $this->parseBoolean($_ENV['FRAUD_DETECTION_ENABLED'] ?? 'true'),
            'min_answer_time' => (float)($_ENV['FRAUD_MIN_ANSWER_TIME'] ?? 0.5),
            'max_daily_participations' => (int)($_ENV['FRAUD_MAX_DAILY_PARTICIPATIONS'] ?? 5),
            'token_attempts_per_hour' => (int)($_ENV['FRAUD_TOKEN_ATTEMPTS_PER_HOUR'] ?? 4),
            'ip_block_duration' => (int)($_ENV['FRAUD_IP_BLOCK_DURATION'] ?? 86400),
        ];

        // Security settings
        $this->config['security'] = [
            'csrf_token_lifetime' => (int)($_ENV['CSRF_TOKEN_LIFETIME'] ?? 3600),
            'session_lifetime' => (int)($_ENV['SESSION_LIFETIME'] ?? 7200),
            'password_hash_algorithm' => $_ENV['PASSWORD_HASH_ALGORITHM'] ?? 'PASSWORD_ARGON2ID',
            'rate_limit_login_attempts' => (int)($_ENV['RATE_LIMIT_LOGIN_ATTEMPTS'] ?? 5),
            'rate_limit_login_window' => (int)($_ENV['RATE_LIMIT_LOGIN_WINDOW'] ?? 900),
        ];

        // Tax configuration
        $this->config['tax'] = [
            'default_vat_rate' => (float)($_ENV['DEFAULT_VAT_RATE'] ?? 20),
            'default_vat_country' => $_ENV['DEFAULT_VAT_COUNTRY'] ?? 'GB',
            'tax_inclusive_pricing' => $this->parseBoolean($_ENV['TAX_INCLUSIVE_PRICING'] ?? 'true'),
        ];

        // Currency settings
        $this->config['currency'] = [
            'default' => $_ENV['DEFAULT_CURRENCY'] ?? 'GBP',
            'supported' => explode(',', $_ENV['SUPPORTED_CURRENCIES'] ?? 'GBP,EUR,USD,CAD,AUD'),
            'symbol_position' => $_ENV['CURRENCY_SYMBOL_POSITION'] ?? 'before',
            'decimal_places' => (int)($_ENV['CURRENCY_DECIMAL_PLACES'] ?? 2),
        ];

        // Admin settings
        $this->config['admin'] = [
            'session_timeout' => (int)($_ENV['ADMIN_SESSION_TIMEOUT'] ?? 28800),
        ];

        // Logging configuration
        $this->config['logging'] = [
            'level' => $_ENV['LOG_LEVEL'] ?? 'info',
            'path' => $_ENV['LOG_PATH'] ?? '/var/log/winabrandnew',
            'max_files' => (int)($_ENV['LOG_MAX_FILES'] ?? 30),
            'app_enabled' => $this->parseBoolean($_ENV['LOG_APP_ENABLED'] ?? 'true'),
            'security_enabled' => $this->parseBoolean($_ENV['LOG_SECURITY_ENABLED'] ?? 'true'),
            'audit_enabled' => $this->parseBoolean($_ENV['LOG_AUDIT_ENABLED'] ?? 'true'),
            'payments_enabled' => $this->parseBoolean($_ENV['LOG_PAYMENTS_ENABLED'] ?? 'true'),
            'queue_enabled' => $this->parseBoolean($_ENV['LOG_QUEUE_ENABLED'] ?? 'true'),
        ];

        // Queue system
        $this->config['queue'] = [
            'worker_enabled' => $this->parseBoolean($_ENV['QUEUE_WORKER_ENABLED'] ?? 'true'),
            'batch_size' => (int)($_ENV['QUEUE_BATCH_SIZE'] ?? 50),
            'max_attempts' => (int)($_ENV['QUEUE_MAX_ATTEMPTS'] ?? 3),
            'retry_delay' => (int)($_ENV['QUEUE_RETRY_DELAY'] ?? 60),
        ];

        // Feature flags
        $this->config['features'] = [
            'sso_login' => $this->parseBoolean($_ENV['FEATURE_SSO_LOGIN'] ?? 'false'),
            'whatsapp_notifications' => $this->parseBoolean($_ENV['FEATURE_WHATSAPP_NOTIFICATIONS'] ?? 'true'),
            'email_notifications' => $this->parseBoolean($_ENV['FEATURE_EMAIL_NOTIFICATIONS'] ?? 'true'),
            'referral_system' => $this->parseBoolean($_ENV['FEATURE_REFERRAL_SYSTEM'] ?? 'true'),
            'bundle_pricing' => $this->parseBoolean($_ENV['FEATURE_BUNDLE_PRICING'] ?? 'true'),
            'fraud_detection' => $this->parseBoolean($_ENV['FEATURE_FRAUD_DETECTION'] ?? 'true'),
            'analytics_tracking' => $this->parseBoolean($_ENV['FEATURE_ANALYTICS_TRACKING'] ?? 'true'),
        ];

        // Game configuration
        $this->config['game'] = [
            'default_max_players' => (int)($_ENV['DEFAULT_MAX_PLAYERS'] ?? 1000),
            'default_question_timeout' => (int)($_ENV['DEFAULT_QUESTION_TIMEOUT'] ?? 10),
            'timer_precision_microseconds' => $this->parseBoolean($_ENV['TIMER_PRECISION_MICROSECONDS'] ?? 'true'),
            'auto_restart_rounds' => $this->parseBoolean($_ENV['AUTO_RESTART_ROUNDS'] ?? 'true'),
        ];

        // Discount system
        $this->config['discounts'] = [
            'replay_percentage' => (float)($_ENV['REPLAY_DISCOUNT_PERCENTAGE'] ?? 10),
            'referral_percentage' => (float)($_ENV['REFERRAL_DISCOUNT_PERCENTAGE'] ?? 10),
            'validity_hours' => (int)($_ENV['DISCOUNT_VALIDITY_HOURS'] ?? 24),
        ];
    }

    /**
     * Set default configuration values
     *
     * @return void
     */
    private function setDefaults(): void
    {
        $this->defaults = [
            'database' => [
                'host' => 'localhost',
                'port' => 3306,
                'name' => 'win_brand_new',
                'user' => '',
                'password' => '',
                'charset' => 'utf8mb4',
            ],
            'app' => [
                'url' => 'https://localhost',
                'debug' => false,
                'timezone' => 'Europe/London',
            ],
        ];
    }

    /**
     * Set required configuration keys
     *
     * @return void
     */
    private function setRequiredKeys(): void
    {
        $this->required = [
            'database.host',
            'database.name',
            'database.user',
            'app.url',
            'payments.mollie.api_key',
        ];
    }

    /**
     * Set validation rules for configuration values
     *
     * @return void
     */
    private function setValidationRules(): void
    {
        $this->validationRules = [
            'database.port' => ['type' => 'integer', 'min' => 1, 'max' => 65535],
            'app.debug' => ['type' => 'boolean'],
            'app.url' => ['type' => 'url'],
            'fraud.min_answer_time' => ['type' => 'float', 'min' => 0],
            'fraud.max_daily_participations' => ['type' => 'integer', 'min' => 1],
            'tax.default_vat_rate' => ['type' => 'float', 'min' => 0, 'max' => 100],
            'currency.decimal_places' => ['type' => 'integer', 'min' => 0, 'max' => 4],
        ];
    }

    /**
     * Normalize configuration values with type casting
     *
     * @return void
     */
    private function normalizeConfiguration(): void
    {
        // Ensure URLs end with trailing slash where needed
        if (isset($this->config['app']['url'])) {
            $this->config['app']['url'] = rtrim($this->config['app']['url'], '/');
        }

        // Normalize supported currencies to uppercase
        if (isset($this->config['currency']['supported'])) {
            $this->config['currency']['supported'] = array_map('strtoupper', $this->config['currency']['supported']);
        }

        // Ensure default currency is in supported list
        if (isset($this->config['currency']['default'], $this->config['currency']['supported'])) {
            $defaultCurrency = strtoupper($this->config['currency']['default']);
            if (!in_array($defaultCurrency, $this->config['currency']['supported'])) {
                $this->config['currency']['supported'][] = $defaultCurrency;
            }
            $this->config['currency']['default'] = $defaultCurrency;
        }
    }

    /**
     * Validate configuration values
     *
     * @return void
     * @throws Exception If validation fails
     */
    private function validateConfiguration(): void
    {
        // Check required keys
        foreach ($this->required as $key) {
            if (!$this->has($key) || empty($this->get($key))) {
                throw new Exception("Required configuration key missing or empty: {$key}");
            }
        }

        // Apply validation rules
        foreach ($this->validationRules as $key => $rules) {
            $value = $this->get($key);

            if ($value === null) {
                continue; // Skip validation for null values
            }

            $this->validateValue($key, $value, $rules);
        }
    }

    /**
     * Validate a single configuration value
     *
     * @param string $key Configuration key
     * @param mixed $value Value to validate
     * @param array $rules Validation rules
     * @return void
     * @throws InvalidArgumentException If validation fails
     */
    private function validateValue(string $key, mixed $value, array $rules): void
    {
        // Type validation
        if (isset($rules['type'])) {
            switch ($rules['type']) {
                case 'integer':
                    if (!is_int($value)) {
                        throw new InvalidArgumentException("Configuration key '{$key}' must be an integer");
                    }
                    break;
                case 'float':
                    if (!is_float($value) && !is_int($value)) {
                        throw new InvalidArgumentException("Configuration key '{$key}' must be a number");
                    }
                    break;
                case 'boolean':
                    if (!is_bool($value)) {
                        throw new InvalidArgumentException("Configuration key '{$key}' must be a boolean");
                    }
                    break;
                case 'url':
                    if (!filter_var($value, FILTER_VALIDATE_URL)) {
                        throw new InvalidArgumentException("Configuration key '{$key}' must be a valid URL");
                    }
                    break;
            }
        }

        // Range validation
        if (isset($rules['min']) && $value < $rules['min']) {
            throw new InvalidArgumentException("Configuration key '{$key}' must be at least {$rules['min']}");
        }

        if (isset($rules['max']) && $value > $rules['max']) {
            throw new InvalidArgumentException("Configuration key '{$key}' must be at most {$rules['max']}");
        }
    }

    /**
     * Get configuration value using dot notation
     *
     * @param string $key Configuration key with dot notation (e.g., 'database.host')
     * @param mixed $default Default value if key not found
     * @return mixed Configuration value
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Set configuration value using dot notation
     *
     * @param string $key Configuration key with dot notation
     * @param mixed $value Value to set
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $config = &$this->config;

        foreach ($keys as $k) {
            if (!is_array($config)) {
                $config = [];
            }
            if (!array_key_exists($k, $config)) {
                $config[$k] = [];
            }
            $config = &$config[$k];
        }

        $config = $value;
    }

    /**
     * Check if configuration key exists
     *
     * @param string $key Configuration key with dot notation
     * @return bool True if key exists
     */
    public function has(string $key): bool
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return false;
            }
            $value = $value[$k];
        }

        return true;
    }

    /**
     * Get all configuration as array
     *
     * @return array Complete configuration array
     */
    public function all(): array
    {
        return $this->config;
    }

    /**
     * Get configuration section
     *
     * @param string $section Section name (e.g., 'database', 'app')
     * @return array Section configuration
     */
    public function section(string $section): array
    {
        return $this->get($section, []);
    }

    /**
     * Parse boolean value from string
     *
     * @param mixed $value Value to parse
     * @return bool Parsed boolean value
     */
    private function parseBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));
            return in_array($value, ['true', '1', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    /**
     * Remove quotes from configuration value
     *
     * @param string $value Value with potential quotes
     * @return string Value without quotes
     */
    private function removeQuotes(string $value): string
    {
        $value = trim($value);

        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    /**
     * Check if configuration is cached
     *
     * @return bool True if configuration is cached
     */
    public function isCached(): bool
    {
        return $this->cached;
    }

    /**
     * Reload configuration from files
     *
     * @return void
     * @throws Exception If reloading fails
     */
    public function reload(): void
    {
        $this->cached = false;
        $this->config = [];
        $this->loadConfiguration();
        $this->validateConfiguration();
    }

    /**
     * Get environment file path
     *
     * @return string Environment file path
     */
    public function getEnvPath(): string
    {
        return $this->envPath;
    }

    /**
     * Debug information about configuration
     *
     * @return array Debug information
     */
    public function debug(): array
    {
        return [
            'env_path' => $this->envPath,
            'env_exists' => file_exists($this->envPath),
            'cached' => $this->cached,
            'config_keys' => array_keys($this->config),
            'required_keys' => $this->required,
            'validation_rules' => array_keys($this->validationRules),
        ];
    }

    /**
     * Prevent cloning of singleton instance
     */
    private function __clone() {}

    /**
     * Prevent unserialization of singleton instance
     */
    public function __wakeup(): void
    {
        throw new Exception("Cannot unserialize singleton");
    }
}

/**
 * Configuration Helper Functions
 *
 * Global convenience functions for configuration access
 */

/**
 * Get configuration instance
 *
 * @return Config
 */
function config(): Config
{
    return Config::getInstance();
}

/**
 * Get configuration value
 *
 * @param string $key Configuration key with dot notation
 * @param mixed $default Default value
 * @return mixed Configuration value
 */
function config_get(string $key, mixed $default = null): mixed
{
    return Config::getInstance()->get($key, $default);
}

/**
 * Set configuration value
 *
 * @param string $key Configuration key with dot notation
 * @param mixed $value Value to set
 * @return void
 */
function config_set(string $key, mixed $value): void
{
    Config::getInstance()->set($key, $value);
}

/**
 * Check if configuration key exists
 *
 * @param string $key Configuration key with dot notation
 * @return bool True if key exists
 */
function config_has(string $key): bool
{
    return Config::getInstance()->has($key);
}

/**
 * Get database configuration
 *
 * @return array Database configuration array
 */
function db_config(): array
{
    return config()->section('database');
}

/**
 * Get application configuration
 *
 * @return array Application configuration array
 */
function app_config(): array
{
    return config()->section('app');
}

/**
 * Check if debug mode is enabled
 *
 * @return bool True if debug mode is enabled
 */
function is_debug(): bool
{
    return config_get('app.debug', false);
}

/**
 * Get application URL
 *
 * @param string $path Optional path to append
 * @return string Application URL
 */
function app_url(string $path = ''): string
{
    $url = config_get('app.url', 'https://localhost');
    return $url . '/' . ltrim($path, '/');
}

/**
 * Check if feature is enabled
 *
 * @param string $feature Feature name
 * @return bool True if feature is enabled
 */
function feature_enabled(string $feature): bool
{
    return config_get("features.{$feature}", false);
}
