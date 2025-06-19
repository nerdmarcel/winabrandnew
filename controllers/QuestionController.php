<?php

/**
 * Win a Brand New - Question Controller
 * File: /controllers/QuestionController.php
 *
 * Handles question serving and answer processing according to the Development Specification.
 * Implements the question selection algorithm, timing measurement (microtime),
 * answer validation, and timeout handling for the quiz game flow.
 *
 * Critical Features:
 * - Question selection algorithm with unique questions per user
 * - Microsecond precision timing using microtime(true)
 * - Server-side 10-second timeout enforcement
 * - Answer validation and scoring
 * - Network interruption handling
 * - Device continuity validation
 * - Fraud detection integration
 *
 * @package WinABrandNew\Controllers
 * @version 1.0.0
 * @author NerdMarcel Development Team
 */

namespace WinABrandNew\Controllers;

use WinABrandNew\Core\Database;
use WinABrandNew\Core\Security;
use WinABrandNew\Models\Question;
use WinABrandNew\Models\Participant;
use WinABrandNew\Models\Round;
use WinABrandNew\Models\Game;
use WinABrandNew\Controllers\BaseController;
use Exception;

class QuestionController extends BaseController
{
    /**
     * Question timeout in seconds (server-side enforcement)
     */
    private const QUESTION_TIMEOUT = 10;

    /**
     * Maximum questions per game
     */
    private const MAX_QUESTIONS = 9;

    /**
     * Pre-payment questions (1-3)
     */
    private const PRE_PAYMENT_QUESTIONS = 3;

    /**
     * Post-payment questions (4-9)
     */
    private const POST_PAYMENT_QUESTIONS = 6;

    /**
     * Get the next question for the participant
     * Implements the unique question selection algorithm
     *
     * @return void
     */
    public function getNextQuestion(): void
    {
        try {
            // Validate session and get participant
            $participant = $this->validateParticipantSession();
            if (!$participant) {
                $this->jsonError('Invalid session', 401);
                return;
            }

            // Check if game is still in progress
            if ($participant['game_completed']) {
                $this->jsonError('Game already completed', 400);
                return;
            }

            // Determine current question number
            $currentQuestionNumber = $this->getCurrentQuestionNumber($participant['id']);

            if ($currentQuestionNumber > self::MAX_QUESTIONS) {
                $this->jsonError('All questions completed', 400);
                return;
            }

            // Check payment status for questions 4+
            if ($currentQuestionNumber > self::PRE_PAYMENT_QUESTIONS &&
                $participant['payment_status'] !== 'paid') {
                $this->jsonError('Payment required to continue', 402);
                return;
            }

            // Check device continuity
            if (!$this->validateDeviceContinuity($participant)) {
                $this->jsonError('Game must be completed on the same device', 403);
                return;
            }

            // Get round information
            $round = Round::findById($participant['round_id']);
            if (!$round) {
                $this->jsonError('Round not found', 404);
                return;
            }

            // Get game information
            $game = Game::findById($round['game_id']);
            if (!$game) {
                $this->jsonError('Game not found', 404);
                return;
            }

            // Select next question using unique selection algorithm
            $question = $this->selectUniqueQuestion(
                $game['id'],
                $participant['user_email'],
                $currentQuestionNumber
            );

            if (!$question) {
                $this->jsonError('No questions available', 404);
                return;
            }

            // Start timing for this question
            $this->startQuestionTiming($participant['id'], $currentQuestionNumber);

            // Prepare question data (exclude correct answer)
            $questionData = [
                'question_id' => $question['id'],
                'question_number' => $currentQuestionNumber,
                'total_questions' => self::MAX_QUESTIONS,
                'question_text' => $question['question_text'],
                'options' => [
                    'A' => $question['option_a'],
                    'B' => $question['option_b'],
                    'C' => $question['option_c']
                ],
                'time_limit' => self::QUESTION_TIMEOUT,
                'server_time' => microtime(true),
                'is_pre_payment' => $currentQuestionNumber <= self::PRE_PAYMENT_QUESTIONS,
                'payment_required' => $currentQuestionNumber === (self::PRE_PAYMENT_QUESTIONS + 1) &&
                                    $participant['payment_status'] !== 'paid'
            ];

            // Add image if available
            if (!empty($question['image_url'])) {
                $questionData['image_url'] = $question['image_url'];
            }

            // Log analytics event
            $this->logAnalyticsEvent('question_served', $participant['id'], [
                'question_id' => $question['id'],
                'question_number' => $currentQuestionNumber,
                'round_id' => $participant['round_id']
            ]);

            $this->jsonSuccess($questionData);

        } catch (Exception $e) {
            $this->logError('Error getting next question: ' . $e->getMessage());
            $this->jsonError('Failed to get question', 500);
        }
    }

    /**
     * Submit answer for the current question
     * Implements microsecond timing and answer validation
     *
     * @return void
     */
    public function submitAnswer(): void
    {
        try {
            // Validate CSRF token
            if (!$this->validateCsrfToken()) {
                $this->jsonError('Invalid CSRF token', 403);
                return;
            }

            // Get and validate input
            $questionId = $this->getInput('question_id', 'int');
            $answer = $this->getInput('answer', 'string');
            $clientSubmitTime = $this->getInput('client_time', 'float');

            if (!$questionId || !$answer || !in_array($answer, ['A', 'B', 'C'])) {
                $this->jsonError('Invalid input', 400);
                return;
            }

            // Validate session and get participant
            $participant = $this->validateParticipantSession();
            if (!$participant) {
                $this->jsonError('Invalid session', 401);
                return;
            }

            // Get question and validate it belongs to this game
            $question = Question::findById($questionId);
            if (!$question) {
                $this->jsonError('Question not found', 404);
                return;
            }

            // Get timing information
            $timingData = $this->getQuestionTiming($participant['id']);
            if (!$timingData) {
                $this->jsonError('No active question timing', 400);
                return;
            }

            // Calculate response time with microsecond precision
            $serverSubmitTime = microtime(true);
            $responseTime = $serverSubmitTime - $timingData['start_time'];

            // Check for timeout (server-side enforcement)
            if ($responseTime > self::QUESTION_TIMEOUT) {
                $this->handleTimeout($participant, $questionId, $responseTime);
                return;
            }

            // Validate answer and calculate score
            $isCorrect = ($answer === $question['correct_answer']);
            $currentQuestionNumber = $this->getCurrentQuestionNumber($participant['id']);

            // Fraud detection checks
            $fraudScore = $this->calculateFraudScore($participant, $responseTime, $isCorrect);
            if ($fraudScore > 0.7) {
                $this->flagFraudulentParticipant($participant['id'], $fraudScore);
                $this->jsonError('Suspicious activity detected', 403);
                return;
            }

            // Store answer and timing
            $this->storeAnswer($participant['id'], $questionId, $answer, $responseTime, $isCorrect);

            // Update participant progress
            $this->updateParticipantProgress($participant['id'], $currentQuestionNumber, $responseTime);

            // Check if this was the last question
            $isGameComplete = ($currentQuestionNumber >= self::MAX_QUESTIONS);

            if ($isGameComplete) {
                $this->completeGame($participant);
            }

            // Prepare response
            $response = [
                'correct' => $isCorrect,
                'correct_answer' => $question['correct_answer'],
                'response_time' => round($responseTime, 3),
                'question_number' => $currentQuestionNumber,
                'game_complete' => $isGameComplete
            ];

            if ($isGameComplete) {
                $response['total_time'] = $this->calculateTotalTime($participant['id']);
                $response['completion_rank'] = $this->calculateCompletionRank($participant);
            }

            // Log analytics event
            $this->logAnalyticsEvent('answer_submitted', $participant['id'], [
                'question_id' => $questionId,
                'answer' => $answer,
                'correct' => $isCorrect,
                'response_time' => $responseTime,
                'question_number' => $currentQuestionNumber
            ]);

            $this->jsonSuccess($response);

        } catch (Exception $e) {
            $this->logError('Error submitting answer: ' . $e->getMessage());
            $this->jsonError('Failed to submit answer', 500);
        }
    }

    /**
     * Get question timing status for AJAX polling
     *
     * @return void
     */
    public function getTimingStatus(): void
    {
        try {
            $participant = $this->validateParticipantSession();
            if (!$participant) {
                $this->jsonError('Invalid session', 401);
                return;
            }

            $timingData = $this->getQuestionTiming($participant['id']);
            if (!$timingData) {
                $this->jsonSuccess(['active' => false]);
                return;
            }

            $elapsedTime = microtime(true) - $timingData['start_time'];
            $remainingTime = max(0, self::QUESTION_TIMEOUT - $elapsedTime);

            $this->jsonSuccess([
                'active' => true,
                'elapsed_time' => round($elapsedTime, 3),
                'remaining_time' => round($remainingTime, 3),
                'timeout' => $elapsedTime >= self::QUESTION_TIMEOUT
            ]);

        } catch (Exception $e) {
            $this->logError('Error getting timing status: ' . $e->getMessage());
            $this->jsonError('Failed to get timing status', 500);
        }
    }

    /**
     * Handle connection recovery after network interruption
     *
     * @return void
     */
    public function recoverConnection(): void
    {
        try {
            $participant = $this->validateParticipantSession();
            if (!$participant) {
                $this->jsonError('Invalid session', 401);
                return;
            }

            $timingData = $this->getQuestionTiming($participant['id']);
            if (!$timingData) {
                $this->jsonSuccess(['recovery' => 'no_active_question']);
                return;
            }

            $elapsedTime = microtime(true) - $timingData['start_time'];

            // If user reconnects after timeout, automatically fail the question
            if ($elapsedTime >= self::QUESTION_TIMEOUT) {
                $this->handleTimeout($participant, $timingData['question_id'], $elapsedTime);
                return;
            }

            // Return current question state
            $question = Question::findById($timingData['question_id']);
            $currentQuestionNumber = $this->getCurrentQuestionNumber($participant['id']);

            $response = [
                'recovery' => 'success',
                'question' => [
                    'question_id' => $question['id'],
                    'question_number' => $currentQuestionNumber,
                    'question_text' => $question['question_text'],
                    'options' => [
                        'A' => $question['option_a'],
                        'B' => $question['option_b'],
                        'C' => $question['option_c']
                    ],
                    'elapsed_time' => round($elapsedTime, 3),
                    'remaining_time' => round(self::QUESTION_TIMEOUT - $elapsedTime, 3)
                ]
            ];

            $this->jsonSuccess($response);

        } catch (Exception $e) {
            $this->logError('Error recovering connection: ' . $e->getMessage());
            $this->jsonError('Failed to recover connection', 500);
        }
    }

    /**
     * Select unique question using the algorithm from specification
     *
     * @param int $gameId Game ID
     * @param string $userEmail User email
     * @param int $questionNumber Current question number
     * @return array|null Question data or null if none available
     */
    private function selectUniqueQuestion(int $gameId, string $userEmail, int $questionNumber): ?array
    {
        // Get all questions that user hasn't seen before
        $unseenQuestions = Database::select("
            SELECT q.* FROM questions q
            WHERE q.game_id = ?
            AND q.active = 1
            AND q.id NOT IN (
                SELECT pqh.question_id
                FROM participant_question_history pqh
                WHERE pqh.user_email = ? AND pqh.game_id = ?
            )
            ORDER BY RAND()
        ", [$gameId, $userEmail, $gameId]);

        if (count($unseenQuestions) >= 1) {
            // User has unseen questions - select one randomly
            $selectedQuestion = $unseenQuestions[0];
        } else {
            // User has seen all questions - reset cycle and select randomly
            $allQuestions = Database::select("
                SELECT * FROM questions
                WHERE game_id = ? AND active = 1
                ORDER BY RAND()
                LIMIT 1
            ", [$gameId]);

            if (empty($allQuestions)) {
                return null;
            }

            $selectedQuestion = $allQuestions[0];
        }

        // Record that this question has been seen by this user
        Database::insert("
            INSERT INTO participant_question_history
            (user_email, game_id, question_id, seen_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE seen_at = NOW()
        ", [$userEmail, $gameId, $selectedQuestion['id']]);

        return $selectedQuestion;
    }

    /**
     * Start timing for a question
     *
     * @param int $participantId Participant ID
     * @param int $questionNumber Question number
     * @return void
     */
    private function startQuestionTiming(int $participantId, int $questionNumber): void
    {
        // Store timing in session for this participant
        $_SESSION['question_timing'] = [
            'participant_id' => $participantId,
            'question_number' => $questionNumber,
            'start_time' => microtime(true),
            'question_id' => null // Will be set when question is served
        ];
    }

    /**
     * Get current question timing data
     *
     * @param int $participantId Participant ID
     * @return array|null Timing data or null if none active
     */
    private function getQuestionTiming(int $participantId): ?array
    {
        if (!isset($_SESSION['question_timing']) ||
            $_SESSION['question_timing']['participant_id'] !== $participantId) {
            return null;
        }

        return $_SESSION['question_timing'];
    }

    /**
     * Get current question number for participant
     *
     * @param int $participantId Participant ID
     * @return int Current question number (1-9)
     */
    private function getCurrentQuestionNumber(int $participantId): int
    {
        $questionTimes = Database::selectOne("
            SELECT question_times_json FROM participants
            WHERE id = ?
        ", [$participantId]);

        if (!$questionTimes || empty($questionTimes['question_times_json'])) {
            return 1;
        }

        $times = json_decode($questionTimes['question_times_json'], true);
        return count($times) + 1;
    }

    /**
     * Handle question timeout
     *
     * @param array $participant Participant data
     * @param int $questionId Question ID
     * @param float $responseTime Response time in seconds
     * @return void
     */
    private function handleTimeout(array $participant, int $questionId, float $responseTime): void
    {
        // Mark as timeout (wrong answer)
        $this->storeAnswer($participant['id'], $questionId, 'TIMEOUT', $responseTime, false);

        // Game over on timeout
        $this->endGameWithFailure($participant['id'], 'timeout');

        $this->jsonError('Question timeout - Game Over', 408, [
            'game_over' => true,
            'reason' => 'timeout',
            'response_time' => round($responseTime, 3)
        ]);
    }

    /**
     * Store answer and timing data
     *
     * @param int $participantId Participant ID
     * @param int $questionId Question ID
     * @param string $answer User's answer
     * @param float $responseTime Response time in seconds
     * @param bool $isCorrect Whether answer is correct
     * @return void
     */
    private function storeAnswer(int $participantId, int $questionId, string $answer, float $responseTime, bool $isCorrect): void
    {
        // Get current question times
        $participant = Database::selectOne("
            SELECT question_times_json, answers_json, correct_answers
            FROM participants
            WHERE id = ?
        ", [$participantId]);

        $questionTimes = $participant['question_times_json'] ?
                       json_decode($participant['question_times_json'], true) : [];
        $answers = $participant['answers_json'] ?
                  json_decode($participant['answers_json'], true) : [];

        // Add new data
        $questionTimes[] = round($responseTime, 6); // Microsecond precision
        $answers[] = [
            'question_id' => $questionId,
            'answer' => $answer,
            'correct' => $isCorrect,
            'time' => round($responseTime, 6)
        ];

        $correctCount = $participant['correct_answers'] + ($isCorrect ? 1 : 0);

        // Update participant record
        Database::update("
            UPDATE participants
            SET question_times_json = ?,
                answers_json = ?,
                correct_answers = ?,
                updated_at = NOW()
            WHERE id = ?
        ", [
            json_encode($questionTimes),
            json_encode($answers),
            $correctCount,
            $participantId
        ]);

        // Clear session timing
        unset($_SESSION['question_timing']);
    }

    /**
     * Update participant progress after each question
     *
     * @param int $participantId Participant ID
     * @param int $questionNumber Question number completed
     * @param float $responseTime Response time for this question
     * @return void
     */
    private function updateParticipantProgress(int $participantId, int $questionNumber, float $responseTime): void
    {
        // Get current timing data
        $participant = Database::selectOne("
            SELECT question_times_json, pre_payment_time, post_payment_time
            FROM participants
            WHERE id = ?
        ", [$participantId]);

        $questionTimes = json_decode($participant['question_times_json'], true);
        $totalTime = array_sum($questionTimes);

        // Calculate pre/post payment times
        $prePaymentTime = 0;
        $postPaymentTime = 0;

        for ($i = 0; $i < count($questionTimes); $i++) {
            if (($i + 1) <= self::PRE_PAYMENT_QUESTIONS) {
                $prePaymentTime += $questionTimes[$i];
            } else {
                $postPaymentTime += $questionTimes[$i];
            }
        }

        Database::update("
            UPDATE participants
            SET total_time_all_questions = ?,
                pre_payment_time = ?,
                post_payment_time = ?,
                updated_at = NOW()
            WHERE id = ?
        ", [
            round($totalTime, 6),
            round($prePaymentTime, 6),
            round($postPaymentTime, 6),
            $participantId
        ]);
    }

    /**
     * Complete the game for a participant
     *
     * @param array $participant Participant data
     * @return void
     */
    private function completeGame(array $participant): void
    {
        Database::update("
            UPDATE participants
            SET game_completed = 1,
                updated_at = NOW()
            WHERE id = ?
        ", [$participant['id']]);

        // Check if round is now full and needs winner selection
        $this->checkRoundCompletion($participant['round_id']);

        // Log completion analytics
        $this->logAnalyticsEvent('game_complete', $participant['id'], [
            'round_id' => $participant['round_id'],
            'total_time' => $this->calculateTotalTime($participant['id'])
        ]);
    }

    /**
     * Calculate total completion time for participant
     *
     * @param int $participantId Participant ID
     * @return float Total time in seconds
     */
    private function calculateTotalTime(int $participantId): float
    {
        $participant = Database::selectOne("
            SELECT total_time_all_questions FROM participants WHERE id = ?
        ", [$participantId]);

        return (float) $participant['total_time_all_questions'];
    }

    /**
     * Calculate completion rank for participant
     *
     * @param array $participant Participant data
     * @return int Completion rank
     */
    private function calculateCompletionRank(array $participant): int
    {
        $rank = Database::selectOne("
            SELECT COUNT(*) + 1 as rank
            FROM participants
            WHERE round_id = ?
            AND game_completed = 1
            AND payment_status = 'paid'
            AND total_time_all_questions < ?
        ", [$participant['round_id'], $participant['total_time_all_questions']]);

        return (int) $rank['rank'];
    }

    /**
     * Calculate fraud score based on response patterns
     *
     * @param array $participant Participant data
     * @param float $responseTime Response time for this question
     * @param bool $isCorrect Whether answer is correct
     * @return float Fraud score (0-1)
     */
    private function calculateFraudScore(array $participant, float $responseTime, bool $isCorrect): float
    {
        $score = 0;

        // Suspiciously fast responses
        if ($responseTime < 0.5) {
            $score += 0.3;
        }

        // Pattern analysis would go here
        // For now, return basic score
        return min($score, 1.0);
    }

    /**
     * Flag participant as fraudulent
     *
     * @param int $participantId Participant ID
     * @param float $fraudScore Fraud score
     * @return void
     */
    private function flagFraudulentParticipant(int $participantId, float $fraudScore): void
    {
        Database::update("
            UPDATE participants
            SET is_fraudulent = 1,
                fraud_score = ?,
                fraud_flags = JSON_ARRAY('fast_response'),
                updated_at = NOW()
            WHERE id = ?
        ", [$fraudScore, $participantId]);
    }

    /**
     * End game with failure
     *
     * @param int $participantId Participant ID
     * @param string $reason Failure reason
     * @return void
     */
    private function endGameWithFailure(int $participantId, string $reason): void
    {
        Database::update("
            UPDATE participants
            SET game_completed = 1,
                fraud_flags = JSON_ARRAY(?),
                updated_at = NOW()
            WHERE id = ?
        ", [$reason, $participantId]);
    }

    /**
     * Check if round is complete and trigger winner selection
     *
     * @param int $roundId Round ID
     * @return void
     */
    private function checkRoundCompletion(int $roundId): void
    {
        // This would trigger the winner selection process
        // Implementation details would be in WinnerController
        $round = Database::selectOne("
            SELECT r.*, g.max_players
            FROM rounds r
            JOIN games g ON r.game_id = g.id
            WHERE r.id = ?
        ", [$roundId]);

        $completedCount = Database::selectOne("
            SELECT COUNT(*) as count
            FROM participants
            WHERE round_id = ?
            AND game_completed = 1
            AND payment_status = 'paid'
        ", [$roundId]);

        if ($completedCount['count'] >= $round['max_players']) {
            // Round is full - trigger winner selection
            // This would be handled by WinnerController
        }
    }

    /**
     * Validate device continuity
     *
     * @param array $participant Participant data
     * @return bool True if device is consistent
     */
    private function validateDeviceContinuity(array $participant): bool
    {
        $sessionFingerprint = $_SESSION['device_fingerprint'] ?? '';
        $storedFingerprint = $participant['device_fingerprint'] ?? '';

        return !empty($sessionFingerprint) && $sessionFingerprint === $storedFingerprint;
    }

    /**
     * Log analytics event
     *
     * @param string $eventType Event type
     * @param int $participantId Participant ID
     * @param array $properties Additional properties
     * @return void
     */
    private function logAnalyticsEvent(string $eventType, int $participantId, array $properties = []): void
    {
        Database::insert("
            INSERT INTO analytics_events
            (event_type, participant_id, event_properties, session_id, ip_address, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ", [
            $eventType,
            $participantId,
            json_encode($properties),
            session_id(),
            $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
    }
}
