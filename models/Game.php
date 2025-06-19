<?php

/**
 * Win a Brand New - Game Model
 * File: /models/Game.php
 *
 * Handles game management, CRUD operations, currency management,
 * and auto-restart logic according to the Development Specification.
 *
 * Features:
 * - Game CRUD operations with validation
 * - Multi-currency support with exchange rate integration
 * - Auto-restart functionality for rounds
 * - Prize value management
 * - Game settings and configuration
 * - Statistics and analytics integration
 * - Slug-based routing support
 *
 * @package WinABrandNew\Models
 * @version 1.0.0
 * @author NerdMarcel Development Team
 */

namespace WinABrandNew\Models;

use WinABrandNew\Core\Database;
use WinABrandNew\Core\Config;
use WinABrandNew\Models\Round;
use WinABrandNew\Models\Question;
use WinABrandNew\Models\ExchangeRate;
use Exception;
use InvalidArgumentException;

class Game
{
    /**
     * Database table name
     */
    private const TABLE = 'games';

    /**
     * Game properties
     */
    private ?int $id = null;
    private ?string $name = null;
    private ?string $slug = null;
    private ?string $description = null;
    private ?float $prize_value = null;
    private ?string $currency = null;
    private ?int $max_players = null;
    private ?float $entry_fee = null;
    private ?float $entry_fee_usd = null;
    private ?float $entry_fee_eur = null;
    private ?float $entry_fee_gbp = null;
    private ?float $entry_fee_cad = null;
    private ?float $entry_fee_aud = null;
    private ?bool $auto_restart = null;
    private ?string $status = null;
    private ?bool $featured = null;
    private ?string $meta_title = null;
    private ?string $meta_description = null;
    private ?string $created_at = null;
    private ?string $updated_at = null;

    /**
     * Supported currencies
     */
    private const SUPPORTED_CURRENCIES = ['GBP', 'EUR', 'USD', 'CAD', 'AUD'];

    /**
     * Valid game statuses
     */
    private const VALID_STATUSES = ['active', 'paused', 'completed', 'disabled'];

    /**
     * Default values
     */
    private const DEFAULTS = [
        'currency' => 'GBP',
        'max_players' => 1000,
        'auto_restart' => true,
        'status' => 'active',
        'featured' => false
    ];

    /**
     * Constructor
     *
     * @param array $data Initial data
     */
    public function __construct(array $data = [])
    {
        $this->fill($data);
    }

    /**
     * Fill model with data
     *
     * @param array $data
     * @return self
     */
    public function fill(array $data): self
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
        return $this;
    }

    /**
     * Convert model to array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'prize_value' => $this->prize_value,
            'currency' => $this->currency,
            'max_players' => $this->max_players,
            'entry_fee' => $this->entry_fee,
            'entry_fee_usd' => $this->entry_fee_usd,
            'entry_fee_eur' => $this->entry_fee_eur,
            'entry_fee_gbp' => $this->entry_fee_gbp,
            'entry_fee_cad' => $this->entry_fee_cad,
            'entry_fee_aud' => $this->entry_fee_aud,
            'auto_restart' => $this->auto_restart,
            'status' => $this->status,
            'featured' => $this->featured,
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        ];
    }

    /**
     * Validate game data
     *
     * @return array Validation errors
     */
    public function validate(): array
    {
        $errors = [];

        // Required fields
        if (empty($this->name)) {
            $errors['name'] = 'Game name is required';
        }

        if (empty($this->slug)) {
            $errors['slug'] = 'Game slug is required';
        } elseif (!$this->isValidSlug($this->slug)) {
            $errors['slug'] = 'Game slug must contain only letters, numbers, and hyphens';
        }

        if (empty($this->prize_value) || $this->prize_value <= 0) {
            $errors['prize_value'] = 'Prize value must be greater than 0';
        }

        if (empty($this->entry_fee) || $this->entry_fee <= 0) {
            $errors['entry_fee'] = 'Entry fee must be greater than 0';
        }

        // Currency validation
        if (!empty($this->currency) && !in_array($this->currency, self::SUPPORTED_CURRENCIES)) {
            $errors['currency'] = 'Currency must be one of: ' . implode(', ', self::SUPPORTED_CURRENCIES);
        }

        // Status validation
        if (!empty($this->status) && !in_array($this->status, self::VALID_STATUSES)) {
            $errors['status'] = 'Status must be one of: ' . implode(', ', self::VALID_STATUSES);
        }

        // Max players validation
        if (!empty($this->max_players) && ($this->max_players < 1 || $this->max_players > 10000)) {
            $errors['max_players'] = 'Max players must be between 1 and 10000';
        }

        // Slug uniqueness check (if updating)
        if (!empty($this->slug)) {
            $existing = self::findBySlug($this->slug);
            if ($existing && (!$this->id || $existing->getId() !== $this->id)) {
                $errors['slug'] = 'Game slug already exists';
            }
        }

        return $errors;
    }

    /**
     * Validate slug format
     *
     * @param string $slug
     * @return bool
     */
    private function isValidSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9\-]+$/', $slug) === 1;
    }

    /**
     * Save game to database
     *
     * @return bool
     * @throws Exception
     */
    public function save(): bool
    {
        // Validate data
        $errors = $this->validate();
        if (!empty($errors)) {
            throw new InvalidArgumentException('Validation failed: ' . implode(', ', $errors));
        }

        // Set defaults
        $this->applyDefaults();

        // Calculate currency fees
        $this->calculateCurrencyFees();

        if ($this->id) {
            return $this->update();
        } else {
            return $this->insert();
        }
    }

    /**
     * Apply default values
     */
    private function applyDefaults(): void
    {
        foreach (self::DEFAULTS as $key => $value) {
            if ($this->{$key} === null) {
                $this->{$key} = $value;
            }
        }

        // Generate slug if not provided
        if (empty($this->slug) && !empty($this->name)) {
            $this->slug = $this->generateSlug($this->name);
        }

        // Generate meta title if not provided
        if (empty($this->meta_title) && !empty($this->name)) {
            $this->meta_title = "Win a " . $this->name . " - Competition";
        }
    }

    /**
     * Generate slug from name
     *
     * @param string $name
     * @return string
     */
    private function generateSlug(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9\s\-]/', '', $slug);
        $slug = preg_replace('/[\s\-]+/', '-', $slug);
        $slug = trim($slug, '-');

        // Ensure uniqueness
        $originalSlug = $slug;
        $counter = 1;
        while (self::findBySlug($slug)) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Calculate currency fees based on exchange rates
     *
     * @throws Exception
     */
    private function calculateCurrencyFees(): void
    {
        if (empty($this->entry_fee) || empty($this->currency)) {
            return;
        }

        $baseCurrency = $this->currency;
        $baseAmount = $this->entry_fee;

        // Calculate fees for all supported currencies
        foreach (self::SUPPORTED_CURRENCIES as $targetCurrency) {
            if ($targetCurrency === $baseCurrency) {
                $this->{"entry_fee_" . strtolower($targetCurrency)} = $baseAmount;
                continue;
            }

            try {
                $rate = ExchangeRate::getRate($baseCurrency, $targetCurrency);
                $convertedAmount = round($baseAmount * $rate, 2);
                $this->{"entry_fee_" . strtolower($targetCurrency)} = $convertedAmount;
            } catch (Exception $e) {
                // Log error but don't fail the save operation
                error_log("Failed to convert currency from {$baseCurrency} to {$targetCurrency}: " . $e->getMessage());
            }
        }
    }

    /**
     * Insert new game
     *
     * @return bool
     * @throws Exception
     */
    private function insert(): bool
    {
        $sql = "INSERT INTO " . self::TABLE . " (
            name, slug, description, prize_value, currency, max_players,
            entry_fee, entry_fee_usd, entry_fee_eur, entry_fee_gbp,
            entry_fee_cad, entry_fee_aud, auto_restart, status, featured,
            meta_title, meta_description, created_at, updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
        )";

        $params = [
            $this->name,
            $this->slug,
            $this->description,
            $this->prize_value,
            $this->currency,
            $this->max_players,
            $this->entry_fee,
            $this->entry_fee_usd,
            $this->entry_fee_eur,
            $this->entry_fee_gbp,
            $this->entry_fee_cad,
            $this->entry_fee_aud,
            $this->auto_restart ? 1 : 0,
            $this->status,
            $this->featured ? 1 : 0,
            $this->meta_title,
            $this->meta_description
        ];

        $this->id = (int) Database::insert($sql, $params);
        return $this->id > 0;
    }

    /**
     * Update existing game
     *
     * @return bool
     * @throws Exception
     */
    private function update(): bool
    {
        $sql = "UPDATE " . self::TABLE . " SET
            name = ?, slug = ?, description = ?, prize_value = ?, currency = ?,
            max_players = ?, entry_fee = ?, entry_fee_usd = ?, entry_fee_eur = ?,
            entry_fee_gbp = ?, entry_fee_cad = ?, entry_fee_aud = ?, auto_restart = ?,
            status = ?, featured = ?, meta_title = ?, meta_description = ?,
            updated_at = NOW()
        WHERE id = ?";

        $params = [
            $this->name,
            $this->slug,
            $this->description,
            $this->prize_value,
            $this->currency,
            $this->max_players,
            $this->entry_fee,
            $this->entry_fee_usd,
            $this->entry_fee_eur,
            $this->entry_fee_gbp,
            $this->entry_fee_cad,
            $this->entry_fee_aud,
            $this->auto_restart ? 1 : 0,
            $this->status,
            $this->featured ? 1 : 0,
            $this->meta_title,
            $this->meta_description,
            $this->id
        ];

        return Database::update($sql, $params) > 0;
    }

    /**
     * Delete game
     *
     * @return bool
     * @throws Exception
     */
    public function delete(): bool
    {
        if (!$this->id) {
            throw new Exception("Cannot delete game without ID");
        }

        // Check if game has active rounds
        $activeRounds = Round::countByGameId($this->id, ['active', 'full']);
        if ($activeRounds > 0) {
            throw new Exception("Cannot delete game with active rounds");
        }

        $sql = "DELETE FROM " . self::TABLE . " WHERE id = ?";
        return Database::delete($sql, [$this->id]) > 0;
    }

    /**
     * Find game by ID
     *
     * @param int $id
     * @return self|null
     */
    public static function find(int $id): ?self
    {
        $sql = "SELECT * FROM " . self::TABLE . " WHERE id = ?";
        $data = Database::selectOne($sql, [$id]);

        return $data ? new self($data) : null;
    }

    /**
     * Find game by slug
     *
     * @param string $slug
     * @return self|null
     */
    public static function findBySlug(string $slug): ?self
    {
        $sql = "SELECT * FROM " . self::TABLE . " WHERE slug = ?";
        $data = Database::selectOne($sql, [$slug]);

        return $data ? new self($data) : null;
    }

    /**
     * Get all games with optional filters
     *
     * @param array $filters
     * @param string $orderBy
     * @param int|null $limit
     * @param int $offset
     * @return array
     */
    public static function getAll(array $filters = [], string $orderBy = 'created_at DESC', ?int $limit = null, int $offset = 0): array
    {
        $sql = "SELECT * FROM " . self::TABLE;
        $params = [];

        // Apply filters
        if (!empty($filters)) {
            [$whereClause, $whereParams] = Database::buildWhereClause($filters);
            $sql .= " " . $whereClause;
            $params = array_merge($params, $whereParams);
        }

        // Add ordering
        $sql .= " ORDER BY " . $orderBy;

        // Add limit and offset
        if ($limit) {
            $sql .= " LIMIT " . $limit . " OFFSET " . $offset;
        }

        $results = Database::select($sql, $params);
        return array_map(fn($data) => new self($data), $results);
    }

    /**
     * Get featured games
     *
     * @param int $limit
     * @return array
     */
    public static function getFeatured(int $limit = 5): array
    {
        return self::getAll(['featured' => 1, 'status' => 'active'], 'created_at DESC', $limit);
    }

    /**
     * Get active games
     *
     * @return array
     */
    public static function getActive(): array
    {
        return self::getAll(['status' => 'active'], 'created_at DESC');
    }

    /**
     * Count games with filters
     *
     * @param array $filters
     * @return int
     */
    public static function count(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as count FROM " . self::TABLE;
        $params = [];

        if (!empty($filters)) {
            [$whereClause, $whereParams] = Database::buildWhereClause($filters);
            $sql .= " " . $whereClause;
            $params = array_merge($params, $whereParams);
        }

        $result = Database::selectOne($sql, $params);
        return (int) $result['count'];
    }

    /**
     * Get current active round for this game
     *
     * @return Round|null
     */
    public function getCurrentRound(): ?Round
    {
        if (!$this->id) {
            return null;
        }

        return Round::getCurrentByGameId($this->id);
    }

    /**
     * Create new round for this game
     *
     * @return Round
     * @throws Exception
     */
    public function createNewRound(): Round
    {
        if (!$this->id) {
            throw new Exception("Cannot create round for unsaved game");
        }

        if ($this->status !== 'active') {
            throw new Exception("Cannot create round for inactive game");
        }

        return Round::create($this->id);
    }

    /**
     * Get questions for this game
     *
     * @return array
     */
    public function getQuestions(): array
    {
        if (!$this->id) {
            return [];
        }

        return Question::getByGameId($this->id);
    }

    /**
     * Get total questions count
     *
     * @return int
     */
    public function getQuestionsCount(): int
    {
        if (!$this->id) {
            return 0;
        }

        return Question::countByGameId($this->id);
    }

    /**
     * Check if game has sufficient questions (minimum 27 for optimal experience)
     *
     * @return bool
     */
    public function hasSufficientQuestions(): bool
    {
        return $this->getQuestionsCount() >= 27;
    }

    /**
     * Get game statistics
     *
     * @return array
     */
    public function getStatistics(): array
    {
        if (!$this->id) {
            return [];
        }

        return [
            'total_rounds' => Round::countByGameId($this->id),
            'active_rounds' => Round::countByGameId($this->id, ['active']),
            'completed_rounds' => Round::countByGameId($this->id, ['completed']),
            'total_participants' => $this->getTotalParticipants(),
            'total_revenue' => $this->getTotalRevenue(),
            'questions_count' => $this->getQuestionsCount(),
            'sufficient_questions' => $this->hasSufficientQuestions()
        ];
    }

    /**
     * Get total participants across all rounds
     *
     * @return int
     */
    private function getTotalParticipants(): int
    {
        if (!$this->id) {
            return 0;
        }

        $sql = "SELECT COUNT(p.id) as count
                FROM participants p
                JOIN rounds r ON p.round_id = r.id
                WHERE r.game_id = ?";

        $result = Database::selectOne($sql, [$this->id]);
        return (int) $result['count'];
    }

    /**
     * Get total revenue across all rounds
     *
     * @return float
     */
    private function getTotalRevenue(): float
    {
        if (!$this->id) {
            return 0.0;
        }

        $sql = "SELECT SUM(p.payment_amount) as total
                FROM participants p
                JOIN rounds r ON p.round_id = r.id
                WHERE r.game_id = ? AND p.payment_status = 'paid'";

        $result = Database::selectOne($sql, [$this->id]);
        return (float) ($result['total'] ?? 0);
    }

    /**
     * Get entry fee for specific currency
     *
     * @param string $currency
     * @return float|null
     */
    public function getEntryFeeForCurrency(string $currency): ?float
    {
        $currency = strtoupper($currency);

        if (!in_array($currency, self::SUPPORTED_CURRENCIES)) {
            return null;
        }

        $property = 'entry_fee_' . strtolower($currency);
        return $this->{$property} ?? $this->entry_fee;
    }

    /**
     * Format currency display
     *
     * @param float $amount
     * @param string $currency
     * @return string
     */
    public static function formatCurrency(float $amount, string $currency): string
    {
        $symbols = [
            'GBP' => '£',
            'EUR' => '€',
            'USD' => '$',
            'CAD' => 'CA$',
            'AUD' => 'AU$'
        ];

        $symbol = $symbols[$currency] ?? $currency . ' ';
        return $symbol . number_format($amount, 2);
    }

    /**
     * Check if auto-restart should trigger for a completed round
     *
     * @param Round $completedRound
     * @return bool
     */
    public function shouldAutoRestart(Round $completedRound): bool
    {
        return $this->auto_restart &&
               $this->status === 'active' &&
               $completedRound->getGameId() === $this->id;
    }

    // Getters and Setters

    public function getId(): ?int { return $this->id; }
    public function getName(): ?string { return $this->name; }
    public function getSlug(): ?string { return $this->slug; }
    public function getDescription(): ?string { return $this->description; }
    public function getPrizeValue(): ?float { return $this->prize_value; }
    public function getCurrency(): ?string { return $this->currency; }
    public function getMaxPlayers(): ?int { return $this->max_players; }
    public function getEntryFee(): ?float { return $this->entry_fee; }
    public function getAutoRestart(): ?bool { return $this->auto_restart; }
    public function getStatus(): ?string { return $this->status; }
    public function getFeatured(): ?bool { return $this->featured; }
    public function getMetaTitle(): ?string { return $this->meta_title; }
    public function getMetaDescription(): ?string { return $this->meta_description; }
    public function getCreatedAt(): ?string { return $this->created_at; }
    public function getUpdatedAt(): ?string { return $this->updated_at; }

    public function setName(string $name): self { $this->name = $name; return $this; }
    public function setSlug(string $slug): self { $this->slug = $slug; return $this; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }
    public function setPrizeValue(float $prize_value): self { $this->prize_value = $prize_value; return $this; }
    public function setCurrency(string $currency): self { $this->currency = strtoupper($currency); return $this; }
    public function setMaxPlayers(int $max_players): self { $this->max_players = $max_players; return $this; }
    public function setEntryFee(float $entry_fee): self { $this->entry_fee = $entry_fee; return $this; }
    public function setAutoRestart(bool $auto_restart): self { $this->auto_restart = $auto_restart; return $this; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function setFeatured(bool $featured): self { $this->featured = $featured; return $this; }
    public function setMetaTitle(?string $meta_title): self { $this->meta_title = $meta_title; return $this; }
    public function setMetaDescription(?string $meta_description): self { $this->meta_description = $meta_description; return $this; }
}
