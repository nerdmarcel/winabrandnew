<?php
declare(strict_types=1);

/**
 * File: queues/email_worker.php
 * Location: queues/email_worker.php
 *
 * WinABN Email Queue Worker
 *
 * Cron job worker that processes the email queue, sends emails via SMTP,
 * and handles retry logic for failed deliveries.
 *
 * Usage: * * * * * /usr/bin/php /path/to/winabn/queues/email_worker.php
 *
 * @package WinABN\Queues
 * @author WinABN Development Team
 * @version 1.0
 */

// Prevent direct web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from command line');
}

// Set working directory to application root
chdir(dirname(__DIR__));

// Bootstrap the application
require_once __DIR__ . '/../public/bootstrap.php';

use WinABN\Core\{EmailQueue, EmailSender, Database};

/**
 * Email Worker Class
 */
class EmailWorker
{
    /**
     * Email queue instance
     *
     * @var EmailQueue
     */
    private EmailQueue $emailQueue;

    /**
     * Email sender instance
     *
     * @var EmailSender
     */
    private EmailSender $emailSender;

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
        'sent' => 0,
        'failed' => 0,
        'skipped' => 0
    ];

    /**
     * Start time for performance tracking
     *
     * @var float
     */
    private float $startTime;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->startTime = microtime(true);
        $this->config = $this->getWorkerConfig();
        $this->emailQueue = new EmailQueue();
        $this->emailSender = new EmailSender();

        // Set memory and time limits
        ini_set('memory_limit', $this->config['memory_limit']);
        set_time_limit($this->config['time_limit']);
    }

    /**
     * Run the email worker
     *
     * @return void
     */
    public function run(): void
    {
        try {
            $this->log('info', 'Email worker started', [
                'pid' => getmypid(),
                'memory_limit' => ini_get('memory_limit'),
                'time_limit' => ini_get('max_execution_time')
            ]);

            // Check database connection
            if (!Database::healthCheck()) {
                throw new Exception('Database health check failed');
            }

            // Reset stuck emails first
            $this->resetStuckEmails();

            // Test email connection
            $connectionTest = $this->emailSender->testConnection();
            if (!$connectionTest['success']) {
                throw new Exception('Email connection test failed: ' . $connectionTest['error']);
            }

            // Process email batches
            $this->processBatches();

            // Cleanup old emails
            $this->cleanupOldEmails();

            // Log completion
            $this->logCompletion();

        } catch (Exception $e) {
            $this->log('error', 'Email worker failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            exit(1);
        }
    }

    /**
     * Process email batches
     *
     * @return void
     */
    private function processBatches(): void
    {
        $maxBatches = $this->config['max_batches'];
        $batchSize = $this->config['batch_size'];
        $batchesProcessed = 0;

        while ($batchesProcessed < $maxBatches) {
            // Get next batch of emails
            $emails = $this->emailQueue->getNextBatch($batchSize);

            if (empty($emails)) {
                $this->log('info', 'No emails in queue to process');
                break;
            }

            $this->log('info', 'Processing email batch', [
                'batch_number' => $batchesProcessed + 1,
                'email_count' => count($emails)
            ]);

            // Process each email in the batch
            foreach ($emails as $email) {
                $this->processEmail($email);

                // Check memory usage
                if ($this->isMemoryLimitApproaching()) {
                    $this->log('warning', 'Memory limit approaching, stopping processing');
                    break 2;
                }

                // Check time limit
                if ($this->isTimeLimitApproaching()) {
                    $this->log('warning', 'Time limit approaching, stopping processing');
                    break 2;
                }
            }

            $batchesProcessed++;

            // Brief pause between batches to prevent overwhelming the SMTP server
            if ($batchesProcessed < $maxBatches && !empty($emails)) {
                sleep($this->config['batch_delay']);
            }
        }
    }

    /**
     * Process individual email
     *
     * @param array<string, mixed> $email Email data
     * @return void
     */
    private function processEmail(array $email): void
    {
        $queueId = (int) $email['id'];
        $this->stats['processed']++;

        try {
            // Mark email as being processed
            if (!$this->emailQueue->markAsProcessing($queueId)) {
                $this->log('warning', 'Could not mark email as processing', [
                    'queue_id' => $queueId,
                    'email' => $email['to_email']
                ]);
                $this->stats['skipped']++;
                return;
            }

            // Parse metadata if present
            $metadata = [];
            if (!empty($email['metadata_json'])) {
                $metadata = json_decode($email['metadata_json'], true) ?? [];
            }

            // Send the email
            $result = $this->emailSender->send(
                $email['to_email'],
                $email['subject'],
                $email['body_html'],
                $email['body_text'],
                $metadata
            );

            if ($result['success']) {
                // Mark as sent
                $this->emailQueue->markAsSent($queueId, $result['message_id'] ?? null);
                $this->stats['sent']++;

                $this->log('info', 'Email sent successfully', [
                    'queue_id' => $queueId,
                    'to_email' => $email['to_email'],
                    'subject' => $email['subject'],
                    'message_id' => $result['message_id'] ?? null
                ]);
            } else {
                // Mark as failed
                $this->emailQueue->markAsFailed($queueId, $result['error']);
                $this->stats['failed']++;

                $this->log('error', 'Email sending failed', [
                    'queue_id' => $queueId,
                    'to_email' => $email['to_email'],
                    'subject' => $email['subject'],
                    'error' => $result['error'],
                    'attempt' => $email['attempts'] + 1
                ]);
            }

        } catch (Exception $e) {
            // Mark as failed with exception details
            $this->emailQueue->markAsFailed($queueId, $e->getMessage());
            $this->stats['failed']++;

            $this->log('error', 'Email processing exception', [
                'queue_id' => $queueId,
                'to_email' => $email['to_email'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Reset stuck emails
     *
     * @return void
     */
    private function resetStuckEmails(): void
    {
        $resetCount = $this->emailQueue->resetStuckEmails($this->config['stuck_timeout']);

        if ($resetCount > 0) {
            $this->log('info', 'Reset stuck emails', ['count' => $resetCount]);
        }
    }

    /**
     * Cleanup old emails
     *
     * @return void
     */
    private function cleanupOldEmails(): void
    {
        // Only run cleanup periodically (every hour)
        $lastCleanup = $this->getLastCleanupTime();
        $cleanupInterval = $this->config['cleanup_interval'] * 60; // Convert to seconds

        if (time() - $lastCleanup < $cleanupInterval) {
            return;
        }

        $deletedCount = $this->emailQueue->cleanupOldEmails($this->config['cleanup_days']);

        if ($deletedCount > 0) {
            $this->log('info', 'Cleaned up old emails', ['count' => $deletedCount]);
        }

        $this->setLastCleanupTime(time());
    }

    /**
     * Check if memory limit is approaching
     *
     * @return bool
     */
    private function isMemoryLimitApproaching(): bool
    {
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));

        // Stop if using more than 80% of memory limit
        return $memoryUsage > ($memoryLimit * 0.8);
    }

    /**
     * Check if time limit is approaching
     *
     * @return bool
     */
    private function isTimeLimitApproaching(): bool
    {
        $executionTime = microtime(true) - $this->startTime;
        $timeLimit = $this->config['time_limit'];

        // Stop if running for more than 80% of time limit
        return $executionTime > ($timeLimit * 0.8);
    }

    /**
     * Parse memory limit string to bytes
     *
     * @param string $memoryLimit Memory limit string
     * @return int Memory limit in bytes
     */
    private function parseMemoryLimit(string $memoryLimit): int
    {
        $memoryLimit = trim($memoryLimit);
        $unit = strtolower(substr($memoryLimit, -1));
        $value = (int) substr($memoryLimit, 0, -1);

        switch ($unit) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }

        return $value;
    }

    /**
     * Get last cleanup time from cache
     *
     * @return int
     */
    private function getLastCleanupTime(): int
    {
        $cacheFile = sys_get_temp_dir() . '/winabn_email_cleanup_time';

        if (file_exists($cacheFile)) {
            return (int) file_get_contents($cacheFile);
        }

        return 0;
    }

    /**
     * Set last cleanup time to cache
     *
     * @param int $time Timestamp
     * @return void
     */
    private function setLastCleanupTime(int $time): void
    {
        $cacheFile = sys_get_temp_dir() . '/winabn_email_cleanup_time';
        file_put_contents($cacheFile, (string) $time);
    }

    /**
     * Log worker completion
     *
     * @return void
     */
    private function logCompletion(): void
    {
        $executionTime = microtime(true) - $this->startTime;
        $memoryUsage = memory_get_peak_usage(true);

        $this->log('info', 'Email worker completed', [
            'stats' => $this->stats,
            'execution_time_seconds' => round($executionTime, 2),
            'memory_peak_mb' => round($memoryUsage / 1024 / 1024, 2)
        ]);

        // Also log queue statistics
        $queueStats = $this->emailQueue->getQueueStats();
        $this->log('info', 'Queue statistics', $queueStats);
    }

    /**
     * Get worker configuration
     *
     * @return array<string, mixed>
     */
    private function getWorkerConfig(): array
    {
        return [
            'batch_size' => (int) env('EMAIL_WORKER_BATCH_SIZE', 50),
            'max_batches' => (int) env('EMAIL_WORKER_MAX_BATCHES', 10),
            'batch_delay' => (int) env('EMAIL_WORKER_BATCH_DELAY', 1), // seconds
            'stuck_timeout' => (int) env('EMAIL_WORKER_STUCK_TIMEOUT', 15), // minutes
            'cleanup_interval' => (int) env('EMAIL_WORKER_CLEANUP_INTERVAL', 60), // minutes
            'cleanup_days' => (int) env('EMAIL_WORKER_CLEANUP_DAYS', 30),
            'memory_limit' => env('EMAIL_WORKER_MEMORY_LIMIT', '256M'),
            'time_limit' => (int) env('EMAIL_WORKER_TIME_LIMIT', 50) // seconds
        ];
    }

    /**
     * Log worker activity
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array<string, mixed> $context Log context
     * @return void
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $context['worker'] = 'email_worker';
        $context['pid'] = getmypid();

        if (function_exists('app_log')) {
            app_log($level, $message, $context);
        } else {
            // Fallback logging
            $logMessage = "[" . date('Y-m-d H:i:s') . "] [$level] $message " . json_encode($context);
            echo $logMessage . "\n";

            // Also write to log file if possible
            $logFile = __DIR__ . '/../logs/email_worker.log';
            if (is_writable(dirname($logFile))) {
                file_put_contents($logFile, $logMessage . "\n", FILE_APPEND | LOCK_EX);
            }
        }
    }
}

/**
 * Handle script termination signals
 */
function handleSignal(int $signal): void
{
    switch ($signal) {
        case SIGTERM:
        case SIGINT:
            echo "Received termination signal ($signal), shutting down gracefully...\n";
            exit(0);
        case SIGUSR1:
            echo "Received USR1 signal, logging current status...\n";
            // Could add status logging here
            break;
    }
}

// Register signal handlers if available
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, 'handleSignal');
    pcntl_signal(SIGINT, 'handleSignal');
    pcntl_signal(SIGUSR1, 'handleSignal');
}

/**
 * Main execution
 */
try {
    // Create lock file to prevent multiple instances
    $lockFile = sys_get_temp_dir() . '/winabn_email_worker.lock';
    $lockHandle = fopen($lockFile, 'w');

    if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        echo "Email worker is already running (lock file exists)\n";
        exit(0);
    }

    // Write PID to lock file
    fwrite($lockHandle, (string) getmypid());
    fflush($lockHandle);

    // Run the worker
    $worker = new EmailWorker();
    $worker->run();

    // Release lock
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    unlink($lockFile);

    exit(0);

} catch (Exception $e) {
    echo "Email worker fatal error: " . $e->getMessage() . "\n";

    // Release lock if we have it
    if (isset($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
    }

    exit(1);
}
