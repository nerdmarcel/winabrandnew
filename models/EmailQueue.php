<?php

/**
 * Win a Brand New - Email Queue Model
 * File: /models/EmailQueue.php
 *
 * Manages email queue operations including priority-based queueing,
 * retry logic, template variable substitution, and background processing
 * according to the Development Specification requirements.
 *
 * Features:
 * - Priority-based email queueing (1=high, 2=normal, 3=low)
 * - Retry logic with exponential backoff
 * - Template variable substitution with security
 * - Batch processing (50 emails per run)
 * - Comprehensive error handling and logging
 * - Email delivery tracking and statistics
 * - Support for HTML and text email formats
 * - Automated reminder and notification emails
 *
 * @package WinABrandNew\Models
 * @version 1.0.0
 * @author NerdMarcel Development Team
 */

namespace WinABrandNew\Models;

use WinABrandNew\Core\Database;
use WinABrandNew\Core\Config;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use Exception;

class EmailQueue
{
    /**
     * Email priority constants
     */
    public const PRIORITY_HIGH = 1;
    public const PRIORITY_NORMAL = 2;
    public const PRIORITY_LOW = 3;

    /**
     * Maximum retry attempts
     */
    public const MAX_ATTEMPTS = 3;

    /**
     * Batch size for queue processing
     */
    public const BATCH_SIZE = 50;

    /**
     * Email template paths
     */
    public const TEMPLATE_PATH = __DIR__ . '/../templates/email/';

    /**
     * Add email to queue
     *
     * @param string $toEmail Recipient email address
     * @param string $subject Email subject
     * @param string|null $bodyHtml HTML email body
     * @param string|null $bodyText Plain text email body
     * @param int $priority Email priority (1=high, 2=normal, 3=low)
     * @param array $templateVars Variables for template substitution
     * @param string|null $templateName Template file name
     * @param array $options Additional email options
     * @return int Queue ID
     * @throws Exception If queue insertion fails
     */
    public static function enqueue(
        string $toEmail,
        string $subject,
        ?string $bodyHtml = null,
        ?string $bodyText = null,
        int $priority = self::PRIORITY_NORMAL,
        array $templateVars = [],
        ?string $templateName = null,
        array $options = []
    ): int {
        try {
            // Validate email address
            if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email address: {$toEmail}");
            }

            // Validate priority
            if (!in_array($priority, [self::PRIORITY_HIGH, self::PRIORITY_NORMAL, self::PRIORITY_LOW])) {
                $priority = self::PRIORITY_NORMAL;
            }

            // Process template if specified
            if ($templateName && file_exists(self::TEMPLATE_PATH . $templateName)) {
                $templateContent = self::processTemplate($templateName, $templateVars);
                if (!$bodyHtml) {
                    $bodyHtml = $templateContent['html'] ?? null;
                }
                if (!$bodyText) {
                    $bodyText = $templateContent['text'] ?? null;
                }
            }

            // Set default send time (immediate)
            $sendAt = $options['send_at'] ?? date('Y-m-d H:i:s');

            // Extract additional options
            $toName = $options['to_name'] ?? null;
            $fromEmail = $options['from_email'] ?? Config::get('MAIL_FROM');
            $fromName = $options['from_name'] ?? Config::get('MAIL_FROM_NAME', 'Win a Brand New');
            $replyTo = $options['reply_to'] ?? null;

            // Insert into queue
            $sql = "
                INSERT INTO email_queue (
                    to_email, to_name, from_email, from_name, reply_to,
                    subject, body_html, body_text,
                    template_name, template_vars,
                    priority, send_at, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?,
                    ?, ?, NOW()
                )
            ";

            $params = [
                $toEmail, $toName, $fromEmail, $fromName, $replyTo,
                $subject, $bodyHtml, $bodyText,
                $templateName, json_encode($templateVars),
                $priority, $sendAt
            ];

            $queueId = Database::insert($sql, $params);

            self::logMessage("Email queued successfully", 'info', [
                'queue_id' => $queueId,
                'to_email' => $toEmail,
                'subject' => $subject,
                'priority' => $priority
            ]);

            return (int)$queueId;

        } catch (Exception $e) {
            self::logMessage("Failed to queue email: " . $e->getMessage(), 'error', [
                'to_email' => $toEmail,
                'subject' => $subject,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Process email queue (called by cron worker)
     *
     * @param int $batchSize Maximum emails to process in this run
     * @return array Processing statistics
     * @throws Exception If processing fails
     */
    public static function processQueue(int $batchSize = self::BATCH_SIZE): array
    {
        $startTime = microtime(true);
        $processed = 0;
        $sent = 0;
        $failed = 0;
        $errors = [];

        try {
            // Get pending emails ordered by priority and send_at
            $sql = "
                SELECT * FROM email_queue
                WHERE send_at <= NOW()
                  AND processing = 0
                  AND attempts < max_attempts
                ORDER BY priority ASC, send_at ASC
                LIMIT ?
            ";

            $emails = Database::select($sql, [$batchSize]);

            foreach ($emails as $email) {
                $processed++;

                try {
                    // Mark as processing to prevent duplicate processing
                    self::markAsProcessing($email['id']);

                    // Send the email
                    $success = self::sendEmail($email);

                    if ($success) {
                        // Mark as sent
                        self::markAsSent($email['id']);
                        $sent++;

                        self::logMessage("Email sent successfully", 'info', [
                            'queue_id' => $email['id'],
                            'to_email' => $email['to_email'],
                            'subject' => $email['subject']
                        ]);
                    } else {
                        throw new Exception("Email sending failed without specific error");
                    }

                } catch (Exception $e) {
                    // Handle failure
                    self::handleFailure($email['id'], $e->getMessage());
                    $failed++;
                    $errors[] = [
                        'queue_id' => $email['id'],
                        'error' => $e->getMessage()
                    ];

                    self::logMessage("Email sending failed", 'error', [
                        'queue_id' => $email['id'],
                        'to_email' => $email['to_email'],
                        'error' => $e->getMessage(),
                        'attempts' => $email['attempts'] + 1
                    ]);
                }
            }

            $processingTime = microtime(true) - $startTime;

            $stats = [
                'processed' => $processed,
                'sent' => $sent,
                'failed' => $failed,
                'processing_time' => $processingTime,
                'errors' => $errors
            ];

            self::logMessage("Queue processing completed", 'info', $stats);

            return $stats;

        } catch (Exception $e) {
            self::logMessage("Queue processing failed: " . $e->getMessage(), 'error');
            throw $e;
        }
    }

    /**
     * Send individual email
     *
     * @param array $emailData Email data from queue
     * @return bool Success status
     * @throws Exception If sending fails
     */
    private static function sendEmail(array $emailData): bool
    {
        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host = Config::get('MAIL_HOST');
            $mail->SMTPAuth = true;
            $mail->Username = Config::get('MAIL_USERNAME');
            $mail->Password = Config::get('MAIL_PASSWORD');
            $mail->SMTPSecure = Config::get('MAIL_ENCRYPTION', 'tls');
            $mail->Port = Config::get('MAIL_PORT', 587);
            $mail->CharSet = 'UTF-8';

            // Recipients
            $mail->setFrom($emailData['from_email'], $emailData['from_name']);
            $mail->addAddress($emailData['to_email'], $emailData['to_name'] ?? '');

            if ($emailData['reply_to']) {
                $mail->addReplyTo($emailData['reply_to']);
            }

            // Content
            $mail->Subject = $emailData['subject'];

            // Process template variables if needed
            $bodyHtml = $emailData['body_html'];
            $bodyText = $emailData['body_text'];

            if ($emailData['template_vars']) {
                $templateVars = json_decode($emailData['template_vars'], true) ?? [];
                $bodyHtml = self::substituteVariables($bodyHtml, $templateVars);
                $bodyText = self::substituteVariables($bodyText, $templateVars);
            }

            if ($bodyHtml) {
                $mail->isHTML(true);
                $mail->Body = $bodyHtml;
                $mail->AltBody = $bodyText ?? strip_tags($bodyHtml);
            } else {
                $mail->isHTML(false);
                $mail->Body = $bodyText;
            }

            // Send email
            $success = $mail->send();

            if ($success) {
                return true;
            } else {
                throw new Exception("PHPMailer send() returned false");
            }

        } catch (PHPMailerException $e) {
            throw new Exception("PHPMailer Error: " . $e->getMessage());
        } catch (Exception $e) {
            throw new Exception("Email sending error: " . $e->getMessage());
        }
    }

    /**
     * Process email template and substitute variables
     *
     * @param string $templateName Template file name
     * @param array $variables Variables for substitution
     * @return array Processed template content
     * @throws Exception If template processing fails
     */
    private static function processTemplate(string $templateName, array $variables = []): array
    {
        $htmlFile = self::TEMPLATE_PATH . $templateName . '.html';
        $textFile = self::TEMPLATE_PATH . $templateName . '.txt';

        $result = [];

        // Process HTML template
        if (file_exists($htmlFile)) {
            $htmlContent = file_get_contents($htmlFile);
            $result['html'] = self::substituteVariables($htmlContent, $variables);
        }

        // Process text template
        if (file_exists($textFile)) {
            $textContent = file_get_contents($textFile);
            $result['text'] = self::substituteVariables($textContent, $variables);
        }

        return $result;
    }

    /**
     * Substitute template variables safely (no code execution)
     *
     * @param string $content Template content
     * @param array $variables Variables to substitute
     * @return string Processed content
     */
    private static function substituteVariables(string $content, array $variables): string
    {
        if (empty($variables) || empty($content)) {
            return $content;
        }

        // Only allow simple variable substitution - no code execution
        foreach ($variables as $key => $value) {
            // Sanitize variable name (alphanumeric and underscore only)
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
                continue;
            }

            // Convert value to string and escape for security
            $value = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

            // Replace {{variable_name}} placeholders
            $content = str_replace('{{' . $key . '}}', $value, $content);
        }

        return $content;
    }

    /**
     * Mark email as processing
     *
     * @param int $queueId Queue ID
     * @return void
     */
    private static function markAsProcessing(int $queueId): void
    {
        Database::update(
            "UPDATE email_queue SET processing = 1, updated_at = NOW() WHERE id = ?",
            [$queueId]
        );
    }

    /**
     * Mark email as sent
     *
     * @param int $queueId Queue ID
     * @return void
     */
    private static function markAsSent(int $queueId): void
    {
        Database::update(
            "UPDATE email_queue SET processing = 0, processed_at = NOW(), updated_at = NOW() WHERE id = ?",
            [$queueId]
        );
    }

    /**
     * Handle email sending failure
     *
     * @param int $queueId Queue ID
     * @param string $errorMessage Error message
     * @return void
     */
    private static function handleFailure(int $queueId, string $errorMessage): void
    {
        try {
            // Update attempt count and error message
            $sql = "
                UPDATE email_queue
                SET attempts = attempts + 1,
                    last_error = ?,
                    processing = 0,
                    updated_at = NOW()
                WHERE id = ?
            ";

            Database::update($sql, [$errorMessage, $queueId]);

            // Check if max attempts reached
            $email = Database::selectOne(
                "SELECT * FROM email_queue WHERE id = ?",
                [$queueId]
            );

            if ($email && $email['attempts'] >= $email['max_attempts']) {
                // Move to failed emails table
                self::moveToFailedEmails($email, $errorMessage);
            } else {
                // Schedule retry with exponential backoff
                self::scheduleRetry($queueId, $email['attempts']);
            }

        } catch (Exception $e) {
            self::logMessage("Failed to handle email failure: " . $e->getMessage(), 'error', [
                'queue_id' => $queueId,
                'original_error' => $errorMessage
            ]);
        }
    }

    /**
     * Schedule email retry with exponential backoff
     *
     * @param int $queueId Queue ID
     * @param int $attempts Current attempt count
     * @return void
     */
    private static function scheduleRetry(int $queueId, int $attempts): void
    {
        // Exponential backoff: 1min, 5min, 30min
        $delays = [60, 300, 1800]; // seconds
        $delay = $delays[min($attempts - 1, count($delays) - 1)];

        $retryAt = date('Y-m-d H:i:s', time() + $delay);

        Database::update(
            "UPDATE email_queue SET send_at = ? WHERE id = ?",
            [$retryAt, $queueId]
        );
    }

    /**
     * Move failed email to failed_emails table
     *
     * @param array $email Email data
     * @param string $errorMessage Final error message
     * @return void
     */
    private static function moveToFailedEmails(array $email, string $errorMessage): void
    {
        try {
            $sql = "
                INSERT INTO failed_emails (
                    original_queue_id, to_email, subject, error_message,
                    attempts_made, failed_at
                ) VALUES (?, ?, ?, ?, ?, NOW())
            ";

            Database::insert($sql, [
                $email['id'],
                $email['to_email'],
                $email['subject'],
                $errorMessage,
                $email['attempts']
            ]);

            // Remove from main queue
            Database::delete("DELETE FROM email_queue WHERE id = ?", [$email['id']]);

        } catch (Exception $e) {
            self::logMessage("Failed to move email to failed_emails table: " . $e->getMessage(), 'error');
        }
    }

    /**
     * Get queue statistics
     *
     * @return array Queue statistics
     */
    public static function getQueueStats(): array
    {
        try {
            $stats = [];

            // Pending emails count
            $stats['pending'] = Database::selectOne(
                "SELECT COUNT(*) as count FROM email_queue WHERE send_at <= NOW() AND processing = 0 AND attempts < max_attempts"
            )['count'];

            // Processing emails count
            $stats['processing'] = Database::selectOne(
                "SELECT COUNT(*) as count FROM email_queue WHERE processing = 1"
            )['count'];

            // Failed emails count (max attempts reached)
            $stats['failed'] = Database::selectOne(
                "SELECT COUNT(*) as count FROM email_queue WHERE attempts >= max_attempts"
            )['count'];

            // Scheduled emails count (future send_at)
            $stats['scheduled'] = Database::selectOne(
                "SELECT COUNT(*) as count FROM email_queue WHERE send_at > NOW() AND processing = 0"
            )['count'];

            // Total failed emails
            $stats['total_failed'] = Database::selectOne(
                "SELECT COUNT(*) as count FROM failed_emails"
            )['count'];

            // Emails by priority
            $priorityStats = Database::select(
                "SELECT priority, COUNT(*) as count FROM email_queue WHERE processing = 0 GROUP BY priority"
            );

            foreach ($priorityStats as $stat) {
                $priorityName = self::getPriorityName($stat['priority']);
                $stats['by_priority'][$priorityName] = $stat['count'];
            }

            return $stats;

        } catch (Exception $e) {
            self::logMessage("Failed to get queue statistics: " . $e->getMessage(), 'error');
            return [];
        }
    }

    /**
     * Get priority name from priority number
     *
     * @param int $priority Priority number
     * @return string Priority name
     */
    private static function getPriorityName(int $priority): string
    {
        return match ($priority) {
            self::PRIORITY_HIGH => 'high',
            self::PRIORITY_NORMAL => 'normal',
            self::PRIORITY_LOW => 'low',
            default => 'unknown'
        };
    }

    /**
     * Clean old processed emails (called by maintenance cron)
     *
     * @param int $daysOld Days to keep processed emails
     * @return int Number of emails deleted
     */
    public static function cleanOldEmails(int $daysOld = 30): int
    {
        try {
            $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$daysOld} days"));

            $count = Database::delete(
                "DELETE FROM email_queue WHERE processed_at IS NOT NULL AND processed_at < ?",
                [$cutoffDate]
            );

            self::logMessage("Cleaned {$count} old processed emails", 'info', [
                'cutoff_date' => $cutoffDate,
                'emails_deleted' => $count
            ]);

            return $count;

        } catch (Exception $e) {
            self::logMessage("Failed to clean old emails: " . $e->getMessage(), 'error');
            return 0;
        }
    }

    /**
     * Send winner notification email
     *
     * @param array $participant Winner participant data
     * @param string $claimToken Secure claim token
     * @return int Queue ID
     */
    public static function sendWinnerNotification(array $participant, string $claimToken): int
    {
        $templateVars = [
            'winner_name' => $participant['first_name'] . ' ' . $participant['last_name'],
            'first_name' => $participant['first_name'],
            'claim_link' => Config::get('APP_URL') . '/claim/' . $claimToken,
            'prize_name' => $participant['game_name'] ?? 'Brand New Prize',
            'completion_time' => number_format($participant['total_time_all_questions'], 3) . ' seconds'
        ];

        return self::enqueue(
            $participant['user_email'],
            'Congratulations! You\'ve Won!',
            null,
            null,
            self::PRIORITY_HIGH,
            $templateVars,
            'winner_notification',
            [
                'to_name' => $participant['first_name'] . ' ' . $participant['last_name']
            ]
        );
    }

    /**
     * Send tracking update email
     *
     * @param array $fulfillment Prize fulfillment data
     * @return int Queue ID
     */
    public static function sendTrackingUpdate(array $fulfillment): int
    {
        $templateVars = [
            'recipient_name' => $fulfillment['shipping_name'],
            'tracking_number' => $fulfillment['tracking_number'],
            'tracking_url' => $fulfillment['tracking_url'] ?? '#',
            'shipping_provider' => $fulfillment['shipping_provider'] ?? 'Courier',
            'estimated_delivery' => $fulfillment['estimated_delivery'] ?? 'Unknown'
        ];

        return self::enqueue(
            $fulfillment['participant_email'],
            'Your Prize is on the Way! Tracking Information',
            null,
            null,
            self::PRIORITY_HIGH,
            $templateVars,
            'tracking_update',
            [
                'to_name' => $fulfillment['shipping_name']
            ]
        );
    }

    /**
     * Send unclaimed prize reminder
     *
     * @param array $participant Participant data
     * @param string $claimToken Secure claim token
     * @return int Queue ID
     */
    public static function sendUnclaimedReminder(array $participant, string $claimToken): int
    {
        $templateVars = [
            'winner_name' => $participant['first_name'] . ' ' . $participant['last_name'],
            'first_name' => $participant['first_name'],
            'claim_link' => Config::get('APP_URL') . '/claim/' . $claimToken,
            'prize_name' => $participant['game_name'] ?? 'Brand New Prize',
            'days_remaining' => 23 // 30 days total - 7 days since win
        ];

        return self::enqueue(
            $participant['user_email'],
            'Reminder: Claim Your Prize!',
            null,
            null,
            self::PRIORITY_NORMAL,
            $templateVars,
            'unclaimed_reminder',
            [
                'to_name' => $participant['first_name'] . ' ' . $participant['last_name']
            ]
        );
    }

    /**
     * Health check for monitoring
     *
     * @return array Health status
     */
    public static function healthCheck(): array
    {
        try {
            $stats = self::getQueueStats();

            return [
                'status' => 'healthy',
                'pending_emails' => $stats['pending'] ?? 0,
                'processing_emails' => $stats['processing'] ?? 0,
                'failed_emails' => $stats['failed'] ?? 0,
                'queue_backlog' => ($stats['pending'] ?? 0) > 1000 ? 'high' : 'normal'
            ];

        } catch (Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Log email queue messages
     *
     * @param string $message Log message
     * @param string $level Log level
     * @param array $context Additional context
     * @return void
     */
    private static function logMessage(string $message, string $level = 'info', array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = $context ? ' | Context: ' . json_encode($context) : '';
        $logMessage = "[{$timestamp}] [{$level}] {$message}{$contextStr}" . PHP_EOL;

        // Log to queue log file
        if (Config::get('LOG_QUEUE_ENABLED', true)) {
            $logFile = Config::get('LOG_PATH', '/var/log/winabrandnew') . '/queue.log';

            if (is_writable(dirname($logFile))) {
                file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
            }
        }

        // Also log errors to error_log
        if ($level === 'error') {
            error_log("EmailQueue Error: {$message}");
        }
    }
}
