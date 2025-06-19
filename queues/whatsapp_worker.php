<?php
declare(strict_types=1);

/**
 * File: queues/whatsapp_worker.php
 * Location: queues/whatsapp_worker.php
 *
 * WinABN WhatsApp Queue Worker
 *
 * Processes WhatsApp message queue with rate limiting and retry logic.
 * Designed to run as a cron job every minute for optimal message delivery.
 *
 * @package WinABN\Queues
 * @author WinABN Development Team
 * @version 1.0
 */

// Include bootstrap
require_once __DIR__ . '/../public/bootstrap.php';

use WinABN\Core\{Database, WhatsAppAPI};

/**
 * WhatsApp Queue Worker Class
 */
class WhatsAppWorker
{
    /**
     * WhatsApp API instance
     *
     * @var WhatsAppAPI
     */
    private WhatsAppAPI $whatsapp;

    /**
     * Worker configuration
     *
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * Processing statistics
     *
     * @var array<string, int>
     */
    private array $stats = [
        'processed' => 0,
        'successful' => 0,
        'failed' => 0,
        'skipped' => 0
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->whatsapp = new WhatsAppAPI();
        $this->config = [
            'batch_size' => (int) env('WHATSAPP_BATCH_SIZE', 10),
            'max_execution_time' => (int) env('WHATSAPP_MAX_EXECUTION_TIME', 50), // seconds
            'rate_limit_per_minute' => (int) env('WHATSAPP_RATE_LIMIT_PER_MINUTE', 10),
            'enabled' => env('WHATSAPP_ENABLED', true)
        ];
    }

    /**
     * Process WhatsApp message queue
     *
     * @return array<string, mixed> Processing results
     */
    public function processQueue(): array
    {
        $startTime = microtime(true);

        try {
            // Check if WhatsApp is enabled
            if (!$this->config['enabled']) {
                return [
                    'success' => false,
                    'message' => 'WhatsApp processing is disabled',
                    'stats' => $this->stats
                ];
            }

            $this->log('info', 'WhatsApp queue processing started');

            // Process pending messages
            while ($this->shouldContinueProcessing($startTime)) {
                $batch = $this->getNextBatch();

                if (empty($batch)) {
                    break; // No more messages to process
                }

                $this->processBatch($batch);
            }

            $executionTime = microtime(true) - $startTime;

            $this->log('info', 'WhatsApp queue processing completed', [
                'stats' => $this->stats,
                'execution_time' => round($executionTime, 2)
            ]);

            return [
                'success' => true,
                'stats' => $this->stats,
                'execution_time' => round($executionTime, 2)
            ];

        } catch (Exception $e) {
            $this->log('error', 'WhatsApp queue processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'stats' => $this->stats
            ];
        }
    }

    /**
     * Get next batch of messages to process
     *
     * @return array<array<string, mixed>> Batch of messages
     */
    private function getNextBatch(): array
    {
        $query = "
            SELECT id, to_phone, message_template, variables_json,
                   message_type, priority, attempts, participant_id
            FROM whatsapp_queue
            WHERE status = 'pending'
            AND send_at <= NOW()
            AND attempts < max_attempts
            ORDER BY priority ASC, send_at ASC
            LIMIT ?
            FOR UPDATE SKIP LOCKED
        ";

        return Database::fetchAll($query, [$this->config['batch_size']]);
    }

    /**
     * Process a batch of messages
     *
     * @param array<array<string, mixed>> $batch Messages to process
     * @return void
     */
    private function processBatch(array $batch): void
    {
        foreach ($batch as $message) {
            $this->processMessage($message);
            $this->stats['processed']++;

            // Respect rate limiting
            if (!$this->whatsapp->getRateLimiter()->allow()) {
                $this->log('info', 'Rate limit reached, stopping batch processing');
                break;
            }
        }
    }

    /**
     * Process individual message
     *
     * @param array<string, mixed> $message Message data
     * @return void
     */
    private function processMessage(array $message): void
    {
        try {
            // Mark as processing
            $this->updateMessageStatus($message['id'], 'processing');

            // Decode variables
            $variables = json_decode($message['variables_json'], true) ?? [];

            // Add participant data if needed
            if ($message['participant_id']) {
                $participant = $this->getParticipantData($message['participant_id']);
                if ($participant) {
                    $variables = array_merge($variables, $participant);
                }
            }

            // Send message
            $result = $this->whatsapp->sendTemplateMessage(
                $message['to_phone'],
                $message['message_template'],
                $variables,
                (int) $message['priority']
            );

            if ($result['success'] && !($result['queued'] ?? false)) {
                // Message sent successfully
                $this->updateMessageStatus($message['id'], 'sent', $result['message_id']);
                $this->stats['successful']++;

                $this->log('info', 'WhatsApp message sent successfully', [
                    'queue_id' => $message['id'],
                    'phone' => $message['to_phone'],
                    'template' => $message['message_template'],
                    'message_id' => $result['message_id']
                ]);

                // Track analytics
                $this->trackMessageAnalytics($message, 'sent');

            } else {
                // Failed to send
                $this->handleMessageFailure($message, $result['error'] ?? 'Unknown error');
            }

        } catch (Exception $e) {
            $this->handleMessageFailure($message, $e->getMessage());
        }
    }

    /**
     * Handle message sending failure
     *
     * @param array<string, mixed> $message Message data
     * @param string $error Error message
     * @return void
     */
    private function handleMessageFailure(array $message, string $error): void
    {
        $this->stats['failed']++;

        $this->log('warning', 'WhatsApp message failed', [
            'queue_id' => $message['id'],
            'phone' => $message['to_phone'],
            'template' => $message['message_template'],
            'error' => $error,
            'attempt' => $message['attempts'] + 1
        ]);

        // Calculate retry delay (exponential backoff)
        $retryDelay = $this->calculateRetryDelay($message['attempts']);

        // Update message with error and retry info
        $query = "
            UPDATE whatsapp_queue
            SET attempts = attempts + 1,
                error_message = ?,
                status = CASE
                    WHEN attempts + 1 >= max_attempts THEN 'failed'
                    ELSE 'pending'
                END,
                send_at = CASE
                    WHEN attempts + 1 < max_attempts THEN DATE_ADD(NOW(), INTERVAL ? MINUTE)
                    ELSE send_at
                END,
                updated_at = NOW()
            WHERE id = ?
        ";

        Database::execute($query, [$error, $retryDelay, $message['id']]);

        // Track analytics for failure
        $this->trackMessageAnalytics($message, 'failed', $error);
    }

    /**
     * Calculate retry delay with exponential backoff
     *
     * @param int $attempts Current attempt count
     * @return int Delay in minutes
     */
    private function calculateRetryDelay(int $attempts): int
    {
        // Exponential backoff: 1, 2, 4, 8, 16 minutes (max 30 minutes)
        return min(pow(2, $attempts), 30);
    }

    /**
     * Update message status in queue
     *
     * @param int $messageId Message ID
     * @param string $status New status
     * @param string|null $whatsappMessageId WhatsApp message ID
     * @return void
     */
    private function updateMessageStatus(int $messageId, string $status, ?string $whatsappMessageId = null): void
    {
        $query = "
            UPDATE whatsapp_queue
            SET status = ?, updated_at = NOW()";

        $params = [$status];

        if ($status === 'sent') {
            $query .= ", sent_at = NOW()";
            if ($whatsappMessageId) {
                $query .= ", whatsapp_message_id = ?";
                $params[] = $whatsappMessageId;
            }
        }

        $query .= " WHERE id = ?";
        $params[] = $messageId;

        Database::execute($query, $params);
    }

    /**
     * Get participant data for message variables
     *
     * @param int $participantId Participant ID
     * @return array<string, mixed>|null Participant data
     */
    private function getParticipantData(int $participantId): ?array
    {
        $query = "
            SELECT p.first_name, p.last_name, p.user_email,
                   g.name as game_name, g.prize_value, g.currency,
                   r.id as round_id
            FROM participants p
            JOIN rounds r ON p.round_id = r.id
            JOIN games g ON r.game_id = g.id
            WHERE p.id = ?
        ";

        $participant = Database::fetchOne($query, [$participantId]);

        if (!$participant) {
            return null;
        }

        return [
            'first_name' => $participant['first_name'],
            'full_name' => $participant['first_name'] . ' ' . $participant['last_name'],
            'prize_name' => $participant['game_name'],
            'prize_value' => $participant['prize_value'],
            'currency_symbol' => $this->getCurrencySymbol($participant['currency'])
        ];
    }

    /**
     * Get currency symbol
     *
     * @param string $currency Currency code
     * @return string Currency symbol
     */
    private function getCurrencySymbol(string $currency): string
    {
        $symbols = [
            'GBP' => '£',
            'USD' => '$',
            'EUR' => '€',
            'CAD' => 'C$',
            'AUD' => 'A$'
        ];

        return $symbols[$currency] ?? $currency;
    }

    /**
     * Track message analytics
     *
     * @param array<string, mixed> $message Message data
     * @param string $status Message status (sent/failed)
     * @param string|null $error Error message if failed
     * @return void
     */
    private function trackMessageAnalytics(array $message, string $status, ?string $error = null): void
    {
        try {
            $query = "
                INSERT INTO analytics_events
                (event_type, participant_id, additional_data_json)
                VALUES (?, ?, ?)
            ";

            $eventData = [
                'whatsapp_template' => $message['message_template'],
                'message_type' => $message['message_type'],
                'phone' => $message['to_phone'],
                'status' => $status,
                'attempt' => $message['attempts'] + 1
            ];

            if ($error) {
                $eventData['error'] = $error;
            }

            Database::execute($query, [
                'whatsapp_' . $status,
                $message['participant_id'],
                json_encode($eventData)
            ]);

        } catch (Exception $e) {
            // Don't fail the main process if analytics fails
            $this->log('warning', 'Analytics tracking failed', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Check if processing should continue
     *
     * @param float $startTime Process start time
     * @return bool True if should continue
     */
    private function shouldContinueProcessing(float $startTime): bool
    {
        $executionTime = microtime(true) - $startTime;

        // Stop if we've been running too long
        if ($executionTime >= $this->config['max_execution_time']) {
            $this->log('info', 'Max execution time reached, stopping processing');
            return false;
        }

        // Stop if rate limit reached
        if (!$this->whatsapp->getRateLimiter()->allow()) {
            return false;
        }

        return true;
    }

    /**
     * Clean up old processed messages
     *
     * @param int $olderThanDays Remove messages older than X days
     * @return int Number of cleaned messages
     */
    public function cleanupOldMessages(int $olderThanDays = 30): int
    {
        try {
            $query = "
                DELETE FROM whatsapp_queue
                WHERE status IN ('sent', 'failed')
                AND updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ";

            $statement = Database::execute($query, [$olderThanDays]);
            $deletedCount = $statement->rowCount();

            $this->log('info', 'Old WhatsApp messages cleaned up', [
                'deleted_count' => $deletedCount,
                'older_than_days' => $olderThanDays
            ]);

            return $deletedCount;

        } catch (Exception $e) {
            $this->log('error', 'WhatsApp cleanup failed', [
                'error' => $e->getMessage()
            ]);

            return 0;
        }
    }

    /**
     * Get queue statistics
     *
     * @return array<string, mixed> Queue statistics
     */
    public function getQueueStats(): array
    {
        try {
            $query = "
                SELECT
                    status,
                    COUNT(*) as count,
                    MIN(created_at) as oldest,
                    MAX(created_at) as newest
                FROM whatsapp_queue
                GROUP BY status
            ";

            $statusStats = Database::fetchAll($query);

            $query = "
                SELECT
                    message_type,
                    COUNT(*) as count,
                    AVG(attempts) as avg_attempts
                FROM whatsapp_queue
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY message_type
            ";

            $typeStats = Database::fetchAll($query);

            return [
                'by_status' => $statusStats,
                'by_type_24h' => $typeStats,
                'current_stats' => $this->stats
            ];

        } catch (Exception $e) {
            return [
                'error' => 'Failed to get queue stats: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Log message with context
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array<string, mixed> $context Additional context
     * @return void
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if (function_exists('app_log')) {
            app_log($level, $message, array_merge($context, [
                'worker' => 'whatsapp',
                'pid' => getmypid()
            ]));
        }
    }
}

// ============================================================================
// SCRIPT EXECUTION
// ============================================================================

try {
    // Prevent multiple instances running simultaneously
    $lockFile = __DIR__ . '/../tmp/whatsapp_worker.lock';
    $lockHandle = fopen($lockFile, 'w');

    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        echo "WhatsApp worker is already running.\n";
        exit(1);
    }

    // Create and run worker
    $worker = new WhatsAppWorker();
    $result = $worker->processQueue();

    // Output results for cron monitoring
    if ($result['success']) {
        echo "WhatsApp queue processed successfully. ";
        echo "Processed: {$result['stats']['processed']}, ";
        echo "Successful: {$result['stats']['successful']}, ";
        echo "Failed: {$result['stats']['failed']}\n";

        // Clean up old messages weekly (when minute is 0)
        if ((int) date('i') === 0 && (int) date('H') % 24 === 2) { // 2 AM daily
            $cleaned = $worker->cleanupOldMessages(30);
            echo "Cleaned up $cleaned old messages.\n";
        }

        exit(0);
    } else {
        echo "WhatsApp queue processing failed: {$result['error']}\n";
        exit(1);
    }

} catch (Exception $e) {
    echo "WhatsApp worker crashed: " . $e->getMessage() . "\n";
    exit(1);

} finally {
    // Release lock
    if (isset($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        unlink($lockFile);
    }
}
