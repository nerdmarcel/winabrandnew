<?php

/**
 * Win a Brand New - Discount Controller
 * File: /controllers/DiscountController.php
 *
 * Handles discount system management according to the Development Specification:
 * - Replay discount (10%) - available for 24 hours after completing same game
 * - Referral system (10% each) - new player gets 10% discount, referrer gets 10% credit
 * - Discount validation and priority logic (replay > referral)
 * - Session validity and discount rules enforcement
 * - Maximum 1 discount per transaction
 * - Not applicable to bundle actions (5-for-4 deals)
 *
 * Key Features:
 * - Discount calculation and validation
 * - Referral tracking and credit management
 * - Session-based discount storage
 * - Fraud prevention (self-referral prevention)
 * - Audit trail for all discount usage
 *
 * @package WinABrandNew\Controllers
 * @version 1.0.0
 * @author NerdMarcel Development Team
 */

namespace WinABrandNew\Controllers;

use WinABrandNew\Core\Database;
use WinABrandNew\Core\Security;
use WinABrandNew\Core\Config;
use WinABrandNew\Controllers\BaseController;
use WinABrandNew\Models\UserAction;
use WinABrandNew\Models\Participant;
use WinABrandNew\Models\Game;
use WinABrandNew\Models\Analytics;
use Exception;

class DiscountController extends BaseController
{
    /**
     * Replay discount percentage (10%)
     */
    private const REPLAY_DISCOUNT_PERCENTAGE = 10;

    /**
     * Referral discount percentage (10%)
     */
    private const REFERRAL_DISCOUNT_PERCENTAGE = 10;

    /**
     * Discount validity period in hours (24 hours)
     */
    private const DISCOUNT_VALIDITY_HOURS = 24;

    /**
     * Referral code validity period in months (6 months)
     */
    private const REFERRAL_VALIDITY_MONTHS = 6;

    /**
     * Maximum referrals per IP per day (fraud prevention)
     */
    private const MAX_REFERRALS_PER_IP_DAILY = 5;

    /**
     * Apply replay discount for completed game
     *
     * @param int $participantId Participant who completed the game
     * @param int $gameId Game that was completed
     * @return array Discount information or error
     */
    public function applyReplayDiscount(int $participantId, int $gameId): array
    {
        try {
            // Validate participant and game
            $participant = Database::selectOne(
                "SELECT * FROM participants WHERE id = ? AND game_completed = 1",
                [$participantId]
            );

            if (!$participant) {
                return $this->error("Invalid participant or game not completed");
            }

            $game = Database::selectOne("SELECT * FROM games WHERE id = ?", [$gameId]);
            if (!$game) {
                return $this->error("Game not found");
            }

            // Check if participant completed this specific game
            $completedGame = Database::selectOne(
                "SELECT r.game_id FROM participants p
                 JOIN rounds r ON p.round_id = r.id
                 WHERE p.id = ? AND r.game_id = ? AND p.game_completed = 1",
                [$participantId, $gameId]
            );

            if (!$completedGame) {
                return $this->error("Participant has not completed this game");
            }

            // Check if replay discount already exists and is still valid
            $existingDiscount = Database::selectOne(
                "SELECT * FROM user_actions
                 WHERE created_by_participant_id = ?
                 AND action_type = 'replay'
                 AND status = 'active'
                 AND expires_at > NOW()",
                [$participantId]
            );

            if ($existingDiscount) {
                return $this->success([
                    'discount_id' => $existingDiscount['id'],
                    'discount_percentage' => $existingDiscount['discount_amount'],
                    'expires_at' => $existingDiscount['expires_at'],
                    'message' => 'Replay discount already available'
                ]);
            }

            // Create new replay discount
            $expiresAt = date('Y-m-d H:i:s', time() + (self::DISCOUNT_VALIDITY_HOURS * 3600));

            $discountId = Database::insert(
                "INSERT INTO user_actions (
                    email, action_type, discount_amount, discount_currency,
                    expires_at, round_id, created_by_participant_id, status, created_at
                ) VALUES (?, 'replay', ?, ?, ?, ?, ?, 'active', NOW())",
                [
                    $participant['user_email'],
                    self::REPLAY_DISCOUNT_PERCENTAGE,
                    $game['currency'],
                    $expiresAt,
                    $participant['round_id'],
                    $participantId
                ]
            );

            // Store in session for immediate use
            $_SESSION['replay_discount'] = [
                'id' => $discountId,
                'percentage' => self::REPLAY_DISCOUNT_PERCENTAGE,
                'currency' => $game['currency'],
                'expires_at' => $expiresAt,
                'game_id' => $gameId
            ];

            // Log analytics event
            Analytics::logEvent('discount_created', $participantId, null, $gameId, [
                'discount_type' => 'replay',
                'discount_percentage' => self::REPLAY_DISCOUNT_PERCENTAGE,
                'expires_at' => $expiresAt
            ]);

            return $this->success([
                'discount_id' => $discountId,
                'discount_percentage' => self::REPLAY_DISCOUNT_PERCENTAGE,
                'expires_at' => $expiresAt,
                'message' => 'Replay discount created successfully'
            ]);

        } catch (Exception $e) {
            $this->logError("Replay discount application failed: " . $e->getMessage());
            return $this->error("Failed to apply replay discount");
        }
    }

    /**
     * Process referral and create discounts for both parties
     *
     * @param string $referralCode Base64 encoded email of referrer
     * @param string $newUserEmail Email of new user
     * @param string $ipAddress IP address for fraud prevention
     * @return array Referral processing result
     */
    public function processReferral(string $referralCode, string $newUserEmail, string $ipAddress): array
    {
        try {
            // Decode referral code
            $referrerEmail = base64_decode($referralCode);

            if (!filter_var($referrerEmail, FILTER_VALIDATE_EMAIL)) {
                return $this->error("Invalid referral code");
            }

            // Prevent self-referral
            if (strtolower($referrerEmail) === strtolower($newUserEmail)) {
                $this->logSecurityEvent("Self-referral attempt", [
                    'email' => $newUserEmail,
                    'ip' => $ipAddress
                ]);
                return $this->error("Cannot refer yourself");
            }

            // Check daily referral limit per IP (fraud prevention)
            $dailyReferrals = Database::selectOne(
                "SELECT COUNT(*) as count FROM conversion_tracking
                 WHERE source_type = 'referral'
                 AND converted_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                 AND target_participant_id IN (
                     SELECT id FROM participants WHERE ip_address = ?
                 )",
                [$ipAddress]
            );

            if ($dailyReferrals['count'] >= self::MAX_REFERRALS_PER_IP_DAILY) {
                $this->logSecurityEvent("Referral limit exceeded", [
                    'ip' => $ipAddress,
                    'daily_count' => $dailyReferrals['count']
                ]);
                return $this->error("Daily referral limit exceeded");
            }

            // Check if referrer exists and has completed at least one game
            $referrer = Database::selectOne(
                "SELECT p.* FROM participants p
                 JOIN rounds r ON p.round_id = r.id
                 WHERE p.user_email = ? AND p.game_completed = 1
                 ORDER BY p.created_at DESC LIMIT 1",
                [$referrerEmail]
            );

            if (!$referrer) {
                return $this->error("Invalid referrer or referrer has not completed any games");
            }

            // Check if new user has already been referred
            $existingReferral = Database::selectOne(
                "SELECT * FROM user_actions
                 WHERE email = ? AND action_type = 'referral' AND status IN ('active', 'used')",
                [$newUserEmail]
            );

            if ($existingReferral) {
                return $this->error("User has already been referred");
            }

            // Create discount for new user (immediate)
            $newUserExpiresAt = date('Y-m-d H:i:s', time() + (self::DISCOUNT_VALIDITY_HOURS * 3600));

            $newUserDiscountId = Database::insert(
                "INSERT INTO user_actions (
                    email, action_type, discount_amount, discount_currency,
                    expires_at, created_by_participant_id, status,
                    metadata_json, created_at
                ) VALUES (?, 'referral', ?, 'GBP', ?, ?, 'active', ?, NOW())",
                [
                    $newUserEmail,
                    self::REFERRAL_DISCOUNT_PERCENTAGE,
                    $newUserExpiresAt,
                    $referrer['id'],
                    json_encode(['referrer_email' => $referrerEmail])
                ]
            );

            // Create credit for referrer (6 months validity)
            $referrerExpiresAt = date('Y-m-d H:i:s', time() + (self::REFERRAL_VALIDITY_MONTHS * 30 * 24 * 3600));

            $referrerCreditId = Database::insert(
                "INSERT INTO user_actions (
                    email, action_type, discount_amount, discount_currency,
                    expires_at, status, metadata_json, created_at
                ) VALUES (?, 'referral', ?, 'GBP', ?, 'active', ?, NOW())",
                [
                    $referrerEmail,
                    self::REFERRAL_DISCOUNT_PERCENTAGE,
                    $referrerExpiresAt,
                    json_encode(['referred_email' => $newUserEmail])
                ]
            );

            // Store referral in session for new user
            $_SESSION['referral_discount'] = [
                'id' => $newUserDiscountId,
                'percentage' => self::REFERRAL_DISCOUNT_PERCENTAGE,
                'currency' => 'GBP',
                'expires_at' => $newUserExpiresAt,
                'referrer_email' => $referrerEmail
            ];

            // Log analytics events
            Analytics::logEvent('referral_created', null, null, null, [
                'referrer_email' => $referrerEmail,
                'new_user_email' => $newUserEmail,
                'discount_percentage' => self::REFERRAL_DISCOUNT_PERCENTAGE
            ]);

            return $this->success([
                'new_user_discount_id' => $newUserDiscountId,
                'referrer_credit_id' => $referrerCreditId,
                'discount_percentage' => self::REFERRAL_DISCOUNT_PERCENTAGE,
                'message' => 'Referral processed successfully'
            ]);

        } catch (Exception $e) {
            $this->logError("Referral processing failed: " . $e->getMessage());
            return $this->error("Failed to process referral");
        }
    }

    /**
     * Get available discounts for user
     *
     * @param string $userEmail User email
     * @param int|null $gameId Optional game ID for game-specific discounts
     * @return array Available discounts
     */
    public function getAvailableDiscounts(string $userEmail, ?int $gameId = null): array
    {
        try {
            $discounts = [];

            // Get active discounts from database
            $dbDiscounts = Database::select(
                "SELECT * FROM user_actions
                 WHERE email = ?
                 AND status = 'active'
                 AND expires_at > NOW()
                 ORDER BY
                     CASE action_type
                         WHEN 'replay' THEN 1
                         WHEN 'referral' THEN 2
                         ELSE 3
                     END,
                     created_at DESC",
                [$userEmail]
            );

            foreach ($dbDiscounts as $discount) {
                $discounts[] = [
                    'id' => $discount['id'],
                    'type' => $discount['action_type'],
                    'percentage' => $discount['discount_amount'],
                    'currency' => $discount['discount_currency'],
                    'expires_at' => $discount['expires_at'],
                    'metadata' => json_decode($discount['metadata_json'] ?? '{}', true)
                ];
            }

            // Check session for immediate discounts
            if (isset($_SESSION['replay_discount'])) {
                $sessionDiscount = $_SESSION['replay_discount'];
                if (strtotime($sessionDiscount['expires_at']) > time()) {
                    // Only add if not already in database results
                    $exists = false;
                    foreach ($discounts as $discount) {
                        if ($discount['id'] == $sessionDiscount['id']) {
                            $exists = true;
                            break;
                        }
                    }

                    if (!$exists) {
                        array_unshift($discounts, [
                            'id' => $sessionDiscount['id'],
                            'type' => 'replay',
                            'percentage' => $sessionDiscount['percentage'],
                            'currency' => $sessionDiscount['currency'],
                            'expires_at' => $sessionDiscount['expires_at'],
                            'metadata' => ['game_id' => $sessionDiscount['game_id']]
                        ]);
                    }
                }
            }

            if (isset($_SESSION['referral_discount'])) {
                $sessionDiscount = $_SESSION['referral_discount'];
                if (strtotime($sessionDiscount['expires_at']) > time()) {
                    // Only add if not already in database results
                    $exists = false;
                    foreach ($discounts as $discount) {
                        if ($discount['id'] == $sessionDiscount['id']) {
                            $exists = true;
                            break;
                        }
                    }

                    if (!$exists) {
                        $discounts[] = [
                            'id' => $sessionDiscount['id'],
                            'type' => 'referral',
                            'percentage' => $sessionDiscount['percentage'],
                            'currency' => $sessionDiscount['currency'],
                            'expires_at' => $sessionDiscount['expires_at'],
                            'metadata' => ['referrer_email' => $sessionDiscount['referrer_email']]
                        ];
                    }
                }
            }

            return $this->success([
                'discounts' => $discounts,
                'priority_info' => [
                    'message' => 'Replay discount takes priority over referral discount',
                    'max_discounts_per_transaction' => 1
                ]
            ]);

        } catch (Exception $e) {
            $this->logError("Failed to get available discounts: " . $e->getMessage());
            return $this->error("Failed to retrieve discounts");
        }
    }

    /**
     * Validate and apply discount to purchase
     *
     * @param string $userEmail User email
     * @param int $discountId Discount ID to apply
     * @param float $purchaseAmount Original purchase amount
     * @param string $currency Purchase currency
     * @param bool $isBundleAction Whether this is a bundle purchase (5-for-4)
     * @return array Validation result with final amount
     */
    public function validateAndApplyDiscount(
        string $userEmail,
        int $discountId,
        float $purchaseAmount,
        string $currency,
        bool $isBundleAction = false
    ): array {
        try {
            // Bundle actions are not eligible for discounts
            if ($isBundleAction) {
                return $this->error("Discounts are not applicable to bundle actions");
            }

            // Get discount details
            $discount = Database::selectOne(
                "SELECT * FROM user_actions
                 WHERE id = ? AND email = ? AND status = 'active' AND expires_at > NOW()",
                [$discountId, $userEmail]
            );

            if (!$discount) {
                return $this->error("Invalid or expired discount");
            }

            // Calculate discount amount
            $discountAmount = ($purchaseAmount * $discount['discount_amount']) / 100;
            $finalAmount = $purchaseAmount - $discountAmount;

            // Ensure final amount is not negative
            if ($finalAmount < 0) {
                $finalAmount = 0;
                $discountAmount = $purchaseAmount;
            }

            return $this->success([
                'original_amount' => $purchaseAmount,
                'discount_percentage' => $discount['discount_amount'],
                'discount_amount' => $discountAmount,
                'final_amount' => $finalAmount,
                'currency' => $currency,
                'discount_type' => $discount['action_type'],
                'discount_id' => $discountId
            ]);

        } catch (Exception $e) {
            $this->logError("Discount validation failed: " . $e->getMessage());
            return $this->error("Failed to validate discount");
        }
    }

    /**
     * Mark discount as used after successful payment
     *
     * @param int $discountId Discount ID
     * @param int $participantId Participant who used the discount
     * @param int $roundId Round where discount was used
     * @return array Usage result
     */
    public function markDiscountAsUsed(int $discountId, int $participantId, int $roundId): array
    {
        try {
            Database::beginTransaction();

            // Update discount status
            $updated = Database::update(
                "UPDATE user_actions
                 SET status = 'used', used_at = NOW(), used_round_id = ?, used_by_participant_id = ?
                 WHERE id = ? AND status = 'active'",
                [$roundId, $participantId, $discountId]
            );

            if ($updated === 0) {
                Database::rollback();
                return $this->error("Discount not found or already used");
            }

            // Get discount details for analytics
            $discount = Database::selectOne("SELECT * FROM user_actions WHERE id = ?", [$discountId]);

            // Log usage in analytics
            Analytics::logEvent('discount_used', $participantId, $roundId, null, [
                'discount_type' => $discount['action_type'],
                'discount_percentage' => $discount['discount_amount'],
                'discount_id' => $discountId
            ]);

            // Track conversion if it's a referral
            if ($discount['action_type'] === 'referral') {
                $metadata = json_decode($discount['metadata_json'] ?? '{}', true);
                if (isset($metadata['referrer_email'])) {
                    Database::insert(
                        "INSERT INTO conversion_tracking (
                            source_type, source_id, target_participant_id, converted_at
                        ) VALUES ('referral', ?, ?, NOW())",
                        [$discount['id'], $participantId]
                    );
                }
            }

            Database::commit();

            // Clear session discounts
            unset($_SESSION['replay_discount']);
            unset($_SESSION['referral_discount']);

            return $this->success([
                'message' => 'Discount marked as used successfully',
                'discount_type' => $discount['action_type'],
                'discount_percentage' => $discount['discount_amount']
            ]);

        } catch (Exception $e) {
            Database::rollback();
            $this->logError("Failed to mark discount as used: " . $e->getMessage());
            return $this->error("Failed to mark discount as used");
        }
    }

    /**
     * Generate referral link for user
     *
     * @param string $userEmail User email
     * @return array Referral link information
     */
    public function generateReferralLink(string $userEmail): array
    {
        try {
            // Check if user has completed at least one game (requirement for referrals)
            $completedGame = Database::selectOne(
                "SELECT COUNT(*) as count FROM participants
                 WHERE user_email = ? AND game_completed = 1",
                [$userEmail]
            );

            if ($completedGame['count'] === 0) {
                return $this->error("Must complete at least one game to generate referral links");
            }

            $referralCode = base64_encode($userEmail);
            $baseUrl = Config::get('APP_URL');

            return $this->success([
                'referral_code' => $referralCode,
                'referral_link' => "{$baseUrl}?ref={$referralCode}",
                'discount_percentage' => self::REFERRAL_DISCOUNT_PERCENTAGE,
                'validity_period' => self::REFERRAL_VALIDITY_MONTHS . ' months',
                'instructions' => 'Share this link to give new users 10% discount and earn 10% credit for yourself'
            ]);

        } catch (Exception $e) {
            $this->logError("Failed to generate referral link: " . $e->getMessage());
            return $this->error("Failed to generate referral link");
        }
    }

    /**
     * Get discount statistics for user
     *
     * @param string $userEmail User email
     * @return array Discount statistics
     */
    public function getDiscountStatistics(string $userEmail): array
    {
        try {
            $stats = Database::selectOne(
                "SELECT
                    COUNT(*) as total_discounts,
                    COUNT(CASE WHEN status = 'used' THEN 1 END) as used_discounts,
                    COUNT(CASE WHEN status = 'active' AND expires_at > NOW() THEN 1 END) as active_discounts,
                    COUNT(CASE WHEN action_type = 'replay' THEN 1 END) as replay_discounts,
                    COUNT(CASE WHEN action_type = 'referral' THEN 1 END) as referral_discounts,
                    SUM(CASE WHEN status = 'used' THEN discount_amount ELSE 0 END) as total_savings_percentage
                 FROM user_actions
                 WHERE email = ?",
                [$userEmail]
            );

            // Get referral statistics
            $referralStats = Database::selectOne(
                "SELECT
                    COUNT(*) as total_referrals,
                    COUNT(CASE WHEN ua.status = 'used' THEN 1 END) as successful_referrals
                 FROM user_actions ua
                 WHERE ua.email = ? AND ua.action_type = 'referral'
                 AND JSON_EXTRACT(ua.metadata_json, '$.referred_email') IS NOT NULL",
                [$userEmail]
            );

            return $this->success([
                'discount_summary' => $stats,
                'referral_summary' => $referralStats,
                'discount_rules' => [
                    'replay_discount_percentage' => self::REPLAY_DISCOUNT_PERCENTAGE,
                    'referral_discount_percentage' => self::REFERRAL_DISCOUNT_PERCENTAGE,
                    'discount_validity_hours' => self::DISCOUNT_VALIDITY_HOURS,
                    'max_discounts_per_transaction' => 1,
                    'priority_order' => ['replay', 'referral']
                ]
            ]);

        } catch (Exception $e) {
            $this->logError("Failed to get discount statistics: " . $e->getMessage());
            return $this->error("Failed to retrieve statistics");
        }
    }

    /**
     * Clean up expired discounts
     *
     * @return array Cleanup result
     */
    public function cleanupExpiredDiscounts(): array
    {
        try {
            $expiredCount = Database::update(
                "UPDATE user_actions
                 SET status = 'expired'
                 WHERE status = 'active' AND expires_at <= NOW()"
            );

            $this->logInfo("Cleaned up {$expiredCount} expired discounts");

            return $this->success([
                'expired_discounts_cleaned' => $expiredCount,
                'message' => 'Expired discounts cleaned up successfully'
            ]);

        } catch (Exception $e) {
            $this->logError("Failed to cleanup expired discounts: " . $e->getMessage());
            return $this->error("Failed to cleanup expired discounts");
        }
    }

    /**
     * Validate referral code format and decode
     *
     * @param string $referralCode Base64 encoded referral code
     * @return array Validation result
     */
    private function validateReferralCode(string $referralCode): array
    {
        try {
            $decodedEmail = base64_decode($referralCode);

            if (!filter_var($decodedEmail, FILTER_VALIDATE_EMAIL)) {
                return $this->error("Invalid referral code format");
            }

            return $this->success(['referrer_email' => $decodedEmail]);

        } catch (Exception $e) {
            return $this->error("Invalid referral code");
        }
    }

    /**
     * Log security event for fraud prevention
     *
     * @param string $eventType Type of security event
     * @param array $details Event details
     * @return void
     */
    private function logSecurityEvent(string $eventType, array $details): void
    {
        try {
            Database::insert(
                "INSERT INTO security_log (ip_address, event_type, details_json, created_at)
                 VALUES (?, ?, ?, NOW())",
                [
                    $details['ip'] ?? $_SERVER['REMOTE_ADDR'],
                    $eventType,
                    json_encode($details)
                ]
            );
        } catch (Exception $e) {
            error_log("Failed to log security event: " . $e->getMessage());
        }
    }

    /**
     * Get best available discount for user (priority logic)
     *
     * @param string $userEmail User email
     * @return array|null Best discount or null if none available
     */
    public function getBestAvailableDiscount(string $userEmail): ?array
    {
        $discounts = $this->getAvailableDiscounts($userEmail);

        if ($discounts['success'] && !empty($discounts['data']['discounts'])) {
            // Return first discount (already sorted by priority)
            return $discounts['data']['discounts'][0];
        }

        return null;
    }
}
