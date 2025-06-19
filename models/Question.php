<?php
declare(strict_types=1);

/**
 * File: models/Question.php
 * Location: models/Question.php
 *
 * WinABN Question Model
 *
 * Handles all question-related database operations including unique question selection
 * algorithm, performance tracking, analytics, question pool management, and admin functions.
 *
 * @package WinABN\Models
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Models;

use WinABN\Core\{Database, Model};
use Exception;

class Question extends Model
{
    /**
     * Table name
     *
     * @var string
     */
    protected string $table = 'questions';

    /**
     * Primary key column
     *
     * @var string
     */
    protected string $primaryKey = 'id';

    /**
     * Difficulty level constants
     *
     * @var string
     */
    public const DIFFICULTY_EASY = 'easy';
    public const DIFFICULTY_MEDIUM = 'medium';
    public const DIFFICULTY_HARD = 'hard';

    /**
     * Valid difficulty levels
     *
     * @var array<string>
     */
    public const DIFFICULTY_LEVELS = [self::DIFFICULTY_EASY, self::DIFFICULTY_MEDIUM, self::DIFFICULTY_HARD];

    /**
     * Valid correct answers
     *
     * @var array<string>
     */
    public const ANSWER_OPTIONS = ['A', 'B', 'C'];

    /**
     * Fillable columns for mass assignment
     *
     * @var array<string>
     */
    protected array $fillable = [
        'game_id',
        'question_order',
        'question_text',
        'option_a',
        'option_b',
        'option_c',
        'correct_answer',
        'difficulty_level',
        'category',
        'explanation',
        'times_used',
        'correct_percentage',
        'is_active'
    ];

    /**
     * Find question by ID
     *
     * @param int $id Question ID
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $query = "SELECT * FROM {$this->table} WHERE id = ?";
        return Database::fetchOne($query, [$id]);
    }

    /**
     * Get unique questions for a participant (avoiding previously seen questions)
     * Core algorithm implementation as per specification
     *
     * @param int $gameId Game ID
     * @param string $userEmail User email
     * @param int $count Number of questions needed
     * @return array<array<string, mixed>>
     * @throws Exception
     */
    public function getUniqueQuestionsForUser(int $gameId, string $userEmail, int $count = 9): array
    {
        // Get questions user has already seen
        $seenQuestionIds = $this->getSeenQuestionIds($userEmail, $gameId);

        // Get all active questions for this game
        $allQuestions = $this->getQuestionsForGame($gameId);

        if (empty($allQuestions)) {
            throw new Exception("No questions available for game: $gameId");
        }

        // Separate seen and unseen questions
        $unseenQuestions = [];
        $seenQuestions = [];

        foreach ($allQuestions as $question) {
            if (in_array($question['id'], $seenQuestionIds)) {
                $seenQuestions[] = $question;
            } else {
                $unseenQuestions[] = $question;
            }
        }

        $selectedQuestions = [];

        // Selection logic based on specification
        if (count($unseenQuestions) >= $count) {
            // Enough unseen questions - randomly select from unseen pool
            $selectedQuestions = $this->randomSelectQuestions($unseenQuestions, $count);
        } elseif (count($unseenQuestions) > 0) {
            // Some unseen questions - use all unseen + random from seen
            $selectedQuestions = $unseenQuestions;
            $remainingNeeded = $count - count($unseenQuestions);
            $additionalQuestions = $this->randomSelectQuestions($seenQuestions, $remainingNeeded);
            $selectedQuestions = array_merge($selectedQuestions, $additionalQuestions);
        } else {
            // All questions have been seen - completely random selection (reset cycle)
            $selectedQuestions = $this->randomSelectQuestions($allQuestions, $count);

            if (function_exists('app_log')) {
                app_log('info', 'Question cycle reset for user', [
                    'user_email' => $userEmail,
                    'game_id' => $gameId,
                    'total_questions' => count($allQuestions)
                ]);
            }
        }

        // Always randomize final order
        shuffle($selectedQuestions);

        if (function_exists('app_log')) {
            app_log('info', 'Questions selected for user', [
                'user_email' => $userEmail,
                'game_id' => $gameId,
                'total_available' => count($allQuestions),
                'unseen_available' => count($unseenQuestions),
                'selected_count' => count($selectedQuestions)
            ]);
        }

        return $selectedQuestions;
    }

    /**
     * Select unique questions for a participant (AdminController compatibility)
     *
     * @param int $gameId Game ID
     * @param string $userEmail User email
     * @param int $questionCount Number of questions needed
     * @return array<array<string, mixed>>
     */
    public function selectUniqueQuestions(int $gameId, string $userEmail, int $questionCount): array
    {
        return $this->getUniqueQuestionsForUser($gameId, $userEmail, $questionCount);
    }

    /**
     * Get all active questions for a game
     *
     * @param int $gameId Game ID
     * @return array<array<string, mixed>>
     */
    public function getQuestionsForGame(int $gameId): array
    {
        $query = "
            SELECT * FROM {$this->table}
            WHERE game_id = ? AND is_active = 1
            ORDER BY RAND()
        ";

        return Database::fetchAll($query, [$gameId]);
    }

    /**
     * Get questions by game ID
     *
     * @param int $gameId Game ID
     * @param int|null $limit Limit number of results
     * @param int $offset Offset for pagination
     * @return array<array<string, mixed>>
     */
    public function getByGameId(int $gameId, ?int $limit = null, int $offset = 0): array
    {
        $query = "
            SELECT q.*,
                   COUNT(pqh.id) as times_used,
                   AVG(CASE WHEN pqh.is_correct = 1 THEN 100.0 ELSE 0.0 END) as correct_percentage
            FROM {$this->table} q
            LEFT JOIN participant_question_history pqh ON q.id = pqh.question_id
            WHERE q.game_id = ?
            GROUP BY q.id
            ORDER BY q.created_at DESC
        ";

        if ($limit !== null) {
            $query .= " LIMIT ? OFFSET ?";
            $params = [$gameId, $limit, $offset];
        } else {
            $params = [$gameId];
        }

        return Database::fetchAll($query, $params);
    }

    /**
     * Get questions by game ID with performance statistics
     *
     * @param int $gameId Game ID
     * @param int $limit Limit number of results
     * @param int $offset Offset for pagination
     * @param array<string, mixed> $filters Optional filters
     * @return array<array<string, mixed>>
     */
    public function getByGameIdWithStats(int $gameId, int $limit = 50, int $offset = 0, array $filters = []): array
    {
        $whereConditions = ['q.game_id = ?'];
        $params = [$gameId];

        // Apply filters
        if (!empty($filters['search'])) {
            $whereConditions[] = "(q.question_text LIKE ? OR q.option_a LIKE ? OR q.option_b LIKE ? OR q.option_c LIKE ?)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if (!empty($filters['difficulty'])) {
            $whereConditions[] = "q.difficulty_level = ?";
            $params[] = $filters['difficulty'];
        }

        if (!empty($filters['category'])) {
            $whereConditions[] = "q.category = ?";
            $params[] = $filters['category'];
        }

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'active') {
                $whereConditions[] = "q.is_active = 1";
            } elseif ($filters['status'] === 'inactive') {
                $whereConditions[] = "q.is_active = 0";
            }
        }

        $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
        $orderBy = $this->getQuestionSortOrder($filters['sort'] ?? 'created_desc');

        $query = "
            SELECT
                q.*,
                COUNT(pqh.id) as total_answers,
                COUNT(CASE WHEN pqh.is_correct = 1 THEN 1 END) as correct_answers,
                CASE
                    WHEN COUNT(pqh.id) > 0
                    THEN ROUND((COUNT(CASE WHEN pqh.is_correct = 1 THEN 1 END) * 100.0 / COUNT(pqh.id)), 2)
                    ELSE NULL
                END as success_rate,
                AVG(pqh.time_taken) as avg_response_time
            FROM {$this->table} q
            LEFT JOIN participant_question_history pqh ON q.id = pqh.question_id
            {$whereClause}
            GROUP BY q.id
            {$orderBy}
            LIMIT ? OFFSET ?
        ";

        $params[] = $limit;
        $params[] = $offset;

        return Database::fetchAll($query, $params);
    }

    /**
     * Get questions by game ID with performance metrics for admin
     *
     * @param int $gameId Game ID
     * @param int $limit Number of questions
     * @return array<array<string, mixed>>
     */
    public function getByGameIdWithPerformance(int $gameId, int $limit = 10): array
    {
        $query = "
            SELECT
                q.*,
                COUNT(pqh.id) as total_attempts,
                COUNT(CASE WHEN pqh.is_correct = 1 THEN 1 END) as correct_attempts,
                CASE
                    WHEN COUNT(pqh.id) > 0
                    THEN ROUND((COUNT(CASE WHEN pqh.is_correct = 1 THEN 1 END) * 100.0 / COUNT(pqh.id)), 2)
                    ELSE 0
                END as success_rate,
                AVG(pqh.time_taken) as avg_time,
                MIN(pqh.time_taken) as fastest_time,
                MAX(pqh.time_taken) as slowest_time
            FROM {$this->table} q
            LEFT JOIN participant_question_history pqh ON q.id = pqh.question_id
            WHERE q.game_id = ? AND q.is_active = 1
            GROUP BY q.id
            ORDER BY q.times_used DESC, q.id ASC
            LIMIT ?
        ";

        return Database::fetchAll($query, [$gameId, $limit]);
    }

    /**
     * Get questions by difficulty for a game
     *
     * @param int $gameId Game ID
     * @param string $difficulty Difficulty level
     * @param int $limit Maximum questions to return
     * @return array<array<string, mixed>>
     */
    public function getQuestionsByDifficulty(int $gameId, string $difficulty, int $limit = 10): array
    {
        $query = "
            SELECT * FROM {$this->table}
            WHERE game_id = ? AND difficulty_level = ? AND is_active = 1
            ORDER BY RAND()
            LIMIT ?
        ";

        return Database::fetchAll($query, [$gameId, $difficulty, $limit]);
    }

    /**
     * Get question count by game ID with filters
     *
     * @param int $gameId Game ID
     * @param array<string, mixed> $filters Optional filters
     * @return int
     */
    public function getCountByGameId(int $gameId, array $filters = []): int
    {
        $whereConditions = ["game_id = ?"];
        $params = [$gameId];

        if (!empty($filters['difficulty'])) {
            $whereConditions[] = "difficulty_level = ?";
            $params[] = $filters['difficulty'];
        }

        if (!empty($filters['category'])) {
            $whereConditions[] = "category = ?";
            $params[] = $filters['category'];
        }

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'active') {
                $whereConditions[] = "is_active = 1";
            } elseif ($filters['status'] === 'inactive') {
                $whereConditions[] = "is_active = 0";
            }
        }

        if (!empty($filters['search'])) {
            $whereConditions[] = "(question_text LIKE ? OR option_a LIKE ? OR option_b LIKE ? OR option_c LIKE ?)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $whereClause = implode(' AND ', $whereConditions);
        $query = "SELECT COUNT(*) FROM {$this->table} WHERE {$whereClause}";

        return (int) Database::fetchColumn($query, $params);
    }

    /**
     * Get active question count by game ID
     *
     * @param int $gameId Game ID
     * @return int
     */
    public function getActiveCountByGameId(int $gameId): int
    {
        $query = "SELECT COUNT(*) FROM {$this->table} WHERE game_id = ? AND is_active = 1";
        return (int) Database::fetchColumn($query, [$gameId]);
    }

    /**
     * Get total question count across all games
     *
     * @return int
     */
    public function getTotalCount(): int
    {
        $query = "SELECT COUNT(*) FROM {$this->table} WHERE is_active = 1";
        return (int) Database::fetchColumn($query);
    }

    /**
     * Record question usage and performance
     *
     * @param int $questionId Question ID
     * @param string $userEmail User email
     * @param string $answerGiven Answer given by user
     * @param bool $isCorrect Whether answer was correct
     * @param float $timeTaken Time taken to answer
     * @param int|null $participantId Participant ID
     * @return void
     */
    public function recordQuestionUsage(int $questionId, string $userEmail, string $answerGiven, bool $isCorrect, float $timeTaken, ?int $participantId = null): void
    {
        // Record in participant question history
        $historyQuery = "
            INSERT INTO participant_question_history
            (user_email, game_id, question_id, participant_id, answer_given, is_correct, time_taken, seen_at)
            SELECT ?, game_id, ?, ?, ?, ?, ?, NOW()
            FROM {$this->table}
            WHERE id = ?
        ";

        Database::execute($historyQuery, [
            $userEmail,
            $questionId,
            $participantId,
            $answerGiven,
            $isCorrect ? 1 : 0,
            $timeTaken,
            $questionId
        ]);

        // Update question usage statistics
        $this->updateQuestionStats($questionId);
    }

    /**
     * Record that a user has seen a question
     *
     * @param int $questionId Question ID
     * @param string $userEmail User email
     * @param int $gameId Game ID
     * @param int|null $participantId Participant ID (optional)
     * @param string|null $answerGiven Answer given by user
     * @param bool|null $isCorrect Whether answer was correct
     * @param float|null $timeTaken Time taken to answer in seconds
     * @return void
     */
    public function recordQuestionSeen(
        int $questionId,
        string $userEmail,
        int $gameId,
        ?int $participantId = null,
        ?string $answerGiven = null,
        ?bool $isCorrect = null,
        ?float $timeTaken = null
    ): void {
        $query = "
            INSERT INTO participant_question_history
            (user_email, game_id, question_id, participant_id, answer_given, is_correct, time_taken, seen_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
            participant_id = VALUES(participant_id),
            answer_given = VALUES(answer_given),
            is_correct = VALUES(is_correct),
            time_taken = VALUES(time_taken),
            seen_at = NOW()
        ";

        Database::execute($query, [
            $userEmail,
            $gameId,
            $questionId,
            $participantId,
            $answerGiven,
            $isCorrect,
            $timeTaken
        ]);

        // Update question usage statistics
        $this->updateQuestionStats($questionId);
    }

    /**
     * Create new question
     *
     * @param array<string, mixed> $data Question data
     * @return int Created question ID
     * @throws Exception
     */
    public function create(array $data): int
    {
        $this->validateQuestionData($data);

        // Set defaults
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['times_used'] = $data['times_used'] ?? 0;
        $data['is_active'] = $data['is_active'] ?? true;

        $query = "
            INSERT INTO {$this->table} (
                game_id, question_order, question_text, option_a, option_b, option_c,
                correct_answer, difficulty_level, category, explanation, times_used,
                correct_percentage, is_active, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";

        Database::execute($query, [
            $data['game_id'],
            $data['question_order'] ?? null,
            $data['question_text'],
            $data['option_a'],
            $data['option_b'],
            $data['option_c'],
            $data['correct_answer'],
            $data['difficulty_level'] ?? self::DIFFICULTY_MEDIUM,
            $data['category'] ?? null,
            $data['explanation'] ?? null,
            $data['times_used'],
            $data['correct_percentage'] ?? null,
            $data['is_active'],
            $data['created_at'],
            $data['updated_at']
        ]);

        $questionId = Database::lastInsertId();

        if (function_exists('app_log')) {
            app_log('info', 'Question created', [
                'question_id' => $questionId,
                'game_id' => $data['game_id'],
                'difficulty' => $data['difficulty_level'] ?? self::DIFFICULTY_MEDIUM
            ]);
        }

        return $questionId;
    }

    /**
     * Update question
     *
     * @param int $id Question ID
     * @param array<string, mixed> $data Update data
     * @return bool
     * @throws Exception
     */
    public function update(int $id, array $data): bool
    {
        $question = $this->findById($id);
        if (!$question) {
            throw new Exception("Question not found: $id");
        }

        $this->validateQuestionData($data, false);

        $data['updated_at'] = date('Y-m-d H:i:s');

        $updateFields = [];
        $params = [];

        $allowedFields = [
            'question_order', 'question_text', 'option_a', 'option_b', 'option_c',
            'correct_answer', 'difficulty_level', 'category', 'explanation',
            'is_active', 'updated_at'
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

        $stmt = Database::execute($query, $params);

        if (function_exists('app_log')) {
            app_log('info', 'Question updated', [
                'question_id' => $id,
                'updated_fields' => array_keys($data)
            ]);
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * Delete question (soft delete)
     *
     * @param int $id Question ID
     * @return bool
     */
    public function delete(int $id): bool
    {
        $query = "UPDATE {$this->table} SET is_active = 0, updated_at = NOW() WHERE id = ?";
        $stmt = Database::execute($query, [$id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Verify answer and record usage statistics
     *
     * @param int $questionId Question ID
     * @param string $answer User's answer (A, B, or C)
     * @return bool Whether answer is correct
     * @throws Exception
     */
    public function verifyAnswer(int $questionId, string $answer): bool
    {
        $question = $this->findById($questionId);
        if (!$question) {
            throw new Exception("Question not found: $questionId");
        }

        $answer = strtoupper($answer);
        if (!in_array($answer, self::ANSWER_OPTIONS)) {
            throw new Exception("Invalid answer format: $answer");
        }

        $isCorrect = $question['correct_answer'] === $answer;

        // Update usage statistics
        $this->updateUsageStats($questionId, $isCorrect);

        return $isCorrect;
    }

    /**
     * Update question usage statistics
     *
     * @param int $questionId Question ID
     * @param bool $isCorrect Whether answer was correct
     * @return bool Success
     */
    public function updateUsageStats(int $questionId, bool $isCorrect): bool
    {
        // Get current statistics
        $question = Database::fetchOne(
            "SELECT times_used, correct_percentage FROM {$this->table} WHERE id = ?",
            [$questionId]
        );

        if (!$question) {
            return false;
        }

        $timesUsed = $question['times_used'] + 1;
        $currentCorrectPercentage = $question['correct_percentage'] ?? 0;

        // Calculate new correct percentage
        if ($timesUsed === 1) {
            $newCorrectPercentage = $isCorrect ? 100.0 : 0.0;
        } else {
            $totalCorrect = ($currentCorrectPercentage / 100) * ($timesUsed - 1);
            if ($isCorrect) {
                $totalCorrect += 1;
            }
            $newCorrectPercentage = ($totalCorrect / $timesUsed) * 100;
        }

        $query = "
            UPDATE {$this->table}
            SET times_used = ?, correct_percentage = ?
            WHERE id = ?
        ";

        Database::execute($query, [$timesUsed, round($newCorrectPercentage, 2), $questionId]);

        return true;
    }

    /**
     * Get question statistics for a game
     *
     * @param int $gameId Game ID
     * @return array<string, mixed>
     */
    public function getQuestionStats(int $gameId): array
    {
        $query = "
            SELECT
                COUNT(*) as total_questions,
                COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_questions,
                COUNT(CASE WHEN difficulty_level = 'easy' THEN 1 END) as easy_questions,
                COUNT(CASE WHEN difficulty_level = 'medium' THEN 1 END) as medium_questions,
                COUNT(CASE WHEN difficulty_level = 'hard' THEN 1 END) as hard_questions,
                AVG(times_used) as avg_times_used,
                AVG(correct_percentage) as avg_correct_rate,
                COUNT(DISTINCT category) as unique_categories
            FROM {$this->table}
            WHERE game_id = ?
        ";

        $stats = Database::fetchOne($query, [$gameId]);

        // Get category breakdown
        $categoryQuery = "
            SELECT
                category,
                COUNT(*) as count
            FROM {$this->table}
            WHERE game_id = ? AND is_active = 1 AND category IS NOT NULL AND category != ''
            GROUP BY category
            ORDER BY count DESC
        ";

        $categories = Database::fetchAll($categoryQuery, [$gameId]);

        $stats['categories'] = [];
        foreach ($categories as $cat) {
            $stats['categories'][$cat['category']] = (int) $cat['count'];
        }

        // Get difficulty breakdown
        $stats['difficulty'] = [
            'easy' => (int) $stats['easy_questions'],
            'medium' => (int) $stats['medium_questions'],
            'hard' => (int) $stats['hard_questions']
        ];

        // Calculate optimal question count (3x total game questions)
        $game = Database::fetchOne("SELECT total_questions FROM games WHERE id = ?", [$gameId]);
        $optimalCount = $game ? $game['total_questions'] * 3 : 27;

        $stats['optimal_question_count'] = $optimalCount;
        $stats['is_optimal'] = $stats['active_questions'] >= $optimalCount;
        $stats['total_active'] = (int) $stats['active_questions'];

        return $stats ?: [];
    }

    /**
     * Get detailed question statistics for admin dashboard
     *
     * @param int $gameId Game ID
     * @return array<string, mixed>
     */
    public function getDetailedQuestionStats(int $gameId): array
    {
        $basicStats = $this->getQuestionStats($gameId);

        // Get performance statistics
        $performanceQuery = "
            SELECT
                AVG(CASE WHEN pqh.is_correct = 1 THEN 100.0 ELSE 0 END) as overall_success_rate,
                AVG(pqh.time_taken) as avg_response_time,
                COUNT(DISTINCT pqh.participant_id) as unique_participants,
                COUNT(pqh.id) as total_attempts
            FROM {$this->table} q
            LEFT JOIN participant_question_history pqh ON q.id = pqh.question_id
            WHERE q.game_id = ? AND q.is_active = 1
        ";

        $performance = Database::fetchOne($performanceQuery, [$gameId]);

        // Get difficulty performance breakdown
        $difficultyPerformanceQuery = "
            SELECT
                q.difficulty_level,
                COUNT(pqh.id) as attempts,
                AVG(CASE WHEN pqh.is_correct = 1 THEN 100.0 ELSE 0 END) as success_rate,
                AVG(pqh.time_taken) as avg_time
            FROM {$this->table} q
            LEFT JOIN participant_question_history pqh ON q.id = pqh.question_id
            WHERE q.game_id = ? AND q.is_active = 1
            GROUP BY q.difficulty_level
        ";

        $difficultyPerformance = Database::fetchAll($difficultyPerformanceQuery, [$gameId]);

        return [
            'basic' => $basicStats,
            'performance' => $performance ?: [],
            'difficulty_performance' => $difficultyPerformance
        ];
    }

    /**
     * Get categories by game ID
     *
     * @param int $gameId Game ID
     * @return array<string>
     */
    public function getCategoriesByGameId(int $gameId): array
    {
        $query = "
            SELECT DISTINCT category
            FROM {$this->table}
            WHERE game_id = ? AND is_active = 1 AND category IS NOT NULL AND category != ''
            ORDER BY category ASC
        ";

        $results = Database::fetchAll($query, [$gameId]);
        return array_column($results, 'category');
    }

    /**
     * Get all categories across all games
     *
     * @return array<string>
     */
    public function getAllCategories(): array
    {
        $query = "
            SELECT DISTINCT category
            FROM {$this->table}
            WHERE is_active = 1 AND category IS NOT NULL AND category != ''
            ORDER BY category ASC
        ";

        $results = Database::fetchAll($query);
        return array_column($results, 'category');
    }

    /**
     * Get performance insights for questions
     *
     * @param int $gameId Game ID
     * @return array<string, mixed>
     */
    public function getPerformanceInsights(int $gameId): array
    {
        // Get most difficult questions (lowest success rate)
        $difficultQuery = "
            SELECT
                q.id,
                q.question_text,
                q.difficulty_level,
                COUNT(pqh.id) as attempts,
                ROUND(AVG(CASE WHEN pqh.is_correct = 1 THEN 100.0 ELSE 0 END), 2) as success_rate
            FROM {$this->table} q
            LEFT JOIN participant_question_history pqh ON q.id = pqh.question_id
            WHERE q.game_id = ? AND q.is_active = 1
            GROUP BY q.id
            HAVING attempts >= 10
            ORDER BY success_rate ASC
            LIMIT 5
        ";

        $mostDifficult = Database::fetchAll($difficultQuery, [$gameId]);

        // Get easiest questions (highest success rate)
        $easyQuery = "
            SELECT
                q.id,
                q.question_text,
                q.difficulty_level,
                COUNT(pqh.id) as attempts,
                ROUND(AVG(CASE WHEN pqh.is_correct = 1 THEN 100.0 ELSE 0 END), 2) as success_rate
            FROM {$this->table} q
            LEFT JOIN participant_question_history pqh ON q.id = pqh.question_id
            WHERE q.game_id = ? AND q.is_active = 1
            GROUP BY q.id
            HAVING attempts >= 10
            ORDER BY success_rate DESC
            LIMIT 5
        ";

        $easiest = Database::fetchAll($easyQuery, [$gameId]);

        // Get unused or rarely used questions
        $underutilizedQuery = "
            SELECT
                q.id,
                q.question_text,
                q.difficulty_level,
                q.times_used
            FROM {$this->table} q
            WHERE q.game_id = ? AND q.is_active = 1
            ORDER BY q.times_used ASC
            LIMIT 10
        ";

        $underutilized = Database::fetchAll($underutilizedQuery, [$gameId]);

        return [
            'most_difficult' => $mostDifficult,
            'easiest' => $easiest,
            'underutilized' => $underutilized
        ];
    }

    /**
     * Get detailed performance analytics for questions
     *
     * @param int $gameId Game ID
     * @param string $startDate Start date
     * @param string $endDate End date
     * @return array<string, mixed>
     */
    public function getDetailedPerformanceAnalytics(int $gameId, string $startDate, string $endDate): array
    {
        $query = "
            SELECT
                q.id,
                q.question_text,
                q.difficulty_level,
                q.category,
                COUNT(pqh.id) as total_attempts,
                COUNT(CASE WHEN pqh.is_correct = 1 THEN 1 END) as correct_attempts,
                ROUND(AVG(CASE WHEN pqh.is_correct = 1 THEN 100.0 ELSE 0 END), 2) as success_rate,
                AVG(pqh.time_taken) as avg_response_time,
                MIN(pqh.time_taken) as fastest_response,
                MAX(pqh.time_taken) as slowest_response
            FROM {$this->table} q
            LEFT JOIN participant_question_history pqh ON q.id = pqh.question_id
                AND pqh.seen_at BETWEEN ? AND ?
            WHERE q.game_id = ? AND q.is_active = 1
            GROUP BY q.id
            ORDER BY total_attempts DESC
        ";

        return Database::fetchAll($query, [$startDate, $endDate, $gameId]);
    }

    /**
     * Get question performance analytics
     *
     * @param int $questionId Question ID
     * @return array<string, mixed>
     */
    public function getQuestionPerformance(int $questionId): array
    {
        $query = "
            SELECT
                COUNT(*) as total_attempts,
                COUNT(CASE WHEN is_correct = 1 THEN 1 END) as correct_answers,
                AVG(time_taken) as avg_response_time,
                MIN(time_taken) as fastest_response,
                MAX(time_taken) as slowest_response,
                AVG(CASE WHEN is_correct = 1 THEN 100.0 ELSE 0.0 END) as success_rate
            FROM participant_question_history
            WHERE question_id = ?
        ";

        $performance = Database::fetchOne($query, [$questionId]);

        // Get answer distribution
        $answerQuery = "
            SELECT
                answer_given,
                COUNT(*) as count,
                ROUND((COUNT(*) * 100.0 / (SELECT COUNT(*) FROM participant_question_history WHERE question_id = ?)), 2) as percentage
            FROM participant_question_history
            WHERE question_id = ? AND answer_given IS NOT NULL
            GROUP BY answer_given
            ORDER BY count DESC
        ";

        $answerDistribution = Database::fetchAll($answerQuery, [$questionId, $questionId]);

        $performance['answer_distribution'] = $answerDistribution;

        return $performance ?: [];
    }

    /**
     * Get question performance analytics for entire game
     *
     * @param int $gameId Game ID
     * @return array<array<string, mixed>>
     */
    public function getGameQuestionPerformance(int $gameId): array
    {
        $query = "
            SELECT
                q.id,
                q.question_text,
                q.difficulty_level,
                q.category,
                q.times_used,
                q.correct_percentage,
                COUNT(pqh.id) as total_attempts,
                COUNT(CASE WHEN pqh.is_correct = 1 THEN 1 END) as correct_answers,
                AVG(pqh.time_taken) as avg_response_time,
                MIN(pqh.time_taken) as fastest_response,
                MAX(pqh.time_taken) as slowest_response
            FROM {$this->table} q
            LEFT JOIN participant_question_history pqh ON q.id = pqh.question_id
            WHERE q.game_id = ? AND q.is_active = 1
            GROUP BY q.id
            ORDER BY q.times_used DESC, q.correct_percentage ASC
        ";

        $results = Database::fetchAll($query, [$gameId]);

        // Calculate additional metrics
        foreach ($results as &$result) {
            if ($result['total_attempts'] > 0) {
                $result['live_success_rate'] = round(
                    ($result['correct_answers'] / $result['total_attempts']) * 100, 2
                );
            } else {
                $result['live_success_rate'] = null;
            }

            // Determine if question needs review
            $result['needs_review'] = false;
            if ($result['correct_percentage'] !== null) {
                if ($result['correct_percentage'] < 30 || $result['correct_percentage'] > 95) {
                    $result['needs_review'] = true;
                }
            }
        }

        return $results;
    }

    /**
     * Get questions for export
     *
     * @param int $gameId Game ID
     * @return array<array<string, mixed>>
     */
    public function getQuestionsForExport(int $gameId): array
    {
        $query = "
            SELECT
                q.*,
                COUNT(pqh.id) as total_uses,
                COUNT(CASE WHEN pqh.is_correct = 1 THEN 1 END) as correct_answers,
                CASE
                    WHEN COUNT(pqh.id) > 0
                    THEN ROUND((COUNT(CASE WHEN pqh.is_correct = 1 THEN 1 END) * 100.0 / COUNT(pqh.id)), 2)
                    ELSE NULL
                END as actual_success_rate
            FROM {$this->table} q
            LEFT JOIN participant_question_history pqh ON q.id = pqh.question_id
            WHERE q.game_id = ?
            GROUP BY q.id
            ORDER BY q.question_order ASC, q.id ASC
        ";

        return Database::fetchAll($query, [$gameId]);
    }

    /**
     * Bulk import questions from array
     *
     * @param int $gameId Game ID
     * @param array<array<string, mixed>> $questionsData Array of question data
     * @return int Number of questions imported
     * @throws Exception
     */
    public function bulkImport(int $gameId, array $questionsData): int
    {
        $importedCount = 0;

        Database::beginTransaction();

        try {
            foreach ($questionsData as $questionData) {
                $questionData['game_id'] = $gameId;
                $this->create($questionData);
                $importedCount++;
            }

            Database::commit();

            if (function_exists('app_log')) {
                app_log('info', 'Questions bulk imported', [
                    'game_id' => $gameId,
                    'imported_count' => $importedCount
                ]);
            }

            return $importedCount;

        } catch (Exception $e) {
            Database::rollback();
            throw $e;
        }
    }

    /**
     * Get questions that need more usage (least used questions)
     *
     * @param int $gameId Game ID
     * @param int $limit Number of questions to return
     * @return array<array<string, mixed>>
     */
    public function getLeastUsedQuestions(int $gameId, int $limit = 10): array
    {
        $query = "
            SELECT q.*, COALESCE(usage_stats.times_used, 0) as times_used
            FROM {$this->table} q
            LEFT JOIN (
                SELECT question_id, COUNT(*) as times_used
                FROM participant_question_history
                WHERE game_id = ?
                GROUP BY question_id
            ) usage_stats ON q.id = usage_stats.question_id
            WHERE q.game_id = ? AND q.is_active = 1
            ORDER BY times_used ASC, q.created_at DESC
            LIMIT ?
        ";

        return Database::fetchAll($query, [$gameId, $gameId, $limit]);
    }

    /**
     * Get questions with poor performance (low success rate)
     *
     * @param int $gameId Game ID
     * @param float $maxSuccessRate Maximum success rate to include (default 30%)
     * @param int $minAttempts Minimum attempts before considering performance
     * @return array<array<string, mixed>>
     */
    public function getPoorPerformingQuestions(int $gameId, float $maxSuccessRate = 30.0, int $minAttempts = 10): array
    {
        $query = "
            SELECT q.*,
                   perf.success_rate,
                   perf.total_attempts
            FROM {$this->table} q
            JOIN (
                SELECT question_id,
                       AVG(CASE WHEN is_correct = 1 THEN 100.0 ELSE 0.0 END) as success_rate,
                       COUNT(*) as total_attempts
                FROM participant_question_history
                WHERE game_id = ?
                GROUP BY question_id
                HAVING total_attempts >= ? AND success_rate <= ?
            ) perf ON q.id = perf.question_id
            WHERE q.game_id = ? AND q.is_active = 1
            ORDER BY perf.success_rate ASC
        ";

        return Database::fetchAll($query, [$gameId, $minAttempts, $maxSuccessRate, $gameId]);
    }

    /**
     * Get questions needing review (too easy or too hard)
     *
     * @param int $gameId Game ID
     * @return array<array<string, mixed>>
     */
    public function getQuestionsNeedingReview(int $gameId): array
    {
        $query = "
            SELECT * FROM {$this->table}
            WHERE game_id = ?
                AND is_active = 1
                AND times_used >= 10
                AND (correct_percentage < 30 OR correct_percentage > 95)
            ORDER BY
                CASE
                    WHEN correct_percentage < 30 THEN correct_percentage
                    ELSE (100 - correct_percentage)
                END ASC
        ";

        return Database::fetchAll($query, [$gameId]);
    }

    /**
     * Get question categories for game (legacy method)
     *
     * @param int $gameId Game ID
     * @return array<string>
     */
    public function getCategories(int $gameId): array
    {
        return $this->getCategoriesByGameId($gameId);
    }

    /**
     * Duplicate question
     *
     * @param int $questionId Question ID to duplicate
     * @return int New question ID
     * @throws Exception
     */
    public function duplicate(int $questionId): int
    {
        $originalQuestion = $this->findById($questionId);
        if (!$originalQuestion) {
            throw new Exception("Question not found: $questionId");
        }

        // Remove ID and timestamps for duplication
        unset($originalQuestion['id'], $originalQuestion['created_at'], $originalQuestion['updated_at'], $originalQuestion['times_used'], $originalQuestion['correct_percentage']);

        // Add suffix to indicate it's a copy
        $originalQuestion['question_text'] .= ' (Copy)';

        return $this->create($originalQuestion);
    }

    /**
     * Toggle question active status
     *
     * @param int $questionId Question ID
     * @return bool New active status
     * @throws Exception
     */
    public function toggleActiveStatus(int $questionId): bool
    {
        $question = $this->findById($questionId);
        if (!$question) {
            throw new Exception("Question not found: $questionId");
        }

        $newStatus = !$question['is_active'];

        $query = "UPDATE {$this->table} SET is_active = ?, updated_at = NOW() WHERE id = ?";
        Database::execute($query, [$newStatus, $questionId]);

        if (function_exists('app_log')) {
            app_log('info', 'Question status changed', [
                'question_id' => $questionId,
                'is_active' => $newStatus
            ]);
        }

        return $newStatus;
    }

    /**
     * Set question active status
     *
     * @param int $questionId Question ID
     * @param bool $isActive New active status
     * @return bool Success
     */
    public function setActive(int $questionId, bool $isActive): bool
    {
        $query = "UPDATE {$this->table} SET is_active = ?, updated_at = NOW() WHERE id = ?";
        Database::execute($query, [$isActive, $questionId]);

        if (function_exists('app_log')) {
            app_log('info', 'Question status changed', [
                'question_id' => $questionId,
                'is_active' => $isActive
            ]);
        }

        return true;
    }

    /**
     * Get seen question IDs for user and game
     *
     * @param string $userEmail User email
     * @param int $gameId Game ID
     * @return array<int>
     */
    private function getSeenQuestionIds(string $userEmail, int $gameId): array
    {
        $query = "
            SELECT question_id FROM participant_question_history
            WHERE user_email = ? AND game_id = ?
        ";

        $results = Database::fetchAll($query, [$userEmail, $gameId]);
        return array_map('intval', array_column($results, 'question_id'));
    }

    /**
     * Randomly select questions from array
     *
     * @param array<array<string, mixed>> $questions Questions to select from
     * @param int $count Number to select
     * @return array<array<string, mixed>>
     */
    private function randomSelectQuestions(array $questions, int $count): array
    {
        if (count($questions) <= $count) {
            return $questions;
        }

        $selectedKeys = array_rand($questions, $count);

        // array_rand returns single key if count is 1, array if count > 1
        if (!is_array($selectedKeys)) {
            $selectedKeys = [$selectedKeys];
        }

        $selected = [];
        foreach ($selectedKeys as $key) {
            $selected[] = $questions[$key];
        }

        return $selected;
    }

    /**
     * Update question usage statistics
     *
     * @param int $questionId Question ID
     * @return void
     */
    private function updateQuestionStats(int $questionId): void
    {
        $query = "
            UPDATE {$this->table}
            SET times_used = (
                SELECT COUNT(*)
                FROM participant_question_history
                WHERE question_id = ?
            ),
            correct_percentage = (
                SELECT AVG(CASE WHEN is_correct = 1 THEN 100.0 ELSE 0.0 END)
                FROM participant_question_history
                WHERE question_id = ? AND is_correct IS NOT NULL
            )
            WHERE id = ?
        ";

        Database::execute($query, [$questionId, $questionId, $questionId]);
    }

    /**
     * Validate question data
     *
     * @param array<string, mixed> $data Question data
     * @param bool $isCreate Is this for creation (requires all fields)
     * @return void
     * @throws Exception
     */
    private function validateQuestionData(array $data, bool $isCreate = true): void
    {
        if ($isCreate) {
            $required = ['game_id', 'question_text', 'option_a', 'option_b', 'option_c', 'correct_answer'];
            foreach ($required as $field) {
                if (!isset($data[$field]) || trim((string) $data[$field]) === '') {
                    throw new Exception("Required field missing: $field");
                }
            }
        }

        // Validate question text
        if (isset($data['question_text'])) {
            $questionText = trim($data['question_text']);
            if (strlen($questionText) < 10 || strlen($questionText) > 1000) {
                throw new Exception('Question text must be between 10 and 1000 characters');
            }
        }

        // Validate options
        foreach (['option_a', 'option_b', 'option_c'] as $option) {
            if (isset($data[$option])) {
                $optionText = trim($data[$option]);
                if (strlen($optionText) < 1 || strlen($optionText) > 255) {
                    throw new Exception("$option must be between 1 and 255 characters");
                }
            }
        }

        // Validate correct answer
        if (isset($data['correct_answer'])) {
            $correctAnswer = strtoupper(trim($data['correct_answer']));
            if (!in_array($correctAnswer, self::ANSWER_OPTIONS)) {
                throw new Exception('Correct answer must be A, B, or C');
            }
            $data['correct_answer'] = $correctAnswer;
        }

        // Validate difficulty level
        if (isset($data['difficulty_level'])) {
            if (!in_array($data['difficulty_level'], self::DIFFICULTY_LEVELS)) {
                throw new Exception('Invalid difficulty level. Must be: ' . implode(', ', self::DIFFICULTY_LEVELS));
            }
        }

        // Validate category (optional)
        if (isset($data['category']) && $data['category'] !== null && strlen($data['category']) > 100) {
            throw new Exception('Category must be 100 characters or less');
        }

        // Validate explanation (optional)
        if (isset($data['explanation']) && $data['explanation'] !== null && strlen($data['explanation']) > 1000) {
            throw new Exception('Explanation must be 1000 characters or less');
        }

        // Validate question order
        if (isset($data['question_order']) && $data['question_order'] !== null) {
            if (!is_int($data['question_order']) || $data['question_order'] < 1) {
                throw new Exception('Question order must be a positive integer or null');
            }
        }

        // Validate game exists
        if (isset($data['game_id'])) {
            $gameExists = Database::fetchColumn(
                "SELECT COUNT(*) FROM games WHERE id = ?",
                [$data['game_id']]
            );

            if (!$gameExists) {
                throw new Exception('Invalid game ID');
            }
        }
    }

    /**
     * Get question sort order SQL clause
     *
     * @param string $sort Sort parameter
     * @return string SQL ORDER BY clause
     */
    private function getQuestionSortOrder(string $sort): string
    {
        $sortOptions = [
            'created_desc' => 'ORDER BY q.created_at DESC',
            'created_asc' => 'ORDER BY q.created_at ASC',
            'usage_desc' => 'ORDER BY times_used DESC',
            'usage_asc' => 'ORDER BY times_used ASC',
            'success_desc' => 'ORDER BY correct_percentage DESC',
            'success_asc' => 'ORDER BY correct_percentage ASC',
            'difficulty_asc' => 'ORDER BY FIELD(q.difficulty_level, "easy", "medium", "hard")',
            'difficulty_desc' => 'ORDER BY FIELD(q.difficulty_level, "hard", "medium", "easy")'
        ];

        return $sortOptions[$sort] ?? $sortOptions['created_desc'];
    }
}
