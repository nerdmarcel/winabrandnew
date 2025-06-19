<?php
declare(strict_types=1);

/**
 * File: public/webhook_mollie.php
 * Location: public/webhook_mollie.php
 *
 * WinABN Mollie Webhook Handler
 *
 * Processes payment status updates from Mollie payment provider.
 * Handles payment verification, participant status updates, and round completion.
 *
 * @package WinABN
 * @author WinABN Development Team
 * @version 1.0
 */

// Bootstrap the application
require_once __DIR__ . '/bootstrap.php';

use WinABN\Models\{Payment, Participant};
use WinABN\Core\{Database, EmailQueue};
use Exception;

// Set response headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

/**
 * Main webhook handler
 */
function handleMollieWebhook(): void
{
    try {
        // Verify webhook authenticity
        if (!verifyWebhookSignature()) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid webhook signature']);
            return;
        }

        // Get payment ID from request
        $paymentId = getPaymentIdFromRequest();
        if (!$paymentId) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing payment ID']);
            return;
        }

        // Process the payment update
        $result = processPaymentUpdate($paymentId);

        if ($result['success']) {
            http_response_code(200);
            echo json_encode(['status' => 'processed', 'payment_id' => $paymentId]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => $result['error']]);
        }

    } catch (Exception $e) {
        logWebhookError('Webhook processing failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'request_data' => getRequestData()
        ]);

        http_response_code(500);
        echo json_encode(['error' => 'Internal server error']);
    }
}

/**
 * Verify webhook signature from Mollie
 *
 * @return bool True if signature is valid
 */
function verifyWebhookSignature(): bool
{
    $webhookSecret = env('MOLLIE_WEBHOOK_SECRET');
    if (!$webhookSecret) {
        // If no secret is configured, skip verification in development
        return env('APP_ENV') === 'development';
    }

    $signature = $_SERVER['HTTP_X_MOLLIE_SIGNATURE'] ?? '';
    if (!$signature) {
        return false;
    }

    $payload = file_get_contents('php://input');
    $expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);

    return hash_equals($signature, $expectedSignature);
}

/**
 * Get payment ID from webhook request
 *
 * @return string|null Mollie payment ID
 */
function getPaymentIdFromRequest(): ?string
{
    $input = json_decode(file_get_contents('php://input'), true);

    // Mollie sends payment ID in the 'id' field
    if (isset($input['id'])) {
        return $input['id'];
    }

    // Fallback: check query parameters
    return $_GET['id'] ?? null;
}

/**
 * Get request data for logging
 *
 * @return array Request data
 */
function getRequestData(): array
{
    return [
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
        'headers' => getallheaders(),
        'body' => file_get_contents('php://input'),
        'query' => $_GET,
        'ip' => client_ip()
    ];
}

/**
 * Process payment status update
 *
 * @param string $molliePaymentId Mollie payment ID
 * @return array Processing result
 */
function processPaymentUpdate(string $molliePaymentId): array
{
    try {
        Database::beginTransaction();

        // Find payment record by Mollie payment ID
        $paymentModel = new Payment();
        $payment = $paymentModel->findByProviderPaymentId($molliePaymentId);

        if (!$payment) {
            Database::rollback();
            return [
                'success' => false,
                'error' => 'Payment record not found'
            ];
        }

        // Initialize Mollie client
        $mollieClient = new \Mollie\Api\MollieApiClient();
        $mollieClient->setApiKey(env('MOLLIE_API_KEY'));

        // Fetch current payment status from Mollie
        $molliePayment = $mollieClient->payments->get($molliePaymentId);

        // Log the webhook event
        logWebhookEvent($payment['id'], $molliePayment->status, [
            'mollie_payment_id' => $molliePaymentId,
            'previous_status' => $payment['status'],
            'new_status' => $molliePayment->status,
            'webhook_data' => $molliePayment->toArray()
        ]);

        // Process based on payment status
        $result = handlePaymentStatus($payment, $molliePayment);

        Database::commit();
        return $result;

    } catch (Exception $e) {
        Database::rollback();

        logWebhookError('Payment update processing failed', [
            'mollie_payment_id' => $molliePaymentId,
            'error' => $e->getMessage()
        ]);

        return [
            'success' => false,
            'error' => 'Payment processing failed: ' . $e->getMessage()
        ];
    }
}

/**
 * Handle specific payment status
 *
 * @param array $payment Local payment record
 * @param object $molliePayment Mollie payment object
 * @return array Processing result
 */
function handlePaymentStatus(array $payment, object $molliePayment): array
{
    $paymentModel = new Payment();
    $participantModel = new Participant();

    // Update payment record with latest data
    $updateData = [
        'provider_status' => $molliePayment->status,
        'webhook_data' => json_encode($molliePayment->toArray())
    ];

    switch ($molliePayment->status) {
        case 'paid':
            return handlePaidStatus($payment, $molliePayment, $paymentModel, $participantModel);

        case 'failed':
        case 'expired':
        case 'canceled':
            return handleFailedStatus($payment, $molliePayment, $paymentModel, $participantModel);

        case 'pending':
        case 'open':
            // Update status but no further action needed
            $paymentModel->update($payment['id'], $updateData);
            return ['success' => true, 'action' => 'status_updated'];

        default:
            logWebhookError('Unknown payment status', [
                'payment_id' => $payment['id'],
                'mollie_status' => $molliePayment->status
            ]);

            return [
                'success' => false,
                'error' => 'Unknown payment status: ' . $molliePayment->status
            ];
    }
}

/**
 * Handle paid payment status
 *
 * @param array $payment Local payment record
 * @param object $molliePayment Mollie payment object
 * @param Payment $paymentModel Payment model instance
 * @param Participant $participantModel Participant model instance
 * @return array Processing result
 */
function handlePaidStatus(array $payment, object $molliePayment, Payment $paymentModel, Participant $participantModel): array
{
    // Prevent duplicate processing
    if ($payment['status'] === 'paid') {
        return ['success' => true, 'action' => 'already_processed'];
    }

    // Mark payment as paid
    $webhookData = $molliePayment->toArray();
    $success = $paymentModel->markAsPaid($payment['id'], $webhookData);

    if (!$success) {
        throw new Exception('Failed to mark payment as paid');
    }

    // Get participant information
    $participant = $participantModel->find($payment['participant_id']);
    if (!$participant) {
        throw new Exception('Participant not found');
    }

    // Update participant payment status
    $participantModel->update($participant['id'], [
        'payment_status' => 'paid',
        'payment_completed_at' => date('Y-m-d H:i:s'),
        'payment_provider' => 'mollie'
    ]);

    // Check if this payment completes the round
    $roundCompleted = checkAndCompleteRound($participant);

    // Queue confirmation email
    queuePaymentConfirmationEmail($participant, $payment);

    // Record analytics event
    recordAnalyticsEvent('payment_completed', $participant, $payment);

    return [
        'success' => true,
        'action' => 'payment_confirmed',
        'round_completed' => $roundCompleted
    ];
}

/**
 * Handle failed payment status
 *
 * @param array $payment Local payment record
 * @param object $molliePayment Mollie payment object
 * @param Payment $paymentModel Payment model instance
 * @param Participant $participantModel Participant model instance
 * @return array Processing result
 */
function handleFailedStatus(array $payment, object $molliePayment, Payment $paymentModel, Participant $participantModel): array
{
    // Determine failure reason
    $failureReason = match($molliePayment->status) {
        'failed' => $molliePayment->details->failureReason ?? 'Payment failed',
        'expired' => 'Payment expired',
        'canceled' => 'Payment cancelled by user',
        default => 'Payment not completed'
    };

    // Mark payment as failed
    $webhookData = $molliePayment->toArray();
    $success = $paymentModel->markAsFailed($payment['id'], $failureReason, $webhookData);

    if (!$success) {
        throw new Exception('Failed to mark payment as failed');
    }

    // Update participant status
    $participant = $participantModel->find($payment['participant_id']);
    if ($participant) {
        $participantModel->update($participant['id'], [
            'payment_status' => 'failed'
        ]);

        // Queue failure notification email
        queuePaymentFailureEmail($participant, $payment, $failureReason);
    }

    return [
        'success' => true,
        'action' => 'payment_failed',
        'reason' => $failureReason
    ];
}

/**
 * Check if round is completed and handle winner selection
 *
 * @param array $participant Participant data
 * @return bool True if round was completed
 */
function checkAndCompleteRound(array $participant): bool
{
    // Get round information
    $query = "
        SELECT r.*, g.max_players, g.auto_restart, g.name as game_name
        FROM rounds r
        JOIN games g ON r.game_id = g.id
        WHERE r.id = ? AND r.status = 'active'
    ";

    $round = Database::fetchOne($query, [$participant['round_id']]);
    if (!$round) {
        return false;
    }

    // Count current paid participants
    $paidCount = Database::fetchColumn(
        "SELECT COUNT(*) FROM participants WHERE round_id = ? AND payment_status = 'paid'",
        [$round['id']]
    );

    // Update round's paid participant count
    Database::execute(
        "UPDATE rounds SET paid_participant_count = ? WHERE id = ?",
        [$paidCount, $round['id']]
    );

    // Check if round is now full
    if ($paidCount >= $round['max_players']) {
        // Mark round as full
        Database::execute(
            "UPDATE rounds SET status = 'full', completed_at = NOW() WHERE id = ?",
            [$round['id']]
        );

        // Queue winner selection
        queueWinnerSelection($round['id']);

        // Queue round completion notifications
        queueRoundCompletionNotifications($round['id']);

        // Create new round if auto-restart is enabled
        if ($round['auto_restart']) {
            createNewRound($round['game_id']);
        }

        logWebhookEvent($participant['id'], 'round_completed', [
            'round_id' => $round['id'],
            'final_participant_count' => $paidCount
        ]);

        return true;
    }

    return false;
}

/**
 * Queue winner selection job
 *
 * @param int $roundId Round ID
 * @return void
 */
function queueWinnerSelection(int $roundId): void
{
    $jobData = [
        'job_type' => 'winner_selection',
        'round_id' => $roundId,
        'priority' => 1,
        'created_at' => date('Y-m-d H:i:s'),
        'execute_at' => date('Y-m-d H:i:s', strtotime('+30 seconds')) // Small delay for final payments
    ];

    Database::execute(
        "INSERT INTO job_queue (job_type, data, priority, created_at, execute_at) VALUES (?, ?, ?, ?, ?)",
        [
            $jobData['job_type'],
            json_encode($jobData),
            $jobData['priority'],
            $jobData['created_at'],
            $jobData['execute_at']
        ]
    );
}

/**
 * Queue round completion notifications
 *
 * @param int $roundId Round ID
 * @return void
 */
function queueRoundCompletionNotifications(int $roundId): void
{
    $jobData = [
        'job_type' => 'round_completion_notifications',
        'round_id' => $roundId,
        'priority' => 2,
        'created_at' => date('Y-m-d H:i:s'),
        'execute_at' => date('Y-m-d H:i:s', strtotime('+2 minutes')) // After winner selection
    ];

    Database::execute(
        "INSERT INTO job_queue (job_type, data, priority, created_at, execute_at) VALUES (?, ?, ?, ?, ?)",
        [
            $jobData['job_type'],
            json_encode($jobData),
            $jobData['priority'],
            $jobData['created_at'],
            $jobData['execute_at']
        ]
    );
}

/**
 * Create new round for game
 *
 * @param int $gameId Game ID
 * @return void
 */
function createNewRound(int $gameId): void
{
    Database::execute(
        "INSERT INTO rounds (game_id, status, started_at, created_at) VALUES (?, 'active', NOW(), NOW())",
        [$gameId]
    );

    logWebhookEvent(0, 'new_round_created', ['game_id' => $gameId]);
}

/**
 * Queue payment confirmation email
 *
 * @param array $participant Participant data
 * @param array $payment Payment data
 * @return void
 */
function queuePaymentConfirmationEmail(array $participant, array $payment): void
{
    $emailQueue = new EmailQueue();

    $emailData = [
        'to_email' => $participant['user_email'],
        'subject' => 'Payment Confirmed - Continue Your Game!',
        'template' => 'payment_confirmation',
        'variables' => [
            'first_name' => $participant['first_name'],
            'amount' => $payment['amount'],
            'currency' => $payment['currency'],
            'continue_url' => url("game?session={$participant['session_id']}&continue=true")
        ],
        'priority' => 1
    ];

    $emailQueue->add($emailData);
}

/**
 * Queue payment failure email
 *
 * @param array $participant Participant data
 * @param array $payment Payment data
 * @param string $reason Failure reason
 * @return void
 */
function queuePaymentFailureEmail(array $participant, array $payment, string $reason): void
{
    $emailQueue = new EmailQueue();

    $emailData = [
        'to_email' => $participant['user_email'],
        'subject' => 'Payment Failed - Try Again',
        'template' => 'payment_failed',
        'variables' => [
            'first_name' => $participant['first_name'],
            'reason' => $reason,
            'retry_url' => url("payment/retry?payment_id={$payment['id']}")
        ],
        'priority' => 2
    ];

    $emailQueue->add($emailData);
}

/**
 * Record analytics event
 *
 * @param string $eventType Event type
 * @param array $participant Participant data
 * @param array $payment Payment data
 * @return void
 */
function recordAnalyticsEvent(string $eventType, array $participant, array $payment): void
{
    $eventData = [
        'event_type' => $eventType,
        'participant_id' => $participant['id'],
        'round_id' => $participant['round_id'],
        'revenue_amount' => $payment['amount'],
        'additional_data_json' => json_encode([
            'payment_id' => $payment['id'],
            'currency' => $payment['currency'],
            'provider' => $payment['provider']
        ]),
        'created_at' => date('Y-m-d H:i:s')
    ];

    Database::execute(
        "INSERT INTO analytics_events (event_type, participant_id, round_id, revenue_amount, additional_data_json, created_at) VALUES (?, ?, ?, ?, ?, ?)",
        array_values($eventData)
    );
}

/**
 * Log webhook event
 *
 * @param int $paymentId Payment ID (0 for non-payment events)
 * @param string $event Event type
 * @param array $data Event data
 * @return void
 */
function logWebhookEvent(int $paymentId, string $event, array $data = []): void
{
    $logData = [
        'payment_id' => $paymentId ?: null,
        'event' => $event,
        'data' => json_encode($data),
        'ip_address' => client_ip(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'created_at' => date('Y-m-d H:i:s')
    ];

    try {
        Database::execute(
            "INSERT INTO webhook_log (payment_id, event, data, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?)",
            array_values($logData)
        );
    } catch (Exception $e) {
        error_log("Webhook event logging failed: " . $e->getMessage());
    }
}

/**
 * Log webhook error
 *
 * @param string $message Error message
 * @param array $context Error context
 * @return void
 */
function logWebhookError(string $message, array $context = []): void
{
    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => $message,
        'context' => $context,
        'ip' => client_ip(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
    ];

    // Log to file
    $logFile = WINABN_LOGS_DIR . '/webhook_errors.log';
    $logLine = json_encode($logEntry) . PHP_EOL;
    file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);

    // Also use application logger if available
    if (function_exists('app_log')) {
        app_log('error', $message, $context);
    }
}

/**
 * Implement retry mechanism for failed webhook processing
 *
 * @param string $molliePaymentId Mollie payment ID
 * @param int $attemptCount Current attempt count
 * @return void
 */
function scheduleWebhookRetry(string $molliePaymentId, int $attemptCount = 1): void
{
    if ($attemptCount >= 3) {
        // Max retries reached, log critical error
        logWebhookError('Webhook retry limit exceeded', [
            'mollie_payment_id' => $molliePaymentId,
            'attempts' => $attemptCount
        ]);
        return;
    }

    // Schedule retry with exponential backoff
    $delayMinutes = pow(2, $attemptCount); // 2, 4, 8 minutes
    $executeAt = date('Y-m-d H:i:s', strtotime("+{$delayMinutes} minutes"));

    $retryData = [
        'job_type' => 'webhook_retry',
        'mollie_payment_id' => $molliePaymentId,
        'attempt_count' => $attemptCount,
        'priority' => 1,
        'created_at' => date('Y-m-d H:i:s'),
        'execute_at' => $executeAt
    ];

    Database::execute(
        "INSERT INTO job_queue (job_type, data, priority, created_at, execute_at) VALUES (?, ?, ?, ?, ?)",
        [
            $retryData['job_type'],
            json_encode($retryData),
            $retryData['priority'],
            $retryData['created_at'],
            $retryData['execute_at']
        ]
    );
}

// Execute the webhook handler
try {
    handleMollieWebhook();
} catch (Throwable $e) {
    logWebhookError('Critical webhook error', [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);

    http_response_code(500);
    echo json_encode(['error' => 'Critical error occurred']);
}
