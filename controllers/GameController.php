<?php
declare(strict_types=1);

/**
 * File: controllers/GameController.php
 * Location: controllers/GameController.php
 *
 * WinABN Game Controller
 *
 * Handles all game flow logic including landing pages, question management,
 * timing accuracy, and winner selection with microsecond precision.
 *
 * @package WinABN\Controllers
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Controllers;

use WinABN\Core\{Controller, Database, View, Security, Session};
use WinABN\Models\{Game, Round, Participant, Question};
use Exception;

class GameController extends Controller
{
    /**
     * Show game landing page
     *
     * @param string $slug Game slug
     * @return string
     */
    public function showLandingPage(string $slug): string
    {
        try {
            // Get game by slug
            $game = Database::fetchOne("
                SELECT * FROM games
                WHERE slug = ? AND status = 'active'
            ", [$slug]);

            if (!$game) {
                http_response_code(404);
                return $this->view->render('errors/404', [
                    'title' => 'Game Not Found'
                ]);
            }

            // Get current active round
            $currentRound = Database::fetchOne("
                SELECT r.*,
                       (r.paid_participant_count / g.max_players * 100) as fill_percentage
                FROM rounds r
                JOIN games g ON r.game_id = g.id
                WHERE r.game_id = ? AND r.status = 'active'
                ORDER BY r.id DESC
                LIMIT 1
            ", [$game['id']]);

            // If no active round, create one
            if (!$currentRound) {
                $roundId = Database::execute("
                    INSERT INTO rounds (game_id, status, started_at)
                    VALUES (?, 'active', NOW())
                ", [$game['id']]);

                $currentRound = [
                    'id' => Database::lastInsertId(),
                    'game_id' => $game['id'],
                    'status' => 'active',
                    'paid_participant_count' => 0,
                    'fill_percentage' => 0
                ];
            }

            // Get recent winners for this game
            $recentWinners = Database::fetchAll("
                SELECT p.first_name, p.last_name, r.completed_at,
                       p.total_time_all_questions
                FROM participants p
                JOIN rounds r ON p.round_id = r.id
                WHERE r.game_id = ? AND p.is_winner = 1
                ORDER BY r.completed_at DESC
                LIMIT 5
            ", [$game['id']]);

            // Check for discount availability
            $discountInfo = $this->checkAvailableDiscounts();

            // Prepare currency display
            $userCurrency = $this->detectUserCurrency();
            $displayPrice = $this->convertCurrency($game['entry_fee'], 'GBP', $userCurrency);

            return $this->view->render('game/landing', [
                'title' => $game['name'] . ' - WinABN',
                'meta_description' => "Win a {$game['name']}! Answer {$game['total_questions']} questions correctly and fast to win this amazing prize worth £{$game['prize_value']}.",
                'og_title' => $game['name'],
                'og_description' => "Answer {$game['total_questions']} quick questions and win {$game['name']}!",
                'og_image' => url("assets/images/prizes/{$game['slug']}.jpg"),
                'game' => $game,
                'current_round' => $currentRound,
                'recent_winners' => $recentWinners,
                'user_currency' => $userCurrency,
                'display_price' => $displayPrice,
                'discount_info' => $discountInfo,
                'csrf_token' => csrf_token(),
                'is_mobile' => $this->isMobileDevice()
            ]);

        } catch (Exception $e) {
            app_log('error', 'Landing page error: ' . $e->getMessage(), [
                'slug' => $slug,
                'error' => $e->getMessage()
            ]);

            return $this->view->render('errors/500', [
                'title' => 'Something went wrong'
            ]);
        }
    }

    /**
     * Start new game session
     *
     * @param array $data Request data
     * @return never
     */
    public function startGame(array $data): never
    {
        try {
            $gameId = (int) ($data['game_id'] ?? 0);

            // Validate game
            $game = Database::fetchOne("
                SELECT * FROM games
                WHERE id = ? AND status = 'active'
            ", [$gameId]);

            if (!$game) {
                json_response(['error' => 'Invalid game'], 400);
            }

            // Get or create active round
            $round = $this->getOrCreateActiveRound($gameId);

            // Generate session ID and device fingerprint
            $sessionId = 'sess_' . unique_id();
            $deviceFingerprint = $data['device_fingerprint'] ?? 'fp_' . unique_id();

            // Check for existing participant with same email in current round
            $existingParticipant = Database::fetchOne("
                SELECT id FROM participants
                WHERE round_id = ? AND user_email = ?
            ", [$round['id'], $data['email'] ?? '']);

            if ($existingParticipant) {
                json_response(['error' => 'You have already started this round'], 400);
            }

            // Create participant record
            $participantId = Database::execute("
                INSERT INTO participants (
                    round_id, user_email, session_id, device_fingerprint,
                    ip_address, user_agent, game_status, current_question,
                    started_at, payment_currency, payment_amount
                ) VALUES (?, ?, ?, ?, ?, ?, 'not_started', 1, NOW(), ?, ?)
            ", [
                $round['id'],
                $data['email'] ?? '',
                $sessionId,
                $deviceFingerprint,
                client_ip(),
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                $this->detectUserCurrency(),
                $this->convertCurrency($game['entry_fee'], 'GBP', $this->detectUserCurrency())
            ]);

            $participantId = Database::lastInsertId();

            // Store in session
            session()->set('game_session_id', $sessionId);
            session()->set('participant_id', $participantId);
            session()->set('round_id', $round['id']);
            session()->set('game_id', $gameId);
            session()->set('timing_start', microtime(true));

            // Log analytics event
            $this->logAnalyticsEvent('game_start', $participantId, $round['id'], $gameId);

            json_response([
                'success' => true,
                'redirect_url' => url('/game.php'),
                'session_id' => $sessionId
            ]);

        } catch (Exception $e) {
            app_log('error', 'Start game error: ' . $e->getMessage(), [
                'data' => $data,
                'error' => $e->getMessage()
            ]);

            json_response(['error' => 'Failed to start game'], 500);
        }
    }

    /**
     * Submit user data after free questions
     *
     * @param array $data Request data
     * @return never
     */
    public function submitUserData(array $data): never
    {
        try {
            $participantId = session()->get('participant_id');

            if (!$participantId) {
                json_response(['error' => 'Invalid session'], 400);
            }

            // Validate required fields
            $requiredFields = ['first_name', 'last_name', 'email', 'phone'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    json_response(['error' => "Field '{$field}' is required"], 400);
                }
            }

            // Sanitize and validate input
            $firstName = Security::sanitizeInput($data['first_name']);
            $lastName = Security::sanitizeInput($data['last_name']);
            $email = filter_var($data['email'], FILTER_VALIDATE_EMAIL);
            $phone = Security::sanitizePhone($data['phone']);
            $whatsappConsent = isset($data['whatsapp_consent']) ? 1 : 0;

            if (!$email) {
                json_response(['error' => 'Invalid email address'], 400);
            }

            if (!$phone) {
                json_response(['error' => 'Invalid phone number'], 400);
            }

            // Calculate pre-payment time (questions 1-3)
            $timingStart = session()->get('timing_start');
            $prePaymentTime = microtime(true) - $timingStart;

            // Update participant with user data
            Database::execute("
                UPDATE participants SET
                    first_name = ?, last_name = ?, user_email = ?,
                    phone = ?, whatsapp_consent = ?, pre_payment_time = ?,
                    current_question = 4, game_status = 'in_progress'
                WHERE id = ?
            ", [
                $firstName, $lastName, $email, $phone,
                $whatsappConsent, $prePaymentTime, $participantId
            ]);

            // Store timing pause point
            session()->set('pre_payment_time', $prePaymentTime);
            session()->set('payment_start_time', microtime(true));

            json_response([
                'success' => true,
                'redirect_url' => url('/pay.php'),
                'pre_payment_time' => $prePaymentTime
            ]);

        } catch (Exception $e) {
            app_log('error', 'Submit user data error: ' . $e->getMessage(), [
                'participant_id' => $participantId ?? null,
                'error' => $e->getMessage()
            ]);

            json_response(['error' => 'Failed to save user data'], 500);
        }
    }

    /**
     * Continue game after payment confirmation
     *
     * @param string $paymentId Payment ID
     * @return string
     */
    public function continueAfterPayment(string $paymentId): string
    {
        try {
            $participantId = session()->get('participant_id');

            if (!$participantId) {
                redirect(url('/'));
            }

            // Verify payment status
            $participant = Database::fetchOne("
                SELECT p.*, g.slug
                FROM participants p
                JOIN rounds r ON p.round_id = r.id
                JOIN games g ON r.game_id = g.id
                WHERE p.id = ? AND p.payment_reference = ?
            ", [$participantId, $paymentId]);

            if (!$participant) {
                redirect(url('/'));
            }

            if ($participant['payment_status'] !== 'paid') {
                redirect(url('/pay.php?status=pending'));
            }

            // Resume timing for questions 4-9
            session()->set('post_payment_start', microtime(true));

            // Redirect to game interface
            redirect(url('/game.php'));

        } catch (Exception $e) {
            app_log('error', 'Continue after payment error: ' . $e->getMessage(), [
                'payment_id' => $paymentId,
                'participant_id' => $participantId ?? null
            ]);

            redirect(url('/?error=payment_error'));
        }
    }

    /**
     * Get current question for participant
     *
     * @param int $participantId Participant ID
     * @return array|null
     */
    public function getCurrentQuestion(int $participantId): ?array
    {
        try {
            $participant = Database::fetchOne("
                SELECT p.*, r.game_id, p.current_question
                FROM participants p
                JOIN rounds r ON p.round_id = r.id
                WHERE p.id = ?
            ", [$participantId]);

            if (!$participant) {
                return null;
            }

            // Get user's question history for this game
            $seenQuestions = Database::fetchAll("
                SELECT question_id
                FROM participant_question_history
                WHERE user_email = ? AND game_id = ?
            ", [$participant['user_email'], $participant['game_id']]);

            $seenQuestionIds = array_column($seenQuestions, 'question_id');

            // Get available questions (not seen by this user)
            $whereClause = "WHERE game_id = ? AND is_active = 1";
            $params = [$participant['game_id']];

            if (!empty($seenQuestionIds)) {
                $placeholders = str_repeat('?,', count($seenQuestionIds) - 1) . '?';
                $whereClause .= " AND id NOT IN ($placeholders)";
                $params = array_merge($params, $seenQuestionIds);
            }

            $availableQuestions = Database::fetchAll("
                SELECT * FROM questions
                $whereClause
                ORDER BY RAND()
                LIMIT 1
            ", $params);

            // If no unseen questions, select from all questions
            if (empty($availableQuestions)) {
                $availableQuestions = Database::fetchAll("
                    SELECT * FROM questions
                    WHERE game_id = ? AND is_active = 1
                    ORDER BY RAND()
                    LIMIT 1
                ", [$participant['game_id']]);
            }

            if (empty($availableQuestions)) {
                throw new Exception('No questions available for this game');
            }

            $question = $availableQuestions[0];

            // Record question as seen
            Database::execute("
                INSERT IGNORE INTO participant_question_history
                (user_email, game_id, question_id, participant_id, seen_at)
                VALUES (?, ?, ?, ?, NOW())
            ", [
                $participant['user_email'],
                $participant['game_id'],
                $question['id'],
                $participantId
            ]);

            // Store question start time in session
            session()->set('question_start_time', microtime(true));
            session()->set('current_question_id', $question['id']);

            return $question;

        } catch (Exception $e) {
            app_log('error', 'Get current question error: ' . $e->getMessage(), [
                'participant_id' => $participantId
            ]);

            return null;
        }
    }

    /**
     * Submit answer to current question
     *
     * @param array $data Request data
     * @return never
     */
    public function submitAnswer(array $data): never
    {
        try {
            $participantId = session()->get('participant_id');
            $questionStartTime = session()->get('question_start_time');
            $currentQuestionId = session()->get('current_question_id');

            if (!$participantId || !$questionStartTime || !$currentQuestionId) {
                json_response(['error' => 'Invalid session'], 400);
            }

            $answer = strtoupper($data['answer'] ?? '');
            $questionEndTime = microtime(true);
            $questionTime = $questionEndTime - $questionStartTime;

            // Get question and participant data
            $question = Database::fetchOne("
                SELECT * FROM questions WHERE id = ?
            ", [$currentQuestionId]);

            $participant = Database::fetchOne("
                SELECT p.*, g.question_timeout, g.total_questions, r.game_id
                FROM participants p
                JOIN rounds r ON p.round_id = r.id
                JOIN games g ON r.game_id = g.id
                WHERE p.id = ?
            ", [$participantId]);

            if (!$question || !$participant) {
                json_response(['error' => 'Invalid question or participant'], 400);
            }

            // Check timeout (server-side enforcement)
            if ($questionTime > $participant['question_timeout']) {
                $this->handleGameFailure($participantId, 'timeout');
                json_response([
                    'success' => false,
                    'result' => 'timeout',
                    'message' => 'Time\'s up! You took too long to answer.',
                    'redirect_url' => url('/win-a-' . $participant['slug'] . '?failed=timeout')
                ]);
            }

            // Check if answer is correct
            $isCorrect = ($answer === $question['correct_answer']);

            if (!$isCorrect) {
                $this->handleGameFailure($participantId, 'wrong_answer');
                json_response([
                    'success' => false,
                    'result' => 'wrong',
                    'correct_answer' => $question['correct_answer'],
                    'message' => 'Sorry, that\'s incorrect!',
                    'redirect_url' => url('/win-a-' . $participant['slug'] . '?failed=wrong')
                ]);
            }

            // Update question history with result
            Database::execute("
                UPDATE participant_question_history SET
                    answer_given = ?, is_correct = ?, time_taken = ?
                WHERE user_email = ? AND game_id = ? AND question_id = ?
            ", [
                $answer, $isCorrect ? 1 : 0, $questionTime,
                $participant['user_email'], $participant['game_id'], $currentQuestionId
            ]);

            // Store question time
            $questionTimes = json_decode($participant['question_times_json'] ?? '[]', true);
            $questionTimes[] = $questionTime;

            $nextQuestion = $participant['current_question'] + 1;
            $questionsCompleted = $participant['questions_completed'] + 1;

            // Check if game is complete
            if ($nextQuestion > $participant['total_questions']) {
                $this->completeGame($participantId, $questionTimes);

                json_response([
                    'success' => true,
                    'result' => 'complete',
                    'message' => 'Congratulations! You completed all questions!',
                    'redirect_url' => url('/game/complete')
                ]);
            } else {
                // Update participant progress
                Database::execute("
                    UPDATE participants SET
                        current_question = ?, questions_completed = ?,
                        question_times_json = ?
                    WHERE id = ?
                ", [$nextQuestion, $questionsCompleted, json_encode($questionTimes), $participantId]);

                json_response([
                    'success' => true,
                    'result' => 'correct',
                    'next_question' => $nextQuestion,
                    'total_questions' => $participant['total_questions'],
                    'question_time' => round($questionTime, 3),
                    'message' => 'Correct! Moving to next question...'
                ]);
            }

        } catch (Exception $e) {
            app_log('error', 'Submit answer error: ' . $e->getMessage(), [
                'participant_id' => $participantId ?? null,
                'data' => $data
            ]);

            json_response(['error' => 'Failed to submit answer'], 500);
        }
    }

    /**
     * Complete game and calculate total time
     *
     * @param int $participantId Participant ID
     * @param array $questionTimes Array of question times
     * @return void
     */
    private function completeGame(int $participantId, array $questionTimes): void
    {
        try {
            Database::beginTransaction();

            $participant = Database::fetchOne("
                SELECT p.*, r.id as round_id, r.game_id, r.paid_participant_count, g.max_players
                FROM participants p
                JOIN rounds r ON p.round_id = r.id
                JOIN games g ON r.game_id = g.id
                WHERE p.id = ? AND p.payment_status = 'paid'
            ", [$participantId]);

            if (!$participant) {
                throw new Exception('Invalid participant for completion');
            }

            // Calculate total completion time
            $totalTime = array_sum($questionTimes);
            $prePaymentTime = (float) $participant['pre_payment_time'];
            $postPaymentTime = $totalTime - $prePaymentTime;

            // Update participant completion
            Database::execute("
                UPDATE participants SET
                    game_status = 'completed', completed_at = NOW(),
                    total_time_all_questions = ?, post_payment_time = ?,
                    question_times_json = ?
                WHERE id = ?
            ", [$totalTime, $postPaymentTime, json_encode($questionTimes), $participantId]);

            // Check if round is now full
            $newCount = $participant['paid_participant_count'];
            if ($newCount >= $participant['max_players']) {
                $this->completeRound($participant['round_id']);
            }

            // Log completion event
            $this->logAnalyticsEvent('game_completed', $participantId, $participant['round_id'], $participant['game_id']);

            Database::commit();

        } catch (Exception $e) {
            Database::rollback();
            throw $e;
        }
    }

    /**
     * Handle game failure (timeout or wrong answer)
     *
     * @param int $participantId Participant ID
     * @param string $reason Failure reason
     * @return void
     */
    private function handleGameFailure(int $participantId, string $reason): void
    {
        Database::execute("
            UPDATE participants SET
                game_status = 'failed', completed_at = NOW()
            WHERE id = ?
        ", [$participantId]);

        // Clear session
        session()->forget(['game_session_id', 'participant_id', 'timing_start']);
    }

    /**
     * Show game completion page
     *
     * @return string
     */
    public function showCompletion(): string
    {
        $participantId = session()->get('participant_id');

        if (!$participantId) {
            redirect(url('/'));
        }

        $participant = Database::fetchOne("
            SELECT p.*, r.status as round_status, g.name as game_name, g.slug,
                   r.winner_participant_id, r.completed_at as round_completed_at
            FROM participants p
            JOIN rounds r ON p.round_id = r.id
            JOIN games g ON r.game_id = g.id
            WHERE p.id = ?
        ", [$participantId]);

        if (!$participant) {
            redirect(url('/'));
        }

        $isWinner = ($participant['winner_participant_id'] == $participantId);

        return $this->view->render('game/completion', [
            'title' => 'Game Complete - ' . $participant['game_name'],
            'participant' => $participant,
            'is_winner' => $isWinner,
            'total_time' => $participant['total_time_all_questions'],
            'round_status' => $participant['round_status']
        ]);
    }

    // Helper methods...

    private function getOrCreateActiveRound(int $gameId): array
    {
        // Implementation for getting or creating active round
        // (Implementation details omitted for brevity)
        return [];
    }

    private function detectUserCurrency(): string
    {
        // Implementation for detecting user currency based on IP
        return 'GBP';
    }

    private function convertCurrency(float $amount, string $from, string $to): float
    {
        // Implementation for currency conversion
        return $amount;
    }

    private function checkAvailableDiscounts(): array
    {
        // Implementation for checking available discounts
        return [];
    }

    private function isMobileDevice(): bool
    {
        return preg_match('/Mobile|Android|iPhone|iPad/', $_SERVER['HTTP_USER_AGENT'] ?? '');
    }

    private function completeRound(int $roundId): void
    {
        // Implementation for completing round and selecting winner
    }

    private function logAnalyticsEvent(string $type, int $participantId, int $roundId, int $gameId): void
    {
        // Implementation for logging analytics events
    }
}
