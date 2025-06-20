<?php

/**
 * Win a Brand New - Analytics Controller
 * File: /controllers/AnalyticsController.php
 *
 * Handles event tracking and conversion analytics according to the Development Specification.
 * Implements comprehensive analytics collection for business intelligence, conversion tracking,
 * revenue analysis, and performance metrics to support data-driven decision making.
 *
 * Key Features:
 * - Event logging for user interactions
 * - Conversion tracking through funnel stages
 * - Revenue analytics with currency support
 * - Performance metrics collection
 * - Fraud detection analytics
 * - Real-time dashboard metrics
 * - Custom event tracking
 * - Data export capabilities
 *
 * @package WinABrandNew\Controllers
 * @version 1.0.0
 * @author NerdMarcel Development Team
 */

namespace WinABrandNew\Controllers;

use WinABrandNew\Core\Database;
use WinABrandNew\Core\Security;
use WinABrandNew\Models\Analytics;
use WinABrandNew\Models\Participant;
use WinABrandNew\Models\Round;
use WinABrandNew\Models\Game;
use WinABrandNew\Controllers\BaseController;
use Exception;

class AnalyticsController extends BaseController
{
    /**
     * Event types for tracking
     */
    private const EVENT_TYPES = [
        'page_view' => 'Page View',
        'game_start' => 'Game Started',
        'question_answered' => 'Question Answered',
        'payment_initiated' => 'Payment Initiated',
        'payment_completed' => 'Payment Completed',
        'payment_failed' => 'Payment Failed',
        'game_completed' => 'Game Completed',
        'winner_selected' => 'Winner Selected',
        'claim_initiated' => 'Prize Claim Initiated',
        'claim_completed' => 'Prize Claim Completed',
        'referral_clicked' => 'Referral Link Clicked',
        'whatsapp_optop' => 'WhatsApp Opt-in',
        'email_sent' => 'Email Sent',
        'fraud_detected' => 'Fraud Detected'
    ];

    /**
     * Funnel stages for conversion tracking
     */
    private const FUNNEL_STAGES = [
        'landing' => 'Landing Page',
        'game_start' => 'Game Started',
        'questions_pre' => 'Pre-Payment Questions',
        'payment_page' => 'Payment Page',
        'payment_complete' => 'Payment Completed',
        'questions_post' => 'Post-Payment Questions',
        'game_complete' => 'Game Completed',
        'winner' => 'Won Prize',
        'claim' => 'Claimed Prize'
    ];

    /**
     * Analytics database instance
     *
     * @var Analytics
     */
    private Analytics $analyticsModel;

    /**
     * Constructor - Initialize analytics tracking
     */
    public function __construct()
    {
        parent::__construct();
        $this->analyticsModel = new Analytics();
    }

    /**
     * Track a custom event
     *
     * @return void
     */
    public function trackEvent(): void
    {
        try {
            // Validate required fields
            $requiredFields = ['event_type', 'game_id'];
            foreach ($requiredFields as $field) {
                if (empty($this->request[$field])) {
                    $this->jsonError("Missing required field: {$field}", 400);
                    return;
                }
            }

            // Validate event type
            if (!array_key_exists($this->request['event_type'], self::EVENT_TYPES)) {
                $this->jsonError('Invalid event type', 400);
                return;
            }

            // Get client information
            $clientInfo = $this->getClientInfo();

            // Prepare event data
            $eventData = [
                'event_type' => $this->request['event_type'],
                'game_id' => (int)$this->request['game_id'],
                'participant_id' => $this->request['participant_id'] ?? null,
                'round_id' => $this->request['round_id'] ?? null,
                'session_id' => $this->request['session_id'] ?? session_id(),
                'user_agent' => $clientInfo['user_agent'],
                'ip_address' => $clientInfo['ip_address'],
                'referrer' => $this->request['referrer'] ?? $_SERVER['HTTP_REFERER'] ?? null,
                'page_url' => $this->request['page_url'] ?? $_SERVER['REQUEST_URI'] ?? null,
                'event_data' => json_encode($this->request['event_data'] ?? []),
                'timestamp' => date('Y-m-d H:i:s'),
                'microtime' => microtime(true)
            ];

            // Add currency and amount for revenue events
            if (in_array($this->request['event_type'], ['payment_completed', 'payment_initiated'])) {
                $eventData['currency'] = $this->request['currency'] ?? 'GBP';
                $eventData['amount'] = floatval($this->request['amount'] ?? 0);
                $eventData['amount_usd'] = $this->convertToUSD($eventData['amount'], $eventData['currency']);
            }

            // Track the event
            $eventId = $this->analyticsModel->logEvent($eventData);

            // Update funnel tracking if applicable
            if (in_array($this->request['event_type'], array_keys(self::FUNNEL_STAGES))) {
                $this->updateFunnelTracking($eventData);
            }

            // Update performance metrics
            $this->updatePerformanceMetrics($eventData);

            $this->jsonSuccess([
                'event_id' => $eventId,
                'message' => 'Event tracked successfully'
            ]);

        } catch (Exception $e) {
            $this->logError('Event tracking failed', $e, $this->request);
            $this->jsonError('Failed to track event', 500);
        }
    }

    /**
     * Get conversion funnel analytics
     *
     * @return void
     */
    public function getFunnelAnalytics(): void
    {
        try {
            $gameId = $this->request['game_id'] ?? null;
            $dateFrom = $this->request['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
            $dateTo = $this->request['date_to'] ?? date('Y-m-d');

            // Get funnel data
            $funnelData = $this->analyticsModel->getFunnelAnalytics($gameId, $dateFrom, $dateTo);

            // Calculate conversion rates
            $processedFunnel = $this->calculateConversionRates($funnelData);

            $this->jsonSuccess([
                'funnel_data' => $processedFunnel,
                'date_range' => [
                    'from' => $dateFrom,
                    'to' => $dateTo
                ],
                'total_sessions' => $processedFunnel['landing']['count'] ?? 0
            ]);

        } catch (Exception $e) {
            $this->logError('Funnel analytics failed', $e, $this->request);
            $this->jsonError('Failed to get funnel analytics', 500);
        }
    }

    /**
     * Get revenue analytics
     *
     * @return void
     */
    public function getRevenueAnalytics(): void
    {
        try {
            $gameId = $this->request['game_id'] ?? null;
            $dateFrom = $this->request['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
            $dateTo = $this->request['date_to'] ?? date('Y-m-d');
            $currency = $this->request['currency'] ?? 'USD';

            // Get revenue data
            $revenueData = $this->analyticsModel->getRevenueAnalytics($gameId, $dateFrom, $dateTo, $currency);

            // Calculate additional metrics
            $metrics = [
                'total_revenue' => $revenueData['total_revenue'] ?? 0,
                'total_transactions' => $revenueData['total_transactions'] ?? 0,
                'average_transaction' => $revenueData['total_transactions'] > 0
                    ? round($revenueData['total_revenue'] / $revenueData['total_transactions'], 2)
                    : 0,
                'conversion_rate' => $this->calculateRevenueConversionRate($gameId, $dateFrom, $dateTo),
                'daily_breakdown' => $revenueData['daily_breakdown'] ?? [],
                'currency_breakdown' => $revenueData['currency_breakdown'] ?? [],
                'top_games' => $revenueData['top_games'] ?? []
            ];

            $this->jsonSuccess([
                'revenue_metrics' => $metrics,
                'date_range' => [
                    'from' => $dateFrom,
                    'to' => $dateTo
                ],
                'currency' => $currency
            ]);

        } catch (Exception $e) {
            $this->logError('Revenue analytics failed', $e, $this->request);
            $this->jsonError('Failed to get revenue analytics', 500);
        }
    }

    /**
     * Get performance metrics
     *
     * @return void
     */
    public function getPerformanceMetrics(): void
    {
        try {
            $gameId = $this->request['game_id'] ?? null;
            $dateFrom = $this->request['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
            $dateTo = $this->request['date_to'] ?? date('Y-m-d');

            // Get performance data
            $performanceData = $this->analyticsModel->getPerformanceMetrics($gameId, $dateFrom, $dateTo);

            // Calculate derived metrics
            $metrics = [
                'avg_question_time' => $performanceData['avg_question_time'] ?? 0,
                'fastest_completion' => $performanceData['fastest_completion'] ?? null,
                'slowest_completion' => $performanceData['slowest_completion'] ?? null,
                'completion_rate' => $this->calculateCompletionRate($gameId, $dateFrom, $dateTo),
                'avg_session_duration' => $performanceData['avg_session_duration'] ?? 0,
                'bounce_rate' => $this->calculateBounceRate($gameId, $dateFrom, $dateTo),
                'device_breakdown' => $performanceData['device_breakdown'] ?? [],
                'browser_breakdown' => $performanceData['browser_breakdown'] ?? [],
                'traffic_sources' => $performanceData['traffic_sources'] ?? []
            ];

            $this->jsonSuccess([
                'performance_metrics' => $metrics,
                'date_range' => [
                    'from' => $dateFrom,
                    'to' => $dateTo
                ]
            ]);

        } catch (Exception $e) {
            $this->logError('Performance metrics failed', $e, $this->request);
            $this->jsonError('Failed to get performance metrics', 500);
        }
    }

    /**
     * Get real-time dashboard metrics
     *
     * @return void
     */
    public function getDashboardMetrics(): void
    {
        try {
            // Get real-time data (last 24 hours)
            $metrics = [
                'active_players' => $this->getActivePlayersCount(),
                'games_completed_today' => $this->getGamesCompletedToday(),
                'revenue_today' => $this->getRevenueToday(),
                'conversion_rate_today' => $this->getConversionRateToday(),
                'active_rounds' => $this->getActiveRoundsCount(),
                'avg_question_time' => $this->getAverageQuestionTime(),
                'fraud_alerts' => $this->getFraudAlerts(),
                'system_health' => $this->getSystemHealth()
            ];

            // Get trend data (compare with yesterday)
            $trends = [
                'players_trend' => $this->getPlayersTrend(),
                'revenue_trend' => $this->getRevenueTrend(),
                'conversion_trend' => $this->getConversionTrend()
            ];

            $this->jsonSuccess([
                'metrics' => $metrics,
                'trends' => $trends,
                'last_updated' => date('Y-m-d H:i:s')
            ]);

        } catch (Exception $e) {
            $this->logError('Dashboard metrics failed', $e);
            $this->jsonError('Failed to get dashboard metrics', 500);
        }
    }

    /**
     * Track fraud event
     *
     * @return void
     */
    public function trackFraudEvent(): void
    {
        try {
            // Validate required fields
            $requiredFields = ['participant_id', 'fraud_type', 'confidence_score'];
            foreach ($requiredFields as $field) {
                if (empty($this->request[$field])) {
                    $this->jsonError("Missing required field: {$field}", 400);
                    return;
                }
            }

            $fraudData = [
                'event_type' => 'fraud_detected',
                'participant_id' => (int)$this->request['participant_id'],
                'fraud_type' => $this->request['fraud_type'],
                'confidence_score' => floatval($this->request['confidence_score']),
                'detection_method' => $this->request['detection_method'] ?? 'automated',
                'additional_data' => json_encode($this->request['additional_data'] ?? []),
                'timestamp' => date('Y-m-d H:i:s')
            ];

            // Log fraud event
            $eventId = $this->analyticsModel->logFraudEvent($fraudData);

            // Trigger alerts if high confidence
            if ($fraudData['confidence_score'] >= 0.8) {
                $this->triggerFraudAlert($fraudData);
            }

            $this->jsonSuccess([
                'event_id' => $eventId,
                'message' => 'Fraud event logged successfully'
            ]);

        } catch (Exception $e) {
            $this->logError('Fraud tracking failed', $e, $this->request);
            $this->jsonError('Failed to track fraud event', 500);
        }
    }

    /**
     * Export analytics data
     *
     * @return void
     */
    public function exportData(): void
    {
        try {
            // Validate admin access
            if (!$this->isAdmin()) {
                $this->jsonError('Admin access required', 403);
                return;
            }

            $exportType = $this->request['type'] ?? 'events';
            $format = $this->request['format'] ?? 'csv';
            $dateFrom = $this->request['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
            $dateTo = $this->request['date_to'] ?? date('Y-m-d');

            // Generate export data
            $data = $this->analyticsModel->exportData($exportType, $dateFrom, $dateTo);

            // Format data
            if ($format === 'csv') {
                $this->outputCSV($data, $exportType);
            } else {
                $this->jsonSuccess([
                    'data' => $data,
                    'export_type' => $exportType,
                    'format' => $format,
                    'record_count' => count($data)
                ]);
            }

        } catch (Exception $e) {
            $this->logError('Data export failed', $e, $this->request);
            $this->jsonError('Failed to export data', 500);
        }
    }

    /**
     * Update funnel tracking for participant
     *
     * @param array $eventData Event data
     * @return void
     */
    private function updateFunnelTracking(array $eventData): void
    {
        if (empty($eventData['participant_id'])) {
            return;
        }

        $funnelData = [
            'participant_id' => $eventData['participant_id'],
            'stage' => $eventData['event_type'],
            'timestamp' => $eventData['timestamp'],
            'session_id' => $eventData['session_id']
        ];

        $this->analyticsModel->updateFunnelTracking($funnelData);
    }

    /**
     * Update performance metrics
     *
     * @param array $eventData Event data
     * @return void
     */
    private function updatePerformanceMetrics(array $eventData): void
    {
        // Update real-time metrics
        $metrics = [
            'game_id' => $eventData['game_id'],
            'event_type' => $eventData['event_type'],
            'timestamp' => $eventData['timestamp'],
            'response_time' => $eventData['microtime'] - $this->requestStartTime
        ];

        $this->analyticsModel->updatePerformanceMetrics($metrics);
    }

    /**
     * Calculate conversion rates for funnel data
     *
     * @param array $funnelData Raw funnel data
     * @return array Processed funnel with conversion rates
     */
    private function calculateConversionRates(array $funnelData): array
    {
        $processed = [];
        $previousCount = null;

        foreach (self::FUNNEL_STAGES as $stage => $label) {
            $count = $funnelData[$stage]['count'] ?? 0;
            $conversionRate = $previousCount ? ($count / $previousCount) * 100 : 100;

            $processed[$stage] = [
                'label' => $label,
                'count' => $count,
                'conversion_rate' => round($conversionRate, 2)
            ];

            $previousCount = $count;
        }

        return $processed;
    }

    /**
     * Calculate revenue conversion rate
     *
     * @param int|null $gameId Game ID filter
     * @param string $dateFrom Start date
     * @param string $dateTo End date
     * @return float Conversion rate percentage
     */
    private function calculateRevenueConversionRate(?int $gameId, string $dateFrom, string $dateTo): float
    {
        $visitors = $this->analyticsModel->getVisitorCount($gameId, $dateFrom, $dateTo);
        $buyers = $this->analyticsModel->getBuyerCount($gameId, $dateFrom, $dateTo);

        return $visitors > 0 ? round(($buyers / $visitors) * 100, 2) : 0;
    }

    /**
     * Calculate completion rate
     *
     * @param int|null $gameId Game ID filter
     * @param string $dateFrom Start date
     * @param string $dateTo End date
     * @return float Completion rate percentage
     */
    private function calculateCompletionRate(?int $gameId, string $dateFrom, string $dateTo): float
    {
        $started = $this->analyticsModel->getGamesStartedCount($gameId, $dateFrom, $dateTo);
        $completed = $this->analyticsModel->getGamesCompletedCount($gameId, $dateFrom, $dateTo);

        return $started > 0 ? round(($completed / $started) * 100, 2) : 0;
    }

    /**
     * Calculate bounce rate
     *
     * @param int|null $gameId Game ID filter
     * @param string $dateFrom Start date
     * @param string $dateTo End date
     * @return float Bounce rate percentage
     */
    private function calculateBounceRate(?int $gameId, string $dateFrom, string $dateTo): float
    {
        $visitors = $this->analyticsModel->getVisitorCount($gameId, $dateFrom, $dateTo);
        $bounces = $this->analyticsModel->getBounceCount($gameId, $dateFrom, $dateTo);

        return $visitors > 0 ? round(($bounces / $visitors) * 100, 2) : 0;
    }

    /**
     * Get active players count (last 15 minutes)
     *
     * @return int Active players count
     */
    private function getActivePlayersCount(): int
    {
        return $this->analyticsModel->getActivePlayersCount(15);
    }

    /**
     * Get games completed today
     *
     * @return int Games completed count
     */
    private function getGamesCompletedToday(): int
    {
        return $this->analyticsModel->getGamesCompletedCount(null, date('Y-m-d'), date('Y-m-d'));
    }

    /**
     * Get revenue today
     *
     * @return float Revenue amount
     */
    private function getRevenueToday(): float
    {
        $revenue = $this->analyticsModel->getRevenueByDate(date('Y-m-d'));
        return $revenue['total_usd'] ?? 0;
    }

    /**
     * Get conversion rate today
     *
     * @return float Conversion rate percentage
     */
    private function getConversionRateToday(): float
    {
        return $this->calculateRevenueConversionRate(null, date('Y-m-d'), date('Y-m-d'));
    }

    /**
     * Get active rounds count
     *
     * @return int Active rounds count
     */
    private function getActiveRoundsCount(): int
    {
        return $this->analyticsModel->getActiveRoundsCount();
    }

    /**
     * Get average question time (today)
     *
     * @return float Average time in seconds
     */
    private function getAverageQuestionTime(): float
    {
        return $this->analyticsModel->getAverageQuestionTime(date('Y-m-d'));
    }

    /**
     * Get fraud alerts (last 24 hours)
     *
     * @return array Fraud alerts
     */
    private function getFraudAlerts(): array
    {
        return $this->analyticsModel->getFraudAlerts(24);
    }

    /**
     * Get system health metrics
     *
     * @return array System health status
     */
    private function getSystemHealth(): array
    {
        return [
            'database' => $this->checkDatabaseHealth(),
            'queue' => $this->checkQueueHealth(),
            'payment_api' => $this->checkPaymentApiHealth()
        ];
    }

    /**
     * Get players trend (today vs yesterday)
     *
     * @return float Percentage change
     */
    private function getPlayersTrend(): float
    {
        $today = $this->analyticsModel->getVisitorCount(null, date('Y-m-d'), date('Y-m-d'));
        $yesterday = $this->analyticsModel->getVisitorCount(null, date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day')));

        return $yesterday > 0 ? round((($today - $yesterday) / $yesterday) * 100, 1) : 0;
    }

    /**
     * Get revenue trend (today vs yesterday)
     *
     * @return float Percentage change
     */
    private function getRevenueTrend(): float
    {
        $today = $this->getRevenueToday();
        $yesterday = $this->analyticsModel->getRevenueByDate(date('Y-m-d', strtotime('-1 day')))['total_usd'] ?? 0;

        return $yesterday > 0 ? round((($today - $yesterday) / $yesterday) * 100, 1) : 0;
    }

    /**
     * Get conversion trend (today vs yesterday)
     *
     * @return float Percentage point change
     */
    private function getConversionTrend(): float
    {
        $today = $this->getConversionRateToday();
        $yesterday = $this->calculateRevenueConversionRate(null, date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day')));

        return round($today - $yesterday, 1);
    }

    /**
     * Get client information for tracking
     *
     * @return array Client info
     */
    private function getClientInfo(): array
    {
        return [
            'ip_address' => $this->getClientIpAddress(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'device_type' => $this->detectDeviceType(),
            'browser' => $this->detectBrowser()
        ];
    }

    /**
     * Convert amount to USD for standardized reporting
     *
     * @param float $amount Original amount
     * @param string $currency Original currency
     * @return float Amount in USD
     */
    private function convertToUSD(float $amount, string $currency): float
    {
        if ($currency === 'USD') {
            return $amount;
        }

        // Get exchange rate from database or API
        $exchangeRate = $this->analyticsModel->getExchangeRate($currency, 'USD');
        return $amount * $exchangeRate;
    }

    /**
     * Detect device type from user agent
     *
     * @return string Device type
     */
    private function detectDeviceType(): string
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if (preg_match('/mobile|android|iphone|ipod/i', $userAgent)) {
            return 'mobile';
        } elseif (preg_match('/tablet|ipad/i', $userAgent)) {
            return 'tablet';
        } else {
            return 'desktop';
        }
    }

    /**
     * Detect browser from user agent
     *
     * @return string Browser name
     */
    private function detectBrowser(): string
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if (strpos($userAgent, 'Chrome') !== false) {
            return 'Chrome';
        } elseif (strpos($userAgent, 'Firefox') !== false) {
            return 'Firefox';
        } elseif (strpos($userAgent, 'Safari') !== false) {
            return 'Safari';
        } elseif (strpos($userAgent, 'Edge') !== false) {
            return 'Edge';
        } else {
            return 'Other';
        }
    }

    /**
     * Trigger fraud alert
     *
     * @param array $fraudData Fraud event data
     * @return void
     */
    private function triggerFraudAlert(array $fraudData): void
    {
        // Queue fraud alert email/notification
        $alertData = [
            'type' => 'fraud_alert',
            'participant_id' => $fraudData['participant_id'],
            'fraud_type' => $fraudData['fraud_type'],
            'confidence_score' => $fraudData['confidence_score'],
            'timestamp' => $fraudData['timestamp']
        ];

        // Add to alert queue (would integrate with email/notification system)
        $this->analyticsModel->queueAlert($alertData);
    }

    /**
     * Check database health
     *
     * @return array Health status
     */
    private function checkDatabaseHealth(): array
    {
        try {
            $db = Database::getInstance();
            $start = microtime(true);
            $db->query("SELECT 1");
            $responseTime = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => 'healthy',
                'response_time_ms' => $responseTime
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check queue health
     *
     * @return array Health status
     */
    private function checkQueueHealth(): array
    {
        try {
            $queueSize = $this->analyticsModel->getQueueSize();
            $failedJobs = $this->analyticsModel->getFailedJobsCount();

            return [
                'status' => $queueSize > 1000 ? 'warning' : 'healthy',
                'queue_size' => $queueSize,
                'failed_jobs' => $failedJobs
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check payment API health
     *
     * @return array Health status
     */
    private function checkPaymentApiHealth(): array
    {
        try {
            // Test Mollie API connection
            $start = microtime(true);
            // Placeholder for actual API health check
            $responseTime = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => 'healthy',
                'response_time_ms' => $responseTime
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Output data as CSV file
     *
     * @param array $data Data to export
     * @param string $type Export type
     * @return void
     */
    private function outputCSV(array $data, string $type): void
    {
        $filename = "analytics_{$type}_" . date('Y-m-d_H-i-s') . '.csv';

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');

        $output = fopen('php://output', 'w');

        // Write CSV headers
        if (!empty($data)) {
            fputcsv($output, array_keys($data[0]));

            // Write data rows
            foreach ($data as $row) {
                fputcsv($output, $row);
            }
        }

        fclose($output);
        exit;
    }

    /**
     * Check if current user is admin
     *
     * @return bool True if admin
     */
    private function isAdmin(): bool
    {
        return isset($_SESSION['admin_user_id']) && !empty($_SESSION['admin_user_id']);
    }

    /**
     * Get client IP address with proxy support
     *
     * @return string Client IP address
     */
    private function getClientIpAddress(): string
    {
        $ipKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];

        foreach ($ipKeys as $key) {
            if (array_key_exists($key, $_SERVER) && !empty($_SERVER[$key])) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return $ip;
                    }
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Validate and sanitize analytics request data
     *
     * @param array $data Raw request data
     * @return array Sanitized data
     */
    private function sanitizeAnalyticsData(array $data): array
    {
        $sanitized = [];

        // Sanitize common fields
        if (isset($data['event_type'])) {
            $sanitized['event_type'] = Security::sanitizeString($data['event_type'], 50);
        }

        if (isset($data['game_id'])) {
            $sanitized['game_id'] = Security::sanitizeInteger($data['game_id']);
        }

        if (isset($data['participant_id'])) {
            $sanitized['participant_id'] = Security::sanitizeInteger($data['participant_id']);
        }

        if (isset($data['round_id'])) {
            $sanitized['round_id'] = Security::sanitizeInteger($data['round_id']);
        }

        if (isset($data['currency'])) {
            $sanitized['currency'] = strtoupper(Security::sanitizeString($data['currency'], 3));
        }

        if (isset($data['amount'])) {
            $sanitized['amount'] = Security::sanitizeFloat($data['amount']);
        }

        if (isset($data['page_url'])) {
            $sanitized['page_url'] = Security::sanitizeUrl($data['page_url']);
        }

        if (isset($data['referrer'])) {
            $sanitized['referrer'] = Security::sanitizeUrl($data['referrer']);
        }

        // Handle event_data JSON
        if (isset($data['event_data']) && is_array($data['event_data'])) {
            $sanitized['event_data'] = $this->sanitizeEventData($data['event_data']);
        }

        return $sanitized;
    }

    /**
     * Sanitize event data object
     *
     * @param array $eventData Raw event data
     * @return array Sanitized event data
     */
    private function sanitizeEventData(array $eventData): array
    {
        $sanitized = [];

        foreach ($eventData as $key => $value) {
            $cleanKey = Security::sanitizeString($key, 50);

            if (is_string($value)) {
                $sanitized[$cleanKey] = Security::sanitizeString($value, 1000);
            } elseif (is_numeric($value)) {
                $sanitized[$cleanKey] = is_float($value) ?
                    Security::sanitizeFloat($value) :
                    Security::sanitizeInteger($value);
            } elseif (is_bool($value)) {
                $sanitized[$cleanKey] = $value;
            } elseif (is_array($value)) {
                // Recursively sanitize nested arrays (limit depth)
                $sanitized[$cleanKey] = $this->sanitizeEventData($value);
            }
        }

        return $sanitized;
    }

    /**
     * Log analytics error with context
     *
     * @param string $message Error message
     * @param Exception $exception Exception object
     * @param array $context Additional context
     * @return void
     */
    private function logError(string $message, Exception $exception, array $context = []): void
    {
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'message' => $message,
            'exception' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'context' => $context,
            'request_id' => $this->request['request_id'] ?? uniqid(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'ip_address' => $this->getClientIpAddress()
        ];

        // Log to analytics error log
        error_log(json_encode($logData), 3, '/var/log/winabrandnew/analytics_errors.log');
    }

    /**
     * Get event tracking JavaScript code for frontend
     *
     * @return void
     */
    public function getTrackingScript(): void
    {
        try {
            $gameId = $this->request['game_id'] ?? null;
            $participantId = $this->request['participant_id'] ?? null;

            if (!$gameId) {
                $this->jsonError('Game ID required', 400);
                return;
            }

            // Generate tracking configuration
            $config = [
                'apiEndpoint' => '/api/analytics/track',
                'gameId' => $gameId,
                'participantId' => $participantId,
                'sessionId' => session_id(),
                'autoTrack' => [
                    'pageViews' => true,
                    'clicks' => true,
                    'formSubmissions' => true,
                    'timeOnPage' => true
                ]
            ];

            $this->jsonSuccess([
                'tracking_config' => $config,
                'javascript_snippet' => $this->generateTrackingJavaScript($config)
            ]);

        } catch (Exception $e) {
            $this->logError('Tracking script generation failed', $e, $this->request);
            $this->jsonError('Failed to generate tracking script', 500);
        }
    }

    /**
     * Generate JavaScript tracking code
     *
     * @param array $config Tracking configuration
     * @return string JavaScript code
     */
    private function generateTrackingJavaScript(array $config): string
    {
        $configJson = json_encode($config);

        return "
        (function() {
            window.WinAnalytics = {
                config: {$configJson},

                track: function(eventType, eventData) {
                    var data = {
                        event_type: eventType,
                        game_id: this.config.gameId,
                        participant_id: this.config.participantId,
                        session_id: this.config.sessionId,
                        page_url: window.location.href,
                        referrer: document.referrer,
                        event_data: eventData || {},
                        timestamp: new Date().toISOString()
                    };

                    fetch(this.config.apiEndpoint, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify(data)
                    }).catch(function(error) {
                        console.warn('Analytics tracking failed:', error);
                    });
                },

                init: function() {
                    var self = this;

                    // Auto-track page view
                    if (this.config.autoTrack.pageViews) {
                        this.track('page_view', {
                            title: document.title,
                            url: window.location.href
                        });
                    }

                    // Auto-track clicks
                    if (this.config.autoTrack.clicks) {
                        document.addEventListener('click', function(e) {
                            var target = e.target;
                            if (target.tagName === 'A' || target.tagName === 'BUTTON' || target.getAttribute('data-track')) {
                                self.track('click', {
                                    element: target.tagName,
                                    text: target.textContent.trim(),
                                    href: target.href || null,
                                    class: target.className,
                                    id: target.id
                                });
                            }
                        });
                    }

                    // Auto-track form submissions
                    if (this.config.autoTrack.formSubmissions) {
                        document.addEventListener('submit', function(e) {
                            var form = e.target;
                            self.track('form_submit', {
                                form_id: form.id,
                                form_action: form.action,
                                form_method: form.method
                            });
                        });
                    }

                    // Track time on page
                    if (this.config.autoTrack.timeOnPage) {
                        var startTime = Date.now();
                        window.addEventListener('beforeunload', function() {
                            var timeOnPage = Date.now() - startTime;
                            self.track('time_on_page', {
                                duration_ms: timeOnPage,
                                duration_seconds: Math.round(timeOnPage / 1000)
                            });
                        });
                    }
                }
            };

            // Initialize analytics
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    window.WinAnalytics.init();
                });
            } else {
                window.WinAnalytics.init();
            }
        })();
        ";
    }

    /**
     * Bulk import analytics events (for data migration or batch processing)
     *
     * @return void
     */
    public function bulkImport(): void
    {
        try {
            // Validate admin access
            if (!$this->isAdmin()) {
                $this->jsonError('Admin access required', 403);
                return;
            }

            $events = $this->request['events'] ?? [];

            if (empty($events) || !is_array($events)) {
                $this->jsonError('Events array required', 400);
                return;
            }

            if (count($events) > 1000) {
                $this->jsonError('Maximum 1000 events per batch', 400);
                return;
            }

            $imported = 0;
            $errors = [];

            foreach ($events as $index => $eventData) {
                try {
                    // Validate and sanitize event data
                    $sanitizedData = $this->sanitizeAnalyticsData($eventData);

                    // Import event
                    $this->analyticsModel->logEvent($sanitizedData);
                    $imported++;

                } catch (Exception $e) {
                    $errors[] = [
                        'index' => $index,
                        'error' => $e->getMessage(),
                        'data' => $eventData
                    ];
                }
            }

            $this->jsonSuccess([
                'imported_count' => $imported,
                'total_count' => count($events),
                'error_count' => count($errors),
                'errors' => $errors
            ]);

        } catch (Exception $e) {
            $this->logError('Bulk import failed', $e, $this->request);
            $this->jsonError('Failed to import events', 500);
        }
    }

    /**
     * Get analytics summary for a specific time period
     *
     * @return void
     */
    public function getSummary(): void
    {
        try {
            $period = $this->request['period'] ?? 'today'; // today, week, month, year
            $gameId = $this->request['game_id'] ?? null;

            $dateRange = $this->getDateRangeForPeriod($period);

            $summary = [
                'period' => $period,
                'date_range' => $dateRange,
                'metrics' => [
                    'total_visitors' => $this->analyticsModel->getVisitorCount($gameId, $dateRange['from'], $dateRange['to']),
                    'total_players' => $this->analyticsModel->getPlayerCount($gameId, $dateRange['from'], $dateRange['to']),
                    'total_revenue' => $this->analyticsModel->getTotalRevenue($gameId, $dateRange['from'], $dateRange['to']),
                    'conversion_rate' => $this->calculateRevenueConversionRate($gameId, $dateRange['from'], $dateRange['to']),
                    'avg_session_duration' => $this->analyticsModel->getAverageSessionDuration($gameId, $dateRange['from'], $dateRange['to']),
                    'bounce_rate' => $this->calculateBounceRate($gameId, $dateRange['from'], $dateRange['to'])
                ],
                'top_events' => $this->analyticsModel->getTopEvents($gameId, $dateRange['from'], $dateRange['to'], 10),
                'device_breakdown' => $this->analyticsModel->getDeviceBreakdown($gameId, $dateRange['from'], $dateRange['to']),
                'traffic_sources' => $this->analyticsModel->getTrafficSources($gameId, $dateRange['from'], $dateRange['to'])
            ];

            $this->jsonSuccess($summary);

        } catch (Exception $e) {
            $this->logError('Analytics summary failed', $e, $this->request);
            $this->jsonError('Failed to get analytics summary', 500);
        }
    }

    /**
     * Get date range for predefined periods
     *
     * @param string $period Period identifier
     * @return array Date range with 'from' and 'to' keys
     */
    private function getDateRangeForPeriod(string $period): array
    {
        switch ($period) {
            case 'today':
                return [
                    'from' => date('Y-m-d'),
                    'to' => date('Y-m-d')
                ];
            case 'yesterday':
                return [
                    'from' => date('Y-m-d', strtotime('-1 day')),
                    'to' => date('Y-m-d', strtotime('-1 day'))
                ];
            case 'week':
                return [
                    'from' => date('Y-m-d', strtotime('-7 days')),
                    'to' => date('Y-m-d')
                ];
            case 'month':
                return [
                    'from' => date('Y-m-d', strtotime('-30 days')),
                    'to' => date('Y-m-d')
                ];
            case 'year':
                return [
                    'from' => date('Y-m-d', strtotime('-365 days')),
                    'to' => date('Y-m-d')
                ];
            default:
                return [
                    'from' => date('Y-m-d'),
                    'to' => date('Y-m-d')
                ];
        }
    }
}
