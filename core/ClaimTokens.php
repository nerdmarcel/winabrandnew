<?php
declare(strict_types=1);

/**
 * File: core/ClaimTokens.php
 * Location: core/ClaimTokens.php
 *
 * WinABN Secure Claim Token System
 *
 * Manages secure token generation, validation, and security for prize claiming
 * with IP-based fraud prevention and token expiration handling.
 *
 * @package WinABN\Core
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Core;

use Exception;

class ClaimTokens
{
    /**
     * Token length in characters
     *
     * @var int
     */
    private const TOKEN_LENGTH = 64;

    /**
     * Default token expiry in seconds (30 days)
     *
     * @var int
     */
    private const DEFAULT_EXPIRY = 2592000; // 30 days

    /**
     * Maximum token validation attempts per IP per hour
     *
     * @var int
     */
    private const MAX_ATTEMPTS_PER_HOUR = 4;

    /**
     * IP block duration in seconds (24 hours)
     *
     * @var int
     */
    private const IP_BLOCK_DURATION = 86400; // 24 hours

    /**
     * Generate secure claim token for winner
     *
     * @param int $participantId Participant ID
     * @param string $tokenType Token type (winner_claim, prize_tracking, etc.)
     * @param int|null $expirySeconds Custom expiry in seconds
     * @return array<string, mixed> Token generation result
     */
    public static function generateToken(
        int $participantId,
        string $tokenType = 'winner_claim',
        ?int $expirySeconds = null
    ): array {
        try {
            // Validate participant exists and is a winner
            $participant = self::validateParticipant($participantId);
            if (!$participant) {
                return [
                    'success' => false,
                    'error' => 'Invalid participant or not a winner'
                ];
            }

            // Check if active token already exists
            $existingToken = self::getActiveToken($participantId, $tokenType);
            if ($existingToken) {
                return [
                    'success' => true,
                    'token' => $existingToken['token'],
                    'expires_at' => $existingToken['expires_at'],
                    'existing' => true
                ];
            }

            // Generate cryptographically secure token
            $token = self::createSecureToken();

            // Calculate expiry
            $expirySeconds = $expirySeconds ?? self::DEFAULT_EXPIRY;
            $expiresAt = date('Y-m-d H:i:s', time() + $expirySeconds);

            // Store token in database
            $query = "
                INSERT INTO claim_tokens
                (participant_id, token, token_type, expires_at)
                VALUES (?, ?, ?, ?)
            ";

            Database::execute($query, [
                $participantId,
                $token,
                $tokenType,
                $expiresAt
            ]);

            self::logSecurityEvent('token_generated', [
                'participant_id' => $participantId,
                'token_type' => $tokenType,
                'expires_at' => $expiresAt
            ]);

            return [
                'success' => true,
                'token' => $token,
                'expires_at' => $expiresAt,
                'claim_url' => url("claim/$token"),
                'existing' => false
            ];

        } catch (Exception $e) {
            self::logSecurityEvent('token_generation_failed', [
                'participant_id' => $participantId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Token generation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Validate and verify claim token
     *
     * @param string $token Token to validate
     * @param string|null $ipAddress Client IP address
     * @return array<string, mixed> Validation result
     */
    public static function validateToken(string $token, ?string $ipAddress = null): array
    {
        $ipAddress = $ipAddress ?? client_ip();

        try {
            // Check IP blocking first
            if (self::isIpBlocked($ipAddress)) {
                self::logSecurityEvent('blocked_ip_attempt', [
                    'ip_address' => $ipAddress,
                    'token' => substr($token, 0, 8) . '...'
                ]);

                return [
                    'success' => false,
                    'error' => 'Access temporarily restricted. Please try again later.',
                    'blocked' => true
                ];
            }

            // Validate token format
            if (!self::isValidTokenFormat($token)) {
                self::recordFailedAttempt($ipAddress, 'invalid_format', $token);
                return [
                    'success' => false,
                    'error' => 'Invalid token format'
                ];
            }

            // Get token from database
            $query = "
                SELECT ct.*, p.first_name, p.last_name, p.user_email, p.phone,
                       r.game_id, g.name as game_name, g.prize_value, g.currency
                FROM claim_tokens ct
                JOIN participants p ON ct.participant_id = p.id
                JOIN rounds r ON p.round_id = r.id
                JOIN games g ON r.game_id = g.id
                WHERE ct.token = ? AND ct.is_used = 0
            ";

            $tokenData = Database::fetchOne($query, [$token]);

            if (!$tokenData) {
                self::recordFailedAttempt($ipAddress, 'token_not_found', $token);
                return [
                    'success' => false,
                    'error' => 'Invalid or already used token'
                ];
            }

            // Check expiry
            if (strtotime($tokenData['expires_at']) < time()) {
                self::recordFailedAttempt($ipAddress, 'token_expired', $token);
                return [
                    'success' => false,
                    'error' => 'Token has expired',
                    'expired' => true
                ];
            }

            // Token is valid
            self::logSecurityEvent('token_validated', [
                'token_id' => $tokenData['id'],
                'participant_id' => $tokenData['participant_id'],
                'ip_address' => $ipAddress
            ]);

            return [
                'success' => true,
                'token_data' => $tokenData,
                'participant' => [
                    'id' => $tokenData['participant_id'],
                    'first_name' => $tokenData['first_name'],
                    'last_name' => $tokenData['last_name'],
                    'email' => $tokenData['user_email'],
                    'phone' => $tokenData['phone']
                ],
                'prize' => [
                    'game_name' => $tokenData['game_name'],
                    'prize_value' => $tokenData['prize_value'],
                    'currency' => $tokenData['currency']
                ]
            ];

        } catch (Exception $e) {
            self::logSecurityEvent('token_validation_error', [
                'ip_address' => $ipAddress,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Token validation failed'
            ];
        }
    }

    /**
     * Mark token as used
     *
     * @param string $token Token to mark as used
     * @param string|null $ipAddress Client IP address
     * @return array<string, mixed> Usage result
     */
    public static function useToken(string $token, ?string $ipAddress = null): array
    {
        $ipAddress = $ipAddress ?? client_ip();

        try {
            // First validate the token
            $validation = self::validateToken($token, $ipAddress);
            if (!$validation['success']) {
                return $validation;
            }

            // Mark token as used
            $query = "
                UPDATE claim_tokens
                SET is_used = 1, used_at = NOW(), used_by_ip = ?
                WHERE token = ? AND is_used = 0
            ";

            $affected = Database::execute($query, [$ipAddress, $token])->rowCount();

            if ($affected === 0) {
                return [
                    'success' => false,
                    'error' => 'Token already used or invalid'
                ];
            }

            self::logSecurityEvent('token_used', [
                'token_id' => $validation['token_data']['id'],
                'participant_id' => $validation['token_data']['participant_id'],
                'ip_address' => $ipAddress
            ]);

            return [
                'success' => true,
                'participant' => $validation['participant'],
                'prize' => $validation['prize'],
                'token_data' => $validation['token_data']
            ];

        } catch (Exception $e) {
            self::logSecurityEvent('token_usage_error', [
                'ip_address' => $ipAddress,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to process token'
            ];
        }
    }

    /**
     * Get active token for participant
     *
     * @param int $participantId Participant ID
     * @param string $tokenType Token type
     * @return array<string, mixed>|null Active token or null
     */
    public static function getActiveToken(int $participantId, string $tokenType = 'winner_claim'): ?array
    {
        $query = "
            SELECT token, expires_at, created_at
            FROM claim_tokens
            WHERE participant_id = ?
            AND token_type = ?
            AND is_used = 0
            AND expires_at > NOW()
            ORDER BY created_at DESC
            LIMIT 1
        ";

        return Database::fetchOne($query, [$participantId, $tokenType]);
    }

    /**
     * Extend token expiry
     *
     * @param string $token Token to extend
     * @param int $additionalSeconds Additional seconds to add
     * @return array<string, mixed> Extension result
     */
    public static function extendToken(string $token, int $additionalSeconds): array
    {
        try {
            $query = "
                UPDATE claim_tokens
                SET expires_at = DATE_ADD(expires_at, INTERVAL ? SECOND)
                WHERE token = ? AND is_used = 0 AND expires_at > NOW()
            ";

            $affected = Database::execute($query, [$additionalSeconds, $token])->rowCount();

            if ($affected === 0) {
                return [
                    'success' => false,
                    'error' => 'Token not found or already expired/used'
                ];
            }

            // Get updated expiry
            $updatedToken = Database::fetchOne(
                "SELECT expires_at FROM claim_tokens WHERE token = ?",
                [$token]
            );

            self::logSecurityEvent('token_extended', [
                'token' => substr($token, 0, 8) . '...',
                'additional_seconds' => $additionalSeconds,
                'new_expiry' => $updatedToken['expires_at']
            ]);

            return [
                'success' => true,
                'new_expiry' => $updatedToken['expires_at']
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to extend token: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Clean up expired tokens
     *
     * @param int $olderThanDays Remove tokens older than X days
     * @return array<string, mixed> Cleanup result
     */
    public static function cleanupExpiredTokens(int $olderThanDays = 60): array
    {
        try {
            $query = "
                DELETE FROM claim_tokens
                WHERE expires_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                OR (is_used = 1 AND used_at < DATE_SUB(NOW(), INTERVAL ? DAY))
            ";

            $statement = Database::execute($query, [$olderThanDays, $olderThanDays]);
            $deletedCount = $statement->rowCount();

            self::logSecurityEvent('tokens_cleaned', [
                'deleted_count' => $deletedCount,
                'older_than_days' => $olderThanDays
            ]);

            return [
                'success' => true,
                'deleted_count' => $deletedCount
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Cleanup failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get token statistics
     *
     * @return array<string, mixed> Token statistics
     */
    public static function getStatistics(): array
    {
        try {
            $stats = [];

            // Total tokens by status
            $query = "
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN is_used = 1 THEN 1 ELSE 0 END) as used,
                    SUM(CASE WHEN is_used = 0 AND expires_at > NOW() THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN is_used = 0 AND expires_at <= NOW() THEN 1 ELSE 0 END) as expired
                FROM claim_tokens
            ";
            $tokenStats = Database::fetchOne($query);

            // Tokens by type
            $query = "
                SELECT token_type, COUNT(*) as count
                FROM claim_tokens
                GROUP BY token_type
            ";
            $typeStats = Database::fetchAll($query);

            // Usage rate in last 30 days
            $query = "
                SELECT
                    COUNT(*) as generated,
                    SUM(CASE WHEN is_used = 1 THEN 1 ELSE 0 END) as used
                FROM claim_tokens
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ";
            $usageStats = Database::fetchOne($query);

            return [
                'total_tokens' => (int) $tokenStats['total'],
                'used_tokens' => (int) $tokenStats['used'],
                'active_tokens' => (int) $tokenStats['active'],
                'expired_tokens' => (int) $tokenStats['expired'],
                'by_type' => $typeStats,
                'last_30_days' => [
                    'generated' => (int) $usageStats['generated'],
                    'used' => (int) $usageStats['used'],
                    'usage_rate' => $usageStats['generated'] > 0
                        ? round(($usageStats['used'] / $usageStats['generated']) * 100, 2)
                        : 0
                ]
            ];

        } catch (Exception $e) {
            return [
                'error' => 'Failed to get statistics: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Create cryptographically secure token
     *
     * @return string Secure random token
     */
    private static function createSecureToken(): string
    {
        $bytes = random_bytes(self::TOKEN_LENGTH / 2);
        return bin2hex($bytes);
    }

    /**
     * Validate token format
     *
     * @param string $token Token to validate
     * @return bool True if format is valid
     */
    private static function isValidTokenFormat(string $token): bool
    {
        return strlen($token) === self::TOKEN_LENGTH && ctype_xdigit($token);
    }

    /**
     * Validate participant is eligible for token
     *
     * @param int $participantId Participant ID
     * @return array<string, mixed>|null Participant data or null
     */
    private static function validateParticipant(int $participantId): ?array
    {
        $query = "
            SELECT p.*, r.status as round_status
            FROM participants p
            JOIN rounds r ON p.round_id = r.id
            WHERE p.id = ?
            AND p.is_winner = 1
            AND p.payment_status = 'paid'
            AND r.status = 'completed'
        ";

        return Database::fetchOne($query, [$participantId]);
    }

    /**
     * Check if IP address is blocked
     *
     * @param string $ipAddress IP address to check
     * @return bool True if blocked
     */
    private static function isIpBlocked(string $ipAddress): bool
    {
        $query = "
            SELECT COUNT(*) as attempts
            FROM security_log
            WHERE ip_address = ?
            AND event_type = 'invalid_token'
            AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ";

        $attempts = (int) Database::fetchColumn($query, [$ipAddress]);

        return $attempts >= self::MAX_ATTEMPTS_PER_HOUR;
    }

    /**
     * Record failed token attempt
     *
     * @param string $ipAddress IP address
     * @param string $reason Failure reason
     * @param string $token Failed token (partial)
     * @return void
     */
    private static function recordFailedAttempt(string $ipAddress, string $reason, string $token): void
    {
        $attempts = self::getRecentAttempts($ipAddress);

        self::logSecurityEvent('invalid_token', [
            'ip_address' => $ipAddress,
            'reason' => $reason,
            'token_partial' => substr($token, 0, 8) . '...',
            'attempt_count' => $attempts + 1
        ]);

        // Block IP if too many attempts
        if ($attempts + 1 >= self::MAX_ATTEMPTS_PER_HOUR) {
            self::blockIpAddress($ipAddress);
        }
    }

    /**
     * Get recent failed attempts for IP
     *
     * @param string $ipAddress IP address
     * @return int Number of recent attempts
     */
    private static function getRecentAttempts(string $ipAddress): int
    {
        $query = "
            SELECT COUNT(*)
            FROM security_log
            WHERE ip_address = ?
            AND event_type = 'invalid_token'
            AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ";

        return (int) Database::fetchColumn($query, [$ipAddress]);
    }

    /**
     * Block IP address temporarily
     *
     * @param string $ipAddress IP address to block
     * @return void
     */
    private static function blockIpAddress(string $ipAddress): void
    {
        $blockedUntil = date('Y-m-d H:i:s', time() + self::IP_BLOCK_DURATION);

        self::logSecurityEvent('ip_blocked', [
            'ip_address' => $ipAddress,
            'blocked_until' => $blockedUntil,
            'duration_hours' => self::IP_BLOCK_DURATION / 3600
        ], 'high');
    }

    /**
     * Log security event
     *
     * @param string $eventType Event type
     * @param array<string, mixed> $details Event details
     * @param string $severity Severity level
     * @return void
     */
    private static function logSecurityEvent(string $eventType, array $details, string $severity = 'medium'): void
    {
        try {
            $query = "
                INSERT INTO security_log
                (ip_address, event_type, details_json, severity, blocked_until)
                VALUES (?, ?, ?, ?, ?)
            ";

            $blockedUntil = null;
            if ($eventType === 'ip_blocked' && isset($details['blocked_until'])) {
                $blockedUntil = $details['blocked_until'];
            }

            Database::execute($query, [
                $details['ip_address'] ?? client_ip(),
                $eventType,
                json_encode($details),
                $severity,
                $blockedUntil
            ]);

        } catch (Exception $e) {
            // Log to file if database logging fails
            if (function_exists('app_log')) {
                app_log('error', 'Security log failed', [
                    'event_type' => $eventType,
                    'details' => $details,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}
