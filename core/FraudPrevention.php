<?php
declare(strict_types=1);

/**
 * File: core/FraudPrevention.php
 * Location: core/FraudPrevention.php
 *
 * WinABN Fraud Prevention System
 *
 * Implements device fingerprinting, IP tracking, and behavioral analysis
 * to prevent fraudulent activities and ensure fair competition.
 *
 * @package WinABN\Core
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Core;

use WinABN\Core\Database;
use WinABN\Core\Security;
use Exception;

class FraudPrevention
{
    /**
     * Fraud detection thresholds
     */
    private const MAX_DAILY_PARTICIPATIONS = 5;
    private const MIN_ANSWER_TIME = 0.5; // seconds
    private const MAX_SAME_IP_PARTICIPANTS = 10;
    private const MAX_SAME_DEVICE_PARTICIPANTS = 3;
    private const SUSPICIOUS_PATTERN_THRESHOLD = 0.8;

    /**
     * Generate device fingerprint from client data
     *
     * @param array<string, mixed> $clientData Client information
     * @return string Device fingerprint hash
     */
    public static function generateDeviceFingerprint(array $clientData): string
    {
        $fingerprintData = [
            'user_agent' => $clientData['user_agent'] ?? '',
            'screen_resolution' => $clientData['screen_resolution'] ?? '',
            'timezone' => $clientData['timezone'] ?? '',
            'language' => $clientData['language'] ?? '',
            'platform' => $clientData['platform'] ?? '',
            'browser_version' => $clientData['browser_version'] ?? '',
            'canvas_fingerprint' => $clientData['canvas_fingerprint'] ?? '',
            'webgl_fingerprint' => $clientData['webgl_fingerprint'] ?? '',
            'audio_fingerprint' => $clientData['audio_fingerprint'] ?? '',
            'fonts_available' => $clientData['fonts_available'] ?? '',
            'plugins_installed' => $clientData['plugins_installed'] ?? '',
            'touch_support' => $clientData['touch_support'] ?? false,
            'cpu_cores' => $clientData['cpu_cores'] ?? 0,
            'memory_size' => $clientData['memory_size'] ?? 0
        ];

        // Create stable hash from fingerprint data
        $fingerprintString = json_encode($fingerprintData, JSON_SORT_KEYS);
        return hash('sha256', $fingerprintString);
    }

    /**
     * Analyze participant for fraud indicators
     *
     * @param array<string, mixed> $participantData Participant data
     * @return array<string, mixed> Fraud analysis result
     */
    public static function analyzeParticipant(array $participantData): array
    {
        $fraudScore = 0;
        $flags = [];
        $details = [];

        // Check IP-based fraud
        $ipAnalysis = self::analyzeIpAddress($participantData['ip_address']);
        $fraudScore += $ipAnalysis['score'];
        $flags = array_merge($flags, $ipAnalysis['flags']);
        $details['ip_analysis'] = $ipAnalysis;

        // Check device fingerprint fraud
        if (!empty($participantData['device_fingerprint'])) {
            $deviceAnalysis = self::analyzeDeviceFingerprint($participantData['device_fingerprint']);
            $fraudScore += $deviceAnalysis['score'];
            $flags = array_merge($flags, $deviceAnalysis['flags']);
            $details['device_analysis'] = $deviceAnalysis;
        }

        // Check behavioral patterns
        if (!empty($participantData['user_email'])) {
            $behaviorAnalysis = self::analyzeBehaviorPattern($participantData['user_email']);
            $fraudScore += $behaviorAnalysis['score'];
            $flags = array_merge($flags, $behaviorAnalysis['flags']);
            $details['behavior_analysis'] = $behaviorAnalysis;
        }

        // Check timing patterns (if question times provided)
        if (!empty($participantData['question_times'])) {
            $timingAnalysis = self::analyzeTimingPattern($participantData['question_times']);
            $fraudScore += $timingAnalysis['score'];
            $flags = array_merge($flags, $timingAnalysis['flags']);
            $details['timing_analysis'] = $timingAnalysis;
        }

        $riskLevel = self::calculateRiskLevel($fraudScore);

        return [
            'fraud_score' => $fraudScore,
            'risk_level' => $riskLevel,
            'is_suspicious' => $fraudScore >= self::SUSPICIOUS_PATTERN_THRESHOLD,
            'flags' => array_unique($flags),
            'details' => $details,
            'recommendation' => self::getRecommendation($riskLevel, $flags)
        ];
    }

    /**
     * Analyze IP address for fraud indicators
     *
     * @param string $ipAddress IP address to analyze
     * @return array<string, mixed>
     */
    private static function analyzeIpAddress(string $ipAddress): array
    {
        $score = 0;
        $flags = [];

        // Check for excessive participants from same IP
        $query = "
            SELECT COUNT(*) as count, COUNT(DISTINCT user_email) as unique_emails
            FROM participants
            WHERE ip_address = ?
            AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ";
        $result = Database::fetchOne($query, [$ipAddress]);

        if ($result['count'] > self::MAX_SAME_IP_PARTICIPANTS) {
            $score += 0.4;
            $flags[] = 'excessive_ip_usage';
        }

        // Check for multiple accounts from same IP
        if ($result['count'] > 0 && $result['unique_emails'] > 5) {
            $score += 0.3;
            $flags[] = 'multiple_accounts_same_ip';
        }

        // Check if IP is in security log for suspicious activity
        $securityCheck = "
            SELECT COUNT(*) as violations
            FROM security_log
            WHERE ip_address = ?
            AND event_type IN ('fraud_detection', 'rate_limit')
            AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
        ";
        $securityResult = Database::fetchOne($securityCheck, [$ipAddress]);

        if ($securityResult['violations'] > 0) {
            $score += 0.2;
            $flags[] = 'previous_security_violations';
        }

        // Check for proxy/VPN indicators (basic check)
        if (self::isProxyOrVpn($ipAddress)) {
            $score += 0.1;
            $flags[] = 'proxy_or_vpn';
        }

        return [
            'score' => $score,
            'flags' => $flags,
            'participant_count_24h' => $result['count'],
            'unique_emails_24h' => $result['unique_emails']
        ];
    }

    /**
     * Analyze device fingerprint for fraud indicators
     *
     * @param string $deviceFingerprint Device fingerprint hash
     * @return array<string, mixed>
     */
    private static function analyzeDeviceFingerprint(string $deviceFingerprint): array
    {
        $score = 0;
        $flags = [];

        // Check for excessive participants from same device
        $query = "
            SELECT COUNT(*) as count, COUNT(DISTINCT user_email) as unique_emails
            FROM participants
            WHERE device_fingerprint = ?
            AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ";
        $result = Database::fetchOne($query, [$deviceFingerprint]);

        if ($result['count'] > self::MAX_SAME_DEVICE_PARTICIPANTS) {
            $score += 0.5;
            $flags[] = 'excessive_device_usage';
        }

        // Check for multiple accounts from same device
        if ($result['count'] > 0 && $result['unique_emails'] > 2) {
            $score += 0.4;
            $flags[] = 'multiple_accounts_same_device';
        }

        // Check for device fingerprint patterns that suggest automation
        if (self::isAutomatedDevice($deviceFingerprint)) {
            $score += 0.3;
            $flags[] = 'automated_device_detected';
        }

        return [
            'score' => $score,
            'flags' => $flags,
            'participant_count_24h' => $result['count'],
            'unique_emails_24h' => $result['unique_emails']
        ];
    }

    /**
     * Analyze user behavior pattern
     *
     * @param string $userEmail User email
     * @return array<string, mixed>
     */
    private static function analyzeBehaviorPattern(string $userEmail): array
    {
        $score = 0;
        $flags = [];

        // Check daily participation frequency
        $query = "
            SELECT COUNT(*) as participations_today
            FROM participants
            WHERE user_email = ?
            AND DATE(created_at) = CURDATE()
        ";
        $result = Database::fetchOne($query, [$userEmail]);

        if ($result['participations_today'] > self::MAX_DAILY_PARTICIPATIONS) {
            $score += 0.3;
            $flags[] = 'excessive_daily_participations';
        }

        // Check for rapid consecutive participations
        $rapidQuery = "
            SELECT created_at
            FROM participants
            WHERE user_email = ?
            ORDER BY created_at DESC
            LIMIT 5
        ";
        $recentParticipations = Database::fetchAll($rapidQuery, [$userEmail]);

        if (count($recentParticipations) >= 3) {
            $timeDiffs = [];
            for ($i = 0; $i < count($recentParticipations) - 1; $i++) {
                $timeDiff = strtotime($recentParticipations[$i]['created_at']) -
                           strtotime($recentParticipations[$i + 1]['created_at']);
                $timeDiffs[] = $timeDiff;
            }

            $avgTimeDiff = array_sum($timeDiffs) / count($timeDiffs);
            if ($avgTimeDiff < 300) { // Less than 5 minutes between participations
                $score += 0.2;
                $flags[] = 'rapid_consecutive_participations';
            }
        }

        // Check win rate (suspicious if too high)
        $winRateQuery = "
            SELECT
                COUNT(*) as total_participations,
                COUNT(CASE WHEN is_winner = 1 THEN 1 END) as wins
            FROM participants
            WHERE user_email = ?
            AND payment_status = 'paid'
        ";
        $winData = Database::fetchOne($winRateQuery, [$userEmail]);

        if ($winData['total_participations'] > 5) {
            $winRate = $winData['wins'] / $winData['total_participations'];
            if ($winRate > 0.2) { // More than 20% win rate is suspicious
                $score += 0.25;
                $flags[] = 'suspicious_win_rate';
            }
        }

        return [
            'score' => $score,
            'flags' => $flags,
            'participations_today' => $result['participations_today'],
            'win_rate' => isset($winRate) ? $winRate : 0
        ];
    }

    /**
     * Analyze timing patterns for fraud indicators
     *
     * @param array<float> $questionTimes Array of question response times
     * @return array<string, mixed>
     */
    private static function analyzeTimingPattern(array $questionTimes): array
    {
        $score = 0;
        $flags = [];

        // Check for consistently fast responses (automation indicator)
        $fastResponses = array_filter($questionTimes, function($time) {
            return $time < self::MIN_ANSWER_TIME;
        });

        if (count($fastResponses) > 2) {
            $score += 0.4;
            $flags[] = 'too_many_fast_responses';
        }

        // Check for suspiciously consistent timing
        if (count($questionTimes) > 3) {
            $avgTime = array_sum($questionTimes) / count($questionTimes);
            $variance = 0;

            foreach ($questionTimes as $time) {
                $variance += pow($time - $avgTime, 2);
            }
            $variance /= count($questionTimes);
            $stdDev = sqrt($variance);

            // Very low standard deviation suggests automation
            if ($stdDev < 0.5 && $avgTime < 2.0) {
                $score += 0.3;
                $flags[] = 'suspiciously_consistent_timing';
            }
        }

        // Check for perfect timing patterns (exactly same intervals)
        $intervals = [];
        for ($i = 1; $i < count($questionTimes); $i++) {
            $intervals[] = abs($questionTimes[$i] - $questionTimes[$i-1]);
        }

        if (!empty($intervals)) {
            $uniqueIntervals = array_unique($intervals);
            if (count($uniqueIntervals) < count($intervals) * 0.5) {
                $score += 0.2;
                $flags[] = 'repetitive_timing_intervals';
            }
        }

        return [
            'score' => $score,
            'flags' => $flags,
            'fast_response_count' => count($fastResponses),
            'avg_response_time' => isset($avgTime) ? $avgTime : 0,
            'timing_variance' => isset($stdDev) ? $stdDev : 0
        ];
    }

    /**
     * Calculate risk level based on fraud score
     *
     * @param float $fraudScore Fraud score
     * @return string Risk level
     */
    private static function calculateRiskLevel(float $fraudScore): string
    {
        if ($fraudScore >= 0.8) return 'HIGH';
        if ($fraudScore >= 0.5) return 'MEDIUM';
        if ($fraudScore >= 0.2) return 'LOW';
        return 'MINIMAL';
    }

    /**
     * Get recommendation based on risk level and flags
     *
     * @param string $riskLevel Risk level
     * @param array<string> $flags Fraud flags
     * @return string Recommendation
     */
    private static function getRecommendation(string $riskLevel, array $flags): string
    {
        switch ($riskLevel) {
            case 'HIGH':
                return 'BLOCK_PARTICIPANT';
            case 'MEDIUM':
                if (in_array('excessive_device_usage', $flags) ||
                    in_array('multiple_accounts_same_device', $flags)) {
                    return 'MANUAL_REVIEW_REQUIRED';
                }
                return 'MONITOR_CLOSELY';
            case 'LOW':
                return 'MONITOR';
            default:
                return 'ALLOW';
        }
    }

    /**
     * Check if IP address is likely a proxy or VPN
     *
     * @param string $ipAddress IP address
     * @return bool
     */
    private static function isProxyOrVpn(string $ipAddress): bool
    {
        // Basic checks for common proxy/VPN indicators
        // In production, you'd use a dedicated service like IPQualityScore

        // Check common VPN/proxy port patterns
        $suspiciousRanges = [
            '10.0.0.0/8',      // Private range often used by VPNs
            '172.16.0.0/12',   // Private range
            '192.168.0.0/16'   // Private range
        ];

        foreach ($suspiciousRanges as $range) {
            if (self::ipInRange($ipAddress, $range)) {
                return true;
            }
        }

        // Check against known VPN provider ASNs (simplified)
        $vpnAsns = ['174', '13335', '16509']; // Example ASNs
        // This would require IP to ASN lookup service in production

        return false;
    }

    /**
     * Check if device fingerprint suggests automation
     *
     * @param string $deviceFingerprint Device fingerprint
     * @return bool
     */
    private static function isAutomatedDevice(string $deviceFingerprint): bool
    {
        // Check for patterns common in automated tools
        // This is a simplified check - production would be more sophisticated

        $query = "
            SELECT device_fingerprint, COUNT(*) as usage_count
            FROM participants
            WHERE device_fingerprint = ?
            GROUP BY device_fingerprint
            HAVING COUNT(*) > 20
        ";

        $result = Database::fetchOne($query, [$deviceFingerprint]);
        return $result && $result['usage_count'] > 20;
    }

    /**
     * Check if IP is in CIDR range
     *
     * @param string $ip IP address
     * @param string $range CIDR range
     * @return bool
     */
    private static function ipInRange(string $ip, string $range): bool
    {
        [$subnet, $mask] = explode('/', $range);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $maskLong = -1 << (32 - (int)$mask);
        $subnetLong &= $maskLong;

        return ($ipLong & $maskLong) === $subnetLong;
    }

    /**
     * Log security event
     *
     * @param string $ipAddress IP address
     * @param string $eventType Event type
     * @param array<string, mixed> $details Event details
     * @return void
     */
    public static function logSecurityEvent(string $ipAddress, string $eventType, array $details): void
    {
        try {
            $severity = self::getSeverityForEventType($eventType);

            $query = "
                INSERT INTO security_log
                (ip_address, event_type, details_json, severity, user_agent, request_uri)
                VALUES (?, ?, ?, ?, ?, ?)
            ";

            Database::execute($query, [
                $ipAddress,
                $eventType,
                json_encode($details),
                $severity,
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                $_SERVER['REQUEST_URI'] ?? ''
            ]);

            // Auto-block IP if too many violations
            self::checkAutoBlock($ipAddress);

        } catch (Exception $e) {
            error_log("Failed to log security event: " . $e->getMessage());
        }
    }

    /**
     * Get severity level for event type
     *
     * @param string $eventType Event type
     * @return string Severity level
     */
    private static function getSeverityForEventType(string $eventType): string
    {
        $severityMap = [
            'fraud_detection' => 'high',
            'excessive_requests' => 'medium',
            'invalid_token' => 'medium',
            'csrf_violation' => 'high',
            'rate_limit' => 'low'
        ];

        return $severityMap[$eventType] ?? 'medium';
    }

    /**
     * Check if IP should be auto-blocked
     *
     * @param string $ipAddress IP address
     * @return void
     */
    private static function checkAutoBlock(string $ipAddress): void
    {
        $query = "
            SELECT COUNT(*) as violation_count
            FROM security_log
            WHERE ip_address = ?
            AND event_type IN ('fraud_detection', 'csrf_violation')
            AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ";

        $result = Database::fetchOne($query, [$ipAddress]);

        if ($result['violation_count'] >= 5) {
            $blockUntil = date('Y-m-d H:i:s', strtotime('+24 hours'));

            $blockQuery = "
                UPDATE security_log
                SET blocked_until = ?
                WHERE ip_address = ?
                AND blocked_until IS NULL
            ";

            Database::execute($blockQuery, [$blockUntil, $ipAddress]);
        }
    }

    /**
     * Check if IP is currently blocked
     *
     * @param string $ipAddress IP address
     * @return bool
     */
    public static function isIpBlocked(string $ipAddress): bool
    {
        $query = "
            SELECT COUNT(*) as blocked_count
            FROM security_log
            WHERE ip_address = ?
            AND blocked_until > NOW()
        ";

        $result = Database::fetchOne($query, [$ipAddress]);
        return $result['blocked_count'] > 0;
    }

    /**
     * Mark participant as fraudulent
     *
     * @param int $participantId Participant ID
     * @param string $reason Fraud reason
     * @param array<string, mixed> $details Fraud details
     * @return bool Success status
     */
    public static function markParticipantFraudulent(int $participantId, string $reason, array $details = []): bool
    {
        try {
            Database::beginTransaction();

            // Update participant record
            $query = "
                UPDATE participants
                SET is_fraudulent = 1, fraud_reason = ?
                WHERE id = ?
            ";
            Database::execute($query, [$reason, $participantId]);

            // Log security event
            $participant = Database::fetchOne("SELECT * FROM participants WHERE id = ?", [$participantId]);
            if ($participant) {
                self::logSecurityEvent($participant['ip_address'], 'fraud_detection', array_merge([
                    'participant_id' => $participantId,
                    'reason' => $reason,
                    'user_email' => $participant['user_email']
                ], $details));
            }

            Database::commit();
            return true;

        } catch (Exception $e) {
            Database::rollback();
            error_log("Failed to mark participant as fraudulent: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get fraud statistics for admin dashboard
     *
     * @param int $days Number of days to analyze
     * @return array<string, mixed>
     */
    public static function getFraudStatistics(int $days = 30): array
    {
        $dateFilter = "created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";

        $stats = [];

        // Total fraud cases
        $query = "
            SELECT COUNT(*) as total_fraud_cases
            FROM participants
            WHERE is_fraudulent = 1 AND {$dateFilter}
        ";
        $stats['total_fraud_cases'] = Database::fetchColumn($query, [$days]);

        // Fraud by type
        $query = "
            SELECT fraud_reason, COUNT(*) as count
            FROM participants
            WHERE is_fraudulent = 1 AND {$dateFilter}
            GROUP BY fraud_reason
        ";
        $stats['fraud_by_reason'] = Database::fetchAll($query, [$days]);

        // Security events
        $query = "
            SELECT event_type, COUNT(*) as count
            FROM security_log
            WHERE {$dateFilter}
            GROUP BY event_type
        ";
        $stats['security_events'] = Database::fetchAll($query, [$days]);

        // Blocked IPs
        $query = "
            SELECT COUNT(DISTINCT ip_address) as blocked_ips
            FROM security_log
            WHERE blocked_until > NOW()
        ";
        $stats['currently_blocked_ips'] = Database::fetchColumn($query);

        return $stats;
    }
}
