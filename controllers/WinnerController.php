<?php
/**
 * File: /controllers/WinnerController.php
 * Winner Selection Controller for "Win a Brand New" Application
 *
 * Handles winner determination, round completion, timing calculations,
 * and winner notification triggering according to Development Specification.
 *
 * Core Functionality:
 * - Fastest time calculation with microsecond precision
 * - Tie-breaking logic using participant_id
 * - Round completion processing
 * - Winner notification triggering
 * - Concurrency control for winner selection
 *
 * @package WinABrandNew
 * @version 1.0
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../models/Round.php';
require_once __DIR__ . '/../models/Participant.php';
require_once __DIR__ . '/../models/Game.php';
require_once __DIR__ . '/../models/EmailQueue.php';
require_once __DIR__ . '/../models/WhatsAppQueue.php';
require_once __DIR__ . '/../models/Analytics.php';
require_once __DIR__ . '/../core/Database.php';

class WinnerController extends BaseController
{
    private $db;
    private $roundModel;
    private $participantModel;
    private $gameModel;
    private $emailQueue;
    private $whatsappQueue;
    private $analytics;

    public function __construct()
    {
        parent::__construct();
        $this->db = Database::getInstance();
        $this->roundModel = new Round();
        $this->participantModel = new Participant();
        $this->gameModel = new Game();
        $this->emailQueue = new EmailQueue();
        $this->whatsappQueue = new WhatsAppQueue();
        $this->analytics = new Analytics();
    }

    /**
     * Process round completion and determine winner
     * Called when a round reaches max_players paid participants
     *
     * @param int $roundId The round ID to process
     * @return array Result with winner information
     */
    public function processRoundCompletion($roundId)
    {
        try {
            // Start transaction for atomic winner selection
            $this->db->beginTransaction();

            // Lock the round to prevent concurrent processing
            $round = $this->roundModel->lockRoundForWinnerSelection($roundId);

            if (!$round) {
                $this->db->rollback();
                return $this->errorResponse('Round not found or already processed');
            }

            // Verify round is ready for completion
            if ($round['status'] !== 'active') {
                $this->db->rollback();
                return $this->errorResponse('Round is not in active status');
            }

            // Get all paid participants with complete games
            $eligibleParticipants = $this->getEligibleParticipants($roundId);

            if (empty($eligibleParticipants)) {
                $this->db->rollback();
                return $this->errorResponse('No eligible participants found');
            }

            // Determine winner using fastest total completion time
            $winner = $this->determineWinner($eligibleParticipants);

            if (!$winner) {
                $this->db->rollback();
                return $this->errorResponse('Unable to determine winner');
            }

            // Update round with winner
            $this->roundModel->completeRound($roundId, $winner['participant_id']);

            // Log analytics event
            $this->analytics->logEvent('round_completed', $winner['participant_id'], $roundId, [
                'total_participants' => count($eligibleParticipants),
                'winning_time' => $winner['total_time_all_questions'],
                'prize_value' => $round['prize_value']
            ]);

            // Queue winner notification
            $this->queueWinnerNotification($winner, $round);

            // Queue non-winner notifications
            $this->queueNonWinnerNotifications($eligibleParticipants, $winner, $round);

            // Auto-start new round if enabled
            if ($round['auto_restart']) {
                $this->autoStartNewRound($round['game_id']);
            }

            $this->db->commit();

            return $this->successResponse([
                'round_id' => $roundId,
                'winner' => $winner,
                'total_participants' => count($eligibleParticipants),
                'completion_time' => microtime(true)
            ]);

        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Winner selection error for round {$roundId}: " . $e->getMessage());
            return $this->errorResponse('Winner selection failed: ' . $e->getMessage());
        }
    }

    /**
     * Get all participants eligible for winning
     * Only paid participants with completed games qualify
     *
     * @param int $roundId The round ID
     * @return array Array of eligible participants
     */
    private function getEligibleParticipants($roundId)
    {
        $sql = "SELECT p.*, u.first_name, u.last_name, u.user_email, u.phone, u.whatsapp_consent
                FROM participants p
                WHERE p.round_id = :round_id
                AND p.payment_status = 'paid'
                AND p.total_time_all_questions IS NOT NULL
                AND p.total_time_all_questions > 0
                ORDER BY p.total_time_all_questions ASC, p.participant_id ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':round_id', $roundId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Determine winner using timing logic from specification
     * Winner = fastest total completion time for all 9 questions
     * Tie breaker = lowest participant_id (first to achieve that time)
     *
     * @param array $participants Array of eligible participants
     * @return array|null Winner participant data
     */
    private function determineWinner($participants)
    {
        if (empty($participants)) {
            return null;
        }

        // Find the fastest time (already sorted by total_time_all_questions ASC, participant_id ASC)
        $winner = $participants[0];

        // Validate winner has valid timing data
        if (!$this->validateWinnerTiming($winner)) {
            error_log("Winner validation failed for participant {$winner['participant_id']}");
            return null;
        }

        return $winner;
    }

    /**
     * Validate winner timing data for fraud detection
     *
     * @param array $winner Winner participant data
     * @return bool True if timing is valid
     */
    private function validateWinnerTiming($winner)
    {
        // Check for minimum realistic completion time (prevent fraud)
        $minTotalTime = 9 * 0.5; // Minimum 0.5 seconds per question
        if ($winner['total_time_all_questions'] < $minTotalTime) {
            return false;
        }

        // Check for maximum realistic completion time
        $maxTotalTime = 9 * 10; // Maximum 10 seconds per question
        if ($winner['total_time_all_questions'] > $maxTotalTime) {
            return false;
        }

        // Validate timing breakdown if available
        if ($winner['question_times_json']) {
            $questionTimes = json_decode($winner['question_times_json'], true);
            if (is_array($questionTimes) && count($questionTimes) === 9) {
                $sumOfQuestions = array_sum($questionTimes);
                $tolerance = 0.1; // 100ms tolerance for rounding

                if (abs($sumOfQuestions - $winner['total_time_all_questions']) > $tolerance) {
                    error_log("Timing mismatch: sum={$sumOfQuestions}, total={$winner['total_time_all_questions']}");
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Queue winner notification via email and WhatsApp
     *
     * @param array $winner Winner participant data
     * @param array $round Round data
     */
    private function queueWinnerNotification($winner, $round)
    {
        // Generate secure claim token
        $claimToken = $this->generateSecureClaimToken();

        // Store claim token in database
        $this->storeClaimToken($winner['participant_id'], $claimToken);

        // Queue winner email
        $this->emailQueue->queueEmail([
            'to_email' => $winner['user_email'],
            'subject' => "🎉 Congratulations! You've won {$round['prize_name']}!",
            'template' => 'winner_notification',
            'variables' => [
                'first_name' => $winner['first_name'],
                'prize_name' => $round['prize_name'],
                'prize_value' => $round['prize_value'],
                'currency' => $round['currency'],
                'completion_time' => number_format($winner['total_time_all_questions'], 3),
                'claim_link' => $this->generateClaimLink($claimToken),
                'round_id' => $round['round_id']
            ],
            'priority' => 1 // Highest priority
        ]);

        // Queue WhatsApp notification if opted in
        if ($winner['whatsapp_consent'] && !empty($winner['phone'])) {
            $this->whatsappQueue->queueMessage([
                'to_phone' => $winner['phone'],
                'template' => 'winner_notification',
                'variables' => [
                    'first_name' => $winner['first_name'],
                    'prize_name' => $round['prize_name'],
                    'claim_link' => $this->generateClaimLink($claimToken)
                ],
                'priority' => 1
            ]);
        }
    }

    /**
     * Queue non-winner notifications with replay offers
     *
     * @param array $participants All participants
     * @param array $winner Winner data
     * @param array $round Round data
     */
    private function queueNonWinnerNotifications($participants, $winner, $round)
    {
        foreach ($participants as $participant) {
            // Skip the winner
            if ($participant['participant_id'] === $winner['participant_id']) {
                continue;
            }

            // Queue consolation email
            $this->emailQueue->queueEmail([
                'to_email' => $participant['user_email'],
                'subject' => "Thanks for playing - Try again with 10% off!",
                'template' => 'non_winner_notification',
                'variables' => [
                    'first_name' => $participant['first_name'],
                    'prize_name' => $round['prize_name'],
                    'winner_time' => number_format($winner['total_time_all_questions'], 3),
                    'participant_time' => number_format($participant['total_time_all_questions'], 3),
                    'replay_link' => $this->generateReplayLink($round['game_slug'], $participant['participant_id']),
                    'discount_code' => 'REPLAY10'
                ],
                'priority' => 2
            ]);

            // Queue WhatsApp consolation if opted in
            if ($participant['whatsapp_consent'] && !empty($participant['phone'])) {
                $this->whatsappQueue->queueMessage([
                    'to_phone' => $participant['phone'],
                    'template' => 'non_winner_notification',
                    'variables' => [
                        'first_name' => $participant['first_name'],
                        'replay_link' => $this->generateReplayLink($round['game_slug'], $participant['participant_id'])
                    ],
                    'priority' => 2
                ]);
            }
        }
    }

    /**
     * Auto-start new round if game has auto_restart enabled
     *
     * @param int $gameId The game ID
     */
    private function autoStartNewRound($gameId)
    {
        try {
            $game = $this->gameModel->getGameById($gameId);

            if ($game && $game['auto_restart'] && $game['status'] === 'active') {
                $newRoundId = $this->roundModel->createNewRound($gameId);

                if ($newRoundId) {
                    $this->analytics->logEvent('round_auto_started', null, $newRoundId, [
                        'game_id' => $gameId,
                        'auto_restart' => true
                    ]);
                }
            }
        } catch (Exception $e) {
            error_log("Auto-restart failed for game {$gameId}: " . $e->getMessage());
        }
    }

    /**
     * Generate secure 32-character claim token
     *
     * @return string Secure random token
     */
    private function generateSecureClaimToken()
    {
        return bin2hex(random_bytes(16)); // 32 character hex string
    }

    /**
     * Store claim token in database with expiration
     *
     * @param int $participantId Participant ID
     * @param string $token Claim token
     */
    private function storeClaimToken($participantId, $token)
    {
        $sql = "INSERT INTO claim_tokens (participant_id, token, expires_at, created_at)
                VALUES (:participant_id, :token, DATE_ADD(NOW(), INTERVAL 30 DAY), NOW())";

        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':participant_id', $participantId, PDO::PARAM_INT);
        $stmt->bindParam(':token', $token, PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * Generate secure claim link for winners
     *
     * @param string $token Claim token
     * @return string Full claim URL
     */
    private function generateClaimLink($token)
    {
        $baseUrl = rtrim(Config::get('APP_URL'), '/');
        return $baseUrl . '/claim/' . $token;
    }

    /**
     * Generate replay link with tracking for non-winners
     *
     * @param string $gameSlug Game slug
     * @param int $participantId Participant ID for tracking
     * @return string Replay URL with tracking
     */
    private function generateReplayLink($gameSlug, $participantId)
    {
        $baseUrl = rtrim(Config::get('APP_URL'), '/');
        return $baseUrl . '/win-a-' . $gameSlug . '?src=whatsapp_retry&ref=' . $participantId;
    }

    /**
     * Manual winner selection endpoint for admin use
     * Allows admin to manually trigger winner selection
     *
     * @param int $roundId Round ID
     * @return array JSON response
     */
    public function manualWinnerSelection($roundId)
    {
        // Verify admin authentication
        if (!$this->isAdmin()) {
            return $this->errorResponse('Admin authentication required', 401);
        }

        // Validate CSRF token
        if (!$this->validateCsrfToken()) {
            return $this->errorResponse('Invalid CSRF token', 403);
        }

        $result = $this->processRoundCompletion($roundId);

        // Log admin action
        $this->logAdminAction('manual_winner_selection', [
            'round_id' => $roundId,
            'success' => $result['success'],
            'admin_id' => $_SESSION['admin_id']
        ]);

        return $result;
    }

    /**
     * Get winner statistics for a specific round
     *
     * @param int $roundId Round ID
     * @return array Winner statistics
     */
    public function getWinnerStatistics($roundId)
    {
        $sql = "SELECT p.*, r.prize_name, r.prize_value, r.currency,
                       COUNT(*) OVER() as total_participants,
                       RANK() OVER(ORDER BY p.total_time_all_questions ASC) as time_rank
                FROM participants p
                JOIN rounds r ON p.round_id = r.round_id
                WHERE p.round_id = :round_id
                AND p.payment_status = 'paid'
                AND p.total_time_all_questions IS NOT NULL
                ORDER BY p.total_time_all_questions ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':round_id', $roundId, PDO::PARAM_INT);
        $stmt->execute();

        $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($participants)) {
            return null;
        }

        $winner = $participants[0];
        $stats = [
            'winner' => $winner,
            'total_participants' => $winner['total_participants'],
            'winning_time' => $winner['total_time_all_questions'],
            'time_distribution' => $this->calculateTimeDistribution($participants),
            'completion_rate' => $this->calculateCompletionRate($roundId)
        ];

        return $stats;
    }

    /**
     * Calculate time distribution statistics
     *
     * @param array $participants All participants
     * @return array Time statistics
     */
    private function calculateTimeDistribution($participants)
    {
        $times = array_column($participants, 'total_time_all_questions');

        return [
            'fastest' => min($times),
            'slowest' => max($times),
            'average' => array_sum($times) / count($times),
            'median' => $this->calculateMedian($times),
            'total_range' => max($times) - min($times)
        ];
    }

    /**
     * Calculate median value from array of times
     *
     * @param array $times Array of completion times
     * @return float Median time
     */
    private function calculateMedian($times)
    {
        sort($times);
        $count = count($times);
        $middle = floor($count / 2);

        if ($count % 2 === 0) {
            return ($times[$middle - 1] + $times[$middle]) / 2;
        } else {
            return $times[$middle];
        }
    }

    /**
     * Calculate completion rate for the round
     *
     * @param int $roundId Round ID
     * @return float Completion rate percentage
     */
    private function calculateCompletionRate($roundId)
    {
        $sql = "SELECT
                    COUNT(*) as total_paid,
                    COUNT(CASE WHEN total_time_all_questions IS NOT NULL THEN 1 END) as completed
                FROM participants
                WHERE round_id = :round_id AND payment_status = 'paid'";

        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':round_id', $roundId, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result['total_paid'] === 0) {
            return 0;
        }

        return ($result['completed'] / $result['total_paid']) * 100;
    }

    /**
     * Check if round is ready for winner selection
     * Used by webhook handlers to determine when to trigger winner selection
     *
     * @param int $roundId Round ID
     * @return bool True if ready for winner selection
     */
    public function isRoundReadyForWinnerSelection($roundId)
    {
        $round = $this->roundModel->getRoundById($roundId);

        if (!$round || $round['status'] !== 'active') {
            return false;
        }

        $paidCount = $this->participantModel->getPaidParticipantCount($roundId);

        return $paidCount >= $round['max_players'];
    }
}
?>
