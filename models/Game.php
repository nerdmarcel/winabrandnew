<?php
declare(strict_types=1);

/**
 * File: models/Game.php
 * Location: models/Game.php
 *
 * WinABN Game Model
 *
 * Handles all game-related database operations and business logic including
 * game creation, management, statistics, round coordination, and currency handling.
 *
 * @package WinABN\Models
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Models;

use WinABN\Core\{Database, Model};
use Exception;

class Game extends Model
{
    /**
     * Table name
     *
     * @var string
     */
    protected string $table = 'games';

    /**
     * Primary key column
     *
     * @var string
     */
    protected string $primaryKey = 'id';

    /**
     * Supported currencies
     *
     * @var array<string>
     */
    private const SUPPORTED_CURRENCIES = ['GBP', 'USD', 'EUR', 'CAD', 'AUD'];

    /**
     * Game status constants
     *
     * @var string
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_ARCHIVED = 'archived';

    /**
     * Fillable columns for mass assignment
     *
     * @var array<string>
     */
    protected array $fillable = [
        'name',
        'slug',
        'description',
        'prize_value',
        'currency',
        'max_players',
        'entry_fee',
        'entry_fee_usd',
        'entry_fee_eur',
        'entry_fee_gbp',
        'entry_fee_cad',
        'entry_fee_aud',
        'auto_restart',
        'status',
        'total_questions',
        'free_questions',
        'question_timeout'
    ];

    /**
     * Find game by ID
     *
     * @param int $id Game ID
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $query = "SELECT * FROM {$this->table} WHERE id = ?";
        return Database::fetchOne($query, [$id]);
    }

    /**
     * Find game by slug
     *
     * @param string $slug Game slug
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $query = "SELECT * FROM {$this->table} WHERE slug = ?";
        return Database::fetchOne($query, [$slug]);
    }

    /**
     * Get active game by slug for public access
     *
     * @param string $slug Game slug
     * @return array<string, mixed>|null
     */
    public function getActiveBySlug(string $slug): ?array
    {
        $query = "
            SELECT g.*,
                   COUNT(DISTINCT q.id) as question_count,
                   COUNT(DISTINCT r.id) as total_rounds,
                   COUNT(DISTINCT CASE WHEN r.status = 'active' THEN r.id END) as active_rounds
            FROM {$this->table} g
            LEFT JOIN questions q ON g.id = q.game_id AND q.is_active = 1
            LEFT JOIN rounds r ON g.id = r.game_id
            WHERE g.slug = ? AND g.status = ?
            GROUP BY g.id
        ";

        return Database::fetchOne($query, [$slug, self::STATUS_ACTIVE]);
    }

    /**
     * Get all games with pagination and optional filters
     *
     * @param int $limit Number of games per page
     * @param int $offset Starting offset
     * @param array<string, mixed> $filters Optional filters
     * @return array<array<string, mixed>>
     */
    public function getAllWithStats(int $limit = 20, int $offset = 0, array $filters = []): array
    {
        $whereConditions = [];
        $params = [];

        // Apply filters
        if (!empty($filters['search'])) {
            $whereConditions[] = "(g.name LIKE ? OR g.slug LIKE ?)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if (!empty($filters['status'])) {
            $whereConditions[] = "g.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['currency'])) {
            $whereConditions[] = "g.currency = ?";
            $params[] = $filters['currency'];
        }

        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

        // Determine sort order
        $orderBy = $this->getSortOrder($filters['sort'] ?? 'created_desc');

        $query = "
            SELECT
                g.*,
                COUNT(DISTINCT p.id) as total_participants,
                COUNT(DISTINCT CASE WHEN p.payment_status = 'paid' THEN p.id END) as paid_participants,
                COALESCE(SUM(CASE WHEN p.payment_status = 'paid' THEN p.payment_amount END), 0) as total_revenue,
                COUNT(DISTINCT r.id) as total_rounds,
                COUNT(DISTINCT CASE WHEN r.status = 'completed' THEN r.id END) as completed_rounds
            FROM {$this->table} g
            LEFT JOIN rounds r ON g.id = r.game_id
            LEFT JOIN participants p ON r.id = p.round_id
            {$whereClause}
            GROUP BY g.id
            {$orderBy}
            LIMIT ? OFFSET ?
        ";

        $params[] = $limit;
        $params[] = $offset;

        return Database::fetchAll($query, $params);
    }

    /**
     * Get total count of games with filters
     *
     * @param array<string, mixed> $filters Optional filters
     * @return int
     */
    public function getTotalCount(array $filters = []): int
    {
        $whereConditions = [];
        $params = [];

        if (!empty($filters['search'])) {
            $whereConditions[] = "(name LIKE ? OR slug LIKE ?)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if (!empty($filters['status'])) {
            $whereConditions[] = "status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['currency'])) {
            $whereConditions[] = "currency = ?";
            $params[] = $filters['currency'];
        }

        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

        $query = "SELECT COUNT(*) FROM {$this->table} {$whereClause}";

        return (int) Database::fetchColumn($query, $params);
    }

    /**
     * Get count of active games
     *
     * @return int
     */
    public function getActiveCount(): int
    {
        $query = "SELECT COUNT(*) FROM {$this->table} WHERE status = ?";
        return (int) Database::fetchColumn($query, [self::STATUS_ACTIVE]);
    }

    /**
     * Get all active games
     *
     * @return array<array<string, mixed>>
     */
    public function getActiveGames(): array
    {
        $query = "
            SELECT g.*,
                   COUNT(DISTINCT r.id) as total_rounds,
                   COUNT(DISTINCT CASE WHEN r.status = 'active' THEN r.id END) as active_rounds
            FROM {$this->table} g
            LEFT JOIN rounds r ON g.id = r.game_id
            WHERE g.status = ?
            GROUP BY g.id
            ORDER BY g.created_at DESC
        ";

        return Database::fetchAll($query, [self::STATUS_ACTIVE]);
    }

    /**
     * Create new game with automatic exchange rate calculation
     *
     * @param array<string, mixed> $data Game data
     * @return int Created game ID
     * @throws Exception
     */
    public function create(array $data): int
    {
        $this->validateGameData($data);

        // Generate slug if not provided
        if (empty($data['slug'])) {
            $data['slug'] = $this->generateSlug($data['name']);
        }

        // Calculate exchange rates for entry fees
        $data = $this->calculateCurrencyPrices($data);

        // Set default values
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        $query = "
            INSERT INTO {$this->table} (
                name, slug, description, prize_value, currency, max_players,
                entry_fee, entry_fee_usd, entry_fee_eur, entry_fee_gbp,
                entry_fee_cad, entry_fee_aud, auto_restart, status,
                total_questions, free_questions, question_timeout,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";

        Database::execute($query, [
            $data['name'],
            $data['slug'],
            $data['description'] ?? null,
            $data['prize_value'],
            $data['currency'] ?? 'GBP',
            $data['max_players'],
            $data['entry_fee'],
            $data['entry_fee_usd'],
            $data['entry_fee_eur'],
            $data['entry_fee_gbp'],
            $data['entry_fee_cad'],
            $data['entry_fee_aud'],
            $data['auto_restart'] ?? true,
            $data['status'] ?? self::STATUS_ACTIVE,
            $data['total_questions'] ?? 9,
            $data['free_questions'] ?? 3,
            $data['question_timeout'] ?? 10,
            $data['created_at'],
            $data['updated_at']
        ]);

        return Database::lastInsertId();
    }

    /**
     * Update game with automatic exchange rate recalculation
     *
     * @param int $id Game ID
     * @param array<string, mixed> $data Update data
     * @return bool
     * @throws Exception
     */
    public function update(int $id, array $data): bool
    {
        $game = $this->findById($id);
        if (!$game) {
            throw new Exception("Game not found: $id");
        }

        $this->validateGameData($data, false);

        // Recalculate exchange rates if entry fee changed
        if (isset($data['entry_fee']) || isset($data['currency'])) {
            $data = $this->calculateCurrencyPrices(array_merge($game, $data));
        }

        $data['updated_at'] = date('Y-m-d H:i:s');

        $updateFields = [];
        $params = [];

        $allowedFields = [
            'name', 'slug', 'description', 'prize_value', 'currency', 'max_players',
            'entry_fee', 'entry_fee_usd', 'entry_fee_eur', 'entry_fee_gbp',
            'entry_fee_cad', 'entry_fee_aud', 'auto_restart', 'status',
            'total_questions', 'free_questions', 'question_timeout', 'updated_at'
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updateFields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($updateFields)) {
            return true; // Nothing to update
        }

        $params[] = $id;
        $query = "UPDATE {$this->table} SET " . implode(', ', $updateFields) . " WHERE id = ?";

        Database::execute($query, $params);
        return true;
    }

    /**
     * Get game statistics
     *
     * @param int $gameId Game ID
     * @return array<string, mixed>
     */
    public function getGameStats(int $gameId): array
    {
        $query = "
            SELECT
                COUNT(DISTINCT p.id) as total_participants,
                COUNT(DISTINCT CASE WHEN p.payment_status = 'paid' THEN p.id END) as paid_participants,
                COALESCE(SUM(CASE WHEN p.payment_status = 'paid' THEN p.payment_amount END), 0) as total_revenue,
                COUNT(DISTINCT r.id) as total_rounds,
                COUNT(DISTINCT CASE WHEN r.status = 'completed' THEN r.id END) as completed_rounds,
                COUNT(DISTINCT CASE WHEN r.status = 'active' THEN r.id END) as active_rounds,
                COUNT(DISTINCT CASE WHEN p.is_winner = 1 THEN p.id END) as total_winners,
                AVG(CASE WHEN p.total_time_all_questions IS NOT NULL THEN p.total_time_all_questions END) as avg_completion_time,
                MIN(CASE WHEN p.total_time_all_questions IS NOT NULL THEN p.total_time_all_questions END) as fastest_completion_time,
                MAX(CASE WHEN p.total_time_all_questions IS NOT NULL THEN p.total_time_all_questions END) as slowest_time
            FROM games g
            LEFT JOIN rounds r ON g.id = r.game_id
            LEFT JOIN participants p ON r.id = p.round_id
            WHERE g.id = ?
            GROUP BY g.id
        ";

        $stats = Database::fetchOne($query, [$gameId]);

        if (!$stats) {
            return [
                'total_participants' => 0,
                'paid_participants' => 0,
                'total_revenue' => 0.00,
                'total_rounds' => 0,
                'completed_rounds' => 0,
                'active_rounds' => 0,
                'total_winners' => 0,
                'avg_completion_time' => null,
                'fastest_completion_time' => null,
                'slowest_time' => null,
                'payment_conversion_rate' => 0,
                'completion_rate' => 0
            ];
        }

        // Calculate conversion rates
        if ($stats['total_participants'] > 0) {
            $stats['payment_conversion_rate'] = round(
                ($stats['paid_participants'] / $stats['total_participants']) * 100, 2
            );
        } else {
            $stats['payment_conversion_rate'] = 0;
        }

        if ($stats['paid_participants'] > 0) {
            $stats['completion_rate'] = round(
                ($stats['total_winners'] / $stats['paid_participants']) * 100, 2
            );
        } else {
            $stats['completion_rate'] = 0;
        }

        return $stats;
    }

    /**
     * Get entry fee for specific currency
     *
     * @param int $gameId Game ID
     * @param string $currency Currency code
     * @return float Entry fee amount
     */
    public function getEntryFee(int $gameId, string $currency = 'GBP'): float
    {
        $game = $this->findById($gameId);
        if (!$game) {
            throw new Exception("Game not found: $gameId");
        }

        $currency = strtoupper($currency);
        if (!in_array($currency, self::SUPPORTED_CURRENCIES)) {
            throw new Exception("Unsupported currency: $currency");
        }

        $feeField = 'entry_fee_' . strtolower($currency);

        if (!isset($game[$feeField]) || $game[$feeField] === null) {
            // Fallback to base entry fee
            return (float) $game['entry_fee'];
        }

        return (float) $game[$feeField];
    }

    /**
     * Check if game can accept new participants
     *
     * @param int $gameId Game ID
     * @return bool
     */
    public function canAcceptParticipants(int $gameId): bool
    {
        $game = $this->findById($gameId);
        if (!$game || $game['status'] !== self::STATUS_ACTIVE) {
            return false;
        }

        // Check if there's an active round with space
        $activeRound = $this->getActiveRound($gameId);
        if (!$activeRound) {
            return $game['auto_restart']; // Can create new round if auto-restart enabled
        }

        return $activeRound['paid_participant_count'] < $game['max_players'];
    }

    /**
     * Get current active round for a game or create new one
     *
     * @param int $gameId Game ID
     * @return array<string, mixed>|null
     */
    public function getCurrentRound(int $gameId): ?array
    {
        $query = "
            SELECT * FROM rounds
            WHERE game_id = ? AND status = 'active'
            ORDER BY started_at DESC
            LIMIT 1
        ";

        $round = Database::fetchOne($query, [$gameId]);

        // If no active round and auto-restart is enabled, create new round
        if (!$round) {
            $game = $this->findById($gameId);
            if ($game && $game['auto_restart'] && $game['status'] === self::STATUS_ACTIVE) {
                $roundId = $this->createNewRound($gameId);
                $round = Database::fetchOne("SELECT * FROM rounds WHERE id = ?", [$roundId]);
            }
        }

        return $round;
    }

    /**
     * Get active round for game
     *
     * @param int $gameId Game ID
     * @return array<string, mixed>|null
     */
    public function getActiveRound(int $gameId): ?array
    {
        $query = "
            SELECT * FROM rounds
            WHERE game_id = ? AND status = 'active'
            ORDER BY id DESC
            LIMIT 1
        ";

        return Database::fetchOne($query, [$gameId]);
    }

    /**
     * Create new round for a game
     *
     * @param int $gameId Game ID
     * @return int Created round ID
     * @throws Exception
     */
    public function createNewRound(int $gameId): int
    {
        $query = "
            INSERT INTO rounds (game_id, status, started_at, created_at)
            VALUES (?, 'active', NOW(), NOW())
        ";

        Database::execute($query, [$gameId]);
        return Database::lastInsertId();
    }

    /**
     * Check if round is full and handle completion
     *
     * @param int $roundId Round ID
     * @return bool True if round was completed
     * @throws Exception
     */
    public function checkRoundCompletion(int $roundId): bool
    {
        Database::beginTransaction();

        try {
            // Lock the round for update to prevent race conditions
            $query = "
                SELECT r.*, g.max_players, g.auto_restart
                FROM rounds r
                JOIN games g ON r.game_id = g.id
                WHERE r.id = ?
                FOR UPDATE
            ";

            $round = Database::fetchOne($query, [$roundId]);

            if (!$round || $round['status'] !== 'active') {
                Database::rollback();
                return false;
            }

            // Count paid participants
            $paidCount = (int) Database::fetchColumn(
                "SELECT COUNT(*) FROM participants WHERE round_id = ? AND payment_status = 'paid'",
                [$roundId]
            );

            // Update participant count
            Database::execute(
                "UPDATE rounds SET paid_participant_count = ? WHERE id = ?",
                [$paidCount, $roundId]
            );

            // Check if round is full
            if ($paidCount >= $round['max_players']) {
                // Complete the round
                $winnerId = $this->selectRoundWinner($roundId);

                Database::execute(
                    "UPDATE rounds SET status = 'completed', completed_at = NOW(), winner_participant_id = ? WHERE id = ?",
                    [$winnerId, $roundId]
                );

                // Mark winner
                if ($winnerId) {
                    Database::execute(
                        "UPDATE participants SET is_winner = 1 WHERE id = ?",
                        [$winnerId]
                    );
                }

                // Create new round if auto-restart is enabled
                if ($round['auto_restart']) {
                    $this->createNewRound($round['game_id']);
                }

                Database::commit();
                return true;
            }

            Database::commit();
            return false;

        } catch (Exception $e) {
            Database::rollback();
            throw $e;
        }
    }

    /**
     * Get current exchange rates
     *
     * @return array<string, float>
     */
    public function getExchangeRates(): array
    {
        $query = "
            SELECT
                CONCAT(base_currency, '_', target_currency) as rate_key,
                rate
            FROM exchange_rates
            WHERE base_currency = 'GBP'
            AND DATE(updated_at) = CURDATE()
            ORDER BY updated_at DESC
        ";

        $rates = Database::fetchAll($query);
        $exchangeRates = [];

        foreach ($rates as $rate) {
            $exchangeRates[$rate['rate_key']] = (float) $rate['rate'];
        }

        // Fallback rates if no current data
        if (empty($exchangeRates)) {
            $exchangeRates = [
                'GBP_USD' => 1.25,
                'GBP_EUR' => 1.15,
                'GBP_CAD' => 1.65,
                'GBP_AUD' => 1.80
            ];
        }

        return $exchangeRates;
    }

    /**
     * Get games suitable for homepage/featured display
     *
     * @param int $limit Number of games to return
     * @return array<array<string, mixed>>
     */
    public function getFeaturedGames(int $limit = 6): array
    {
        $query = "
            SELECT g.*,
                   COUNT(DISTINCT p.id) as total_participants,
                   COUNT(DISTINCT CASE WHEN p.payment_status = 'paid' THEN p.id END) as paid_participants,
                   COUNT(DISTINCT CASE WHEN r.status = 'active' THEN r.id END) as active_rounds
            FROM {$this->table} g
            LEFT JOIN rounds r ON g.id = r.game_id
            LEFT JOIN participants p ON r.id = p.round_id
            WHERE g.status = ?
            GROUP BY g.id
            ORDER BY g.created_at DESC
            LIMIT ?
        ";

        return Database::fetchAll($query, [self::STATUS_ACTIVE, $limit]);
    }

    /**
     * Get questions count for game
     *
     * @param int $gameId Game ID
     * @return int Number of questions
     */
    public function getQuestionsCount(int $gameId): int
    {
        $query = "SELECT COUNT(*) FROM questions WHERE game_id = ? AND is_active = 1";
        return (int) Database::fetchColumn($query, [$gameId]);
    }

    /**
     * Validate minimum questions requirement
     *
     * @param int $gameId Game ID
     * @return bool
     * @throws Exception
     */
    public function validateQuestionsRequirement(int $gameId): bool
    {
        $game = $this->findById($gameId);
        if (!$game) {
            throw new Exception("Game not found: $gameId");
        }

        $questionsCount = $this->getQuestionsCount($gameId);
        $minRequired = $game['total_questions'] * 3; // 3x for optimal variety

        if ($questionsCount < $game['total_questions']) {
            throw new Exception("Game needs at least {$game['total_questions']} questions, only $questionsCount found");
        }

        if ($questionsCount < $minRequired) {
            // Warning but not error
            if (function_exists('app_log')) {
                app_log('warning', "Game has less than optimal questions", [
                    'game_id' => $gameId,
                    'current_count' => $questionsCount,
                    'recommended_minimum' => $minRequired
                ]);
            }
        }

        return true;
    }

    /**
     * Toggle game status
     *
     * @param int $gameId Game ID
     * @param string $status New status
     * @return bool
     * @throws Exception
     */
    public function setStatus(int $gameId, string $status): bool
    {
        $validStatuses = [self::STATUS_ACTIVE, self::STATUS_PAUSED, self::STATUS_ARCHIVED];
        if (!in_array($status, $validStatuses)) {
            throw new Exception("Invalid status: $status");
        }

        $query = "UPDATE {$this->table} SET status = ?, updated_at = NOW() WHERE id = ?";
        Database::execute($query, [$status, $gameId]);

        return true;
    }

    /**
     * Get performance analytics for admin dashboard
     *
     * @return array<string, mixed>
     */
    public function getPerformanceAnalytics(): array
    {
        $query = "
            SELECT
                COUNT(DISTINCT g.id) as total_games,
                COUNT(DISTINCT CASE WHEN g.status = 'active' THEN g.id END) as active_games,
                COUNT(DISTINCT r.id) as total_rounds,
                COUNT(DISTINCT CASE WHEN r.status = 'completed' THEN r.id END) as completed_rounds,
                COUNT(DISTINCT p.id) as total_participants,
                COUNT(DISTINCT CASE WHEN p.payment_status = 'paid' THEN p.id END) as paid_participants,
                COALESCE(SUM(CASE WHEN p.payment_status = 'paid' THEN p.payment_amount END), 0) as total_revenue,
                AVG(CASE WHEN p.payment_status = 'paid' THEN p.payment_amount END) as avg_payment_amount
            FROM games g
            LEFT JOIN rounds r ON g.id = r.game_id
            LEFT JOIN participants p ON r.id = p.round_id
        ";

        $analytics = Database::fetchOne($query);

        // Calculate conversion rates
        if ($analytics && $analytics['total_participants'] > 0) {
            $analytics['payment_conversion_rate'] =
                ($analytics['paid_participants'] / $analytics['total_participants']) * 100;
        } else {
            $analytics['payment_conversion_rate'] = 0;
        }

        return $analytics ?: [];
    }

    /**
     * Validate game slug uniqueness
     *
     * @param string $slug Game slug
     * @param int|null $excludeId Game ID to exclude from check
     * @return bool True if slug is unique
     */
    public function isSlugUnique(string $slug, ?int $excludeId = null): bool
    {
        $query = "SELECT COUNT(*) FROM {$this->table} WHERE slug = ?";
        $params = [$slug];

        if ($excludeId !== null) {
            $query .= " AND id != ?";
            $params[] = $excludeId;
        }

        return (int) Database::fetchColumn($query, $params) === 0;
    }

    /**
     * Get game summary for dashboard
     *
     * @param int $gameId Game ID
     * @return array<string, mixed>
     */
    public function getDashboardSummary(int $gameId): array
    {
        $query = "
            SELECT
                g.*,
                COUNT(DISTINCT q.id) as question_count,
                COUNT(DISTINCT r.id) as total_rounds,
                COUNT(DISTINCT CASE WHEN r.status = 'active' THEN r.id END) as active_rounds,
                COUNT(DISTINCT CASE WHEN r.status = 'completed' THEN r.id END) as completed_rounds,
                COUNT(DISTINCT p.id) as total_participants,
                COUNT(DISTINCT CASE WHEN p.payment_status = 'paid' THEN p.id END) as paid_participants,
                COALESCE(SUM(CASE WHEN p.payment_status = 'paid' THEN p.payment_amount END), 0) as total_revenue
            FROM {$this->table} g
            LEFT JOIN questions q ON g.id = q.game_id AND q.is_active = 1
            LEFT JOIN rounds r ON g.id = r.game_id
            LEFT JOIN participants p ON r.id = p.round_id
            WHERE g.id = ?
            GROUP BY g.id
        ";

        return Database::fetchOne($query, [$gameId]) ?: [];
    }

    /**
     * Select winner for completed round
     *
     * @param int $roundId Round ID
     * @return int|null Winner participant ID
     */
    private function selectRoundWinner(int $roundId): ?int
    {
        $query = "
            SELECT id
            FROM participants
            WHERE round_id = ?
            AND payment_status = 'paid'
            AND game_status = 'completed'
            AND total_time_all_questions IS NOT NULL
            ORDER BY total_time_all_questions ASC, id ASC
            LIMIT 1
        ";

        $result = Database::fetchOne($query, [$roundId]);
        return $result ? (int) $result['id'] : null;
    }

    /**
     * Generate unique slug from name
     *
     * @param string $name Game name
     * @return string
     */
    private function generateSlug(string $name): string
    {
        // Convert to lowercase and replace spaces/special chars with hyphens
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        // Ensure uniqueness
        $originalSlug = $slug;
        $counter = 1;

        while ($this->slugExists($slug)) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Check if slug exists
     *
     * @param string $slug Slug to check
     * @return bool
     */
    private function slugExists(string $slug): bool
    {
        $query = "SELECT COUNT(*) FROM {$this->table} WHERE slug = ?";
        return Database::fetchColumn($query, [$slug]) > 0;
    }

    /**
     * Calculate currency prices based on exchange rates
     *
     * @param array<string, mixed> $data Game data
     * @return array<string, mixed>
     */
    private function calculateCurrencyPrices(array $data): array
    {
        $baseCurrency = $data['currency'] ?? 'GBP';
        $baseAmount = $data['entry_fee'];

        // Get current exchange rates
        $exchangeRates = $this->getExchangeRates();

        // If base currency prices not set, calculate them
        foreach (self::SUPPORTED_CURRENCIES as $currency) {
            $feeField = 'entry_fee_' . strtolower($currency);

            if (!isset($data[$feeField]) || $data[$feeField] === null) {
                if ($currency === $baseCurrency) {
                    $data[$feeField] = $baseAmount;
                } else {
                    $data[$feeField] = $this->convertCurrency($baseAmount, $baseCurrency, $currency, $exchangeRates);
                }
            }
        }

        return $data;
    }

    /**
     * Convert currency using exchange rates
     *
     * @param float $amount Amount to convert
     * @param string $fromCurrency Source currency
     * @param string $toCurrency Target currency
     * @param array<string, float> $exchangeRates Exchange rates
     * @return float Converted amount
     */
    private function convertCurrency(float $amount, string $fromCurrency, string $toCurrency, array $exchangeRates = []): float
    {
        if ($fromCurrency === $toCurrency) {
            return $amount;
        }

        // Use provided rates or fetch from database
        if (empty($exchangeRates)) {
            $exchangeRates = $this->getExchangeRates();
        }

        $rateKey = $fromCurrency . '_' . $toCurrency;

        if (isset($exchangeRates[$rateKey])) {
            $rate = $exchangeRates[$rateKey];
        } else {
            // Try reverse rate
            $reverseRateKey = $toCurrency . '_' . $fromCurrency;
            if (isset($exchangeRates[$reverseRateKey]) && $exchangeRates[$reverseRateKey] > 0) {
                $rate = 1 / $exchangeRates[$reverseRateKey];
            } else {
                // Fallback rates for GBP base
                $fallbackRates = [
                    'GBP_USD' => 1.25,
                    'GBP_EUR' => 1.15,
                    'GBP_CAD' => 1.65,
                    'GBP_AUD' => 1.80
                ];

                if (isset($fallbackRates[$rateKey])) {
                    $rate = $fallbackRates[$rateKey];
                } elseif (isset($fallbackRates[$reverseRateKey])) {
                    $rate = 1 / $fallbackRates[$reverseRateKey];
                } else {
                    // Ultimate fallback: assume 1:1
                    if (function_exists('app_log')) {
                        app_log('warning', 'Exchange rate not found, using 1:1 conversion', [
                            'from' => $fromCurrency,
                            'to' => $toCurrency
                        ]);
                    }
                    return $amount;
                }
            }
        }

        return round($amount * $rate, 2);
    }

    /**
     * Validate game data
     *
     * @param array<string, mixed> $data Game data
     * @param bool $isCreate Is this for creation (requires all fields)
     * @return void
     * @throws Exception
     */
    private function validateGameData(array $data, bool $isCreate = true): void
    {
        if ($isCreate) {
            $required = ['name', 'prize_value', 'currency', 'max_players', 'entry_fee'];
            foreach ($required as $field) {
                if (!isset($data[$field]) || $data[$field] === '') {
                    throw new Exception("Required field missing: $field");
                }
            }
        }

        // Validate name
        if (isset($data['name'])) {
            if (strlen($data['name']) < 3 || strlen($data['name']) > 255) {
                throw new Exception('Game name must be between 3 and 255 characters');
            }
        }

        // Validate slug uniqueness if provided
        if (isset($data['slug'])) {
            if (!preg_match('/^[a-z0-9\-]+$/', $data['slug'])) {
                throw new Exception('Slug can only contain lowercase letters, numbers, and hyphens');
            }

            if ($this->slugExists($data['slug'])) {
                throw new Exception('Slug already exists: ' . $data['slug']);
            }
        }

        // Validate currency
        if (isset($data['currency'])) {
            if (!in_array(strtoupper($data['currency']), self::SUPPORTED_CURRENCIES)) {
                throw new Exception('Unsupported currency: ' . $data['currency']);
            }
        }

        // Validate numeric fields
        if (isset($data['prize_value'])) {
            if (!is_numeric($data['prize_value']) || $data['prize_value'] <= 0) {
                throw new Exception('Prize value must be a positive number');
            }
        }

        if (isset($data['entry_fee'])) {
            if (!is_numeric($data['entry_fee']) || $data['entry_fee'] <= 0) {
                throw new Exception('Entry fee must be a positive number');
            }
        }

        if (isset($data['max_players'])) {
            if (!is_int($data['max_players']) || $data['max_players'] < 1 || $data['max_players'] > 10000) {
                throw new Exception('Max players must be between 1 and 10,000');
            }
        }

        // Validate boolean fields
        if (isset($data['auto_restart'])) {
            $data['auto_restart'] = filter_var($data['auto_restart'], FILTER_VALIDATE_BOOLEAN);
        }

        // Validate status
        if (isset($data['status'])) {
            $validStatuses = [self::STATUS_ACTIVE, self::STATUS_PAUSED, self::STATUS_ARCHIVED];
            if (!in_array($data['status'], $validStatuses)) {
                throw new Exception('Invalid status: ' . $data['status']);
            }
        }

        // Validate question settings
        if (isset($data['total_questions'])) {
            if (!is_int($data['total_questions']) || $data['total_questions'] < 3 || $data['total_questions'] > 50) {
                throw new Exception('Total questions must be between 3 and 50');
            }
        }

        if (isset($data['free_questions'])) {
            if (!is_int($data['free_questions']) || $data['free_questions'] < 0) {
                throw new Exception('Free questions must be 0 or more');
            }

            if (isset($data['total_questions']) && $data['free_questions'] >= $data['total_questions']) {
                throw new Exception('Free questions must be less than total questions');
            }
        }

        if (isset($data['question_timeout'])) {
            if (!is_int($data['question_timeout']) || $data['question_timeout'] < 5 || $data['question_timeout'] > 300) {
                throw new Exception('Question timeout must be between 5 and 300 seconds');
            }
        }
    }

    /**
     * Get sort order SQL clause
     *
     * @param string $sort Sort parameter
     * @return string SQL ORDER BY clause
     */
    private function getSortOrder(string $sort): string
    {
        $sortOptions = [
            'created_desc' => 'ORDER BY g.created_at DESC',
            'created_asc' => 'ORDER BY g.created_at ASC',
            'name_asc' => 'ORDER BY g.name ASC',
            'name_desc' => 'ORDER BY g.name DESC',
            'participants_desc' => 'ORDER BY paid_participants DESC',
            'participants_asc' => 'ORDER BY paid_participants ASC',
            'revenue_desc' => 'ORDER BY total_revenue DESC',
            'revenue_asc' => 'ORDER BY total_revenue ASC'
        ];

        return $sortOptions[$sort] ?? $sortOptions['created_desc'];
    }
}
