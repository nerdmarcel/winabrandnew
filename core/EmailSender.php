<?php
declare(strict_types=1);

/**
 * File: core/EmailSender.php
 * Location: core/EmailSender.php
 *
 * WinABN SMTP Email Sender
 *
 * Handles actual email sending via SMTP with multiple provider support,
 * template processing, and delivery tracking for the WinABN platform.
 *
 * @package WinABN\Core
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Core;

use Exception;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class EmailSender
{
    /**
     * PHPMailer instance
     *
     * @var PHPMailer
     */
    private PHPMailer $mailer;

    /**
     * Email configuration
     *
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * Template cache
     *
     * @var array<string, string>
     */
    private static array $templateCache = [];

    /**
     * Delivery statistics
     *
     * @var array<string, int>
     */
    private array $stats = [
        'sent' => 0,
        'failed' => 0,
        'retries' => 0
    ];

    /**
     * Constructor
     *
     * @param array<string, mixed>|null $config Email configuration
     */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? $this->getDefaultConfig();
        $this->mailer = new PHPMailer(true);
        $this->configureSMTP();
    }

    /**
     * Send email
     *
     * @param string $toEmail Recipient email
     * @param string $subject Email subject
     * @param string $bodyHtml HTML body
     * @param string $bodyText Plain text body
     * @param array<string, mixed> $options Additional options
     * @return array<string, mixed> Send result
     */
    public function send(
        string $toEmail,
        string $subject,
        string $bodyHtml,
        string $bodyText = '',
        array $options = []
    ): array {
        try {
            // Validate email address
            if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email address: $toEmail");
            }

            // Clear previous recipients
            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            $this->mailer->clearCustomHeaders();

            // Set recipient
            $this->mailer->addAddress($toEmail, $options['to_name'] ?? '');

            // Set subject
            $this->mailer->Subject = $subject;

            // Set email bodies
            $this->mailer->isHTML(true);
            $this->mailer->Body = $bodyHtml;
            $this->mailer->AltBody = $bodyText ?: strip_tags($bodyHtml);

            // Add CC/BCC if specified
            if (!empty($options['cc'])) {
                foreach ((array) $options['cc'] as $cc) {
                    $this->mailer->addCC($cc);
                }
            }

            if (!empty($options['bcc'])) {
                foreach ((array) $options['bcc'] as $bcc) {
                    $this->mailer->addBCC($bcc);
                }
            }

            // Add attachments if specified
            if (!empty($options['attachments'])) {
                foreach ($options['attachments'] as $attachment) {
                    if (is_string($attachment)) {
                        $this->mailer->addAttachment($attachment);
                    } else if (is_array($attachment)) {
                        $this->mailer->addAttachment(
                            $attachment['path'],
                            $attachment['name'] ?? '',
                            $attachment['encoding'] ?? 'base64',
                            $attachment['type'] ?? ''
                        );
                    }
                }
            }

            // Add custom headers
            if (!empty($options['headers'])) {
                foreach ($options['headers'] as $name => $value) {
                    $this->mailer->addCustomHeader($name, $value);
                }
            }

            // Set reply-to if specified
            if (!empty($options['reply_to'])) {
                $this->mailer->addReplyTo(
                    $options['reply_to'],
                    $options['reply_to_name'] ?? ''
                );
            }

            // Send email
            $success = $this->mailer->send();

            if ($success) {
                $this->stats['sent']++;

                $result = [
                    'success' => true,
                    'message' => 'Email sent successfully',
                    'message_id' => $this->extractMessageId(),
                    'to_email' => $toEmail,
                    'subject' => $subject
                ];

                $this->logEmailActivity('email_sent', $result);
                return $result;
            }

            throw new Exception('PHPMailer send returned false');

        } catch (PHPMailerException $e) {
            $this->stats['failed']++;

            $result = [
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'to_email' => $toEmail,
                'subject' => $subject
            ];

            $this->logEmailActivity('email_failed', $result);
            return $result;

        } catch (Exception $e) {
            $this->stats['failed']++;

            $result = [
                'success' => false,
                'error' => $e->getMessage(),
                'to_email' => $toEmail,
                'subject' => $subject
            ];

            $this->logEmailActivity('email_failed', $result);
            return $result;
        }
    }

    /**
     * Send email using template
     *
     * @param string $toEmail Recipient email
     * @param string $templateName Template name
     * @param array<string, mixed> $variables Template variables
     * @param array<string, mixed> $options Additional options
     * @return array<string, mixed> Send result
     */
    public function sendTemplate(
        string $toEmail,
        string $templateName,
        array $variables = [],
        array $options = []
    ): array {
        try {
            $template = $this->loadTemplate($templateName);

            // Process template variables
            $subject = $this->processTemplateVariables($template['subject'], $variables);
            $bodyHtml = $this->processTemplateVariables($template['html'], $variables);
            $bodyText = $this->processTemplateVariables($template['text'] ?? '', $variables);

            return $this->send($toEmail, $subject, $bodyHtml, $bodyText, $options);

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => "Template error: " . $e->getMessage(),
                'template' => $templateName,
                'to_email' => $toEmail
            ];
        }
    }

    /**
     * Send winner notification email
     *
     * @param string $toEmail Winner email
     * @param array<string, mixed> $winnerData Winner information
     * @return array<string, mixed> Send result
     */
    public function sendWinnerNotification(string $toEmail, array $winnerData): array
    {
        return $this->sendTemplate($toEmail, 'winner_notification', $winnerData, [
            'to_name' => $winnerData['first_name'] . ' ' . $winnerData['last_name']
        ]);
    }

    /**
     * Send payment confirmation email
     *
     * @param string $toEmail Participant email
     * @param array<string, mixed> $paymentData Payment information
     * @return array<string, mixed> Send result
     */
    public function sendPaymentConfirmation(string $toEmail, array $paymentData): array
    {
        return $this->sendTemplate($toEmail, 'payment_confirmation', $paymentData, [
            'to_name' => $paymentData['first_name'] . ' ' . $paymentData['last_name']
        ]);
    }

    /**
     * Send prize claim reminder
     *
     * @param string $toEmail Winner email
     * @param array<string, mixed> $reminderData Reminder information
     * @return array<string, mixed> Send result
     */
    public function sendPrizeClaimReminder(string $toEmail, array $reminderData): array
    {
        return $this->sendTemplate($toEmail, 'prize_claim_reminder', $reminderData, [
            'to_name' => $reminderData['first_name'] . ' ' . $reminderData['last_name']
        ]);
    }

    /**
     * Send tracking notification
     *
     * @param string $toEmail Winner email
     * @param array<string, mixed> $trackingData Tracking information
     * @return array<string, mixed> Send result
     */
    public function sendTrackingNotification(string $toEmail, array $trackingData): array
    {
        return $this->sendTemplate($toEmail, 'tracking_notification', $trackingData, [
            'to_name' => $trackingData['first_name'] . ' ' . $trackingData['last_name']
        ]);
    }

    /**
     * Test email connection
     *
     * @return array<string, mixed> Test result
     */
    public function testConnection(): array
    {
        try {
            // Test SMTP connection
            $this->mailer->smtpConnect();
            $this->mailer->smtpClose();

            return [
                'success' => true,
                'message' => 'SMTP connection successful',
                'host' => $this->config['host'],
                'port' => $this->config['port']
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'host' => $this->config['host'],
                'port' => $this->config['port']
            ];
        }
    }

    /**
     * Send test email
     *
     * @param string $toEmail Test recipient
     * @return array<string, mixed> Send result
     */
    public function sendTestEmail(string $toEmail): array
    {
        $subject = 'WinABN Test Email - ' . date('Y-m-d H:i:s');
        $bodyHtml = $this->getTestEmailHtml();
        $bodyText = $this->getTestEmailText();

        return $this->send($toEmail, $subject, $bodyHtml, $bodyText);
    }

    /**
     * Get delivery statistics
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return [
            'sent' => $this->stats['sent'],
            'failed' => $this->stats['failed'],
            'retries' => $this->stats['retries'],
            'success_rate' => $this->calculateSuccessRate()
        ];
    }

    /**
     * Reset delivery statistics
     *
     * @return void
     */
    public function resetStats(): void
    {
        $this->stats = ['sent' => 0, 'failed' => 0, 'retries' => 0];
    }

    /**
     * Configure SMTP settings
     *
     * @return void
     * @throws Exception
     */
    private function configureSMTP(): void
    {
        try {
            // Server settings
            $this->mailer->isSMTP();
            $this->mailer->Host = $this->config['host'];
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = $this->config['username'];
            $this->mailer->Password = $this->config['password'];
            $this->mailer->Port = $this->config['port'];

            // Encryption
            if ($this->config['encryption'] === 'tls') {
                $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else if ($this->config['encryption'] === 'ssl') {
                $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            }

            // Timeout settings
            $this->mailer->Timeout = $this->config['timeout'] ?? 30;
            $this->mailer->SMTPKeepAlive = false; // Don't keep connection alive for queue processing

            // From address
            $this->mailer->setFrom(
                $this->config['from_address'],
                $this->config['from_name']
            );

            // Debug level (only in development)
            if (env('APP_DEBUG', false)) {
                $this->mailer->SMTPDebug = SMTP::DEBUG_CONNECTION;
                $this->mailer->Debugoutput = function($str, $level) {
                    $this->logEmailActivity('smtp_debug', [
                        'level' => $level,
                        'message' => trim($str)
                    ]);
                };
            } else {
                $this->mailer->SMTPDebug = SMTP::DEBUG_OFF;
            }

            // Character set
            $this->mailer->CharSet = 'UTF-8';
            $this->mailer->Encoding = 'base64';

            // Additional options
            $this->mailer->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ];

        } catch (Exception $e) {
            throw new Exception("SMTP configuration failed: " . $e->getMessage());
        }
    }

    /**
     * Get default email configuration
     *
     * @return array<string, mixed>
     */
    private function getDefaultConfig(): array
    {
        return [
            'host' => env('MAIL_HOST', 'smtp.gmail.com'),
            'port' => (int) env('MAIL_PORT', 587),
            'username' => env('MAIL_USERNAME', ''),
            'password' => env('MAIL_PASSWORD', ''),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'from_address' => env('MAIL_FROM_ADDRESS', 'noreply@winabn.com'),
            'from_name' => env('MAIL_FROM_NAME', 'WinABN Platform'),
            'timeout' => (int) env('MAIL_TIMEOUT', 30)
        ];
    }

    /**
     * Load email template
     *
     * @param string $templateName Template name
     * @return array<string, string> Template data
     * @throws Exception
     */
    private function loadTemplate(string $templateName): array
    {
        // Check cache first
        $cacheKey = $templateName;
        if (isset(self::$templateCache[$cacheKey])) {
            return self::$templateCache[$cacheKey];
        }

        $templatePath = WINABN_ROOT_DIR . "/views/emails/{$templateName}.php";

        if (!file_exists($templatePath)) {
            throw new Exception("Email template not found: $templateName");
        }

        // Load template file
        ob_start();
        include $templatePath;
        $templateContent = ob_get_clean();

        // Parse template content
        $template = $this->parseTemplate($templateContent);

        // Cache template
        self::$templateCache[$cacheKey] = $template;

        return $template;
    }

    /**
     * Parse template content
     *
     * @param string $content Template content
     * @return array<string, string>
     * @throws Exception
     */
    private function parseTemplate(string $content): array
    {
        // Extract subject from template
        if (!preg_match('/@subject\s*:\s*(.+)/i', $content, $subjectMatch)) {
            throw new Exception('Template missing @subject directive');
        }

        $subject = trim($subjectMatch[1]);

        // Extract HTML content
        if (preg_match('/@html\s*:(.*?)(?=@text|$)/s', $content, $htmlMatch)) {
            $html = trim($htmlMatch[1]);
        } else {
            throw new Exception('Template missing @html directive');
        }

        // Extract text content (optional)
        $text = '';
        if (preg_match('/@text\s*:(.*?)$/s', $content, $textMatch)) {
            $text = trim($textMatch[1]);
        }

        return [
            'subject' => $subject,
            'html' => $html,
            'text' => $text
        ];
    }

    /**
     * Process template variables
     *
     * @param string $content Template content
     * @param array<string, mixed> $variables Variables to replace
     * @return string Processed content
     */
    private function processTemplateVariables(string $content, array $variables): string
    {
        // Add default variables
        $defaultVariables = [
            'app_name' => env('APP_NAME', 'WinABN'),
            'app_url' => env('APP_URL', 'https://winabn.com'),
            'support_email' => env('MAIL_FROM_ADDRESS', 'support@winabn.com'),
            'current_year' => date('Y'),
            'current_date' => date('Y-m-d'),
            'current_datetime' => date('Y-m-d H:i:s')
        ];

        $allVariables = array_merge($defaultVariables, $variables);

        // Replace variables in format {{variable_name}}
        foreach ($allVariables as $key => $value) {
            if (is_scalar($value)) {
                $content = str_replace("{{" . $key . "}}", (string) $value, $content);
            }
        }

        // Process conditional blocks {{#if variable}}...{{/if}}
        $content = preg_replace_callback(
            '/\{\{#if\s+(\w+)\}\}(.*?)\{\{\/if\}\}/s',
            function($matches) use ($allVariables) {
                $variable = $matches[1];
                $content = $matches[2];

                return !empty($allVariables[$variable]) ? $content : '';
            },
            $content
        );

        return $content;
    }

    /**
     * Extract message ID from headers
     *
     * @return string|null
     */
    private function extractMessageId(): ?string
    {
        $lastMessageID = $this->mailer->getLastMessageID();
        return $lastMessageID ?: null;
    }

    /**
     * Calculate success rate
     *
     * @return float
     */
    private function calculateSuccessRate(): float
    {
        $total = $this->stats['sent'] + $this->stats['failed'];

        if ($total === 0) {
            return 0.0;
        }

        return round(($this->stats['sent'] / $total) * 100, 2);
    }

    /**
     * Get test email HTML content
     *
     * @return string
     */
    private function getTestEmailHtml(): string
    {
        return '
        <html>
        <head>
            <meta charset="UTF-8">
            <title>WinABN Test Email</title>
        </head>
        <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
            <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
                <h1 style="color: #007cba;">WinABN Test Email</h1>

                <p>This is a test email from the WinABN platform.</p>

                <div style="background-color: #f4f4f4; padding: 15px; border-radius: 5px; margin: 20px 0;">
                    <h3>System Information</h3>
                    <ul>
                        <li><strong>Sent at:</strong> ' . date('Y-m-d H:i:s T') . '</li>
                        <li><strong>Server:</strong> ' . ($_SERVER['SERVER_NAME'] ?? 'localhost') . '</li>
                        <li><strong>PHP Version:</strong> ' . PHP_VERSION . '</li>
                        <li><strong>Environment:</strong> ' . env('APP_ENV', 'development') . '</li>
                    </ul>
                </div>

                <p>If you received this email, your email configuration is working correctly.</p>

                <hr style="border: none; border-top: 1px solid #ddd; margin: 30px 0;">

                <p style="color: #666; font-size: 14px;">
                    This is an automated test email from WinABN Competition Platform.<br>
                    If you received this email in error, please disregard it.
                </p>
            </div>
        </body>
        </html>';
    }

    /**
     * Get test email text content
     *
     * @return string
     */
    private function getTestEmailText(): string
    {
        return "WinABN Test Email\n\n" .
               "This is a test email from the WinABN platform.\n\n" .
               "System Information:\n" .
               "- Sent at: " . date('Y-m-d H:i:s T') . "\n" .
               "- Server: " . ($_SERVER['SERVER_NAME'] ?? 'localhost') . "\n" .
               "- PHP Version: " . PHP_VERSION . "\n" .
               "- Environment: " . env('APP_ENV', 'development') . "\n\n" .
               "If you received this email, your email configuration is working correctly.\n\n" .
               "---\n" .
               "This is an automated test email from WinABN Competition Platform.\n" .
               "If you received this email in error, please disregard it.";
    }

    /**
     * Log email activity
     *
     * @param string $activity Activity type
     * @param array<string, mixed> $context Activity context
     * @return void
     */
    private function logEmailActivity(string $activity, array $context = []): void
    {
        if (function_exists('app_log')) {
            app_log('info', "Email sender: $activity", $context);
        }
    }
}
