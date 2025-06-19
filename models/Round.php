<?php

/**
 * Win a Brand New - Round Model Class
 * File: /models/Round.php
 *
 * Handles round management, concurrency control, and round lifecycle
 * according to the Development Specification requirements.
 *
 * Features:
 * - Round lifecycle management (active/full/completed/cancelled)
 * - Concurrency control with database row locking
 * - Participant counting and round completion detection
 * - Auto-restart logic for new rounds
 * - Winner selection coordination
 * - Thread-safe round transitions
 *
 * Critical Timing Logic:
 * - New round auto-starts when current round reaches max_players paid participants
 * - Database row locking prevents race conditions during round transitions
 * - Only paid participants count toward max_players limit
 * - Round completion triggers winner selection process
 *
 * @package WinABrandNew\Models
 * @version 1.0.0
 * @author NerdMarcel Development Team
 */

namespace WinABrandNew\Models;

use WinABrandNew\Core\Database;
use WinABrandNew\Core\Security;
use Exception;

class Round
{
    /**
     * Round ID
     *
     * @var int|null
     */
    private ?int $id = null;

    /**
     * Game ID this round belongs to
     *
     * @var int
     */
    private int $gameId;

    /**
     * Round number within the game
     *
     * @var int
     */
    private int $roundNumber = 1;

    /**
     * Round status
     *
     * @var string
     */
    private string $status = 'active';

    /**
     * Total participant count
     *
     * @var int
     */
    private int $participantCount = 0;

    /**
     * Paid participant count (only these count toward max_players)
     *
     * @var int
     */
    private int $paidParticipantCount = 0;

    /**
     * Round start timestamp
     *
     * @var string|null
     */
    private ?string $startedAt = null;

    /**
     * Round completion timestamp
     *
     * @var string|null
     */
    private ?string $completedAt = null;

    /**
     * Winner participant ID
     *
     * @var int|null
     */
    private ?int $winnerParticipantId = null;

    /**
     * Winner selection timestamp
     *
     * @var string|null
     */
    private ?string $winnerSelectedAt = null;

    /**
     * Round creation timestamp
     *
     * @var string|null
     */
    private ?string $createdAt = null;

    /**
     * Round last update timestamp
     *
     * @var string|null
     */
    private ?string $updatedAt = null;

    /**
     * Round status constants
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_FULL = 'full';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Valid round statuses
     */
    public const VALID_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_FULL,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED
    ];

    /**
     * Constructor
     *
     * @param int $gameId Game ID this round belongs to
     */
    public function __construct(int $gameId)
    {
        $this->gameId = $gameId;
        $this->startedAt = date('Y-m-d H:i:s');
        $this->createdAt = date('Y-m-d H:i:s');
        $this->updatedAt = date('Y-m-d H:i:s');
    }

    /**
     * Create a new round for a game
     *
     * @param int $gameId Game ID
     * @return Round New round instance
     * @throws Exception If round creation fails
     */
    public static function create(int $gameId): Round
    {
        return Database::transaction(function() use ($gameId) {
            // Get the next round number for this game
            $lastRound = Database::selectOne(
                "SELECT MAX(round_number) as last_round FROM rounds WHERE game_id = ?",
                [$gameId]
            );

            $nextRoundNumber = ($lastRound['last_round'] ?? 0) + 1;

            // Create new round instance
            $round = new Round($gameId);
            $round->roundNumber = $nextRoundNumber;

            // Insert into database
            $roundId = Database::insert(
                "INSERT INTO rounds (
                    game_id, round_number, status, participant_count,
                    paid_participant_count, started_at, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $round->gameId,
                    $round->roundNumber,
                    $round->status,
                    $round->participantCount,
                    $round->paidParticipantCount,
                    $round->startedAt,
                    $round->createdAt,
                    $round->updatedAt
                ]
            );

            $round->id = (int)$roundId;

            self::logActivity('round_created', [
                'round_id' => $round->id,
                'game_id' => $gameId,
                'round_number' => $round->roundNumber
            ]);

            return $round;
        });
    }

    /**
     * Get current active round for a game (with concurrency control)
     *
     * @param int $gameId Game ID
     * @param bool $lockForUpdate Whether to lock the row for update
     * @return Round|null Current active round or null if none exists
     * @throws Exception If database operation fails
     */
    public static function getCurrentRound(int $gameId, bool $lockForUpdate = false): ?Round
    {
        $sql = "SELECT * FROM rounds
                WHERE game_id = ? AND status = ?
                ORDER BY round_number DESC
                LIMIT 1";

        if ($lockForUpdate) {
            $sql .= " FOR UPDATE";
        }

        $roundData = Database::selectOne($sql, [$gameId, self::STATUS_ACTIVE]);

        if (!$roundData) {
            return null;
        }

        return self::fromArray($roundData);
    }

    /**
     * Get or create current round for a game with concurrency control
     * This is the main method used during participant registration
     *
     * @param int $gameId Game ID
     * @return Round Current or new round
     * @throws Exception If operation fails
     */
    public static function getOrCreateCurrentRound(int $gameId): Round
    {
        return Database::transaction(function() use ($gameId) {
            // Lock the current round for update to prevent race conditions
            $currentRound = self::getCurrentRound($gameId, true);

            if ($currentRound && $currentRound->getStatus() === self::STATUS_ACTIVE) {
                // Check if round is full and needs to be completed
                $game = Game::findById($gameId);
                if (!$game) {
                    throw new Exception("Game not found");
                }

                if ($currentRound->getPaidParticipantCount() >= $game->getMaxPlayers()) {
                    // Mark current round as full and completed
                    $currentRound->markAsCompleted();

                    // Create new round for the next participants
                    $newRound = self::create($gameId);

                    return $newRound;
                }

                return $currentRound;
            }

            // No active round found, create a new one
            return self::create($gameId);
        });
    }

    /**
     * Add participant to round and check if round becomes full
     *
     * @param int $participantId Participant ID
     * @param bool $isPaid Whether participant has paid
     * @return bool Whether round became full after adding participant
     * @throws Exception If operation fails
     */
    public function addParticipant(int $participantId, bool $isPaid = false): bool
    {
        return Database::transaction(function() use ($participantId, $isPaid) {
            // Increment participant counts
            $this->participantCount++;
            if ($isPaid) {
                $this->paidParticipantCount++;
            }

            // Update database
            Database::update(
                "UPDATE rounds SET
                    participant_count = ?,
                    paid_participant_count = ?,
                    updated_at = ?
                WHERE id = ?",
                [
                    $this->participantCount,
                    $this->paidParticipantCount,
                    date('Y-m-d H:i:s'),
                    $this->id
                ]
            );

            // Check if round is now full
            $game = Game::findById($this->gameId);
            if ($game && $this->paidParticipantCount >= $game->getMaxPlayers()) {
                $this->markAsFull();

                self::logActivity('round_full', [
                    'round_id' => $this->id,
                    'game_id' => $this->gameId,
                    'final_participant_count' => $this->paidParticipantCount
                ]);

                return true; // Round became full
            }

            return false; // Round not full yet
        });
    }

    /**
     * Remove participant from round (fraud removal)
     *
     * @param int $participantId Participant ID
     * @param bool $wasPaid Whether participant had paid
     * @return void
     * @throws Exception If operation fails
     */
    public function removeParticipant(int $participantId, bool $wasPaid = false): void
    {
        Database::transaction(function() use ($participantId, $wasPaid) {
            // Decrement participant counts
            $this->participantCount = max(0, $this->participantCount - 1);
            if ($wasPaid) {
                $this->paidParticipantCount = max(0, $this->paidParticipantCount - 1);
            }

            // Update database
            Database::update(
                "UPDATE rounds SET
                    participant_count = ?,
                    paid_participant_count = ?,
                    updated_at = ?
                WHERE id = ?",
                [
                    $this->participantCount,
                    $this->paidParticipantCount,
                    date('Y-m-d H:i:s'),
                    $this->id
                ]
            );

            // If round was full and now has space, reactivate it
            if ($this->status === self::STATUS_FULL) {
                $game = Game::findById($this->gameId);
                if ($game && $this->paidParticipantCount < $game->getMaxPlayers()) {
                    $this->status = self::STATUS_ACTIVE;
                    Database::update(
                        "UPDATE rounds SET status = ?, updated_at = ? WHERE id = ?",
                        [$this->status, date('Y-m-d H:i:s'), $this->id]
                    );

                    self::logActivity('round_reactivated', [
                        'round_id' => $this->id,
                        'reason' => 'participant_removal',
                        'current_paid_count' => $this->paidParticipantCount
                    ]);
                }
            }

            self::logActivity('participant_removed', [
                'round_id' => $this->id,
                'participant_id' => $participantId,
                'was_paid' => $wasPaid,
                'new_paid_count' => $this->paidParticipantCount
            ]);
        });
    }

    /**
     * Mark round as full
     *
     * @return void
     * @throws Exception If update fails
     */
    public function markAsFull(): void
    {
        $this->status = self::STATUS_FULL;
        $this->updatedAt = date('Y-m-d H:i:s');

        Database::update(
            "UPDATE rounds SET status = ?, updated_at = ? WHERE id = ?",
            [$this->status, $this->updatedAt, $this->id]
        );

        self::logActivity('round_marked_full', [
            'round_id' => $this->id,
            'paid_participant_count' => $this->paidParticipantCount
        ]);
    }

    /**
     * Mark round as completed and trigger winner selection
     *
     * @return void
     * @throws Exception If completion fails
     */
    public function markAsCompleted(): void
    {
        Database::transaction(function() {
            $this->status = self::STATUS_COMPLETED;
            $this->completedAt = date('Y-m-d H:i:s');
            $this->updatedAt = date('Y-m-d H:i:s');

            Database::update(
                "UPDATE rounds SET status = ?, completed_at = ?, updated_at = ? WHERE id = ?",
                [$this->status, $this->completedAt, $this->updatedAt, $this->id]
            );

            self::logActivity('round_completed', [
                'round_id' => $this->id,
                'completed_at' => $this->completedAt,
                'final_participant_count' => $this->paidParticipantCount
            ]);

            // Trigger winner selection process
            $this->selectWinner();
        });
    }

    /**
     * Select winner for the completed round
     *
     * @return int|null Winner participant ID
     * @throws Exception If winner selection fails
     */
    public function selectWinner(): ?int
    {
        if ($this->status !== self::STATUS_COMPLETED) {
            throw new Exception("Cannot select winner for non-completed round");
        }

        return Database::transaction(function() {
            // Find fastest paid participant with correct answers to all questions
            $winner = Database::selectOne(
                "SELECT id, total_time_all_questions, user_email
                FROM participants
                WHERE round_id = ?
                AND payment_status = 'paid'
                AND game_completed = 1
                AND correct_answers = 9
                ORDER BY total_time_all_questions ASC, id ASC
                LIMIT 1",
                [$this->id]
            );

            if (!$winner) {
                self::logActivity('no_winner_found', [
                    'round_id' => $this->id,
                    'reason' => 'no_qualified_participants'
                ]);
                return null;
            }

            $this->winnerParticipantId = (int)$winner['id'];
            $this->winnerSelectedAt = date('Y-m-d H:i:s');

            // Update round with winner information
            Database::update(
                "UPDATE rounds SET
                    winner_participant_id = ?,
                    winner_selected_at = ?,
                    updated_at = ?
                WHERE id = ?",
                [
                    $this->winnerParticipantId,
                    $this->winnerSelectedAt,
                    $this->updatedAt,
                    $this->id
                ]
            );

            // Mark winner participant
            Database::update(
                "UPDATE participants SET is_winner = 1, updated_at = ? WHERE id = ?",
                [date('Y-m-d H:i:s'), $this->winnerParticipantId]
            );

            self::logActivity('winner_selected', [
                'round_id' => $this->id,
                'winner_participant_id' => $this->winnerParticipantId,
                'winner_email' => $winner['user_email'],
                'winning_time' => $winner['total_time_all_questions']
            ]);

            // Queue winner notification
            $this->queueWinnerNotification();

            return $this->winnerParticipantId;
        });
    }

    /**
     * Queue winner notification email and WhatsApp message
     *
     * @return void
     * @throws Exception If notification queueing fails
     */
    private function queueWinnerNotification(): void
    {
        if (!$this->winnerParticipantId) {
            return;
        }

        $winner = Database::selectOne(
            "SELECT p.*, g.name as game_name, g.prize_value, g.currency
            FROM participants p
            JOIN rounds r ON p.round_id = r.id
            JOIN games g ON r.game_id = g.id
            WHERE p.id = ?",
            [$this->winnerParticipantId]
        );

        if (!$winner) {
            return;
        }

        // Queue winner notification email
        EmailQueue::create([
            'to_email' => $winner['user_email'],
            'to_name' => $winner['first_name'] . ' ' . $winner['last_name'],
            'template_name' => 'winner_notification',
            'template_vars' => [
                'first_name' => $winner['first_name'],
                'game_name' => $winner['game_name'],
                'prize_value' => $winner['prize_value'],
                'currency' => $winner['currency'],
                'round_number' => $this->roundNumber,
                'completion_time' => $winner['total_time_all_questions']
            ],
            'priority' => 1 // High priority
        ]);

        // Queue WhatsApp notification if user consented
        if ($winner['whatsapp_consent'] && $winner['phone']) {
            WhatsAppQueue::create([
                'to_phone' => $winner['phone'],
                'message_template' => 'winner_notification',
                'participant_id' => $this->winnerParticipantId,
                'variables' => [
                    'first_name' => $winner['first_name'],
                    'game_name' => $winner['game_name'],
                    'prize_value' => $winner['prize_value'],
                    'currency' => $winner['currency']
                ],
                'priority' => 1 // High priority
            ]);
        }
    }

    /**
     * Cancel round (admin action)
     *
     * @param string $reason Cancellation reason
     * @return void
     * @throws Exception If cancellation fails
     */
    public function cancel(string $reason = ''): void
    {
        Database::transaction(function() use ($reason) {
            $this->status = self::STATUS_CANCELLED;
            $this->updatedAt = date('Y-m-d H:i:s');

            Database::update(
                "UPDATE rounds SET status = ?, updated_at = ? WHERE id = ?",
                [$this->status, $this->updatedAt, $this->id]
            );

            self::logActivity('round_cancelled', [
                'round_id' => $this->id,
                'reason' => $reason,
                'participant_count' => $this->participantCount,
                'paid_participant_count' => $this->paidParticipantCount
            ]);

            // Process refunds for paid participants if needed
            $this->processRefunds($reason);
        });
    }

    /**
     * Process refunds for cancelled round
     *
     * @param string $reason Cancellation reason
     * @return void
     * @throws Exception If refund processing fails
     */
    private function processRefunds(string $reason): void
    {
        $paidParticipants = Database::select(
            "SELECT id, user_email, payment_id, payment_amount, payment_currency
            FROM participants
            WHERE round_id = ? AND payment_status = 'paid'",
            [$this->id]
        );

        foreach ($paidParticipants as $participant) {
            // Queue refund processing (implement with payment provider)
            self::logActivity('refund_queued', [
                'participant_id' => $participant['id'],
                'payment_id' => $participant['payment_id'],
                'amount' => $participant['payment_amount'],
                'currency' => $participant['payment_currency'],
                'reason' => $reason
            ]);
        }
    }

    /**
     * Get round statistics
     *
     * @return array Round statistics
     */
    public function getStatistics(): array
    {
        $completedParticipants = Database::selectOne(
            "SELECT COUNT(*) as count FROM participants
            WHERE round_id = ? AND game_completed = 1",
            [$this->id]
        );

        $averageTime = Database::selectOne(
            "SELECT AVG(total_time_all_questions) as avg_time
            FROM participants
            WHERE round_id = ? AND game_completed = 1 AND total_time_all_questions IS NOT NULL",
            [$this->id]
        );

        return [
            'round_id' => $this->id,
            'game_id' => $this->gameId,
            'round_number' => $this->roundNumber,
            'status' => $this->status,
            'total_participants' => $this->participantCount,
            'paid_participants' => $this->paidParticipantCount,
            'completed_participants' => $completedParticipants['count'] ?? 0,
            'average_completion_time' => $averageTime['avg_time'] ?? null,
            'winner_participant_id' => $this->winnerParticipantId,
            'started_at' => $this->startedAt,
            'completed_at' => $this->completedAt,
            'winner_selected_at' => $this->winnerSelectedAt
        ];
    }

    /**
     * Find round by ID
     *
     * @param int $id Round ID
     * @return Round|null Round instance or null if not found
     */
    public static function findById(int $id): ?Round
    {
        $roundData = Database::selectOne(
            "SELECT * FROM rounds WHERE id = ?",
            [$id]
        );

        return $roundData ? self::fromArray($roundData) : null;
    }

    /**
     * Find rounds by game ID
     *
     * @param int $gameId Game ID
     * @param string|null $status Filter by status
     * @param int $limit Maximum number of rounds to return
     * @param int $offset Offset for pagination
     * @return array Array of Round instances
     */
    public static function findByGameId(
        int $gameId,
        ?string $status = null,
        int $limit = 50,
        int $offset = 0
    ): array {
        $sql = "SELECT * FROM rounds WHERE game_id = ?";
        $params = [$gameId];

        if ($status !== null) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY round_number DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $roundsData = Database::select($sql, $params);

        return array_map(function($roundData) {
            return self::fromArray($roundData);
        }, $roundsData);
    }

    /**
     * Create Round instance from database array
     *
     * @param array $data Database row data
     * @return Round Round instance
     */
    public static function fromArray(array $data): Round
    {
        $round = new Round((int)$data['game_id']);
        $round->id = (int)$data['id'];
        $round->roundNumber = (int)$data['round_number'];
        $round->status = $data['status'];
        $round->participantCount = (int)$data['participant_count'];
        $round->paidParticipantCount = (int)$data['paid_participant_count'];
        $round->startedAt = $data['started_at'];
        $round->completedAt = $data['completed_at'];
        $round->winnerParticipantId = $data['winner_participant_id'] ? (int)$data['winner_participant_id'] : null;
        $round->winnerSelectedAt = $data['winner_selected_at'];
        $round->createdAt = $data['created_at'];
        $round->updatedAt = $data['updated_at'];

        return $round;
    }

    /**
     * Log round activity
     *
     * @param string $action Action performed
     * @param array $details Action details
     * @return void
     */
    private static function logActivity(string $action, array $details = []): void
    {
        try {
            Database::insert(
                "INSERT INTO analytics_events (
                    event_type, round_id, game_id, event_properties, created_at
                ) VALUES (?, ?, ?, ?, ?)",
                [
                    $action,
                    $details['round_id'] ?? null,
                    $details['game_id'] ?? null,
                    json_encode($details),
                    date('Y-m-d H:i:s')
                ]
            );
        } catch (Exception $e) {
            // Log to error log but don't break the main operation
            error_log("Failed to log round activity: " . $e->getMessage());
        }
    }

    /**
     * Validate round status
     *
     * @param string $status Status to validate
     * @return bool Whether status is valid
     */
    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::VALID_STATUSES);
    }

    // Getters
    public function getId(): ?int { return $this->id; }
    public function getGameId(): int { return $this->gameId; }
    public function getRoundNumber(): int { return $this->roundNumber; }
    public function getStatus(): string { return $this->status; }
    public function getParticipantCount(): int { return $this->participantCount; }
    public function getPaidParticipantCount(): int { return $this->paidParticipantCount; }
    public function getStartedAt(): ?string { return $this->startedAt; }
    public function getCompletedAt(): ?string { return $this->completedAt; }
    public function getWinnerParticipantId(): ?int { return $this->winnerParticipantId; }
    public function getWinnerSelectedAt(): ?string { return $this->winnerSelectedAt; }
    public function getCreatedAt(): ?string { return $this->createdAt; }
    public function getUpdatedAt(): ?string { return $this->updatedAt; }

    /**
     * Check if round is active
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Check if round is full
     *
     * @return bool
     */
    public function isFull(): bool
    {
        return $this->status === self::STATUS_FULL;
    }

    /**
     * Check if round is completed
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if round is cancelled
     *
     * @return bool
     */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Convert round to array representation
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'game_id' => $this->gameId,
            'round_number' => $this->roundNumber,
            'status' => $this->status,
            'participant_count' => $this->participantCount,
            'paid_participant_count' => $this->paidParticipantCount,
            'started_at' => $this->startedAt,
            'completed_at' => $this->completedAt,
            'winner_participant_id' => $this->winnerParticipantId,
            'winner_selected_at' => $this->winnerSelectedAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt
        ];
    }
}

/**
 * Round Helper Functions
 *
 * Convenience functions for common round operations
 */

/**
 * Get current active round for a game
 *
 * @param int $gameId Game ID
 * @return Round|null
 */
function getCurrentRound(int $gameId): ?Round
{
    return Round::getCurrentRound($gameId);
}

/**
 * Get or create round for participant registration
 *
 * @param int $gameId Game ID
 * @return Round
 */
function getOrCreateRound(int $gameId): Round
{
    return Round::getOrCreateCurrentRound($gameId);
}

/**
 * Check if a round is ready for winner selection
 *
 * @param int $roundId Round ID
 * @return bool
 */
function isRoundReadyForWinner(int $roundId): bool
{
    $round = Round::findById($roundId);
    return $round && $round->isCompleted() && !$round->getWinnerParticipantId();
}
