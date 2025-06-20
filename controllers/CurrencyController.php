<?php
/**
 * Currency Controller for Win a Brand New Application
 * File: /controllers/CurrencyController.php
 *
 * Handles currency detection, conversion, and price display formatting
 * according to the Development Specification.
 *
 * Features:
 * - IP geolocation currency detection
 * - Exchange rate application
 * - Price display formatting
 * - Country-specific currency mapping
 * - Tax-inclusive pricing display
 *
 * @author Win a Brand New Development Team
 * @version 1.0
 * @since 2025-06-20
 */

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Security.php';
require_once __DIR__ . '/../models/TaxRate.php';
require_once __DIR__ . '/../models/GameSession.php';

class CurrencyController extends BaseController
{
    private $db;
    private $security;
    private $taxRateModel;
    private $gameSessionModel;

    // Supported currencies with their symbols and formatting
    private const SUPPORTED_CURRENCIES = [
        'GBP' => ['symbol' => '£', 'decimals' => 2, 'format' => 'before'],
        'EUR' => ['symbol' => '€', 'decimals' => 2, 'format' => 'before'],
        'USD' => ['symbol' => '$', 'decimals' => 2, 'format' => 'before'],
        'CAD' => ['symbol' => 'C$', 'decimals' => 2, 'format' => 'before'],
        'AUD' => ['symbol' => 'A$', 'decimals' => 2, 'format' => 'before'],
        'CHF' => ['symbol' => 'CHF', 'decimals' => 2, 'format' => 'after'],
        'SEK' => ['symbol' => 'kr', 'decimals' => 2, 'format' => 'after'],
        'NOK' => ['symbol' => 'kr', 'decimals' => 2, 'format' => 'after'],
        'DKK' => ['symbol' => 'kr', 'decimals' => 2, 'format' => 'after'],
        'PLN' => ['symbol' => 'zł', 'decimals' => 2, 'format' => 'after']
    ];

    // Country to currency mapping based on IP geolocation
    private const COUNTRY_CURRENCY_MAP = [
        'GB' => 'GBP', 'UK' => 'GBP', 'IM' => 'GBP', 'JE' => 'GBP', 'GG' => 'GBP',
        'IE' => 'EUR', 'FR' => 'EUR', 'DE' => 'EUR', 'IT' => 'EUR', 'ES' => 'EUR',
        'NL' => 'EUR', 'BE' => 'EUR', 'AT' => 'EUR', 'PT' => 'EUR', 'GR' => 'EUR',
        'FI' => 'EUR', 'EE' => 'EUR', 'LV' => 'EUR', 'LT' => 'EUR', 'SK' => 'EUR',
        'SI' => 'EUR', 'CY' => 'EUR', 'MT' => 'EUR', 'LU' => 'EUR',
        'US' => 'USD', 'PR' => 'USD', 'VI' => 'USD', 'GU' => 'USD', 'AS' => 'USD',
        'CA' => 'CAD',
        'AU' => 'AUD',
        'CH' => 'CHF', 'LI' => 'CHF',
        'SE' => 'SEK',
        'NO' => 'NOK', 'SJ' => 'NOK',
        'DK' => 'DKK', 'FO' => 'DKK', 'GL' => 'DKK',
        'PL' => 'PLN'
    ];

    // IP Geolocation services (fallback chain)
    private const GEOLOCATION_SERVICES = [
        'ipapi' => 'http://ip-api.com/json/{ip}?fields=countryCode',
        'ipinfo' => 'https://ipinfo.io/{ip}/json',
        'geojs' => 'https://get.geojs.io/v1/ip/geo/{ip}.json'
    ];

    public function __construct()
    {
        parent::__construct();
        $this->db = new Database();
        $this->security = new Security();
        $this->taxRateModel = new TaxRate();
        $this->gameSessionModel = new GameSession();
    }

    /**
     * Detect user's currency based on IP geolocation
     *
     * @return array Currency information
     */
    public function detectCurrency()
    {
        try {
            // Check if currency is already detected in session
            if (isset($_SESSION['detected_currency'])) {
                return $this->jsonResponse([
                    'success' => true,
                    'currency' => $_SESSION['detected_currency'],
                    'source' => 'session'
                ]);
            }

            // Get user's IP address
            $userIP = $this->getUserIP();

            // Skip detection for localhost/private IPs
            if ($this->isPrivateIP($userIP)) {
                $currency = $this->getDefaultCurrency();
                $_SESSION['detected_currency'] = $currency;

                return $this->jsonResponse([
                    'success' => true,
                    'currency' => $currency,
                    'source' => 'default_localhost'
                ]);
            }

            // Attempt geolocation detection
            $countryCode = $this->detectCountryFromIP($userIP);
            $currency = $this->getCurrencyFromCountry($countryCode);

            // Store in session for performance
            $_SESSION['detected_currency'] = $currency;

            // Log currency detection for analytics
            $this->logCurrencyDetection($userIP, $countryCode, $currency['code']);

            return $this->jsonResponse([
                'success' => true,
                'currency' => $currency,
                'source' => 'geolocation',
                'country_code' => $countryCode
            ]);

        } catch (Exception $e) {
            error_log("Currency detection error: " . $e->getMessage());

            // Fallback to default currency
            $currency = $this->getDefaultCurrency();
            $_SESSION['detected_currency'] = $currency;

            return $this->jsonResponse([
                'success' => true,
                'currency' => $currency,
                'source' => 'fallback',
                'error' => 'Detection failed, using default'
            ]);
        }
    }

    /**
     * Convert price from base currency (GBP) to target currency
     *
     * @param float $basePrice Price in GBP
     * @param string $targetCurrency Target currency code
     * @return array Converted price information
     */
    public function convertPrice($basePrice, $targetCurrency = null)
    {
        try {
            // Validate base price
            if (!is_numeric($basePrice) || $basePrice < 0) {
                throw new InvalidArgumentException('Invalid base price');
            }

            // Use detected currency if not specified
            if (!$targetCurrency) {
                $currencyInfo = $_SESSION['detected_currency'] ?? $this->getDefaultCurrency();
                $targetCurrency = $currencyInfo['code'];
            }

            // Validate target currency
            if (!isset(self::SUPPORTED_CURRENCIES[$targetCurrency])) {
                throw new InvalidArgumentException('Unsupported currency: ' . $targetCurrency);
            }

            // Get current exchange rate
            $exchangeRate = $this->getExchangeRate('GBP', $targetCurrency);

            // Convert price
            $convertedPrice = $basePrice * $exchangeRate;

            // Get tax rate for the currency/country
            $taxRate = $this->taxRateModel->getTaxRateForCurrency($targetCurrency);

            // Calculate tax-inclusive price
            $taxInclusivePrice = $convertedPrice * (1 + $taxRate / 100);

            // Format the price
            $formattedPrice = $this->formatPrice($taxInclusivePrice, $targetCurrency);

            return $this->jsonResponse([
                'success' => true,
                'original_price' => $basePrice,
                'converted_price' => round($convertedPrice, 2),
                'tax_rate' => $taxRate,
                'tax_inclusive_price' => round($taxInclusivePrice, 2),
                'formatted_price' => $formattedPrice,
                'currency' => $targetCurrency,
                'exchange_rate' => $exchangeRate
            ]);

        } catch (Exception $e) {
            error_log("Price conversion error: " . $e->getMessage());

            return $this->jsonResponse([
                'success' => false,
                'error' => 'Price conversion failed',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Get current exchange rates for all supported currencies
     *
     * @return array Exchange rates data
     */
    public function getExchangeRates()
    {
        try {
            // Check cache first (rates cached for 1 hour)
            $cacheKey = 'exchange_rates_' . date('Y-m-d-H');
            $cachedRates = $this->getCachedData($cacheKey);

            if ($cachedRates) {
                return $this->jsonResponse([
                    'success' => true,
                    'rates' => $cachedRates,
                    'source' => 'cache',
                    'updated_at' => $cachedRates['updated_at'] ?? null
                ]);
            }

            // Fetch fresh rates from database
            $rates = $this->fetchExchangeRatesFromDB();

            if (empty($rates)) {
                // Fallback to external API if DB is empty
                $rates = $this->fetchExchangeRatesFromAPI();
            }

            // Add metadata
            $rates['updated_at'] = date('Y-m-d H:i:s');
            $rates['base_currency'] = 'GBP';

            // Cache for 1 hour
            $this->setCachedData($cacheKey, $rates, 3600);

            return $this->jsonResponse([
                'success' => true,
                'rates' => $rates,
                'source' => 'fresh'
            ]);

        } catch (Exception $e) {
            error_log("Exchange rates fetch error: " . $e->getMessage());

            return $this->jsonResponse([
                'success' => false,
                'error' => 'Failed to fetch exchange rates',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Set user's preferred currency manually
     *
     * @return array Response
     */
    public function setCurrency()
    {
        try {
            // Verify CSRF token
            $this->security->validateCSRFToken();

            // Get currency from POST data
            $currency = $_POST['currency'] ?? '';

            // Validate currency
            if (!isset(self::SUPPORTED_CURRENCIES[$currency])) {
                throw new InvalidArgumentException('Invalid currency code');
            }

            // Update session
            $_SESSION['detected_currency'] = $this->getCurrencyInfo($currency);

            // Update game session if exists
            if (isset($_SESSION['game_session_id'])) {
                $this->gameSessionModel->updateCurrency($_SESSION['game_session_id'], $currency);
            }

            // Log currency change
            $this->logCurrencyChange($this->getUserIP(), $currency);

            return $this->jsonResponse([
                'success' => true,
                'currency' => $_SESSION['detected_currency'],
                'message' => 'Currency updated successfully'
            ]);

        } catch (Exception $e) {
            error_log("Currency set error: " . $e->getMessage());

            return $this->jsonResponse([
                'success' => false,
                'error' => 'Failed to set currency',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Get list of all supported currencies
     *
     * @return array Currencies list
     */
    public function getSupportedCurrencies()
    {
        $currencies = [];

        foreach (self::SUPPORTED_CURRENCIES as $code => $info) {
            $currencies[] = [
                'code' => $code,
                'symbol' => $info['symbol'],
                'name' => $this->getCurrencyName($code),
                'format' => $info['format']
            ];
        }

        return $this->jsonResponse([
            'success' => true,
            'currencies' => $currencies,
            'default' => 'GBP'
        ]);
    }

    /**
     * Get formatted price display for frontend
     *
     * @param float $price Price amount
     * @param string $currency Currency code
     * @return string Formatted price
     */
    private function formatPrice($price, $currency)
    {
        if (!isset(self::SUPPORTED_CURRENCIES[$currency])) {
            $currency = 'GBP'; // Fallback
        }

        $currencyInfo = self::SUPPORTED_CURRENCIES[$currency];
        $roundedPrice = round($price, $currencyInfo['decimals']);
        $formattedAmount = number_format($roundedPrice, $currencyInfo['decimals']);

        if ($currencyInfo['format'] === 'before') {
            return $currencyInfo['symbol'] . $formattedAmount;
        } else {
            return $formattedAmount . ' ' . $currencyInfo['symbol'];
        }
    }

    /**
     * Detect country from IP address using geolocation services
     *
     * @param string $ip IP address
     * @return string Country code
     */
    private function detectCountryFromIP($ip)
    {
        foreach (self::GEOLOCATION_SERVICES as $service => $url) {
            try {
                $apiUrl = str_replace('{ip}', $ip, $url);

                $context = stream_context_create([
                    'http' => [
                        'timeout' => 5,
                        'user_agent' => 'WinABrandNew/1.0'
                    ]
                ]);

                $response = file_get_contents($apiUrl, false, $context);

                if ($response === false) {
                    continue;
                }

                $data = json_decode($response, true);

                if (!$data) {
                    continue;
                }

                // Extract country code based on service
                $countryCode = $this->extractCountryCode($data, $service);

                if ($countryCode) {
                    return strtoupper($countryCode);
                }

            } catch (Exception $e) {
                error_log("Geolocation service {$service} error: " . $e->getMessage());
                continue;
            }
        }

        // Fallback to UK if all services fail
        return 'GB';
    }

    /**
     * Extract country code from geolocation service response
     *
     * @param array $data API response data
     * @param string $service Service name
     * @return string|null Country code
     */
    private function extractCountryCode($data, $service)
    {
        switch ($service) {
            case 'ipapi':
                return $data['countryCode'] ?? null;
            case 'ipinfo':
                return $data['country'] ?? null;
            case 'geojs':
                return $data['country_code'] ?? null;
            default:
                return null;
        }
    }

    /**
     * Get currency from country code
     *
     * @param string $countryCode Two-letter country code
     * @return array Currency information
     */
    private function getCurrencyFromCountry($countryCode)
    {
        $currencyCode = self::COUNTRY_CURRENCY_MAP[$countryCode] ?? 'GBP';
        return $this->getCurrencyInfo($currencyCode);
    }

    /**
     * Get currency information
     *
     * @param string $currencyCode Currency code
     * @return array Currency information
     */
    private function getCurrencyInfo($currencyCode)
    {
        if (!isset(self::SUPPORTED_CURRENCIES[$currencyCode])) {
            $currencyCode = 'GBP'; // Fallback
        }

        $info = self::SUPPORTED_CURRENCIES[$currencyCode];

        return [
            'code' => $currencyCode,
            'symbol' => $info['symbol'],
            'name' => $this->getCurrencyName($currencyCode),
            'decimals' => $info['decimals'],
            'format' => $info['format']
        ];
    }

    /**
     * Get default currency (GBP)
     *
     * @return array Default currency information
     */
    private function getDefaultCurrency()
    {
        return $this->getCurrencyInfo('GBP');
    }

    /**
     * Get exchange rate between two currencies
     *
     * @param string $from Base currency
     * @param string $to Target currency
     * @return float Exchange rate
     */
    private function getExchangeRate($from, $to)
    {
        // Same currency
        if ($from === $to) {
            return 1.0;
        }

        try {
            // Get from database first
            $query = "SELECT rate FROM exchange_rates
                     WHERE from_currency = ? AND to_currency = ?
                     AND updated_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                     ORDER BY updated_at DESC LIMIT 1";

            $stmt = $this->db->prepare($query);
            $stmt->execute([$from, $to]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                return (float)$result['rate'];
            }

            // Fallback to hardcoded rates (approximate)
            return $this->getFallbackExchangeRate($from, $to);

        } catch (Exception $e) {
            error_log("Exchange rate fetch error: " . $e->getMessage());
            return $this->getFallbackExchangeRate($from, $to);
        }
    }

    /**
     * Get fallback exchange rates (approximate)
     *
     * @param string $from Base currency
     * @param string $to Target currency
     * @return float Exchange rate
     */
    private function getFallbackExchangeRate($from, $to)
    {
        // Approximate rates from GBP (base currency)
        $gbpRates = [
            'GBP' => 1.0,
            'EUR' => 1.17,
            'USD' => 1.27,
            'CAD' => 1.71,
            'AUD' => 1.92,
            'CHF' => 1.13,
            'SEK' => 13.89,
            'NOK' => 13.67,
            'DKK' => 8.73,
            'PLN' => 5.14
        ];

        if ($from === 'GBP') {
            return $gbpRates[$to] ?? 1.0;
        } elseif ($to === 'GBP') {
            return 1.0 / ($gbpRates[$from] ?? 1.0);
        } else {
            // Convert via GBP
            $fromRate = $gbpRates[$from] ?? 1.0;
            $toRate = $gbpRates[$to] ?? 1.0;
            return $toRate / $fromRate;
        }
    }

    /**
     * Fetch exchange rates from database
     *
     * @return array Exchange rates
     */
    private function fetchExchangeRatesFromDB()
    {
        try {
            $query = "SELECT from_currency, to_currency, rate, updated_at
                     FROM exchange_rates
                     WHERE updated_at > DATE_SUB(NOW(), INTERVAL 24 HOURS)
                     ORDER BY updated_at DESC";

            $stmt = $this->db->prepare($query);
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $rates = [];
            foreach ($results as $row) {
                $rates[$row['to_currency']] = (float)$row['rate'];
            }

            return $rates;

        } catch (Exception $e) {
            error_log("DB exchange rates fetch error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Fetch exchange rates from external API (fallback)
     *
     * @return array Exchange rates
     */
    private function fetchExchangeRatesFromAPI()
    {
        // This would typically call an external API like Exchange Rates API
        // For now, return fallback rates
        $fallbackRates = [
            'EUR' => 1.17,
            'USD' => 1.27,
            'CAD' => 1.71,
            'AUD' => 1.92,
            'CHF' => 1.13,
            'SEK' => 13.89,
            'NOK' => 13.67,
            'DKK' => 8.73,
            'PLN' => 5.14
        ];

        return $fallbackRates;
    }

    /**
     * Get currency full name
     *
     * @param string $code Currency code
     * @return string Currency name
     */
    private function getCurrencyName($code)
    {
        $names = [
            'GBP' => 'British Pound',
            'EUR' => 'Euro',
            'USD' => 'US Dollar',
            'CAD' => 'Canadian Dollar',
            'AUD' => 'Australian Dollar',
            'CHF' => 'Swiss Franc',
            'SEK' => 'Swedish Krona',
            'NOK' => 'Norwegian Krone',
            'DKK' => 'Danish Krone',
            'PLN' => 'Polish Złoty'
        ];

        return $names[$code] ?? $code;
    }

    /**
     * Get user's IP address
     *
     * @return string IP address
     */
    private function getUserIP()
    {
        // Check for various headers that might contain the real IP
        $headers = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];

                // Handle comma-separated IPs (load balancers)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }

                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        // Fallback to REMOTE_ADDR
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Check if IP is private/local
     *
     * @param string $ip IP address
     * @return bool True if private IP
     */
    private function isPrivateIP($ip)
    {
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /**
     * Log currency detection for analytics
     *
     * @param string $ip IP address
     * @param string $countryCode Country code
     * @param string $currency Currency code
     */
    private function logCurrencyDetection($ip, $countryCode, $currency)
    {
        try {
            $query = "INSERT INTO currency_detection_log
                     (ip_address, country_code, detected_currency, created_at)
                     VALUES (?, ?, ?, NOW())";

            $stmt = $this->db->prepare($query);
            $stmt->execute([$ip, $countryCode, $currency]);

        } catch (Exception $e) {
            error_log("Currency detection logging error: " . $e->getMessage());
        }
    }

    /**
     * Log currency change for analytics
     *
     * @param string $ip IP address
     * @param string $currency New currency code
     */
    private function logCurrencyChange($ip, $currency)
    {
        try {
            $query = "INSERT INTO currency_change_log
                     (ip_address, new_currency, created_at)
                     VALUES (?, ?, NOW())";

            $stmt = $this->db->prepare($query);
            $stmt->execute([$ip, $currency]);

        } catch (Exception $e) {
            error_log("Currency change logging error: " . $e->getMessage());
        }
    }

    /**
     * Get cached data
     *
     * @param string $key Cache key
     * @return mixed Cached data or null
     */
    private function getCachedData($key)
    {
        if (isset($_SESSION['cache'][$key])) {
            $cache = $_SESSION['cache'][$key];

            if ($cache['expires'] > time()) {
                return $cache['data'];
            } else {
                unset($_SESSION['cache'][$key]);
            }
        }

        return null;
    }

    /**
     * Set cached data
     *
     * @param string $key Cache key
     * @param mixed $data Data to cache
     * @param int $ttl Time to live in seconds
     */
    private function setCachedData($key, $data, $ttl)
    {
        $_SESSION['cache'][$key] = [
            'data' => $data,
            'expires' => time() + $ttl
        ];
    }
}

?>
