<?php
declare(strict_types=1);

/**
 * File: models/UserAction.php
 * Location: models/UserAction.php
 *
 * WinABN UserAction Model - Discount and Referral Tracking
 *
 * Manages discount actions including replay discounts, referral rewards,
 * and promotional campaigns for the WinABN platform.
 *
 * @package WinABN\Models
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Models;

use WinABN\Core\Model;
use WinABN\Core\Database;
use Exception;

class UserAction extends Model
{
    /**
     * Table name
     *
     * @var string
     */
    protected string $table = 'user_actions';

    /**
     * Discount action types
     */
    public const TYPE_REPLAY = 'replay';
    public const TYPE_REFERRAL = 'referral';
    public const TYPE_BONUS = 'bonus';
    public const TYPE_MANUAL = 'manual';

    /**
     * Default discount percentages
     */
    public const REPLAY_DISCOUNT = 10.00;
    public const REFERRAL_DISCOUNT = 10.00;

    /**
     * Create replay discount for user
     *
     * @param string $email User email
     * @param int $sourceRoundId Round that generated the discount
     * @param int|null $sourceParticipantId Source participant ID
     * @return int Created action ID
     * @throws Exception
     */
    public function createReplayDiscount(string $email, int $sourceRoundId, ?int $sourceParticipantId = null): int
    {
        // Check if user already has an active replay discount
        $existing = $this->getActiveReplayDiscount($email);
        if ($existing) {
            // Extend expiry time instead of creating new one
            return $this->extendDiscountExpiry($existing['id']);
        }

        $data = [
            'email' => $email,
            'action_type' => self::TYPE_REPLAY,
            'discount_amount' => self::REPLAY_DISCOUNT,
            'source_round_id' => $sourceRoundId,
            'source_participant_id' => $sourceParticipantId,
            'expires_at' => date('Y-m-d H:i:s', strtotime('+24 hours')),
            'is_active' => true
        ];

        return $this->create($data);
    }

    /**
     * Create referral discount for both referrer and referee
     *
     * @param string $referrerEmail Referrer email
     * @param string $refereeEmail Referee email
     * @param int|null $sourceParticipantId Source participant ID
     * @return array<string, int> Created action IDs
     * @throws Exception
     */
    public function createReferralDiscount(string $referrerEmail, string $refereeEmail, ?int $sourceParticipantId = null): array
    {
        if ($referrerEmail === $refereeEmail) {
            throw new Exception('Cannot refer yourself');
        }

        $expiresAt = date('Y-m-d H:i:s', strtotime('+6 months'));
        $createdIds = [];

        Database::beginTransaction();

        try {
            // Create discount for referee (immediate use)
            $refereeData = [
                'email' => $refereeEmail,
                'action_type' => self::TYPE_REFERRAL,
                'discount_amount' => self::REFERRAL_DISCOUNT,
                'source_participant_id' => $sourceParticipantId,
                'expires_at' => date('Y-m-d H:i:s', strtotime('+24 hours')), // Short expiry for first-time use
                'is_active' => true
            ];
            $createdIds['referee'] = $this->create($refereeData);

            // Create credit for referrer (future use)
            $referrerData = [
                'email' => $referrerEmail,
                'action_type' => self::TYPE_REFERRAL,
                'discount_amount' => self::REFERRAL_DISCOUNT,
                'source_participant_id' => $sourceParticipantId,
                'expires_at' => $expiresAt,
                'is_active' => true
            ];
            $createdIds['referrer'] = $this->create($referrerData);

            Database::commit();
            return $createdIds;

        } catch (Exception $e) {
            Database::rollback();
            throw $e;
        }
    }

    /**
     * Get best available discount for user
     * Priority: Replay > Referral > Other
     *
     * @param string $email User email
     * @return array<string, mixed>|null
     */
    public function getBestAvailableDiscount(string $email): ?array
    {
        $query = "
            SELECT * FROM {$this->table}
            WHERE email = ?
            AND is_active = 1
            AND (expires_at IS NULL OR expires_at > NOW())
            AND used_at IS NULL
            ORDER BY
                CASE action_type
                    WHEN 'replay' THEN 1
                    WHEN 'referral' THEN 2
                    WHEN 'bonus' THEN 3
                    WHEN 'manual' THEN 4
                END,
                discount_amount DESC,
                created_at ASC
            LIMIT 1
        ";

        return Database::fetchOne($query, [$email]);
    }

    /**
     * Get active replay discount for user
     *
     * @param string $email User email
     * @return array<string, mixed>|null
     */
    public function getActiveReplayDiscount(string $email): ?array
    {
        $query = "
            SELECT * FROM {$this->table}
            WHERE email = ?
            AND action_type = 'replay'
            AND is_active = 1
            AND expires_at > NOW()
            AND used_at IS NULL
            LIMIT 1
        ";

        return Database::fetchOne($query, [$email]);
    }

    /**
     * Apply discount to participant
     *
     * @param int $actionId Action ID to apply
     * @param int $participantId Participant receiving the discount
     * @return bool Success status
     * @throws Exception
     */
    public function applyDiscount(int $actionId, int $participantId): bool
    {
        $action = $this->find($actionId);
        if (!$action) {
            throw new Exception('Discount action not found');
        }

        if ($action['used_at']) {
            throw new Exception('Discount already used');
        }

        if (!$action['is_active']) {
            throw new Exception('Discount is not active');
        }

        if ($action['expires_at'] && strtotime($action['expires_at']) < time()) {
            throw new Exception('Discount has expired');
        }

        $updateData = [
            'applied_to_participant_id' => $participantId,
            'used_at' => date('Y-m-d H:i:s'),
            'is_active' => false
        ];

        return $this->update($actionId, $updateData);
    }

    /**
     * Calculate discount amount for given price
     *
     * @param float $originalPrice Original price
     * @param float $discountPercentage Discount percentage
     * @return array<string, float> Discount calculation
     */
    public function calculateDiscount(float $originalPrice, float $discountPercentage): array
    {
        $discountAmount = round($originalPrice * ($discountPercentage / 100), 2);
        $finalPrice = round($originalPrice - $discountAmount, 2);

        return [
            'original_price' => $originalPrice,
            'discount_percentage' => $discountPercentage,
            'discount_amount' => $discountAmount,
            'final_price' => $finalPrice,
            'savings' => $discountAmount
        ];
    }

    /**
     * Validate referral code from URL
     *
     * @param string $referralCode Base64 encoded email
     * @param string $currentUserEmail Current user email
     * @param string $clientIp Client IP address
     * @return array<string, mixed> Validation result
     */
    public function validateReferralCode(string $referralCode, string $currentUserEmail, string $clientIp): array
    {
        try {
            // Decode referral code
            $referrerEmail = base64_decode($referralCode);

            if (!filter_var($referrerEmail, FILTER_VALIDATE_EMAIL)) {
                return ['valid' => false, 'error' => 'Invalid referral code format'];
            }

            // Check for self-referral
            if ($referrerEmail === $currentUserEmail) {
                return ['valid' => false, 'error' => 'Cannot refer yourself'];
            }

            // Check for IP-based fraud prevention
            if ($this->checkReferralFraud($referrerEmail, $currentUserEmail, $clientIp)) {
                return ['valid' => false, 'error' => 'Referral validation failed'];
            }

            // Check if referrer exists in system
            $referrerExists = $this->checkReferrerExists($referrerEmail);
            if (!$referrerExists) {
                return ['valid' => false, 'error' => 'Invalid referrer'];
            }

            // Check if referee already used a referral
            $alreadyReferred = $this->checkAlreadyReferred($currentUserEmail);
            if ($alreadyReferred) {
                return ['valid' => false, 'error' => 'Referral already used'];
            }

            return [
                'valid' => true,
                'referrer_email' => $referrerEmail,
                'discount_amount' => self::REFERRAL_DISCOUNT
            ];

        } catch (Exception $e) {
            return ['valid' => false, 'error' => 'Referral validation error'];
        }
    }

    /**
     * Generate referral URL for user
     *
     * @param string $email User email
     * @param string $gameSlug Game slug
     * @return string Referral URL
     */
    public function generateReferralUrl(string $email, string $gameSlug): string
    {
        $referralCode = base64_encode($email);
        $baseUrl = env('APP_URL', 'https://winabn.com');

        return $baseUrl . "/win-a-{$gameSlug}?ref=" . urlencode($referralCode);
    }

    /**
     * Track referral conversion
     *
     * @param string $referrerEmail Referrer email
     * @param int $participantId New participant ID
     * @return void
     */
    public function trackReferralConversion(string $referrerEmail, int $participantId): void
    {
        $query = "
            INSERT INTO conversion_tracking
            (source_type, source_id, target_participant_id, converted_at)
            VALUES ('referral', ?, ?, NOW())
        ";

        Database::execute($query, [$referrerEmail, $participantId]);
    }

    /**
     * Get user discount history
     *
     * @param string $email User email
     * @param int $limit Number of records to return
     * @return array<array<string, mixed>>
     */
    public function getUserDiscountHistory(string $email, int $limit = 10): array
    {
        $query = "
            SELECT
                ua.*,
                p.first_name,
                p.last_name,
                p.payment_amount,
                g.name as game_name
            FROM {$this->table} ua
            LEFT JOIN participants p ON ua.applied_to_participant_id = p.id
            LEFT JOIN rounds r ON p.round_id = r.id
            LEFT JOIN games g ON r.game_id = g.id
            WHERE ua.email = ?
            ORDER BY ua.created_at DESC
            LIMIT ?
        ";

        return Database::fetchAll($query, [$email, $limit]);
    }

    /**
     * Get referral statistics for user
     *
     * @param string $email User email
     * @return array<string, mixed>
     */
    public function getReferralStats(string $email): array
    {
        $stats = [
            'total_referrals' => 0,
            'successful_conversions' => 0,
            'total_credits_earned' => 0,
            'credits_used' => 0,
            'credits_available' => 0
        ];

        // Count total referrals given
        $query = "
            SELECT COUNT(*) as total
            FROM conversion_tracking
            WHERE source_type = 'referral' AND source_id = ?
        ";
        $result = Database::fetchOne($query, [$email]);
        $stats['total_referrals'] = (int) $result['total'];

        // Count successful conversions (referred users who paid)
        $query = "
            SELECT COUNT(*) as total
            FROM conversion_tracking ct
            JOIN participants p ON ct.target_participant_id = p.id
            WHERE ct.source_type = 'referral'
            AND ct.source_id = ?
            AND p.payment_status = 'paid'
        ";
        $result = Database::fetchOne($query, [$email]);
        $stats['successful_conversions'] = (int) $result['total'];

        // Calculate credits earned and used
        $query = "
            SELECT
                COUNT(*) as total_credits,
                COUNT(CASE WHEN used_at IS NOT NULL THEN 1 END) as used_credits,
                COUNT(CASE WHEN used_at IS NULL AND expires_at > NOW() THEN 1 END) as available_credits
            FROM {$this->table}
            WHERE email = ? AND action_type = 'referral' AND is_active = 1
        ";
        $result = Database::fetchOne($query, [$email]);
        $stats['total_credits_earned'] = (int) $result['total_credits'];
        $stats['credits_used'] = (int) $result['used_credits'];
        $stats['credits_available'] = (int) $result['available_credits'];

        return $stats;
    }

    /**
     * Extend discount expiry time
     *
     * @param int $actionId Action ID
     * @param string $newExpiry New expiry time
     * @return int Action ID
     */
    private function extendDiscountExpiry(int $actionId, string $newExpiry = '+24 hours'): int
    {
        $updateData = [
            'expires_at' => date('Y-m-d H:i:s', strtotime($newExpiry))
        ];

        $this->update($actionId, $updateData);
        return $actionId;
    }

    /**
     * Check for referral fraud (IP/device fingerprinting)
     *
     * @param string $referrerEmail Referrer email
     * @param string $refereeEmail Referee email
     * @param string $clientIp Client IP
     * @return bool True if fraud detected
     */
    private function checkReferralFraud(string $referrerEmail, string $refereeEmail, string $clientIp): bool
    {
        // Check if same IP was used by referrer recently
        $query = "
            SELECT COUNT(*) as count
            FROM participants p
            JOIN rounds r ON p.round_id = r.id
            WHERE p.user_email = ?
            AND p.ip_address = ?
            AND p.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ";

        $result = Database::fetchOne($query, [$referrerEmail, $clientIp]);
        if ($result['count'] > 0) {
            return true; // Same IP used by referrer recently
        }

        // Check for excessive referrals from same IP
        $query = "
            SELECT COUNT(DISTINCT target_participant_id) as count
            FROM conversion_tracking ct
            JOIN participants p ON ct.target_participant_id = p.id
            WHERE ct.source_type = 'referral'
            AND p.ip_address = ?
            AND ct.converted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ";

        $result = Database::fetchOne($query, [$clientIp]);
        if ($result['count'] > 3) {
            return true; // Too many referrals from same IP
        }

        return false;
    }

    /**
     * Check if referrer exists in system
     *
     * @param string $email Referrer email
     * @return bool
     */
    private function checkReferrerExists(string $email): bool
    {
        $query = "
            SELECT COUNT(*) as count
            FROM participants
            WHERE user_email = ?
            AND payment_status = 'paid'
        ";

        $result = Database::fetchOne($query, [$email]);
        return $result['count'] > 0;
    }

    /**
     * Check if user already used a referral
     *
     * @param string $email User email
     * @return bool
     */
    private function checkAlreadyReferred(string $email): bool
    {
        $query = "
            SELECT COUNT(*) as count
            FROM {$this->table}
            WHERE email = ?
            AND action_type = 'referral'
            AND used_at IS NOT NULL
        ";

        $result = Database::fetchOne($query, [$email]);
        return $result['count'] > 0;
    }

    /**
     * Clean up expired discounts
     *
     * @return int Number of cleaned up records
     */
    public function cleanupExpiredDiscounts(): int
    {
        $query = "
            UPDATE {$this->table}
            SET is_active = 0
            WHERE expires_at < NOW()
            AND is_active = 1
        ";

        $statement = Database::execute($query);
        return $statement->rowCount();
    }

    /**
     * Get admin discount statistics
     *
     * @param int $days Number of days to analyze
     * @return array<string, mixed>
     */
    public function getAdminDiscountStats(int $days = 30): array
    {
        $dateFilter = "created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";

        $stats = [];

        // Total discounts created
        $query = "
            SELECT action_type, COUNT(*) as count, AVG(discount_amount) as avg_amount
            FROM {$this->table}
            WHERE {$dateFilter}
            GROUP BY action_type
        ";
        $stats['by_type'] = Database::fetchAll($query, [$days]);

        // Discount usage rate
        $query = "
            SELECT
                COUNT(*) as total_created,
                COUNT(CASE WHEN used_at IS NOT NULL THEN 1 END) as total_used,
                ROUND((COUNT(CASE WHEN used_at IS NOT NULL THEN 1 END) / COUNT(*)) * 100, 2) as usage_rate
            FROM {$this->table}
            WHERE {$dateFilter}
        ";
        $stats['usage'] = Database::fetchOne($query, [$days]);

        // Revenue impact
        $query = "
            SELECT
                COUNT(ua.id) as discounts_applied,
                SUM(p.payment_amount) as gross_revenue,
                SUM(p.payment_amount * (ua.discount_amount / 100)) as discount_amount,
                SUM(p.payment_amount * (1 - ua.discount_amount / 100)) as net_revenue
            FROM {$this->table} ua
            JOIN participants p ON ua.applied_to_participant_id = p.id
            WHERE ua.used_at IS NOT NULL
            AND ua.{$dateFilter}
        ";
        $stats['revenue_impact'] = Database::fetchOne($query, [$days]);

        return $stats;
    }
}
