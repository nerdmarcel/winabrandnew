<?php

/**
 * Win a Brand New - Game Controller
 * File: /controllers/GameController.php
 *
 * Handles the complete game participation flow from landing page through completion.
 * Manages game sessions, free questions (1-3), data collection, and game state tracking
 * according to the Development Specification requirements.
 *
 * Features:
 * - Landing page with prize display and participant count
 * - Free questions handling (questions 1-3, no authentication required)
 * - User data collection (name, email, phone, WhatsApp consent)
 * - Game session management and device continuity
 * - Question selection using unique algorithm
 * - Real-time participant counting and currency detection
 * - Integration with payment flow and timer logic
 *
 * @package WinABrandNew\Controllers
 * @version 1.0.0
 * @author NerdMarcel Development Team
 */

namespace WinABrandNew\Controllers;

use WinABrandNew\Core\Database;
use WinABrandNew\Core\Security;
use WinABrandNew\Core\Config;
use WinABrandNew\Models\Game;
use WinABrandNew\Models\Round;
use WinABrandNew\Models\Participant;
use WinABrandNew\Models\Question;
use WinABrandNew\Models\ExchangeRate;
use WinABrandNew\Models\Analytics;
use WinABrandNew\Controllers\BaseController;
use WinABrandNew\Core\GeolocationService;
use WinABrandNew\Core\DeviceFingerprintService;
use Exception;

class GameController extends BaseController
{
    /**
     * Display game landing page
     * Route: GET /win-a-{slug}
     *
     * @param string $slug Game slug
     * @return void
     * @throws Exception If game not found or inactive
     */
    public function landing(string $slug): void
    {
        try {
            // Get game by slug
            $game = Game::getBySlug($slug);
            if (!$game || $game['status'] !== 'active') {
                $this->handleError('Game not found or currently unavailable', 404);
                return;
            }

            // Get current active round
            $round = Round::getCurrentRound($game['id']);
            if (!$round) {
                // Create new round if none exists
                $round = Round::create($game['id']);
            }

            // Get current participant count
            $participantCount = $round['paid_participant_count'];
            $spotsRemaining = max(0, $game['max_players'] - $participantCount);

            // Detect user currency based on IP geolocation
            $userCurrency = $this->detectUserCurrency();
            $entryFee = $this->getLocalizedEntryFee($game, $userCurrency);

            // Check for referral code
            $referralCode = $_GET['ref'] ?? null;
            $referralDiscount = 0;
            if ($referralCode) {
                $referralDiscount = $this->validateReferralCode($referralCode);
            }

            // Check for replay discount in session
            $replayDiscount = $this->checkReplayDiscount($game['id']);

            // Determine best discount (replay takes priority)
            $bestDiscount = max($replayDiscount, $referralDiscount);
            $discountType = $replayDiscount > 0 ? 'replay' : ($referralDiscount > 0 ? 'referral' : null);

            // Calculate discounted price
            $discountedFee = $entryFee * (1 - $bestDiscount / 100);

            // Track analytics event
            Analytics::trackEvent('game_landing_view', null, $round['id'], $game['id'], [
                'user_currency' => $userCurrency,
                'referral_code' => $referralCode ? 'yes' : 'no',
                'discount_available' => $bestDiscount > 0 ? 'yes' : 'no'
            ]);

            // Render landing page
            $this->render('game/landing', [
                'game' => $game,
                'round' => $round,
                'participantCount' => $participantCount,
                'spotsRemaining' => $spotsRemaining,
                'entryFee' => $entryFee,
                'discountedFee' => $discountedFee,
                'currency' => $userCurrency,
                'discount' => $bestDiscount,
                'discountType' => $discountType,
                'referralCode' => $referralCode,
                'csrf_token' => Security::generateCSRFToken()
            ]);

        } catch (Exception $e) {
            $this->logError("Game landing error: " . $e->getMessage());
            $this->handleError('Unable to load game', 500);
        }
    }

    /**
     * Start game participation (questions 1-3)
     * Route: POST /game/start
     *
     * @return void
     * @throws Exception If game start fails
     */
    public function start(): void
    {
        if (!$this->isPost()) {
            $this->jsonError('Method not allowed', 405);
            return;
        }

        if (!Security::validateCSRFToken($this->getInput('csrf_token'))) {
            $this->jsonError('Invalid CSRF token', 400);
            return;
        }

        try {
            $gameSlug = $this->getInput('game_slug');
            $referralCode = $this->getInput('referral_code');

            // Validate game
            $game = Game::getBySlug($gameSlug);
            if (!$game || $game['status'] !== 'active') {
                $this->jsonError('Game not available', 404);
                return;
            }

            // Get or create current round
            $round = Round::getCurrentRound($game['id']);
            if (!$round) {
                $round = Round::create($game['id']);
            }

            // Check if round is full
            if ($round['paid_participant_count'] >= $game['max_players']) {
                $this->jsonError('This round is currently full. Please try again.', 400);
                return;
            }

            // Generate device fingerprint
            $deviceFingerprint = DeviceFingerprintService::generateFingerprint(
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
                $_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''
            );

            // Create participant session (no payment required yet)
            $participantData = [
                'round_id' => $round['id'],
                'user_email' => '', // Will be filled during data collection
                'first_name' => '',
                'last_name' => '',
                'phone' => '',
                'payment_status' => 'pending',
                'device_fingerprint' => $deviceFingerprint,
                'ip_address' => $this->getClientIP(),
                'session_id' => session_id(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'referral_source' => $referralCode ? 'referral_link' : 'direct'
            ];

            $participantId = Participant::create($participantData);

            // Store participant ID in session for continuity
            $_SESSION['game_participant_id'] = $participantId;
            $_SESSION['game_id'] = $game['id'];
            $_SESSION['round_id'] = $round['id'];
            $_SESSION['game_start_time'] = microtime(true);

            // Get first 3 questions for this participant
            $questions = Question::getUniqueQuestionsForParticipant('', $game['id'], 3);
            if (count($questions) < 3) {
                $this->jsonError('Not enough questions available for this game', 500);
                return;
            }

            // Store questions in session
            $_SESSION['free_questions'] = array_slice($questions, 0, 3);
            $_SESSION['current_question_index'] = 0;

            // Track analytics
            Analytics::trackEvent('game_start', $participantId, $round['id'], $game['id']);

            $this->jsonSuccess([
                'participant_id' => $participantId,
                'first_question' => $this->formatQuestionForFrontend($questions[0], 1),
                'total_questions' => 9,
                'free_questions_count' => 3
            ]);

        } catch (Exception $e) {
            $this->logError("Game start error: " . $e->getMessage());
            $this->jsonError('Failed to start game', 500);
        }
    }

    /**
     * Get next question in sequence
     * Route: POST /game/question
     *
     * @return void
     */
    public function getQuestion(): void
    {
        if (!$this->isPost()) {
            $this->jsonError('Method not allowed', 405);
            return;
        }

        try {
            $participantId = $_SESSION['game_participant_id'] ?? null;
            if (!$participantId) {
                $this->jsonError('No active game session', 400);
                return;
            }

            $questionIndex = (int)$this->getInput('question_index');

            // Questions 1-3 are free (pre-payment)
            if ($questionIndex <= 3) {
                $questions = $_SESSION['free_questions'] ?? [];
                if (!isset($questions[$questionIndex - 1])) {
                    $this->jsonError('Question not found', 404);
                    return;
                }

                $question = $questions[$questionIndex - 1];
                $_SESSION['current_question_index'] = $questionIndex - 1;

            } else {
                // Questions 4-9 require payment
                $participant = Participant::getById($participantId);
                if (!$participant || $participant['payment_status'] !== 'paid') {
                    $this->jsonError('Payment required to continue', 402);
                    return;
                }

                // Get paid questions from session (loaded after payment)
                $paidQuestions = $_SESSION['paid_questions'] ?? [];
                $paidIndex = $questionIndex - 4; // Questions 4-9 map to indices 0-5

                if (!isset($paidQuestions[$paidIndex])) {
                    $this->jsonError('Question not found', 404);
                    return;
                }

                $question = $paidQuestions[$paidIndex];
            }

            // Update session tracking
            $_SESSION['current_question_index'] = $questionIndex - 1;
            $_SESSION["question_{$questionIndex}_start_time"] = microtime(true);

            $this->jsonSuccess([
                'question' => $this->formatQuestionForFrontend($question, $questionIndex),
                'question_number' => $questionIndex,
                'total_questions' => 9,
                'time_limit' => 10, // 10 seconds per question
                'is_free_question' => $questionIndex <= 3
            ]);

        } catch (Exception $e) {
            $this->logError("Get question error: " . $e->getMessage());
            $this->jsonError('Failed to load question', 500);
        }
    }

    /**
     * Submit answer for current question
     * Route: POST /game/answer
     *
     * @return void
     */
    public function submitAnswer(): void
    {
        if (!$this->isPost()) {
            $this->jsonError('Method not allowed', 405);
            return;
        }

        try {
            $participantId = $_SESSION['game_participant_id'] ?? null;
            if (!$participantId) {
                $this->jsonError('No active game session', 400);
                return;
            }

            $questionIndex = (int)$this->getInput('question_index');
            $answer = strtoupper(trim($this->getInput('answer')));
            $clientTime = (float)$this->getInput('client_time');

            // Validate answer format
            if (!in_array($answer, ['A', 'B', 'C'])) {
                $this->jsonError('Invalid answer format', 400);
                return;
            }

            // Calculate server-side timing
            $questionStartTime = $_SESSION["question_{$questionIndex}_start_time"] ?? microtime(true);
            $responseTime = microtime(true) - $questionStartTime;

            // Enforce 10-second timeout
            if ($responseTime > 10.0) {
                $this->jsonError('Time expired for this question', 408);
                return;
            }

            // Get correct answer
            $questions = $questionIndex <= 3 ?
                ($_SESSION['free_questions'] ?? []) :
                ($_SESSION['paid_questions'] ?? []);

            $currentQuestionIndex = $questionIndex <= 3 ?
                $questionIndex - 1 :
                $questionIndex - 4;

            if (!isset($questions[$currentQuestionIndex])) {
                $this->jsonError('Question not found', 404);
                return;
            }

            $question = $questions[$currentQuestionIndex];
            $correctAnswer = $question['correct_answer'];
            $isCorrect = $answer === $correctAnswer;

            // If wrong answer, game over
            if (!$isCorrect) {
                $this->handleIncorrectAnswer($participantId, $questionIndex, $answer, $responseTime);
                return;
            }

            // Store answer and timing
            $this->storeQuestionResult($participantId, $questionIndex, $answer, $responseTime, true);

            // Check if this was the last free question (question 3)
            if ($questionIndex === 3) {
                // Calculate pre-payment time (questions 1-3)
                $prePaymentTime = $this->calculatePrePaymentTime();

                // Update participant with pre-payment time
                Participant::updatePrePaymentTime($participantId, $prePaymentTime);

                $this->jsonSuccess([
                    'correct' => true,
                    'response_time' => $responseTime,
                    'next_action' => 'collect_data',
                    'message' => 'Great job! Please provide your details to continue.'
                ]);
                return;
            }

            // Check if this was the last question (question 9)
            if ($questionIndex === 9) {
                $this->handleGameCompletion($participantId);
                return;
            }

            // Continue to next question
            $this->jsonSuccess([
                'correct' => true,
                'response_time' => $responseTime,
                'next_action' => 'next_question',
                'next_question_number' => $questionIndex + 1
            ]);

        } catch (Exception $e) {
            $this->logError("Submit answer error: " . $e->getMessage());
            $this->jsonError('Failed to submit answer', 500);
        }
    }

    /**
     * Collect user data after free questions
     * Route: POST /game/submit-data
     *
     * @return void
     */
    public function submitData(): void
    {
        if (!$this->isPost()) {
            $this->jsonError('Method not allowed', 405);
            return;
        }

        if (!Security::validateCSRFToken($this->getInput('csrf_token'))) {
            $this->jsonError('Invalid CSRF token', 400);
            return;
        }

        try {
            $participantId = $_SESSION['game_participant_id'] ?? null;
            if (!$participantId) {
                $this->jsonError('No active game session', 400);
                return;
            }

            // Validate required fields
            $firstName = trim($this->getInput('first_name'));
            $lastName = trim($this->getInput('last_name'));
            $email = trim($this->getInput('email'));
            $phone = trim($this->getInput('phone'));
            $whatsappConsent = (bool)$this->getInput('whatsapp_consent', false);

            $errors = [];
            if (empty($firstName)) $errors[] = 'First name is required';
            if (empty($lastName)) $errors[] = 'Last name is required';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required';
            if (empty($phone)) $errors[] = 'Phone number is required';

            if (!empty($errors)) {
                $this->jsonError(implode(', ', $errors), 400);
                return;
            }

            // Check if email is already used in current round
            $existingParticipant = Participant::getByEmailInRound($email, $_SESSION['round_id']);
            if ($existingParticipant && $existingParticipant['id'] != $participantId) {
                $this->jsonError('This email is already registered for this round', 400);
                return;
            }

            // Update participant with user data
            $updateData = [
                'user_email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $phone,
                'whatsapp_consent' => $whatsappConsent
            ];

            Participant::update($participantId, $updateData);

            // Track analytics
            Analytics::trackEvent('user_data_collected', $participantId, $_SESSION['round_id'], $_SESSION['game_id']);

            $this->jsonSuccess([
                'message' => 'Data collected successfully',
                'next_action' => 'payment',
                'participant_id' => $participantId
            ]);

        } catch (Exception $e) {
            $this->logError("Submit data error: " . $e->getMessage());
            $this->jsonError('Failed to save user data', 500);
        }
    }

    /**
     * Continue game after payment (questions 4-9)
     * Route: GET /game/continue/{payment_id}
     *
     * @param string $paymentId Payment ID from payment provider
     * @return void
     */
    public function continueAfterPayment(string $paymentId): void
    {
        try {
            $participantId = $_SESSION['game_participant_id'] ?? null;
            if (!$participantId) {
                $this->handleError('No active game session', 400);
                return;
            }

            // Verify payment status
            $participant = Participant::getById($participantId);
            if (!$participant || $participant['payment_status'] !== 'paid') {
                $this->handleError('Payment not confirmed', 402);
                return;
            }

            // Load remaining questions (4-9) if not already loaded
            if (!isset($_SESSION['paid_questions'])) {
                $userEmail = $participant['user_email'];
                $gameId = $_SESSION['game_id'];

                // Get 6 more unique questions for paid portion
                $paidQuestions = Question::getUniqueQuestionsForParticipant($userEmail, $gameId, 6, 3);
                if (count($paidQuestions) < 6) {
                    $this->handleError('Insufficient questions available', 500);
                    return;
                }

                $_SESSION['paid_questions'] = $paidQuestions;
            }

            // Resume timer for paid questions (questions 4-9)
            $_SESSION['paid_questions_start_time'] = microtime(true);

            // Render continue page with first paid question
            $firstPaidQuestion = $_SESSION['paid_questions'][0];

            $this->render('game/continue', [
                'participant' => $participant,
                'payment_id' => $paymentId,
                'first_question' => $this->formatQuestionForFrontend($firstPaidQuestion, 4),
                'questions_completed' => 3,
                'questions_remaining' => 6,
                'csrf_token' => Security::generateCSRFToken()
            ]);

        } catch (Exception $e) {
            $this->logError("Continue after payment error: " . $e->getMessage());
            $this->handleError('Unable to continue game', 500);
        }
    }

    /**
     * Handle incorrect answer (game over)
     *
     * @param int $participantId Participant ID
     * @param int $questionIndex Question number
     * @param string $answer Submitted answer
     * @param float $responseTime Response time
     * @return void
     */
    private function handleIncorrectAnswer(int $participantId, int $questionIndex, string $answer, float $responseTime): void
    {
        try {
            // Store the incorrect answer
            $this->storeQuestionResult($participantId, $questionIndex, $answer, $responseTime, false);

            // Mark participant as completed (but not winner)
            Participant::update($participantId, [
                'game_completed' => 1,
                'total_time_all_questions' => null // No completion time for incorrect answers
            ]);

            // Track analytics
            Analytics::trackEvent('game_over_incorrect', $participantId, $_SESSION['round_id'], $_SESSION['game_id'], [
                'question_number' => $questionIndex,
                'user_answer' => $answer
            ]);

            // Clear session
            $this->clearGameSession();

            $this->jsonError('Incorrect answer. Game over!', 200, [
                'game_over' => true,
                'reason' => 'incorrect_answer',
                'question_number' => $questionIndex,
                'correct_answer' => $this->getCorrectAnswer($questionIndex)
            ]);

        } catch (Exception $e) {
            $this->logError("Handle incorrect answer error: " . $e->getMessage());
            $this->jsonError('Game ended', 500);
        }
    }

    /**
     * Handle game completion (all 9 questions correct)
     *
     * @param int $participantId Participant ID
     * @return void
     */
    private function handleGameCompletion(int $participantId): void
    {
        try {
            // Calculate total completion time
            $gameStartTime = $_SESSION['game_start_time'];
            $totalTime = microtime(true) - $gameStartTime;

            // Calculate post-payment time (questions 4-9)
            $paidStartTime = $_SESSION['paid_questions_start_time'];
            $postPaymentTime = microtime(true) - $paidStartTime;

            // Update participant with completion data
            Participant::update($participantId, [
                'game_completed' => 1,
                'total_time_all_questions' => $totalTime,
                'post_payment_time' => $postPaymentTime,
                'correct_answers' => 9
            ]);

            // Check if this participant is the winner
            $round = Round::getById($_SESSION['round_id']);
            $isWinner = $this->checkForWinner($participantId, $round);

            // Track analytics
            Analytics::trackEvent('game_complete', $participantId, $_SESSION['round_id'], $_SESSION['game_id'], [
                'total_time' => $totalTime,
                'is_winner' => $isWinner
            ]);

            // Clear session
            $this->clearGameSession();

            $this->jsonSuccess([
                'game_completed' => true,
                'total_time' => $totalTime,
                'is_winner' => $isWinner,
                'completion_message' => $isWinner ?
                    'Congratulations! You won!' :
                    'Well done! You completed all questions correctly.',
                'next_action' => 'show_results'
            ]);

        } catch (Exception $e) {
            $this->logError("Handle game completion error: " . $e->getMessage());
            $this->jsonError('Game completion failed', 500);
        }
    }

    /**
     * Store question result
     *
     * @param int $participantId Participant ID
     * @param int $questionIndex Question number
     * @param string $answer Submitted answer
     * @param float $responseTime Response time
     * @param bool $isCorrect Whether answer is correct
     * @return void
     */
    private function storeQuestionResult(int $participantId, int $questionIndex, string $answer, float $responseTime, bool $isCorrect): void
    {
        // Get current answers from participant
        $participant = Participant::getById($participantId);
        $answers = json_decode($participant['answers_json'] ?? '[]', true);
        $questionTimes = json_decode($participant['question_times_json'] ?? '[]', true);

        // Add this answer and time
        $answers[$questionIndex] = $answer;
        $questionTimes[$questionIndex] = $responseTime;

        // Update participant
        Participant::update($participantId, [
            'answers_json' => json_encode($answers),
            'question_times_json' => json_encode($questionTimes),
            'correct_answers' => $isCorrect ? ($participant['correct_answers'] + 1) : $participant['correct_answers']
        ]);
    }

    /**
     * Calculate pre-payment time (questions 1-3)
     *
     * @return float Pre-payment time in seconds
     */
    private function calculatePrePaymentTime(): float
    {
        $gameStartTime = $_SESSION['game_start_time'];
        return microtime(true) - $gameStartTime;
    }

    /**
     * Check if participant is winner
     *
     * @param int $participantId Participant ID
     * @param array $round Round data
     * @return bool True if participant is winner
     */
    private function checkForWinner(int $participantId, array $round): bool
    {
        try {
            $participant = Participant::getById($participantId);

            // Check if round is now full (1000 paid participants)
            $currentPaidCount = Participant::getPaidCountInRound($round['id']);

            if ($currentPaidCount >= $round['game']['max_players']) {
                // Round is full, determine winner
                $winner = Participant::getFastestInRound($round['id']);

                if ($winner && $winner['id'] == $participantId) {
                    // This participant is the winner!
                    Participant::update($participantId, ['is_winner' => 1]);
                    Round::update($round['id'], [
                        'status' => 'completed',
                        'winner_participant_id' => $participantId,
                        'completed_at' => date('Y-m-d H:i:s')
                    ]);

                    return true;
                }
            }

            return false;

        } catch (Exception $e) {
            $this->logError("Check winner error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Format question for frontend display
     *
     * @param array $question Question data
     * @param int $questionNumber Question number (1-9)
     * @return array Formatted question
     */
    private function formatQuestionForFrontend(array $question, int $questionNumber): array
    {
        return [
            'id' => $question['id'],
            'number' => $questionNumber,
            'text' => $question['question_text'],
            'options' => [
                'A' => $question['option_a'],
                'B' => $question['option_b'],
                'C' => $question['option_c']
            ],
            'image_url' => $question['image_url'] ?? null,
            'difficulty' => $question['difficulty_level']
        ];
    }

    /**
     * Get correct answer for a question
     *
     * @param int $questionIndex Question number
     * @return string Correct answer
     */
    private function getCorrectAnswer(int $questionIndex): string
    {
        $questions = $questionIndex <= 3 ?
            ($_SESSION['free_questions'] ?? []) :
            ($_SESSION['paid_questions'] ?? []);

        $currentQuestionIndex = $questionIndex <= 3 ?
            $questionIndex - 1 :
            $questionIndex - 4;

        return $questions[$currentQuestionIndex]['correct_answer'] ?? 'A';
    }

    /**
     * Detect user currency based on IP geolocation
     *
     * @return string Currency code (GBP, EUR, USD, etc.)
     */
    private function detectUserCurrency(): string
    {
        try {
            $ipAddress = $this->getClientIP();
            $geolocation = GeolocationService::getLocationByIP($ipAddress);

            $currencyMap = [
                'GB' => 'GBP',
                'US' => 'USD',
                'CA' => 'CAD',
                'AU' => 'AUD',
                'DE' => 'EUR',
                'FR' => 'EUR',
                'ES' => 'EUR',
                'IT' => 'EUR',
                'NL' => 'EUR'
            ];

            return $currencyMap[$geolocation['country_code']] ?? Config::get('DEFAULT_CURRENCY', 'GBP');

        } catch (Exception $e) {
            return Config::get('DEFAULT_CURRENCY', 'GBP');
        }
    }

    /**
     * Get localized entry fee for game
     *
     * @param array $game Game data
     * @param string $currency Target currency
     * @return float Entry fee in target currency
     */
    private function getLocalizedEntryFee(array $game, string $currency): float
    {
        $feeColumn = 'entry_fee_' . strtolower($currency);

        if (isset($game[$feeColumn]) && $game[$feeColumn] > 0) {
            return (float)$game[$feeColumn];
        }

        // Fallback to conversion from base currency
        return ExchangeRate::convert($game['entry_fee'], $game['currency'], $currency);
    }

    /**
     * Validate referral code
     *
     * @param string $referralCode Base64 encoded email
     * @return float Discount percentage (0-100)
     */
    private function validateReferralCode(string $referralCode): float
    {
        try {
            $email = base64_decode($referralCode);

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return 0;
            }

            // Check if referral is valid (not self-referral)
            $currentIP = $this->getClientIP();
            $deviceFingerprint = DeviceFingerprintService::generateFingerprint(
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
                $_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''
            );

            // Prevent self-referral based on IP and device fingerprint
            $recentParticipant = Participant::getRecentByEmailAndDevice($email, $currentIP, $deviceFingerprint);
            if ($recentParticipant) {
                return 0; // Self-referral prevention
            }

            // Store referral in session for later processing
            $_SESSION['referral_email'] = $email;
            $_SESSION['referral_code'] = $referralCode;

            return 10; // 10% referral discount

        } catch (Exception $e) {
            $this->logError("Referral validation error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Check for replay discount in session
     *
     * @param int $gameId Game ID
     * @return float Discount percentage (0-100)
     */
    private function checkReplayDiscount(int $gameId): float
    {
        try {
            $replayDiscount = $_SESSION["replay_discount_game_{$gameId}"] ?? null;

            if ($replayDiscount && isset($replayDiscount['expires_at'])) {
                if (time() <= $replayDiscount['expires_at']) {
                    return (float)$replayDiscount['percentage'];
                } else {
                    // Discount expired, remove from session
                    unset($_SESSION["replay_discount_game_{$gameId}"]);
                }
            }

            return 0;

        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Clear game session data
     *
     * @return void
     */
    private function clearGameSession(): void
    {
        $keysToRemove = [
            'game_participant_id',
            'game_id',
            'round_id',
            'game_start_time',
            'free_questions',
            'paid_questions',
            'current_question_index',
            'paid_questions_start_time'
        ];

        foreach ($keysToRemove as $key) {
            unset($_SESSION[$key]);
        }

        // Also remove question start times
        for ($i = 1; $i <= 9; $i++) {
            unset($_SESSION["question_{$i}_start_time"]);
        }
    }

    /**
     * Get client IP address
     *
     * @return string Client IP address
     */
    private function getClientIP(): string
    {
        $ipKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];

        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Handle comma-separated IPs (from proxies)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }

                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Log error message
     *
     * @param string $message Error message
     * @return void
     */
    private function logError(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] GameController Error: {$message}" . PHP_EOL;

        $logFile = ($_ENV['LOG_PATH'] ?? '/var/log/winabrandnew') . '/app.log';

        if (is_writable(dirname($logFile))) {
            file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
        }

        error_log("GameController: {$message}");
    }

    /**
     * Handle error response
     *
     * @param string $message Error message
     * @param int $code HTTP status code
     * @return void
     */
    private function handleError(string $message, int $code = 500): void
    {
        http_response_code($code);

        if ($this->isAjax()) {
            header('Content-Type: application/json');
            echo json_encode(['error' => $message, 'code' => $code]);
        } else {
            $this->render('errors/error', [
                'message' => $message,
                'code' => $code
            ]);
        }
    }

    /**
     * Send JSON success response
     *
     * @param array $data Response data
     * @return void
     */
    private function jsonSuccess(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode(array_merge(['success' => true], $data));
    }

    /**
     * Send JSON error response
     *
     * @param string $message Error message
     * @param int $code HTTP status code
     * @param array $data Additional data
     * @return void
     */
    private function jsonError(string $message, int $code = 400, array $data = []): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(array_merge([
            'success' => false,
            'error' => $message,
            'code' => $code
        ], $data));
    }

    /**
     * Check if request is AJAX
     *
     * @return bool True if AJAX request
     */
    private function isAjax(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    /**
     * Check if request method is POST
     *
     * @return bool True if POST request
     */
    private function isPost(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST';
    }

    /**
     * Get input value from request
     *
     * @param string $key Input key
     * @param mixed $default Default value
     * @return mixed Input value
     */
    private function getInput(string $key, $default = null)
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    /**
     * Render view template
     *
     * @param string $template Template path
     * @param array $data Template data
     * @return void
     */
    private function render(string $template, array $data = []): void
    {
        // Extract data for template
        extract($data);

        // Include template file
        $templateFile = __DIR__ . "/../views/{$template}.php";
        if (file_exists($templateFile)) {
            include $templateFile;
        } else {
            throw new Exception("Template not found: {$template}");
        }
    }

    /**
     * Fraud detection checks for participants
     *
     * @param int $participantId Participant ID
     * @param float $responseTime Response time for analysis
     * @return void
     */
    private function performFraudChecks(int $participantId, float $responseTime): void
    {
        try {
            $fraudScore = 0;
            $fraudFlags = [];

            // Check response time (too fast might indicate bot)
            $minAnswerTime = (float)Config::get('FRAUD_MIN_ANSWER_TIME', 0.5);
            if ($responseTime < $minAnswerTime) {
                $fraudScore += 0.3;
                $fraudFlags[] = 'response_too_fast';
            }

            // Check daily participation limit
            $maxDaily = (int)Config::get('FRAUD_MAX_DAILY_PARTICIPATIONS', 5);
            $todayCount = Participant::getDailyParticipationCount($this->getClientIP());
            if ($todayCount > $maxDaily) {
                $fraudScore += 0.4;
                $fraudFlags[] = 'excessive_daily_participation';
            }

            // Check device fingerprint patterns
            $deviceFingerprint = $_SESSION['device_fingerprint'] ?? '';
            $deviceCount = Participant::getDeviceFingerprintCount($deviceFingerprint);
            if ($deviceCount > 10) {
                $fraudScore += 0.2;
                $fraudFlags[] = 'device_overuse';
            }

            // Update participant fraud score
            if ($fraudScore > 0) {
                Participant::update($participantId, [
                    'fraud_score' => $fraudScore,
                    'fraud_flags' => json_encode($fraudFlags),
                    'is_fraudulent' => $fraudScore >= 0.7 ? 1 : 0
                ]);
            }

        } catch (Exception $e) {
            $this->logError("Fraud check error: " . $e->getMessage());
        }
    }

    /**
     * Auto-advance to next round if current round is full
     *
     * @param int $gameId Game ID
     * @return array|null New round data if created
     */
    private function autoAdvanceRound(int $gameId): ?array
    {
        try {
            $game = Game::getById($gameId);
            if (!$game || !$game['auto_restart']) {
                return null;
            }

            $currentRound = Round::getCurrentRound($gameId);
            if (!$currentRound) {
                return null;
            }

            // Check if current round is full
            $paidCount = Participant::getPaidCountInRound($currentRound['id']);
            if ($paidCount >= $game['max_players']) {
                // Create new round
                $newRound = Round::create($gameId);

                // Track analytics
                Analytics::trackEvent('round_auto_created', null, $newRound['id'], $gameId, [
                    'previous_round_id' => $currentRound['id'],
                    'trigger' => 'auto_restart'
                ]);

                return $newRound;
            }

            return null;

        } catch (Exception $e) {
            $this->logError("Auto-advance round error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get game statistics for display
     *
     * @param int $gameId Game ID
     * @return array Game statistics
     */
    public function getGameStats(int $gameId): array
    {
        try {
            $game = Game::getById($gameId);
            $currentRound = Round::getCurrentRound($gameId);

            $stats = [
                'game' => $game,
                'current_round' => $currentRound,
                'participants_count' => 0,
                'spots_remaining' => $game['max_players'],
                'completion_rate' => 0,
                'average_time' => 0
            ];

            if ($currentRound) {
                $stats['participants_count'] = $currentRound['paid_participant_count'];
                $stats['spots_remaining'] = max(0, $game['max_players'] - $currentRound['paid_participant_count']);

                // Get completion statistics
                $completionStats = Participant::getCompletionStatsForRound($currentRound['id']);
                $stats['completion_rate'] = $completionStats['completion_rate'];
                $stats['average_time'] = $completionStats['average_time'];
            }

            return $stats;

        } catch (Exception $e) {
            $this->logError("Get game stats error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Health check endpoint for monitoring
     * Route: GET /game/health
     *
     * @return void
     */
    public function healthCheck(): void
    {
        try {
            $health = [
                'status' => 'healthy',
                'timestamp' => date('c'),
                'checks' => [
                    'database' => Database::healthCheck(),
                    'session' => session_status() === PHP_SESSION_ACTIVE,
                    'memory_usage' => memory_get_usage(true),
                    'peak_memory' => memory_get_peak_usage(true)
                ]
            ];

            // Check if any critical systems are down
            if ($health['checks']['database']['status'] !== 'healthy') {
                $health['status'] = 'unhealthy';
                http_response_code(503);
            }

            header('Content-Type: application/json');
            echo json_encode($health);

        } catch (Exception $e) {
            http_response_code(503);
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'unhealthy',
                'error' => 'Health check failed',
                'timestamp' => date('c')
            ]);
        }
    }
}
