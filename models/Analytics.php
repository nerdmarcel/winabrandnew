<?php

/**
 * Win a Brand New - Analytics Model
 * File: /models/Analytics.php
 *
 * Provides business analytics and conversion tracking functionality
 * according to the Development Specification requirements.
 *
 * Features:
 * - Event tracking for all business actions
 * - Revenue analytics and metrics
 * - Conversion funnel analysis
 * - WhatsApp replay conversion tracking
 * - Referral analytics and attribution
 * - Performance metrics and KPI calculations
 * - Revenue attribution and source tracking
 * - Custom date range reporting
 *
 * @package WinABrandNew\Models
 * @version 1.0.0
 * @author NerdMarcel Development Team
 */

namespace WinABrandNew\Models;

use WinABrandNew\Core\Database;
use Exception;

class Analytics
{
    /**
     * Event types as defined in the database schema
     */
    public const EVENT_GAME_START = 'game_start';
    public const EVENT_PAYMENT_ATTEMPT = 'payment_attempt';
    public const EVENT_PAYMENT_SUCCESS = 'payment_success';
    public const EVENT_PAYMENT_FAILURE = 'payment_failure';
    public const EVENT_GAME_COMPLETE = 'game_complete';
    public const EVENT_WINNER_SELECTED = 'winner_selected';
    public const EVENT_CLAIM_INITIATED = 'claim_initiated';
    public const EVENT_PRIZE_SHIPPED = 'prize_shipped';
    public const EVENT_WHATSAPP_SENT = 'whatsapp_sent';
    public const EVENT_EMAIL_SENT = 'email_sent';
    public const EVENT_REFERRAL_USED = 'referral_used';

    /**
     * Track an analytics event
     *
     * @param string $eventType Event type constant
     * @param array $data Event data
     * @return bool Success status
     * @throws Exception If event tracking fails
     */
    public static function trackEvent(string $eventType, array $data = []): bool
    {
        try {
            $sql = "
                INSERT INTO analytics_events (
                    event_type, participant_id, round_id, game_id,
                    revenue_amount, revenue_currency, conversion_source,
                    event_properties, session_id, ip_address, user_agent,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ";

            $params = [
                $eventType,
                $data['participant_id'] ?? null,
                $data['round_id'] ?? null,
                $data['game_id'] ?? null,
                $data['revenue_amount'] ?? null,
                $data['revenue_currency'] ?? 'GBP',
                $data['conversion_source'] ?? null,
                !empty($data['properties']) ? json_encode($data['properties']) : null,
                $data['session_id'] ?? session_id(),
                $data['ip_address'] ?? $_SERVER['REMOTE_ADDR'] ?? null,
                $data['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? null
            ];

            Database::execute($sql, $params);

            return true;

        } catch (Exception $e) {
            error_log("Analytics event tracking failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Track game start event
     *
     * @param int $gameId Game ID
     * @param int|null $roundId Round ID
     * @param array $properties Additional properties
     * @return bool Success status
     */
    public static function trackGameStart(int $gameId, ?int $roundId = null, array $properties = []): bool
    {
        return self::trackEvent(self::EVENT_GAME_START, [
            'game_id' => $gameId,
            'round_id' => $roundId,
            'properties' => $properties
        ]);
    }

    /**
     * Track payment attempt event
     *
     * @param int $participantId Participant ID
     * @param float $amount Payment amount
     * @param string $currency Payment currency
     * @param array $properties Additional properties
     * @return bool Success status
     */
    public static function trackPaymentAttempt(int $participantId, float $amount, string $currency = 'GBP', array $properties = []): bool
    {
        return self::trackEvent(self::EVENT_PAYMENT_ATTEMPT, [
            'participant_id' => $participantId,
            'revenue_amount' => $amount,
            'revenue_currency' => $currency,
            'properties' => $properties
        ]);
    }

    /**
     * Track successful payment event
     *
     * @param int $participantId Participant ID
     * @param float $amount Payment amount
     * @param string $currency Payment currency
     * @param string|null $conversionSource Source of conversion
     * @param array $properties Additional properties
     * @return bool Success status
     */
    public static function trackPaymentSuccess(int $participantId, float $amount, string $currency = 'GBP', ?string $conversionSource = null, array $properties = []): bool
    {
        return self::trackEvent(self::EVENT_PAYMENT_SUCCESS, [
            'participant_id' => $participantId,
            'revenue_amount' => $amount,
            'revenue_currency' => $currency,
            'conversion_source' => $conversionSource,
            'properties' => $properties
        ]);
    }

    /**
     * Track game completion event
     *
     * @param int $participantId Participant ID
     * @param float $completionTime Total completion time
     * @param int $correctAnswers Number of correct answers
     * @param array $properties Additional properties
     * @return bool Success status
     */
    public static function trackGameComplete(int $participantId, float $completionTime, int $correctAnswers, array $properties = []): bool
    {
        $properties['completion_time'] = $completionTime;
        $properties['correct_answers'] = $correctAnswers;

        return self::trackEvent(self::EVENT_GAME_COMPLETE, [
            'participant_id' => $participantId,
            'properties' => $properties
        ]);
    }

    /**
     * Track winner selection event
     *
     * @param int $participantId Winner participant ID
     * @param int $roundId Round ID
     * @param float $completionTime Winning completion time
     * @param array $properties Additional properties
     * @return bool Success status
     */
    public static function trackWinnerSelected(int $participantId, int $roundId, float $completionTime, array $properties = []): bool
    {
        $properties['winning_time'] = $completionTime;

        return self::trackEvent(self::EVENT_WINNER_SELECTED, [
            'participant_id' => $participantId,
            'round_id' => $roundId,
            'properties' => $properties
        ]);
    }

    /**
     * Track referral usage event
     *
     * @param int $referrerParticipantId Referrer participant ID
     * @param int $newParticipantId New participant ID
     * @param float $discountAmount Discount amount applied
     * @param array $properties Additional properties
     * @return bool Success status
     */
    public static function trackReferralUsed(int $referrerParticipantId, int $newParticipantId, float $discountAmount, array $properties = []): bool
    {
        $properties['referrer_participant_id'] = $referrerParticipantId;
        $properties['discount_amount'] = $discountAmount;

        return self::trackEvent(self::EVENT_REFERRAL_USED, [
            'participant_id' => $newParticipantId,
            'properties' => $properties
        ]);
    }

    /**
     * Get revenue analytics for a date range
     *
     * @param string $startDate Start date (Y-m-d format)
     * @param string $endDate End date (Y-m-d format)
     * @param string $groupBy Group by period (day, week, month)
     * @return array Revenue analytics data
     * @throws Exception If query fails
     */
    public static function getRevenueAnalytics(string $startDate, string $endDate, string $groupBy = 'day'): array
    {
        $dateFormat = match($groupBy) {
            'day' => '%Y-%m-%d',
            'week' => '%Y-%u',
            'month' => '%Y-%m',
            'year' => '%Y',
            default => '%Y-%m-%d'
        };

        $sql = "
            SELECT
                DATE_FORMAT(created_at, ?) as period,
                COUNT(*) as transaction_count,
                SUM(revenue_amount) as total_revenue,
                AVG(revenue_amount) as average_revenue,
                COUNT(DISTINCT participant_id) as unique_participants,
                revenue_currency
            FROM analytics_events
            WHERE event_type = ?
                AND DATE(created_at) BETWEEN ? AND ?
                AND revenue_amount IS NOT NULL
            GROUP BY period, revenue_currency
            ORDER BY period ASC
        ";

        $params = [$dateFormat, self::EVENT_PAYMENT_SUCCESS, $startDate, $endDate];
        return Database::select($sql, $params);
    }

    /**
     * Get conversion funnel analytics
     *
     * @param string $startDate Start date (Y-m-d format)
     * @param string $endDate End date (Y-m-d format)
     * @param int|null $gameId Specific game ID (optional)
     * @return array Conversion funnel data
     * @throws Exception If query fails
     */
    public static function getConversionFunnel(string $startDate, string $endDate, ?int $gameId = null): array
    {
        $gameCondition = $gameId ? 'AND game_id = ?' : '';
        $params = [$startDate, $endDate];
        if ($gameId) {
            $params[] = $gameId;
        }

        $sql = "
            SELECT
                event_type,
                COUNT(*) as event_count,
                COUNT(DISTINCT participant_id) as unique_participants,
                COUNT(DISTINCT session_id) as unique_sessions
            FROM analytics_events
            WHERE DATE(created_at) BETWEEN ? AND ?
                {$gameCondition}
                AND event_type IN (?, ?, ?, ?, ?)
            GROUP BY event_type
            ORDER BY
                CASE event_type
                    WHEN ? THEN 1
                    WHEN ? THEN 2
                    WHEN ? THEN 3
                    WHEN ? THEN 4
                    WHEN ? THEN 5
                END
        ";

        // Add event types to params for both WHERE IN and ORDER BY
        $eventTypes = [
            self::EVENT_GAME_START,
            self::EVENT_PAYMENT_ATTEMPT,
            self::EVENT_PAYMENT_SUCCESS,
            self::EVENT_GAME_COMPLETE,
            self::EVENT_WINNER_SELECTED
        ];

        $params = array_merge($params, $eventTypes, $eventTypes);

        $results = Database::select($sql, $params);

        // Calculate conversion rates
        $funnel = [];
        $previousCount = null;

        foreach ($results as $step) {
            $conversionRate = $previousCount ? ($step['unique_participants'] / $previousCount) * 100 : 100;

            $funnel[] = [
                'step' => $step['event_type'],
                'participants' => (int)$step['unique_participants'],
                'events' => (int)$step['event_count'],
                'sessions' => (int)$step['unique_sessions'],
                'conversion_rate' => round($conversionRate, 2)
            ];

            $previousCount = (int)$step['unique_participants'];
        }

        return $funnel;
    }

    /**
     * Get WhatsApp conversion analytics
     *
     * @param string $startDate Start date (Y-m-d format)
     * @param string $endDate End date (Y-m-d format)
     * @return array WhatsApp conversion data
     * @throws Exception If query fails
     */
    public static function getWhatsAppConversions(string $startDate, string $endDate): array
    {
        $sql = "
            SELECT
                DATE(ae.created_at) as date,
                COUNT(DISTINCT ae.participant_id) as whatsapp_sent,
                COUNT(DISTINCT ct.target_participant_id) as conversions,
                SUM(CASE WHEN ps.event_type = ? THEN ps.revenue_amount ELSE 0 END) as conversion_revenue
            FROM analytics_events ae
            LEFT JOIN conversion_tracking ct ON ae.participant_id = ct.source_id
                AND ct.source_type = 'whatsapp_replay'
                AND DATE(ct.converted_at) = DATE(ae.created_at)
            LEFT JOIN analytics_events ps ON ct.target_participant_id = ps.participant_id
                AND ps.event_type = ?
                AND DATE(ps.created_at) = DATE(ae.created_at)
            WHERE ae.event_type = ?
                AND DATE(ae.created_at) BETWEEN ? AND ?
            GROUP BY DATE(ae.created_at)
            ORDER BY date ASC
        ";

        $params = [
            self::EVENT_PAYMENT_SUCCESS,
            self::EVENT_PAYMENT_SUCCESS,
            self::EVENT_WHATSAPP_SENT,
            $startDate,
            $endDate
        ];

        $results = Database::select($sql, $params);

        // Calculate conversion rates and metrics
        return array_map(function($row) {
            $conversionRate = $row['whatsapp_sent'] > 0
                ? ($row['conversions'] / $row['whatsapp_sent']) * 100
                : 0;

            return [
                'date' => $row['date'],
                'whatsapp_sent' => (int)$row['whatsapp_sent'],
                'conversions' => (int)$row['conversions'],
                'conversion_rate' => round($conversionRate, 2),
                'conversion_revenue' => (float)$row['conversion_revenue'] ?? 0,
                'revenue_per_message' => $row['whatsapp_sent'] > 0
                    ? round(($row['conversion_revenue'] ?? 0) / $row['whatsapp_sent'], 2)
                    : 0
            ];
        }, $results);
    }

    /**
     * Get referral analytics
     *
     * @param string $startDate Start date (Y-m-d format)
     * @param string $endDate End date (Y-m-d format)
     * @return array Referral analytics data
     * @throws Exception If query fails
     */
    public static function getReferralAnalytics(string $startDate, string $endDate): array
    {
        $sql = "
            SELECT
                DATE(ae.created_at) as date,
                COUNT(*) as referral_uses,
                COUNT(DISTINCT ae.participant_id) as unique_referees,
                SUM(JSON_EXTRACT(ae.event_properties, '$.discount_amount')) as total_discounts,
                AVG(JSON_EXTRACT(ae.event_properties, '$.discount_amount')) as avg_discount
            FROM analytics_events ae
            WHERE ae.event_type = ?
                AND DATE(ae.created_at) BETWEEN ? AND ?
            GROUP BY DATE(ae.created_at)
            ORDER BY date ASC
        ";

        $params = [self::EVENT_REFERRAL_USED, $startDate, $endDate];

        return Database::select($sql, $params);
    }

    /**
     * Get participant behavior analytics
     *
     * @param string $startDate Start date (Y-m-d format)
     * @param string $endDate End date (Y-m-d format)
     * @return array Participant behavior data
     * @throws Exception If query fails
     */
    public static function getParticipantBehavior(string $startDate, string $endDate): array
    {
        $sql = "
            SELECT
                p.user_email,
                COUNT(DISTINCT p.round_id) as total_participations,
                SUM(CASE WHEN p.payment_status = 'paid' THEN 1 ELSE 0 END) as paid_participations,
                SUM(CASE WHEN p.game_completed = 1 THEN 1 ELSE 0 END) as completed_games,
                SUM(CASE WHEN p.is_winner = 1 THEN 1 ELSE 0 END) as wins,
                SUM(p.payment_amount) as total_revenue,
                AVG(p.total_time_all_questions) as avg_completion_time,
                AVG(p.correct_answers) as avg_correct_answers,
                MIN(p.created_at) as first_participation,
                MAX(p.created_at) as last_participation
            FROM participants p
            WHERE DATE(p.created_at) BETWEEN ? AND ?
                AND p.payment_status = 'paid'
            GROUP BY p.user_email
            HAVING total_participations > 1
            ORDER BY total_revenue DESC
            LIMIT 100
        ";

        $params = [$startDate, $endDate];

        return Database::select($sql, $params);
    }

    /**
     * Get performance metrics and KPIs
     *
     * @param string $startDate Start date (Y-m-d format)
     * @param string $endDate End date (Y-m-d format)
     * @return array Performance metrics
     * @throws Exception If query fails
     */
    public static function getPerformanceMetrics(string $startDate, string $endDate): array
    {
        // Get basic metrics
        $sql = "
            SELECT
                SUM(CASE WHEN event_type = ? THEN 1 ELSE 0 END) as total_game_starts,
                SUM(CASE WHEN event_type = ? THEN 1 ELSE 0 END) as total_payments,
                SUM(CASE WHEN event_type = ? THEN revenue_amount ELSE 0 END) as total_revenue,
                COUNT(DISTINCT participant_id) as unique_participants,
                COUNT(DISTINCT session_id) as unique_sessions
            FROM analytics_events
            WHERE DATE(created_at) BETWEEN ? AND ?
        ";

        $params = [
            self::EVENT_GAME_START,
            self::EVENT_PAYMENT_SUCCESS,
            self::EVENT_PAYMENT_SUCCESS,
            $startDate,
            $endDate
        ];

        $metrics = Database::selectOne($sql, $params);

        // Calculate derived metrics
        $metrics['conversion_rate'] = $metrics['total_game_starts'] > 0
            ? ($metrics['total_payments'] / $metrics['total_game_starts']) * 100
            : 0;

        $metrics['average_revenue_per_user'] = $metrics['unique_participants'] > 0
            ? $metrics['total_revenue'] / $metrics['unique_participants']
            : 0;

        $metrics['average_revenue_per_session'] = $metrics['unique_sessions'] > 0
            ? $metrics['total_revenue'] / $metrics['unique_sessions']
            : 0;

        // Round calculated values
        $metrics['conversion_rate'] = round($metrics['conversion_rate'], 2);
        $metrics['average_revenue_per_user'] = round($metrics['average_revenue_per_user'], 2);
        $metrics['average_revenue_per_session'] = round($metrics['average_revenue_per_session'], 2);

        return $metrics;
    }

    /**
     * Get game performance analytics
     *
     * @param string $startDate Start date (Y-m-d format)
     * @param string $endDate End date (Y-m-d format)
     * @return array Game performance data
     * @throws Exception If query fails
     */
    public static function getGamePerformance(string $startDate, string $endDate): array
    {
        $sql = "
            SELECT
                g.id as game_id,
                g.name as game_name,
                g.entry_fee,
                COUNT(DISTINCT ae.participant_id) as unique_participants,
                SUM(CASE WHEN ae.event_type = ? THEN 1 ELSE 0 END) as total_starts,
                SUM(CASE WHEN ae.event_type = ? THEN 1 ELSE 0 END) as total_payments,
                SUM(CASE WHEN ae.event_type = ? THEN ae.revenue_amount ELSE 0 END) as total_revenue,
                AVG(CASE WHEN ae.event_type = ? THEN
                    JSON_EXTRACT(ae.event_properties, '$.completion_time') ELSE NULL END) as avg_completion_time
            FROM games g
            LEFT JOIN analytics_events ae ON g.id = ae.game_id
                AND DATE(ae.created_at) BETWEEN ? AND ?
            WHERE g.status = 'active'
            GROUP BY g.id, g.name, g.entry_fee
            ORDER BY total_revenue DESC
        ";

        $params = [
            self::EVENT_GAME_START,
            self::EVENT_PAYMENT_SUCCESS,
            self::EVENT_PAYMENT_SUCCESS,
            self::EVENT_GAME_COMPLETE,
            $startDate,
            $endDate
        ];

        $results = Database::select($sql, $params);

        // Calculate additional metrics for each game
        return array_map(function($game) {
            $game['conversion_rate'] = $game['total_starts'] > 0
                ? ($game['total_payments'] / $game['total_starts']) * 100
                : 0;

            $game['revenue_per_participant'] = $game['unique_participants'] > 0
                ? $game['total_revenue'] / $game['unique_participants']
                : 0;

            $game['conversion_rate'] = round($game['conversion_rate'], 2);
            $game['revenue_per_participant'] = round($game['revenue_per_participant'], 2);
            $game['avg_completion_time'] = round($game['avg_completion_time'] ?? 0, 2);

            return $game;
        }, $results);
    }

    /**
     * Get churn analysis
     *
     * @param string $startDate Start date (Y-m-d format)
     * @param string $endDate End date (Y-m-d format)
     * @return array Churn analysis data
     * @throws Exception If query fails
     */
    public static function getChurnAnalysis(string $startDate, string $endDate): array
    {
        $sql = "
            SELECT
                CASE
                    WHEN DATEDIFF(NOW(), MAX(p.created_at)) <= 7 THEN 'active_week'
                    WHEN DATEDIFF(NOW(), MAX(p.created_at)) <= 30 THEN 'active_month'
                    WHEN DATEDIFF(NOW(), MAX(p.created_at)) <= 90 THEN 'dormant'
                    ELSE 'churned'
                END as user_status,
                COUNT(DISTINCT p.user_email) as user_count,
                SUM(p.payment_amount) as total_value,
                AVG(DATEDIFF(NOW(), MAX(p.created_at))) as avg_days_since_last_activity
            FROM participants p
            WHERE p.payment_status = 'paid'
                AND DATE(p.created_at) >= ?
            GROUP BY user_status
            ORDER BY
                CASE user_status
                    WHEN 'active_week' THEN 1
                    WHEN 'active_month' THEN 2
                    WHEN 'dormant' THEN 3
                    WHEN 'churned' THEN 4
                END
        ";

        $params = [$startDate];

        return Database::select($sql, $params);
    }

    /**
     * Get top performing referrers
     *
     * @param string $startDate Start date (Y-m-d format)
     * @param string $endDate End date (Y-m-d format)
     * @param int $limit Number of top referrers to return
     * @return array Top referrers data
     * @throws Exception If query fails
     */
    public static function getTopReferrers(string $startDate, string $endDate, int $limit = 10): array
    {
        $sql = "
            SELECT
                referrer.user_email as referrer_email,
                COUNT(DISTINCT referee.id) as total_referrals,
                SUM(referee.payment_amount) as total_referral_revenue,
                COUNT(DISTINCT referee.user_email) as unique_referees,
                AVG(referee.payment_amount) as avg_referral_value
            FROM participants referrer
            INNER JOIN participants referee ON referrer.id = referee.referral_participant_id
            WHERE DATE(referee.created_at) BETWEEN ? AND ?
                AND referee.payment_status = 'paid'
            GROUP BY referrer.user_email
            ORDER BY total_referral_revenue DESC
            LIMIT ?
        ";

        $params = [$startDate, $endDate, $limit];

        return Database::select($sql, $params);
    }

    /**
     * Clean up old analytics events (data retention)
     *
     * @param int $retentionDays Number of days to retain data
     * @return int Number of records deleted
     * @throws Exception If cleanup fails
     */
    public static function cleanupOldEvents(int $retentionDays = 365): int
    {
        $cutoffDate = date('Y-m-d', strtotime("-{$retentionDays} days"));

        $sql = "DELETE FROM analytics_events WHERE DATE(created_at) < ?";
        $params = [$cutoffDate];

        return Database::delete($sql, $params);
    }

    /**
     * Export analytics data to CSV format
     *
     * @param string $eventType Event type to export
     * @param string $startDate Start date (Y-m-d format)
     * @param string $endDate End date (Y-m-d format)
     * @return array CSV data rows
     * @throws Exception If export fails
     */
    public static function exportToCSV(string $eventType, string $startDate, string $endDate): array
    {
        $sql = "
            SELECT
                ae.created_at,
                ae.event_type,
                ae.participant_id,
                ae.round_id,
                ae.game_id,
                ae.revenue_amount,
                ae.revenue_currency,
                ae.conversion_source,
                ae.session_id,
                ae.ip_address
            FROM analytics_events ae
            WHERE ae.event_type = ?
                AND DATE(ae.created_at) BETWEEN ? AND ?
            ORDER BY ae.created_at ASC
        ";

        $params = [$eventType, $startDate, $endDate];

        return Database::select($sql, $params);
    }

    /**
     * Get real-time dashboard metrics
     *
     * @return array Real-time metrics
     * @throws Exception If query fails
     */
    public static function getRealTimeMetrics(): array
    {
        // Get today's metrics
        $today = date('Y-m-d');

        $sql = "
            SELECT
                SUM(CASE WHEN event_type = ? THEN 1 ELSE 0 END) as today_starts,
                SUM(CASE WHEN event_type = ? THEN 1 ELSE 0 END) as today_payments,
                SUM(CASE WHEN event_type = ? THEN revenue_amount ELSE 0 END) as today_revenue,
                COUNT(DISTINCT participant_id) as today_participants
            FROM analytics_events
            WHERE DATE(created_at) = ?
        ";

        $params = [
            self::EVENT_GAME_START,
            self::EVENT_PAYMENT_SUCCESS,
            self::EVENT_PAYMENT_SUCCESS,
            $today
        ];

        $todayMetrics = Database::selectOne($sql, $params);

        // Get active rounds count
        $activeRoundsSql = "SELECT COUNT(*) as active_rounds FROM rounds WHERE status = 'active'";
        $activeRounds = Database::selectOne($activeRoundsSql);

        return array_merge($todayMetrics, $activeRounds);
    }
}

/**
 * Analytics Helper Functions
 */

/**
 * Track analytics event helper
 *
 * @param string $eventType
 * @param array $data
 * @return bool
 */
function track_analytics_event(string $eventType, array $data = []): bool
{
    return Analytics::trackEvent($eventType, $data);
}

/**
 * Track payment success helper
 *
 * @param int $participantId
 * @param float $amount
 * @param string $currency
 * @param string|null $source
 * @return bool
 */
function track_payment_success(int $participantId, float $amount, string $currency = 'GBP', ?string $source = null): bool
{
    return Analytics::trackPaymentSuccess($participantId, $amount, $currency, $source);
}

/**
 * Track game completion helper
 *
 * @param int $participantId
 * @param float $completionTime
 * @param int $correctAnswers
 * @return bool
 */
function track_game_completion(int $participantId, float $completionTime, int $correctAnswers): bool
{
    return Analytics::trackGameComplete($participantId, $completionTime, $correctAnswers);
}

/**
 * Get performance metrics helper
 *
 * @param string $startDate
 * @param string $endDate
 * @return array
 */
function get_performance_metrics(string $startDate, string $endDate): array
{
    return Analytics::getPerformanceMetrics($startDate, $endDate);
}
