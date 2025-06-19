<?php
declare(strict_types=1);

/**
 * File: models/Payment.php
 * Location: models/Payment.php
 *
 * WinABN Payment Model
 *
 * Handles payment data operations, status tracking, and provider integration
 * for the WinABN competition platform.
 *
 * @package WinABN\Models
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Models;

use WinABN\Core\{Model, Database};
use Exception;

class Payment extends Model
{
    /**
     * Table name
     *
     * @var string
     */
    protected string $table = 'payments';

    /**
     * Fillable fields
     *
     * @var array<string>
     */
    protected array $fillable = [
        'participant_id',
        'amount',
        'currency',
        'original_amount',
        'discount_applied',
        'discount_type',
        'provider',
        'provider_payment_id',
        'provider_status',
        'status',
        'payment_url',
        'webhook_data',
        'paid_at',
        'failed_reason'
    ];

    /**
     * Create payment record
     *
     * @param array<string, mixed> $data Payment data
     * @return int Payment ID
     * @throws Exception
     */
    public function create(array $data): int
    {
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        // Validate required fields
        $this->validatePaymentData($data);

        $columns = implode(',', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));

        $query = "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})";
        Database::execute($query, $data);

        $paymentId = Database::lastInsertId();

        // Log payment creation
        $this->logPaymentEvent($paymentId, 'created', $data);

        return $paymentId;
    }

    /**
     * Update payment record
     *
     * @param int $id Payment ID
     * @param array<string, mixed> $data Update data
     * @return bool Success status
     */
    public function update(int $id, array $data): bool
    {
        $data['updated_at'] = date('Y-m-d H:i:s');

        $setParts = [];
        foreach ($data as $key => $value) {
            $setParts[] = "$key = :$key";
        }
        $setClause = implode(', ', $setParts);

        $data['id'] = $id;

        $query = "UPDATE {$this->table} SET {$setClause} WHERE id = :id";
        $statement = Database::execute($query, $data);

        $success = $statement->rowCount() > 0;

        if ($success) {
            $this->logPaymentEvent($id, 'updated', $data);
        }

        return $success;
    }

    /**
     * Find payment by ID
     *
     * @param int $id Payment ID
     * @return array<string, mixed>|null Payment data
     */
    public function find(int $id): ?array
    {
        $query = "SELECT * FROM {$this->table} WHERE id = ?";
        return Database::fetchOne($query, [$id]);
    }

    /**
     * Find payment by provider payment ID
     *
     * @param string $providerPaymentId Provider payment ID
     * @return array<string, mixed>|null Payment data
     */
    public function findByProviderPaymentId(string $providerPaymentId): ?array
    {
        $query = "SELECT * FROM {$this->table} WHERE provider_payment_id = ?";
        return Database::fetchOne($query, [$providerPaymentId]);
    }

    /**
     * Find payments by participant ID
     *
     * @param int $participantId Participant ID
     * @return array<array<string, mixed>> Payment records
     */
    public function findByParticipantId(int $participantId): array
    {
        $query = "
            SELECT * FROM {$this->table}
            WHERE participant_id = ?
            ORDER BY created_at DESC
        ";
        return Database::fetchAll($query, [$participantId]);
    }

    /**
     * Update payment status
     *
     * @param int $id Payment ID
     * @param string $status New status
     * @param array<string, mixed> $additionalData Additional data to update
     * @return bool Success status
     */
    public function updateStatus(int $id, string $status, array $additionalData = []): bool
    {
        $data = array_merge($additionalData, [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        // Set paid_at timestamp for successful payments
        if ($status === 'paid' && !isset($data['paid_at'])) {
            $data['paid_at'] = date('Y-m-d H:i:s');
        }

        return $this->update($id, $data);
    }

    /**
     * Mark payment as paid
     *
     * @param int $id Payment ID
     * @param array<string, mixed> $webhookData Webhook data from provider
     * @return bool Success status
     */
    public function markAsPaid(int $id, array $webhookData = []): bool
    {
        $data = [
            'status' => 'paid',
            'paid_at' => date('Y-m-d H:i:s')
        ];

        if (!empty($webhookData)) {
            $data['webhook_data'] = json_encode($webhookData);
        }

        try {
            Database::beginTransaction();

            // Update payment status
            $success = $this->update($id, $data);

            if ($success) {
                // Update participant payment status
                $payment = $this->find($id);
                if ($payment) {
                    $this->updateParticipantPaymentStatus($payment['participant_id'], 'paid');

                    // Check if round is now full and needs completion
                    $this->checkRoundCompletion($payment['participant_id']);
                }
            }

            Database::commit();
            return $success;

        } catch (Exception $e) {
            Database::rollback();
            $this->logError('Failed to mark payment as paid', [
                'payment_id' => $id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Mark payment as failed
     *
     * @param int $id Payment ID
     * @param string $reason Failure reason
     * @param array<string, mixed> $webhookData Webhook data from provider
     * @return bool Success status
     */
    public function markAsFailed(int $id, string $reason = '', array $webhookData = []): bool
    {
        $data = [
            'status' => 'failed',
            'failed_reason' => $reason
        ];

        if (!empty($webhookData)) {
            $data['webhook_data'] = json_encode($webhookData);
        }

        $success = $this->update($id, $data);

        if ($success) {
            // Update participant payment status
            $payment = $this->find($id);
            if ($payment) {
                $this->updateParticipantPaymentStatus($payment['participant_id'], 'failed');
            }
        }

        return $success;
    }

    /**
     * Get payment statistics
     *
     * @param array<string, mixed> $filters Filter criteria
     * @return array<string, mixed> Payment statistics
     */
    public function getPaymentStats(array $filters = []): array
    {
        $whereClause = "WHERE 1=1";
        $params = [];

        if (!empty($filters['date_from'])) {
            $whereClause .= " AND created_at >= ?";
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $whereClause .= " AND created_at <= ?";
            $params[] = $filters['date_to'];
        }

        if (!empty($filters['currency'])) {
            $whereClause .= " AND currency = ?";
            $params[] = $filters['currency'];
        }

        if (!empty($filters['provider'])) {
            $whereClause .= " AND provider = ?";
            $params[] = $filters['provider'];
        }

        $query = "
            SELECT
                COUNT(*) as total_payments,
                COUNT(CASE WHEN status = 'paid' THEN 1 END) as successful_payments,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_payments,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_payments,
                SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as total_revenue,
                AVG(CASE WHEN status = 'paid' THEN amount ELSE NULL END) as average_payment,
                currency,
                provider
            FROM {$this->table}
            {$whereClause}
            GROUP BY currency, provider
        ";

        return Database::fetchAll($query, $params);
    }

    /**
     * Get recent payments
     *
     * @param int $limit Number of payments to retrieve
     * @param int $offset Offset for pagination
     * @return array<array<string, mixed>> Recent payments
     */
    public function getRecentPayments(int $limit = 50, int $offset = 0): array
    {
        $query = "
            SELECT
                p.*,
                pt.first_name,
                pt.last_name,
                pt.user_email,
                g.name as game_name
            FROM {$this->table} p
            JOIN participants pt ON p.participant_id = pt.id
            JOIN rounds r ON pt.round_id = r.id
            JOIN games g ON r.game_id = g.id
            ORDER BY p.created_at DESC
            LIMIT ? OFFSET ?
        ";

        return Database::fetchAll($query, [$limit, $offset]);
    }

    /**
     * Get payments by status
     *
     * @param string $status Payment status
     * @param int $limit Limit results
     * @return array<array<string, mixed>> Payments with specified status
     */
    public function getPaymentsByStatus(string $status, int $limit = 100): array
    {
        $query = "
            SELECT
                p.*,
                pt.first_name,
                pt.last_name,
                pt.user_email
            FROM {$this->table} p
            JOIN participants pt ON p.participant_id = pt.id
            WHERE p.status = ?
            ORDER BY p.created_at DESC
            LIMIT ?
        ";

        return Database::fetchAll($query, [$status, $limit]);
    }

    /**
     * Get payment conversion rates
     *
     * @param string $period Time period (day, week, month)
     * @return array<string, mixed> Conversion statistics
     */
    public function getConversionRates(string $period = 'day'): array
    {
        $dateFormat = match($period) {
            'week' => '%Y-%u',
            'month' => '%Y-%m',
            default => '%Y-%m-%d'
        };

        $query = "
            SELECT
                DATE_FORMAT(created_at, '{$dateFormat}') as period,
                COUNT(*) as total_attempts,
                COUNT(CASE WHEN status = 'paid' THEN 1 END) as successful_payments,
                ROUND(
                    (COUNT(CASE WHEN status = 'paid' THEN 1 END) / COUNT(*)) * 100, 2
                ) as conversion_rate,
                SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as revenue
            FROM {$this->table}
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY period
            ORDER BY period DESC
        ";

        return Database::fetchAll($query);
    }

    /**
     * Process refund
     *
     * @param int $id Payment ID
     * @param string $reason Refund reason
     * @return bool Success status
     */
    public function processRefund(int $id, string $reason = ''): bool
    {
        try {
            Database::beginTransaction();

            $payment = $this->find($id);
            if (!$payment || $payment['status'] !== 'paid') {
                throw new Exception('Payment not eligible for refund');
            }

            // Update payment status
            $success = $this->update($id, [
                'status' => 'refunded',
                'failed_reason' => $reason,
                'refunded_at' => date('Y-m-d H:i:s')
            ]);

            if ($success) {
                // Update participant status
                $this->updateParticipantPaymentStatus($payment['participant_id'], 'refunded');

                // Log refund
                $this->logPaymentEvent($id, 'refunded', ['reason' => $reason]);
            }

            Database::commit();
            return $success;

        } catch (Exception $e) {
            Database::rollback();
            $this->logError('Refund processing failed', [
                'payment_id' => $id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Validate payment data
     *
     * @param array<string, mixed> $data Payment data
     * @throws Exception If validation fails
     */
    private function validatePaymentData(array $data): void
    {
        $required = ['participant_id', 'amount', 'currency', 'provider'];

        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new Exception("Required field missing: {$field}");
            }
        }

        if (!is_numeric($data['amount']) || $data['amount'] <= 0) {
            throw new Exception('Invalid payment amount');
        }

        if (!in_array($data['currency'], ['GBP', 'EUR', 'USD', 'CAD', 'AUD'])) {
            throw new Exception('Unsupported currency');
        }

        if (!in_array($data['provider'], ['mollie', 'stripe'])) {
            throw new Exception('Unsupported payment provider');
        }
    }

    /**
     * Update participant payment status
     *
     * @param int $participantId Participant ID
     * @param string $status Payment status
     * @return bool Success status
     */
    private function updateParticipantPaymentStatus(int $participantId, string $status): bool
    {
        $data = ['payment_status' => $status];

        if ($status === 'paid') {
            $data['payment_completed_at'] = date('Y-m-d H:i:s');
        }

        $query = "UPDATE participants SET " .
                implode(' = ?, ', array_keys($data)) . " = ?, updated_at = ? WHERE id = ?";

        $params = array_values($data);
        $params[] = date('Y-m-d H:i:s');
        $params[] = $participantId;

        $statement = Database::execute($query, $params);
        return $statement->rowCount() > 0;
    }

    /**
     * Check if round should be completed after payment
     *
     * @param int $participantId Participant ID
     * @return void
     */
    private function checkRoundCompletion(int $participantId): void
    {
        // Get participant's round
        $query = "
            SELECT r.id, r.game_id, r.paid_participant_count, g.max_players, g.auto_restart
            FROM participants p
            JOIN rounds r ON p.round_id = r.id
            JOIN games g ON r.game_id = g.id
            WHERE p.id = ? AND r.status = 'active'
        ";

        $roundData = Database::fetchOne($query, [$participantId]);

        if ($roundData) {
            // Update paid participant count
            $newCount = $roundData['paid_participant_count'] + 1;

            Database::execute(
                "UPDATE rounds SET paid_participant_count = ? WHERE id = ?",
                [$newCount, $roundData['id']]
            );

            // Check if round is now full
            if ($newCount >= $roundData['max_players']) {
                Database::execute(
                    "UPDATE rounds SET status = 'full' WHERE id = ?",
                    [$roundData['id']]
                );

                // Queue winner selection and notifications
                $this->queueWinnerSelection($roundData['id']);

                // Create new round if auto-restart is enabled
                if ($roundData['auto_restart']) {
                    $this->createNewRound($roundData['game_id']);
                }
            }
        }
    }

    /**
     * Queue winner selection for completed round
     *
     * @param int $roundId Round ID
     * @return void
     */
    private function queueWinnerSelection(int $roundId): void
    {
        // Add to background queue for processing
        $queueData = [
            'job_type' => 'winner_selection',
            'round_id' => $roundId,
            'created_at' => date('Y-m-d H:i:s'),
            'priority' => 1
        ];

        Database::execute(
            "INSERT INTO job_queue (job_type, data, priority, created_at) VALUES (?, ?, ?, ?)",
            [$queueData['job_type'], json_encode($queueData), $queueData['priority'], $queueData['created_at']]
        );
    }

    /**
     * Create new round for game
     *
     * @param int $gameId Game ID
     * @return void
     */
    private function createNewRound(int $gameId): void
    {
        Database::execute(
            "INSERT INTO rounds (game_id, status, started_at, created_at) VALUES (?, 'active', NOW(), NOW())",
            [$gameId]
        );
    }

    /**
     * Log payment event
     *
     * @param int $paymentId Payment ID
     * @param string $event Event type
     * @param array<string, mixed> $data Event data
     * @return void
     */
    private function logPaymentEvent(int $paymentId, string $event, array $data = []): void
    {
        $logData = [
            'payment_id' => $paymentId,
            'event' => $event,
            'data' => json_encode($data),
            'created_at' => date('Y-m-d H:i:s')
        ];

        try {
            Database::execute(
                "INSERT INTO payment_log (payment_id, event, data, created_at) VALUES (?, ?, ?, ?)",
                array_values($logData)
            );
        } catch (Exception $e) {
            // Log to file if database logging fails
            error_log("Payment event logging failed: " . $e->getMessage());
        }
    }

    /**
     * Log error
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
            error_log("Payment Error: $message " . json_encode($context));
        }
    }
}
