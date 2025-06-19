<?php
declare(strict_types=1);

/**
 * File: models/Participant.php
 * Location: models/Participant.php
 *
 * WinABN Participant Model
 *
 * Handles participant data management including timing logic, question progression,
 * payment status tracking, and fraud detection for the WinABN platform.
 *
 * @package WinABN\Models
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Models;

use WinABN\Core\Database;
use WinABN\Core\Model;
use Exception;

class Participant extends Model
{
    /**
     * Table name
     *
     * @var string
     */
    protected string $table = 'participants';

    /**
     * Payment status constants
     *
     * @var string
     */
    public const PAYMENT_PENDING = 'pending';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_FAILED = 'failed';
    public const PAYMENT_REFUNDED = 'refunded';

    /**
     * Game status constants
     *
     * @var string
     */
    public const GAME_NOT_STARTED = 'not_started';
    public const GAME_IN_PROGRESS = 'in_progress';
    public const GAME_COMPLETED = 'completed';
    public const GAME_FAILED = 'failed';
    public const GAME_ABANDONED = 'abandoned';

    /**
     * Find participant by ID
     *
     * @param int $id Participant ID
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $query = "SELECT * FROM {$this->table} WHERE id = ?";
        return Database::fetchOne($query, [$id]);
    }

    /**
     * Find participant by session ID
     *
     * @param string $sessionId Session ID
     * @return array<string, mixed>|null
     */
    public function findBySession(string $sessionId): ?array
    {
        $query = "SELECT * FROM {$this->table} WHERE session_id = ?";
        return Database::fetchOne($query, [$sessionId]);
    }

    /**
     * Find participant by email and round
     *
     * @param string $email User email
     * @param int $roundId Round ID
     * @return array<string, mixed>|null
     */
    public function findByEmailAndRound(string $email, int $roundId): ?array
    {
        $query = "SELECT * FROM {$this->table} WHERE user_email = ? AND round_id = ?";
        return Database::fetchOne($query, [$email, $roundId]);
    }

    /**
     * Create new participant
     *
     * @param array<string, mixed> $data Participant data
     * @return int Participant ID
     * @throws Exception
     */
    public function create(array $data): int
    {
        $this->validateParticipantData($data);

        // Generate device fingerprint if not provided
        if (empty($data['device_fingerprint'])) {
            $data['device_fingerprint'] = $this->generateDeviceFingerprint($data);
        }

        // Initialize timing and question data
        $data['current_question'] = 1;
        $data['questions_completed'] = 0;
        $data['game_status'] = self::GAME_NOT_STARTED;
        $data['started_at'] = null;

        $query = "
            INSERT INTO {$this->table} (
                round_id, user_email, first_name, last_name, phone, whatsapp_consent,
                payment_status, payment_currency, payment_amount, payment_reference,
                payment_provider, game_status, current_question, questions_completed,
                device_fingerprint, ip_address, session_id, user_agent
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";

        Database::execute($query, [
            $data['round_id'],
            $data['user_email'],
            $data['first_name'],
            $data['last_name'],
            $data['phone'],
            $data['whatsapp_consent'] ?? false,
            $data['payment_status'] ?? self::PAYMENT_PENDING,
            $data['payment_currency'],
            $data['payment_amount'],
            $data['payment_reference'] ?? null,
            $data['payment_provider'] ?? null,
            $data['game_status'],
            $data['current_question'],
            $data['questions_completed'],
            $data['device_fingerprint'],
            $data['ip_address'],
            $data['session_id'],
            $data['user_agent'] ?? null
        ]);

        $participantId = Database::lastInsertId();

        app_log('info', 'New participant created', [
            'participant_id' => $participantId,
            'round_id' => $data['round_id'],
            'email' => $data['user_email']
        ]);

        return $participantId;
    }

    /**
     * Start game for participant
     *
     * @param int $participantId Participant ID
     * @return bool Success
     * @throws Exception
     */
    public function startGame(int $participantId): bool
    {
        $participant = $this->find($participantId);
        if (!$participant) {
            throw new Exception("Participant not found: $participantId");
        }

        if ($participant['game_status'] !== self::GAME_NOT_STARTED) {
            throw new Exception("Game already started for participant: $participantId");
        }

        $query = "
            UPDATE {$this->table}
            SET game_status = ?, started_at = NOW(), current_question = 1
            WHERE id = ?
        ";

        Database::execute($query, [self::GAME_IN_PROGRESS, $participantId]);

        app_log('info', 'Game started', [
            'participant_id' => $participantId,
            'email' => $participant['user_email']
        ]);

        return true;
    }

    /**
     * Record question answer and timing
     *
     * @param int $participantId Participant ID
     * @param int $questionNumber Question number (1-9)
     * @param string $answer Answer given (A, B, or C)
     * @param float $timeTaken Time taken in seconds
     * @param bool $isCorrect Whether answer is correct
     * @return bool Success
     * @throws Exception
     */
    public function recordAnswer(int $participantId, int $questionNumber, string $answer, float $timeTaken, bool $isCorrect): bool
    {
        return Database::transaction(function() use ($participantId, $questionNumber, $answer, $timeTaken, $isCorrect) {
            $participant = $this->find($participantId);
            if (!$participant) {
                throw new Exception("Participant not found: $participantId");
            }

            if ($participant['game_status'] !== self::GAME_IN_PROGRESS) {
                throw new Exception("Game not in progress for participant: $participantId");
            }

            // Validate question number
            if ($questionNumber !== $participant['current_question']) {
                throw new Exception("Question number mismatch. Expected: {$participant['current_question']}, Got: $questionNumber");
            }

            // Fraud detection - check minimum answer time
            $minAnswerTime = env('FRAUD_MIN_ANSWER_TIME', 0.5);
            if ($timeTaken < $minAnswerTime) {
                $this->markFraudulent($participantId, "Answer time too fast: {$timeTaken}s");
                throw new Exception("Answer submitted too quickly");
            }

            // If wrong answer, fail the game
            if (!$isCorrect) {
                $this->failGame($participantId, "Incorrect answer on question $questionNumber");
                return false;
            }

            // Update question times JSON
            $questionTimes = json_decode($participant['question_times_json'] ?? '[]', true);
            $questionTimes[$questionNumber] = $timeTaken;

            // Calculate timing based on question number
            $prePaymentTime = $participant['pre_payment_time'];
            $postPaymentTime = $participant['post_payment_time'];

            if ($questionNumber <= 3) {
                // Pre-payment questions (1-3)
                $prePaymentTime = ($prePaymentTime ?? 0) + $timeTaken;
            } else {
                // Post-payment questions (4-9)
                $postPaymentTime = ($postPaymentTime ?? 0) + $timeTaken;
            }

            // Calculate total time
            $totalTime = ($prePaymentTime ?? 0) + ($postPaymentTime ?? 0);

            // Determine if game is completed
            $questionsCompleted = $participant['questions_completed'] + 1;
            $nextQuestion = $questionNumber + 1;
            $gameStatus = $participant['game_status'];
            $completedAt = null;

            // Get game info to check total questions
            $round = Database::fetchOne("SELECT game_id FROM rounds WHERE id = ?", [$participant['round_id']]);
            $game = Database::fetchOne("SELECT total_questions FROM games WHERE id = ?", [$round['game_id']]);

            if ($questionsCompleted >= $game['total_questions']) {
                $gameStatus = self::GAME_COMPLETED;
                $nextQuestion = $game['total_questions'] + 1; // Beyond last question
                $completedAt = 'NOW()';
            }

            // Update participant record
            $query = "
                UPDATE {$this->table}
                SET current_question = ?,
                    questions_completed = ?,
                    question_times_json = ?,
                    pre_payment_time = ?,
                    post_payment_time = ?,
                    total_time_all_questions = ?,
                    game_status = ?
            ";

            $params = [
                $nextQuestion,
                $questionsCompleted,
                json_encode($questionTimes),
                $prePaymentTime,
                $postPaymentTime,
                $totalTime,
                $gameStatus
            ];

            if ($completedAt) {
                $query .= ", completed_at = NOW()";
            }

            $query .= " WHERE id = ?";
            $params[] = $participantId;

            Database::execute($query, $params);

            app_log('info', 'Answer recorded', [
                'participant_id' => $participantId,
                'question' => $questionNumber,
                'time_taken' => $timeTaken,
                'is_correct' => $isCorrect,
                'game_completed' => $gameStatus === self::GAME_COMPLETED
            ]);

            return true;
        });
    }

    /**
     * Update payment status
     *
     * @param int $participantId Participant ID
     * @param string $status New payment status
     * @param array<string, mixed> $paymentData Additional payment data
     * @return bool Success
     * @throws Exception
     */
    public function updatePaymentStatus(int $participantId, string $status, array $paymentData = []): bool
    {
        return Database::transaction(function() use ($participantId, $status, $paymentData) {
            $participant = $this->find($participantId);
            if (!$participant) {
                throw new Exception("Participant not found: $participantId");
            }

            $oldStatus = $participant['payment_status'];

            $updateData = ['payment_status' => $status];

            if (isset($paymentData['payment_reference'])) {
                $updateData['payment_reference'] = $paymentData['payment_reference'];
            }

            if (isset($paymentData['payment_provider'])) {
                $updateData['payment_provider'] = $paymentData['payment_provider'];
            }

            if ($status === self::PAYMENT_PAID) {
                $updateData['payment_completed_at'] = 'NOW()';
            }

            // Build update query
            $setClause = [];
            $params = [];

            foreach ($updateData as $field => $value) {
                if ($value === 'NOW()') {
                    $setClause[] = "$field = NOW()";
                } else {
                    $setClause[] = "$field = ?";
                    $params[] = $value;
                }
            }

            $params[] = $participantId;

            $query = "UPDATE {$this->table} SET " . implode(', ', $setClause) . " WHERE id = ?";
            Database::execute($query, $params);

            // Update round participant counts
            if ($oldStatus !== $status) {
                $roundModel = new Round();
                $roundModel->updateParticipantPaymentStatus(
                    $participant['round_id'],
                    $participantId,
                    $oldStatus,
                    $status
                );
            }

            app_log('info', 'Payment status updated', [
                'participant_id' => $participantId,
                'old_status' => $oldStatus,
                'new_status' => $status
            ]);

            return true;
        });
    }

    /**
     * Mark participant as fraudulent
     *
     * @param int $participantId Participant ID
     * @param string $reason Fraud reason
     * @return bool Success
     */
    public function markFraudulent(int $participantId, string $reason): bool
    {
        $query = "
            UPDATE {$this->table}
            SET is_fraudulent = 1, fraud_reason = ?, game_status = ?
            WHERE id = ?
        ";

        Database::execute($query, [$reason, self::GAME_FAILED, $participantId]);

        app_log('warning', 'Participant marked as fraudulent', [
            'participant_id' => $participantId,
            'reason' => $reason
        ]);

        return true;
    }

    /**
     * Fail game for participant
     *
     * @param int $participantId Participant ID
     * @param string $reason Failure reason
     * @return bool Success
     */
    public function failGame(int $participantId, string $reason): bool
    {
        $query = "
            UPDATE {$this->table}
            SET game_status = ?, completed_at = NOW()
            WHERE id = ?
        ";

        Database::execute($query, [self::GAME_FAILED, $participantId]);

        app_log('info', 'Game failed for participant', [
            'participant_id' => $participantId,
            'reason' => $reason
        ]);

        return true;
    }

    /**
     * Get participant's question history for game
     *
     * @param string $email User email
     * @param int $gameId Game ID
     * @return array<int> Array of question IDs already seen
     */
    public function getQuestionHistory(string $email, int $gameId): array
    {
        $query = "
            SELECT question_id FROM participant_question_history
            WHERE user_email = ? AND game_id = ?
        ";

        $results = Database::fetchAll($query, [$email, $gameId]);
        return array_column($results, 'question_id');
    }

    /**
     * Record question as seen by participant
     *
     * @param int $participantId Participant ID
     * @param string $email User email
     * @param int $gameId Game ID
     * @param int $questionId Question ID
     * @param string|null $answer Answer given
     * @param bool|null $isCorrect Whether answer was correct
     * @param float|null $timeTaken Time taken to answer
     * @return bool Success
     */
    public function recordQuestionSeen(int $participantId, string $email, int $gameId, int $questionId, ?string $answer = null, ?bool $isCorrect = null, ?float $timeTaken = null): bool
    {
        $query = "
            INSERT INTO participant_question_history
            (user_email, game_id, question_id, participant_id, answer_given, is_correct, time_taken)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                participant_id = VALUES(participant_id),
                answer_given = VALUES(answer_given),
                is_correct = VALUES(is_correct),
                time_taken = VALUES(time_taken)
        ";

        Database::execute($query, [$email, $gameId, $questionId, $participantId, $answer, $isCorrect, $timeTaken]);
        return true;
    }

    /**
     * Get participants by round
     *
     * @param int $roundId Round ID
     * @param array<string, mixed> $filters Optional filters
     * @return array<array<string, mixed>>
     */
    public function getByRound(int $roundId, array $filters = []): array
    {
        $query = "SELECT * FROM {$this->table} WHERE round_id = ?";
        $params = [$roundId];

        if (isset($filters['payment_status'])) {
            $query .= " AND payment_status = ?";
            $params[] = $filters['payment_status'];
        }

        if (isset($filters['game_status'])) {
            $query .= " AND game_status = ?";
            $params[] = $filters['game_status'];
        }

        if (isset($filters['is_winner'])) {
            $query .= " AND is_winner = ?";
            $params[] = $filters['is_winner'];
        }

        if (isset($filters['is_fraudulent'])) {
            $query .= " AND is_fraudulent = ?";
            $params[] = $filters['is_fraudulent'];
        }

        $query .= " ORDER BY created_at ASC";

        return Database::fetchAll($query, $params);
    }

    /**
     * Get participant leaderboard for round
     *
     * @param int $roundId Round ID
     * @param int $limit Number of participants to return
     * @return array<array<string, mixed>>
     */
    public function getLeaderboard(int $roundId, int $limit = 10): array
    {
        $query = "
            SELECT id, first_name, last_name, total_time_all_questions, is_winner
            FROM {$this->table}
            WHERE round_id = ?
                AND payment_status = 'paid'
                AND game_status = 'completed'
                AND total_time_all_questions IS NOT NULL
                AND is_fraudulent = 0
            ORDER BY total_time_all_questions ASC
            LIMIT ?
        ";

        return Database::fetchAll($query, [$roundId, $limit]);
    }

    /**
     * Check for potential fraud patterns
     *
     * @param int $participantId Participant ID
     * @return array<string, mixed> Fraud analysis results
     */
    public function analyzeFraudRisk(int $participantId): array
    {
        $participant = $this->find($participantId);
        if (!$participant) {
            return ['risk_level' => 'unknown', 'factors' => []];
        }

        $riskFactors = [];
        $riskLevel = 'low';

        // Check IP address frequency
        $ipCount = Database::fetchColumn(
            "SELECT COUNT(*) FROM {$this->table} WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            [$participant['ip_address']]
        );

        if ($ipCount > env('FRAUD_MAX_DAILY_PARTICIPATIONS', 5)) {
            $riskFactors[] = "High IP frequency: $ipCount participations in 24h";
            $riskLevel = 'high';
        }

        // Check device fingerprint frequency
        if ($participant['device_fingerprint']) {
            $deviceCount = Database::fetchColumn(
                "SELECT COUNT(*) FROM {$this->table} WHERE device_fingerprint = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
                [$participant['device_fingerprint']]
            );

            if ($deviceCount > 3) {
                $riskFactors[] = "High device frequency: $deviceCount participations in 24h";
                $riskLevel = 'high';
            }
        }

        // Check for unusually fast completion times
        if ($participant['total_time_all_questions'] && $participant['total_time_all_questions'] < 30) {
            $riskFactors[] = "Unusually fast completion: {$participant['total_time_all_questions']}s";
            $riskLevel = $riskLevel === 'high' ? 'high' : 'medium';
        }

        // Check question times for consistency
        if ($participant['question_times_json']) {
            $questionTimes = json_decode($participant['question_times_json'], true);
            $avgTime = array_sum($questionTimes) / count($questionTimes);

            if ($avgTime < 2) {
                $riskFactors[] = "Average question time too fast: {$avgTime}s";
                $riskLevel = 'high';
            }
        }

        return [
            'risk_level' => $riskLevel,
            'factors' => $riskFactors,
            'ip_frequency' => $ipCount ?? 0,
            'device_frequency' => $deviceCount ?? 0
        ];
    }

    /**
     * Generate device fingerprint
     *
     * @param array<string, mixed> $data Participant data
     * @return string Device fingerprint
     */
    private function generateDeviceFingerprint(array $data): string
    {
        $components = [
            $data['user_agent'] ?? '',
            $data['ip_address'] ?? '',
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            $_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''
        ];

        return hash('sha256', implode('|', $components));
    }

    /**
     * Validate participant data
     *
     * @param array<string, mixed> $data Participant data
     * @return void
     * @throws Exception
     */
    private function validateParticipantData(array $data): void
    {
        $required = ['round_id', 'user_email', 'first_name', 'last_name', 'phone', 'payment_currency', 'payment_amount', 'ip_address', 'session_id'];

        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                throw new Exception("Required field missing: $field");
            }
        }

        // Validate email
        if (!filter_var($data['user_email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email format');
        }

        // Validate names
        if (strlen($data['first_name']) < 1 || strlen($data['first_name']) > 100) {
            throw new Exception('First name must be between 1 and 100 characters');
        }

        if (strlen($data['last_name']) < 1 || strlen($data['last_name']) > 100) {
            throw new Exception('Last name must be between 1 and 100 characters');
        }

        // Validate phone
        if (strlen($data['phone']) < 10 || strlen($data['phone']) > 20) {
            throw new Exception('Phone number must be between 10 and 20 characters');
        }

        // Validate payment amount
        if (!is_numeric($data['payment_amount']) || $data['payment_amount'] <= 0) {
            throw new Exception('Payment amount must be a positive number');
        }

        // Validate IP address
        if (!filter_var($data['ip_address'], FILTER_VALIDATE_IP)) {
            throw new Exception('Invalid IP address');
        }
    }
}
