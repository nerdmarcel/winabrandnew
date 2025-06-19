<?php
declare(strict_types=1);

/**
 * File: models/Round.php
 * Location: models/Round.php
 *
 * WinABN Round Model
 *
 * Handles round management including participant tracking, winner selection,
 * auto-restart functionality, and concurrency control for the WinABN platform.
 *
 * @package WinABN\Models
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Models;

use WinABN\Core\Database;
use WinABN\Core\Model;
use Exception;

class Round extends Model
{
    /**
     * Table name
     *
     * @var string
     */
    protected string $table = 'rounds';

    /**
     * Round status constants
     *
     * @var string
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_FULL = 'full';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Winner selection methods
     *
     * @var string
     */
    public const WINNER_FASTEST_TIME = 'fastest_time';
    public const WINNER_RANDOM = 'random';
    public const WINNER_MANUAL = 'manual';

    /**
     * Find round by ID
     *
     * @param int $id Round ID
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $query = "SELECT * FROM {$this->table} WHERE id = ?";
        return Database::fetchOne($query, [$id]);
    }

    /**
     * Get or create active round for game
     *
     * @param int $gameId Game ID
     * @return array<string, mixed> Round data
     * @throws Exception
     */
    public function getOrCreateActiveRound(int $gameId): array
    {
        return Database::transaction(function() use ($gameId) {
            // Lock the game record to prevent race conditions
            $query = "SELECT * FROM games WHERE id = ? FOR UPDATE";
            $game = Database::fetchOne($query, [$gameId]);

            if (!$game) {
                throw new Exception("Game not found: $gameId");
            }

            if ($game['status'] !== 'active') {
                throw new Exception("Game is not active: $gameId");
            }

            // Look for existing active round
            $query = "
                SELECT * FROM {$this->table}
                WHERE game_id = ? AND status = ?
                FOR UPDATE
            ";
            $activeRound = Database::fetchOne($query, [$gameId, self::STATUS_ACTIVE]);

            if ($activeRound) {
                // Check if round is full
                if ($activeRound['paid_participant_count'] >= $game['max_players']) {
                    // Complete this round and create new one if auto-restart enabled
                    $this->completeRound($activeRound['id']);

                    if ($game['auto_restart']) {
                        return $this->createNewRound($gameId);
                    } else {
                        throw new Exception("Round is full and auto-restart is disabled");
                    }
                }

                return $activeRound;
            }

            // No active round exists, create new one
            return $this->createNewRound($gameId);
        });
    }

    /**
     * Create new round
     *
     * @param int $gameId Game ID
     * @return array<string, mixed> New round data
     * @throws Exception
     */
    public function createNewRound(int $gameId): array
    {
        $query = "
            INSERT INTO {$this->table} (game_id, status, participant_count, paid_participant_count)
            VALUES (?, ?, 0, 0)
        ";

        Database::execute($query, [$gameId, self::STATUS_ACTIVE]);
        $roundId = Database::lastInsertId();

        app_log('info', 'New round created', [
            'round_id' => $roundId,
            'game_id' => $gameId
        ]);

        return $this->find($roundId);
    }

    /**
     * Add participant to round
     *
     * @param int $roundId Round ID
     * @param bool $isPaid Whether participant has paid
     * @return bool Success
     * @throws Exception
     */
    public function addParticipant(int $roundId, bool $isPaid = false): bool
    {
        return Database::transaction(function() use ($roundId, $isPaid) {
            // Lock round record
            $query = "SELECT * FROM {$this->table} WHERE id = ? FOR UPDATE";
            $round = Database::fetchOne($query, [$roundId]);

            if (!$round) {
                throw new Exception("Round not found: $roundId");
            }

            if ($round['status'] !== self::STATUS_ACTIVE) {
                throw new Exception("Round is not active: $roundId");
            }

            // Get game data to check max players
            $game = Database::fetchOne("SELECT max_players FROM games WHERE id = ?", [$round['game_id']]);

            if ($isPaid && $round['paid_participant_count'] >= $game['max_players']) {
                throw new Exception("Round is already full");
            }

            // Update participant counts
            $participantCountIncrement = 1;
            $paidCountIncrement = $isPaid ? 1 : 0;

            $query = "
                UPDATE {$this->table}
                SET participant_count = participant_count + ?,
                    paid_participant_count = paid_participant_count + ?
                WHERE id = ?
            ";

            Database::execute($query, [$participantCountIncrement, $paidCountIncrement, $roundId]);

            // Check if round is now full
            if ($isPaid) {
                $newPaidCount = $round['paid_participant_count'] + $paidCountIncrement;
                if ($newPaidCount >= $game['max_players']) {
                    $this->markRoundFull($roundId);
                }
            }

            return true;
        });
    }

    /**
     * Update participant payment status
     *
     * @param int $roundId Round ID
     * @param int $participantId Participant ID
     * @param string $oldStatus Old payment status
     * @param string $newStatus New payment status
     * @return bool Success
     * @throws Exception
     */
    public function updateParticipantPaymentStatus(int $roundId, int $participantId, string $oldStatus, string $newStatus): bool
    {
        return Database::transaction(function() use ($roundId, $participantId, $oldStatus, $newStatus) {
            // Lock round record
            $query = "SELECT * FROM {$this->table} WHERE id = ? FOR UPDATE";
            $round = Database::fetchOne($query, [$roundId]);

            if (!$round) {
                throw new Exception("Round not found: $roundId");
            }

            $paidCountChange = 0;

            // Calculate paid count change
            if ($oldStatus !== 'paid' && $newStatus === 'paid') {
                $paidCountChange = 1;
            } elseif ($oldStatus === 'paid' && $newStatus !== 'paid') {
                $paidCountChange = -1;
            }

            if ($paidCountChange !== 0) {
                $query = "
                    UPDATE {$this->table}
                    SET paid_participant_count = paid_participant_count + ?
                    WHERE id = ?
                ";

                Database::execute($query, [$paidCountChange, $roundId]);

                // Check if round should be marked as full
                if ($paidCountChange > 0) {
                    $game = Database::fetchOne("SELECT max_players FROM games WHERE id = ?", [$round['game_id']]);
                    $newPaidCount = $round['paid_participant_count'] + $paidCountChange;

                    if ($newPaidCount >= $game['max_players']) {
                        $this->markRoundFull($roundId);
                    }
                }
            }

            return true;
        });
    }

    /**
     * Mark round as full
     *
     * @param int $roundId Round ID
     * @return bool Success
     */
    public function markRoundFull(int $roundId): bool
    {
        $query = "UPDATE {$this->table} SET status = ? WHERE id = ?";
        Database::execute($query, [self::STATUS_FULL, $roundId]);

        app_log('info', 'Round marked as full', ['round_id' => $roundId]);

        // Trigger winner selection process
        $this->selectWinner($roundId);

        return true;
    }

    /**
     * Complete round and select winner
     *
     * @param int $roundId Round ID
     * @param string $method Winner selection method
     * @return array<string, mixed>|null Winner data
     * @throws Exception
     */
    public function completeRound(int $roundId, string $method = self::WINNER_FASTEST_TIME): ?array
    {
        return Database::transaction(function() use ($roundId, $method) {
            $round = $this->find($roundId);
            if (!$round) {
                throw new Exception("Round not found: $roundId");
            }

            if ($round['status'] === self::STATUS_COMPLETED) {
                // Already completed, return existing winner
                return $this->getWinner($roundId);
            }

            // Select winner
            $winner = $this->selectWinner($roundId, $method);

            // Update round status
            $query = "
                UPDATE {$this->table}
                SET status = ?,
                    completed_at = NOW(),
                    winner_participant_id = ?,
                    winner_selection_method = ?
                WHERE id = ?
            ";

            Database::execute($query, [
                self::STATUS_COMPLETED,
                $winner ? $winner['id'] : null,
                $method,
                $roundId
            ]);

            if ($winner) {
                // Mark winner in participants table
                $query = "UPDATE participants SET is_winner = 1 WHERE id = ?";
                Database::execute($query, [$winner['id']]);

                app_log('info', 'Round completed with winner', [
                    'round_id' => $roundId,
                    'winner_id' => $winner['id'],
                    'winner_email' => $winner['user_email'],
                    'completion_time' => $winner['total_time_all_questions']
                ]);
            } else {
                app_log('warning', 'Round completed without winner', ['round_id' => $roundId]);
            }

            return $winner;
        });
    }

    /**
     * Select winner from round participants
     *
     * @param int $roundId Round ID
     * @param string $method Selection method
     * @return array<string, mixed>|null Winner participant data
     * @throws Exception
     */
    public function selectWinner(int $roundId, string $method = self::WINNER_FASTEST_TIME): ?array
    {
        $round = $this->find($roundId);
        if (!$round) {
            throw new Exception("Round not found: $roundId");
        }

        switch ($method) {
            case self::WINNER_FASTEST_TIME:
                return $this->selectWinnerByFastestTime($roundId);

            case self::WINNER_RANDOM:
                return $this->selectWinnerRandomly($roundId);

            case self::WINNER_MANUAL:
                // Manual selection - no automatic winner
                return null;

            default:
                throw new Exception("Invalid winner selection method: $method");
        }
    }

    /**
     * Select winner by fastest completion time
     *
     * @param int $roundId Round ID
     * @return array<string, mixed>|null Winner data
     */
    private function selectWinnerByFastestTime(int $roundId): ?array
    {
        $query = "
            SELECT * FROM participants
            WHERE round_id = ?
                AND payment_status = 'paid'
                AND game_status = 'completed'
                AND total_time_all_questions IS NOT NULL
                AND is_fraudulent = 0
            ORDER BY total_time_all_questions ASC, id ASC
            LIMIT 1
        ";

        return Database::fetchOne($query, [$roundId]);
    }

    /**
     * Select winner randomly
     *
     * @param int $roundId Round ID
     * @return array<string, mixed>|null Winner data
     */
    private function selectWinnerRandomly(int $roundId): ?array
    {
        $query = "
            SELECT * FROM participants
            WHERE round_id = ?
                AND payment_status = 'paid'
                AND game_status = 'completed'
                AND is_fraudulent = 0
            ORDER BY RAND()
            LIMIT 1
        ";

        return Database::fetchOne($query, [$roundId]);
    }

    /**
     * Get winner of round
     *
     * @param int $roundId Round ID
     * @return array<string, mixed>|null Winner data
     */
    public function getWinner(int $roundId): ?array
    {
        $query = "
            SELECT p.* FROM participants p
            JOIN rounds r ON p.id = r.winner_participant_id
            WHERE r.id = ?
        ";

        return Database::fetchOne($query, [$roundId]);
    }

    /**
     * Get round participants
     *
     * @param int $roundId Round ID
     * @param string|null $paymentStatus Filter by payment status
     * @return array<array<string, mixed>>
     */
    public function getParticipants(int $roundId, ?string $paymentStatus = null): array
    {
        $query = "SELECT * FROM participants WHERE round_id = ?";
        $params = [$roundId];

        if ($paymentStatus !== null) {
            $query .= " AND payment_status = ?";
            $params[] = $paymentStatus;
        }

        $query .= " ORDER BY created_at ASC";

        return Database::fetchAll($query, $params);
    }

    /**
     * Get round statistics
     *
     * @param int $roundId Round ID
     * @return array<string, mixed>
     */
    public function getRoundStats(int $roundId): array
    {
        $query = "
            SELECT
                r.*,
                g.name as game_name,
                g.prize_value,
                g.currency,
                g.max_players,
                COUNT(DISTINCT p.id) as total_participants,
                COUNT(DISTINCT CASE WHEN p.payment_status = 'paid' THEN p.id END) as paid_participants,
                COUNT(DISTINCT CASE WHEN p.game_status = 'completed' THEN p.id END) as completed_participants,
                AVG(CASE WHEN p.total_time_all_questions IS NOT NULL THEN p.total_time_all_questions END) as avg_completion_time,
                MIN(CASE WHEN p.total_time_all_questions IS NOT NULL THEN p.total_time_all_questions END) as fastest_time,
                SUM(CASE WHEN p.payment_status = 'paid' THEN p.payment_amount ELSE 0 END) as total_revenue
            FROM {$this->table} r
            JOIN games g ON r.game_id = g.id
            LEFT JOIN participants p ON r.id = p.round_id
            WHERE r.id = ?
            GROUP BY r.id
        ";

        $stats = Database::fetchOne($query, [$roundId]);

        if ($stats) {
            // Calculate fill percentage
            $stats['fill_percentage'] = $stats['max_players'] > 0
                ? round(($stats['paid_participants'] / $stats['max_players']) * 100, 2)
                : 0;

            // Calculate duration
            if ($stats['completed_at']) {
                $start = new \DateTime($stats['started_at']);
                $end = new \DateTime($stats['completed_at']);
                $stats['duration_minutes'] = $start->diff($end)->i;
            } else {
                $start = new \DateTime($stats['started_at']);
                $now = new \DateTime();
                $stats['duration_minutes'] = $start->diff($now)->i;
            }
        }

        return $stats ?: [];
    }

    /**
     * Get active rounds for game
     *
     * @param int $gameId Game ID
     * @return array<array<string, mixed>>
     */
    public function getActiveRounds(int $gameId): array
    {
        $query = "
            SELECT r.*, g.name as game_name, g.max_players
            FROM {$this->table} r
            JOIN games g ON r.game_id = g.id
            WHERE r.game_id = ? AND r.status = ?
            ORDER BY r.started_at DESC
        ";

        return Database::fetchAll($query, [$gameId, self::STATUS_ACTIVE]);
    }

    /**
     * Get recent completed rounds
     *
     * @param int|null $gameId Filter by game ID
     * @param int $limit Number of rounds to return
     * @return array<array<string, mixed>>
     */
    public function getRecentCompletedRounds(?int $gameId = null, int $limit = 10): array
    {
        $query = "
            SELECT r.*, g.name as game_name, g.prize_value, g.currency,
                   w.first_name as winner_first_name, w.last_name as winner_last_name,
                   w.total_time_all_questions as winning_time
            FROM {$this->table} r
            JOIN games g ON r.game_id = g.id
            LEFT JOIN participants w ON r.winner_participant_id = w.id
            WHERE r.status = ?
        ";

        $params = [self::STATUS_COMPLETED];

        if ($gameId !== null) {
            $query .= " AND r.game_id = ?";
            $params[] = $gameId;
        }

        $query .= " ORDER BY r.completed_at DESC LIMIT ?";
        $params[] = $limit;

        return Database::fetchAll($query, $params);
    }

    /**
     * Cancel round
     *
     * @param int $roundId Round ID
     * @param string $reason Cancellation reason
     * @return bool Success
     * @throws Exception
     */
    public function cancelRound(int $roundId, string $reason = ''): bool
    {
        return Database::transaction(function() use ($roundId, $reason) {
            $round = $this->find($roundId);
            if (!$round) {
                throw new Exception("Round not found: $roundId");
            }

            if ($round['status'] === self::STATUS_COMPLETED) {
                throw new Exception("Cannot cancel completed round");
            }

            // Update round status
            $query = "UPDATE {$this->table} SET status = ? WHERE id = ?";
            Database::execute($query, [self::STATUS_CANCELLED, $roundId]);

            // Log cancellation
            app_log('info', 'Round cancelled', [
                'round_id' => $roundId,
                'reason' => $reason,
                'participant_count' => $round['participant_count'],
                'paid_participants' => $round['paid_participant_count']
            ]);

            return true;
        });
    }

    /**
     * Set manual winner
     *
     * @param int $roundId Round ID
     * @param int $participantId Winner participant ID
     * @return bool Success
     * @throws Exception
     */
    public function setManualWinner(int $roundId, int $participantId): bool
    {
        return Database::transaction(function() use ($roundId, $participantId) {
            // Verify participant belongs to round
            $participant = Database::fetchOne(
                "SELECT * FROM participants WHERE id = ? AND round_id = ?",
                [$participantId, $roundId]
            );

            if (!$participant) {
                throw new Exception("Participant not found in round");
            }

            if ($participant['payment_status'] !== 'paid') {
                throw new Exception("Winner must have paid status");
            }

            // Clear existing winner
            Database::execute("UPDATE participants SET is_winner = 0 WHERE round_id = ?", [$roundId]);

            // Set new winner
            Database::execute("UPDATE participants SET is_winner = 1 WHERE id = ?", [$participantId]);

            // Update round
            $query = "
                UPDATE {$this->table}
                SET winner_participant_id = ?,
                    winner_selection_method = ?,
                    status = ?,
                    completed_at = NOW()
                WHERE id = ?
            ";

            Database::execute($query, [$participantId, self::WINNER_MANUAL, self::STATUS_COMPLETED, $roundId]);

            app_log('info', 'Manual winner set', [
                'round_id' => $roundId,
                'winner_id' => $participantId,
                'winner_email' => $participant['user_email']
            ]);

            return true;
        });
    }

    /**
     * Get rounds requiring winner selection
     *
     * @return array<array<string, mixed>>
     */
    public function getRoundsNeedingWinnerSelection(): array
    {
        $query = "
            SELECT r.*, g.name as game_name
            FROM {$this->table} r
            JOIN games g ON r.game_id = g.id
            WHERE r.status = ? AND r.winner_participant_id IS NULL
            ORDER BY r.started_at ASC
        ";

        return Database::fetchAll($query, [self::STATUS_FULL]);
    }
}
