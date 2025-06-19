<?php
declare(strict_types=1);

/**
 * File: core/WhatsAppOptIn.php
 * Location: core/WhatsAppOptIn.php
 *
 * WinABN WhatsApp Opt-in/Opt-out Management
 *
 * Handles WhatsApp consent management, unsubscribe processing, and compliance
 * with WhatsApp Business API policies and GDPR requirements.
 *
 * @package WinABN\Core
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Core;

use Exception;

class WhatsAppOptIn
{
    /**
     * Opt-in status constants
     */
    public const STATUS_OPTED_IN = 'opted_in';
    public const STATUS_OPTED_OUT = 'opted_out';
    public const STATUS_PENDING = 'pending';
    public const STATUS_UNKNOWN = 'unknown';

    /**
     * Unsubscribe keywords
     *
     * @var array<string>
     */
    private static array $unsubscribeKeywords = [
        'stop', 'unsubscribe', 'opt out', 'remove', 'cancel',
        'quit', 'end', 'no more', 'halt', 'cease'
    ];

    /**
     * Resubscribe keywords
     *
     * @var array<string>
     */
    private static array $resubscribeKeywords = [
        'start', 'subscribe', 'opt in', 'yes', 'join', 'begin'
    ];

    /**
     * Get opt-in status for phone number
     *
     * @param string $phoneNumber Phone number to check
     * @return array<string, mixed> Opt-in status and details
     */
    public static function getOptInStatus(string $phoneNumber): array
    {
        try {
            $phoneNumber = self::formatPhoneNumber($phoneNumber);

            // Get latest consent from participants table
            $query = "
                SELECT whatsapp_consent, user_email, first_name, created_at
                FROM participants
                WHERE phone = ?
                ORDER BY created_at DESC
                LIMIT 1
            ";

            $participant = Database::fetchOne($query, [$phoneNumber]);

            if (!$participant) {
                return [
                    'status' => self::STATUS_UNKNOWN,
                    'phone_number' => $phoneNumber,
                    'opted_in' => false,
                    'last_updated' => null,
                    'email' => null
                ];
            }

            // Check for explicit opt-out in opt-out log
            $optOutQuery = "
                SELECT created_at, reason, method
                FROM whatsapp_opt_outs
                WHERE phone_number = ?
                ORDER BY created_at DESC
                LIMIT 1
            ";

            $optOut = Database::fetchOne($optOutQuery, [$phoneNumber]);

            if ($optOut) {
                return [
                    'status' => self::STATUS_OPTED_OUT,
                    'phone_number' => $phoneNumber,
                    'opted_in' => false,
                    'last_updated' => $optOut['created_at'],
                    'opt_out_reason' => $optOut['reason'],
                    'opt_out_method' => $optOut['method'],
                    'email' => $participant['user_email']
                ];
            }

            $status = $participant['whatsapp_consent'] ? self::STATUS_OPTED_IN : self::STATUS_OPTED_OUT;

            return [
                'status' => $status,
                'phone_number' => $phoneNumber,
                'opted_in' => (bool) $participant['whatsapp_consent'],
                'last_updated' => $participant['created_at'],
                'email' => $participant['user_email'],
                'first_name' => $participant['first_name']
            ];

        } catch (Exception $e) {
            return [
                'status' => self::STATUS_UNKNOWN,
                'phone_number' => $phoneNumber,
                'opted_in' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Process opt-out request
     *
     * @param string $phoneNumber Phone number to opt out
     * @param string $method Opt-out method (whatsapp_message, web_form, admin, etc.)
     * @param string|null $reason Opt-out reason
     * @param array<string, mixed> $context Additional context
     * @return array<string, mixed> Processing result
     */
    public static function processOptOut(
        string $phoneNumber,
        string $method = 'whatsapp_message',
        ?string $reason = null,
        array $context = []
    ): array {
        try {
            $phoneNumber = self::formatPhoneNumber($phoneNumber);

            Database::beginTransaction();

            // Update all participants with this phone to opt out
            $updateQuery = "
                UPDATE participants
                SET whatsapp_consent = 0, updated_at = NOW()
                WHERE phone = ?
            ";

            $statement = Database::execute($updateQuery, [$phoneNumber]);
            $updatedParticipants = $statement->rowCount();

            // Record opt-out in dedicated table
            $optOutQuery = "
                INSERT INTO whatsapp_opt_outs
                (phone_number, method, reason, context_json, ip_address)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                method = VALUES(method),
                reason = VALUES(reason),
                context_json = VALUES(context_json),
                updated_at = NOW()
            ";

            Database::execute($optOutQuery, [
                $phoneNumber,
                $method,
                $reason,
                json_encode($context),
                client_ip()
            ]);

            // Cancel any pending WhatsApp messages
            $cancelQuery = "
                UPDATE whatsapp_queue
                SET status = 'cancelled', updated_at = NOW()
                WHERE to_phone = ? AND status = 'pending'
            ";

            $cancelStatement = Database::execute($cancelQuery, [$phoneNumber]);
            $cancelledMessages = $cancelStatement->rowCount();

            Database::commit();

            // Log the opt-out
            self::logOptInEvent('opt_out', $phoneNumber, [
                'method' => $method,
                'reason' => $reason,
                'updated_participants' => $updatedParticipants,
                'cancelled_messages' => $cancelledMessages,
                'context' => $context
            ]);

            // Send confirmation if requested via WhatsApp
            if ($method === 'whatsapp_message') {
                self::sendOptOutConfirmation($phoneNumber);
            }

            return [
                'success' => true,
                'phone_number' => $phoneNumber,
                'updated_participants' => $updatedParticipants,
                'cancelled_messages' => $cancelledMessages,
                'method' => $method
            ];

        } catch (Exception $e) {
            Database::rollback();

            self::logOptInEvent('opt_out_failed', $phoneNumber, [
                'method' => $method,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Opt-out processing failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Process opt-in request
     *
     * @param string $phoneNumber Phone number to opt in
     * @param string $email User email
     * @param string $firstName User first name
     * @param string $method Opt-in method
     * @param array<string, mixed> $context Additional context
     * @return array<string, mixed> Processing result
     */
    public static function processOptIn(
        string $phoneNumber,
        string $email,
        string $firstName,
        string $method = 'web_form',
        array $context = []
    ): array {
        try {
            $phoneNumber = self::formatPhoneNumber($phoneNumber);

            Database::beginTransaction();

            // Remove from opt-out table if exists
            $removeOptOutQuery = "
                DELETE FROM whatsapp_opt_outs
                WHERE phone_number = ?
            ";
            Database::execute($removeOptOutQuery, [$phoneNumber]);

            // Update existing participants
            $updateQuery = "
                UPDATE participants
                SET whatsapp_consent = 1, updated_at = NOW()
                WHERE phone = ?
            ";

            $statement = Database::execute($updateQuery, [$phoneNumber]);
            $updatedParticipants = $statement->rowCount();

            Database::commit();

            self::logOptInEvent('opt_in', $phoneNumber, [
                'method' => $method,
                'email' => $email,
                'first_name' => $firstName,
                'updated_participants' => $updatedParticipants,
                'context' => $context
            ]);

            // Send welcome message if requested via WhatsApp
            if ($method === 'whatsapp_message') {
                self::sendOptInConfirmation($phoneNumber, $firstName);
            }

            return [
                'success' => true,
                'phone_number' => $phoneNumber,
                'updated_participants' => $updatedParticipants,
                'method' => $method
            ];

        } catch (Exception $e) {
            Database::rollback();

            self::logOptInEvent('opt_in_failed', $phoneNumber, [
                'method' => $method,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Opt-in processing failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Process incoming WhatsApp message for opt-in/opt-out
     *
     * @param string $phoneNumber Sender phone number
     * @param string $messageText Message content
     * @return array<string, mixed> Processing result
     */
    public static function processIncomingMessage(string $phoneNumber, string $messageText): array
    {
        try {
            $phoneNumber = self::formatPhoneNumber($phoneNumber);
            $messageText = strtolower(trim($messageText));

            // Check for unsubscribe keywords
            foreach (self::$unsubscribeKeywords as $keyword) {
                if (strpos($messageText, $keyword) !== false) {
                    return self::processOptOut(
                        $phoneNumber,
                        'whatsapp_message',
                        'User sent unsubscribe keyword: ' . $keyword,
                        ['original_message' => $messageText]
                    );
                }
            }

            // Check for resubscribe keywords
            foreach (self::$resubscribeKeywords as $keyword) {
                if (strpos($messageText, $keyword) !== false) {
                    // Get user data for opt-in
                    $userData = self::getUserDataByPhone($phoneNumber);

                    if ($userData) {
                        return self::processOptIn(
                            $phoneNumber,
                            $userData['email'],
                            $userData['first_name'],
                            'whatsapp_message',
                            ['original_message' => $messageText]
                        );
                    } else {
                        return [
                            'success' => false,
                            'error' => 'User data not found for resubscription'
                        ];
                    }
                }
            }

            // Message doesn't contain opt-in/opt-out keywords
            return [
                'success' => true,
                'action' => 'none',
                'message' => 'No opt-in/opt-out keywords detected'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Message processing failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Bulk update opt-in status
     *
     * @param array<string> $phoneNumbers Array of phone numbers
     * @param bool $optIn True for opt-in, false for opt-out
     * @param string $reason Reason for bulk update
     * @return array<string, mixed> Update result
     */
    public static function bulkUpdateOptIn(array $phoneNumbers, bool $optIn, string $reason): array
    {
        $successful = 0;
        $failed = 0;
        $errors = [];

        try {
            Database::beginTransaction();

            foreach ($phoneNumbers as $phoneNumber) {
                try {
                    $phoneNumber = self::formatPhoneNumber($phoneNumber);

                    if ($optIn) {
                        $result = self::processOptIn($phoneNumber, '', '', 'bulk_admin', ['reason' => $reason]);
                    } else {
                        $result = self::processOptOut($phoneNumber, 'bulk_admin', $reason);
                    }

                    if ($result['success']) {
                        $successful++;
                    } else {
                        $failed++;
                        $errors[] = [
                            'phone' => $phoneNumber,
                            'error' => $result['error']
                        ];
                    }

                } catch (Exception $e) {
                    $failed++;
                    $errors[] = [
                        'phone' => $phoneNumber,
                        'error' => $e->getMessage()
                    ];
                }
            }

            Database::commit();

            return [
                'success' => true,
                'successful' => $successful,
                'failed' => $failed,
                'errors' => $errors,
                'total' => count($phoneNumbers)
            ];

        } catch (Exception $e) {
            Database::rollback();

            return [
                'success' => false,
                'error' => 'Bulk update failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get opt-in statistics
     *
     * @param int $days Number of days to analyze
     * @return array<string, mixed> Statistics
     */
    public static function getOptInStatistics(int $days = 30): array
    {
        try {
            // Total participants with WhatsApp consent
            $totalOptedInQuery = "
                SELECT COUNT(DISTINCT phone) as count
                FROM participants
                WHERE whatsapp_consent = 1
            ";
            $totalOptedIn = (int) Database::fetchColumn($totalOptedInQuery);

            // Recent opt-outs
            $recentOptOutsQuery = "
                SELECT COUNT(*) as count, method
                FROM whatsapp_opt_outs
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY method
            ";
            $recentOptOuts = Database::fetchAll($recentOptOutsQuery, [$days]);

            // Recent opt-ins (participants who gave consent recently)
            $recentOptInsQuery = "
                SELECT COUNT(DISTINCT phone) as count
                FROM participants
                WHERE whatsapp_consent = 1
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ";
            $recentOptIns = (int) Database::fetchColumn($recentOptInsQuery, [$days]);

            // Opt-out reasons
            $optOutReasonsQuery = "
                SELECT reason, COUNT(*) as count
                FROM whatsapp_opt_outs
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                AND reason IS NOT NULL
                GROUP BY reason
                ORDER BY count DESC
            ";
            $optOutReasons = Database::fetchAll($optOutReasonsQuery, [$days]);

            return [
                'total_opted_in' => $totalOptedIn,
                'recent_opt_ins' => $recentOptIns,
                'recent_opt_outs' => $recentOptOuts,
                'opt_out_reasons' => $optOutReasons,
                'analysis_period_days' => $days
            ];

        } catch (Exception $e) {
            return [
                'error' => 'Failed to get statistics: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Export opt-out list for compliance
     *
     * @return array<array<string, mixed>> List of opted-out numbers
     */
    public static function exportOptOutList(): array
    {
        try {
            $query = "
                SELECT phone_number, method, reason, created_at, updated_at
                FROM whatsapp_opt_outs
                ORDER BY updated_at DESC
            ";

            return Database::fetchAll($query);

        } catch (Exception $e) {
            return [
                ['error' => 'Export failed: ' . $e->getMessage()]
            ];
        }
    }

    /**
     * Validate phone number and get consent status before sending
     *
     * @param string $phoneNumber Phone number to validate
     * @return array<string, mixed> Validation result
     */
    public static function validateForSending(string $phoneNumber): array
    {
        $optInStatus = self::getOptInStatus($phoneNumber);

        if (!$optInStatus['opted_in']) {
            return [
                'valid' => false,
                'reason' => 'User has not opted in or has opted out',
                'status' => $optInStatus['status'],
                'last_updated' => $optInStatus['last_updated']
            ];
        }

        return [
            'valid' => true,
            'status' => $optInStatus['status'],
            'last_updated' => $optInStatus['last_updated']
        ];
    }

    /**
     * Send opt-out confirmation message
     *
     * @param string $phoneNumber Phone number
     * @return void
     */
    private static function sendOptOutConfirmation(string $phoneNumber): void
    {
        try {
            // Queue a simple text message confirmation
            $query = "
                INSERT INTO whatsapp_queue
                (to_phone, message_template, message_type, priority, send_at)
                VALUES (?, 'opt_out_confirmation', 'system', 1, NOW())
            ";

            Database::execute($query, [$phoneNumber]);

        } catch (Exception $e) {
            // Don't fail the opt-out process if confirmation fails
            self::logOptInEvent('opt_out_confirmation_failed', $phoneNumber, [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Send opt-in confirmation message
     *
     * @param string $phoneNumber Phone number
     * @param string $firstName User's first name
     * @return void
     */
    private static function sendOptInConfirmation(string $phoneNumber, string $firstName): void
    {
        try {
            $query = "
                INSERT INTO whatsapp_queue
                (to_phone, message_template, variables_json, message_type, priority, send_at)
                VALUES (?, 'opt_in_confirmation', ?, 'system', 1, NOW())
            ";

            $variables = json_encode(['first_name' => $firstName]);

            Database::execute($query, [$phoneNumber, $variables]);

        } catch (Exception $e) {
            self::logOptInEvent('opt_in_confirmation_failed', $phoneNumber, [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get user data by phone number
     *
     * @param string $phoneNumber Phone number
     * @return array<string, mixed>|null User data
     */
    private static function getUserDataByPhone(string $phoneNumber): ?array
    {
        $query = "
            SELECT user_email as email, first_name
            FROM participants
            WHERE phone = ?
            ORDER BY created_at DESC
            LIMIT 1
        ";

        return Database::fetchOne($query, [$phoneNumber]);
    }

    /**
     * Format phone number to international format
     *
     * @param string $phoneNumber Raw phone number
     * @return string Formatted phone number
     * @throws Exception
     */
    private static function formatPhoneNumber(string $phoneNumber): string
    {
        // Remove all non-numeric characters except +
        $cleaned = preg_replace('/[^\d+]/', '', $phoneNumber);

        // Add + if not present and starts with country code
        if (!str_starts_with($cleaned, '+')) {
            $cleaned = '+' . $cleaned;
        }

        // Basic validation
        if (!preg_match('/^\+\d{10,15}$/', $cleaned)) {
            throw new Exception("Invalid phone number format: $phoneNumber");
        }

        return $cleaned;
    }

    /**
     * Log opt-in/opt-out events
     *
     * @param string $eventType Event type
     * @param string $phoneNumber Phone number
     * @param array<string, mixed> $context Event context
     * @return void
     */
    private static function logOptInEvent(string $eventType, string $phoneNumber, array $context = []): void
    {
        if (function_exists('app_log')) {
            app_log('info', "WhatsApp $eventType", array_merge($context, [
                'phone_number' => $phoneNumber,
                'ip_address' => client_ip()
            ]));
        }
    }
}

/**
 * Create whatsapp_opt_outs table if it doesn't exist
 */
if (!Database::fetchOne("SHOW TABLES LIKE 'whatsapp_opt_outs'")) {
    $createTableQuery = "
        CREATE TABLE `whatsapp_opt_outs` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `phone_number` varchar(20) NOT NULL,
            `method` varchar(50) NOT NULL COMMENT 'whatsapp_message, web_form, admin, bulk_admin',
            `reason` varchar(255) NULL,
            `context_json` json NULL,
            `ip_address` varchar(45) NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_whatsapp_opt_outs_phone` (`phone_number`),
            KEY `idx_whatsapp_opt_outs_method` (`method`),
            KEY `idx_whatsapp_opt_outs_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    try {
        Database::exec($createTableQuery);
    } catch (Exception $e) {
        // Table might already exist, ignore error
    }
}
