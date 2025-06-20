<?php

/**
 * Win a Brand New - Webhook Controller
 * File: /controllers/WebhookController.php
 *
 * Handles payment webhook processing and signature verification according to the Development Specification:
 * - Mollie webhook verification and signature validation
 * - Payment status updates and participant notification
 * - Retry mechanism for failed webhook calls
 * - Round completion triggering when full
 * - Secure webhook endpoint protection
 * - Audit logging for all webhook events
 *
 * Key Features:
 * - Mollie webhook signature verification
 * - Payment status processing (paid, failed, cancelled, expired)
 * - Participant payment status updates
 * - Winner notification email queue triggers
 * - Round completion detection and handling
 * - Retry mechanism with exponential backoff
 * - IP whitelisting for webhook security
 * - Comprehensive audit trail
 *
 * @package WinABrandNew\Controllers
 * @version 1.0.0
 * @author NerdMarcel Development Team
 */

namespace WinABrandNew\Controllers;

use WinABrandNew\Core\Database;
use WinABrandNew\Core\Security;
use WinABrandNew\Core\Config;
use WinABrandNew\Controllers\BaseController;
use WinABrandNew\Models\UserAction;
use WinABrandNew\Models\Participant;
use WinABrandNew\Models\Round;
use WinABrandNew\Models\Analytics;
use WinABrandNew\Services\EmailQueue;
use WinABrandNew\Services\MollieService;
use Exception;

class WebhookController extends BaseController
{
    /**
     * Mollie webhook allowed IP ranges for security
     */
    private const MOLLIE_IP_RANGES = [
        '194.150.6.0/24',
        '194.150.7.0/24'
    ];

    /**
     * Maximum retry attempts for failed webhook processing
     */
    private const MAX_RETRY_ATTEMPTS = 5;

    /**
     * Retry delay in seconds (exponential backoff)
     */
    private const RETRY_DELAY_BASE = 60;

    /**
     * Process Mollie webhook
     *
     * @return array Response for webhook endpoint
     */
    public function processMollieWebhook(): array
    {
        try {
            // Verify IP whitelist for security
            if (!$this->verifyMollieIP()) {
                $this->logSecurityIncident('Invalid IP for webhook', [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
                ]);
                return $this->error("Unauthorized", 403);
            }

            // Get webhook payload
            $payload = file_get_contents('php://input');
            $data = json_decode($payload, true);

            if (!$data || !isset($data['id'])) {
                return $this->error("Invalid webhook payload", 400);
            }

            // Verify webhook signature
            if (!$this->verifyMollieSignature($payload)) {
                $this->logSecurityIncident('Invalid webhook signature', [
                    'payload_hash' => hash('sha256', $payload)
                ]);
                return $this->error("Invalid signature", 403);
            }

            $paymentId = $data['id'];

            // Process the webhook with retry mechanism
            $result = $this->processWebhookWithRetry($paymentId);

            if ($result['success']) {
                return $this->success("Webhook processed successfully");
            } else {
                return $this->error("Webhook processing failed: " . $result['error'], 500);
            }

        } catch (Exception $e) {
            error_log("Webhook processing error: " . $e->getMessage());

            UserAction::log('webhook_error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->error("Internal server error", 500);
        }
    }

    /**
     * Verify Mollie IP address
     *
     * @return bool True if IP is allowed
     */
    private function verifyMollieIP(): bool
    {
        $clientIP = $_SERVER['REMOTE_ADDR'] ?? '';

        // Skip IP verification in development mode
        if (Config::get('app.debug', false)) {
            return true;
        }

        foreach (self::MOLLIE_IP_RANGES as $range) {
            if ($this->ipInRange($clientIP, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if IP address is in CIDR range
     *
     * @param string $ip IP address to check
     * @param string $range CIDR range
     * @return bool True if IP is in range
     */
    private function ipInRange(string $ip, string $range): bool
    {
        list($subnet, $bits) = explode('/', $range);

        $ip_long = ip2long($ip);
        $subnet_long = ip2long($subnet);
        $mask = -1 << (32 - $bits);
        $subnet_long &= $mask;

        return ($ip_long & $mask) == $subnet_long;
    }

    /**
     * Verify Mollie webhook signature
     *
     * @param string $payload Raw webhook payload
     * @return bool True if signature is valid
     */
    private function verifyMollieSignature(string $payload): bool
    {
        $signature = $_SERVER['HTTP_X_MOLLIE_SIGNATURE'] ?? '';

        if (empty($signature)) {
            return false;
        }

        $webhookSecret = Config::get('mollie.webhook_secret');
        if (empty($webhookSecret)) {
            error_log("Mollie webhook secret not configured");
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);

        return hash_equals($signature, $expectedSignature);
    }

    /**
     * Process webhook with retry mechanism
     *
     * @param string $paymentId Mollie payment ID
     * @return array Processing result
     */
    private function processWebhookWithRetry(string $paymentId): array
    {
        $attempts = 0;
        $lastError = '';

        while ($attempts < self::MAX_RETRY_ATTEMPTS) {
            try {
                $result = $this->processPaymentWebhook($paymentId);

                if ($result['success']) {
                    // Log successful processing
                    UserAction::log('webhook_processed', [
                        'payment_id' => $paymentId,
                        'attempts' => $attempts + 1
                    ]);

                    return $result;
                }

                $lastError = $result['error'];

            } catch (Exception $e) {
                $lastError = $e->getMessage();
            }

            $attempts++;

            // Exponential backoff delay
            if ($attempts < self::MAX_RETRY_ATTEMPTS) {
                $delay = self::RETRY_DELAY_BASE * pow(2, $attempts - 1);
                sleep($delay);
            }
        }

        // Log failed webhook after all retries
        UserAction::log('webhook_failed', [
            'payment_id' => $paymentId,
            'attempts' => $attempts,
            'final_error' => $lastError
        ]);

        return [
            'success' => false,
            'error' => "Failed after {$attempts} attempts: {$lastError}"
        ];
    }

    /**
     * Process individual payment webhook
     *
     * @param string $paymentId Mollie payment ID
     * @return array Processing result
     */
    private function processPaymentWebhook(string $paymentId): array
    {
        // Fetch payment details from Mollie
        $mollieService = new MollieService();
        $payment = $mollieService->getPayment($paymentId);

        if (!$payment) {
            return [
                'success' => false,
                'error' => "Payment not found: {$paymentId}"
            ];
        }

        // Find participant by payment ID
        $participant = Database::selectOne(
            "SELECT * FROM participants WHERE mollie_payment_id = ?",
            [$paymentId]
        );

        if (!$participant) {
            return [
                'success' => false,
                'error' => "Participant not found for payment: {$paymentId}"
            ];
        }

        // Process payment status update
        $result = $this->updatePaymentStatus($participant, $payment);

        if (!$result['success']) {
            return $result;
        }

        // Check if round is complete and trigger notifications
        $this->checkRoundCompletion($participant['round_id']);

        return [
            'success' => true,
            'participant_id' => $participant['id'],
            'status' => $payment['status']
        ];
    }

    /**
     * Update participant payment status
     *
     * @param array $participant Participant data
     * @param array $payment Mollie payment data
     * @return array Update result
     */
    private function updatePaymentStatus(array $participant, array $payment): array
    {
        $oldStatus = $participant['payment_status'];
        $newStatus = $payment['status'];

        // Skip if status hasn't changed
        if ($oldStatus === $newStatus) {
            return ['success' => true, 'message' => 'Status unchanged'];
        }

        // Begin transaction for atomic updates
        Database::beginTransaction();

        try {
            // Update participant payment status
            Database::update(
                "UPDATE participants SET
                 payment_status = ?,
                 mollie_payment_data = ?,
                 updated_at = NOW()
                 WHERE id = ?",
                [
                    $newStatus,
                    json_encode($payment),
                    $participant['id']
                ]
            );

            // Log payment status change
            UserAction::log('payment_status_changed', [
                'participant_id' => $participant['id'],
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'payment_id' => $payment['id'],
                'amount' => $payment['amount']['value'] ?? null
            ]);

            // Handle specific status changes
            switch ($newStatus) {
                case 'paid':
                    $this->handlePaidStatus($participant, $payment);
                    break;

                case 'failed':
                case 'cancelled':
                case 'expired':
                    $this->handleFailedStatus($participant, $payment);
                    break;
            }

            Database::commit();

            return [
                'success' => true,
                'old_status' => $oldStatus,
                'new_status' => $newStatus
            ];

        } catch (Exception $e) {
            Database::rollback();

            return [
                'success' => false,
                'error' => "Failed to update payment status: " . $e->getMessage()
            ];
        }
    }

    /**
     * Handle paid payment status
     *
     * @param array $participant Participant data
     * @param array $payment Payment data
     */
    private function handlePaidStatus(array $participant, array $payment): void
    {
        // Send payment confirmation email
        EmailQueue::add([
            'type' => 'payment_confirmation',
            'to' => $participant['email'],
            'participant_id' => $participant['id'],
            'data' => [
                'name' => $participant['name'],
                'amount' => $payment['amount']['value'],
                'currency' => $payment['amount']['currency'],
                'payment_id' => $payment['id']
            ]
        ]);

        // Update analytics
        Analytics::track('payment_completed', [
            'participant_id' => $participant['id'],
            'amount' => $payment['amount']['value'],
            'currency' => $payment['amount']['currency'],
            'payment_method' => $payment['method'] ?? null
        ]);
    }

    /**
     * Handle failed payment status
     *
     * @param array $participant Participant data
     * @param array $payment Payment data
     */
    private function handleFailedStatus(array $participant, array $payment): void
    {
        // Send payment failed notification
        EmailQueue::add([
            'type' => 'payment_failed',
            'to' => $participant['email'],
            'participant_id' => $participant['id'],
            'data' => [
                'name' => $participant['name'],
                'reason' => $payment['details']['failureReason'] ?? 'Unknown',
                'retry_url' => Config::get('app.url') . "/game/{$participant['round_id']}/payment"
            ]
        ]);

        // Track failed payment
        Analytics::track('payment_failed', [
            'participant_id' => $participant['id'],
            'reason' => $payment['details']['failureReason'] ?? 'unknown',
            'status' => $payment['status']
        ]);
    }

    /**
     * Check if round is complete and trigger notifications
     *
     * @param int $roundId Round ID to check
     */
    private function checkRoundCompletion(int $roundId): void
    {
        // Get round information
        $round = Database::selectOne(
            "SELECT r.*, g.max_players, g.title, g.prize_value
             FROM rounds r
             JOIN games g ON r.game_id = g.id
             WHERE r.id = ?",
            [$roundId]
        );

        if (!$round) {
            return;
        }

        // Count paid participants
        $paidCount = Database::selectValue(
            "SELECT COUNT(*) FROM participants
             WHERE round_id = ? AND payment_status = 'paid'",
            [$roundId]
        );

        // Check if round is now complete
        if ($paidCount >= $round['max_players'] && $round['status'] === 'active') {
            $this->completeRound($round);
        }
    }

    /**
     * Complete a round and select winner
     *
     * @param array $round Round data
     */
    private function completeRound(array $round): void
    {
        Database::beginTransaction();

        try {
            // Mark round as complete
            Database::update(
                "UPDATE rounds SET status = 'completed', completed_at = NOW() WHERE id = ?",
                [$round['id']]
            );

            // Select winner (participant with best time)
            $winner = Database::selectOne(
                "SELECT * FROM participants
                 WHERE round_id = ? AND payment_status = 'paid'
                 ORDER BY completion_time ASC, created_at ASC
                 LIMIT 1",
                [$round['id']]
            );

            if ($winner) {
                // Mark participant as winner
                Database::update(
                    "UPDATE participants SET is_winner = 1 WHERE id = ?",
                    [$winner['id']]
                );

                // Send winner notification
                EmailQueue::add([
                    'type' => 'winner_notification',
                    'to' => $winner['email'],
                    'priority' => 'high',
                    'data' => [
                        'name' => $winner['name'],
                        'game_title' => $round['title'],
                        'prize_value' => $round['prize_value'],
                        'claim_url' => $this->generateClaimUrl($winner['id'])
                    ]
                ]);

                // Track winner selection
                Analytics::track('winner_selected', [
                    'round_id' => $round['id'],
                    'winner_id' => $winner['id'],
                    'completion_time' => $winner['completion_time']
                ]);
            }

            // Log round completion
            UserAction::log('round_completed', [
                'round_id' => $round['id'],
                'winner_id' => $winner['id'] ?? null,
                'participant_count' => $round['max_players']
            ]);

            Database::commit();

        } catch (Exception $e) {
            Database::rollback();
            error_log("Failed to complete round {$round['id']}: " . $e->getMessage());
        }
    }

    /**
     * Generate secure claim URL for winner
     *
     * @param int $participantId Winner participant ID
     * @return string Claim URL
     */
    private function generateClaimUrl(int $participantId): string
    {
        $token = Security::generateSecureToken();

        // Store claim token
        Database::insert(
            "INSERT INTO claim_tokens (participant_id, token, expires_at, created_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY), NOW())",
            [$participantId, $token]
        );

        return Config::get('app.url') . "/claim?token={$token}";
    }

    /**
     * Log security incident
     *
     * @param string $type Incident type
     * @param array $data Additional data
     */
    private function logSecurityIncident(string $type, array $data = []): void
    {
        UserAction::log('security_incident', [
            'type' => $type,
            'data' => $data,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Get webhook status for monitoring
     *
     * @return array Webhook status information
     */
    public function getWebhookStatus(): array
    {
        try {
            // Get recent webhook statistics
            $stats = Database::selectOne(
                "SELECT
                    COUNT(*) as total_webhooks,
                    SUM(CASE WHEN JSON_EXTRACT(data, '$.success') = true THEN 1 ELSE 0 END) as successful_webhooks,
                    SUM(CASE WHEN JSON_EXTRACT(data, '$.success') = false THEN 1 ELSE 0 END) as failed_webhooks
                 FROM user_actions
                 WHERE action = 'webhook_processed'
                 AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            );

            // Get recent errors
            $recentErrors = Database::select(
                "SELECT created_at, JSON_EXTRACT(data, '$.error') as error
                 FROM user_actions
                 WHERE action = 'webhook_failed'
                 AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                 ORDER BY created_at DESC
                 LIMIT 10"
            );

            return $this->success("Webhook status retrieved", [
                'statistics' => $stats,
                'recent_errors' => $recentErrors,
                'last_check' => date('Y-m-d H:i:s')
            ]);

        } catch (Exception $e) {
            return $this->error("Failed to get webhook status: " . $e->getMessage());
        }
    }
}
