<?php
declare(strict_types=1);

/**
 * File: core/CurrencyConverter.php
 * Location: core/CurrencyConverter.php
 *
 * WinABN Currency Converter
 *
 * Handles currency conversion using exchange rates, IP geolocation detection,
 * and caching for optimal performance in the WinABN platform.
 *
 * @package WinABN\Core
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Core;

use WinABN\Models\ExchangeRate;
use WinABN\Core\Database;
use Exception;

class CurrencyConverter
{
    /**
     * Supported currencies
     *
     * @var array<string>
     */
    public const SUPPORTED_CURRENCIES = ['GBP', 'EUR', 'USD', 'CAD', 'AUD'];

    /**
     * Default currency
     *
     * @var string
     */
    public const DEFAULT_CURRENCY = 'GBP';

    /**
     * Exchange rate API URL
     *
     * @var string
     */
    private string $apiUrl;

    /**
     * API key for exchange rate service
     *
     * @var string
     */
    private string $apiKey;

    /**
     * Exchange rate model
     *
     * @var ExchangeRate
     */
    private ExchangeRate $exchangeRateModel;

    /**
     * Cache for rates during request
     *
     * @var array<string, float>
     */
    private array $rateCache = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->apiUrl = env('EXCHANGE_API_URL', 'https://api.exchangerate-api.com/v4/latest/');
        $this->apiKey = env('EXCHANGE_API_KEY', '');
        $this->exchangeRateModel = new ExchangeRate();
    }

    /**
     * Convert amount from one currency to another
     *
     * @param float $amount Amount to convert
     * @param string $fromCurrency Source currency
     * @param string $toCurrency Target currency
     * @param bool $useCache Whether to use cached rates
     * @return float Converted amount
     * @throws Exception
     */
    public function convert(float $amount, string $fromCurrency, string $toCurrency, bool $useCache = true): float
    {
        // No conversion needed for same currency
        if ($fromCurrency === $toCurrency) {
            return $amount;
        }

        // Validate currencies
        $this->validateCurrency($fromCurrency);
        $this->validateCurrency($toCurrency);

        // Get exchange rate
        $rate = $this->getExchangeRate($fromCurrency, $toCurrency, $useCache);

        if ($rate === null) {
            throw new Exception("Exchange rate not available for {$fromCurrency} to {$toCurrency}");
        }

        return round($amount * $rate, 2);
    }

    /**
     * Get exchange rate between two currencies
     *
     * @param string $fromCurrency Source currency
     * @param string $toCurrency Target currency
     * @param bool $useCache Whether to use cached rates
     * @return float|null Exchange rate or null if not available
     */
    public function getExchangeRate(string $fromCurrency, string $toCurrency, bool $useCache = true): ?float
    {
        $cacheKey = "{$fromCurrency}_{$toCurrency}";

        // Check in-memory cache first
        if ($useCache && isset($this->rateCache[$cacheKey])) {
            return $this->rateCache[$cacheKey];
        }

        // Check database cache
        $rate = $this->exchangeRateModel->getRate($fromCurrency, $toCurrency);

        if ($rate !== null) {
            $this->rateCache[$cacheKey] = $rate;
            return $rate;
        }

        // Fetch from API if not in cache
        try {
            $rate = $this->fetchRateFromApi($fromCurrency, $toCurrency);
            if ($rate !== null) {
                $this->rateCache[$cacheKey] = $rate;
                $this->exchangeRateModel->updateRate($fromCurrency, $toCurrency, $rate);
                return $rate;
            }
        } catch (Exception $e) {
            $this->logError('Failed to fetch exchange rate from API', [
                'from' => $fromCurrency,
                'to' => $toCurrency,
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    /**
     * Fetch exchange rate from external API
     *
     * @param string $fromCurrency Source currency
     * @param string $toCurrency Target currency
     * @return float|null Exchange rate or null if failed
     * @throws Exception
     */
    private function fetchRateFromApi(string $fromCurrency, string $toCurrency): ?float
    {
        $url = $this->buildApiUrl($fromCurrency);

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'WinABN/1.0'
            ]
        ]);

        $response = file_get_contents($url, false, $context);

        if ($response === false) {
            throw new Exception('Failed to fetch exchange rates from API');
        }

        $data = json_decode($response, true);

        if (!$data || !isset($data['rates'])) {
            throw new Exception('Invalid API response format');
        }

        return $data['rates'][$toCurrency] ?? null;
    }

    /**
     * Build API URL for fetching rates
     *
     * @param string $baseCurrency Base currency
     * @return string API URL
     */
    private function buildApiUrl(string $baseCurrency): string
    {
        $url = rtrim($this->apiUrl, '/') . '/' . $baseCurrency;

        if ($this->apiKey) {
            $url .= '?access_key=' . $this->apiKey;
        }

        return $url;
    }

    /**
     * Update all exchange rates for supported currencies
     *
     * @return array Update results
     */
    public function updateAllRates(): array
    {
        $results = [];
        $baseCurrency = self::DEFAULT_CURRENCY;

        try {
            // Fetch rates with GBP as base
            $url = $this->buildApiUrl($baseCurrency);
            $response = file_get_contents($url, false, stream_context_create([
                'http' => ['timeout' => 15]
            ]));

            if ($response === false) {
                throw new Exception('Failed to fetch rates from API');
            }

            $data = json_decode($response, true);

            if (!$data || !isset($data['rates'])) {
                throw new Exception('Invalid API response');
            }

            Database::beginTransaction();

            // Update rates for all supported currencies
            foreach (self::SUPPORTED_CURRENCIES as $currency) {
                if ($currency === $baseCurrency) {
                    continue; // Skip base currency
                }

                $rate = $data['rates'][$currency] ?? null;

                if ($rate !== null) {
                    $success = $this->exchangeRateModel->updateRate($baseCurrency, $currency, $rate);

                    // Also store reverse rate
                    $reverseRate = 1 / $rate;
                    $this->exchangeRateModel->updateRate($currency, $baseCurrency, $reverseRate);

                    $results[$currency] = [
                        'success' => $success,
                        'rate' => $rate,
                        'reverse_rate' => $reverseRate
                    ];
                } else {
                    $results[$currency] = [
                        'success' => false,
                        'error' => 'Rate not available in API response'
                    ];
                }
            }

            // Update cross-rates (EUR to USD, etc.)
            $this->updateCrossRates($data['rates']);

            Database::commit();

            $this->logInfo('Exchange rates updated successfully', [
                'base_currency' => $baseCurrency,
                'updated_currencies' => array_keys($results)
            ]);

        } catch (Exception $e) {
            Database::rollback();

            $this->logError('Failed to update exchange rates', [
                'error' => $e->getMessage()
            ]);

            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Update cross-rates between non-GBP currencies
     *
     * @param array $rates Rates from API (GBP as base)
     * @return void
     */
    private function updateCrossRates(array $rates): void
    {
        $currencies = array_filter(self::SUPPORTED_CURRENCIES, fn($c) => $c !== 'GBP');

        foreach ($currencies as $fromCurrency) {
            foreach ($currencies as $toCurrency) {
                if ($fromCurrency === $toCurrency) {
                    continue;
                }

                $fromRate = $rates[$fromCurrency] ?? null;
                $toRate = $rates[$toCurrency] ?? null;

                if ($fromRate && $toRate) {
                    // Calculate cross-rate: to get from EUR to USD, divide USD rate by EUR rate
                    $crossRate = $toRate / $fromRate;
                    $this->exchangeRateModel->updateRate($fromCurrency, $toCurrency, $crossRate);
                }
            }
        }
    }

    /**
     * Detect currency based on IP address
     *
     * @param string|null $ipAddress IP address (null for current client)
     * @return string Currency code
     */
    public function detectCurrencyByIp(?string $ipAddress = null): string
    {
        if ($ipAddress === null) {
            $ipAddress = $this->getClientIp();
        }

        // Skip geolocation for private/local IPs
        if (!filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return self::DEFAULT_CURRENCY;
        }

        try {
            $country = $this->getCountryByIp($ipAddress);
            return $this->getCurrencyByCountry($country);
        } catch (Exception $e) {
            $this->logError('IP geolocation failed', [
                'ip' => $ipAddress,
                'error' => $e->getMessage()
            ]);
            return self::DEFAULT_CURRENCY;
        }
    }

    /**
     * Get country code by IP address
     *
     * @param string $ipAddress IP address
     * @return string Country code
     * @throws Exception
     */
    private function getCountryByIp(string $ipAddress): string
    {
        $geoApiKey = env('GEOIP_API_KEY');
        $geoApiUrl = env('GEOIP_API_URL', 'http://api.ipstack.com/');

        if (!$geoApiKey) {
            // Fallback to free service or default
            return 'GB';
        }

        $url = rtrim($geoApiUrl, '/') . '/' . $ipAddress . '?access_key=' . $geoApiKey;

        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'user_agent' => 'WinABN/1.0'
            ]
        ]);

        $response = file_get_contents($url, false, $context);

        if ($response === false) {
            throw new Exception('Failed to fetch geolocation data');
        }

        $data = json_decode($response, true);

        if (!$data || !isset($data['country_code'])) {
            throw new Exception('Invalid geolocation response');
        }

        return $data['country_code'];
    }

    /**
     * Get currency by country code
     *
     * @param string $countryCode ISO country code
     * @return string Currency code
     */
    private function getCurrencyByCountry(string $countryCode): string
    {
        $currencyMap = [
            'GB' => 'GBP',
            'UK' => 'GBP',
            'US' => 'USD',
            'DE' => 'EUR',
            'FR' => 'EUR',
            'ES' => 'EUR',
            'IT' => 'EUR',
            'NL' => 'EUR',
            'BE' => 'EUR',
            'AT' => 'EUR',
            'IE' => 'EUR',
            'FI' => 'EUR',
            'PT' => 'EUR',
            'GR' => 'EUR',
            'LU' => 'EUR',
            'SI' => 'EUR',
            'SK' => 'EUR',
            'EE' => 'EUR',
            'LV' => 'EUR',
            'LT' => 'EUR',
            'MT' => 'EUR',
            'CY' => 'EUR',
            'CA' => 'CAD',
            'AU' => 'AUD',
            'NZ' => 'AUD' // Use AUD for New Zealand as well
        ];

        return $currencyMap[$countryCode] ?? self::DEFAULT_CURRENCY;
    }

    /**
     * Format currency amount for display
     *
     * @param float $amount Amount
     * @param string $currency Currency code
     * @param bool $showSymbol Whether to show currency symbol
     * @return string Formatted amount
     */
    public function formatAmount(float $amount, string $currency, bool $showSymbol = true): string
    {
        $symbols = [
            'GBP' => '£',
            'EUR' => '€',
            'USD' => '$',
            'CAD' => 'C$',
            'AUD' => 'A$'
        ];

        $formattedAmount = number_format($amount, 2, '.', ',');

        if ($showSymbol && isset($symbols[$currency])) {
            return $symbols[$currency] . $formattedAmount;
        }

        return $formattedAmount . ($showSymbol ? ' ' . $currency : '');
    }

    /**
     * Get all current exchange rates
     *
     * @param string $baseCurrency Base currency
     * @return array Exchange rates
     */
    public function getAllRates(string $baseCurrency = self::DEFAULT_CURRENCY): array
    {
        $rates = [];

        foreach (self::SUPPORTED_CURRENCIES as $currency) {
            if ($currency === $baseCurrency) {
                $rates[$currency] = 1.0;
            } else {
                $rates[$currency] = $this->getExchangeRate($baseCurrency, $currency);
            }
        }

        return array_filter($rates, fn($rate) => $rate !== null);
    }

    /**
     * Validate currency code
     *
     * @param string $currency Currency code
     * @throws Exception If currency is not supported
     */
    private function validateCurrency(string $currency): void
    {
        if (!in_array($currency, self::SUPPORTED_CURRENCIES)) {
            throw new Exception("Unsupported currency: {$currency}");
        }
    }

    /**
     * Get client IP address
     *
     * @return string Client IP
     */
    private function getClientIp(): string
    {
        return client_ip();
    }

    /**
     * Check if rates need updating
     *
     * @return bool True if rates are stale
     */
    public function needsUpdate(): bool
    {
        $lastUpdate = Database::fetchColumn(
            "SELECT MAX(updated_at) FROM exchange_rates WHERE base_currency = ?",
            [self::DEFAULT_CURRENCY]
        );

        if (!$lastUpdate) {
            return true; // No rates found, needs update
        }

        // Update if older than 24 hours
        $updateThreshold = date('Y-m-d H:i:s', strtotime('-24 hours'));
        return $lastUpdate < $updateThreshold;
    }

    /**
     * Get rate age in hours
     *
     * @param string $fromCurrency Source currency
     * @param string $toCurrency Target currency
     * @return int|null Age in hours or null if not found
     */
    public function getRateAge(string $fromCurrency, string $toCurrency): ?int
    {
        $rate = Database::fetchOne(
            "SELECT updated_at FROM exchange_rates WHERE base_currency = ? AND target_currency = ? ORDER BY updated_at DESC LIMIT 1",
            [$fromCurrency, $toCurrency]
        );

        if (!$rate) {
            return null;
        }

        $now = new \DateTime();
        $updated = new \DateTime($rate['updated_at']);
        $diff = $now->diff($updated);

        return ($diff->days * 24) + $diff->h;
    }

    /**
     * Clear rate cache
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->rateCache = [];
    }

    /**
     * Log error message
     *
     * @param string $message Error message
     * @param array $context Error context
     * @return void
     */
    private function logError(string $message, array $context = []): void
    {
        if (function_exists('app_log')) {
            app_log('error', $message, $context);
        } else {
            error_log("Currency Converter Error: $message " . json_encode($context));
        }
    }

    /**
     * Log info message
     *
     * @param string $message Info message
     * @param array $context Message context
     * @return void
     */
    private function logInfo(string $message, array $context = []): void
    {
        if (function_exists('app_log')) {
            app_log('info', $message, $context);
        }
    }

    /**
     * Get currency display information
     *
     * @return array Currency display data
     */
    public static function getCurrencyDisplayInfo(): array
    {
        return [
            'GBP' => [
                'name' => 'British Pound',
                'symbol' => '£',
                'code' => 'GBP'
            ],
            'EUR' => [
                'name' => 'Euro',
                'symbol' => '€',
                'code' => 'EUR'
            ],
            'USD' => [
                'name' => 'US Dollar',
                'symbol' => '$',
                'code' => 'USD'
            ],
            'CAD' => [
                'name' => 'Canadian Dollar',
                'symbol' => 'C$',
                'code' => 'CAD'
            ],
            'AUD' => [
                'name' => 'Australian Dollar',
                'symbol' => 'A$',
                'code' => 'AUD'
            ]
        ];
    }
}
