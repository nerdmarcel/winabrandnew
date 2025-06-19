<?php
declare(strict_types=1);

/**
 * File: core/WhatsAppAPI.php
 * Location: core/WhatsAppAPI.php
 *
 * WinABN WhatsApp Business API Integration
 *
 * Handles WhatsApp message sending through Meta Business API with rate limiting,
 * template management, and queue processing for the WinABN platform.
 *
 * @package WinABN\Core
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Core;

use Exception;
use CurlHandle;

class WhatsAppAPI
{
    /**
     * WhatsApp Business API configuration
     *
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * API base URL
     *
     * @var string
     */
    private string $baseUrl;

    /**
     * Rate limiter instance
     *
     * @var RateLimiter
     */
    private RateLimiter $rateLimiter;

    /**
     * Message templates
     *
     * @var array<string, array>
     */
    private array $templates = [];

    /**
     * Constructor
     *
     * @param array<string, mixed>|null $config Custom configuration
     */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? [
            'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
            'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
            'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
            'rate_limit_per_minute' => (int) env('WHATSAPP_RATE_LIMIT_PER_MINUTE', 10),
            'enabled' => env('WHATSAPP_ENABLED', true)
        ];

        $this->baseUrl = "https://graph.facebook.com/v18.0/{$this->config['phone_number_id']}/messages";
        $this->rateLimiter = new RateLimiter($this->config['rate_limit_per_minute']);
        $this->loadTemplates();
    }

    /**
     * Send WhatsApp message using template
     *
     * @param string $phoneNumber Recipient phone number (international format)
     * @param string $templateName Template name
     * @param array<string, mixed> $variables Template variables
     * @param int $priority Message priority (1=highest, 10=lowest)
     * @return array<string, mixed> Send result
     */
    public function sendTemplateMessage(
        string $phoneNumber,
        string $templateName,
        array $variables = [],
        int $priority = 5
    ): array {
        if (!$this->config['enabled']) {
            return [
                'success' => false,
                'error' => 'WhatsApp API is disabled',
                'message_id' => null
            ];
        }

        try {
            // Validate phone number
            $phoneNumber = $this->formatPhoneNumber($phoneNumber);

            // Check rate limit
            if (!$this->rateLimiter->allow()) {
                return $this->queueMessage($phoneNumber, $templateName, $variables, $priority);
            }

            // Get template
            $template = $this->getTemplate($templateName);
            if (!$template) {
                throw new Exception("Template not found: $templateName");
            }

            // Build message payload
            $payload = $this->buildTemplatePayload($phoneNumber, $template, $variables);

            // Send message
            $response = $this->sendRequest($payload);

            if ($response['success']) {
                $this->logMessage($phoneNumber, $templateName, 'sent', $response['message_id']);
                return [
                    'success' => true,
                    'message_id' => $response['message_id'],
                    'phone_number' => $phoneNumber
                ];
            } else {
                throw new Exception($response['error'] ?? 'Unknown error');
            }

        } catch (Exception $e) {
            $this->logError('WhatsApp message failed', [
                'phone' => $phoneNumber,
                'template' => $templateName,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'message_id' => null
            ];
        }
    }

    /**
     * Queue message for later sending
     *
     * @param string $phoneNumber Recipient phone number
     * @param string $templateName Template name
     * @param array<string, mixed> $variables Template variables
     * @param int $priority Message priority
     * @return array<string, mixed>
     */
    public function queueMessage(
        string $phoneNumber,
        string $templateName,
        array $variables = [],
        int $priority = 5
    ): array {
        try {
            $query = "
                INSERT INTO whatsapp_queue
                (to_phone, message_template, variables_json, message_type, priority, send_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ";

            $messageType = $this->getMessageTypeFromTemplate($templateName);

            Database::execute($query, [
                $phoneNumber,
                $templateName,
                json_encode($variables),
                $messageType,
                $priority
            ]);

            return [
                'success' => true,
                'queued' => true,
                'message_id' => null,
                'queue_id' => Database::lastInsertId()
            ];

        } catch (Exception $e) {
            $this->logError('WhatsApp queue failed', [
                'phone' => $phoneNumber,
                'template' => $templateName,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to queue message: ' . $e->getMessage(),
                'message_id' => null
            ];
        }
    }

    /**
     * Process queued messages
     *
     * @param int $batchSize Number of messages to process
     * @return array<string, mixed> Processing results
     */
    public function processQueue(int $batchSize = 10): array
    {
        $processed = 0;
        $successful = 0;
        $failed = 0;
        $errors = [];

        try {
            // Get pending messages ordered by priority and send_at
            $query = "
                SELECT id, to_phone, message_template, variables_json, priority, attempts
                FROM whatsapp_queue
                WHERE status = 'pending'
                AND send_at <= NOW()
                AND attempts < max_attempts
                ORDER BY priority ASC, send_at ASC
                LIMIT ?
            ";

            $messages = Database::fetchAll($query, [$batchSize]);

            foreach ($messages as $message) {
                $processed++;

                if (!$this->rateLimiter->allow()) {
                    // Rate limit reached, stop processing
                    break;
                }

                $result = $this->processQueuedMessage($message);

                if ($result['success']) {
                    $successful++;
                } else {
                    $failed++;
                    $errors[] = [
                        'queue_id' => $message['id'],
                        'error' => $result['error']
                    ];
                }
            }

            return [
                'processed' => $processed,
                'successful' => $successful,
                'failed' => $failed,
                'errors' => $errors,
                'rate_limit_reached' => !$this->rateLimiter->allow()
            ];

        } catch (Exception $e) {
            $this->logError('Queue processing failed', ['error' => $e->getMessage()]);

            return [
                'processed' => $processed,
                'successful' => $successful,
                'failed' => $failed,
                'errors' => array_merge($errors, [['error' => $e->getMessage()]]),
                'rate_limit_reached' => false
            ];
        }
    }

    /**
     * Process individual queued message
     *
     * @param array<string, mixed> $message Queued message data
     * @return array<string, mixed>
     */
    private function processQueuedMessage(array $message): array
    {
        try {
            // Update status to processing
            $this->updateQueueStatus($message['id'], 'processing');

            // Decode variables
            $variables = json_decode($message['variables_json'], true) ?? [];

            // Send message
            $result = $this->sendTemplateMessage(
                $message['to_phone'],
                $message['message_template'],
                $variables,
                (int) $message['priority']
            );

            if ($result['success'] && !($result['queued'] ?? false)) {
                // Message sent successfully
                $this->updateQueueStatus($message['id'], 'sent', $result['message_id']);
                return ['success' => true];
            } else {
                // Failed to send, increment attempts
                $this->incrementQueueAttempts($message['id'], $result['error'] ?? 'Unknown error');
                return ['success' => false, 'error' => $result['error'] ?? 'Unknown error'];
            }

        } catch (Exception $e) {
            $this->incrementQueueAttempts($message['id'], $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send winner notification
     *
     * @param array<string, mixed> $participant Winner participant data
     * @param string $claimToken Secure claim token
     * @return array<string, mixed>
     */
    public function sendWinnerNotification(array $participant, string $claimToken): array
    {
        if (!$participant['whatsapp_consent']) {
            return ['success' => false, 'error' => 'No WhatsApp consent'];
        }

        $variables = [
            'first_name' => $participant['first_name'],
            'prize_name' => $this->getPrizeName($participant),
            'claim_url' => url("claim/$claimToken")
        ];

        return $this->sendTemplateMessage(
            $participant['phone'],
            'winner_notification',
            $variables,
            1 // Highest priority
        );
    }

    /**
     * Send non-winner notification with replay link
     *
     * @param array<string, mixed> $participant Participant data
     * @param string $gameSlug Game slug for replay link
     * @return array<string, mixed>
     */
    public function sendNonWinnerNotification(array $participant, string $gameSlug): array
    {
        if (!$participant['whatsapp_consent']) {
            return ['success' => false, 'error' => 'No WhatsApp consent'];
        }

        $replayUrl = url("win-a-$gameSlug", [
            'src' => 'whatsapp_retry',
            'ref' => $participant['id']
        ]);

        $variables = [
            'first_name' => $participant['first_name'],
            'replay_url' => $replayUrl
        ];

        return $this->sendTemplateMessage(
            $participant['phone'],
            'non_winner_notification',
            $variables,
            3 // Medium priority
        );
    }

    /**
     * Send weekly promotion
     *
     * @param string $phoneNumber Recipient phone number
     * @param string $firstName Recipient first name
     * @param array<array> $newGames New games data
     * @return array<string, mixed>
     */
    public function sendWeeklyPromotion(string $phoneNumber, string $firstName, array $newGames): array
    {
        $gamesList = '';
        foreach ($newGames as $game) {
            $gamesList .= "🎁 {$game['name']} - £{$game['prize_value']}\n";
        }

        $variables = [
            'first_name' => $firstName,
            'games_list' => trim($gamesList),
            'website_url' => env('APP_URL')
        ];

        return $this->sendTemplateMessage(
            $phoneNumber,
            'weekly_promotion',
            $variables,
            5 // Normal priority
        );
    }

    /**
     * Verify webhook request
     *
     * @param string $mode Webhook mode
     * @param string $token Verification token
     * @param string $challenge Challenge string
     * @return string|null Challenge response or null if invalid
     */
    public function verifyWebhook(string $mode, string $token, string $challenge): ?string
    {
        if ($mode === 'subscribe' && $token === $this->config['webhook_verify_token']) {
            return $challenge;
        }

        return null;
    }

    /**
     * Process webhook payload
     *
     * @param array<string, mixed> $payload Webhook payload
     * @return array<string, mixed> Processing result
     */
    public function processWebhook(array $payload): array
    {
        try {
            if (!isset($payload['entry'][0]['changes'][0]['value'])) {
                return ['success' => false, 'error' => 'Invalid payload structure'];
            }

            $value = $payload['entry'][0]['changes'][0]['value'];

            // Process different webhook types
            if (isset($value['messages'])) {
                return $this->processIncomingMessages($value['messages']);
            }

            if (isset($value['statuses'])) {
                return $this->processMessageStatuses($value['statuses']);
            }

            return ['success' => true, 'processed' => 0];

        } catch (Exception $e) {
            $this->logError('Webhook processing failed', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get message delivery statistics
     *
     * @param int $days Number of days to analyze
     * @return array<string, mixed>
     */
    public function getDeliveryStats(int $days = 7): array
    {
        $query = "
            SELECT
                status,
                COUNT(*) as count,
                DATE(created_at) as date
            FROM whatsapp_queue
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY status, DATE(created_at)
            ORDER BY date DESC
        ";

        $results = Database::fetchAll($query, [$days]);

        $stats = [
            'total_messages' => 0,
            'sent' => 0,
            'failed' => 0,
            'pending' => 0,
            'daily_breakdown' => []
        ];

        foreach ($results as $row) {
            $stats['total_messages'] += $row['count'];
            $stats[$row['status']] += $row['count'];

            if (!isset($stats['daily_breakdown'][$row['date']])) {
                $stats['daily_breakdown'][$row['date']] = [];
            }
            $stats['daily_breakdown'][$row['date']][$row['status']] = $row['count'];
        }

        return $stats;
    }

    /**
     * Load message templates
     *
     * @return void
     */
    private function loadTemplates(): void
    {
        $this->templates = [
            'winner_notification' => [
                'name' => 'winner_notification',
                'language' => 'en_GB',
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text'], // first_name
                            ['type' => 'text'], // prize_name
                            ['type' => 'text']  // claim_url
                        ]
                    ]
                ]
            ],
            'non_winner_notification' => [
                'name' => 'non_winner_notification',
                'language' => 'en_GB',
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text'], // first_name
                            ['type' => 'text']  // replay_url
                        ]
                    ]
                ]
            ],
            'weekly_promotion' => [
                'name' => 'weekly_promotion',
                'language' => 'en_GB',
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text'], // first_name
                            ['type' => 'text'], // games_list
                            ['type' => 'text']  // website_url
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * Get template configuration
     *
     * @param string $templateName Template name
     * @return array<string, mixed>|null
     */
    private function getTemplate(string $templateName): ?array
    {
        return $this->templates[$templateName] ?? null;
    }

    /**
     * Build template message payload
     *
     * @param string $phoneNumber Recipient phone number
     * @param array<string, mixed> $template Template configuration
     * @param array<string, mixed> $variables Template variables
     * @return array<string, mixed>
     */
    private function buildTemplatePayload(string $phoneNumber, array $template, array $variables): array
    {
        $components = [];

        foreach ($template['components'] as $component) {
            if ($component['type'] === 'body' && isset($component['parameters'])) {
                $parameters = [];
                $variableIndex = 0;

                foreach ($component['parameters'] as $param) {
                    $variableKey = array_keys($variables)[$variableIndex] ?? null;
                    if ($variableKey && isset($variables[$variableKey])) {
                        $parameters[] = [
                            'type' => $param['type'],
                            'text' => (string) $variables[$variableKey]
                        ];
                    }
                    $variableIndex++;
                }

                $components[] = [
                    'type' => 'body',
                    'parameters' => $parameters
                ];
            }
        }

        return [
            'messaging_product' => 'whatsapp',
            'to' => $phoneNumber,
            'type' => 'template',
            'template' => [
                'name' => $template['name'],
                'language' => [
                    'code' => $template['language']
                ],
                'components' => $components
            ]
        ];
    }

    /**
     * Send HTTP request to WhatsApp API
     *
     * @param array<string, mixed> $payload Request payload
     * @return array<string, mixed>
     * @throws Exception
     */
    private function sendRequest(array $payload): array
    {
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $this->baseUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->config['access_token'],
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);

        curl_close($curl);

        if ($error) {
            throw new Exception("cURL error: $error");
        }

        $data = json_decode($response, true);

        if ($httpCode === 200 && isset($data['messages'][0]['id'])) {
            return [
                'success' => true,
                'message_id' => $data['messages'][0]['id']
            ];
        } else {
            $errorMessage = $data['error']['message'] ?? 'Unknown API error';
            return [
                'success' => false,
                'error' => $errorMessage,
                'http_code' => $httpCode
            ];
        }
    }

    /**
     * Format phone number to international format
     *
     * @param string $phoneNumber Raw phone number
     * @return string Formatted phone number
     * @throws Exception
     */
    private function formatPhoneNumber(string $phoneNumber): string
    {
        // Remove all non-numeric characters except +
        $cleaned = preg_replace('/[^\d+]/', '', $phoneNumber);

        // Add + if not present and starts with country code
        if (!str_starts_with($cleaned, '+')) {
            $cleaned = '+' . $cleaned;
        }

        // Validate format (basic validation)
        if (!preg_match('/^\+\d{10,15}$/', $cleaned)) {
            throw new Exception("Invalid phone number format: $phoneNumber");
        }

        return $cleaned;
    }

    /**
     * Get message type from template name
     *
     * @param string $templateName Template name
     * @return string Message type
     */
    private function getMessageTypeFromTemplate(string $templateName): string
    {
        $mapping = [
            'winner_notification' => 'winner_notification',
            'non_winner_notification' => 'loser_notification',
            'weekly_promotion' => 'promotion'
        ];

        return $mapping[$templateName] ?? 'general';
    }

    /**
     * Get prize name for participant
     *
     * @param array<string, mixed> $participant Participant data
     * @return string Prize name
     */
    private function getPrizeName(array $participant): string
    {
        // This would typically come from the game data
        // For now, return a generic message
        return 'Your Prize';
    }

    /**
     * Update queue message status
     *
     * @param int $queueId Queue message ID
     * @param string $status New status
     * @param string|null $messageId WhatsApp message ID
     * @return void
     */
    private function updateQueueStatus(int $queueId, string $status, ?string $messageId = null): void
    {
        $query = "
            UPDATE whatsapp_queue
            SET status = ?, updated_at = NOW()";

        $params = [$status];

        if ($status === 'sent' && $messageId) {
            $query .= ", sent_at = NOW()";
        }

        $query .= " WHERE id = ?";
        $params[] = $queueId;

        Database::execute($query, $params);
    }

    /**
     * Increment queue message attempts
     *
     * @param int $queueId Queue message ID
     * @param string $errorMessage Error message
     * @return void
     */
    private function incrementQueueAttempts(int $queueId, string $errorMessage): void
    {
        $query = "
            UPDATE whatsapp_queue
            SET attempts = attempts + 1,
                error_message = ?,
                status = CASE
                    WHEN attempts + 1 >= max_attempts THEN 'failed'
                    ELSE 'pending'
                END,
                send_at = CASE
                    WHEN attempts + 1 < max_attempts THEN DATE_ADD(NOW(), INTERVAL POW(2, attempts) MINUTE)
                    ELSE send_at
                END,
                updated_at = NOW()
            WHERE id = ?
        ";

        Database::execute($query, [$errorMessage, $queueId]);
    }

    /**
     * Process incoming messages (for unsubscribe, etc.)
     *
     * @param array<array> $messages Incoming messages
     * @return array<string, mixed>
     */
    private function processIncomingMessages(array $messages): array
    {
        $processed = 0;

        foreach ($messages as $message) {
            if (isset($message['text']['body'])) {
                $body = strtolower(trim($message['text']['body']));
                $fromPhone = $message['from'];

                // Handle unsubscribe requests
                if (in_array($body, ['stop', 'unsubscribe', 'opt out', 'remove'])) {
                    $this->handleUnsubscribe($fromPhone);
                    $processed++;
                }
            }
        }

        return ['success' => true, 'processed' => $processed];
    }

    /**
     * Process message delivery statuses
     *
     * @param array<array> $statuses Message statuses
     * @return array<string, mixed>
     */
    private function processMessageStatuses(array $statuses): array
    {
        $processed = 0;

        foreach ($statuses as $status) {
            if (isset($status['id']) && isset($status['status'])) {
                // Update message status in queue if needed
                // This helps track delivery success/failure
                $processed++;
            }
        }

        return ['success' => true, 'processed' => $processed];
    }

    /**
     * Handle unsubscribe request
     *
     * @param string $phoneNumber Phone number to unsubscribe
     * @return void
     */
    private function handleUnsubscribe(string $phoneNumber): void
    {
        try {
            // Update all participants to opt out of WhatsApp
            $query = "
                UPDATE participants
                SET whatsapp_consent = 0
                WHERE phone = ?
            ";

            Database::execute($query, [$phoneNumber]);

            $this->logMessage($phoneNumber, 'unsubscribe', 'processed');

        } catch (Exception $e) {
            $this->logError('Unsubscribe failed', [
                'phone' => $phoneNumber,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Log WhatsApp message
     *
     * @param string $phoneNumber Phone number
     * @param string $template Template name
     * @param string $status Message status
     * @param string|null $messageId Message ID
     * @return void
     */
    private function logMessage(string $phoneNumber, string $template, string $status, ?string $messageId = null): void
    {
        if (function_exists('app_log')) {
            app_log('info', "WhatsApp message $status", [
                'phone' => $phoneNumber,
                'template' => $template,
                'message_id' => $messageId
            ]);
        }
    }

    /**
     * Log WhatsApp errors
     *
     * @param string $message Error message
     * @param array<string, mixed> $context Error context
     * @return void
     */
    private function logError(string $message, array $context = []): void
    {
        if (function_exists('app_log')) {
            app_log('error', $message, $context);
        }
    }
}

/**
 * Simple rate limiter for WhatsApp API
 */
class RateLimiter
{
    private int $maxRequests;
    private int $timeWindow = 60; // 1 minute
    private array $requests = [];

    public function __construct(int $maxRequests)
    {
        $this->maxRequests = $maxRequests;
    }

    public function allow(): bool
    {
        $now = time();

        // Remove old requests outside the time window
        $this->requests = array_filter($this->requests, function($timestamp) use ($now) {
            return ($now - $timestamp) < $this->timeWindow;
        });

        // Check if we can make another request
        if (count($this->requests) < $this->maxRequests) {
            $this->requests[] = $now;
            return true;
        }

        return false;
    }

    public function getRemaining(): int
    {
        $now = time();
        $this->requests = array_filter($this->requests, function($timestamp) use ($now) {
            return ($now - $timestamp) < $this->timeWindow;
        });

        return max(0, $this->maxRequests - count($this->requests));
    }
}
