<?php
declare(strict_types=1);

/**
 * File: core/TimingManager.php
 * Location: core/TimingManager.php
 *
 * WinABN Core Timing Logic
 *
 * Handles microsecond-precision timing for game sessions, including start/pause/resume
 * functionality, timeout enforcement, and total completion time calculation.
 *
 * @package WinABN\Core
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Core;

use WinABN\Core\Database;
use WinABN\Core\Session;
use Exception;

class TimingManager
{
    /**
     * Session key for timing data
     *
     * @var string
     */
    private const SESSION_TIMING_KEY = 'winabn_timing';

    /**
     * Timing state constants
     *
     * @var string
     */
    public const STATE_NOT_STARTED = 'not_started';
    public const STATE_RUNNING = 'running';
    public const STATE_PAUSED = 'paused';
    public const STATE_COMPLETED = 'completed';
    public const STATE_TIMEOUT = 'timeout';

    /**
     * Start timing for a participant
     *
     * @param int $participantId Participant ID
     * @param int $questionNumber Starting question number
     * @return bool Success
     * @throws Exception
     */
    public static function startTiming(int $participantId, int $questionNumber = 1): bool
    {
        $session = new Session();

        $timingData = [
            'participant_id' => $participantId,
            'state' => self::STATE_RUNNING,
            'session_start_time' => microtime(true),
            'current_question' => $questionNumber,
            'question_start_time' => microtime(true),
            'question_times' => [],
            'pause_time' => null,
            'paused_duration' => 0,
            'timeout_warnings' => 0,
            'device_continuity_hash' => self::generateDeviceContinuityHash()
        ];

        $session->set(self::SESSION_TIMING_KEY, $timingData);

        app_log('info', 'Timing started', [
            'participant_id' => $participantId,
            'question_number' => $questionNumber,
            'start_time' => $timingData['session_start_time']
        ]);

        return true;
    }

    /**
     * Record question completion and start next question
     *
     * @param int $participantId Participant ID
     * @param int $questionNumber Completed question number
     * @param string $answer Answer given
     * @param bool $isCorrect Whether answer is correct
     * @return array<string, mixed> Timing results
     * @throws Exception
     */
    public static function completeQuestion(int $participantId, int $questionNumber, string $answer, bool $isCorrect): array
    {
        $session = new Session();
        $timingData = $session->get(self::SESSION_TIMING_KEY);

        if (!$timingData || $timingData['participant_id'] !== $participantId) {
            throw new Exception('No active timing session found');
        }

        if ($timingData['state'] !== self::STATE_RUNNING) {
            throw new Exception('Timing session is not active');
        }

        // Verify device continuity
        if (!self::verifyDeviceContinuity($timingData['device_continuity_hash'])) {
            throw new Exception('Device continuity check failed');
        }

        $currentTime = microtime(true);

        // Calculate question completion time
        $questionTime = $currentTime - $timingData['question_start_time'] - $timingData['paused_duration'];

        // Validate minimum time (fraud detection)
        $minTime = env('FRAUD_MIN_ANSWER_TIME', 0.5);
        if ($questionTime < $minTime) {
            app_log('warning', 'Suspiciously fast answer detected', [
                'participant_id' => $participantId,
                'question_number' => $questionNumber,
                'time_taken' => $questionTime,
                'minimum_expected' => $minTime
            ]);

            throw new Exception('Answer submitted too quickly');
        }

        // Check for timeout
        $game = self::getGameConfig($participantId);
        $timeoutSeconds = $game['question_timeout'] ?? 10;

        if ($questionTime > $timeoutSeconds) {
            self::handleTimeout($participantId, $questionNumber);
            throw new Exception('Question timeout exceeded');
        }

        // Record question time
        $timingData['question_times'][$questionNumber] = $questionTime;

        // Determine next question
        $nextQuestion = $questionNumber + 1;
        $isGameComplete = false;

        // Check if game is complete
        if ($nextQuestion > ($game['total_questions'] ?? 9)) {
            $isGameComplete = true;
            $timingData['state'] = self::STATE_COMPLETED;
            $timingData['completion_time'] = $currentTime;
        } else {
            // Start timing for next question
            $timingData['current_question'] = $nextQuestion;
            $timingData['question_start_time'] = $currentTime;
            $timingData['paused_duration'] = 0; // Reset for next question
        }

        $session->set(self::SESSION_TIMING_KEY, $timingData);

        // Calculate total times
        $totalTime = self::calculateTotalTime($timingData['question_times']);
        $prePaymentTime = self::calculatePrePaymentTime($timingData['question_times'], $game['free_questions'] ?? 3);
        $postPaymentTime = $totalTime - $prePaymentTime;

        $result = [
            'question_time' => $questionTime,
            'total_time' => $totalTime,
            'pre_payment_time' => $prePaymentTime,
            'post_payment_time' => $postPaymentTime,
            'is_game_complete' => $isGameComplete,
            'next_question' => $nextQuestion,
            'questions_completed' => count($timingData['question_times'])
        ];

        app_log('info', 'Question completed', [
            'participant_id' => $participantId,
            'question_number' => $questionNumber,
            'time_taken' => $questionTime,
            'is_correct' => $isCorrect,
            'is_game_complete' => $isGameComplete
        ]);

        return $result;
    }

    /**
     * Pause timing (during payment process)
     *
     * @param int $participantId Participant ID
     * @return bool Success
     * @throws Exception
     */
    public static function pauseTiming(int $participantId): bool
    {
        $session = new Session();
        $timingData = $session->get(self::SESSION_TIMING_KEY);

        if (!$timingData || $timingData['participant_id'] !== $participantId) {
            throw new Exception('No active timing session found');
        }

        if ($timingData['state'] !== self::STATE_RUNNING) {
            throw new Exception('Timing session is not active');
        }

        $timingData['state'] = self::STATE_PAUSED;
        $timingData['pause_time'] = microtime(true);

        $session->set(self::SESSION_TIMING_KEY, $timingData);

        app_log('info', 'Timing paused', [
            'participant_id' => $participantId,
            'pause_time' => $timingData['pause_time']
        ]);

        return true;
    }

    /**
     * Resume timing (after payment completion)
     *
     * @param int $participantId Participant ID
     * @return bool Success
     * @throws Exception
     */
    public static function resumeTiming(int $participantId): bool
    {
        $session = new Session();
        $timingData = $session->get(self::SESSION_TIMING_KEY);

        if (!$timingData || $timingData['participant_id'] !== $participantId) {
            throw new Exception('No active timing session found');
        }

        if ($timingData['state'] !== self::STATE_PAUSED) {
            throw new Exception('Timing session is not paused');
        }

        // Verify device continuity
        if (!self::verifyDeviceContinuity($timingData['device_continuity_hash'])) {
            throw new Exception('Device continuity check failed - game must be completed on same device');
        }

        $currentTime = microtime(true);

        // Add paused duration to total
        if ($timingData['pause_time']) {
            $pausedDuration = $currentTime - $timingData['pause_time'];
            $timingData['paused_duration'] += $pausedDuration;
        }

        $timingData['state'] = self::STATE_RUNNING;
        $timingData['question_start_time'] = $currentTime; // Reset question start time
        $timingData['pause_time'] = null;

        $session->set(self::SESSION_TIMING_KEY, $timingData);

        app_log('info', 'Timing resumed', [
            'participant_id' => $participantId,
            'resume_time' => $currentTime,
            'paused_duration' => $pausedDuration ?? 0
        ]);

        return true;
    }

    /**
     * Get current timing status
     *
     * @param int $participantId Participant ID
     * @return array<string, mixed>|null Timing status
     */
    public static function getTimingStatus(int $participantId): ?array
    {
        $session = new Session();
        $timingData = $session->get(self::SESSION_TIMING_KEY);

        if (!$timingData || $timingData['participant_id'] !== $participantId) {
            return null;
        }

        $currentTime = microtime(true);
        $status = [
            'state' => $timingData['state'],
            'current_question' => $timingData['current_question'],
            'questions_completed' => count($timingData['question_times']),
            'session_duration' => $currentTime - $timingData['session_start_time'],
            'question_times' => $timingData['question_times']
        ];

        if ($timingData['state'] === self::STATE_RUNNING) {
            $questionElapsed = $currentTime - $timingData['question_start_time'] - $timingData['paused_duration'];
            $status['current_question_elapsed'] = $questionElapsed;

            // Check for approaching timeout
            $game = self::getGameConfig($participantId);
            $timeoutSeconds = $game['question_timeout'] ?? 10;
            $status['time_remaining'] = max(0, $timeoutSeconds - $questionElapsed);
            $status['is_timeout_warning'] = $questionElapsed > ($timeoutSeconds * 0.8); // 80% warning
        }

        return $status;
    }

    /**
     * Check for timeout and handle if necessary
     *
     * @param int $participantId Participant ID
     * @return bool True if timeout occurred
     */
    public static function checkTimeout(int $participantId): bool
    {
        $status = self::getTimingStatus($participantId);

        if (!$status || $status['state'] !== self::STATE_RUNNING) {
            return false;
        }

        $game = self::getGameConfig($participantId);
        $timeoutSeconds = $game['question_timeout'] ?? 10;

        if ($status['current_question_elapsed'] > $timeoutSeconds) {
            self::handleTimeout($participantId, $status['current_question']);
            return true;
        }

        return false;
    }

    /**
     * Handle question timeout
     *
     * @param int $participantId Participant ID
     * @param int $questionNumber Question that timed out
     * @return void
     */
    public static function handleTimeout(int $participantId, int $questionNumber): void
    {
        $session = new Session();
        $timingData = $session->get(self::SESSION_TIMING_KEY);

        if ($timingData) {
            $timingData['state'] = self::STATE_TIMEOUT;
            $session->set(self::SESSION_TIMING_KEY, $timingData);
        }

        // Update participant status in database
        $query = "UPDATE participants SET game_status = 'failed', completed_at = NOW() WHERE id = ?";
        Database::execute($query, [$participantId]);

        app_log('info', 'Question timeout occurred', [
            'participant_id' => $participantId,
            'question_number' => $questionNumber,
            'timeout_time' => microtime(true)
        ]);
    }

    /**
     * Finalize timing and save to database
     *
     * @param int $participantId Participant ID
     * @return array<string, mixed> Final timing results
     * @throws Exception
     */
    public static function finalizeTiming(int $participantId): array
    {
        $session = new Session();
        $timingData = $session->get(self::SESSION_TIMING_KEY);

        if (!$timingData || $timingData['participant_id'] !== $participantId) {
            throw new Exception('No active timing session found');
        }

        $game = self::getGameConfig($participantId);
        $freeQuestions = $game['free_questions'] ?? 3;

        // Calculate final times
        $totalTime = self::calculateTotalTime($timingData['question_times']);
        $prePaymentTime = self::calculatePrePaymentTime($timingData['question_times'], $freeQuestions);
        $postPaymentTime = $totalTime - $prePaymentTime;

        // Save to database
        $query = "
            UPDATE participants
            SET total_time_all_questions = ?,
                pre_payment_time = ?,
                post_payment_time = ?,
                question_times_json = ?,
                game_status = 'completed',
                completed_at = NOW()
            WHERE id = ?
        ";

        Database::execute($query, [
            $totalTime,
            $prePaymentTime,
            $postPaymentTime,
            json_encode($timingData['question_times']),
            $participantId
        ]);

        // Clear session timing data
        $session->remove(self::SESSION_TIMING_KEY);

        $result = [
            'total_time' => $totalTime,
            'pre_payment_time' => $prePaymentTime,
            'post_payment_time' => $postPaymentTime,
            'question_times' => $timingData['question_times'],
            'questions_completed' => count($timingData['question_times'])
        ];

        app_log('info', 'Timing finalized', [
            'participant_id' => $participantId,
            'total_time' => $totalTime,
            'questions_completed' => count($timingData['question_times'])
        ]);

        return $result;
    }

    /**
     * Clean up abandoned timing sessions
     *
     * @return int Number of sessions cleaned up
     */
    public static function cleanupAbandonedSessions(): int
    {
        // This would typically be called by a cron job
        $cutoffTime = time() - (30 * 60); // 30 minutes ago
        $cleanedUp = 0;

        // Mark participants as abandoned if they haven't completed and session is old
        $query = "
            UPDATE participants
            SET game_status = 'abandoned'
            WHERE game_status = 'in_progress'
                AND started_at < FROM_UNIXTIME(?)
                AND completed_at IS NULL
        ";

        $result = Database::execute($query, [$cutoffTime]);
        $cleanedUp = $result->rowCount();

        if ($cleanedUp > 0) {
            app_log('info', 'Cleaned up abandoned timing sessions', [
                'count' => $cleanedUp,
                'cutoff_time' => date('Y-m-d H:i:s', $cutoffTime)
            ]);
        }

        return $cleanedUp;
    }

    /**
     * Calculate total completion time from question times
     *
     * @param array<int, float> $questionTimes Array of question times
     * @return float Total time in seconds
     */
    private static function calculateTotalTime(array $questionTimes): float
    {
        return array_sum($questionTimes);
    }

    /**
     * Calculate pre-payment time (questions 1-3)
     *
     * @param array<int, float> $questionTimes Array of question times
     * @param int $freeQuestions Number of free questions
     * @return float Pre-payment time in seconds
     */
    private static function calculatePrePaymentTime(array $questionTimes, int $freeQuestions): float
    {
        $prePaymentTime = 0;

        for ($i = 1; $i <= $freeQuestions; $i++) {
            if (isset($questionTimes[$i])) {
                $prePaymentTime += $questionTimes[$i];
            }
        }

        return $prePaymentTime;
    }

    /**
     * Get game configuration for participant
     *
     * @param int $participantId Participant ID
     * @return array<string, mixed> Game configuration
     */
    private static function getGameConfig(int $participantId): array
    {
        static $cache = [];

        if (isset($cache[$participantId])) {
            return $cache[$participantId];
        }

        $query = "
            SELECT g.* FROM games g
            JOIN rounds r ON g.id = r.game_id
            JOIN participants p ON r.id = p.round_id
            WHERE p.id = ?
        ";

        $game = Database::fetchOne($query, [$participantId]);

        if ($game) {
            $cache[$participantId] = $game;
        }

        return $game ?: [];
    }

    /**
     * Generate device continuity hash
     *
     * @return string Device hash
     */
    private static function generateDeviceContinuityHash(): string
    {
        $components = [
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        ];

        return hash('sha256', implode('|', $components));
    }

    /**
     * Verify device continuity
     *
     * @param string $expectedHash Expected device hash
     * @return bool Whether device matches
     */
    private static function verifyDeviceContinuity(string $expectedHash): bool
    {
        $currentHash = self::generateDeviceContinuityHash();
        return hash_equals($expectedHash, $currentHash);
    }
}
