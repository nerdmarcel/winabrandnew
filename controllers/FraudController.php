<?php
/**
 * File: /controllers/FraudController.php
 * Fraud Detection Controller
 *
 * Handles fraud detection and prevention through device fingerprinting,
 * answer time analysis, IP tracking, daily participation limits, and fraud scoring.
 *
 * @package WinABrandNew
 * @version 1.0.0
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../models/Database.php';
require_once __DIR__ . '/../models/Participant.php';
require_once __DIR__ . '/../config/Config.php';

class FraudController extends BaseController
{
    private Database $db;
    private Participant $participantModel;

    // Fraud scoring thresholds
    private const FRAUD_SCORE_THRESHOLD = 75;
    private const HIGH_RISK_SCORE = 50;
    private const ANSWER_TIME_MIN_SUSPICIOUS = 0.5; // seconds
    private const ANSWER_TIME_MAX_SUSPICIOUS = 15.0; // seconds
    private const DAILY_PARTICIPATION_LIMIT = 5;
    private const IP_DAILY_LIMIT = 10;

    public function __construct()
    {
        parent::__construct();
        $this->db = Database::getInstance();
        $this->participantModel = new Participant();
    }

    /**
     * Generate device fingerprint for fraud detection
     *
     * @param array $deviceData Client device information
     * @return string Device fingerprint hash
     */
    public function generateDeviceFingerprint(array $deviceData): string
    {
        try {
            // Extract relevant device characteristics
            $fingerprintData = [
                'user_agent' => $deviceData['user_agent'] ?? '',
                'screen_resolution' => $deviceData['screen_resolution'] ?? '',
                'timezone' => $deviceData['timezone'] ?? '',
                'language' => $deviceData['language'] ?? '',
                'platform' => $deviceData['platform'] ?? '',
                'plugins' => $deviceData['plugins'] ?? '',
                'canvas_fingerprint' => $deviceData['canvas_fingerprint'] ?? '',
                'webgl_vendor' => $deviceData['webgl_vendor'] ?? '',
                'webgl_renderer' => $deviceData['webgl_renderer'] ?? '',
                'touch_support' => $deviceData['touch_support'] ?? false,
                'cpu_cores' => $deviceData['cpu_cores'] ?? 0,
                'memory' => $deviceData['memory'] ?? 0
            ];

            // Create unique fingerprint
            $fingerprintString = json_encode($fingerprintData);
            $fingerprint = hash('sha256', $fingerprintString);

            // Store fingerprint data
            $this->storeDeviceFingerprint($fingerprint, $fingerprintData);

            return $fingerprint;

        } catch (Exception $e) {
            error_log("Device fingerprinting error: " . $e->getMessage());
            return hash('sha256', json_encode(['fallback' => true, 'timestamp' => time()]));
        }
    }

    /**
     * Store device fingerprint in database
     *
     * @param string $fingerprint Device fingerprint hash
     * @param array $deviceData Device characteristics
     * @return bool Success status
     */
    private function storeDeviceFingerprint(string $fingerprint, array $deviceData): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO device_fingerprints
                (fingerprint, device_data, created_at, updated_at)
                VALUES (?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    usage_count = usage_count + 1,
                    updated_at = NOW()
            ");

            return $stmt->execute([
                $fingerprint,
                json_encode($deviceData)
            ]);

        } catch (Exception $e) {
            error_log("Store fingerprint error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Analyze answer times for suspicious patterns
     *
     * @param int $participantId Participant ID
     * @param array $answerTimes Array of answer times in seconds
     * @return array Analysis results
     */
    public function analyzeAnswerTimes(int $participantId, array $answerTimes): array
    {
        try {
            $analysis = [
                'suspicious' => false,
                'score' => 0,
                'patterns' => [],
                'total_questions' => count($answerTimes)
            ];

            if (empty($answerTimes)) {
                return $analysis;
            }

            // Calculate statistics
            $avgTime = array_sum($answerTimes) / count($answerTimes);
            $minTime = min($answerTimes);
            $maxTime = max($answerTimes);

            // Check for extremely fast answers (bot-like)
            $fastAnswers = array_filter($answerTimes, fn($time) => $time < self::ANSWER_TIME_MIN_SUSPICIOUS);
            if (count($fastAnswers) > 0) {
                $analysis['patterns'][] = 'extremely_fast_answers';
                $analysis['score'] += count($fastAnswers) * 15;
            }

            // Check for extremely slow answers (potential cheating)
            $slowAnswers = array_filter($answerTimes, fn($time) => $time > self::ANSWER_TIME_MAX_SUSPICIOUS);
            if (count($slowAnswers) > 0) {
                $analysis['patterns'][] = 'extremely_slow_answers';
                $analysis['score'] += count($slowAnswers) * 10;
            }

            // Check for consistent timing (bot pattern)
            $standardDeviation = $this->calculateStandardDeviation($answerTimes);
            if ($standardDeviation < 0.2 && count($answerTimes) > 3) {
                $analysis['patterns'][] = 'consistent_timing_pattern';
                $analysis['score'] += 20;
            }

            // Check for arithmetic progression pattern
            if ($this->detectArithmeticProgression($answerTimes)) {
                $analysis['patterns'][] = 'arithmetic_progression';
                $analysis['score'] += 25;
            }

            // Store analysis results
            $this->storeAnswerTimeAnalysis($participantId, $analysis);

            $analysis['suspicious'] = $analysis['score'] > self::HIGH_RISK_SCORE;

            return $analysis;

        } catch (Exception $e) {
            error_log("Answer time analysis error: " . $e->getMessage());
            return ['suspicious' => false, 'score' => 0, 'patterns' => [], 'error' => true];
        }
    }

    /**
     * Calculate standard deviation of answer times
     *
     * @param array $values Array of numeric values
     * @return float Standard deviation
     */
    private function calculateStandardDeviation(array $values): float
    {
        $count = count($values);
        if ($count < 2) return 0;

        $mean = array_sum($values) / $count;
        $variance = array_sum(array_map(fn($x) => pow($x - $mean, 2), $values)) / $count;

        return sqrt($variance);
    }

    /**
     * Detect arithmetic progression in answer times
     *
     * @param array $times Answer times
     * @return bool Whether arithmetic progression detected
     */
    private function detectArithmeticProgression(array $times): bool
    {
        if (count($times) < 3) return false;

        $differences = [];
        for ($i = 1; $i < count($times); $i++) {
            $differences[] = $times[$i] - $times[$i - 1];
        }

        // Check if differences are consistent (within 10% tolerance)
        $avgDiff = array_sum($differences) / count($differences);
        $tolerance = abs($avgDiff) * 0.1;

        foreach ($differences as $diff) {
            if (abs($diff - $avgDiff) > $tolerance) {
                return false;
            }
        }

        return true;
    }

    /**
     * Track IP address usage and detect suspicious activity
     *
     * @param string $ipAddress Client IP address
     * @param int $participantId Participant ID
     * @return array IP tracking results
     */
    public function trackIPAddress(string $ipAddress, int $participantId): array
    {
        try {
            $tracking = [
                'blocked' => false,
                'daily_count' => 0,
                'total_count' => 0,
                'risk_score' => 0,
                'warnings' => []
            ];

            // Get today's usage count for this IP
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as daily_count
                FROM participants
                WHERE ip_address = ?
                AND DATE(created_at) = CURDATE()
            ");
            $stmt->execute([$ipAddress]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $tracking['daily_count'] = intval($result['daily_count'] ?? 0);

            // Get total usage count
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as total_count
                FROM participants
                WHERE ip_address = ?
            ");
            $stmt->execute([$ipAddress]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $tracking['total_count'] = intval($result['total_count'] ?? 0);

            // Check daily limit
            if ($tracking['daily_count'] >= self::IP_DAILY_LIMIT) {
                $tracking['blocked'] = true;
                $tracking['warnings'][] = 'daily_limit_exceeded';
                $tracking['risk_score'] += 40;
            } else if ($tracking['daily_count'] >= self::IP_DAILY_LIMIT * 0.8) {
                $tracking['warnings'][] = 'approaching_daily_limit';
                $tracking['risk_score'] += 20;
            }

            // Check for VPN/Proxy indicators
            $vpnCheck = $this->checkVPNProxy($ipAddress);
            if ($vpnCheck['is_vpn']) {
                $tracking['warnings'][] = 'vpn_detected';
                $tracking['risk_score'] += 30;
            }

            // Store IP tracking data
            $this->storeIPTracking($ipAddress, $participantId, $tracking);

            return $tracking;

        } catch (Exception $e) {
            error_log("IP tracking error: " . $e->getMessage());
            return ['blocked' => false, 'daily_count' => 0, 'total_count' => 0, 'risk_score' => 0, 'error' => true];
        }
    }

    /**
     * Check for VPN/Proxy usage
     *
     * @param string $ipAddress IP address to check
     * @return array VPN/Proxy detection results
     */
    private function checkVPNProxy(string $ipAddress): array
    {
        try {
            // Basic check for common VPN/Proxy ranges
            $vpnRanges = [
                '10.0.0.0/8',
                '172.16.0.0/12',
                '192.168.0.0/16',
                '127.0.0.0/8'
            ];

            $result = [
                'is_vpn' => false,
                'is_proxy' => false,
                'confidence' => 0
            ];

            // Check against known ranges (basic implementation)
            foreach ($vpnRanges as $range) {
                if ($this->ipInRange($ipAddress, $range)) {
                    $result['is_vpn'] = true;
                    $result['confidence'] = 50;
                    break;
                }
            }

            // Additional checks could include:
            // - ASN lookup for hosting providers
            // - Blacklist checking
            // - Geolocation inconsistencies

            return $result;

        } catch (Exception $e) {
            error_log("VPN/Proxy check error: " . $e->getMessage());
            return ['is_vpn' => false, 'is_proxy' => false, 'confidence' => 0];
        }
    }

    /**
     * Check if IP is in range
     *
     * @param string $ip IP address
     * @param string $range CIDR range
     * @return bool Whether IP is in range
     */
    private function ipInRange(string $ip, string $range): bool
    {
        list($subnet, $bits) = explode('/', $range);
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - $bits);
        $subnet &= $mask;
        return ($ip & $mask) == $subnet;
    }

    /**
     * Check daily participation limits
     *
     * @param int $participantId Participant ID
     * @param string $deviceFingerprint Device fingerprint
     * @return array Participation limit check results
     */
    public function checkDailyParticipationLimits(int $participantId, string $deviceFingerprint): array
    {
        try {
            $limits = [
                'exceeded' => false,
                'current_count' => 0,
                'limit' => self::DAILY_PARTICIPATION_LIMIT,
                'risk_score' => 0
            ];

            // Check participation by device fingerprint today
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count
                FROM participants
                WHERE device_fingerprint = ?
                AND DATE(created_at) = CURDATE()
            ");
            $stmt->execute([$deviceFingerprint]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $limits['current_count'] = intval($result['count'] ?? 0);

            if ($limits['current_count'] >= self::DAILY_PARTICIPATION_LIMIT) {
                $limits['exceeded'] = true;
                $limits['risk_score'] = 50;
            } else if ($limits['current_count'] >= self::DAILY_PARTICIPATION_LIMIT * 0.8) {
                $limits['risk_score'] = 25;
            }

            return $limits;

        } catch (Exception $e) {
            error_log("Daily participation check error: " . $e->getMessage());
            return ['exceeded' => false, 'current_count' => 0, 'limit' => self::DAILY_PARTICIPATION_LIMIT, 'error' => true];
        }
    }

    /**
     * Calculate comprehensive fraud score
     *
     * @param int $participantId Participant ID
     * @param array $fraudData Collected fraud detection data
     * @return array Fraud score results
     */
    public function calculateFraudScore(int $participantId, array $fraudData): array
    {
        try {
            $score = [
                'total_score' => 0,
                'risk_level' => 'low',
                'blocked' => false,
                'factors' => [],
                'recommendations' => []
            ];

            // Answer time analysis score
            if (isset($fraudData['answer_analysis']['score'])) {
                $score['total_score'] += $fraudData['answer_analysis']['score'];
                $score['factors']['answer_timing'] = $fraudData['answer_analysis']['score'];
            }

            // IP tracking score
            if (isset($fraudData['ip_tracking']['risk_score'])) {
                $score['total_score'] += $fraudData['ip_tracking']['risk_score'];
                $score['factors']['ip_reputation'] = $fraudData['ip_tracking']['risk_score'];
            }

            // Daily limits score
            if (isset($fraudData['daily_limits']['risk_score'])) {
                $score['total_score'] += $fraudData['daily_limits']['risk_score'];
                $score['factors']['participation_frequency'] = $fraudData['daily_limits']['risk_score'];
            }

            // Device fingerprint uniqueness
            $deviceScore = $this->calculateDeviceScore($fraudData['device_fingerprint'] ?? '');
            $score['total_score'] += $deviceScore;
            $score['factors']['device_uniqueness'] = $deviceScore;

            // Determine risk level
            if ($score['total_score'] >= self::FRAUD_SCORE_THRESHOLD) {
                $score['risk_level'] = 'high';
                $score['blocked'] = true;
                $score['recommendations'][] = 'Block participation';
            } else if ($score['total_score'] >= self::HIGH_RISK_SCORE) {
                $score['risk_level'] = 'medium';
                $score['recommendations'][] = 'Require additional verification';
            } else {
                $score['risk_level'] = 'low';
                $score['recommendations'][] = 'Allow participation';
            }

            // Store fraud score
            $this->storeFraudScore($participantId, $score);

            return $score;

        } catch (Exception $e) {
            error_log("Fraud score calculation error: " . $e->getMessage());
            return ['total_score' => 0, 'risk_level' => 'low', 'blocked' => false, 'error' => true];
        }
    }

    /**
     * Calculate device uniqueness score
     *
     * @param string $deviceFingerprint Device fingerprint
     * @return int Device score (higher = more suspicious)
     */
    private function calculateDeviceScore(string $deviceFingerprint): int
    {
        try {
            if (empty($deviceFingerprint)) {
                return 30; // Missing fingerprint is suspicious
            }

            // Check how common this device fingerprint is
            $stmt = $this->db->prepare("
                SELECT usage_count
                FROM device_fingerprints
                WHERE fingerprint = ?
            ");
            $stmt->execute([$deviceFingerprint]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            $usageCount = intval($result['usage_count'] ?? 0);

            // More usage = more suspicious
            if ($usageCount > 20) {
                return 40;
            } else if ($usageCount > 10) {
                return 25;
            } else if ($usageCount > 5) {
                return 15;
            }

            return 0; // New/unique device

        } catch (Exception $e) {
            error_log("Device score calculation error: " . $e->getMessage());
            return 20;
        }
    }

    /**
     * Store answer time analysis results
     *
     * @param int $participantId Participant ID
     * @param array $analysis Analysis results
     * @return bool Success status
     */
    private function storeAnswerTimeAnalysis(int $participantId, array $analysis): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO fraud_analysis
                (participant_id, analysis_type, analysis_data, risk_score, created_at)
                VALUES (?, 'answer_timing', ?, ?, NOW())
            ");

            return $stmt->execute([
                $participantId,
                json_encode($analysis),
                $analysis['score']
            ]);

        } catch (Exception $e) {
            error_log("Store analysis error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Store IP tracking data
     *
     * @param string $ipAddress IP address
     * @param int $participantId Participant ID
     * @param array $tracking Tracking data
     * @return bool Success status
     */
    private function storeIPTracking(string $ipAddress, int $participantId, array $tracking): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO ip_tracking
                (ip_address, participant_id, daily_count, total_count, risk_score, warnings, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");

            return $stmt->execute([
                $ipAddress,
                $participantId,
                $tracking['daily_count'],
                $tracking['total_count'],
                $tracking['risk_score'],
                json_encode($tracking['warnings'])
            ]);

        } catch (Exception $e) {
            error_log("Store IP tracking error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Store fraud score results
     *
     * @param int $participantId Participant ID
     * @param array $score Fraud score data
     * @return bool Success status
     */
    private function storeFraudScore(int $participantId, array $score): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO fraud_scores
                (participant_id, total_score, risk_level, factors, blocked, recommendations, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");

            return $stmt->execute([
                $participantId,
                $score['total_score'],
                $score['risk_level'],
                json_encode($score['factors']),
                $score['blocked'],
                json_encode($score['recommendations'])
            ]);

        } catch (Exception $e) {
            error_log("Store fraud score error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get fraud history for participant
     *
     * @param int $participantId Participant ID
     * @return array Fraud history
     */
    public function getFraudHistory(int $participantId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM fraud_scores
                WHERE participant_id = ?
                ORDER BY created_at DESC
                LIMIT 10
            ");
            $stmt->execute([$participantId]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            error_log("Get fraud history error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if participant is blocked
     *
     * @param int $participantId Participant ID
     * @return bool Whether participant is blocked
     */
    public function isParticipantBlocked(int $participantId): bool
    {
        try {
            $stmt = $this->db->prepare("
                SELECT blocked FROM fraud_scores
                WHERE participant_id = ?
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $stmt->execute([$participantId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return boolval($result['blocked'] ?? false);

        } catch (Exception $e) {
            error_log("Check blocked status error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get fraud statistics for admin dashboard
     *
     * @return array Fraud statistics
     */
    public function getFraudStatistics(): array
    {
        try {
            $stats = [
                'total_checks' => 0,
                'blocked_attempts' => 0,
                'high_risk_attempts' => 0,
                'common_patterns' => [],
                'top_risk_ips' => []
            ];

            // Total fraud checks today
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count
                FROM fraud_scores
                WHERE DATE(created_at) = CURDATE()
            ");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['total_checks'] = intval($result['count'] ?? 0);

            // Blocked attempts today
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count
                FROM fraud_scores
                WHERE blocked = 1
                AND DATE(created_at) = CURDATE()
            ");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['blocked_attempts'] = intval($result['count'] ?? 0);

            // High risk attempts today
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count
                FROM fraud_scores
                WHERE risk_level = 'high'
                AND DATE(created_at) = CURDATE()
            ");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['high_risk_attempts'] = intval($result['count'] ?? 0);

            return $stats;

        } catch (Exception $e) {
            error_log("Get fraud statistics error: " . $e->getMessage());
            return ['total_checks' => 0, 'blocked_attempts' => 0, 'high_risk_attempts' => 0];
        }
    }
}
?>
