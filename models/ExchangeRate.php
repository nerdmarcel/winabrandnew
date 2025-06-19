<?php
declare(strict_types=1);

/**
 * File: models/ExchangeRate.php
 * Location: models/ExchangeRate.php
 *
 * WinABN Exchange Rate Model
 *
 * Manages currency exchange rate data storage, retrieval, and updates
 * for multi-currency support in the WinABN platform.
 *
 * @package WinABN\Models
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Models;

use WinABN\Core\{Model, Database};
use Exception;

class ExchangeRate extends Model
{
    /**
     * Table name
     *
     * @var string
     */
    protected string $table = 'exchange_rates';

    /**
     * Fillable fields
     *
     * @var array<string>
     */
    protected array $fillable = [
        'base_currency',
        'target_currency',
        'rate',
        'provider'
    ];

    /**
     * Cache duration in seconds (6 hours)
     *
     * @var int
     */
    private const CACHE_DURATION = 21600;

    /**
     * Get exchange rate between two currencies
     *
     * @param string $fromCurrency Source currency
     * @param string $toCurrency Target currency
     * @param int $maxAgeHours Maximum age of rate in hours (default: 24)
     * @return float|null Exchange rate or null if not found
     */
    public function getRate(string $fromCurrency, string $toCurrency, int $maxAgeHours = 24): ?float
    {
        if ($fromCurrency === $toCurrency) {
            return 1.0;
        }

        $maxAge = date('Y-m-d H:i:s', strtotime("-{$maxAgeHours} hours"));

        $query = "
            SELECT rate
            FROM {$this->table}
            WHERE base_currency = ?
            AND target_currency = ?
            AND updated_at >= ?
            ORDER BY updated_at DESC
            LIMIT 1
        ";

        $result = Database::fetchColumn($query, [$fromCurrency, $toCurrency, $maxAge]);

        return $result !== false ? (float) $result : null;
    }

    /**
     * Update or insert exchange rate
     *
     * @param string $fromCurrency Source currency
     * @param string $toCurrency Target currency
     * @param float $rate Exchange rate
     * @param string $provider Rate provider (default: 'exchangerate-api')
     * @return bool Success status
     */
    public function updateRate(string $fromCurrency, string $toCurrency, float $rate, string $provider = 'exchangerate-api'): bool
    {
        try {
            Database::beginTransaction();

            // Check if rate exists for today
            $today = date('Y-m-d');
            $existingRate = Database::fetchOne(
                "SELECT id FROM {$this->table}
                 WHERE base_currency = ? AND target_currency = ?
                 AND DATE(updated_at) = ?",
                [$fromCurrency, $toCurrency, $today]
            );

            if ($existingRate) {
                // Update existing rate
                $query = "
                    UPDATE {$this->table}
                    SET rate = ?, provider = ?, updated_at = NOW()
                    WHERE id = ?
                ";
                $success = Database::execute($query, [$rate, $provider, $existingRate['id']])->rowCount() > 0;
            } else {
                // Insert new rate
                $query = "
                    INSERT INTO {$this->table} (base_currency, target_currency, rate, provider, updated_at, created_at)
                    VALUES (?, ?, ?, ?, NOW(), NOW())
                ";
                Database::execute($query, [$fromCurrency, $toCurrency, $rate, $provider]);
                $success = true;
            }

            Database::commit();

            if ($success) {
                $this->logRateUpdate($fromCurrency, $toCurrency, $rate, $provider);
            }

            return $success;

        } catch (Exception $e) {
            Database::rollback();
            $this->logError('Failed to update exchange rate', [
                'from' => $fromCurrency,
                'to' => $toCurrency,
                'rate' => $rate,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get all current rates for a base currency
     *
     * @param string $baseCurrency Base currency
     * @param int $maxAgeHours Maximum age in hours
     * @return array<string, float> Currency => rate pairs
     */
    public function getRatesForCurrency(string $baseCurrency, int $maxAgeHours = 24): array
    {
        $maxAge = date('Y-m-d H:i:s', strtotime("-{$maxAgeHours} hours"));

        $query = "
            SELECT target_currency, rate
            FROM {$this->table}
            WHERE base_currency = ?
            AND updated_at >= ?
            ORDER BY target_currency
        ";

        $results = Database::fetchAll($query, [$baseCurrency, $maxAge]);

        $rates = [];
        foreach ($results as $row) {
            $rates[$row['target_currency']] = (float) $row['rate'];
        }

        // Always include base currency with rate 1.0
        $rates[$baseCurrency] = 1.0;

        return $rates;
    }

    /**
     * Get rate history for a currency pair
     *
     * @param string $fromCurrency Source currency
     * @param string $toCurrency Target currency
     * @param int $days Number of days to retrieve (default: 30)
     * @return array<array<string, mixed>> Rate history
     */
    public function getRateHistory(string $fromCurrency, string $toCurrency, int $days = 30): array
    {
        $startDate = date('Y-m-d', strtotime("-{$days} days"));

        $query = "
            SELECT
                DATE(updated_at) as date,
                rate,
                provider,
                updated_at
            FROM {$this->table}
            WHERE base_currency = ?
            AND target_currency = ?
            AND DATE(updated_at) >= ?
            ORDER BY updated_at DESC
        ";

        $results = Database::fetchAll($query, [$fromCurrency, $toCurrency, $startDate]);

        // Convert rates to float and format dates
        return array_map(function($row) {
            return [
                'date' => $row['date'],
                'rate' => (float) $row['rate'],
                'provider' => $row['provider'],
                'updated_at' => $row['updated_at']
            ];
        }, $results);
    }

    /**
     * Get latest update time for any currency
     *
     * @return string|null Latest update timestamp
     */
    public function getLastUpdateTime(): ?string
    {
        return Database::fetchColumn(
            "SELECT MAX(updated_at) FROM {$this->table}"
        ) ?: null;
    }

    /**
     * Clean old exchange rate records
     *
     * @param int $keepDays Number of days to keep (default: 90)
     * @return int Number of records deleted
     */
    public function cleanOldRates(int $keepDays = 90): int
    {
        $cutoffDate = date('Y-m-d', strtotime("-{$keepDays} days"));

        $query = "DELETE FROM {$this->table} WHERE updated_at < ?";
        $statement = Database::execute($query, [$cutoffDate]);

        $deletedCount = $statement->rowCount();

        if ($deletedCount > 0) {
            $this->logInfo("Cleaned old exchange rates", [
                'deleted_count' => $deletedCount,
                'cutoff_date' => $cutoffDate
            ]);
        }

        return $deletedCount;
    }

    /**
     * Get rate statistics
     *
     * @param string $fromCurrency Source currency
     * @param string $toCurrency Target currency
     * @param int $days Number of days for statistics
     * @return array<string, mixed> Rate statistics
     */
    public function getRateStatistics(string $fromCurrency, string $toCurrency, int $days = 30): array
    {
        $startDate = date('Y-m-d', strtotime("-{$days} days"));

        $query = "
            SELECT
                COUNT(*) as total_updates,
                MIN(rate) as min_rate,
                MAX(rate) as max_rate,
                AVG(rate) as avg_rate,
                STDDEV(rate) as rate_volatility,
                MIN(updated_at) as first_update,
                MAX(updated_at) as last_update
            FROM {$this->table}
            WHERE base_currency = ?
            AND target_currency = ?
            AND DATE(updated_at) >= ?
        ";

        $result = Database::fetchOne($query, [$fromCurrency, $toCurrency, $startDate]);

        if (!$result) {
            return [];
        }

        return [
            'currency_pair' => "{$fromCurrency}/{$toCurrency}",
            'period_days' => $days,
            'total_updates' => (int) $result['total_updates'],
            'min_rate' => $result['min_rate'] ? (float) $result['min_rate'] : null,
            'max_rate' => $result['max_rate'] ? (float) $result['max_rate'] : null,
            'avg_rate' => $result['avg_rate'] ? (float) $result['avg_rate'] : null,
            'volatility' => $result['rate_volatility'] ? (float) $result['rate_volatility'] : null,
            'first_update' => $result['first_update'],
            'last_update' => $result['last_update'],
            'rate_change_percent' => $this->calculateRateChange($fromCurrency, $toCurrency, $days)
        ];
    }

    /**
     * Calculate rate change percentage over period
     *
     * @param string $fromCurrency Source currency
     * @param string $toCurrency Target currency
     * @param int $days Number of days
     * @return float|null Rate change percentage
     */
    private function calculateRateChange(string $fromCurrency, string $toCurrency, int $days): ?float
    {
        $startDate = date('Y-m-d', strtotime("-{$days} days"));

        // Get first rate in period
        $firstRate = Database::fetchColumn(
            "SELECT rate FROM {$this->table}
             WHERE base_currency = ? AND target_currency = ?
             AND DATE(updated_at) >= ?
             ORDER BY updated_at ASC LIMIT 1",
            [$fromCurrency, $toCurrency, $startDate]
        );

        // Get latest rate
        $latestRate = Database::fetchColumn(
            "SELECT rate FROM {$this->table}
             WHERE base_currency = ? AND target_currency = ?
             ORDER BY updated_at DESC LIMIT 1",
            [$fromCurrency, $toCurrency]
        );

        if (!$firstRate || !$latestRate || $firstRate == 0) {
            return null;
        }

        return (($latestRate - $firstRate) / $firstRate) * 100;
    }

    /**
     * Get all available currency pairs
     *
     * @return array<array<string, string>> Available currency pairs
     */
    public function getAvailablePairs(): array
    {
        $query = "
            SELECT DISTINCT base_currency, target_currency
            FROM {$this->table}
            WHERE updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ORDER BY base_currency, target_currency
        ";

        return Database::fetchAll($query);
    }

    /**
     * Check if rate is stale
     *
     * @param string $fromCurrency Source currency
     * @param string $toCurrency Target currency
     * @param int $maxAgeHours Maximum age in hours
     * @return bool True if rate is stale or missing
     */
    public function isRateStale(string $fromCurrency, string $toCurrency, int $maxAgeHours = 24): bool
    {
        $rate = $this->getRate($fromCurrency, $toCurrency, $maxAgeHours);
        return $rate === null;
    }

    /**
     * Get rates that need updating
     *
     * @param array<string> $currencies List of currencies to check
     * @param int $maxAgeHours Maximum age before considered stale
     * @return array<array<string, string>> Currency pairs needing update
     */
    public function getStaleRates(array $currencies, int $maxAgeHours = 24): array
    {
        $stalePairs = [];

        foreach ($currencies as $fromCurrency) {
            foreach ($currencies as $toCurrency) {
                if ($fromCurrency !== $toCurrency) {
                    if ($this->isRateStale($fromCurrency, $toCurrency, $maxAgeHours)) {
                        $stalePairs[] = [
                            'from_currency' => $fromCurrency,
                            'to_currency' => $toCurrency
                        ];
                    }
                }
            }
        }

        return $stalePairs;
    }

    /**
     * Batch update multiple rates
     *
     * @param array<array<string, mixed>> $rates Array of rate data
     * @param string $provider Rate provider
     * @return array<string, mixed> Update results
     */
    public function batchUpdateRates(array $rates, string $provider = 'exchangerate-api'): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];

        try {
            Database::beginTransaction();

            foreach ($rates as $rateData) {
                $success = $this->updateRate(
                    $rateData['from_currency'],
                    $rateData['to_currency'],
                    $rateData['rate'],
                    $provider
                );

                if ($success) {
                    $results['success']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = "Failed to update {$rateData['from_currency']}/{$rateData['to_currency']}";
                }
            }

            Database::commit();

        } catch (Exception $e) {
            Database::rollback();
            $results['errors'][] = 'Batch update failed: ' . $e->getMessage();
        }

        return $results;
    }

    /**
     * Log rate update
     *
     * @param string $fromCurrency Source currency
     * @param string $toCurrency Target currency
     * @param float $rate Exchange rate
     * @param string $provider Rate provider
     * @return void
     */
    private function logRateUpdate(string $fromCurrency, string $toCurrency, float $rate, string $provider): void
    {
        $this->logInfo('Exchange rate updated', [
            'from_currency' => $fromCurrency,
            'to_currency' => $toCurrency,
            'rate' => $rate,
            'provider' => $provider
        ]);
    }

    /**
     * Log error message
     *
     * @param string $message Error message
     * @param array<string, mixed> $context Error context
     * @return void
     */
    private function logError(string $message, array $context = []): void
    {
        if (function_exists('app_log')) {
            app_log('error', $message, $context);
        } else {
            error_log("Exchange Rate Error: $message " . json_encode($context));
        }
    }

    /**
     * Log info message
     *
     * @param string $message Info message
     * @param array<string, mixed> $context Message context
     * @return void
     */
    private function logInfo(string $message, array $context = []): void
    {
        if (function_exists('app_log')) {
            app_log('info', $message, $context);
        }
    }
}
