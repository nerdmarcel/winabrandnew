<?php
/**
 * File: /controllers/QueueController.php
 * Queue Worker Controller for background job processing and queue management
 *
 * Handles:
 * - Email queue processing
 * - WhatsApp queue processing
 * - Retry logic with exponential backoff
 * - Cron job coordination
 * - Job prioritization and batch processing
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/models/EmailQueue.php';
require_once dirname(__DIR__) . '/models/WhatsAppQueue.php';
require_once dirname(__DIR__) . '/core/Logger.php';

class QueueController {
    private $db;
    private $logger;
    private $config;
    private $max_execution_time;
    private $batch_size;

    // Queue priorities
    const PRIORITY_HIGH = 1;
    const PRIORITY_NORMAL = 2;
    const PRIORITY_LOW = 3;

    // Queue types
    const QUEUE_EMAIL = 'email';
    const QUEUE_WHATSAPP = 'whatsapp';
    const QUEUE_EXCHANGE_RATE = 'exchange_rate';

    // Retry configuration
    const MAX_RETRIES = 3;
    const RETRY_DELAYS = [60, 300, 1800]; // 1min, 5min, 30min

    public function __construct() {
        $this->db = Database::getInstance();
        $this->logger = new Logger('queue');
        $this->config = Config::get();
        $this->max_execution_time = 300; // 5 minutes max execution
        $this->batch_size = 50; // Process 50 jobs per batch

        // Set memory and time limits
        ini_set('memory_limit', '256M');
        set_time_limit($this->max_execution_time);
    }

    /**
     * Main queue processing entry point
     * Called by cron jobs
     */
    public function processQueues() {
        $this->logger->info('Starting queue processing cycle');
        $start_time = microtime(true);

        try {
            // Check if another instance is running
            if ($this->isProcessingLocked()) {
                $this->logger->warning('Queue processing already running, skipping');
                return false;
            }

            $this->lockProcessing();

            // Process queues in priority order
            $processed = [
                'email' => $this->processEmailQueue(),
                'whatsapp' => $this->processWhatsAppQueue(),
                'failed' => $this->processFailedJobs()
            ];

            $this->unlockProcessing();

            $duration = round(microtime(true) - $start_time, 2);
            $this->logger->info("Queue processing completed", [
                'duration' => $duration,
                'processed' => $processed
            ]);

            return $processed;

        } catch (Exception $e) {
            $this->unlockProcessing();
            $this->logger->error('Queue processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Process email queue
     */
    public function processEmailQueue() {
        $this->logger->info('Processing email queue');
        $processed = 0;

        try {
            $emailQueue = new EmailQueue();

            // Get pending emails ordered by priority and created date
            $emails = $this->getPendingJobs(self::QUEUE_EMAIL, $this->batch_size);

            foreach ($emails as $email) {
                if ($this->shouldStopProcessing()) {
                    break;
                }

                try {
                    $this->processEmailJob($email);
                    $processed++;

                    // Small delay to prevent overwhelming the email service
                    usleep(100000); // 0.1 second

                } catch (Exception $e) {
                    $this->handleJobFailure($email, $e);
                }
            }

            return $processed;

        } catch (Exception $e) {
            $this->logger->error('Email queue processing failed', [
                'error' => $e->getMessage()
            ]);
            return $processed;
        }
    }

    /**
     * Process WhatsApp queue
     */
    public function processWhatsAppQueue() {
        $this->logger->info('Processing WhatsApp queue');
        $processed = 0;

        try {
            $whatsappQueue = new WhatsAppQueue();

            // Get pending WhatsApp messages
            $messages = $this->getPendingJobs(self::QUEUE_WHATSAPP, 10); // Lower batch for rate limiting

            foreach ($messages as $message) {
                if ($this->shouldStopProcessing()) {
                    break;
                }

                try {
                    $this->processWhatsAppJob($message);
                    $processed++;

                    // Rate limiting: 10 messages per minute
                    sleep(6); // 6 seconds between messages

                } catch (Exception $e) {
                    $this->handleJobFailure($message, $e);
                }
            }

            return $processed;

        } catch (Exception $e) {
            $this->logger->error('WhatsApp queue processing failed', [
                'error' => $e->getMessage()
            ]);
            return $processed;
        }
    }

    /**
     * Process failed jobs with retry logic
     */
    public function processFailedJobs() {
        $this->logger->info('Processing failed jobs');
        $processed = 0;

        try {
            // Get jobs ready for retry
            $failedJobs = $this->getRetryableJobs();

            foreach ($failedJobs as $job) {
                if ($this->shouldStopProcessing()) {
                    break;
                }

                try {
                    $this->retryJob($job);
                    $processed++;

                } catch (Exception $e) {
                    $this->handleJobFailure($job, $e);
                }
            }

            return $processed;

        } catch (Exception $e) {
            $this->logger->error('Failed jobs processing error', [
                'error' => $e->getMessage()
            ]);
            return $processed;
        }
    }

    /**
     * Process individual email job
     */
    private function processEmailJob($email) {
        $this->logger->debug('Processing email job', ['id' => $email['id']]);

        // Update job status to processing
        $this->updateJobStatus($email['id'], 'processing', self::QUEUE_EMAIL);

        try {
            $emailQueue = new EmailQueue();
            $result = $emailQueue->sendEmail($email);

            if ($result) {
                $this->updateJobStatus($email['id'], 'completed', self::QUEUE_EMAIL);
                $this->logger->debug('Email sent successfully', ['id' => $email['id']]);
            } else {
                throw new Exception('Email sending failed');
            }

        } catch (Exception $e) {
            $this->updateJobStatus($email['id'], 'failed', self::QUEUE_EMAIL);
            throw $e;
        }
    }

    /**
     * Process individual WhatsApp job
     */
    private function processWhatsAppJob($message) {
        $this->logger->debug('Processing WhatsApp job', ['id' => $message['id']]);

        // Update job status to processing
        $this->updateJobStatus($message['id'], 'processing', self::QUEUE_WHATSAPP);

        try {
            $whatsappQueue = new WhatsAppQueue();
            $result = $whatsappQueue->sendMessage($message);

            if ($result) {
                $this->updateJobStatus($message['id'], 'completed', self::QUEUE_WHATSAPP);
                $this->logger->debug('WhatsApp message sent successfully', ['id' => $message['id']]);
            } else {
                throw new Exception('WhatsApp message sending failed');
            }

        } catch (Exception $e) {
            $this->updateJobStatus($message['id'], 'failed', self::QUEUE_WHATSAPP);
            throw $e;
        }
    }

    /**
     * Get pending jobs from queue
     */
    private function getPendingJobs($queue_type, $limit) {
        $table = $this->getQueueTable($queue_type);

        $sql = "SELECT * FROM {$table}
                WHERE status = 'pending'
                   OR (status = 'processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))
                ORDER BY priority ASC, created_at ASC
                LIMIT ?";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $limit);
        $stmt->execute();

        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Get jobs ready for retry
     */
    private function getRetryableJobs() {
        $jobs = [];

        // Get failed email jobs
        $sql = "SELECT *, 'email' as queue_type FROM email_queue
                WHERE status = 'failed'
                  AND retry_count < ?
                  AND next_retry_at <= NOW()
                ORDER BY priority ASC, created_at ASC
                LIMIT 20";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', self::MAX_RETRIES);
        $stmt->execute();
        $jobs = array_merge($jobs, $stmt->get_result()->fetch_all(MYSQLI_ASSOC));

        // Get failed WhatsApp jobs
        $sql = "SELECT *, 'whatsapp' as queue_type FROM whatsapp_queue
                WHERE status = 'failed'
                  AND retry_count < ?
                  AND next_retry_at <= NOW()
                ORDER BY priority ASC, created_at ASC
                LIMIT 10";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', self::MAX_RETRIES);
        $stmt->execute();
        $jobs = array_merge($jobs, $stmt->get_result()->fetch_all(MYSQLI_ASSOC));

        return $jobs;
    }

    /**
     * Retry a failed job
     */
    private function retryJob($job) {
        $this->logger->info('Retrying job', [
            'id' => $job['id'],
            'type' => $job['queue_type'],
            'attempt' => $job['retry_count'] + 1
        ]);

        if ($job['queue_type'] === self::QUEUE_EMAIL) {
            $this->processEmailJob($job);
        } elseif ($job['queue_type'] === self::QUEUE_WHATSAPP) {
            $this->processWhatsAppJob($job);
        }
    }

    /**
     * Handle job failure
     */
    private function handleJobFailure($job, Exception $e) {
        $this->logger->error('Job failed', [
            'id' => $job['id'],
            'type' => $job['queue_type'] ?? 'unknown',
            'error' => $e->getMessage(),
            'retry_count' => $job['retry_count'] ?? 0
        ]);

        $retry_count = ($job['retry_count'] ?? 0) + 1;
        $queue_type = $job['queue_type'] ?? self::QUEUE_EMAIL;

        if ($retry_count >= self::MAX_RETRIES) {
            // Maximum retries reached, mark as permanently failed
            $this->updateJobStatus($job['id'], 'permanently_failed', $queue_type);
            $this->logger->error('Job permanently failed', ['id' => $job['id']]);
        } else {
            // Schedule retry with exponential backoff
            $delay = self::RETRY_DELAYS[$retry_count - 1] ?? 1800;
            $next_retry = date('Y-m-d H:i:s', time() + $delay);

            $this->scheduleRetry($job['id'], $retry_count, $next_retry, $queue_type, $e->getMessage());
        }
    }

    /**
     * Schedule job retry
     */
    private function scheduleRetry($job_id, $retry_count, $next_retry, $queue_type, $error_message) {
        $table = $this->getQueueTable($queue_type);

        $sql = "UPDATE {$table} SET
                status = 'failed',
                retry_count = ?,
                next_retry_at = ?,
                error_message = ?,
                updated_at = NOW()
                WHERE id = ?";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('issi', $retry_count, $next_retry, $error_message, $job_id);
        $stmt->execute();
    }

    /**
     * Update job status
     */
    private function updateJobStatus($job_id, $status, $queue_type) {
        $table = $this->getQueueTable($queue_type);

        $sql = "UPDATE {$table} SET
                status = ?,
                updated_at = NOW()";

        if ($status === 'completed') {
            $sql .= ", completed_at = NOW()";
        } elseif ($status === 'processing') {
            $sql .= ", started_at = NOW()";
        }

        $sql .= " WHERE id = ?";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('si', $status, $job_id);
        $stmt->execute();
    }

    /**
     * Get queue table name
     */
    private function getQueueTable($queue_type) {
        switch ($queue_type) {
            case self::QUEUE_EMAIL:
                return 'email_queue';
            case self::QUEUE_WHATSAPP:
                return 'whatsapp_queue';
            default:
                throw new InvalidArgumentException("Unknown queue type: {$queue_type}");
        }
    }

    /**
     * Check if processing should stop
     */
    private function shouldStopProcessing() {
        // Check execution time
        if (time() - $_SERVER['REQUEST_TIME'] > $this->max_execution_time - 30) {
            $this->logger->warning('Approaching execution time limit, stopping processing');
            return true;
        }

        // Check memory usage
        if (memory_get_usage(true) > 200 * 1024 * 1024) { // 200MB
            $this->logger->warning('High memory usage, stopping processing');
            return true;
        }

        return false;
    }

    /**
     * Lock processing to prevent concurrent execution
     */
    private function lockProcessing() {
        $sql = "INSERT INTO system_locks (lock_name, locked_at, expires_at)
                VALUES ('queue_processing', NOW(), DATE_ADD(NOW(), INTERVAL 10 MINUTE))
                ON DUPLICATE KEY UPDATE
                locked_at = NOW(),
                expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE)";

        $this->db->query($sql);
    }

    /**
     * Check if processing is locked
     */
    private function isProcessingLocked() {
        $sql = "SELECT COUNT(*) as count FROM system_locks
                WHERE lock_name = 'queue_processing'
                  AND expires_at > NOW()";

        $result = $this->db->query($sql);
        $row = $result->fetch_assoc();

        return $row['count'] > 0;
    }

    /**
     * Unlock processing
     */
    private function unlockProcessing() {
        $sql = "DELETE FROM system_locks WHERE lock_name = 'queue_processing'";
        $this->db->query($sql);
    }

    /**
     * Get queue statistics
     */
    public function getQueueStats() {
        $stats = [];

        // Email queue stats
        $sql = "SELECT
                    status,
                    COUNT(*) as count,
                    AVG(TIMESTAMPDIFF(SECOND, created_at, COALESCE(completed_at, NOW()))) as avg_processing_time
                FROM email_queue
                GROUP BY status";

        $result = $this->db->query($sql);
        $stats['email'] = [];
        while ($row = $result->fetch_assoc()) {
            $stats['email'][$row['status']] = [
                'count' => $row['count'],
                'avg_processing_time' => round($row['avg_processing_time'], 2)
            ];
        }

        // WhatsApp queue stats
        $sql = "SELECT
                    status,
                    COUNT(*) as count,
                    AVG(TIMESTAMPDIFF(SECOND, created_at, COALESCE(completed_at, NOW()))) as avg_processing_time
                FROM whatsapp_queue
                GROUP BY status";

        $result = $this->db->query($sql);
        $stats['whatsapp'] = [];
        while ($row = $result->fetch_assoc()) {
            $stats['whatsapp'][$row['status']] = [
                'count' => $row['count'],
                'avg_processing_time' => round($row['avg_processing_time'], 2)
            ];
        }

        return $stats;
    }

    /**
     * Clean up old completed jobs
     */
    public function cleanupCompletedJobs($days_old = 30) {
        $this->logger->info("Cleaning up jobs older than {$days_old} days");

        $tables = ['email_queue', 'whatsapp_queue'];
        $cleaned = 0;

        foreach ($tables as $table) {
            $sql = "DELETE FROM {$table}
                    WHERE status IN ('completed', 'permanently_failed')
                      AND completed_at < DATE_SUB(NOW(), INTERVAL ? DAY)";

            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('i', $days_old);
            $stmt->execute();

            $cleaned += $stmt->affected_rows;
        }

        $this->logger->info("Cleaned up {$cleaned} old jobs");

        return $cleaned;
    }

    /**
     * Add job to queue
     */
    public function addJob($queue_type, $data, $priority = self::PRIORITY_NORMAL) {
        $table = $this->getQueueTable($queue_type);

        $sql = "INSERT INTO {$table} (priority, data, status, created_at)
                VALUES (?, ?, 'pending', NOW())";

        $stmt = $this->db->prepare($sql);
        $data_json = json_encode($data);
        $stmt->bind_param('is', $priority, $data_json);

        if ($stmt->execute()) {
            $job_id = $this->db->insert_id;
            $this->logger->info("Job added to {$queue_type} queue", [
                'id' => $job_id,
                'priority' => $priority
            ]);
            return $job_id;
        }

        throw new Exception("Failed to add job to {$queue_type} queue");
    }

    /**
     * Get job status
     */
    public function getJobStatus($job_id, $queue_type) {
        $table = $this->getQueueTable($queue_type);

        $sql = "SELECT status, retry_count, error_message, created_at, completed_at
                FROM {$table} WHERE id = ?";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $job_id);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Pause/Resume queue processing
     */
    public function pauseQueue($queue_type) {
        $sql = "INSERT INTO system_locks (lock_name, locked_at, expires_at)
                VALUES (?, NOW(), '2038-01-01 00:00:00')
                ON DUPLICATE KEY UPDATE
                locked_at = NOW(),
                expires_at = '2038-01-01 00:00:00'";

        $stmt = $this->db->prepare($sql);
        $lock_name = "queue_pause_{$queue_type}";
        $stmt->bind_param('s', $lock_name);
        $stmt->execute();

        $this->logger->info("Queue {$queue_type} paused");
    }

    public function resumeQueue($queue_type) {
        $sql = "DELETE FROM system_locks WHERE lock_name = ?";

        $stmt = $this->db->prepare($sql);
        $lock_name = "queue_pause_{$queue_type}";
        $stmt->bind_param('s', $lock_name);
        $stmt->execute();

        $this->logger->info("Queue {$queue_type} resumed");
    }

    /**
     * Check if queue is paused
     */
    public function isQueuePaused($queue_type) {
        $sql = "SELECT COUNT(*) as count FROM system_locks
                WHERE lock_name = ? AND expires_at > NOW()";

        $stmt = $this->db->prepare($sql);
        $lock_name = "queue_pause_{$queue_type}";
        $stmt->bind_param('s', $lock_name);
        $stmt->execute();
        
        $result = $stmt->get_result()->fetch_assoc();

        return $result['count'] > 0;
    }
}
?>
