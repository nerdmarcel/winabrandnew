<?php

/**
 * Win a Brand New - Participant Management Model
 * File: /models/Participant.php
 *
 * Handles participant management, timing logic, device continuity,
 * and winner selection according to the Development Specification.
 *
 * Key Features:
 * - Microsecond precision timing measurement using microtime(true)
 * - Complete game participation flow from landing to completion
 * - Device continuity enforcement (same device/session required)
 * - Winner selection based on fastest total completion time
 * - Fraud detection and prevention capabilities
 * - Payment status tracking and validation
 * - Question answer recording and validation
 * - Device fingerprint validation for security
 *
 * Critical Timing Logic:
 * - Timer starts when Question 1 is displayed
 * - Timer pauses after Question 3 submission (during payment)
 * - Timer resumes when Question 4 is displayed after payment confirmation
 * - Total timing = Questions 1-3 (pre-payment) + Questions 4-9 (post-payment)
 * - Winner determination by fastest total completion time (microsecond precision)
 * - 10-second timeout per question (server-side enforcement)
 * - Wrong answer or timeout = immediate game over
 *
 * @package WinABrandNew\Models
 * @version 1.0.0
 * @author NerdMarcel Development Team
 */

namespace WinABrandNew\Models;

use WinABrandNew\Core\Database;
use WinABrandNew\Core\Security;
use Exception;

class Participant
{
    /**
     * Participant table name
     */
    private const TABLE = 'participants';

    /**
     * Participant status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';

    /**
     * Question timeout in seconds
     */
    public const QUESTION_TIMEOUT = 10;

    /**
     * Minimum answer time for fraud detection (seconds)
     */
    public const MIN_ANSWER_TIME = 0.5;

    /**
     * Maximum daily participations per user
     */
    public const MAX_DAILY_PARTICIPATIONS = 5;

    /**
     * Participant data
     *
     * @var array
     */
    private array $data = [];

    /**
     * Timing data for current session
     *
     * @var array
     */
    private array $timingData = [];

    /**
     * Constructor
     *
     * @param array $data Participant data
     */
    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    /**
     * Create new participant entry
     *
     * @param int $roundId Round ID
     * @param array $userData User information
     * @param string $sessionId Session ID for device continuity
     * @param string $deviceFingerprint Device fingerprint
     * @param string $ipAddress User IP address
     * @param string|null $referralSource Referral source if applicable
     * @return Participant
     * @throws Exception If creation fails
     */
    public static function create(
        int $roundId,
        array $userData,
        string $sessionId,
        string $deviceFingerprint,
        string $ipAddress,
        ?string $referralSource = null
    ): self {
        // Validate required user data
        $requiredFields = ['email', 'first_name', 'last_name', 'phone'];
        foreach ($requiredFields as $field) {
            if (empty($userData[$field])) {
                throw new Exception("Missing required field: {$field}", 400);
            }
        }

        // Validate email format
        if (!filter_var($userData['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email format", 400);
        }

        // Check daily participation limit
        self::checkDailyParticipationLimit($userData['email'], $ipAddress);

        // Check for existing active participation in same round
        $existing = self::getActiveParticipation($userData['email'], $roundId);
        if ($existing) {
            throw new Exception("User already has active participation in this round", 409);
        }

        $participantData = [
            'round_id' => $roundId,
            'user_email' => $userData['email'],
            'first_name' => $userData['first_name'],
            'last_name' => $userData['last_name'],
            'phone' => $userData['phone'],
            'whatsapp_consent' => (bool)($userData['whatsapp_consent'] ?? false),
            'payment_status' => self::STATUS_PENDING,
            'payment_currency' => $userData['currency'] ?? 'GBP',
            'payment_amount' => $userData['amount'] ?? 0.00,
            'device_fingerprint' => $deviceFingerprint,
            'ip_address' => $ipAddress,
            'session_id' => $sessionId,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'referral_source' => $referralSource,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        try {
            $participantId = Database::insert(
                "INSERT INTO " . self::TABLE . " (" . implode(', ', array_keys($participantData)) . ")
                 VALUES (" . str_repeat('?,', count($participantData) - 1) . "?)",
                array_values($participantData)
            );

            $participantData['id'] = (int)$participantId;

            // Log participant creation
            self::logAnalyticsEvent('game_start', $participantData);

            return new self($participantData);

        } catch (Exception $e) {
            throw new Exception("Failed to create participant: " . $e->getMessage(), 500);
        }
    }

    /**
     * Find participant by ID
     *
     * @param int $id Participant ID
     * @return Participant|null
     */
    public static function findById(int $id): ?self
    {
        $data = Database::selectOne(
            "SELECT * FROM " . self::TABLE . " WHERE id = ?",
            [$id]
        );

        return $data ? new self($data) : null;
    }

    /**
     * Find participant by session ID (for device continuity)
     *
     * @param string $sessionId Session ID
     * @return Participant|null
     */
    public static function findBySession(string $sessionId): ?self
    {
        $data = Database::selectOne(
            "SELECT * FROM " . self::TABLE . " WHERE session_id = ? AND game_completed = 0",
            [$sessionId]
        );

        return $data ? new self($data) : null;
    }

    /**
     * Get active participation for user in specific round
     *
     * @param string $email User email
     * @param int $roundId Round ID
     * @return Participant|null
     */
    public static function getActiveParticipation(string $email, int $roundId): ?self
    {
        $data = Database::selectOne(
            "SELECT * FROM " . self::TABLE . "
             WHERE user_email = ? AND round_id = ? AND game_completed = 0",
            [$email, $roundId]
        );

        return $data ? new self($data) : null;
    }

    /**
     * Start timing for questions (called when Question 1 is displayed)
     *
     * @return float Start timestamp with microsecond precision
     * @throws Exception If timing start fails
     */
    public function startTiming(): float
    {
        $startTime = microtime(true);

        $this->timingData = [
            'game_start_time' => $startTime,
            'current_question' => 1,
            'question_start_time' => $startTime,
            'pre_payment_questions' => [],
            'post_payment_questions' => [],
            'paused_at' => null,
            'paused_duration' => 0
        ];

        // Save timing start to database
        $this->updateData([
            'timing_started_at' => date('Y-m-d H:i:s', (int)$startTime)
        ]);

        return $startTime;
    }

    /**
     * Record answer for current question with timing
     *
     * @param int $questionNumber Question number (1-9)
     * @param string $answer User's answer (A, B, or C)
     * @param string $correctAnswer Correct answer for validation
     * @param int $questionId Question ID for tracking
     * @return array Timing and validation result
     * @throws Exception If answer recording fails
     */
    public function recordAnswer(
        int $questionNumber,
        string $answer,
        string $correctAnswer,
        int $questionId
    ): array {
        $currentTime = microtime(true);

        // Validate question number
        if ($questionNumber < 1 || $questionNumber > 9) {
            throw new Exception("Invalid question number: {$questionNumber}", 400);
        }

        // Validate answer format
        if (!in_array($answer, ['A', 'B', 'C'])) {
            throw new Exception("Invalid answer format. Must be A, B, or C", 400);
        }

        // Get question start time
        $questionStartTime = $this->timingData['question_start_time'] ?? $currentTime;
        $questionTime = $currentTime - $questionStartTime;

        // Check for timeout (10 seconds per question)
        if ($questionTime > self::QUESTION_TIMEOUT) {
            $this->endGameWithTimeout($questionNumber);
            throw new Exception("Question timeout. Game over.", 408);
        }

        // Check for suspiciously fast answers (fraud detection)
        if ($questionTime < self::MIN_ANSWER_TIME) {
            $this->flagFraudulentActivity('fast_answer', [
                'question_number' => $questionNumber,
                'answer_time' => $questionTime,
                'minimum_expected' => self::MIN_ANSWER_TIME
            ]);
        }

        // Validate answer
        $isCorrect = ($answer === $correctAnswer);
        if (!$isCorrect) {
            $this->endGameWithWrongAnswer($questionNumber, $answer, $correctAnswer);
            throw new Exception("Wrong answer. Game over.", 400);
        }

        // Record question timing
        $questionData = [
            'question_number' => $questionNumber,
            'question_id' => $questionId,
            'answer' => $answer,
            'correct' => $isCorrect,
            'time_taken' => $questionTime,
            'timestamp' => $currentTime
        ];

        // Store in appropriate array (pre or post payment)
        if ($questionNumber <= 3) {
            $this->timingData['pre_payment_questions'][] = $questionData;
        } else {
            $this->timingData['post_payment_questions'][] = $questionData;
        }

        // Update current question tracking
        $this->timingData['current_question'] = $questionNumber + 1;

        // Prepare for next question if not finished
        if ($questionNumber < 9) {
            $this->timingData['question_start_time'] = $currentTime;
        }

        return [
            'correct' => $isCorrect,
            'time_taken' => $questionTime,
            'question_completed' => $questionNumber,
            'game_completed' => $questionNumber === 9,
            'total_time_so_far' => $this->calculateCurrentTotalTime()
        ];
    }

    /**
     * Pause timing (called after Question 3, during payment process)
     *
     * @return float Pause timestamp
     * @throws Exception If pause fails
     */
    public function pauseTiming(): float
    {
        $pauseTime = microtime(true);
        $this->timingData['paused_at'] = $pauseTime;

        // Calculate pre-payment time (questions 1-3)
        $prePaymentTime = $this->calculatePrePaymentTime();

        // Update database with pre-payment timing
        $this->updateData([
            'pre_payment_time' => $prePaymentTime,
            'timing_paused_at' => date('Y-m-d H:i:s', (int)$pauseTime)
        ]);

        return $pauseTime;
    }

    /**
     * Resume timing (called when Question 4 is displayed after payment)
     *
     * @return float Resume timestamp
     * @throws Exception If resume fails
     */
    public function resumeTiming(): float
    {
        $resumeTime = microtime(true);

        if (!isset($this->timingData['paused_at'])) {
            throw new Exception("Timer was not paused, cannot resume", 400);
        }

        // Calculate pause duration
        $pauseDuration = $resumeTime - $this->timingData['paused_at'];
        $this->timingData['paused_duration'] += $pauseDuration;
        $this->timingData['paused_at'] = null;

        // Set question start time for Question 4
        $this->timingData['question_start_time'] = $resumeTime;

        // Update database
        $this->updateData([
            'timing_resumed_at' => date('Y-m-d H:i:s', (int)$resumeTime)
        ]);

        return $resumeTime;
    }

    /**
     * Complete game and calculate final timing
     *
     * @return array Final completion data
     * @throws Exception If completion fails
     */
    public function completeGame(): array
    {
        $completionTime = microtime(true);

        // Calculate final times
        $prePaymentTime = $this->calculatePrePaymentTime();
        $postPaymentTime = $this->calculatePostPaymentTime();
        $totalTime = $prePaymentTime + $postPaymentTime;

        // Prepare answers JSON
        $allAnswers = array_merge(
            $this->timingData['pre_payment_questions'] ?? [],
            $this->timingData['post_payment_questions'] ?? []
        );

        $questionTimes = array_map(function($q) {
            return $q['time_taken'];
        }, $allAnswers);

        // Count correct answers
        $correctAnswers = count(array_filter($allAnswers, function($q) {
            return $q['correct'];
        }));

        // Update database with final completion data
        $updateData = [
            'game_completed' => 1,
            'total_time_all_questions' => $totalTime,
            'pre_payment_time' => $prePaymentTime,
            'post_payment_time' => $postPaymentTime,
            'question_times_json' => json_encode($questionTimes),
            'answers_json' => json_encode($allAnswers),
            'correct_answers' => $correctAnswers,
            'completed_at' => date('Y-m-d H:i:s', (int)$completionTime),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->updateData($updateData);

        // Log completion event
        self::logAnalyticsEvent('game_complete', $this->data);

        // Check if this completion triggers round completion
        $this->checkRoundCompletion();

        return [
            'participant_id' => $this->getId(),
            'total_time' => $totalTime,
            'pre_payment_time' => $prePaymentTime,
            'post_payment_time' => $postPaymentTime,
            'correct_answers' => $correctAnswers,
            'completion_time' => $completionTime,
            'question_details' => $allAnswers
        ];
    }

    /**
     * Update payment status
     *
     * @param string $status Payment status
     * @param string|null $paymentId External payment ID
     * @param string|null $provider Payment provider (mollie/stripe)
     * @param float|null $amount Payment amount
     * @param string|null $currency Payment currency
     * @return bool Success status
     * @throws Exception If update fails
     */
    public function updatePaymentStatus(
        string $status,
        ?string $paymentId = null,
        ?string $provider = null,
        ?float $amount = null,
        ?string $currency = null
    ): bool {
        // Validate status
        $validStatuses = [
            self::STATUS_PENDING,
            self::STATUS_PAID,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
            self::STATUS_REFUNDED
        ];

        if (!in_array($status, $validStatuses)) {
            throw new Exception("Invalid payment status: {$status}", 400);
        }

        $updateData = [
            'payment_status' => $status,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        if ($paymentId !== null) {
            $updateData['payment_id'] = $paymentId;
        }
        if ($provider !== null) {
            $updateData['payment_provider'] = $provider;
        }
        if ($amount !== null) {
            $updateData['payment_amount'] = $amount;
        }
        if ($currency !== null) {
            $updateData['payment_currency'] = $currency;
        }

        // If status is paid, record confirmation timestamp
        if ($status === self::STATUS_PAID) {
            $updateData['payment_confirmed_at'] = date('Y-m-d H:i:s');
        }

        $success = $this->updateData($updateData);

        if ($success && $status === self::STATUS_PAID) {
            // Log successful payment
            self::logAnalyticsEvent('payment_success', $this->data);

            // Update round participant count
            $this->updateRoundParticipantCount();
        } elseif ($success && $status === self::STATUS_FAILED) {
            // Log failed payment
            self::logAnalyticsEvent('payment_failure', $this->data);
        }

        return $success;
    }

    /**
     * Select winner for completed round (fastest total time)
     *
     * @param int $roundId Round ID
     * @return Participant|null Winner participant
     * @throws Exception If winner selection fails
     */
    public static function selectWinner(int $roundId): ?self
    {
        try {
            // Get all paid participants with completed games, ordered by total time
            $winnerData = Database::selectOne(
                "SELECT * FROM " . self::TABLE . "
                 WHERE round_id = ?
                   AND payment_status = ?
                   AND game_completed = 1
                   AND total_time_all_questions IS NOT NULL
                   AND is_fraudulent = 0
                 ORDER BY total_time_all_questions ASC, id ASC
                 LIMIT 1",
                [$roundId, self::STATUS_PAID]
            );

            if (!$winnerData) {
                return null;
            }

            $winner = new self($winnerData);

            // Mark as winner
            $winner->updateData([
                'is_winner' => 1,
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            // Log winner selection
            self::logAnalyticsEvent('winner_selected', $winner->data);

            return $winner;

        } catch (Exception $e) {
            throw new Exception("Failed to select winner: " . $e->getMessage(), 500);
        }
    }

    /**
     * Validate device continuity (same device/session enforcement)
     *
     * @param string $sessionId Current session ID
     * @param string $deviceFingerprint Current device fingerprint
     * @return bool Validation result
     * @throws Exception If device validation fails
     */
    public function validateDeviceContinuity(string $sessionId, string $deviceFingerprint): bool
    {
        // Check session ID match
        if ($this->data['session_id'] !== $sessionId) {
            $this->flagFraudulentActivity('session_mismatch', [
                'expected_session' => $this->data['session_id'],
                'provided_session' => $sessionId
            ]);
            throw new Exception("Game must be completed on the same device/session", 403);
        }

        // Check device fingerprint match
        if ($this->data['device_fingerprint'] !== $deviceFingerprint) {
            $this->flagFraudulentActivity('device_mismatch', [
                'expected_fingerprint' => $this->data['device_fingerprint'],
                'provided_fingerprint' => $deviceFingerprint
            ]);
            throw new Exception("Game must be completed on the same device", 403);
        }

        return true;
    }

    /**
     * Flag fraudulent activity
     *
     * @param string $type Fraud type
     * @param array $details Fraud details
     * @return void
     */
    public function flagFraudulentActivity(string $type, array $details = []): void
    {
        $currentFlags = json_decode($this->data['fraud_flags'] ?? '[]', true);
        $currentFlags[] = [
            'type' => $type,
            'details' => $details,
            'timestamp' => microtime(true),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];

        // Calculate fraud score
        $fraudScore = $this->calculateFraudScore($currentFlags);

        $updateData = [
            'fraud_flags' => json_encode($currentFlags),
            'fraud_score' => $fraudScore,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        // Mark as fraudulent if score exceeds threshold
        if ($fraudScore >= 0.8) {
            $updateData['is_fraudulent'] = 1;
        }

        $this->updateData($updateData);

        // Log security event
        self::logSecurityEvent('fraud_detection', [
            'participant_id' => $this->getId(),
            'fraud_type' => $type,
            'fraud_score' => $fraudScore,
            'details' => $details
        ]);
    }

    /**
     * Calculate fraud score based on flags
     *
     * @param array $flags Fraud flags
     * @return float Fraud score (0.0 to 1.0)
     */
    private function calculateFraudScore(array $flags): float
    {
        if (empty($flags)) {
            return 0.0;
        }

        $scoreWeights = [
            'fast_answer' => 0.3,
            'session_mismatch' => 0.8,
            'device_mismatch' => 0.8,
            'network_pattern' => 0.4,
            'timing_anomaly' => 0.5,
            'multiple_attempts' => 0.6
        ];

        $totalScore = 0.0;
        foreach ($flags as $flag) {
            $weight = $scoreWeights[$flag['type']] ?? 0.2;
            $totalScore += $weight;
        }

        return min($totalScore, 1.0);
    }

    /**
     * End game due to timeout
     *
     * @param int $questionNumber Question that timed out
     * @return void
     */
    private function endGameWithTimeout(int $questionNumber): void
    {
        $this->updateData([
            'game_completed' => 1,
            'total_time_all_questions' => null, // No valid completion time
            'timeout_question' => $questionNumber,
            'completed_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        self::logAnalyticsEvent('game_timeout', array_merge($this->data, [
            'timeout_question' => $questionNumber
        ]));
    }

    /**
     * End game due to wrong answer
     *
     * @param int $questionNumber Question with wrong answer
     * @param string $userAnswer User's answer
     * @param string $correctAnswer Correct answer
     * @return void
     */
    private function endGameWithWrongAnswer(int $questionNumber, string $userAnswer, string $correctAnswer): void
    {
        $this->updateData([
            'game_completed' => 1,
            'total_time_all_questions' => null, // No valid completion time
            'wrong_answer_question' => $questionNumber,
            'wrong_answer_given' => $userAnswer,
            'correct_answer_was' => $correctAnswer,
            'completed_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        self::logAnalyticsEvent('game_wrong_answer', array_merge($this->data, [
            'wrong_answer_question' => $questionNumber,
            'user_answer' => $userAnswer,
            'correct_answer' => $correctAnswer
        ]));
    }

    /**
     * Calculate pre-payment time (questions 1-3)
     *
     * @return float Pre-payment time in seconds
     */
    private function calculatePrePaymentTime(): float
    {
        $prePaymentQuestions = $this->timingData['pre_payment_questions'] ?? [];
        return array_sum(array_column($prePaymentQuestions, 'time_taken'));
    }

    /**
     * Calculate post-payment time (questions 4-9)
     *
     * @return float Post-payment time in seconds
     */
    private function calculatePostPaymentTime(): float
    {
        $postPaymentQuestions = $this->timingData['post_payment_questions'] ?? [];
        return array_sum(array_column($postPaymentQuestions, 'time_taken'));
    }

    /**
     * Calculate current total time
     *
     * @return float Current total time
     */
    private function calculateCurrentTotalTime(): float
    {
        return $this->calculatePrePaymentTime() + $this->calculatePostPaymentTime();
    }

    /**
     * Check daily participation limit for fraud prevention
     *
     * @param string $email User email
     * @param string $ipAddress IP address
     * @return void
     * @throws Exception If limit exceeded
     */
    private static function checkDailyParticipationLimit(string $email, string $ipAddress): void
    {
        $today = date('Y-m-d');

        // Check email limit
        $emailCount = Database::selectOne(
            "SELECT COUNT(*) as count FROM " . self::TABLE . "
             WHERE user_email = ? AND DATE(created_at) = ?",
            [$email, $today]
        )['count'];

        if ($emailCount >= self::MAX_DAILY_PARTICIPATIONS) {
            throw new Exception("Daily participation limit exceeded for this email", 429);
        }

        // Check IP limit
        $ipCount = Database::selectOne(
            "SELECT COUNT(*) as count FROM " . self::TABLE . "
             WHERE ip_address = ? AND DATE(created_at) = ?",
            [$ipAddress, $today]
        )['count'];

        if ($ipCount >= self::MAX_DAILY_PARTICIPATIONS) {
            throw new Exception("Daily participation limit exceeded for this IP address", 429);
        }
    }

    /**
     * Update round participant count after payment confirmation
     *
     * @return void
     */
    private function updateRoundParticipantCount(): void
    {
        Database::execute(
            "UPDATE rounds SET
                paid_participant_count = (
                    SELECT COUNT(*) FROM " . self::TABLE . "
                    WHERE round_id = ? AND payment_status = ?
                ),
                updated_at = ?
             WHERE id = ?",
            [$this->data['round_id'], self::STATUS_PAID, date('Y-m-d H:i:s'), $this->data['round_id']]
        );
    }

    /**
     * Check if round is complete and trigger winner selection
     *
     * @return void
     */
    private function checkRoundCompletion(): void
    {
        // Get round information
        $round = Database::selectOne(
            "SELECT r.*, g.max_players
             FROM rounds r
             JOIN games g ON r.game_id = g.id
             WHERE r.id = ?",
            [$this->data['round_id']]
        );

        if (!$round) {
            return;
        }

        // Count paid participants with completed games
        $completedCount = Database::selectOne(
            "SELECT COUNT(*) as count FROM " . self::TABLE . "
             WHERE round_id = ? AND payment_status = ? AND game_completed = 1",
            [$this->data['round_id'], self::STATUS_PAID]
        )['count'];

        // Check if round is complete
        if ($completedCount >= $round['max_players']) {
            // Mark round as full and trigger winner selection
            Database::execute(
                "UPDATE rounds SET status = 'full', updated_at = ? WHERE id = ?",
                [date('Y-m-d H:i:s'), $this->data['round_id']]
            );

            // Winner selection will be handled by a separate process
            // to avoid blocking the user response
        }
    }

    /**
     * Get participant data
     *
     * @return array Participant data
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Get participant ID
     *
     * @return int Participant ID
     */
    public function getId(): int
    {
        return (int)($this->data['id'] ?? 0);
    }

    /**
     * Get round ID
     *
     * @return int Round ID
     */
    public function getRoundId(): int
    {
        return (int)($this->data['round_id'] ?? 0);
    }

    /**
     * Get payment status
     *
     * @return string Payment status
     */
    public function getPaymentStatus(): string
    {
        return $this->data['payment_status'] ?? self::STATUS_PENDING;
    }

    /**
     * Check if participant is winner
     *
     * @return bool Winner status
     */
    public function isWinner(): bool
    {
        return (bool)($this->data['is_winner'] ?? false);
    }

    /**
     * Check if game is completed
     *
     * @return bool Completion status
     */
    public function isGameCompleted(): bool
    {
        return (bool)($this->data['game_completed'] ?? false);
    }

    /**
     * Check if participant has valid completion time
     *
     * @return bool Has valid time
     */
    public function hasValidCompletionTime(): bool
    {
        return !is_null($this->data['total_time_all_questions'] ?? null);
    }

    /**
     * Get total completion time
     *
     * @return float|null Total time in seconds
     */
    public function getTotalTime(): ?float
    {
        return $this->data['total_time_all_questions'] ?? null;
    }

    /**
     * Update participant data in database
     *
     * @param array $data Data to update
     * @return bool Success status
     */
    private function updateData(array $data): bool
    {
        if (empty($data) || !$this->getId()) {
            return false;
        }

        $data['updated_at'] = date('Y-m-d H:i:s');

        try {
            $setParts = [];
            $values = [];

            foreach ($data as $key => $value) {
                $setParts[] = "{$key} = ?";
                $values[] = $value;
            }

            $values[] = $this->getId();

            $affectedRows = Database::update(
                "UPDATE " . self::TABLE . " SET " . implode(', ', $setParts) . " WHERE id = ?",
                $values
            );

            if ($affectedRows > 0) {
                // Update local data
                $this->data = array_merge($this->data, $data);
                return true;
            }

            return false;

        } catch (Exception $e) {
            error_log("Failed to update participant data: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Log analytics event
     *
     * @param string $eventType Event type
     * @param array $data Event data
     * @return void
     */
    private static function logAnalyticsEvent(string $eventType, array $data): void
    {
        try {
            $eventData = [
                'event_type' => $eventType,
                'participant_id' => $data['id'] ?? null,
                'round_id' => $data['round_id'] ?? null,
                'game_id' => null, // Will be fetched from round if needed
                'revenue_amount' => $data['payment_amount'] ?? null,
                'revenue_currency' => $data['payment_currency'] ?? null,
                'event_properties' => json_encode($data),
                'session_id' => $data['session_id'] ?? null,
                'ip_address' => $data['ip_address'] ?? $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $data['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? null,
                'created_at' => date('Y-m-d H:i:s')
            ];

            Database::insert(
                "INSERT INTO analytics_events (" . implode(', ', array_keys($eventData)) . ")
                 VALUES (" . str_repeat('?,', count($eventData) - 1) . "?)",
                array_values($eventData)
            );

        } catch (Exception $e) {
            error_log("Failed to log analytics event: " . $e->getMessage());
        }
    }

    /**
     * Log security event
     *
     * @param string $eventType Event type
     * @param array $details Event details
     * @return void
     */
    private static function logSecurityEvent(string $eventType, array $details): void
    {
        try {
            $logData = [
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'event_type' => $eventType,
                'details_json' => json_encode($details),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'created_at' => date('Y-m-d H:i:s')
            ];

            Database::insert(
                "INSERT INTO security_log (" . implode(', ', array_keys($logData)) . ")
                 VALUES (" . str_repeat('?,', count($logData) - 1) . "?)",
                array_values($logData)
            );

        } catch (Exception $e) {
            error_log("Failed to log security event: " . $e->getMessage());
        }
    }

    /**
     * Get participants for specific round
     *
     * @param int $roundId Round ID
     * @param string|null $status Filter by payment status
     * @param bool $completedOnly Show only completed games
     * @return array Participants
     */
    public static function getByRound(int $roundId, ?string $status = null, bool $completedOnly = false): array
    {
        $conditions = ['round_id = ?'];
        $params = [$roundId];

        if ($status !== null) {
            $conditions[] = 'payment_status = ?';
            $params[] = $status;
        }

        if ($completedOnly) {
            $conditions[] = 'game_completed = 1';
        }

        $sql = "SELECT * FROM " . self::TABLE . "
                WHERE " . implode(' AND ', $conditions) . "
                ORDER BY total_time_all_questions ASC, id ASC";

        $results = Database::select($sql, $params);

        return array_map(function($data) {
            return new self($data);
        }, $results);
    }

    /**
     * Get leaderboard for round (top times)
     *
     * @param int $roundId Round ID
     * @param int $limit Number of entries
     * @return array Leaderboard
     */
    public static function getLeaderboard(int $roundId, int $limit = 10): array
    {
        $results = Database::select(
            "SELECT * FROM " . self::TABLE . "
             WHERE round_id = ?
               AND payment_status = ?
               AND game_completed = 1
               AND total_time_all_questions IS NOT NULL
               AND is_fraudulent = 0
             ORDER BY total_time_all_questions ASC, id ASC
             LIMIT ?",
            [$roundId, self::STATUS_PAID, $limit]
        );

        return array_map(function($data, $index) {
            $participant = new self($data);
            $data['rank'] = $index + 1;
            return $data;
        }, $results, array_keys($results));
    }

    /**
     * Health check for participant system
     *
     * @return array Health status
     */
    public static function healthCheck(): array
    {
        try {
            // Test database connectivity
            $testQuery = Database::selectOne("SELECT COUNT(*) as count FROM " . self::TABLE . " LIMIT 1");

            // Check for recent activity
            $recentCount = Database::selectOne(
                "SELECT COUNT(*) as count FROM " . self::TABLE . "
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
            )['count'];

            // Check for stuck sessions (over 1 hour old, not completed)
            $stuckSessions = Database::selectOne(
                "SELECT COUNT(*) as count FROM " . self::TABLE . "
                 WHERE game_completed = 0
                   AND created_at <= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
            )['count'];

            return [
                'status' => 'healthy',
                'database_connectivity' => 'ok',
                'recent_participants' => $recentCount,
                'stuck_sessions' => $stuckSessions,
                'total_participants' => $testQuery['count']
            ];

        } catch (Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'database_connectivity' => 'failed'
            ];
        }
    }
}
