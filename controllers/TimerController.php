<?php
/**
 * TimerController.php - Precise timing management and network interruption handling
 *
 * Implements microsecond precision timing, server-side timer enforcement,
 * pause/resume during payment, and connection failure recovery according to
 * the Development Specification.
 *
 * Path: /controllers/TimerController.php
 */

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Security.php';
require_once __DIR__ . '/../models/Participant.php';
require_once __DIR__ . '/../models/Question.php';
require_once __DIR__ . '/BaseController.php';

class TimerController extends BaseController
{
    private $db;
    private $security;
    private $participantModel;
    private $questionModel;

    // Timer constants from specification
    const QUESTION_TIMEOUT_SECONDS = 10;
    const MICROSECOND_PRECISION = true;
    const TIMER_SESSION_KEY = 'game_timer_data';

    public function __construct()
    {
        parent::__construct();
        $this->db = Database::getInstance();
        $this->security = new Security();
        $this->participantModel = new Participant();
        $this->questionModel = new Question();
    }

    /**
     * Start timer for Question 1 (beginning of game)
     * Timer starts when Question 1 is displayed, not before
     */
    public function startGameTimer($participantId, $roundId)
    {
        try {
            // Validate participant and round
            $participant = $this->participantModel->getById($participantId);
            if (!$participant || $participant['round_id'] != $roundId) {
                throw new Exception('Invalid participant or round');
            }

            // Check if timer already started
            if (!empty($participant['timer_started_at'])) {
                return $this->jsonResponse([
                    'success' => false,
                    'error' => 'Timer already started'
                ]);
            }

            // Record precise start time (microsecond precision)
            $startTime = microtime(true);

            // Store timer data in session for recovery
            $timerData = [
                'participant_id' => $participantId,
                'round_id' => $roundId,
                'timer_started_at' => $startTime,
                'current_question' => 1,
                'questions_completed' => 0,
                'is_paused' => false,
                'pause_time' => null,
                'total_pause_duration' => 0,
                'device_fingerprint' => $this->generateDeviceFingerprint()
            ];

            $_SESSION[self::TIMER_SESSION_KEY] = $timerData;

            // Update participant in database
            $this->participantModel->update($participantId, [
                'timer_started_at' => date('Y-m-d H:i:s', $startTime),
                'timer_started_microtime' => $startTime,
                'status' => 'in_progress'
            ]);

            return $this->jsonResponse([
                'success' => true,
                'timer_started_at' => $startTime,
                'server_time' => microtime(true),
                'question_timeout' => self::QUESTION_TIMEOUT_SECONDS
            ]);

        } catch (Exception $e) {
            $this->logError('Timer start failed: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'error' => 'Failed to start timer'
            ]);
        }
    }

    /**
     * Pause timer after Question 3 (before payment)
     * Timer pauses during payment process and resumes when Question 4 starts
     */
    public function pauseTimer($participantId)
    {
        try {
            $timerData = $_SESSION[self::TIMER_SESSION_KEY] ?? null;

            if (!$timerData || $timerData['participant_id'] != $participantId) {
                throw new Exception('Invalid timer session');
            }

            if ($timerData['is_paused']) {
                return $this->jsonResponse([
                    'success' => false,
                    'error' => 'Timer already paused'
                ]);
            }

            // Record pause time with microsecond precision
            $pauseTime = microtime(true);
            $timerData['is_paused'] = true;
            $timerData['pause_time'] = $pauseTime;

            $_SESSION[self::TIMER_SESSION_KEY] = $timerData;

            // Calculate pre-payment time (Questions 1-3)
            $prePaymentTime = $pauseTime - $timerData['timer_started_at'] - $timerData['total_pause_duration'];

            // Update participant with pre-payment timing
            $this->participantModel->update($participantId, [
                'pre_payment_time' => $prePaymentTime,
                'timer_paused_at' => date('Y-m-d H:i:s', $pauseTime),
                'status' => 'payment_pending'
            ]);

            return $this->jsonResponse([
                'success' => true,
                'paused_at' => $pauseTime,
                'pre_payment_time' => $prePaymentTime,
                'server_time' => microtime(true)
            ]);

        } catch (Exception $e) {
            $this->logError('Timer pause failed: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'error' => 'Failed to pause timer'
            ]);
        }
    }

    /**
     * Resume timer when Question 4 is displayed (after payment confirmation)
     * Timer resumes only after payment is confirmed
     */
    public function resumeTimer($participantId)
    {
        try {
            $timerData = $_SESSION[self::TIMER_SESSION_KEY] ?? null;

            if (!$timerData || $timerData['participant_id'] != $participantId) {
                throw new Exception('Invalid timer session');
            }

            if (!$timerData['is_paused']) {
                return $this->jsonResponse([
                    'success' => false,
                    'error' => 'Timer is not paused'
                ]);
            }

            // Verify payment status before resuming
            $participant = $this->participantModel->getById($participantId);
            if ($participant['payment_status'] !== 'paid') {
                return $this->jsonResponse([
                    'success' => false,
                    'error' => 'Payment not confirmed'
                ]);
            }

            // Record resume time with microsecond precision
            $resumeTime = microtime(true);
            $pauseDuration = $resumeTime - $timerData['pause_time'];

            $timerData['is_paused'] = false;
            $timerData['total_pause_duration'] += $pauseDuration;
            $timerData['current_question'] = 4; // Resume at Question 4
            unset($timerData['pause_time']);

            $_SESSION[self::TIMER_SESSION_KEY] = $timerData;

            // Update participant
            $this->participantModel->update($participantId, [
                'timer_resumed_at' => date('Y-m-d H:i:s', $resumeTime),
                'total_pause_duration' => $timerData['total_pause_duration'],
                'status' => 'in_progress'
            ]);

            return $this->jsonResponse([
                'success' => true,
                'resumed_at' => $resumeTime,
                'total_pause_duration' => $timerData['total_pause_duration'],
                'current_question' => 4,
                'server_time' => microtime(true)
            ]);

        } catch (Exception $e) {
            $this->logError('Timer resume failed: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'error' => 'Failed to resume timer'
            ]);
        }
    }

    /**
     * Record question completion time with microsecond precision
     * Enforces 10-second timeout server-side
     */
    public function recordQuestionTime($participantId, $questionNumber, $answerSubmittedAt = null)
    {
        try {
            $timerData = $_SESSION[self::TIMER_SESSION_KEY] ?? null;

            if (!$timerData || $timerData['participant_id'] != $participantId) {
                throw new Exception('Invalid timer session');
            }

            // Verify device continuity
            if (!$this->verifyDeviceContinuity($timerData['device_fingerprint'])) {
                throw new Exception('Device mismatch - game must be completed on same device');
            }

            $currentTime = $answerSubmittedAt ?? microtime(true);

            // Calculate question start time
            $questionStartTime = $this->calculateQuestionStartTime($timerData, $questionNumber);

            // Enforce 10-second timeout server-side
            $questionDuration = $currentTime - $questionStartTime;
            $timeoutExceeded = $questionDuration > self::QUESTION_TIMEOUT_SECONDS;

            if ($timeoutExceeded) {
                // Auto-fail question if timeout exceeded
                return $this->handleQuestionTimeout($participantId, $questionNumber, $questionDuration);
            }

            // Store question timing
            $questionTimes = $this->getStoredQuestionTimes($participantId);
            $questionTimes["question_{$questionNumber}"] = [
                'duration' => $questionDuration,
                'started_at' => $questionStartTime,
                'completed_at' => $currentTime,
                'timeout_exceeded' => false
            ];

            // Update session
            $timerData['current_question'] = $questionNumber + 1;
            $timerData['questions_completed'] = $questionNumber;
            $_SESSION[self::TIMER_SESSION_KEY] = $timerData;

            // Update database
            $this->participantModel->update($participantId, [
                'question_times_json' => json_encode($questionTimes),
                'last_question_completed' => $questionNumber,
                'last_activity_at' => date('Y-m-d H:i:s', $currentTime)
            ]);

            return $this->jsonResponse([
                'success' => true,
                'question_duration' => $questionDuration,
                'timeout_exceeded' => false,
                'next_question' => $questionNumber + 1,
                'server_time' => microtime(true)
            ]);

        } catch (Exception $e) {
            $this->logError('Question timing failed: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Complete game timer and calculate total times
     * Called when all 9 questions are completed
     */
    public function completeGameTimer($participantId)
    {
        try {
            $timerData = $_SESSION[self::TIMER_SESSION_KEY] ?? null;

            if (!$timerData || $timerData['participant_id'] != $participantId) {
                throw new Exception('Invalid timer session');
            }

            $completionTime = microtime(true);

            // Calculate total completion time for all 9 questions
            $totalGameTime = $completionTime - $timerData['timer_started_at'] - $timerData['total_pause_duration'];

            // Get all question times
            $questionTimes = $this->getStoredQuestionTimes($participantId);

            // Calculate pre-payment time (Questions 1-3) and post-payment time (Questions 4-9)
            $prePaymentTime = $this->calculatePrePaymentTime($questionTimes);
            $postPaymentTime = $this->calculatePostPaymentTime($questionTimes);

            // Update participant with final timings
            $this->participantModel->update($participantId, [
                'total_time_all_questions' => $totalGameTime,
                'pre_payment_time' => $prePaymentTime,
                'post_payment_time' => $postPaymentTime,
                'game_completed_at' => date('Y-m-d H:i:s', $completionTime),
                'status' => 'completed'
            ]);

            // Clear timer session
            unset($_SESSION[self::TIMER_SESSION_KEY]);

            // Check if this completes the round and trigger winner selection
            $this->checkRoundCompletion($timerData['round_id']);

            return $this->jsonResponse([
                'success' => true,
                'total_time_all_questions' => $totalGameTime,
                'pre_payment_time' => $prePaymentTime,
                'post_payment_time' => $postPaymentTime,
                'completion_time' => $completionTime,
                'server_time' => microtime(true)
            ]);

        } catch (Exception $e) {
            $this->logError('Game completion failed: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'error' => 'Failed to complete game timer'
            ]);
        }
    }

    /**
     * Handle connection failure recovery
     * Checks if user reconnects after timeout and handles accordingly
     */
    public function checkConnectionRecovery($participantId)
    {
        try {
            $timerData = $_SESSION[self::TIMER_SESSION_KEY] ?? null;

            if (!$timerData || $timerData['participant_id'] != $participantId) {
                return $this->jsonResponse([
                    'success' => false,
                    'error' => 'No active timer session',
                    'action' => 'restart_required'
                ]);
            }

            // Verify device continuity
            if (!$this->verifyDeviceContinuity($timerData['device_fingerprint'])) {
                return $this->jsonResponse([
                    'success' => false,
                    'error' => 'Device mismatch detected',
                    'action' => 'game_over'
                ]);
            }

            $currentTime = microtime(true);
            $currentQuestion = $timerData['current_question'];

            // Check if current question has timed out
            $questionStartTime = $this->calculateQuestionStartTime($timerData, $currentQuestion);
            $timeSinceQuestionStart = $currentTime - $questionStartTime;

            if ($timeSinceQuestionStart > self::QUESTION_TIMEOUT_SECONDS) {
                // Question timed out - game over
                return $this->handleQuestionTimeout($participantId, $currentQuestion, $timeSinceQuestionStart);
            }

            // Recovery successful - return current timer state
            return $this->jsonResponse([
                'success' => true,
                'current_question' => $currentQuestion,
                'time_remaining' => max(0, self::QUESTION_TIMEOUT_SECONDS - $timeSinceQuestionStart),
                'is_paused' => $timerData['is_paused'],
                'server_time' => microtime(true)
            ]);

        } catch (Exception $e) {
            $this->logError('Connection recovery failed: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'error' => 'Recovery failed',
                'action' => 'restart_required'
            ]);
        }
    }

    /**
     * Get current timer status for frontend synchronization
     */
    public function getTimerStatus($participantId)
    {
        try {
            $timerData = $_SESSION[self::TIMER_SESSION_KEY] ?? null;

            if (!$timerData || $timerData['participant_id'] != $participantId) {
                return $this->jsonResponse([
                    'success' => false,
                    'error' => 'No active timer session'
                ]);
            }

            $currentTime = microtime(true);
            $currentQuestion = $timerData['current_question'];

            if ($timerData['is_paused']) {
                return $this->jsonResponse([
                    'success' => true,
                    'status' => 'paused',
                    'current_question' => $currentQuestion,
                    'paused_at' => $timerData['pause_time'],
                    'server_time' => $currentTime
                ]);
            }

            // Calculate time remaining for current question
            $questionStartTime = $this->calculateQuestionStartTime($timerData, $currentQuestion);
            $timeSinceQuestionStart = $currentTime - $questionStartTime;
            $timeRemaining = max(0, self::QUESTION_TIMEOUT_SECONDS - $timeSinceQuestionStart);

            return $this->jsonResponse([
                'success' => true,
                'status' => 'active',
                'current_question' => $currentQuestion,
                'time_remaining' => $timeRemaining,
                'question_start_time' => $questionStartTime,
                'server_time' => $currentTime
            ]);

        } catch (Exception $e) {
            $this->logError('Timer status check failed: ' . $e->getMessage());
            return $this->jsonResponse([
                'success' => false,
                'error' => 'Failed to get timer status'
            ]);
        }
    }

    // Private helper methods

    private function handleQuestionTimeout($participantId, $questionNumber, $duration)
    {
        // Mark question as failed due to timeout
        $questionTimes = $this->getStoredQuestionTimes($participantId);
        $questionTimes["question_{$questionNumber}"] = [
            'duration' => $duration,
            'timeout_exceeded' => true,
            'failed_at' => microtime(true)
        ];

        // Game over - update participant status
        $this->participantModel->update($participantId, [
            'question_times_json' => json_encode($questionTimes),
            'status' => 'failed',
            'failure_reason' => 'question_timeout',
            'failed_at' => date('Y-m-d H:i:s')
        ]);

        // Clear timer session
        unset($_SESSION[self::TIMER_SESSION_KEY]);

        return $this->jsonResponse([
            'success' => false,
            'error' => 'Question timeout exceeded',
            'action' => 'game_over',
            'question_number' => $questionNumber,
            'duration' => $duration
        ]);
    }

    private function calculateQuestionStartTime($timerData, $questionNumber)
    {
        $startTime = $timerData['timer_started_at'];

        // Add time for previously completed questions
        $questionTimes = $this->getStoredQuestionTimes($timerData['participant_id']);
        $previousQuestionsTime = 0;

        for ($i = 1; $i < $questionNumber; $i++) {
            if (isset($questionTimes["question_{$i}"])) {
                $previousQuestionsTime += $questionTimes["question_{$i}"]['duration'];
            }
        }

        // Account for pause duration if question is after payment
        $pauseAdjustment = ($questionNumber > 3) ? $timerData['total_pause_duration'] : 0;

        return $startTime + $previousQuestionsTime + $pauseAdjustment;
    }

    private function getStoredQuestionTimes($participantId)
    {
        $participant = $this->participantModel->getById($participantId);
        return json_decode($participant['question_times_json'] ?? '{}', true);
    }

    private function calculatePrePaymentTime($questionTimes)
    {
        $prePaymentTime = 0;
        for ($i = 1; $i <= 3; $i++) {
            if (isset($questionTimes["question_{$i}"])) {
                $prePaymentTime += $questionTimes["question_{$i}"]['duration'];
            }
        }
        return $prePaymentTime;
    }

    private function calculatePostPaymentTime($questionTimes)
    {
        $postPaymentTime = 0;
        for ($i = 4; $i <= 9; $i++) {
            if (isset($questionTimes["question_{$i}"])) {
                $postPaymentTime += $questionTimes["question_{$i}"]['duration'];
            }
        }
        return $postPaymentTime;
    }

    private function verifyDeviceContinuity($originalFingerprint)
    {
        $currentFingerprint = $this->generateDeviceFingerprint();
        return $currentFingerprint === $originalFingerprint;
    }

    private function generateDeviceFingerprint()
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $acceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        $acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        return hash('sha256', $userAgent . $acceptLanguage . $acceptEncoding . $ip);
    }

    private function checkRoundCompletion($roundId)
    {
        try {
            // Check if round is now complete and trigger winner selection
            $sql = "SELECT COUNT(*) as paid_count, g.max_players
                   FROM participants p
                   JOIN rounds r ON p.round_id = r.id
                   JOIN games g ON r.game_id = g.id
                   WHERE p.round_id = ? AND p.payment_status = 'paid' AND p.status = 'completed'";

            $result = $this->db->fetchOne($sql, [$roundId]);

            if ($result['paid_count'] >= $result['max_players']) {
                // Trigger winner selection (delegate to WinnerController)
                require_once __DIR__ . '/WinnerController.php';
                $winnerController = new WinnerController();
                $winnerController->selectWinner($roundId);
            }

        } catch (Exception $e) {
            $this->logError('Round completion check failed: ' . $e->getMessage());
        }
    }

    private function logError($message)
    {
        error_log('[TimerController] ' . $message);
    }
}
?>
