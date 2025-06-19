<?php
declare(strict_types=1);

/**
 * File: core/WinnerSelection.php
 * Location: core/WinnerSelection.php
 *
 * WinABN Winner Selection Logic
 *
 * Handles winner selection based on fastest completion time with microsecond precision,
 * tie-breaking logic, fraud detection, and automated winner notification.
 *
 * @package WinABN\Core
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Core;

use WinABN\Core\Database;
use WinABN\Models\Round;
use WinABN\Models\Participant;
use Exception;

class WinnerSelection
{
    /**
     * Selection method constants
     *
     * @var string
     */
    public const METHOD_FASTEST_TIME = 'fastest_time';
    public const METHOD_RANDOM = 'random';
    public const METHOD_MANUAL = 'manual';

    /**
     * Select winner for a completed round
     *
     * @param int $roundId Round ID
     * @param string $method Selection method
     * @return array<string, mixed>|null Winner data or null if no eligible participants
     * @throws Exception
     */
    public static function selectWinner(int $roundId, string $method = self::METHOD_FASTEST_TIME): ?array
    {
        return Database::transaction(function() use ($roundId, $method) {
            $roundModel = new Round();
            $round = $roundModel->find($roundId);

            if (!$round) {
                throw new Exception("Round not found: $roundId");
            }

            // Get eligible participants
            $eligibleParticipants = self::getEligibleParticipants($roundId);

            if (empty($eligibleParticipants)) {
                app_log('warning', 'No eligible participants for winner selection', [
                    'round_id' => $roundId,
                    'method' => $method
                ]);
                return null;
            }

            // Apply selection method
            $winner = match($method) {
                self::METHOD_FASTEST_TIME => self::selectByFastestTime($eligibleParticipants),
                self::METHOD_RANDOM => self::selectRandomly($eligibleParticipants),
                self::METHOD_MANUAL => null, // Manual selection handled separately
                default => throw new Exception("Invalid selection method: $method")
            };

            if ($winner) {
                // Mark winner in database
                self::markWinner($roundId, $winner['id'], $method);

                // Queue winner notifications
                self::queueWinnerNotifications($roundId, $winner, $eligibleParticipants);

                app_log('info', 'Winner selected', [
                    'round_id' => $roundId,
                    'winner_id' => $winner['id'],
                    'winner_email' => $winner['user_email'],
                    'method' => $method,
                    'completion_time' => $winner['total_time_all_questions'],
                    'eligible_count' => count($eligibleParticipants)
                ]);
            }

            return $winner;
        });
    }

    /**
     * Get eligible participants for winner selection
     *
     * @param int $roundId Round ID
     * @return array<array<string, mixed>> Eligible participants
     */
    public static function getEligibleParticipants(int $roundId): array
    {
        $query = "
            SELECT * FROM participants
            WHERE round_id = ?
                AND payment_status = 'paid'
                AND game_status = 'completed'
                AND total_time_all_questions IS NOT NULL
                AND is_fraudulent = 0
            ORDER BY total_time_all_questions ASC, id ASC
        ";

        $participants = Database::fetchAll($query, [$roundId]);

        // Additional fraud screening
        $screenedParticipants = [];
        foreach ($participants as $participant) {
            if (self::passesAdvancedFraudCheck($participant)) {
                $screenedParticipants[] = $participant;
            } else {
                // Mark as fraudulent
                self::markParticipantFraudulent($participant['id'], 'Failed advanced fraud screening');
            }
        }

        return $screenedParticipants;
    }

    /**
     * Select winner by fastest completion time
     *
     * @param array<array<string, mixed>> $participants Eligible participants
     * @return array<string, mixed>|null Winner data
     */
    private static function selectByFastestTime(array $participants): ?array
    {
        if (empty($participants)) {
            return null;
        }

        // Participants are already sorted by total_time_all_questions ASC, id ASC
        $winner = $participants[0];

        // Check for ties (identical completion times to microsecond precision)
        $winningTime = $winner['total_time_all_questions'];
        $tiedParticipants = array_filter($participants, function($p) use ($winningTime) {
            return abs($p['total_time_all_questions'] - $winningTime) < 0.000001; // Microsecond precision
        });

        if (count($tiedParticipants) > 1) {
            app_log('info', 'Tie detected in winner selection', [
                'winning_time' => $winningTime,
                'tied_participants' => count($tiedParticipants),
                'participant_ids' => array_column($tiedParticipants, 'id')
            ]);

            // Tie-breaking: lowest participant ID wins (first to reach that exact time)
            $winner = min($tiedParticipants, function($a, $b) {
                return $a['id'] <=> $b['id'];
            });
        }

        return $winner;
    }

    /**
     * Select winner randomly
     *
     * @param array<array<string, mixed>> $participants Eligible participants
     * @return array<string, mixed>|null Winner data
     */
    private static function selectRandomly(array $participants): ?array
    {
        if (empty($participants)) {
            return null;
        }

        $randomIndex = array_rand($participants);
        return $participants[$randomIndex];
    }

    /**
     * Mark participant as winner
     *
     * @param int $roundId Round ID
     * @param int $participantId Winner participant ID
     * @param string $method Selection method used
     * @return void
     * @throws Exception
     */
    private static function markWinner(int $roundId, int $participantId, string $method): void
    {
        // Clear any existing winners for this round
        $query = "UPDATE participants SET is_winner = 0 WHERE round_id = ?";
        Database::execute($query, [$roundId]);

        // Mark new winner
        $query = "UPDATE participants SET is_winner = 1 WHERE id = ?";
        Database::execute($query, [$participantId]);

        // Update round with winner information
        $query = "
            UPDATE rounds
            SET winner_participant_id = ?,
                winner_selection_method = ?,
                status = 'completed',
                completed_at = NOW()
            WHERE id = ?
        ";
        Database::execute($query, [$participantId, $method, $roundId]);

        // Record analytics event
        $query = "
            INSERT INTO analytics_events (event_type, participant_id, round_id, created_at)
            VALUES ('winner_selected', ?, ?, NOW())
        ";
        Database::execute($query, [$participantId, $roundId]);
    }

    /**
     * Queue winner and loser notifications
     *
     * @param int $roundId Round ID
     * @param array<string, mixed> $winner Winner participant
     * @param array<array<string, mixed>> $allParticipants All eligible participants
     * @return void
     */
    private static function queueWinnerNotifications(int $roundId, array $winner, array $allParticipants): void
    {
        // Queue winner notification
        self::queueWinnerNotification($winner);

        // Queue loser notifications for all other participants
        foreach ($allParticipants as $participant) {
            if ($participant['id'] !== $winner['id']) {
                self::queueLoserNotification($participant, $winner);
            }
        }

        // Get all paid participants who didn't complete (also get loser notification)
        $incompletePaid = Database::fetchAll("
            SELECT * FROM participants
            WHERE round_id = ?
                AND payment_status = 'paid'
                AND game_status != 'completed'
                AND is_fraudulent = 0
        ", [$roundId]);

        foreach ($incompletePaid as $participant) {
            self::queueLoserNotification($participant, $winner);
        }
    }

    /**
     * Queue winner notification
     *
     * @param array<string, mixed> $winner Winner participant
     * @return void
     */
    private static function queueWinnerNotification(array $winner): void
    {
        // Generate secure claim token
        $claimToken = self::generateClaimToken($winner['id']);

        // Queue email notification
        $emailData = [
            'to_email' => $winner['user_email'],
            'subject' => 'Congratulations! You Won!',
            'template' => 'winner_notification',
            'variables' => [
                'first_name' => $winner['first_name'],
                'last_name' => $winner['last_name'],
                'completion_time' => $winner['total_time_all_questions'],
                'claim_token' => $claimToken,
                'claim_url' => url('claim/' . $claimToken)
            ],
            'priority' => 1 // Highest priority
        ];

        self::queueEmail($emailData);

        // Queue WhatsApp notification if consent given
        if ($winner['whatsapp_consent'] && $winner['phone']) {
            $whatsappData = [
                'to_phone' => $winner['phone'],
                'message_template' => 'winner_notification',
                'variables' => [
                    'first_name' => $winner['first_name'],
                    'claim_url' => url('claim/' . $claimToken)
                ],
                'participant_id' => $winner['id'],
                'priority' => 1
            ];

            self::queueWhatsApp($whatsappData);
        }
    }

    /**
     * Queue loser notification
     *
     * @param array<string, mixed> $participant Participant who didn't win
     * @param array<string, mixed> $winner Winner participant
     * @return void
     */
    private static function queueLoserNotification(array $participant, array $winner): void
    {
        // Generate replay link with tracking
        $replayUrl = self::generateReplayUrl($participant);

        // Queue email notification
        $emailData = [
            'to_email' => $participant['user_email'],
            'subject' => 'Better luck next time!',
            'template' => 'loser_notification',
            'variables' => [
                'first_name' => $participant['first_name'],
                'winner_time' => $winner['total_time_all_questions'],
                'participant_time' => $participant['total_time_all_questions'],
                'replay_url' => $replayUrl
            ],
            'priority' => 2
        ];

        self::queueEmail($emailData);

        // Queue WhatsApp notification if consent given
        if ($participant['whatsapp_consent'] && $participant['phone']) {
            $whatsappData = [
                'to_phone' => $participant['phone'],
                'message_template' => 'loser_notification',
                'variables' => [
                    'first_name' => $participant['first_name'],
                    'replay_url' => $replayUrl
                ],
                'participant_id' => $participant['id'],
                'priority' => 2
            ];

            self::queueWhatsApp($whatsappData);
        }
    }

    /**
     * Generate secure claim token for winner
     *
     * @param int $participantId Participant ID
     * @return string Claim token
     */
    private static function generateClaimToken(int $participantId): string
    {
        $token = bin2hex(random_bytes(32)); // 64-character token
        $expiresAt = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60)); // 30 days

        $query = "
            INSERT INTO claim_tokens (participant_id, token, token_type, expires_at)
            VALUES (?, ?, 'winner_claim', ?)
        ";

        Database::execute($query, [$participantId, $token, $expiresAt]);

        return $token;
    }

    /**
     * Generate replay URL with tracking
     *
     * @param array<string, mixed> $participant Participant data
     * @return string Replay URL
     */
    private static function generateReplayUrl(array $participant): string
    {
        // Get game slug
        $query = "
            SELECT g.slug FROM games g
            JOIN rounds r ON g.id = r.game_id
            WHERE r.id = ?
        ";
        $game = Database::fetchOne($query, [$participant['round_id']]);

        if (!$game) {
            return url();
        }

        // Create tracking parameters
        $trackingParams = [
            'src' => 'whatsapp_retry',
            'ref' => base64_encode($participant['user_email']),
            'pid' => $participant['id']
        ];

        return url('win-a-' . $game['slug'], $trackingParams);
    }

    /**
     * Perform advanced fraud check on participant
     *
     * @param array<string, mixed> $participant Participant data
     * @return bool Whether participant passes fraud check
     */
    private static function passesAdvancedFraudCheck(array $participant): bool
    {
        $fraudFactors = 0;

        // Check 1: Completion time too fast
        if ($participant['total_time_all_questions'] < 30) {
            $fraudFactors++;
        }

        // Check 2: Average question time too fast
        if ($participant['question_times_json']) {
            $questionTimes = json_decode($participant['question_times_json'], true);
            if ($questionTimes && is_array($questionTimes)) {
                $avgTime = array_sum($questionTimes) / count($questionTimes);
                if ($avgTime < 2) {
                    $fraudFactors++;
                }

                // Check 3: Suspiciously consistent timing
                $variance = self::calculateVariance($questionTimes);
                if ($variance < 0.1) { // Very low variance indicates automation
                    $fraudFactors++;
                }
            }
        }

        // Check 4: Too many attempts from same IP/device recently
        $ipCount = Database::fetchColumn("
            SELECT COUNT(*) FROM participants
            WHERE ip_address = ?
                AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                AND payment_status = 'paid'
        ", [$participant['ip_address']]);

        if ($ipCount > env('FRAUD_MAX_DAILY_PARTICIPATIONS', 5)) {
            $fraudFactors++;
        }

        // Check 5: Device fingerprint frequency
        if ($participant['device_fingerprint']) {
            $deviceCount = Database::fetchColumn("
                SELECT COUNT(*) FROM participants
                WHERE device_fingerprint = ?
                    AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    AND payment_status = 'paid'
            ", [$participant['device_fingerprint']]);

            if ($deviceCount > 3) {
                $fraudFactors++;
            }
        }

        // Fail if 2 or more fraud factors present
        $isFraudulent = $fraudFactors >= 2;

        if ($isFraudulent) {
            app_log('warning', 'Participant failed advanced fraud check', [
                'participant_id' => $participant['id'],
                'fraud_factors' => $fraudFactors,
                'completion_time' => $participant['total_time_all_questions'],
                'ip_count' => $ipCount,
                'device_count' => $deviceCount ?? 0
            ]);
        }

        return !$isFraudulent;
    }

    /**
     * Mark participant as fraudulent
     *
     * @param int $participantId Participant ID
     * @param string $reason Fraud reason
     * @return void
     */
    private static function markParticipantFraudulent(int $participantId, string $reason): void
    {
        $query = "
            UPDATE participants
            SET is_fraudulent = 1, fraud_reason = ?
            WHERE id = ?
        ";

        Database::execute($query, [$reason, $participantId]);
    }

    /**
     * Calculate variance of timing array
     *
     * @param array<float> $times Array of times
     * @return float Variance
     */
    private static function calculateVariance(array $times): float
    {
        if (count($times) < 2) {
            return 0;
        }

        $mean = array_sum($times) / count($times);
        $squaredDiffs = array_map(function($time) use ($mean) {
            return pow($time - $mean, 2);
        }, $times);

        return array_sum($squaredDiffs) / count($squaredDiffs);
    }

    /**
     * Queue email notification
     *
     * @param array<string, mixed> $emailData Email data
     * @return void
     */
    private static function queueEmail(array $emailData): void
    {
        $query = "
            INSERT INTO email_queue (
                to_email, subject, template, variables_json, priority, send_at
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ";

        Database::execute($query, [
            $emailData['to_email'],
            $emailData['subject'],
            $emailData['template'],
            json_encode($emailData['variables']),
            $emailData['priority']
        ]);
    }

    /**
     * Queue WhatsApp notification
     *
     * @param array<string, mixed> $whatsappData WhatsApp data
     * @return void
     */
    private static function queueWhatsApp(array $whatsappData): void
    {
        $query = "
            INSERT INTO whatsapp_queue (
                to_phone, message_template, variables_json, participant_id, priority, send_at
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ";

        Database::execute($query, [
            $whatsappData['to_phone'],
            $whatsappData['message_template'],
            json_encode($whatsappData['variables']),
            $whatsappData['participant_id'],
            $whatsappData['priority']
        ]);
    }

    /**
     * Get winner statistics for round
     *
     * @param int $roundId Round ID
     * @return array<string, mixed>|null Winner statistics
     */
    public static function getWinnerStats(int $roundId): ?array
    {
        $query = "
            SELECT
                p.*,
                g.name as game_name,
                g.prize_value,
                g.currency,
                r.completed_at as round_completed_at,
                r.winner_selection_method
            FROM participants p
            JOIN rounds r ON p.round_id = r.id
            JOIN games g ON r.game_id = g.id
            WHERE p.round_id = ? AND p.is_winner = 1
        ";

        $winner = Database::fetchOne($query, [$roundId]);

        if (!$winner) {
            return null;
        }

        // Get completion rank
        $rankQuery = "
            SELECT COUNT(*) + 1 as rank
            FROM participants
            WHERE round_id = ?
                AND payment_status = 'paid'
                AND game_status = 'completed'
                AND total_time_all_questions < ?
                AND is_fraudulent = 0
        ";

        $rank = Database::fetchColumn($rankQuery, [$roundId, $winner['total_time_all_questions']]);

        $winner['completion_rank'] = $rank ?: 1;

        return $winner;
    }

    /**
     * Validate winner selection for round
     *
     * @param int $roundId Round ID
     * @return array<string, mixed> Validation results
     */
    public static function validateWinnerSelection(int $roundId): array
    {
        $round = Database::fetchOne("SELECT * FROM rounds WHERE id = ?", [$roundId]);

        if (!$round) {
            return ['valid' => false, 'errors' => ['Round not found']];
        }

        $errors = [];
        $warnings = [];

        // Check if round is completed
        if ($round['status'] !== 'completed') {
            $errors[] = 'Round is not completed';
        }

        // Check if winner exists
        if (!$round['winner_participant_id']) {
            $errors[] = 'No winner selected for completed round';
        }

        // Validate winner eligibility
        if ($round['winner_participant_id']) {
            $winner = Database::fetchOne("
                SELECT * FROM participants
                WHERE id = ? AND round_id = ?
            ", [$round['winner_participant_id'], $roundId]);

            if (!$winner) {
                $errors[] = 'Winner participant not found';
            } else {
                if ($winner['payment_status'] !== 'paid') {
                    $errors[] = 'Winner does not have paid status';
                }

                if ($winner['game_status'] !== 'completed') {
                    $errors[] = 'Winner did not complete the game';
                }

                if ($winner['is_fraudulent']) {
                    $errors[] = 'Winner is marked as fraudulent';
                }

                if (!$winner['total_time_all_questions']) {
                    $errors[] = 'Winner has no completion time recorded';
                }
            }
        }

        // Check for faster valid participants
        if ($round['winner_participant_id'] && $round['winner_selection_method'] === self::METHOD_FASTEST_TIME) {
            $fasterCount = Database::fetchColumn("
                SELECT COUNT(*) FROM participants
                WHERE round_id = ?
                    AND payment_status = 'paid'
                    AND game_status = 'completed'
                    AND total_time_all_questions < (
                        SELECT total_time_all_questions
                        FROM participants
                        WHERE id = ?
                    )
                    AND is_fraudulent = 0
            ", [$roundId, $round['winner_participant_id']]);

            if ($fasterCount > 0) {
                $warnings[] = "$fasterCount participants completed faster than the declared winner";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'round_status' => $round['status'],
            'winner_id' => $round['winner_participant_id'],
            'selection_method' => $round['winner_selection_method']
        ];
    }
}
