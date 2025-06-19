<?php

/**
 * Win a Brand New - Question Model
 * File: /models/Question.php
 *
 * Manages quiz questions with unique selection algorithm and
 * participant question history tracking according to the Development Specification.
 *
 * Features:
 * - Unique question selection algorithm (prevents repeat questions until all seen)
 * - Question pool management with minimum 27 questions per game
 * - Participant question history tracking per user email + game
 * - Random question order generation
 * - Question difficulty management
 * - Performance optimized with proper indexing
 * - Reset cycle when all questions have been seen
 *
 * Question Selection Logic:
 * 1. Query all questions for the game that user hasn't seen
 * 2. If unseen questions ≥ 9: randomly select 9 from unseen pool
 * 3. If unseen questions < 9: include all unseen + random selection from seen
 * 4. If user has seen all questions: completely random selection (reset cycle)
 *
 * @package WinABrandNew\Models
 * @version 1.0.0
 * @author NerdMarcel Development Team
 */

namespace WinABrandNew\Models;

use WinABrandNew\Core\Database;
use WinABrandNew\Core\Security;
use Exception;

class Question
{
    /**
     * Table name
     */
    private const TABLE = 'questions';

    /**
     * Question history table
     */
    private const HISTORY_TABLE = 'participant_question_history';

    /**
     * Minimum recommended questions per game for optimal variety
     */
    private const MIN_QUESTIONS_PER_GAME = 27;

    /**
     * Number of questions per game session
     */
    private const QUESTIONS_PER_GAME = 9;

    /**
     * Question difficulty levels
     */
    private const DIFFICULTY_LEVELS = ['easy', 'medium', 'hard'];

    /**
     * Question properties
     */
    private array $data = [];

    /**
     * Constructor
     *
     * @param array $data Question data
     */
    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    /**
     * Create a new question
     *
     * @param array $questionData Question data
     * @return string Question ID
     * @throws Exception If creation fails
     */
    public static function create(array $questionData): string
    {
        // Validate required fields
        $required = ['game_id', 'question_order', 'question_text', 'option_a', 'option_b', 'option_c', 'correct_answer'];
        foreach ($required as $field) {
            if (empty($questionData[$field])) {
                throw new Exception("Required field '{$field}' is missing");
            }
        }

        // Validate correct answer
        if (!in_array($questionData['correct_answer'], ['A', 'B', 'C'])) {
            throw new Exception("Correct answer must be A, B, or C");
        }

        // Validate difficulty level
        if (isset($questionData['difficulty_level']) &&
            !in_array($questionData['difficulty_level'], self::DIFFICULTY_LEVELS)) {
            throw new Exception("Invalid difficulty level");
        }

        // Sanitize inputs
        $questionData['question_text'] = Security::sanitizeInput($questionData['question_text']);
        $questionData['option_a'] = Security::sanitizeInput($questionData['option_a']);
        $questionData['option_b'] = Security::sanitizeInput($questionData['option_b']);
        $questionData['option_c'] = Security::sanitizeInput($questionData['option_c']);

        if (isset($questionData['explanation'])) {
            $questionData['explanation'] = Security::sanitizeInput($questionData['explanation']);
        }

        // Set defaults
        $questionData['difficulty_level'] = $questionData['difficulty_level'] ?? 'medium';
        $questionData['active'] = $questionData['active'] ?? 1;
        $questionData['created_at'] = date('Y-m-d H:i:s');
        $questionData['updated_at'] = date('Y-m-d H:i:s');

        // Build insert query
        $fields = array_keys($questionData);
        $placeholders = ':' . implode(', :', $fields);
        $fieldsList = '`' . implode('`, `', $fields) . '`';

        $sql = "INSERT INTO " . self::TABLE . " ({$fieldsList}) VALUES ({$placeholders})";

        return Database::insert($sql, $questionData);
    }

    /**
     * Get question by ID
     *
     * @param int $questionId Question ID
     * @return Question|null
     * @throws Exception If query fails
     */
    public static function findById(int $questionId): ?Question
    {
        $sql = "SELECT * FROM " . self::TABLE . " WHERE id = ? AND active = 1";
        $data = Database::selectOne($sql, [$questionId]);

        return $data ? new self($data) : null;
    }

    /**
     * Get all questions for a game
     *
     * @param int $gameId Game ID
     * @param bool $activeOnly Only active questions
     * @return array Array of Question objects
     * @throws Exception If query fails
     */
    public static function findByGameId(int $gameId, bool $activeOnly = true): array
    {
        $sql = "SELECT * FROM " . self::TABLE . " WHERE game_id = ?";
        $params = [$gameId];

        if ($activeOnly) {
            $sql .= " AND active = 1";
        }

        $sql .= " ORDER BY question_order ASC";

        $results = Database::select($sql, $params);

        return array_map(function($data) {
            return new self($data);
        }, $results);
    }

    /**
     * Get unique questions for a participant (Core Algorithm)
     *
     * This implements the unique question selection algorithm:
     * 1. Find all questions user hasn't seen for this game
     * 2. If enough unseen questions: select 9 randomly from unseen
     * 3. If not enough unseen: include all unseen + random from seen
     * 4. If all seen: completely random selection (reset cycle)
     *
     * @param int $gameId Game ID
     * @param string $userEmail User email
     * @param int $participantId Optional participant ID for tracking
     * @return array Array of 9 Question objects in random order
     * @throws Exception If query fails or insufficient questions
     */
    public static function getUniqueQuestionsForParticipant(
        int $gameId,
        string $userEmail,
        ?int $participantId = null
    ): array {
        // Validate email
        if (!filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email address");
        }

        // Get all questions that user hasn't seen for this game
        $unseenQuestions = self::getUnseenQuestions($gameId, $userEmail);

        // Get total questions available for the game
        $totalQuestions = self::countQuestionsByGameId($gameId);

        // Check if game has minimum recommended questions
        if ($totalQuestions < self::MIN_QUESTIONS_PER_GAME) {
            error_log("Warning: Game {$gameId} has only {$totalQuestions} questions. Recommended minimum: " . self::MIN_QUESTIONS_PER_GAME);
        }

        // Check if we have any questions at all
        if ($totalQuestions < self::QUESTIONS_PER_GAME) {
            throw new Exception("Game must have at least " . self::QUESTIONS_PER_GAME . " questions");
        }

        $selectedQuestions = [];

        if (count($unseenQuestions) >= self::QUESTIONS_PER_GAME) {
            // Case 1: Enough unseen questions - select 9 randomly from unseen pool
            $selectedQuestions = self::selectRandomQuestions($unseenQuestions, self::QUESTIONS_PER_GAME);

        } elseif (count($unseenQuestions) > 0) {
            // Case 2: Some unseen questions - include all unseen + random from seen
            $selectedQuestions = $unseenQuestions;

            $neededQuestions = self::QUESTIONS_PER_GAME - count($unseenQuestions);
            $seenQuestions = self::getSeenQuestions($gameId, $userEmail);

            if (!empty($seenQuestions)) {
                $additionalQuestions = self::selectRandomQuestions($seenQuestions, $neededQuestions);
                $selectedQuestions = array_merge($selectedQuestions, $additionalQuestions);
            }

        } else {
            // Case 3: All questions seen - completely random selection (reset cycle)
            $allQuestions = self::findByGameId($gameId, true);

            if (count($allQuestions) < self::QUESTIONS_PER_GAME) {
                throw new Exception("Insufficient active questions for this game");
            }

            $selectedQuestions = self::selectRandomQuestions($allQuestions, self::QUESTIONS_PER_GAME);
        }

        // Randomize the final order
        shuffle($selectedQuestions);

        // Track selected questions in history if participant ID provided
        if ($participantId) {
            self::trackQuestionHistory($userEmail, $gameId, $selectedQuestions, $participantId);
        }

        return $selectedQuestions;
    }

    /**
     * Get unseen questions for a user in a specific game
     *
     * @param int $gameId Game ID
     * @param string $userEmail User email
     * @return array Array of Question objects
     * @throws Exception If query fails
     */
    private static function getUnseenQuestions(int $gameId, string $userEmail): array
    {
        $sql = "
            SELECT q.*
            FROM " . self::TABLE . " q
            LEFT JOIN " . self::HISTORY_TABLE . " h ON (
                q.id = h.question_id
                AND h.user_email = ?
                AND h.game_id = ?
            )
            WHERE q.game_id = ?
            AND q.active = 1
            AND h.question_id IS NULL
            ORDER BY q.question_order ASC
        ";

        $results = Database::select($sql, [$userEmail, $gameId, $gameId]);

        return array_map(function($data) {
            return new self($data);
        }, $results);
    }

    /**
     * Get seen questions for a user in a specific game
     *
     * @param int $gameId Game ID
     * @param string $userEmail User email
     * @return array Array of Question objects
     * @throws Exception If query fails
     */
    private static function getSeenQuestions(int $gameId, string $userEmail): array
    {
        $sql = "
            SELECT q.*
            FROM " . self::TABLE . " q
            INNER JOIN " . self::HISTORY_TABLE . " h ON (
                q.id = h.question_id
                AND h.user_email = ?
                AND h.game_id = ?
            )
            WHERE q.game_id = ?
            AND q.active = 1
            ORDER BY h.seen_at DESC
        ";

        $results = Database::select($sql, [$userEmail, $gameId, $gameId]);

        return array_map(function($data) {
            return new self($data);
        }, $results);
    }

    /**
     * Randomly select questions from an array
     *
     * @param array $questions Array of Question objects
     * @param int $count Number of questions to select
     * @return array Array of selected Question objects
     */
    private static function selectRandomQuestions(array $questions, int $count): array
    {
        if (count($questions) <= $count) {
            return $questions;
        }

        $randomKeys = array_rand($questions, $count);

        // array_rand returns single value if $count = 1, ensure it's always an array
        if (!is_array($randomKeys)) {
            $randomKeys = [$randomKeys];
        }

        $selectedQuestions = [];
        foreach ($randomKeys as $key) {
            $selectedQuestions[] = $questions[$key];
        }

        return $selectedQuestions;
    }

    /**
     * Track question history for participant
     *
     * @param string $userEmail User email
     * @param int $gameId Game ID
     * @param array $questions Array of Question objects
     * @param int $participantId Participant ID
     * @return void
     * @throws Exception If tracking fails
     */
    private static function trackQuestionHistory(
        string $userEmail,
        int $gameId,
        array $questions,
        int $participantId
    ): void {
        Database::transaction(function() use ($userEmail, $gameId, $questions, $participantId) {
            foreach ($questions as $question) {
                $questionId = $question->getId();

                // Check if already tracked (prevent duplicates)
                $existsSql = "SELECT id FROM " . self::HISTORY_TABLE . "
                             WHERE user_email = ? AND game_id = ? AND question_id = ?";
                $exists = Database::selectOne($existsSql, [$userEmail, $gameId, $questionId]);

                if (!$exists) {
                    $historySql = "INSERT INTO " . self::HISTORY_TABLE . "
                                  (user_email, game_id, question_id, participant_id, seen_at)
                                  VALUES (?, ?, ?, ?, NOW())";
                    Database::execute($historySql, [$userEmail, $gameId, $questionId, $participantId]);
                }
            }
        });
    }

    /**
     * Count total questions for a game
     *
     * @param int $gameId Game ID
     * @param bool $activeOnly Count only active questions
     * @return int Number of questions
     * @throws Exception If query fails
     */
    public static function countQuestionsByGameId(int $gameId, bool $activeOnly = true): int
    {
        $sql = "SELECT COUNT(*) as count FROM " . self::TABLE . " WHERE game_id = ?";
        $params = [$gameId];

        if ($activeOnly) {
            $sql .= " AND active = 1";
        }

        $result = Database::selectOne($sql, $params);
        return (int) $result['count'];
    }

    /**
     * Update question
     *
     * @param int $questionId Question ID
     * @param array $updateData Data to update
     * @return bool Success status
     * @throws Exception If update fails
     */
    public static function update(int $questionId, array $updateData): bool
    {
        if (empty($updateData)) {
            return true;
        }

        // Validate correct answer if provided
        if (isset($updateData['correct_answer']) &&
            !in_array($updateData['correct_answer'], ['A', 'B', 'C'])) {
            throw new Exception("Correct answer must be A, B, or C");
        }

        // Validate difficulty level if provided
        if (isset($updateData['difficulty_level']) &&
            !in_array($updateData['difficulty_level'], self::DIFFICULTY_LEVELS)) {
            throw new Exception("Invalid difficulty level");
        }

        // Sanitize text inputs
        $textFields = ['question_text', 'option_a', 'option_b', 'option_c', 'explanation'];
        foreach ($textFields as $field) {
            if (isset($updateData[$field])) {
                $updateData[$field] = Security::sanitizeInput($updateData[$field]);
            }
        }

        // Add updated timestamp
        $updateData['updated_at'] = date('Y-m-d H:i:s');

        // Build update query
        $setParts = [];
        $params = [];

        foreach ($updateData as $field => $value) {
            $setParts[] = "`{$field}` = ?";
            $params[] = $value;
        }

        $params[] = $questionId;

        $sql = "UPDATE " . self::TABLE . " SET " . implode(', ', $setParts) . " WHERE id = ?";

        $affectedRows = Database::update($sql, $params);
        return $affectedRows > 0;
    }

    /**
     * Delete question (soft delete by setting active = 0)
     *
     * @param int $questionId Question ID
     * @return bool Success status
     * @throws Exception If deletion fails
     */
    public static function delete(int $questionId): bool
    {
        $sql = "UPDATE " . self::TABLE . " SET active = 0, updated_at = NOW() WHERE id = ?";
        $affectedRows = Database::update($sql, [$questionId]);

        return $affectedRows > 0;
    }

    /**
     * Permanently delete question from database
     *
     * @param int $questionId Question ID
     * @return bool Success status
     * @throws Exception If deletion fails
     */
    public static function permanentDelete(int $questionId): bool
    {
        return Database::transaction(function() use ($questionId) {
            // Delete question history first (foreign key constraint)
            $historyDeleteSql = "DELETE FROM " . self::HISTORY_TABLE . " WHERE question_id = ?";
            Database::delete($historyDeleteSql, [$questionId]);

            // Delete the question
            $questionDeleteSql = "DELETE FROM " . self::TABLE . " WHERE id = ?";
            $affectedRows = Database::delete($questionDeleteSql, [$questionId]);

            return $affectedRows > 0;
        });
    }

    /**
     * Bulk import questions for a game
     *
     * @param int $gameId Game ID
     * @param array $questionsData Array of question data
     * @return array Array of created question IDs
     * @throws Exception If import fails
     */
    public static function bulkImport(int $gameId, array $questionsData): array
    {
        return Database::transaction(function() use ($gameId, $questionsData) {
            $createdIds = [];

            foreach ($questionsData as $index => $questionData) {
                try {
                    $questionData['game_id'] = $gameId;
                    $questionData['question_order'] = $questionData['question_order'] ?? ($index + 1);

                    $questionId = self::create($questionData);
                    $createdIds[] = $questionId;

                } catch (Exception $e) {
                    throw new Exception("Failed to import question at index {$index}: " . $e->getMessage());
                }
            }

            return $createdIds;
        });
    }

    /**
     * Get question performance analytics
     *
     * @param int $questionId Question ID
     * @return array Performance statistics
     * @throws Exception If query fails
     */
    public static function getQuestionPerformance(int $questionId): array
    {
        $sql = "
            SELECT
                COUNT(p.id) as total_attempts,
                SUM(JSON_EXTRACT(p.answers_json, CONCAT('$[', q.question_order - 1, ']')) = q.correct_answer) as correct_answers,
                AVG(JSON_EXTRACT(p.question_times_json, CONCAT('$[', q.question_order - 1, ']'))) as avg_response_time,
                MIN(JSON_EXTRACT(p.question_times_json, CONCAT('$[', q.question_order - 1, ']'))) as min_response_time,
                MAX(JSON_EXTRACT(p.question_times_json, CONCAT('$[', q.question_order - 1, ']'))) as max_response_time
            FROM " . self::TABLE . " q
            LEFT JOIN participants p ON p.round_id IN (
                SELECT r.id FROM rounds r WHERE r.game_id = q.game_id
            )
            WHERE q.id = ?
            AND p.game_completed = 1
            GROUP BY q.id
        ";

        $result = Database::selectOne($sql, [$questionId]);

        if (!$result) {
            return [
                'total_attempts' => 0,
                'correct_answers' => 0,
                'success_rate' => 0,
                'avg_response_time' => 0,
                'min_response_time' => 0,
                'max_response_time' => 0
            ];
        }

        $totalAttempts = (int) $result['total_attempts'];
        $correctAnswers = (int) $result['correct_answers'];

        return [
            'total_attempts' => $totalAttempts,
            'correct_answers' => $correctAnswers,
            'success_rate' => $totalAttempts > 0 ? round(($correctAnswers / $totalAttempts) * 100, 2) : 0,
            'avg_response_time' => round((float) $result['avg_response_time'], 3),
            'min_response_time' => round((float) $result['min_response_time'], 3),
            'max_response_time' => round((float) $result['max_response_time'], 3)
        ];
    }

    /**
     * Get questions by difficulty level
     *
     * @param int $gameId Game ID
     * @param string $difficulty Difficulty level
     * @return array Array of Question objects
     * @throws Exception If query fails
     */
    public static function findByDifficulty(int $gameId, string $difficulty): array
    {
        if (!in_array($difficulty, self::DIFFICULTY_LEVELS)) {
            throw new Exception("Invalid difficulty level");
        }

        $sql = "SELECT * FROM " . self::TABLE . "
                WHERE game_id = ? AND difficulty_level = ? AND active = 1
                ORDER BY question_order ASC";

        $results = Database::select($sql, [$gameId, $difficulty]);

        return array_map(function($data) {
            return new self($data);
        }, $results);
    }

    /**
     * Reorder questions for a game
     *
     * @param int $gameId Game ID
     * @param array $questionOrder Array of question IDs in desired order
     * @return bool Success status
     * @throws Exception If reordering fails
     */
    public static function reorderQuestions(int $gameId, array $questionOrder): bool
    {
        return Database::transaction(function() use ($gameId, $questionOrder) {
            foreach ($questionOrder as $index => $questionId) {
                $order = $index + 1;
                $sql = "UPDATE " . self::TABLE . "
                        SET question_order = ?, updated_at = NOW()
                        WHERE id = ? AND game_id = ?";

                Database::update($sql, [$order, $questionId, $gameId]);
            }

            return true;
        });
    }

    /**
     * Clear question history for a user (reset their seen questions)
     *
     * @param string $userEmail User email
     * @param int|null $gameId Optional game ID (if null, clears for all games)
     * @return int Number of history records deleted
     * @throws Exception If deletion fails
     */
    public static function clearQuestionHistory(string $userEmail, ?int $gameId = null): int
    {
        $sql = "DELETE FROM " . self::HISTORY_TABLE . " WHERE user_email = ?";
        $params = [$userEmail];

        if ($gameId !== null) {
            $sql .= " AND game_id = ?";
            $params[] = $gameId;
        }

        return Database::delete($sql, $params);
    }

    /**
     * Get question history for a user
     *
     * @param string $userEmail User email
     * @param int|null $gameId Optional game ID filter
     * @return array Question history data
     * @throws Exception If query fails
     */
    public static function getQuestionHistory(string $userEmail, ?int $gameId = null): array
    {
        $sql = "
            SELECT h.*, q.question_text, g.name as game_name
            FROM " . self::HISTORY_TABLE . " h
            INNER JOIN " . self::TABLE . " q ON h.question_id = q.id
            INNER JOIN games g ON h.game_id = g.id
            WHERE h.user_email = ?
        ";

        $params = [$userEmail];

        if ($gameId !== null) {
            $sql .= " AND h.game_id = ?";
            $params[] = $gameId;
        }

        $sql .= " ORDER BY h.seen_at DESC";

        return Database::select($sql, $params);
    }

    /**
     * Get questions that need quality review (poor performance)
     *
     * @param int $gameId Game ID
     * @param float $maxSuccessRate Maximum success rate threshold (default 20%)
     * @return array Array of questions with poor performance
     * @throws Exception If query fails
     */
    public static function getQuestionsNeedingReview(int $gameId, float $maxSuccessRate = 20.0): array
    {
        // This is a simplified version - in production you'd want more sophisticated analytics
        $sql = "
            SELECT q.*,
                   COUNT(p.id) as total_attempts,
                   SUM(JSON_EXTRACT(p.answers_json, CONCAT('$[', q.question_order - 1, ']')) = q.correct_answer) as correct_answers
            FROM " . self::TABLE . " q
            LEFT JOIN participants p ON p.round_id IN (
                SELECT r.id FROM rounds r WHERE r.game_id = q.game_id
            )
            WHERE q.game_id = ? AND q.active = 1
            AND p.game_completed = 1
            GROUP BY q.id
            HAVING total_attempts >= 10
            AND (correct_answers / total_attempts * 100) <= ?
            ORDER BY (correct_answers / total_attempts * 100) ASC
        ";

        return Database::select($sql, [$gameId, $maxSuccessRate]);
    }

    // =====================================
    // GETTER AND SETTER METHODS
    // =====================================

    /**
     * Get question ID
     *
     * @return int|null
     */
    public function getId(): ?int
    {
        return isset($this->data['id']) ? (int) $this->data['id'] : null;
    }

    /**
     * Get game ID
     *
     * @return int|null
     */
    public function getGameId(): ?int
    {
        return isset($this->data['game_id']) ? (int) $this->data['game_id'] : null;
    }

    /**
     * Get question order
     *
     * @return int|null
     */
    public function getQuestionOrder(): ?int
    {
        return isset($this->data['question_order']) ? (int) $this->data['question_order'] : null;
    }

    /**
     * Get question text
     *
     * @return string|null
     */
    public function getQuestionText(): ?string
    {
        return $this->data['question_text'] ?? null;
    }

    /**
     * Get option A
     *
     * @return string|null
     */
    public function getOptionA(): ?string
    {
        return $this->data['option_a'] ?? null;
    }

    /**
     * Get option B
     *
     * @return string|null
     */
    public function getOptionB(): ?string
    {
        return $this->data['option_b'] ?? null;
    }

    /**
     * Get option C
     *
     * @return string|null
     */
    public function getOptionC(): ?string
    {
        return $this->data['option_c'] ?? null;
    }

    /**
     * Get correct answer
     *
     * @return string|null
     */
    public function getCorrectAnswer(): ?string
    {
        return $this->data['correct_answer'] ?? null;
    }

    /**
     * Get difficulty level
     *
     * @return string|null
     */
    public function getDifficultyLevel(): ?string
    {
        return $this->data['difficulty_level'] ?? null;
    }

    /**
     * Get explanation
     *
     * @return string|null
     */
    public function getExplanation(): ?string
    {
        return $this->data['explanation'] ?? null;
    }

    /**
     * Get image URL
     *
     * @return string|null
     */
    public function getImageUrl(): ?string
    {
        return $this->data['image_url'] ?? null;
    }

    /**
     * Check if question is active
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return (bool) ($this->data['active'] ?? false);
    }

    /**
     * Get created at timestamp
     *
     * @return string|null
     */
    public function getCreatedAt(): ?string
    {
        return $this->data['created_at'] ?? null;
    }

    /**
     * Get updated at timestamp
     *
     * @return string|null
     */
    public function getUpdatedAt(): ?string
    {
        return $this->data['updated_at'] ?? null;
    }

    /**
     * Get all question data as array
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Get question data formatted for JSON response
     *
     * @param bool $includeCorrectAnswer Whether to include correct answer
     * @return array
     */
    public function toJsonArray(bool $includeCorrectAnswer = false): array
    {
        $data = [
            'id' => $this->getId(),
            'question_order' => $this->getQuestionOrder(),
            'question_text' => $this->getQuestionText(),
            'option_a' => $this->getOptionA(),
            'option_b' => $this->getOptionB(),
            'option_c' => $this->getOptionC(),
            'difficulty_level' => $this->getDifficultyLevel(),
            'explanation' => $this->getExplanation(),
            'image_url' => $this->getImageUrl(),
        ];

        if ($includeCorrectAnswer) {
            $data['correct_answer'] = $this->getCorrectAnswer();
        }

        return $data;
    }

    // =====================================
    // UTILITY AND MONITORING METHODS
    // =====================================

    /**
     * Validate question completeness for a game
     *
     * @param int $gameId Game ID
     * @return array Validation results
     * @throws Exception If query fails
     */
    public static function validateGameQuestions(int $gameId): array
    {
        $issues = [];

        // Check minimum question count
        $totalQuestions = self::countQuestionsByGameId($gameId);
        if ($totalQuestions < self::QUESTIONS_PER_GAME) {
            $issues[] = "Game has only {$totalQuestions} questions. Minimum required: " . self::QUESTIONS_PER_GAME;
        }

        if ($totalQuestions < self::MIN_QUESTIONS_PER_GAME) {
            $issues[] = "Game has only {$totalQuestions} questions. Recommended minimum: " . self::MIN_QUESTIONS_PER_GAME;
        }

        // Check for gaps in question order
        $sql = "SELECT question_order FROM " . self::TABLE . "
                WHERE game_id = ? AND active = 1
                ORDER BY question_order ASC";
        $orders = Database::select($sql, [$gameId]);

        for ($i = 1; $i <= count($orders); $i++) {
            if (!in_array($i, array_column($orders, 'question_order'))) {
                $issues[] = "Missing question order: {$i}";
            }
        }

        // Check for duplicate question orders
        $duplicates = array_count_values(array_column($orders, 'question_order'));
        foreach ($duplicates as $order => $count) {
            if ($count > 1) {
                $issues[] = "Duplicate question order: {$order} (appears {$count} times)";
            }
        }

        return [
            'valid' => empty($issues),
            'total_questions' => $totalQuestions,
            'issues' => $issues
        ];
    }

    /**
     * Health check for question system
     *
     * @return array Health status
     */
    public static function healthCheck(): array
    {
        try {
            // Test basic query
            $sql = "SELECT COUNT(*) as count FROM " . self::TABLE . " WHERE active = 1";
            $result = Database::selectOne($sql);
            $activeQuestions = (int) $result['count'];

            // Test history table
            $historySql = "SELECT COUNT(*) as count FROM " . self::HISTORY_TABLE;
            $historyResult = Database::selectOne($historySql);
            $historyRecords = (int) $historyResult['count'];

            return [
                'status' => 'healthy',
                'active_questions' => $activeQuestions,
                'history_records' => $historyRecords,
                'tables_accessible' => true
            ];

        } catch (Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'tables_accessible' => false
            ];
        }
    }

    /**
     * Get statistics for admin dashboard
     *
     * @return array Question statistics
     * @throws Exception If query fails
     */
    public static function getStatistics(): array
    {
        $stats = [];

        // Total questions
        $sql = "SELECT COUNT(*) as count FROM " . self::TABLE . " WHERE active = 1";
        $result = Database::selectOne($sql);
        $stats['total_active_questions'] = (int) $result['count'];

        // Questions by difficulty
        foreach (self::DIFFICULTY_LEVELS as $difficulty) {
            $sql = "SELECT COUNT(*) as count FROM " . self::TABLE . "
                    WHERE active = 1 AND difficulty_level = ?";
            $result = Database::selectOne($sql, [$difficulty]);
            $stats["questions_{$difficulty}"] = (int) $result['count'];
        }

        // Games with insufficient questions
        $sql = "SELECT game_id, COUNT(*) as question_count
                FROM " . self::TABLE . "
                WHERE active = 1
                GROUP BY game_id
                HAVING COUNT(*) < ?";
        $insufficientGames = Database::select($sql, [self::MIN_QUESTIONS_PER_GAME]);
        $stats['games_needing_more_questions'] = count($insufficientGames);

        // Total history records
        $sql = "SELECT COUNT(*) as count FROM " . self::HISTORY_TABLE;
        $result = Database::selectOne($sql);
        $stats['total_question_history'] = (int) $result['count'];

        return $stats;
    }
}
