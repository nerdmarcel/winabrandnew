<?php

/**
 * Win a Brand New - Exchange Rate Model
 * File: /models/ExchangeRate.php
 *
 * Handles currency conversion and daily rate updates according to the
 * Development Specification requirements. Provides exchange rate management,
 * caching, fallback logic, and integration with external APIs.
 *
 * Features:
 * - Daily rate fetching from multiple API providers
 * - Caching with fallback logic (max 7 days old)
 * - Currency conversion calculations
 * - Rate history tracking
 * - Cron job integration for daily updates (06:00 UTC)
 * - Support for GBP, EUR, USD, CAD, AUD
 * - Integration with ExchangeRate-API.com and Fixer.io
 *
 * @package WinABrandNew\Models
 * @version 1.0.0
 * @author NerdMarcel Development Team
 */

namespace WinABrandNew\Models;

use WinABrandNew\Core\Database;
use WinABrandNew\Core\Config;
use Exception;
use PDO;

class ExchangeRate
{
    /**
     * Supported currencies
     *
     * @var array
     */
    private const SUPPORTED_CURRENCIES = ['GBP', 'EUR', 'USD', 'CAD', 'AUD'];

    /**
     * Default base currency (GBP)
     *
     * @var string
     */
    private const DEFAULT_BASE_CURRENCY = 'GBP';

    /**
     * Maximum cache age in seconds (7 days)
     *
     * @var int
     */
    private const MAX_CACHE_AGE = 7 * 24 * 60 * 60; // 7 days

    /**
     * Rate update schedule (daily at 06:00 UTC)
     *
     * @var string
     */
    private const UPDATE_SCHEDULE = '0 6 * * *';

    /**
     * API providers configuration
     *
     * @var array
     */
    private static array $apiProviders = [
        'exchangerate-api' => [
            'url' => 'https://api.exchangerate-api.com/v4/latest/',
            'key_required' => false,
            'free_requests' => 1500,
            'rate_limit' => '1 per second'
        ],
        'fixer' => [
            'url' => 'http://data.fixer.io/api/latest',
            'key_required' => true,
            'free_requests' => 100,
            'rate_limit' => '1 per minute'
        ]
    ];

    /**
     * Current exchange rate data
     *
     * @var array|null
     */
    private ?array $currentRates = null;

    /**
     * Get current exchange rate between two currencies
     *
     * @param string $fromCurrency Source currency code
     * @param string $toCurrency Target currency code
     * @param float $amount Amount to convert (default: 1.0)
     * @return float|null Converted amount or null on failure
     * @throws Exception If currencies are not supported
     */
    public static function convert(string $fromCurrency, string $toCurrency, float $amount = 1.0): ?float
    {
        // Validate currencies
        if (!self::isSupportedCurrency($fromCurrency)) {
            throw new Exception("Unsupported source currency: {$fromCurrency}");
        }

        if (!self::isSupportedCurrency($toCurrency)) {
            throw new Exception("Unsupported target currency: {$toCurrency}");
        }

        // Same currency conversion
        if ($fromCurrency === $toCurrency) {
            return $amount;
        }

        try {
            // Get exchange rate
            $rate = self::getExchangeRate($fromCurrency, $toCurrency);

            if ($rate === null) {
                self::logMessage("Failed to get exchange rate from {$fromCurrency} to {$toCurrency}", 'error');
                return null;
            }

            $convertedAmount = $amount * $rate;

            self::logMessage(
                "Converted {$amount} {$fromCurrency} to {$convertedAmount} {$toCurrency} (rate: {$rate})",
                'info'
            );

            return round($convertedAmount, 2);

        } catch (Exception $e) {
            self::logMessage("Currency conversion failed: " . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Get exchange rate between two currencies
     *
     * @param string $fromCurrency Source currency
     * @param string $toCurrency Target currency
     * @return float|null Exchange rate or null on failure
     */
    public static function getExchangeRate(string $fromCurrency, string $toCurrency): ?float
    {
        try {
            // Check cache first
            $cachedRate = self::getCachedRate($fromCurrency, $toCurrency);
            if ($cachedRate !== null) {
                return $cachedRate;
            }

            // Fetch fresh rates
            $rates = self::fetchLatestRates($fromCurrency);
            if ($rates === null) {
                // Try fallback with older cached data
                return self::getFallbackRate($fromCurrency, $toCurrency);
            }

            // Store new rates in cache
            self::storeRates($fromCurrency, $rates);

            return $rates[$toCurrency] ?? null;

        } catch (Exception $e) {
            self::logMessage("Failed to get exchange rate: " . $e->getMessage(), 'error');
            return self::getFallbackRate($fromCurrency, $toCurrency);
        }
    }

    /**
     * Get cached exchange rate if available and not expired
     *
     * @param string $fromCurrency Source currency
     * @param string $toCurrency Target currency
     * @return float|null Cached rate or null if not available/expired
     */
    private static function getCachedRate(string $fromCurrency, string $toCurrency): ?float
    {
        try {
            $sql = "SELECT rate, last_updated
                    FROM exchange_rates
                    WHERE base_currency = ? AND target_currency = ?
                    ORDER BY last_updated DESC
                    LIMIT 1";

            $result = Database::selectOne($sql, [$fromCurrency, $toCurrency]);

            if (!$result) {
                return null;
            }

            $lastUpdated = strtotime($result['last_updated']);
            $cacheAge = time() - $lastUpdated;

            // Return cached rate if less than 24 hours old
            if ($cacheAge < 24 * 60 * 60) {
                self::logMessage("Using cached exchange rate for {$fromCurrency} to {$toCurrency}", 'info');
                return (float) $result['rate'];
            }

            // Allow older cache (up to 7 days) as fallback
            if ($cacheAge < self::MAX_CACHE_AGE) {
                self::logMessage("Cached rate available but old ({$cacheAge}s) for {$fromCurrency} to {$toCurrency}", 'warning');
                return (float) $result['rate'];
            }

            return null;

        } catch (Exception $e) {
            self::logMessage("Failed to get cached rate: " . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Get fallback rate from older cached data (up to 7 days)
     *
     * @param string $fromCurrency Source currency
     * @param string $toCurrency Target currency
     * @return float|null Fallback rate or null if not available
     */
    private static function getFallbackRate(string $fromCurrency, string $toCurrency): ?float
    {
        try {
            $sql = "SELECT rate, last_updated
                    FROM exchange_rates
                    WHERE base_currency = ? AND target_currency = ?
                    AND last_updated > DATE_SUB(NOW(), INTERVAL 7 DAY)
                    ORDER BY last_updated DESC
                    LIMIT 1";

            $result = Database::selectOne($sql, [$fromCurrency, $toCurrency]);

            if ($result) {
                self::logMessage("Using fallback cached rate for {$fromCurrency} to {$toCurrency}", 'warning');
                return (float) $result['rate'];
            }

            return null;

        } catch (Exception $e) {
            self::logMessage("Failed to get fallback rate: " . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Fetch latest exchange rates from external API
     *
     * @param string $baseCurrency Base currency for rates
     * @return array|null Array of rates or null on failure
     */
    private static function fetchLatestRates(string $baseCurrency): ?array
    {
        $providers = [
            'exchangerate-api' => 'fetchFromExchangeRateAPI',
            'fixer' => 'fetchFromFixerAPI'
        ];

        foreach ($providers as $providerName => $method) {
            try {
                self::logMessage("Attempting to fetch rates from {$providerName}", 'info');
                $rates = self::$method($baseCurrency);

                if ($rates !== null) {
                    self::logMessage("Successfully fetched rates from {$providerName}", 'info');
                    return $rates;
                }

            } catch (Exception $e) {
                self::logMessage("Failed to fetch from {$providerName}: " . $e->getMessage(), 'warning');
                continue;
            }
        }

        self::logMessage("All API providers failed to fetch exchange rates", 'error');
        return null;
    }

    /**
     * Fetch rates from ExchangeRate-API.com
     *
     * @param string $baseCurrency Base currency
     * @return array|null Exchange rates array
     */
    private static function fetchFromExchangeRateAPI(string $baseCurrency): ?array
    {
        try {
            $url = "https://api.exchangerate-api.com/v4/latest/{$baseCurrency}";

            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'user_agent' => 'WinABrandNew/1.0'
                ]
            ]);

            $response = file_get_contents($url, false, $context);

            if ($response === false) {
                throw new Exception("Failed to fetch data from ExchangeRate-API");
            }

            $data = json_decode($response, true);

            if (!$data || !isset($data['rates'])) {
                throw new Exception("Invalid response format from ExchangeRate-API");
            }

            // Filter only supported currencies
            $filteredRates = [];
            foreach (self::SUPPORTED_CURRENCIES as $currency) {
                if (isset($data['rates'][$currency])) {
                    $filteredRates[$currency] = (float) $data['rates'][$currency];
                }
            }

            return $filteredRates;

        } catch (Exception $e) {
            self::logMessage("ExchangeRate-API error: " . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Fetch rates from Fixer.io API
     *
     * @param string $baseCurrency Base currency
     * @return array|null Exchange rates array
     */
    private static function fetchFromFixerAPI(string $baseCurrency): ?array
    {
        try {
            $apiKey = Config::get('EXCHANGE_API_KEY');

            if (empty($apiKey)) {
                throw new Exception("Fixer.io API key not configured");
            }

            $symbols = implode(',', self::SUPPORTED_CURRENCIES);
            $url = "http://data.fixer.io/api/latest?access_key={$apiKey}&base={$baseCurrency}&symbols={$symbols}";

            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'user_agent' => 'WinABrandNew/1.0'
                ]
            ]);

            $response = file_get_contents($url, false, $context);

            if ($response === false) {
                throw new Exception("Failed to fetch data from Fixer.io");
            }

            $data = json_decode($response, true);

            if (!$data || !$data['success'] || !isset($data['rates'])) {
                $error = $data['error']['info'] ?? 'Unknown error';
                throw new Exception("Fixer.io API error: {$error}");
            }

            // Convert to float values
            $rates = [];
            foreach ($data['rates'] as $currency => $rate) {
                $rates[$currency] = (float) $rate;
            }

            return $rates;

        } catch (Exception $e) {
            self::logMessage("Fixer.io API error: " . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Store exchange rates in database
     *
     * @param string $baseCurrency Base currency
     * @param array $rates Exchange rates array
     * @return bool Success status
     */
    private static function storeRates(string $baseCurrency, array $rates): bool
    {
        try {
            Database::beginTransaction();

            foreach ($rates as $targetCurrency => $rate) {
                $sql = "INSERT INTO exchange_rates
                        (base_currency, target_currency, rate, provider, last_updated, created_at)
                        VALUES (?, ?, ?, ?, NOW(), NOW())";

                Database::execute($sql, [
                    $baseCurrency,
                    $targetCurrency,
                    $rate,
                    Config::get('EXCHANGE_API_PROVIDER', 'exchangerate-api')
                ]);
            }

            Database::commit();

            self::logMessage("Stored " . count($rates) . " exchange rates for base currency {$baseCurrency}", 'info');
            return true;

        } catch (Exception $e) {
            Database::rollback();
            self::logMessage("Failed to store exchange rates: " . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Update all exchange rates (for cron job)
     *
     * @return array Update results summary
     */
    public static function updateAllRates(): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'currencies' => [],
            'errors' => []
        ];

        foreach (self::SUPPORTED_CURRENCIES as $baseCurrency) {
            try {
                $rates = self::fetchLatestRates($baseCurrency);

                if ($rates !== null) {
                    $stored = self::storeRates($baseCurrency, $rates);

                    if ($stored) {
                        $results['success']++;
                        $results['currencies'][] = $baseCurrency;
                    } else {
                        $results['failed']++;
                        $results['errors'][] = "Failed to store rates for {$baseCurrency}";
                    }
                } else {
                    $results['failed']++;
                    $results['errors'][] = "Failed to fetch rates for {$baseCurrency}";
                }

                // Rate limiting - wait 1 second between requests
                sleep(1);

            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = "Error updating {$baseCurrency}: " . $e->getMessage();
            }
        }

        self::logMessage(
            "Exchange rate update completed: {$results['success']} success, {$results['failed']} failed",
            $results['failed'] > 0 ? 'warning' : 'info'
        );

        return $results;
    }

    /**
     * Get all current exchange rates for a base currency
     *
     * @param string $baseCurrency Base currency
     * @param bool $forceRefresh Force API refresh
     * @return array|null Array of current rates
     */
    public static function getAllRates(string $baseCurrency = self::DEFAULT_BASE_CURRENCY, bool $forceRefresh = false): ?array
    {
        try {
            if ($forceRefresh) {
                $rates = self::fetchLatestRates($baseCurrency);
                if ($rates !== null) {
                    self::storeRates($baseCurrency, $rates);
                    return $rates;
                }
            }

            // Get from cache
            $sql = "SELECT target_currency, rate, last_updated
                    FROM exchange_rates
                    WHERE base_currency = ?
                    AND last_updated > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    ORDER BY last_updated DESC";

            $results = Database::select($sql, [$baseCurrency]);

            $rates = [];
            foreach ($results as $result) {
                $rates[$result['target_currency']] = (float) $result['rate'];
            }

            // If we don't have all supported currencies, try to fetch fresh data
            $missingSurrencies = array_diff(self::SUPPORTED_CURRENCIES, array_keys($rates));
            if (!empty($missingSurrencies)) {
                $freshRates = self::fetchLatestRates($baseCurrency);
                if ($freshRates !== null) {
                    self::storeRates($baseCurrency, $freshRates);
                    return $freshRates;
                }
            }

            return empty($rates) ? null : $rates;

        } catch (Exception $e) {
            self::logMessage("Failed to get all rates: " . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Get historical exchange rate for a specific date
     *
     * @param string $fromCurrency Source currency
     * @param string $toCurrency Target currency
     * @param string $date Date in YYYY-MM-DD format
     * @return float|null Historical rate or null if not available
     */
    public static function getHistoricalRate(string $fromCurrency, string $toCurrency, string $date): ?float
    {
        try {
            $sql = "SELECT rate
                    FROM exchange_rates
                    WHERE base_currency = ? AND target_currency = ?
                    AND DATE(last_updated) = ?
                    ORDER BY last_updated DESC
                    LIMIT 1";

            $result = Database::selectOne($sql, [$fromCurrency, $toCurrency, $date]);

            return $result ? (float) $result['rate'] : null;

        } catch (Exception $e) {
            self::logMessage("Failed to get historical rate: " . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Get exchange rate statistics
     *
     * @param string $fromCurrency Source currency
     * @param string $toCurrency Target currency
     * @param int $days Number of days for statistics
     * @return array|null Statistics array
     */
    public static function getRateStatistics(string $fromCurrency, string $toCurrency, int $days = 30): ?array
    {
        try {
            $sql = "SELECT
                        MIN(rate) as min_rate,
                        MAX(rate) as max_rate,
                        AVG(rate) as avg_rate,
                        COUNT(*) as data_points,
                        STDDEV(rate) as std_deviation
                    FROM exchange_rates
                    WHERE base_currency = ? AND target_currency = ?
                    AND last_updated >= DATE_SUB(NOW(), INTERVAL ? DAY)";

            $result = Database::selectOne($sql, [$fromCurrency, $toCurrency, $days]);

            if (!$result || $result['data_points'] == 0) {
                return null;
            }

            return [
                'min_rate' => (float) $result['min_rate'],
                'max_rate' => (float) $result['max_rate'],
                'avg_rate' => (float) $result['avg_rate'],
                'std_deviation' => (float) $result['std_deviation'],
                'data_points' => (int) $result['data_points'],
                'volatility' => (float) $result['std_deviation'] / (float) $result['avg_rate'] * 100
            ];

        } catch (Exception $e) {
            self::logMessage("Failed to get rate statistics: " . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Check if currency is supported
     *
     * @param string $currency Currency code
     * @return bool True if supported
     */
    public static function isSupportedCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), self::SUPPORTED_CURRENCIES);
    }

    /**
     * Get list of supported currencies
     *
     * @return array Supported currency codes
     */
    public static function getSupportedCurrencies(): array
    {
        return self::SUPPORTED_CURRENCIES;
    }

    /**
     * Format currency amount with proper symbol and formatting
     *
     * @param float $amount Amount to format
     * @param string $currency Currency code
     * @return string Formatted currency string
     */
    public static function formatCurrency(float $amount, string $currency): string
    {
        $symbols = [
            'GBP' => '£',
            'EUR' => '€',
            'USD' => '$',
            'CAD' => 'C$',
            'AUD' => 'A$'
        ];

        $symbol = $symbols[$currency] ?? $currency;
        $formattedAmount = number_format($amount, 2);

        // Different positioning for different currencies
        if ($currency === 'EUR') {
            return "{$formattedAmount} {$symbol}";
        }

        return "{$symbol}{$formattedAmount}";
    }

    /**
     * Clean old exchange rate records (for maintenance)
     *
     * @param int $daysToKeep Number of days to keep records
     * @return int Number of deleted records
     */
    public static function cleanOldRates(int $daysToKeep = 30): int
    {
        try {
            $sql = "DELETE FROM exchange_rates
                    WHERE last_updated < DATE_SUB(NOW(), INTERVAL ? DAY)";

            $deleted = Database::delete($sql, [$daysToKeep]);

            self::logMessage("Cleaned {$deleted} old exchange rate records", 'info');
            return $deleted;

        } catch (Exception $e) {
            self::logMessage("Failed to clean old rates: " . $e->getMessage(), 'error');
            return 0;
        }
    }

    /**
     * Get API provider status and limits
     *
     * @return array Provider status information
     */
    public static function getAPIProviderStatus(): array
    {
        $status = [];

        foreach (self::$apiProviders as $provider => $config) {
            $status[$provider] = [
                'name' => $provider,
                'url' => $config['url'],
                'key_required' => $config['key_required'],
                'available' => true, // Will be determined by test request
                'last_error' => null
            ];

            // Test API availability
            try {
                if ($provider === 'exchangerate-api') {
                    $testUrl = $config['url'] . 'USD';
                    $response = @file_get_contents($testUrl, false, stream_context_create([
                        'http' => ['timeout' => 5]
                    ]));
                    $status[$provider]['available'] = $response !== false;
                } elseif ($provider === 'fixer') {
                    $apiKey = Config::get('EXCHANGE_API_KEY');
                    $status[$provider]['available'] = !empty($apiKey);
                    if (empty($apiKey)) {
                        $status[$provider]['last_error'] = 'API key not configured';
                    }
                }
            } catch (Exception $e) {
                $status[$provider]['available'] = false;
                $status[$provider]['last_error'] = $e->getMessage();
            }
        }

        return $status;
    }

    /**
     * Health check for monitoring systems
     *
     * @return array Health status information
     */
    public static function healthCheck(): array
    {
        try {
            $startTime = microtime(true);

            // Check if we have recent rates
            $sql = "SELECT COUNT(*) as recent_rates
                    FROM exchange_rates
                    WHERE last_updated > DATE_SUB(NOW(), INTERVAL 24 HOUR)";

            $result = Database::selectOne($sql);
            $recentRates = (int) $result['recent_rates'];

            // Test conversion
            $testConversion = self::convert('GBP', 'USD', 1.0);

            $responseTime = microtime(true) - $startTime;

            return [
                'status' => $recentRates > 0 && $testConversion !== null ? 'healthy' : 'unhealthy',
                'response_time' => $responseTime,
                'recent_rates_count' => $recentRates,
                'test_conversion_successful' => $testConversion !== null,
                'last_update_check' => date('Y-m-d H:i:s'),
                'api_providers' => self::getAPIProviderStatus()
            ];

        } catch (Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'response_time' => null
            ];
        }
    }

    /**
     * Log exchange rate messages
     *
     * @param string $message Log message
     * @param string $level Log level (info, warning, error)
     * @return void
     */
    private static function logMessage(string $message, string $level = 'info'): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [ExchangeRate] [{$level}] {$message}" . PHP_EOL;

        // Log to file if logging is enabled
        if (Config::get('LOG_EXCHANGE_RATES', true)) {
            $logFile = Config::get('LOG_PATH', '/var/log/winabrandnew') . '/exchange_rates.log';

            if (is_writable(dirname($logFile))) {
                file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
            }
        }

        // Also log to error_log for critical errors
        if ($level === 'error') {
            error_log("ExchangeRate Error: {$message}");
        }
    }
}

/**
 * Exchange Rate Helper Functions
 *
 * Convenience functions for common currency operations
 */

/**
 * Quick currency conversion helper
 *
 * @param float $amount Amount to convert
 * @param string $from Source currency
 * @param string $to Target currency
 * @return float|null Converted amount
 */
function convert_currency(float $amount, string $from, string $to): ?float
{
    return ExchangeRate::convert($from, $to, $amount);
}

/**
 * Format currency amount helper
 *
 * @param float $amount Amount to format
 * @param string $currency Currency code
 * @return string Formatted currency string
 */
function format_currency(float $amount, string $currency): string
{
    return ExchangeRate::formatCurrency($amount, $currency);
}

/**
 * Get current exchange rate helper
 *
 * @param string $from Source currency
 * @param string $to Target currency
 * @return float|null Exchange rate
 */
function get_exchange_rate(string $from, string $to): ?float
{
    return ExchangeRate::getExchangeRate($from, $to);
}

/**
 * Check if currency is supported helper
 *
 * @param string $currency Currency code
 * @return bool True if supported
 */
function is_supported_currency(string $currency): bool
{
    return ExchangeRate::isSupportedCurrency($currency);
}
