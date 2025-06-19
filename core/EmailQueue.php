<?php
declare(strict_types=1);

/**
 * File: core/EmailQueue.php
 * Location: core/EmailQueue.php
 *
 * WinABN Email Queue Management System
 *
 * Manages database-based email queuing with priority levels, retry logic,
 * and batch processing for the WinABN platform.
 *
 * @package WinABN\Core
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Core;

use Exception;
use DateTime;

class EmailQueue
{
    /**
     * Email queue table name
     *
     * @var string
     */
    private string $queueTable = 'email_queue';

    /**
     * Failed emails table name
     *
     * @var string
     */
    private string $failedTable = 'failed_emails';

    /**
     * Database instance
     *
     * @var Database
     */
    private Database $db;

    /**
     * Maximum retry attempts
     *
     * @var int
     */
    private int $maxAttempts = 3;

    /**
     * Batch processing limit
     *
     * @var int
     */
    private int $batchLimit = 50;

    /**
     * Priority levels
     *
     * @var array<string, int>
     */
    private array $priorities = [
        'critical' => 1,  // Winner notifications
        'high' => 2,      // Payment confirmations
        'normal' => 3,    // General notifications
        'low' => 4,       // Marketing emails
        'bulk' => 5       // Newsletter/promotions
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = new Database();
        $this->maxAttempts = (int) env('QUEUE_MAX_ATTEMPTS', 3);
        $this->batchLimit = (int) env('EMAIL_BATCH_LIMIT', 50);
    }

    /**
     * Add email to queue
     *
     * @param string $toEmail Recipient email address
     * @param string $subject Email subject
     * @param string $bodyHtml HTML email body
     * @param string $bodyText Plain text email body
     * @param string $priority Priority level (critical, high, normal, low, bulk)
     * @param DateTime|null $sendAt Scheduled send time (null for immediate)
     * @param array<string, mixed> $metadata Additional metadata
     * @return int Queue ID
     * @throws Exception
     */
    public function addToQueue(
        string $toEmail,
        string $subject,
        string $bodyHtml,
        string $bodyText = '',
        string $priority = 'normal',
        ?DateTime $sendAt = null,
        array $metadata = []
    ): int {
        // Validate email address
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email address: $toEmail");
        }

        // Validate priority
        if (!isset($this->priorities[$priority])) {
            $priority = 'normal';
        }

        // Set default send time to now if not specified
        if ($sendAt === null) {
            $sendAt = new DateTime();
        }

        // Prepare email data
        $emailData = [
            'to_email' => $toEmail,
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'body_text' => $bodyText ?: strip_tags($bodyHtml),
            'priority' => $this->priorities[$priority],
            'attempts' => 0,
            'send_at' => $sendAt->format('Y-m-d H:i:s'),
            'metadata_json' => !empty($metadata) ? json_encode($metadata) : null,
            'created_at' => (new DateTime())->format('Y-m-d H:i:s')
        ];

        try {
            $query = "
                INSERT INTO `{$this->queueTable}` (
                    `to_email`, `subject`, `body_html`, `body_text`,
                    `priority`, `attempts`, `send_at`, `metadata_json`, `created_at`
                ) VALUES (
                    :to_email, :subject, :body_html, :body_text,
                    :priority, :attempts, :send_at, :metadata_json, :created_at
                )
            ";

            $this->db->execute($query, $emailData);
            $queueId = $this->db->lastInsertId();

            $this->logQueueActivity('email_queued', [
                'queue_id' => $queueId,
                'to_email' => $toEmail,
                'subject' => $subject,
                'priority' => $priority,
                'send_at' => $sendAt->format('Y-m-d H:i:s')
            ]);

            return $queueId;

        } catch (Exception $e) {
            $this->logError('Failed to add email to queue', [
                'to_email' => $toEmail,
                'subject' => $subject,
                'error' => $e->getMessage()
            ]);

            throw new Exception("Failed to queue email: " . $e->getMessage());
        }
    }

    /**
     * Get next batch of emails to process
     *
     * @param int $limit Batch size limit
     * @return array<array<string, mixed>>
     */
    public function getNextBatch(int $limit = null): array
    {
        $limit = $limit ?? $this->batchLimit;

        $query = "
            SELECT * FROM `{$this->queueTable}`
            WHERE `processing` = 0
                AND `send_at` <= NOW()
                AND `attempts` < :max_attempts
            ORDER BY `priority` ASC, `created_at` ASC
            LIMIT :limit
        ";

        try {
            $statement = $this->db->execute($query, [
                'max_attempts' => $this->maxAttempts,
                'limit' => $limit
            ]);

            return $statement->fetchAll();

        } catch (Exception $e) {
            $this->logError('Failed to get email batch', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Mark email as being processed
     *
     * @param int $queueId Queue ID
     * @return bool
     */
    public function markAsProcessing(int $queueId): bool
    {
        $query = "
            UPDATE `{$this->queueTable}`
            SET `processing` = 1, `processing_started_at` = NOW()
            WHERE `id` = ? AND `processing` = 0
        ";

        try {
            $statement = $this->db->execute($query, [$queueId]);
            return $statement->rowCount() > 0;

        } catch (Exception $e) {
            $this->logError('Failed to mark email as processing', [
                'queue_id' => $queueId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Mark email as sent successfully
     *
     * @param int $queueId Queue ID
     * @param string|null $messageId Provider message ID
     * @return bool
     */
    public function markAsSent(int $queueId, ?string $messageId = null): bool
    {
        $query = "
            UPDATE `{$this->queueTable}`
            SET `status` = 'sent',
                `sent_at` = NOW(),
                `message_id` = ?,
                `processing` = 0
            WHERE `id` = ?
        ";

        try {
            $statement = $this->db->execute($query, [$messageId, $queueId]);
            $success = $statement->rowCount() > 0;

            if ($success) {
                $this->logQueueActivity('email_sent', [
                    'queue_id' => $queueId,
                    'message_id' => $messageId
                ]);
            }

            return $success;

        } catch (Exception $e) {
            $this->logError('Failed to mark email as sent', [
                'queue_id' => $queueId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Mark email as failed and handle retry logic
     *
     * @param int $queueId Queue ID
     * @param string $errorMessage Error message
     * @return bool
     */
    public function markAsFailed(int $queueId, string $errorMessage): bool
    {
        try {
            $this->db->beginTransaction();

            // Get current email data
            $email = $this->getQueuedEmail($queueId);
            if (!$email) {
                throw new Exception("Email not found in queue: $queueId");
            }

            $newAttempts = $email['attempts'] + 1;

            if ($newAttempts >= $this->maxAttempts) {
                // Move to failed emails table
                $this->moveToFailedTable($queueId, $errorMessage);

                // Remove from queue
                $this->removeFromQueue($queueId);

                $this->logQueueActivity('email_failed_permanently', [
                    'queue_id' => $queueId,
                    'attempts' => $newAttempts,
                    'error' => $errorMessage
                ]);

            } else {
                // Update retry count and schedule next attempt
                $nextAttemptTime = $this->calculateNextAttemptTime($newAttempts);

                $query = "
                    UPDATE `{$this->queueTable}`
                    SET `attempts` = ?,
                        `last_error` = ?,
                        `send_at` = ?,
                        `processing` = 0,
                        `processing_started_at` = NULL
                    WHERE `id` = ?
                ";

                $this->db->execute($query, [
                    $newAttempts,
                    $errorMessage,
                    $nextAttemptTime->format('Y-m-d H:i:s'),
                    $queueId
                ]);

                $this->logQueueActivity('email_retry_scheduled', [
                    'queue_id' => $queueId,
                    'attempt' => $newAttempts,
                    'next_attempt' => $nextAttemptTime->format('Y-m-d H:i:s'),
                    'error' => $errorMessage
                ]);
            }

            $this->db->commit();
            return true;

        } catch (Exception $e) {
            $this->db->rollback();

            $this->logError('Failed to mark email as failed', [
                'queue_id' => $queueId,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Get queued email by ID
     *
     * @param int $queueId Queue ID
     * @return array<string, mixed>|null
     */
    public function getQueuedEmail(int $queueId): ?array
    {
        $query = "SELECT * FROM `{$this->queueTable}` WHERE `id` = ?";
        return $this->db->fetchOne($query, [$queueId]);
    }

    /**
     * Calculate next attempt time with exponential backoff
     *
     * @param int $attemptNumber Current attempt number
     * @return DateTime
     */
    private function calculateNextAttemptTime(int $attemptNumber): DateTime
    {
        // Exponential backoff: 1min, 5min, 30min
        $delays = [1, 5, 30]; // minutes
        $delayIndex = min($attemptNumber - 1, count($delays) - 1);
        $delayMinutes = $delays[$delayIndex];

        $nextAttempt = new DateTime();
        $nextAttempt->modify("+{$delayMinutes} minutes");

        return $nextAttempt;
    }

    /**
     * Move email to failed table
     *
     * @param int $queueId Queue ID
     * @param string $errorMessage Error message
     * @return void
     * @throws Exception
     */
    private function moveToFailedTable(int $queueId, string $errorMessage): void
    {
        $email = $this->getQueuedEmail($queueId);
        if (!$email) {
            throw new Exception("Email not found for failed table move: $queueId");
        }

        $query = "
            INSERT INTO `{$this->failedTable}` (
                `original_queue_id`, `to_email`, `subject`, `body_html`, `body_text`,
                `error_message`, `failed_at`, `attempts`, `metadata_json`
            ) VALUES (
                ?, ?, ?, ?, ?, ?, NOW(), ?, ?
            )
        ";

        $this->db->execute($query, [
            $queueId,
            $email['to_email'],
            $email['subject'],
            $email['body_html'],
            $email['body_text'],
            $errorMessage,
            $email['attempts'],
            $email['metadata_json']
        ]);
    }

    /**
     * Remove email from queue
     *
     * @param int $queueId Queue ID
     * @return bool
     */
    private function removeFromQueue(int $queueId): bool
    {
        $query = "DELETE FROM `{$this->queueTable}` WHERE `id` = ?";

        try {
            $statement = $this->db->execute($query, [$queueId]);
            return $statement->rowCount() > 0;
        } catch (Exception $e) {
            $this->logError('Failed to remove email from queue', [
                'queue_id' => $queueId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get queue statistics
     *
     * @return array<string, mixed>
     */
    public function getQueueStats(): array
    {
        try {
            $stats = [];

            // Total queued emails
            $stats['total_queued'] = $this->db->fetchColumn(
                "SELECT COUNT(*) FROM `{$this->queueTable}` WHERE `status` = 'pending'"
            );

            // Emails being processed
            $stats['processing'] = $this->db->fetchColumn(
                "SELECT COUNT(*) FROM `{$this->queueTable}` WHERE `processing` = 1"
            );

            // Failed emails
            $stats['failed'] = $this->db->fetchColumn(
                "SELECT COUNT(*) FROM `{$this->failedTable}`"
            );

            // Sent emails (last 24 hours)
            $stats['sent_24h'] = $this->db->fetchColumn(
                "SELECT COUNT(*) FROM `{$this->queueTable}`
                 WHERE `status` = 'sent' AND `sent_at` > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            );

            // Emails by priority
            $priorityStats = $this->db->fetchAll(
                "SELECT `priority`, COUNT(*) as count
                 FROM `{$this->queueTable}`
                 WHERE `status` = 'pending'
                 GROUP BY `priority`"
            );

            $stats['by_priority'] = [];
            foreach ($priorityStats as $stat) {
                $priorityName = array_search($stat['priority'], $this->priorities);
                $stats['by_priority'][$priorityName] = $stat['count'];
            }

            // Oldest pending email
            $oldestPending = $this->db->fetchOne(
                "SELECT `created_at` FROM `{$this->queueTable}`
                 WHERE `status` = 'pending'
                 ORDER BY `created_at` ASC LIMIT 1"
            );

            $stats['oldest_pending'] = $oldestPending ? $oldestPending['created_at'] : null;

            return $stats;

        } catch (Exception $e) {
            $this->logError('Failed to get queue statistics', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Clean up old processed emails
     *
     * @param int $daysOld Number of days old to clean up
     * @return int Number of cleaned up emails
     */
    public function cleanupOldEmails(int $daysOld = 30): int
    {
        try {
            // Clean up sent emails older than specified days
            $query = "
                DELETE FROM `{$this->queueTable}`
                WHERE `status` = 'sent'
                    AND `sent_at` < DATE_SUB(NOW(), INTERVAL ? DAY)
            ";

            $statement = $this->db->execute($query, [$daysOld]);
            $deletedCount = $statement->rowCount();

            $this->logQueueActivity('queue_cleanup', [
                'days_old' => $daysOld,
                'deleted_count' => $deletedCount
            ]);

            return $deletedCount;

        } catch (Exception $e) {
            $this->logError('Failed to cleanup old emails', [
                'days_old' => $daysOld,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Retry failed emails
     *
     * @param array<int> $queueIds Specific queue IDs to retry (empty for all)
     * @return int Number of emails moved back to queue
     */
    public function retryFailedEmails(array $queueIds = []): int
    {
        try {
            $this->db->beginTransaction();

            // Get failed emails to retry
            if (!empty($queueIds)) {
                $placeholders = str_repeat('?,', count($queueIds) - 1) . '?';
                $whereClause = "WHERE `original_queue_id` IN ($placeholders)";
                $params = $queueIds;
            } else {
                $whereClause = "";
                $params = [];
            }

            $failedEmails = $this->db->fetchAll(
                "SELECT * FROM `{$this->failedTable}` $whereClause",
                $params
            );

            $retriedCount = 0;

            foreach ($failedEmails as $failedEmail) {
                // Add back to queue with reset attempts
                $newQueueId = $this->addToQueue(
                    $failedEmail['to_email'],
                    $failedEmail['subject'],
                    $failedEmail['body_html'],
                    $failedEmail['body_text'],
                    'normal', // Reset to normal priority
                    new DateTime(), // Send immediately
                    json_decode($failedEmail['metadata_json'] ?? '[]', true)
                );

                if ($newQueueId) {
                    // Remove from failed table
                    $this->db->execute(
                        "DELETE FROM `{$this->failedTable}` WHERE `id` = ?",
                        [$failedEmail['id']]
                    );

                    $retriedCount++;
                }
            }

            $this->db->commit();

            $this->logQueueActivity('failed_emails_retried', [
                'retried_count' => $retriedCount,
                'queue_ids' => $queueIds
            ]);

            return $retriedCount;

        } catch (Exception $e) {
            $this->db->rollback();

            $this->logError('Failed to retry failed emails', [
                'queue_ids' => $queueIds,
                'error' => $e->getMessage()
            ]);

            return 0;
        }
    }

    /**
     * Reset stuck processing emails
     *
     * @param int $timeoutMinutes Timeout in minutes for stuck emails
     * @return int Number of reset emails
     */
    public function resetStuckEmails(int $timeoutMinutes = 15): int
    {
        try {
            $query = "
                UPDATE `{$this->queueTable}`
                SET `processing` = 0,
                    `processing_started_at` = NULL,
                    `last_error` = 'Reset due to processing timeout'
                WHERE `processing` = 1
                    AND `processing_started_at` < DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ";

            $statement = $this->db->execute($query, [$timeoutMinutes]);
            $resetCount = $statement->rowCount();

            if ($resetCount > 0) {
                $this->logQueueActivity('stuck_emails_reset', [
                    'timeout_minutes' => $timeoutMinutes,
                    'reset_count' => $resetCount
                ]);
            }

            return $resetCount;

        } catch (Exception $e) {
            $this->logError('Failed to reset stuck emails', [
                'timeout_minutes' => $timeoutMinutes,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Log queue activity
     *
     * @param string $activity Activity type
     * @param array<string, mixed> $context Activity context
     * @return void
     */
    private function logQueueActivity(string $activity, array $context = []): void
    {
        if (function_exists('app_log')) {
            app_log('info', "Email queue: $activity", $context);
        }
    }

    /**
     * Log errors
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
            error_log("EmailQueue Error: $message " . json_encode($context));
        }
    }
}
