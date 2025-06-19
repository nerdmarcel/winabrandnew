<?php
declare(strict_types=1);

/**
 * File: core/ReferralTracker.php
 * Location: core/ReferralTracker.php
 *
 * WinABN Referral Tracking System
 *
 * Handles referral URL generation, tracking, and conversion analytics
 * with comprehensive fraud prevention and attribution tracking.
 *
 * @package WinABN\Core
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Core;

use WinABN\Core\Database;
use WinABN\Core\Session;
use WinABN\Models\UserAction;
use Exception;

class ReferralTracker
{
    /**
     * Attribution window in hours
     */
    private const ATTRIBUTION_WINDOW = 168; // 7 days

    /**
     * Maximum referrals per IP per day
     */
    private const MAX_REFERRALS_PER_IP = 5;

    /**
     * Session key for referral data
     */
    private const SESSION_KEY = 'referral_data';

    /**
     * UserAction model instance
     *
     * @var UserAction
     */
    private UserAction $userActionModel;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->userActionModel = new UserAction();
    }

    /**
     * Process incoming referral from URL
     *
     * @param string $referralCode Base64 encoded referrer email
     * @param string $gameSlug Game slug
     * @param string $clientIp Client IP address
     * @param string $userAgent User agent string
     * @return array<string, mixed> Processing result
     */
    public function processIncomingReferral(string $referralCode, string $gameSlug, string $clientIp, string $userAgent): array
    {
        try {
            // Decode and validate referral code
            $referrerEmail = base64_decode($referralCode);
            if (!filter_var($referrerEmail, FILTER_VALIDATE_EMAIL)) {
                return $this->createResult(false, 'Invalid referral code format');
            }

            // Check for fraud indicators
            $fraudCheck = $this->checkReferralFraud($referrerEmail, $clientIp, $userAgent);
            if (!$fraudCheck['valid']) {
                $this->logSecurityEvent($clientIp, 'referral_fraud', [
                    'referrer_email' => $referrerEmail,
                    'reason' => $fraudCheck['reason'],
                    'game_slug' => $gameSlug
                ]);
                return $this->createResult(false, $fraudCheck['reason']);
            }

            // Validate referrer exists and is eligible
            $referrerValidation = $this->validateReferrer($referrerEmail);
            if (!$referrerValidation['valid']) {
                return $this->createResult(false, $referrerValidation['reason']);
            }

            // Store referral data in session
            $referralData = [
                'referrer_email' => $referrerEmail,
                'game_slug' => $gameSlug,
                'referral_code' => $referralCode,
                'landed_at' => time(),
                'client_ip' => $clientIp,
                'user_agent' => $userAgent,
                'attribution_expires' => time() + (self::ATTRIBUTION_WINDOW * 3600)
            ];

            Session::set(self::SESSION_KEY, $referralData);

            // Log referral click for analytics
            $this->logReferralClick($referrerEmail, $gameSlug, $clientIp, $userAgent);

            return $this->createResult(true, 'Referral processed successfully', [
                'referrer_email' => $referrerEmail,
                'discount_available' => UserAction::REFERRAL_DISCOUNT,
                'attribution_window_hours' => self::ATTRIBUTION_WINDOW
            ]);

        } catch (Exception $e) {
            $this->logError('Referral processing failed', [
                'error' => $e->getMessage(),
                'referral_code' => $referralCode,
                'game_slug' => $gameSlug
            ]);

            return $this->createResult(false, 'Failed to process referral');
        }
    }

    /**
     * Apply referral discount when user registers
     *
     * @param string $userEmail User email
     * @param int $participantId Participant ID
     * @return array<string, mixed> Application result
     */
    public function applyReferralDiscount(string $userEmail, int $participantId): array
    {
        try {
            $referralData = Session::get(self::SESSION_KEY);
            if (!$referralData) {
                return $this->createResult(false, 'No referral data found');
            }

            // Check if attribution window is still valid
            if (time() > $referralData['attribution_expires']) {
                Session::forget(self::SESSION_KEY);
                return $this->createResult(false, 'Referral attribution window expired');
            }

            $referrerEmail = $referralData['referrer_email'];

            // Prevent self-referral
            if ($referrerEmail === $userEmail) {
                Session::forget(self::SESSION_KEY);
                $this->logSecurityEvent($referralData['client_ip'], 'self_referral_attempt', [
                    'user_email' => $userEmail,
                    'referrer_email' => $referrerEmail
                ]);
                return $this->createResult(false, 'Self-referral not allowed');
            }

            // Check if user already used a referral
            if ($this->userHasUsedReferral($userEmail)) {
                return $this->createResult(false, 'User already used a referral discount');
            }

            // Create referral discounts for both parties
            $actionIds = $this->userActionModel->createReferralDiscount(
                $referrerEmail,
                $userEmail,
                $participantId
            );

            // Track successful referral conversion
            $conversionId = $this->trackReferralConversion(
                $referrerEmail,
                $userEmail,
                $participantId,
                $referralData
            );

            // Update referrer statistics
            $this->updateReferrerStats($referrerEmail);

            // Clear referral data from session
            Session::forget(self::SESSION_KEY);

            return $this->createResult(true, 'Referral discount applied successfully', [
                'referrer_action_id' => $actionIds['referrer'],
                'referee_action_id' => $actionIds['referee'],
                'conversion_id' => $conversionId,
                'discount_amount' => UserAction::REFERRAL_DISCOUNT
            ]);

        } catch (Exception $e) {
            $this->logError('Referral discount application failed', [
                'error' => $e->getMessage(),
                'user_email' => $userEmail,
                'participant_id' => $participantId
            ]);

            return $this->createResult(false, 'Failed to apply referral discount');
        }
    }

    /**
     * Generate shareable referral URL
     *
     * @param string $referrerEmail Referrer email
     * @param string $gameSlug Game slug
     * @param array<string, mixed> $options Additional options
     * @return array<string, mixed> Generated URL data
     */
    public function generateReferralUrl(string $referrerEmail, string $gameSlug, array $options = []): array
    {
        try {
            // Validate referrer eligibility
            $referrerValidation = $this->validateReferrer($referrerEmail);
            if (!$referrerValidation['valid']) {
                return $this->createResult(false, $referrerValidation['reason']);
            }

            $referralCode = base64_encode($referrerEmail);
            $baseUrl = env('APP_URL', 'https://winabn.com');

            // Build URL with tracking parameters
            $urlParams = [
                'ref' => $referralCode
            ];

            // Add campaign tracking if specified
            if (!empty($options['campaign'])) {
                $urlParams['utm_campaign'] = $options['campaign'];
            }
            if (!empty($options['source'])) {
                $urlParams['utm_source'] = $options['source'];
            }
            if (!empty($options['medium'])) {
                $urlParams['utm_medium'] = $options['medium'];
            }

            $referralUrl = $baseUrl . "/win-a-{$gameSlug}?" . http_build_query($urlParams);

            // Generate short URL if requested
            $shortUrl = null;
            if ($options['generate_short_url'] ?? false) {
                $shortUrl = $this->generateShortUrl($referralUrl, $referrerEmail, $gameSlug);
            }

            // Generate sharing content
            $sharingContent = $this->generateSharingContent($gameSlug, $referralUrl, $options);

            // Log URL generation
            $this->logReferralUrlGeneration($referrerEmail, $gameSlug, $referralUrl);

            return $this->createResult(true, 'Referral URL generated successfully', [
                'referral_url' => $referralUrl,
                'short_url' => $shortUrl,
                'referral_code' => $referralCode,
                'sharing_content' => $sharingContent,
                'expires_in_hours' => self::ATTRIBUTION_WINDOW
            ]);

        } catch (Exception $e) {
            $this->logError('Referral URL generation failed', [
                'error' => $e->getMessage(),
                'referrer_email' => $referrerEmail,
                'game_slug' => $gameSlug
            ]);

            return $this->createResult(false, 'Failed to generate referral URL');
        }
    }

    /**
     * Get referral statistics for user
     *
     * @param string $userEmail User email
     * @return array<string, mixed> Referral statistics
     */
    public function getReferralStatistics(string $userEmail): array
    {
        try {
            $stats = [
                'total_referrals_sent' => 0,
                'successful_conversions' => 0,
                'pending_conversions' => 0,
                'total_credits_earned' => 0,
                'credits_used' => 0,
                'credits_available' => 0,
                'conversion_rate' => 0,
                'recent_referrals' => [],
                'top_performing_games' => []
            ];

            // Get total referrals sent (clicks tracked)
            $clicksQuery = "
                SELECT COUNT(*) as total_clicks
                FROM referral_clicks
                WHERE referrer_email = ?
            ";
            $clicksResult = Database::fetchOne($clicksQuery, [$userEmail]);
            $stats['total_referrals_sent'] = (int) $clicksResult['total_clicks'];

            // Get successful conversions
            $conversionsQuery = "
                SELECT COUNT(*) as total_conversions
                FROM conversion_tracking ct
                JOIN participants p ON ct.target_participant_id = p.id
                WHERE ct.source_type = 'referral'
                AND ct.source_id = ?
                AND p.payment_status = 'paid'
            ";
            $conversionsResult = Database::fetchOne($conversionsQuery, [$userEmail]);
            $stats['successful_conversions'] = (int) $conversionsResult['total_conversions'];

            // Get pending conversions (registered but not paid)
            $pendingQuery = "
                SELECT COUNT(*) as pending_conversions
                FROM conversion_tracking ct
                JOIN participants p ON ct.target_participant_id = p.id
                WHERE ct.source_type = 'referral'
                AND ct.source_id = ?
                AND p.payment_status = 'pending'
            ";
            $pendingResult = Database::fetchOne($pendingQuery, [$userEmail]);
            $stats['pending_conversions'] = (int) $pendingResult['pending_conversions'];

            // Get credit information
            $creditsQuery = "
                SELECT
                    COUNT(*) as total_credits,
                    COUNT(CASE WHEN used_at IS NOT NULL THEN 1 END) as used_credits,
                    COUNT(CASE WHEN used_at IS NULL AND expires_at > NOW() THEN 1 END) as available_credits
                FROM user_actions
                WHERE email = ?
                AND action_type = 'referral'
                AND is_active = 1
            ";
            $creditsResult = Database::fetchOne($creditsQuery, [$userEmail]);
            $stats['total_credits_earned'] = (int) $creditsResult['total_credits'];
            $stats['credits_used'] = (int) $creditsResult['used_credits'];
            $stats['credits_available'] = (int) $creditsResult['available_credits'];

            // Calculate conversion rate
            if ($stats['total_referrals_sent'] > 0) {
                $stats['conversion_rate'] = round(
                    ($stats['successful_conversions'] / $stats['total_referrals_sent']) * 100,
                    2
                );
            }

            // Get recent referrals
            $recentQuery = "
                SELECT
                    ct.converted_at,
                    p.first_name,
                    p.last_name,
                    p.payment_status,
                    g.name as game_name,
                    p.payment_amount
                FROM conversion_tracking ct
                JOIN participants p ON ct.target_participant_id = p.id
                JOIN rounds r ON p.round_id = r.id
                JOIN games g ON r.game_id = g.id
                WHERE ct.source_type = 'referral'
                AND ct.source_id = ?
                ORDER BY ct.converted_at DESC
                LIMIT 10
            ";
            $stats['recent_referrals'] = Database::fetchAll($recentQuery, [$userEmail]);

            // Get top performing games
            $topGamesQuery = "
                SELECT
                    g.name as game_name,
                    COUNT(*) as referral_count,
                    COUNT(CASE WHEN p.payment_status = 'paid' THEN 1 END) as successful_count
                FROM conversion_tracking ct
                JOIN participants p ON ct.target_participant_id = p.id
                JOIN rounds r ON p.round_id = r.id
                JOIN games g ON r.game_id = g.id
                WHERE ct.source_type = 'referral'
                AND ct.source_id = ?
                GROUP BY g.id, g.name
                ORDER BY successful_count DESC, referral_count DESC
                LIMIT 5
            ";
            $stats['top_performing_games'] = Database::fetchAll($topGamesQuery, [$userEmail]);

            return $stats;

        } catch (Exception $e) {
            $this->logError('Failed to get referral statistics', [
                'error' => $e->getMessage(),
                'user_email' => $userEmail
            ]);

            return [];
        }
    }

    /**
     * Check for referral fraud indicators
     *
     * @param string $referrerEmail Referrer email
     * @param string $clientIp Client IP
     * @param string $userAgent User agent
     * @return array<string, mixed> Fraud check result
     */
    private function checkReferralFraud(string $referrerEmail, string $clientIp, string $userAgent): array
    {
        // Check if same IP was used by referrer recently
        $ipCheckQuery = "
            SELECT COUNT(*) as count
            FROM participants p
            WHERE p.user_email = ?
            AND p.ip_address = ?
            AND p.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ";
        $ipResult = Database::fetchOne($ipCheckQuery, [$referrerEmail, $clientIp]);

        if ($ipResult['count'] > 0) {
            return ['valid' => false, 'reason' => 'Same IP used by referrer recently'];
        }

        // Check for excessive referrals from same IP
        $excessiveIpQuery = "
            SELECT COUNT(*) as count
            FROM referral_clicks
            WHERE client_ip = ?
            AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ";
        $excessiveResult = Database::fetchOne($excessiveIpQuery, [$clientIp]);

        if ($excessiveResult['count'] > self::MAX_REFERRALS_PER_IP) {
            return ['valid' => false, 'reason' => 'Too many referrals from this IP'];
        }

        // Check for bot-like user agent patterns
        if ($this->isBotUserAgent($userAgent)) {
            return ['valid' => false, 'reason' => 'Bot-like user agent detected'];
        }

        return ['valid' => true, 'reason' => ''];
    }

    /**
     * Validate that referrer is eligible to refer others
     *
     * @param string $referrerEmail Referrer email
     * @return array<string, mixed> Validation result
     */
    private function validateReferrer(string $referrerEmail): array
    {
        // Check if referrer has made at least one paid participation
        $paidParticipationQuery = "
            SELECT COUNT(*) as count
            FROM participants
            WHERE user_email = ?
            AND payment_status = 'paid'
        ";
        $result = Database::fetchOne($paidParticipationQuery, [$referrerEmail]);

        if ($result['count'] === 0) {
            return ['valid' => false, 'reason' => 'Referrer must participate in a game first'];
        }

        // Check if referrer account is not fraudulent
        $fraudCheckQuery = "
            SELECT COUNT(*) as fraud_count
            FROM participants
            WHERE user_email = ?
            AND is_fraudulent = 1
        ";
        $fraudResult = Database::fetchOne($fraudCheckQuery, [$referrerEmail]);

        if ($fraudResult['fraud_count'] > 0) {
            return ['valid' => false, 'reason' => 'Referrer account flagged for fraud'];
        }

        return ['valid' => true, 'reason' => ''];
    }

    /**
     * Check if user has already used a referral discount
     *
     * @param string $userEmail User email
     * @return bool
     */
    private function userHasUsedReferral(string $userEmail): bool
    {
        $query = "
            SELECT COUNT(*) as count
            FROM user_actions
            WHERE email = ?
            AND action_type = 'referral'
            AND used_at IS NOT NULL
        ";
        $result = Database::fetchOne($query, [$userEmail]);

        return $result['count'] > 0;
    }

    /**
     * Track referral conversion
     *
     * @param string $referrerEmail Referrer email
     * @param string $refereeEmail Referee email
     * @param int $participantId Participant ID
     * @param array<string, mixed> $referralData Referral data
     * @return int Conversion tracking ID
     */
    private function trackReferralConversion(string $referrerEmail, string $refereeEmail, int $participantId, array $referralData): int
    {
        $query = "
            INSERT INTO conversion_tracking
            (source_type, source_id, target_participant_id, attribution_window_hours, converted_at)
            VALUES ('referral', ?, ?, ?, NOW())
        ";

        Database::execute($query, [
            $referrerEmail,
            $participantId,
            self::ATTRIBUTION_WINDOW
        ]);

        return Database::lastInsertId();
    }

    /**
     * Log referral click for analytics
     *
     * @param string $referrerEmail Referrer email
     * @param string $gameSlug Game slug
     * @param string $clientIp Client IP
     * @param string $userAgent User agent
     * @return void
     */
    private function logReferralClick(string $referrerEmail, string $gameSlug, string $clientIp, string $userAgent): void
    {
        $query = "
            INSERT INTO referral_clicks
            (referrer_email, game_slug, client_ip, user_agent, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ";

        Database::execute($query, [$referrerEmail, $gameSlug, $clientIp, $userAgent]);
    }

    /**
     * Update referrer statistics
     *
     * @param string $referrerEmail Referrer email
     * @return void
     */
    private function updateReferrerStats(string $referrerEmail): void
    {
        // This could update a referrer_stats table or trigger other analytics
        // For now, we'll just log the successful referral
        $this->logAnalyticsEvent('referral_success', [
            'referrer_email' => $referrerEmail,
            'timestamp' => time()
        ]);
    }

    /**
     * Generate sharing content for different platforms
     *
     * @param string $gameSlug Game slug
     * @param string $referralUrl Referral URL
     * @param array<string, mixed> $options Options
     * @return array<string, mixed> Sharing content
     */
    private function generateSharingContent(string $gameSlug, string $referralUrl, array $options): array
    {
        $gameMessages = [
            'win-a-iphone-15-pro' => [
                'title' => '🎉 Win the latest iPhone 15 Pro!',
                'message' => "I found this amazing competition where you can win the latest iPhone 15 Pro! We both get 10% off when you join using my link.",
                'hashtags' => '#iPhone15Pro #Competition #WinABN'
            ],
            'win-a-macbook-air-m3' => [
                'title' => '💻 Win a MacBook Air M3!',
                'message' => "Want to win a brand new MacBook Air M3? Join this competition with my referral link and we both save 10%!",
                'hashtags' => '#MacBookAir #M3 #Competition #WinABN'
            ],
            'win-a-ps5-pro' => [
                'title' => '🎮 PlayStation 5 Pro Competition!',
                'message' => "PlayStation 5 Pro up for grabs! Use my link to join and we both get a discount!",
                'hashtags' => '#PS5Pro #Gaming #Competition #WinABN'
            ]
        ];

        $content = $gameMessages[$gameSlug] ?? [
            'title' => '🎯 Amazing Competition Alert!',
            'message' => "Join this amazing competition with my link and we both get 10% off our entry!",
            'hashtags' => '#Competition #WinABN'
        ];

        return [
            'email' => [
                'subject' => $content['title'],
                'body' => $content['message'] . "\n\n" . $referralUrl
            ],
            'social_media' => [
                'twitter' => $content['message'] . "\n\n" . $referralUrl . "\n\n" . $content['hashtags'],
                'facebook' => $content['message'] . "\n\n" . $referralUrl,
                'whatsapp' => $content['message'] . "\n\n" . $referralUrl,
                'telegram' => $content['message'] . "\n\n" . $referralUrl
            ],
            'sms' => substr($content['message'], 0, 100) . "... " . $referralUrl
        ];
    }

    /**
     * Generate short URL for easier sharing
     *
     * @param string $longUrl Long URL
     * @param string $referrerEmail Referrer email
     * @param string $gameSlug Game slug
     * @return string|null Short URL or null if generation fails
     */
    private function generateShortUrl(string $longUrl, string $referrerEmail, string $gameSlug): ?string
    {
        try {
            // Generate short code
            $shortCode = $this->generateShortCode();

            // Store in database
            $query = "
                INSERT INTO short_urls
                (short_code, long_url, referrer_email, game_slug, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ";

            Database::execute($query, [$shortCode, $longUrl, $referrerEmail, $gameSlug]);

            $baseUrl = env('APP_URL', 'https://winabn.com');
            return $baseUrl . '/r/' . $shortCode;

        } catch (Exception $e) {
            $this->logError('Short URL generation failed', [
                'error' => $e->getMessage(),
                'long_url' => $longUrl
            ]);
            return null;
        }
    }

    /**
     * Generate random short code
     *
     * @return string Short code
     */
    private function generateShortCode(): string
    {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $shortCode = '';

        for ($i = 0; $i < 6; $i++) {
            $shortCode .= $characters[random_int(0, strlen($characters) - 1)];
        }

        // Check if code already exists
        $query = "SELECT COUNT(*) as count FROM short_urls WHERE short_code = ?";
        $result = Database::fetchOne($query, [$shortCode]);

        if ($result['count'] > 0) {
            return $this->generateShortCode(); // Recursive retry
        }

        return $shortCode;
    }

    /**
     * Check if user agent appears to be a bot
     *
     * @param string $userAgent User agent string
     * @return bool
     */
    private function isBotUserAgent(string $userAgent): bool
    {
        $botPatterns = [
            '/bot/i',
            '/crawler/i',
            '/spider/i',
            '/scraper/i',
            '/curl/i',
            '/wget/i',
            '/phantom/i',
            '/headless/i'
        ];

        foreach ($botPatterns as $pattern) {
            if (preg_match($pattern, $userAgent)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Log URL generation event
     *
     * @param string $referrerEmail Referrer email
     * @param string $gameSlug Game slug
     * @param string $url Generated URL
     * @return void
     */
    private function logReferralUrlGeneration(string $referrerEmail, string $gameSlug, string $url): void
    {
        $this->logAnalyticsEvent('referral_url_generated', [
            'referrer_email' => $referrerEmail,
            'game_slug' => $gameSlug,
            'url' => $url,
            'timestamp' => time()
        ]);
    }

    /**
     * Log security event
     *
     * @param string $ipAddress IP address
     * @param string $eventType Event type
     * @param array<string, mixed> $details Event details
     * @return void
     */
    private function logSecurityEvent(string $ipAddress, string $eventType, array $details): void
    {
        $query = "
            INSERT INTO security_log
            (ip_address, event_type, details_json, severity, created_at)
            VALUES (?, ?, ?, 'medium', NOW())
        ";

        Database::execute($query, [$ipAddress, $eventType, json_encode($details)]);
    }

    /**
     * Log analytics event
     *
     * @param string $eventType Event type
     * @param array<string, mixed> $data Event data
     * @return void
     */
    private function logAnalyticsEvent(string $eventType, array $data): void
    {
        $query = "
            INSERT INTO analytics_events
            (event_type, additional_data_json, created_at)
            VALUES (?, ?, NOW())
        ";

        Database::execute($query, [$eventType, json_encode($data)]);
    }

    /**
     * Create standardized result array
     *
     * @param bool $success Success status
     * @param string $message Result message
     * @param array<string, mixed> $data Additional data
     * @return array<string, mixed>
     */
    private function createResult(bool $success, string $message, array $data = []): array
    {
        return [
            'success' => $success,
            'message' => $message,
            'data' => $data
        ];
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
            error_log("ReferralTracker Error: $message " . json_encode($context));
        }
    }
}
