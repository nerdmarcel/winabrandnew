<?php
declare(strict_types=1);

/**
 * File: public/webhook_whatsapp.php
 * Location: public/webhook_whatsapp.php
 *
 * WinABN WhatsApp Webhook Handler
 *
 * Handles incoming WhatsApp webhooks from Meta Business API including
 * message delivery status, incoming messages, and opt-out processing.
 *
 * @package WinABN\Public
 * @author WinABN Development Team
 * @version 1.0
 */

// Include bootstrap
require_once __DIR__ . '/bootstrap.php';

use WinABN\Core\{WhatsAppAPI, WhatsAppOptIn, Security};

/**
 * WhatsApp Webhook Handler Class
 */
class WhatsAppWebhookHandler
{
    /**
     * WhatsApp API instance
     *
     * @var WhatsAppAPI
     */
    private WhatsAppAPI $whatsapp;

    /**
     * Request data
     *
     * @var array<string, mixed>
     */
    private array $requestData;

    /**
     * Request headers
     *
     * @var array<string, string>
     */
    private array $headers;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->whatsapp = new WhatsAppAPI();
        $this->requestData = $this->getRequestData();
        $this->headers = $this->getRequestHeaders();
    }

    /**
     * Handle webhook request
     *
     * @return never
     */
    public function handleRequest(): never
    {
        try {
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

            switch ($method) {
                case 'GET':
                    $this->handleVerification();
                    break;

                case 'POST':
                    $this->handleWebhook();
                    break;

                default:
                    $this->respondWithError(405, 'Method not allowed');
            }

        } catch (Exception $e) {
            $this->logError('Webhook handler crashed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->respondWithError(500, 'Internal server error');
        }
    }

    /**
     * Handle webhook verification (GET request)
     *
     * @return never
     */
    private function handleVerification(): never
    {
        $mode = $_GET['hub_mode'] ?? '';
        $token = $_GET['hub_verify_token'] ?? '';
        $challenge = $_GET['hub_challenge'] ?? '';

        $this->logInfo('Webhook verification attempt', [
            'mode' => $mode,
            'token_provided' => !empty($token),
            'challenge_provided' => !empty($challenge)
        ]);

        $challengeResponse = $this->whatsapp->verifyWebhook($mode, $token, $challenge);

        if ($challengeResponse !== null) {
            $this->logInfo('Webhook verification successful');
            echo $challengeResponse;
            exit(0);
        } else {
            $this->logWarning('Webhook verification failed', [
                'mode' => $mode,
                'expected_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN') ? 'configured' : 'not_configured'
            ]);

            http_response_code(403);
            echo 'Forbidden';
            exit(1);
        }
    }

    /**
     * Handle webhook payload (POST request)
     *
     * @return never
     */
    private function handleWebhook(): never
    {
        // Verify webhook signature if configured
        if (!$this->verifySignature()) {
            $this->logWarning('Webhook signature verification failed');
            $this->respondWithError(403, 'Invalid signature');
        }

        // Rate limiting
        if (!$this->checkRateLimit()) {
            $this->logWarning('Webhook rate limit exceeded');
            $this->respondWithError(429, 'Rate limit exceeded');
        }

        if (empty($this->requestData)) {
            $this->logWarning('Empty webhook payload received');
            $this->respondWithError(400, 'Empty payload');
        }

        $this->logInfo('Processing WhatsApp webhook', [
            'payload_size' => strlen(json_encode($this->requestData)),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);

        // Process the webhook
        $result = $this->processWebhookPayload();

        if ($result['success']) {
            $this->logInfo('Webhook processed successfully', [
                'processed_events' => $result['processed'] ?? 0
            ]);

            $this->respondWithSuccess([
                'status' => 'processed',
                'events' => $result['processed'] ?? 0
            ]);
        } else {
            $this->logError('Webhook processing failed', [
                'error' => $result['error']
            ]);

            $this->respondWithError(400, 'Processing failed');
        }
    }

    /**
     * Process webhook payload
     *
     * @return array<string, mixed> Processing result
     */
    private function processWebhookPayload(): array
    {
        try {
            $processedEvents = 0;

            if (!isset($this->requestData['entry']) || !is_array($this->requestData['entry'])) {
                return [
                    'success' => false,
                    'error' => 'Invalid payload structure - missing entry'
                ];
            }

            foreach ($this->requestData['entry'] as $entry) {
                if (!isset($entry['changes']) || !is_array($entry['changes'])) {
                    continue;
                }

                foreach ($entry['changes'] as $change) {
                    if (!isset($change['value'])) {
                        continue;
                    }

                    $value = $change['value'];
                    $field = $change['field'] ?? 'unknown';

                    switch ($field) {
                        case 'messages':
                            $result = $this->processMessages($value);
                            $processedEvents += $result['processed'];
                            break;

                        case 'message_echoes':
                            $result = $this->processMessageEchoes($value);
                            $processedEvents += $result['processed'];
                            break;

                        case 'statuses':
                            $result = $this->processStatuses($value);
                            $processedEvents += $result['processed'];
                            break;

                        default:
                            $this->logInfo("Unknown webhook field: $field", ['value' => $value]);
                    }
                }
            }

            return [
                'success' => true,
                'processed' => $processedEvents
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Process incoming messages
     *
     * @param array<string, mixed> $value Webhook value
     * @return array<string, mixed> Processing result
     */
    private function processMessages(array $value): array
    {
        $processed = 0;

        if (!isset($value['messages']) || !is_array($value['messages'])) {
            return ['processed' => 0];
        }

        foreach ($value['messages'] as $message) {
            try {
                $messageId = $message['id'] ?? 'unknown';
                $fromPhone = $message['from'] ?? '';
                $timestamp = $message['timestamp'] ?? time();

                $this->logInfo('Processing incoming WhatsApp message', [
                    'message_id' => $messageId,
                    'from' => $fromPhone,
                    'type' => $message['type'] ?? 'unknown'
                ]);

                // Handle text messages
                if (isset($message['text']['body'])) {
                    $messageText = $message['text']['body'];
                    $result = $this->processTextMessage($fromPhone, $messageText, $messageId);

                    if ($result['success']) {
                        $processed++;
                    }
                }

                // Handle interactive messages (button responses)
                if (isset($message['interactive'])) {
                    $result = $this->processInteractiveMessage($fromPhone, $message['interactive'], $messageId);

                    if ($result['success']) {
                        $processed++;
                    }
                }

                // Store message in database for audit
                $this->storeIncomingMessage($message);

            } catch (Exception $e) {
                $this->logError('Failed to process incoming message', [
                    'message_id' => $messageId ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }

        return ['processed' => $processed];
    }

    /**
     * Process text message for opt-in/opt-out
     *
     * @param string $fromPhone Sender phone number
     * @param string $messageText Message content
     * @param string $messageId Message ID
     * @return array<string, mixed> Processing result
     */
    private function processTextMessage(string $fromPhone, string $messageText, string $messageId): array
    {
        try {
            // Process opt-in/opt-out keywords
            $result = WhatsAppOptIn::processIncomingMessage($fromPhone, $messageText);

            $this->logInfo('Text message processed', [
                'message_id' => $messageId,
                'from' => $fromPhone,
                'action' => $result['action'] ?? 'none',
                'success' => $result['success']
            ]);

            return $result;

        } catch (Exception $e) {
            $this->logError('Text message processing failed', [
                'message_id' => $messageId,
                'from' => $fromPhone,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Process interactive message (button responses)
     *
     * @param string $fromPhone Sender phone number
     * @param array<string, mixed> $interactive Interactive message data
     * @param string $messageId Message ID
     * @return array<string, mixed> Processing result
     */
    private function processInteractiveMessage(string $fromPhone, array $interactive, string $messageId): array
    {
        try {
            $buttonReply = $interactive['button_reply'] ?? null;

            if ($buttonReply && isset($buttonReply['id'])) {
                $buttonId = $buttonReply['id'];

                // Handle predefined button responses
                switch ($buttonId) {
                    case 'opt_out_confirm':
                        return WhatsAppOptIn::processOptOut(
                            $fromPhone,
                            'whatsapp_button',
                            'User confirmed opt-out via button'
                        );

                    case 'opt_in_confirm':
                        $userData = $this->getUserDataByPhone($fromPhone);
                        if ($userData) {
                            return WhatsAppOptIn::processOptIn(
                                $fromPhone,
                                $userData['email'],
                                $userData['first_name'],
                                'whatsapp_button'
                            );
                        }
                        break;
                }
            }

            return ['success' => true, 'action' => 'none'];

        } catch (Exception $e) {
            $this->logError('Interactive message processing failed', [
                'message_id' => $messageId,
                'from' => $fromPhone,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Process message delivery statuses
     *
     * @param array<string, mixed> $value Webhook value
     * @return array<string, mixed> Processing result
     */
    private function processStatuses(array $value): array
    {
        $processed = 0;

        if (!isset($value['statuses']) || !is_array($value['statuses'])) {
            return ['processed' => 0];
        }

        foreach ($value['statuses'] as $status) {
            try {
                $messageId = $status['id'] ?? 'unknown';
                $statusType = $status['status'] ?? 'unknown';
                $recipientId = $status['recipient_id'] ?? '';
                $timestamp = $status['timestamp'] ?? time();

                $this->logInfo('Processing message status', [
                    'message_id' => $messageId,
                    'status' => $statusType,
                    'recipient' => $recipientId
                ]);

                // Update queue status if message exists
                $this->updateQueueMessageStatus($messageId, $statusType, $status);

                // Handle specific statuses
                switch ($statusType) {
                    case 'delivered':
                        $this->handleDeliveredStatus($messageId, $status);
                        break;

                    case 'read':
                        $this->handleReadStatus($messageId, $status);
                        break;

                    case 'failed':
                        $this->handleFailedStatus($messageId, $status);
                        break;
                }

                $processed++;

            } catch (Exception $e) {
                $this->logError('Failed to process message status', [
                    'message_id' => $messageId ?? 'unknown',
                    'status' => $statusType ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }

        return ['processed' => $processed];
    }

    /**
     * Process message echoes (messages sent by business)
     *
     * @param array<string, mixed> $value Webhook value
     * @return array<string, mixed> Processing result
     */
    private function processMessageEchoes(array $value): array
    {
        // Message echoes are typically just for logging/audit
        $this->logInfo('Received message echo', ['data' => $value]);
        return ['processed' => 1];
    }

    /**
     * Update queue message status
     *
     * @param string $whatsappMessageId WhatsApp message ID
     * @param string $status Status
     * @param array<string, mixed> $statusData Full status data
     * @return void
     */
    private function updateQueueMessageStatus(string $whatsappMessageId, string $status, array $statusData): void
    {
        try {
            $query = "
                UPDATE whatsapp_queue
                SET whatsapp_status = ?,
                    whatsapp_status_data = ?,
                    updated_at = NOW()
                WHERE whatsapp_message_id = ?
            ";

            Database::execute($query, [
                $status,
                json_encode($statusData),
                $whatsappMessageId
            ]);

        } catch (Exception $e) {
            $this->logError('Failed to update queue message status', [
                'whatsapp_message_id' => $whatsappMessageId,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle delivered status
     *
     * @param string $messageId Message ID
     * @param array<string, mixed> $status Status data
     * @return void
     */
    private function handleDeliveredStatus(string $messageId, array $status): void
    {
        // Track delivery analytics
        $this->trackDeliveryAnalytics($messageId, 'delivered', $status);
    }

    /**
     * Handle read status
     *
     * @param string $messageId Message ID
     * @param array<string, mixed> $status Status data
     * @return void
     */
    private function handleReadStatus(string $messageId, array $status): void
    {
        // Track read analytics
        $this->trackDeliveryAnalytics($messageId, 'read', $status);
    }

    /**
     * Handle failed status
     *
     * @param string $messageId Message ID
     * @param array<string, mixed> $status Status data
     * @return void
     */
    private function handleFailedStatus(string $messageId, array $status): void
    {
        $errorCode = $status['errors'][0]['code'] ?? 'unknown';
        $errorTitle = $status['errors'][0]['title'] ?? 'Unknown error';

        $this->logWarning('WhatsApp message delivery failed', [
            'message_id' => $messageId,
            'error_code' => $errorCode,
            'error_title' => $errorTitle
        ]);

        // Track failure analytics
        $this->trackDeliveryAnalytics($messageId, 'failed', $status);
    }

    /**
     * Store incoming message for audit
     *
     * @param array<string, mixed> $message Message data
     * @return void
     */
    private function storeIncomingMessage(array $message): void
    {
        try {
            $query = "
                INSERT INTO whatsapp_incoming_messages
                (message_id, from_phone, message_type, message_data, received_at)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                message_data = VALUES(message_data),
                updated_at = NOW()
            ";

            Database::execute($query, [
                $message['id'] ?? 'unknown',
                $message['from'] ?? '',
                $message['type'] ?? 'unknown',
                json_encode($message)
            ]);

        } catch (Exception $e) {
            // Don't fail webhook processing if storage fails
            $this->logError('Failed to store incoming message', [
                'message_id' => $message['id'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Track delivery analytics
     *
     * @param string $messageId Message ID
     * @param string $event Event type
     * @param array<string, mixed> $statusData Status data
     * @return void
     */
    private function trackDeliveryAnalytics(string $messageId, string $event, array $statusData): void
    {
        try {
            $query = "
                INSERT INTO analytics_events
                (event_type, additional_data_json)
                VALUES (?, ?)
            ";

            $eventData = [
                'whatsapp_message_id' => $messageId,
                'delivery_status' => $event,
                'timestamp' => $statusData['timestamp'] ?? time(),
                'recipient' => $statusData['recipient_id'] ?? ''
            ];

            if ($event === 'failed' && isset($statusData['errors'])) {
                $eventData['errors'] = $statusData['errors'];
            }

            Database::execute($query, [
                'whatsapp_' . $event,
                json_encode($eventData)
            ]);

        } catch (Exception $e) {
            $this->logError('Failed to track delivery analytics', [
                'message_id' => $messageId,
                'event' => $event,
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
    private function getUserDataByPhone(string $phoneNumber): ?array
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
     * Verify webhook signature
     *
     * @return bool True if signature is valid
     */
    private function verifySignature(): bool
    {
        $webhookSecret = env('WHATSAPP_WEBHOOK_SECRET');

        if (!$webhookSecret) {
            // If no secret configured, skip verification
            return true;
        }

        $signature = $this->headers['x-hub-signature-256'] ?? '';
        $payload = file_get_contents('php://input');

        $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $webhookSecret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Check rate limiting
     *
     * @return bool True if request is allowed
     */
    private function checkRateLimit(): bool
    {
        $clientIp = client_ip();
        $key = "whatsapp_webhook_$clientIp";

        // Simple rate limiting: max 60 requests per minute
        $maxRequests = 60;
        $timeWindow = 60;

        return Security::checkRateLimit($key, $maxRequests, $timeWindow);
    }

    /**
     * Get request data
     *
     * @return array<string, mixed>
     */
    private function getRequestData(): array
    {
        $rawInput = file_get_contents('php://input');

        if (empty($rawInput)) {
            return [];
        }

        $data = json_decode($rawInput, true);

        return is_array($data) ? $data : [];
    }

    /**
     * Get request headers
     *
     * @return array<string, string>
     */
    private function getRequestHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headerName = strtolower(str_replace(['HTTP_', '_'], ['', '-'], $key));
                $headers[$headerName] = $value;
            }
        }

        return $headers;
    }

    /**
     * Respond with success
     *
     * @param array<string, mixed> $data Response data
     * @return never
     */
    private function respondWithSuccess(array $data): never
    {
        header('Content-Type: application/json');
        echo json_encode(array_merge(['success' => true], $data));
        exit(0);
    }

    /**
     * Respond with error
     *
     * @param int $statusCode HTTP status code
     * @param string $message Error message
     * @return never
     */
    private function respondWithError(int $statusCode, string $message): never
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $message,
            'status_code' => $statusCode
        ]);
        exit(1);
    }

    /**
     * Log info message
     *
     * @param string $message Log message
     * @param array<string, mixed> $context Additional context
     * @return void
     */
    private function logInfo(string $message, array $context = []): void
    {
        if (function_exists('app_log')) {
            app_log('info', $message, array_merge($context, [
                'component' => 'whatsapp_webhook',
                'ip' => client_ip()
            ]));
        }
    }

    /**
     * Log warning message
     *
     * @param string $message Log message
     * @param array<string, mixed> $context Additional context
     * @return void
     */
    private function logWarning(string $message, array $context = []): void
    {
        if (function_exists('app_log')) {
            app_log('warning', $message, array_merge($context, [
                'component' => 'whatsapp_webhook',
                'ip' => client_ip()
            ]));
        }
    }

    /**
     * Log error message
     *
     * @param string $message Log message
     * @param array<string, mixed> $context Additional context
     * @return void
     */
    private function logError(string $message, array $context = []): void
    {
        if (function_exists('app_log')) {
            app_log('error', $message, array_merge($context, [
                'component' => 'whatsapp_webhook',
                'ip' => client_ip()
            ]));
        }
    }
}

// ============================================================================
// WEBHOOK EXECUTION
// ============================================================================

// Create and run webhook handler
$handler = new WhatsAppWebhookHandler();
$handler->handleRequest();
