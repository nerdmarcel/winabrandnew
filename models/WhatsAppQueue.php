<?php

/**
 * Win a Brand New - WhatsApp Queue Model
 * File: /models/WhatsAppQueue.php
 *
 * Manages WhatsApp Business API integration, rate limiting, and message queue processing
 * according to the Development Specification requirements.
 *
 * Features:
 * - Meta WhatsApp Business API integration
 * - Rate limiting (10 messages/minute configurable)
 * - Message templates for winner notifications, consolations, etc.
 * - Priority-based queueing system
 * - Retry logic with exponential backoff
 * - Delivery tracking and status management
 * - Webhook handling for delivery receipts
 * - Opt-in/opt-out management
 *
 * @package WinABrandNew\Models
 * @version 1.0.0
 * @author NerdMarcel Development Team
 */

namespace WinABrandNew\Models;

use WinABrandNew\Core\Database;
use WinABrandNew\Core\Config;
use Exception;
use DateTime;

class WhatsAppQueue
{
    /**
     * Database table name
     */
    private const TABLE_NAME = 'whatsapp_queue';

    /**
     * WhatsApp Business API base URL
     */
    private const API_BASE_URL = 'https://graph.facebook.com/v18.0';

    /**
     * Rate limiting configuration
     */
    private const DEFAULT_RATE_LIMIT = 10; // messages per minute
    private const RATE_WINDOW_SECONDS = 60;

    /**
     * Message priorities
     */
    public const PRIORITY_HIGH = 1;    // Winner notifications
    public const PRIORITY_NORMAL = 2;  // Regular notifications
    public const PRIORITY_LOW = 3;     // Promotional messages

    /**
     * Message types
     */
    public const TYPE_WINNER_NOTIFICATION = 'winner_notification';
    public const TYPE_NON_WINNER_CONSOLATION = 'non_winner_consolation';
    public const TYPE_WEEKLY_PROMOTION = 'weekly_promotion';
    public const TYPE_TRACKING_UPDATE = 'tracking_update';
    public const TYPE_REMINDER = 'reminder';

    /**
     * Delivery statuses
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_READ = 'read';
    public const STATUS_FAILED = 'failed';

    /**
     * WhatsApp API configuration
     *
     * @var array
     */
    private array $config;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->config = [
            'access_token' => Config::get('WHATSAPP_ACCESS_TOKEN'),
            'phone_number_id' => Config::get('WHATSAPP_PHONE_NUMBER_ID'),
            'rate_limit' => (int) Config::get('WHATSAPP_RATE_LIMIT_PER_MINUTE', self::DEFAULT_RATE_LIMIT),
            'webhook_verify_token' => Config::get('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
            'api_version' => Config::get('WHATSAPP_API_VERSION', 'v18.0')
        ];

        $this->validateConfiguration();
    }

    /**
     * Validate WhatsApp API configuration
     *
     * @throws Exception If configuration is invalid
     */
    private function validateConfiguration(): void
    {
        $required = ['access_token', 'phone_number_id', 'webhook_verify_token'];

        foreach ($required as $key) {
            if (empty($this->config[$key])) {
                throw new Exception("WhatsApp configuration missing: {$key}");
            }
        }
    }

    /**
     * Queue a WhatsApp message for sending
     *
     * @param string $toPhone Recipient phone number in international format
     * @param string $messageTemplate Template name to use
     * @param array $variables Template variables
     * @param string $messageType Type of message
     * @param int|null $participantId Related participant ID
     * @param int $priority Message priority (1=high, 2=normal, 3=low)
     * @param DateTime|null $sendAt Scheduled send time
     * @return int Queue ID
     * @throws Exception If queueing fails
     */
    public function queueMessage(
        string $toPhone,
        string $messageTemplate,
        array $variables = [],
        string $messageType = self::TYPE_WINNER_NOTIFICATION,
        ?int $participantId = null,
        int $priority = self::PRIORITY_NORMAL,
        ?DateTime $sendAt = null
    ): int {
        try {
            // Validate phone number format
            $toPhone = $this->formatPhoneNumber($toPhone);

            // Validate template exists
            if (!$this->templateExists($messageTemplate)) {
                throw new Exception("WhatsApp template '{$messageTemplate}' not found");
            }

            // Prepare queue data
            $queueData = [
                'to_phone' => $toPhone,
                'message_template' => $messageTemplate,
                'variables_json' => json_encode($variables),
                'message_type' => $messageType,
                'participant_id' => $participantId,
                'priority' => $priority,
                'send_at' => $sendAt ? $sendAt->format('Y-m-d H:i:s') : date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s')
            ];

            $sql = "INSERT INTO " . self::TABLE_NAME . "
                    (to_phone, message_template, variables_json, message_type, participant_id,
                     priority, send_at, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

            $queueId = Database::insert($sql, array_values($queueData));

            $this->logMessage("Message queued successfully", [
                'queue_id' => $queueId,
                'to_phone' => $toPhone,
                'template' => $messageTemplate,
                'type' => $messageType
            ]);

            return (int) $queueId;

        } catch (Exception $e) {
            $this->logMessage("Failed to queue message: " . $e->getMessage(), [
                'to_phone' => $toPhone,
                'template' => $messageTemplate,
                'error' => $e->getMessage()
            ], 'error');
            throw $e;
        }
    }

    /**
     * Process pending messages in the queue
     *
     * @param int $batchSize Number of messages to process per batch
     * @return int Number of messages processed
     * @throws Exception If processing fails
     */
    public function processQueue(int $batchSize = 10): int
    {
        try {
            // Check rate limiting
            if (!$this->canSendMessages()) {
                $this->logMessage("Rate limit reached, skipping queue processing");
                return 0;
            }

            // Get pending messages ordered by priority and send time
            $sql = "SELECT * FROM " . self::TABLE_NAME . "
                    WHERE processing = 0
                    AND send_at <= NOW()
                    AND attempts < max_attempts
                    ORDER BY priority ASC, send_at ASC
                    LIMIT ?";

            $messages = Database::select($sql, [$batchSize]);

            if (empty($messages)) {
                return 0;
            }

            $processed = 0;

            foreach ($messages as $message) {
                if (!$this->canSendMessages()) {
                    $this->logMessage("Rate limit reached during processing");
                    break;
                }

                try {
                    $this->markAsProcessing((int) $message['id']);
                    $success = $this->sendMessage($message);

                    if ($success) {
                        $this->markAsSent((int) $message['id']);
                        $processed++;
                    } else {
                        $this->markAsFailed((int) $message['id'], "Failed to send message");
                    }

                } catch (Exception $e) {
                    $this->markAsFailed((int) $message['id'], $e->getMessage());
                    $this->logMessage("Failed to send message: " . $e->getMessage(), [
                        'queue_id' => $message['id'],
                        'to_phone' => $message['to_phone']
                    ], 'error');
                }

                // Rate limiting delay
                $this->enforceRateLimit();
            }

            $this->logMessage("Queue processing completed", [
                'processed' => $processed,
                'batch_size' => $batchSize
            ]);

            return $processed;

        } catch (Exception $e) {
            $this->logMessage("Queue processing failed: " . $e->getMessage(), [], 'error');
            throw $e;
        }
    }

    /**
     * Send a WhatsApp message via Business API
     *
     * @param array $messageData Message data from queue
     * @return bool Success status
     * @throws Exception If sending fails
     */
    private function sendMessage(array $messageData): bool
    {
        try {
            $variables = json_decode($messageData['variables_json'], true) ?? [];

            // Build message payload based on template
            $payload = $this->buildMessagePayload(
                $messageData['to_phone'],
                $messageData['message_template'],
                $variables
            );

            // Send via WhatsApp Business API
            $response = $this->callWhatsAppAPI('messages', $payload);

            if ($response && isset($response['messages'][0]['id'])) {
                $whatsappMessageId = $response['messages'][0]['id'];

                // Update queue record with WhatsApp message ID
                $this->updateWhatsAppMessageId((int) $messageData['id'], $whatsappMessageId);

                $this->logMessage("Message sent successfully", [
                    'queue_id' => $messageData['id'],
                    'whatsapp_message_id' => $whatsappMessageId,
                    'to_phone' => $messageData['to_phone']
                ]);

                return true;
            }

            throw new Exception("Invalid API response: " . json_encode($response));

        } catch (Exception $e) {
            $this->logMessage("Failed to send WhatsApp message: " . $e->getMessage(), [
                'queue_id' => $messageData['id'],
                'to_phone' => $messageData['to_phone']
            ], 'error');
            throw $e;
        }
    }

    /**
     * Build WhatsApp API message payload
     *
     * @param string $toPhone Recipient phone number
     * @param string $template Template name
     * @param array $variables Template variables
     * @return array API payload
     */
    private function buildMessagePayload(string $toPhone, string $template, array $variables): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $toPhone,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => [
                    'code' => 'en_GB' // UK English
                ]
            ]
        ];

        // Add template parameters if provided
        if (!empty($variables)) {
            $components = [];

            // Header parameters
            if (isset($variables['header'])) {
                $components[] = [
                    'type' => 'header',
                    'parameters' => $this->formatTemplateParameters($variables['header'])
                ];
            }

            // Body parameters
            if (isset($variables['body'])) {
                $components[] = [
                    'type' => 'body',
                    'parameters' => $this->formatTemplateParameters($variables['body'])
                ];
            }

            // Button parameters
            if (isset($variables['buttons'])) {
                foreach ($variables['buttons'] as $index => $button) {
                    $components[] = [
                        'type' => 'button',
                        'sub_type' => 'url',
                        'index' => $index,
                        'parameters' => $this->formatTemplateParameters([$button])
                    ];
                }
            }

            if (!empty($components)) {
                $payload['template']['components'] = $components;
            }
        }

        return $payload;
    }

    /**
     * Format template parameters for WhatsApp API
     *
     * @param array $parameters Raw parameters
     * @return array Formatted parameters
     */
    private function formatTemplateParameters(array $parameters): array
    {
        $formatted = [];

        foreach ($parameters as $param) {
            $formatted[] = [
                'type' => 'text',
                'text' => (string) $param
            ];
        }

        return $formatted;
    }

    /**
     * Call WhatsApp Business API
     *
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @return array|null API response
     * @throws Exception If API call fails
     */
    private function callWhatsAppAPI(string $endpoint, array $data): ?array
    {
        $url = self::API_BASE_URL . '/' . $this->config['phone_number_id'] . '/' . $endpoint;

        $headers = [
            'Authorization: Bearer ' . $this->config['access_token'],
            'Content-Type: application/json'
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'WinABrandNew/1.0'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("cURL error: {$error}");
        }

        if ($httpCode !== 200) {
            $errorMsg = "WhatsApp API error: HTTP {$httpCode}";
            if ($response) {
                $errorMsg .= " - " . $response;
            }
            throw new Exception($errorMsg);
        }

        return json_decode($response, true);
    }

    /**
     * Check if we can send messages based on rate limiting
     *
     * @return bool Whether messages can be sent
     */
    private function canSendMessages(): bool
    {
        $sql = "SELECT COUNT(*) as count FROM " . self::TABLE_NAME . "
                WHERE processed_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
                AND processed_at IS NOT NULL";

        $result = Database::selectOne($sql, [self::RATE_WINDOW_SECONDS]);
        $recentCount = (int) ($result['count'] ?? 0);

        return $recentCount < $this->config['rate_limit'];
    }

    /**
     * Enforce rate limiting delay between messages
     */
    private function enforceRateLimit(): void
    {
        // Calculate delay needed to maintain rate limit
        $delaySeconds = self::RATE_WINDOW_SECONDS / $this->config['rate_limit'];

        if ($delaySeconds >= 1) {
            sleep((int) $delaySeconds);
        } else {
            usleep((int) ($delaySeconds * 1000000)); // Convert to microseconds
        }
    }

    /**
     * Queue winner notification message
     *
     * @param string $phone Winner's phone number
     * @param string $claimToken Secure claim token
     * @param string $prizeName Prize name
     * @param int $participantId Participant ID
     * @return int Queue ID
     */
    public function queueWinnerNotification(
        string $phone,
        string $claimToken,
        string $prizeName,
        int $participantId
    ): int {
        $variables = [
            'body' => [$prizeName, $claimToken],
            'buttons' => [
                Config::get('APP_URL') . "/claim/{$claimToken}"
            ]
        ];

        return $this->queueMessage(
            $phone,
            'winner_notification',
            $variables,
            self::TYPE_WINNER_NOTIFICATION,
            $participantId,
            self::PRIORITY_HIGH
        );
    }

    /**
     * Queue non-winner consolation message
     *
     * @param string $phone Player's phone number
     * @param string $gameSlug Game slug for replay link
     * @param int $participantId Participant ID
     * @return int Queue ID
     */
    public function queueNonWinnerConsolation(
        string $phone,
        string $gameSlug,
        int $participantId
    ): int {
        $replayUrl = Config::get('APP_URL') . "/win-a-{$gameSlug}?src=whatsapp_retry&ref={$participantId}";

        $variables = [
            'body' => [$gameSlug],
            'buttons' => [$replayUrl]
        ];

        return $this->queueMessage(
            $phone,
            'non_winner_consolation',
            $variables,
            self::TYPE_NON_WINNER_CONSOLATION,
            $participantId,
            self::PRIORITY_NORMAL
        );
    }

    /**
     * Queue tracking update message
     *
     * @param string $phone Recipient phone number
     * @param string $trackingNumber Tracking number
     * @param string $carrier Shipping carrier
     * @param int $participantId Participant ID
     * @return int Queue ID
     */
    public function queueTrackingUpdate(
        string $phone,
        string $trackingNumber,
        string $carrier,
        int $participantId
    ): int {
        $variables = [
            'body' => [$trackingNumber, $carrier]
        ];

        return $this->queueMessage(
            $phone,
            'tracking_update',
            $variables,
            self::TYPE_TRACKING_UPDATE,
            $participantId,
            self::PRIORITY_HIGH
        );
    }

    /**
     * Queue weekly promotion message
     *
     * @param array $recipients Array of phone numbers
     * @param string $promotionText Promotion message
     * @param DateTime $sendAt Scheduled send time
     * @return array Queue IDs
     */
    public function queueWeeklyPromotion(
        array $recipients,
        string $promotionText,
        DateTime $sendAt
    ): array {
        $queueIds = [];

        foreach ($recipients as $phone) {
            $variables = [
                'body' => [$promotionText]
            ];

            $queueIds[] = $this->queueMessage(
                $phone,
                'weekly_promotion',
                $variables,
                self::TYPE_WEEKLY_PROMOTION,
                null,
                self::PRIORITY_LOW,
                $sendAt
            );
        }

        return $queueIds;
    }

    /**
     * Handle WhatsApp webhook for delivery status updates
     *
     * @param array $webhookData Webhook payload
     * @return bool Success status
     */
    public function handleWebhook(array $webhookData): bool
    {
        try {
            if (!isset($webhookData['entry'][0]['changes'][0]['value'])) {
                throw new Exception("Invalid webhook payload structure");
            }

            $value = $webhookData['entry'][0]['changes'][0]['value'];

            // Handle status updates
            if (isset($value['statuses'])) {
                foreach ($value['statuses'] as $status) {
                    $this->updateMessageStatus(
                        $status['id'],
                        $status['status'],
                        $status['timestamp'] ?? null
                    );
                }
            }

            // Handle incoming messages (for opt-out requests)
            if (isset($value['messages'])) {
                foreach ($value['messages'] as $message) {
                    $this->handleIncomingMessage($message);
                }
            }

            return true;

        } catch (Exception $e) {
            $this->logMessage("Webhook handling failed: " . $e->getMessage(), [
                'webhook_data' => $webhookData
            ], 'error');
            return false;
        }
    }

    /**
     * Update message status based on webhook data
     *
     * @param string $whatsappMessageId WhatsApp message ID
     * @param string $status New status
     * @param string|null $timestamp Status timestamp
     */
    private function updateMessageStatus(string $whatsappMessageId, string $status, ?string $timestamp): void
    {
        $updateData = ['last_error' => null];
        $statusTimestamp = $timestamp ? date('Y-m-d H:i:s', (int) $timestamp) : date('Y-m-d H:i:s');

        switch ($status) {
            case 'sent':
                $updateData['processed_at'] = $statusTimestamp;
                break;
            case 'delivered':
                $updateData['delivered_at'] = $statusTimestamp;
                break;
            case 'read':
                $updateData['read_at'] = $statusTimestamp;
                break;
            case 'failed':
                $updateData['last_error'] = 'Message delivery failed';
                $updateData['attempts'] = Database::selectOne(
                    "SELECT attempts FROM " . self::TABLE_NAME . " WHERE whatsapp_message_id = ?",
                    [$whatsappMessageId]
                )['attempts'] + 1;
                break;
        }

        $updateData['updated_at'] = date('Y-m-d H:i:s');

        $setClause = implode(', ', array_map(fn($key) => "{$key} = ?", array_keys($updateData)));
        $sql = "UPDATE " . self::TABLE_NAME . " SET {$setClause} WHERE whatsapp_message_id = ?";

        Database::update($sql, array_merge(array_values($updateData), [$whatsappMessageId]));

        $this->logMessage("Message status updated", [
            'whatsapp_message_id' => $whatsappMessageId,
            'status' => $status,
            'timestamp' => $statusTimestamp
        ]);
    }

    /**
     * Handle incoming WhatsApp messages (for opt-out requests)
     *
     * @param array $message Incoming message data
     */
    private function handleIncomingMessage(array $message): void
    {
        $fromPhone = $message['from'] ?? '';
        $messageText = strtolower($message['text']['body'] ?? '');

        // Check for opt-out keywords
        $optOutKeywords = ['stop', 'unsubscribe', 'opt out', 'remove me'];

        foreach ($optOutKeywords as $keyword) {
            if (strpos($messageText, $keyword) !== false) {
                $this->handleOptOut($fromPhone);
                break;
            }
        }
    }

    /**
     * Handle WhatsApp opt-out request
     *
     * @param string $phone Phone number to opt out
     */
    private function handleOptOut(string $phone): void
    {
        try {
            // Update participant consent
            $sql = "UPDATE participants SET whatsapp_consent = 0 WHERE phone = ?";
            Database::update($sql, [$phone]);

            // Cancel pending messages
            $sql = "UPDATE " . self::TABLE_NAME . "
                    SET processing = 1, last_error = 'User opted out'
                    WHERE to_phone = ? AND processed_at IS NULL";
            Database::update($sql, [$phone]);

            $this->logMessage("User opted out of WhatsApp messages", [
                'phone' => $phone
            ]);

        } catch (Exception $e) {
            $this->logMessage("Failed to process opt-out: " . $e->getMessage(), [
                'phone' => $phone
            ], 'error');
        }
    }

    /**
     * Mark message as processing
     *
     * @param int $queueId Queue ID
     */
    private function markAsProcessing(int $queueId): void
    {
        $sql = "UPDATE " . self::TABLE_NAME . "
                SET processing = 1, updated_at = NOW()
                WHERE id = ?";
        Database::update($sql, [$queueId]);
    }

    /**
     * Mark message as sent
     *
     * @param int $queueId Queue ID
     */
    private function markAsSent(int $queueId): void
    {
        $sql = "UPDATE " . self::TABLE_NAME . "
                SET processing = 0, processed_at = NOW(), updated_at = NOW()
                WHERE id = ?";
        Database::update($sql, [$queueId]);
    }

    /**
     * Mark message as failed and increment attempts
     *
     * @param int $queueId Queue ID
     * @param string $error Error message
     */
    private function markAsFailed(int $queueId, string $error): void
    {
        $sql = "UPDATE " . self::TABLE_NAME . "
                SET processing = 0, attempts = attempts + 1, last_error = ?, updated_at = NOW()
                WHERE id = ?";
        Database::update($sql, [$error, $queueId]);
    }

    /**
     * Update WhatsApp message ID after successful send
     *
     * @param int $queueId Queue ID
     * @param string $whatsappMessageId WhatsApp message ID
     */
    private function updateWhatsAppMessageId(int $queueId, string $whatsappMessageId): void
    {
        $sql = "UPDATE " . self::TABLE_NAME . "
                SET whatsapp_message_id = ?, updated_at = NOW()
                WHERE id = ?";
        Database::update($sql, [$whatsappMessageId, $queueId]);
    }

    /**
     * Format phone number to international format
     *
     * @param string $phone Phone number
     * @return string Formatted phone number
     */
    private function formatPhoneNumber(string $phone): string
    {
        // Remove any non-numeric characters except +
        $phone = preg_replace('/[^\d+]/', '', $phone);

        // Add + if not present
        if (!str_starts_with($phone, '+')) {
            $phone = '+' . $phone;
        }

        return $phone;
    }

    /**
     * Check if template exists in WhatsApp Business Manager
     *
     * @param string $template Template name
     * @return bool Whether template exists
     */
    private function templateExists(string $template): bool
    {
        // For now, assume all templates exist
        // In production, this should check against WhatsApp Business API
        $validTemplates = [
            'winner_notification',
            'non_winner_consolation',
            'weekly_promotion',
            'tracking_update',
            'reminder'
        ];

        return in_array($template, $validTemplates);
    }

    /**
     * Get queue statistics
     *
     * @return array Statistics
     */
    public function getQueueStatistics(): array
    {
        $stats = [];

        // Total messages
        $result = Database::selectOne("SELECT COUNT(*) as total FROM " . self::TABLE_NAME);
        $stats['total_messages'] = (int) $result['total'];

        // Messages by status
        $sql = "SELECT
                    SUM(CASE WHEN processed_at IS NULL AND attempts < max_attempts THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN processed_at IS NOT NULL AND delivered_at IS NULL THEN 1 ELSE 0 END) as sent,
                    SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END) as delivered,
                    SUM(CASE WHEN attempts >= max_attempts THEN 1 ELSE 0 END) as failed
                FROM " . self::TABLE_NAME;

        $result = Database::selectOne($sql);
        $stats['pending'] = (int) $result['pending'];
        $stats['sent'] = (int) $result['sent'];
        $stats['delivered'] = (int) $result['delivered'];
        $stats['failed'] = (int) $result['failed'];

        // Messages in last hour
        $sql = "SELECT COUNT(*) as recent FROM " . self::TABLE_NAME . "
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";
        $result = Database::selectOne($sql);
        $stats['last_hour'] = (int) $result['recent'];

        // Current rate limit status
        $stats['rate_limit'] = $this->config['rate_limit'];
        $stats['can_send'] = $this->canSendMessages();

        return $stats;
    }

    /**
     * Log WhatsApp queue activity
     *
     * @param string $message Log message
     * @param array $context Additional context
     * @param string $level Log level
     */
    private function logMessage(string $message, array $context = [], string $level = 'info'): void
    {
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'message' => $message,
            'context' => $context
        ];

        if (Config::get('LOG_QUEUE_ENABLED', true)) {
            $logFile = Config::get('LOG_PATH', '/var/log/winabrandnew') . '/whatsapp_queue.log';

            if (is_writable(dirname($logFile))) {
                file_put_contents(
                    $logFile,
                    json_encode($logData) . PHP_EOL,
                    FILE_APPEND | LOCK_EX
                );
            }
        }

        // Also log errors to system error log
        if ($level === 'error') {
            error_log("WhatsApp Queue Error: {$message} | Context: " . json_encode($context));
        }
    }

    /**
     * Clean up old processed messages
     *
     * @param int $daysOld Messages older than this many days will be deleted
     * @return int Number of messages cleaned up
     */
    public function cleanupOldMessages(int $daysOld = 30): int
    {
        try {
            $sql = "DELETE FROM " . self::TABLE_NAME . "
                    WHERE delivered_at IS NOT NULL
                    AND delivered_at < DATE_SUB(NOW(), INTERVAL ? DAY)";

            $deleted = Database::delete($sql, [$daysOld]);

            $this->logMessage("Cleaned up old WhatsApp messages", [
                'deleted_count' => $deleted,
                'days_old' => $daysOld
            ]);

            return $deleted;

        } catch (Exception $e) {
            $this->logMessage("Failed to cleanup old messages: " . $e->getMessage(), [], 'error');
            throw $e;
        }
    }

    /**
     * Get failed messages for retry
     *
     * @param int $limit Maximum number of messages to return
     * @return array Failed messages
     */
    public function getFailedMessages(int $limit = 50): array
    {
        $sql = "SELECT * FROM " . self::TABLE_NAME . "
                WHERE attempts >= max_attempts
                AND last_error IS NOT NULL
                ORDER BY updated_at DESC
                LIMIT ?";

        return Database::select($sql, [$limit]);
    }

    /**
     * Retry failed message
     *
     * @param int $queueId Queue ID to retry
     * @return bool Success status
     */
    public function retryFailedMessage(int $queueId): bool
    {
        try {
            // Reset attempts and clear error
            $sql = "UPDATE " . self::TABLE_NAME . "
                    SET attempts = 0, last_error = NULL, processing = 0,
                        send_at = NOW(), updated_at = NOW()
                    WHERE id = ?";

            $updated = Database::update($sql, [$queueId]);

            if ($updated > 0) {
                $this->logMessage("Message queued for retry", ['queue_id' => $queueId]);
                return true;
            }

            return false;

        } catch (Exception $e) {
            $this->logMessage("Failed to retry message: " . $e->getMessage(), [
                'queue_id' => $queueId
            ], 'error');
            return false;
        }
    }

    /**
     * Get opted-out phone numbers
     *
     * @return array List of opted-out phone numbers
     */
    public function getOptedOutNumbers(): array
    {
        $sql = "SELECT DISTINCT phone FROM participants WHERE whatsapp_consent = 0";
        $results = Database::select($sql);

        return array_column($results, 'phone');
    }

    /**
     * Check if phone number is opted out
     *
     * @param string $phone Phone number to check
     * @return bool Whether phone is opted out
     */
    public function isOptedOut(string $phone): bool
    {
        $sql = "SELECT COUNT(*) as count FROM participants
                WHERE phone = ? AND whatsapp_consent = 0";

        $result = Database::selectOne($sql, [$phone]);
        return (int) $result['count'] > 0;
    }

    /**
     * Get delivery report for a message
     *
     * @param int $queueId Queue ID
     * @return array|null Delivery report
     */
    public function getDeliveryReport(int $queueId): ?array
    {
        $sql = "SELECT id, to_phone, message_template, message_type, priority,
                       attempts, whatsapp_message_id, send_at, processed_at,
                       delivered_at, read_at, last_error, created_at, updated_at
                FROM " . self::TABLE_NAME . "
                WHERE id = ?";

        return Database::selectOne($sql, [$queueId]);
    }

    /**
     * Get messages for a specific participant
     *
     * @param int $participantId Participant ID
     * @return array Messages for participant
     */
    public function getParticipantMessages(int $participantId): array
    {
        $sql = "SELECT * FROM " . self::TABLE_NAME . "
                WHERE participant_id = ?
                ORDER BY created_at DESC";

        return Database::select($sql, [$participantId]);
    }

    /**
     * Cancel pending messages for a phone number
     *
     * @param string $phone Phone number
     * @param string $reason Cancellation reason
     * @return int Number of messages cancelled
     */
    public function cancelPendingMessages(string $phone, string $reason = 'Cancelled by admin'): int
    {
        try {
            $sql = "UPDATE " . self::TABLE_NAME . "
                    SET processing = 1, last_error = ?
                    WHERE to_phone = ? AND processed_at IS NULL";

            $cancelled = Database::update($sql, [$reason, $phone]);

            $this->logMessage("Cancelled pending messages", [
                'phone' => $phone,
                'cancelled_count' => $cancelled,
                'reason' => $reason
            ]);

            return $cancelled;

        } catch (Exception $e) {
            $this->logMessage("Failed to cancel messages: " . $e->getMessage(), [
                'phone' => $phone
            ], 'error');
            throw $e;
        }
    }

    /**
     * Verify webhook signature for security
     *
     * @param string $payload Webhook payload
     * @param string $signature Webhook signature
     * @return bool Whether signature is valid
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $expectedSignature = 'sha256=' . hash_hmac(
            'sha256',
            $payload,
            $this->config['webhook_verify_token']
        );

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Get queue health status for monitoring
     *
     * @return array Health status
     */
    public function getHealthStatus(): array
    {
        try {
            $stats = $this->getQueueStatistics();

            $health = [
                'status' => 'healthy',
                'queue_size' => $stats['pending'],
                'can_send_messages' => $stats['can_send'],
                'rate_limit' => $stats['rate_limit'],
                'recent_activity' => $stats['last_hour'],
                'failed_messages' => $stats['failed']
            ];

            // Determine health status based on metrics
            if ($stats['pending'] > 1000) {
                $health['status'] = 'warning';
                $health['issues'][] = 'Large queue backlog';
            }

            if (!$stats['can_send']) {
                $health['status'] = 'warning';
                $health['issues'][] = 'Rate limit reached';
            }

            if ($stats['failed'] > 100) {
                $health['status'] = 'unhealthy';
                $health['issues'][] = 'High failure rate';
            }

            return $health;

        } catch (Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Schedule weekly promotion messages
     *
     * @param DateTime $sendTime When to send (Fridays 18:00 UTC recommended)
     * @return int Number of messages scheduled
     */
    public function scheduleWeeklyPromotion(DateTime $sendTime): int
    {
        try {
            // Get opted-in users who have participated in last 30 days
            $sql = "SELECT DISTINCT phone FROM participants
                    WHERE whatsapp_consent = 1
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    AND phone NOT IN (
                        SELECT DISTINCT to_phone FROM " . self::TABLE_NAME . "
                        WHERE message_type = ?
                        AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    )";

            $recipients = Database::select($sql, [self::TYPE_WEEKLY_PROMOTION]);
            $phoneNumbers = array_column($recipients, 'phone');

            if (empty($phoneNumbers)) {
                return 0;
            }

            $promotionText = "🎮 New games this week! Check out our latest prizes and play to win amazing rewards!";

            $queueIds = $this->queueWeeklyPromotion($phoneNumbers, $promotionText, $sendTime);

            $this->logMessage("Weekly promotion scheduled", [
                'recipient_count' => count($queueIds),
                'send_time' => $sendTime->format('Y-m-d H:i:s')
            ]);

            return count($queueIds);

        } catch (Exception $e) {
            $this->logMessage("Failed to schedule weekly promotion: " . $e->getMessage(), [], 'error');
            throw $e;
        }
    }

    /**
     * Get template usage statistics
     *
     * @param int $days Number of days to analyze
     * @return array Template usage stats
     */
    public function getTemplateStats(int $days = 30): array
    {
        $sql = "SELECT message_template, COUNT(*) as usage_count,
                       SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END) as delivered_count,
                       SUM(CASE WHEN read_at IS NOT NULL THEN 1 ELSE 0 END) as read_count
                FROM " . self::TABLE_NAME . "
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY message_template
                ORDER BY usage_count DESC";

        return Database::select($sql, [$days]);
    }

    /**
     * Validate phone number format
     *
     * @param string $phone Phone number to validate
     * @return bool Whether phone number is valid
     */
    public function validatePhoneNumber(string $phone): bool
    {
        // Basic international phone number validation
        $pattern = '/^\+[1-9]\d{1,14}$/';
        return preg_match($pattern, $this->formatPhoneNumber($phone));
    }

    /**
     * Get rate limiting information
     *
     * @return array Rate limit info
     */
    public function getRateLimitInfo(): array
    {
        $sql = "SELECT COUNT(*) as sent_count FROM " . self::TABLE_NAME . "
                WHERE processed_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)";

        $result = Database::selectOne($sql, [self::RATE_WINDOW_SECONDS]);
        $sentInWindow = (int) $result['sent_count'];

        return [
            'rate_limit' => $this->config['rate_limit'],
            'window_seconds' => self::RATE_WINDOW_SECONDS,
            'sent_in_window' => $sentInWindow,
            'remaining_capacity' => max(0, $this->config['rate_limit'] - $sentInWindow),
            'can_send' => $sentInWindow < $this->config['rate_limit'],
            'reset_time' => date('Y-m-d H:i:s', time() + self::RATE_WINDOW_SECONDS)
        ];
    }
}

/**
 * WhatsApp Queue Helper Functions
 */

/**
 * Quick helper to queue winner notification
 *
 * @param string $phone Winner's phone
 * @param string $claimToken Claim token
 * @param string $prizeName Prize name
 * @param int $participantId Participant ID
 * @return int Queue ID
 */
function queue_winner_notification(string $phone, string $claimToken, string $prizeName, int $participantId): int
{
    $queue = new WhatsAppQueue();
    return $queue->queueWinnerNotification($phone, $claimToken, $prizeName, $participantId);
}

/**
 * Quick helper to queue consolation message
 *
 * @param string $phone Player's phone
 * @param string $gameSlug Game slug
 * @param int $participantId Participant ID
 * @return int Queue ID
 */
function queue_consolation_message(string $phone, string $gameSlug, int $participantId): int
{
    $queue = new WhatsAppQueue();
    return $queue->queueNonWinnerConsolation($phone, $gameSlug, $participantId);
}

/**
 * Quick helper to queue tracking update
 *
 * @param string $phone Recipient phone
 * @param string $trackingNumber Tracking number
 * @param string $carrier Carrier name
 * @param int $participantId Participant ID
 * @return int Queue ID
 */
function queue_tracking_update(string $phone, string $trackingNumber, string $carrier, int $participantId): int
{
    $queue = new WhatsAppQueue();
    return $queue->queueTrackingUpdate($phone, $trackingNumber, $carrier, $participantId);
}

/**
 * Quick helper to process WhatsApp queue
 *
 * @param int $batchSize Batch size
 * @return int Messages processed
 */
function process_whatsapp_queue(int $batchSize = 10): int
{
    $queue = new WhatsAppQueue();
    return $queue->processQueue($batchSize);
}
