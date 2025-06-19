<?php
declare(strict_types=1);

/**
 * File: controllers/AdminGameController.php
 * Location: controllers/AdminGameController.php
 *
 * WinABN Admin Game Management Controller
 *
 * Handles all game management operations in the admin portal including
 * game creation, editing, question management, and bulk operations.
 *
 * @package WinABN\Controllers
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Controllers;

use WinABN\Core\{Controller, Database, Security, Session};
use WinABN\Models\{Game, Question, Round, Analytics};
use Exception;

class AdminGameController extends Controller
{
    /**
     * Admin authentication middleware
     */
    public function __construct()
    {
        parent::__construct();
        $this->requireAdminAuth();
    }

    /**
     * Display games management dashboard
     *
     * @return void
     */
    public function index(): void
    {
        try {
            $games = Game::getAllWithStats();
            $stats = $this->getGamesDashboardStats();

            $this->view->render('admin/games/index', [
                'games' => $games,
                'stats' => $stats,
                'page_title' => 'Games Management',
                'active_tab' => 'games'
            ]);

        } catch (Exception $e) {
            $this->handleError('Failed to load games dashboard', $e);
        }
    }

    /**
     * Show create game form
     *
     * @return void
     */
    public function create(): void
    {
        try {
            $currencies = $this->getSupportedCurrencies();
            $exchangeRates = $this->getLatestExchangeRates();

            $this->view->render('admin/games/create', [
                'currencies' => $currencies,
                'exchange_rates' => $exchangeRates,
                'page_title' => 'Create New Game',
                'active_tab' => 'games'
            ]);

        } catch (Exception $e) {
            $this->handleError('Failed to load create game form', $e);
        }
    }

    /**
     * Store new game
     *
     * @return void
     */
    public function store(): void
    {
        try {
            if (!$this->validateCsrfToken()) {
                throw new Exception('Invalid CSRF token');
            }

            $gameData = $this->validateGameData($_POST);

            Database::beginTransaction();

            // Create game
            $gameId = Game::create($gameData);

            // Log admin action
            $this->logAdminAction('game_created', [
                'game_id' => $gameId,
                'game_name' => $gameData['name']
            ]);

            Database::commit();

            Session::setFlash('success', 'Game created successfully!');
            $this->redirect('/adminportal/games/' . $gameId);

        } catch (Exception $e) {
            Database::rollback();
            $this->handleError('Failed to create game', $e);
        }
    }

    /**
     * Show game details and edit form
     *
     * @param int $gameId Game ID
     * @return void
     */
    public function show(int $gameId): void
    {
        try {
            $game = Game::findWithStats($gameId);
            if (!$game) {
                throw new Exception('Game not found');
            }

            $questions = Question::getByGameId($gameId);
            $rounds = Round::getByGameId($gameId, 10);
            $analytics = Analytics::getGamePerformance($gameId);
            $currencies = $this->getSupportedCurrencies();

            $this->view->render('admin/games/show', [
                'game' => $game,
                'questions' => $questions,
                'rounds' => $rounds,
                'analytics' => $analytics,
                'currencies' => $currencies,
                'page_title' => 'Game: ' . $game['name'],
                'active_tab' => 'games'
            ]);

        } catch (Exception $e) {
            $this->handleError('Failed to load game details', $e);
        }
    }

    /**
     * Update game settings
     *
     * @param int $gameId Game ID
     * @return void
     */
    public function update(int $gameId): void
    {
        try {
            if (!$this->validateCsrfToken()) {
                throw new Exception('Invalid CSRF token');
            }

            $game = Game::find($gameId);
            if (!$game) {
                throw new Exception('Game not found');
            }

            $gameData = $this->validateGameData($_POST, $gameId);

            Database::beginTransaction();

            // Update game
            Game::update($gameId, $gameData);

            // Log admin action
            $this->logAdminAction('game_updated', [
                'game_id' => $gameId,
                'changes' => $this->getChangedFields($game, $gameData)
            ]);

            Database::commit();

            Session::setFlash('success', 'Game updated successfully!');
            $this->redirect('/adminportal/games/' . $gameId);

        } catch (Exception $e) {
            Database::rollback();
            $this->handleError('Failed to update game', $e);
        }
    }

    /**
     * Toggle game status (active/paused)
     *
     * @param int $gameId Game ID
     * @return void
     */
    public function toggleStatus(int $gameId): void
    {
        try {
            if (!$this->validateCsrfToken()) {
                throw new Exception('Invalid CSRF token');
            }

            $game = Game::find($gameId);
            if (!$game) {
                throw new Exception('Game not found');
            }

            $newStatus = $game['status'] === 'active' ? 'paused' : 'active';

            Database::beginTransaction();

            Game::update($gameId, ['status' => $newStatus]);

            // If pausing, also pause active rounds
            if ($newStatus === 'paused') {
                Round::pauseActiveRounds($gameId);
            }

            $this->logAdminAction('game_status_changed', [
                'game_id' => $gameId,
                'old_status' => $game['status'],
                'new_status' => $newStatus
            ]);

            Database::commit();

            Session::setFlash('success', "Game {$newStatus} successfully!");
            $this->jsonResponse(['success' => true, 'new_status' => $newStatus]);

        } catch (Exception $e) {
            Database::rollback();
            $this->handleError('Failed to toggle game status', $e);
        }
    }

    /**
     * Delete game (soft delete)
     *
     * @param int $gameId Game ID
     * @return void
     */
    public function delete(int $gameId): void
    {
        try {
            if (!$this->validateCsrfToken()) {
                throw new Exception('Invalid CSRF token');
            }

            $game = Game::find($gameId);
            if (!$game) {
                throw new Exception('Game not found');
            }

            // Check if game has active rounds
            $activeRounds = Round::getActiveRoundCount($gameId);
            if ($activeRounds > 0) {
                throw new Exception('Cannot delete game with active rounds');
            }

            Database::beginTransaction();

            // Archive game instead of hard delete
            Game::update($gameId, ['status' => 'archived']);

            $this->logAdminAction('game_archived', [
                'game_id' => $gameId,
                'game_name' => $game['name']
            ]);

            Database::commit();

            Session::setFlash('success', 'Game archived successfully!');
            $this->redirect('/adminportal/games');

        } catch (Exception $e) {
            Database::rollback();
            $this->handleError('Failed to archive game', $e);
        }
    }

    /**
     * Show questions management for a game
     *
     * @param int $gameId Game ID
     * @return void
     */
    public function questions(int $gameId): void
    {
        try {
            $game = Game::find($gameId);
            if (!$game) {
                throw new Exception('Game not found');
            }

            $questions = Question::getByGameIdWithStats($gameId);
            $questionStats = Question::getStatsForGame($gameId);

            $this->view->render('admin/games/questions', [
                'game' => $game,
                'questions' => $questions,
                'question_stats' => $questionStats,
                'page_title' => 'Questions: ' . $game['name'],
                'active_tab' => 'games'
            ]);

        } catch (Exception $e) {
            $this->handleError('Failed to load questions', $e);
        }
    }

    /**
     * Create new question for game
     *
     * @param int $gameId Game ID
     * @return void
     */
    public function createQuestion(int $gameId): void
    {
        try {
            if (!$this->validateCsrfToken()) {
                throw new Exception('Invalid CSRF token');
            }

            $game = Game::find($gameId);
            if (!$game) {
                throw new Exception('Game not found');
            }

            $questionData = $this->validateQuestionData($_POST, $gameId);

            Database::beginTransaction();

            $questionId = Question::create($questionData);

            $this->logAdminAction('question_created', [
                'game_id' => $gameId,
                'question_id' => $questionId
            ]);

            Database::commit();

            Session::setFlash('success', 'Question created successfully!');
            $this->redirect('/adminportal/games/' . $gameId . '/questions');

        } catch (Exception $e) {
            Database::rollback();
            $this->handleError('Failed to create question', $e);
        }
    }

    /**
     * Update question
     *
     * @param int $gameId Game ID
     * @param int $questionId Question ID
     * @return void
     */
    public function updateQuestion(int $gameId, int $questionId): void
    {
        try {
            if (!$this->validateCsrfToken()) {
                throw new Exception('Invalid CSRF token');
            }

            $question = Question::find($questionId);
            if (!$question || $question['game_id'] != $gameId) {
                throw new Exception('Question not found');
            }

            $questionData = $this->validateQuestionData($_POST, $gameId, $questionId);

            Database::beginTransaction();

            Question::update($questionId, $questionData);

            $this->logAdminAction('question_updated', [
                'game_id' => $gameId,
                'question_id' => $questionId
            ]);

            Database::commit();

            Session::setFlash('success', 'Question updated successfully!');
            $this->redirect('/adminportal/games/' . $gameId . '/questions');

        } catch (Exception $e) {
            Database::rollback();
            $this->handleError('Failed to update question', $e);
        }
    }

    /**
     * Delete question
     *
     * @param int $gameId Game ID
     * @param int $questionId Question ID
     * @return void
     */
    public function deleteQuestion(int $gameId, int $questionId): void
    {
        try {
            if (!$this->validateCsrfToken()) {
                throw new Exception('Invalid CSRF token');
            }

            $question = Question::find($questionId);
            if (!$question || $question['game_id'] != $gameId) {
                throw new Exception('Question not found');
            }

            Database::beginTransaction();

            // Soft delete by marking as inactive
            Question::update($questionId, ['is_active' => false]);

            $this->logAdminAction('question_deleted', [
                'game_id' => $gameId,
                'question_id' => $questionId
            ]);

            Database::commit();

            Session::setFlash('success', 'Question deleted successfully!');
            $this->jsonResponse(['success' => true]);

        } catch (Exception $e) {
            Database::rollback();
            $this->handleError('Failed to delete question', $e);
        }
    }

    /**
     * Bulk import questions from CSV
     *
     * @param int $gameId Game ID
     * @return void
     */
    public function importQuestions(int $gameId): void
    {
        try {
            if (!$this->validateCsrfToken()) {
                throw new Exception('Invalid CSRF token');
            }

            $game = Game::find($gameId);
            if (!$game) {
                throw new Exception('Game not found');
            }

            if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Please select a valid CSV file');
            }

            $csvData = $this->parseCsvFile($_FILES['csv_file']['tmp_name']);
            $results = $this->processQuestionImport($gameId, $csvData);

            $this->logAdminAction('questions_imported', [
                'game_id' => $gameId,
                'imported_count' => $results['success_count'],
                'failed_count' => $results['error_count']
            ]);

            Session::setFlash('success',
                "Import completed! {$results['success_count']} questions imported, {$results['error_count']} errors."
            );

            $this->redirect('/adminportal/games/' . $gameId . '/questions');

        } catch (Exception $e) {
            $this->handleError('Failed to import questions', $e);
        }
    }

    /**
     * Export questions to CSV
     *
     * @param int $gameId Game ID
     * @return void
     */
    public function exportQuestions(int $gameId): void
    {
        try {
            $game = Game::find($gameId);
            if (!$game) {
                throw new Exception('Game not found');
            }

            $questions = Question::getByGameId($gameId);

            $filename = 'questions_' . $game['slug'] . '_' . date('Y-m-d') . '.csv';

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Pragma: public');

            $output = fopen('php://output', 'w');

            // CSV Headers
            fputcsv($output, [
                'Question Text',
                'Option A',
                'Option B',
                'Option C',
                'Correct Answer',
                'Difficulty',
                'Category',
                'Explanation'
            ]);

            // CSV Data
            foreach ($questions as $question) {
                fputcsv($output, [
                    $question['question_text'],
                    $question['option_a'],
                    $question['option_b'],
                    $question['option_c'],
                    $question['correct_answer'],
                    $question['difficulty_level'],
                    $question['category'] ?? '',
                    $question['explanation'] ?? ''
                ]);
            }

            fclose($output);
            exit;

        } catch (Exception $e) {
            $this->handleError('Failed to export questions', $e);
        }
    }

    /**
     * Validate game data
     *
     * @param array $data Input data
     * @param int|null $gameId Game ID for updates
     * @return array Validated data
     * @throws Exception
     */
    private function validateGameData(array $data, ?int $gameId = null): array
    {
        $errors = [];

        // Required fields
        if (empty($data['name'])) {
            $errors[] = 'Game name is required';
        }

        if (empty($data['slug'])) {
            $errors[] = 'Game slug is required';
        } else {
            // Check slug uniqueness
            $existingGame = Game::findBySlug($data['slug']);
            if ($existingGame && (!$gameId || $existingGame['id'] != $gameId)) {
                $errors[] = 'Slug already exists';
            }
        }

        if (empty($data['prize_value']) || !is_numeric($data['prize_value'])) {
            $errors[] = 'Valid prize value is required';
        }

        if (empty($data['entry_fee']) || !is_numeric($data['entry_fee'])) {
            $errors[] = 'Valid entry fee is required';
        }

        if (empty($data['max_players']) || !is_numeric($data['max_players']) || $data['max_players'] < 1) {
            $errors[] = 'Valid max players is required (minimum 1)';
        }

        if (!empty($errors)) {
            throw new Exception('Validation failed: ' . implode(', ', $errors));
        }

        return [
            'name' => Security::sanitizeInput($data['name']),
            'slug' => Security::sanitizeSlug($data['slug']),
            'description' => Security::sanitizeInput($data['description'] ?? ''),
            'prize_value' => (float) $data['prize_value'],
            'currency' => Security::sanitizeInput($data['currency'] ?? 'GBP'),
            'max_players' => (int) $data['max_players'],
            'entry_fee' => (float) $data['entry_fee'],
            'entry_fee_usd' => isset($data['entry_fee_usd']) ? (float) $data['entry_fee_usd'] : null,
            'entry_fee_eur' => isset($data['entry_fee_eur']) ? (float) $data['entry_fee_eur'] : null,
            'entry_fee_gbp' => isset($data['entry_fee_gbp']) ? (float) $data['entry_fee_gbp'] : null,
            'entry_fee_cad' => isset($data['entry_fee_cad']) ? (float) $data['entry_fee_cad'] : null,
            'entry_fee_aud' => isset($data['entry_fee_aud']) ? (float) $data['entry_fee_aud'] : null,
            'auto_restart' => isset($data['auto_restart']) ? 1 : 0,
            'status' => Security::sanitizeInput($data['status'] ?? 'active'),
            'total_questions' => (int) ($data['total_questions'] ?? 9),
            'free_questions' => (int) ($data['free_questions'] ?? 3),
            'question_timeout' => (int) ($data['question_timeout'] ?? 10)
        ];
    }

    /**
     * Validate question data
     *
     * @param array $data Input data
     * @param int $gameId Game ID
     * @param int|null $questionId Question ID for updates
     * @return array Validated data
     * @throws Exception
     */
    private function validateQuestionData(array $data, int $gameId, ?int $questionId = null): array
    {
        $errors = [];

        if (empty($data['question_text'])) {
            $errors[] = 'Question text is required';
        }

        if (empty($data['option_a'])) {
            $errors[] = 'Option A is required';
        }

        if (empty($data['option_b'])) {
            $errors[] = 'Option B is required';
        }

        if (empty($data['option_c'])) {
            $errors[] = 'Option C is required';
        }

        if (empty($data['correct_answer']) || !in_array($data['correct_answer'], ['A', 'B', 'C'])) {
            $errors[] = 'Valid correct answer is required (A, B, or C)';
        }

        if (!empty($errors)) {
            throw new Exception('Validation failed: ' . implode(', ', $errors));
        }

        return [
            'game_id' => $gameId,
            'question_text' => Security::sanitizeInput($data['question_text']),
            'option_a' => Security::sanitizeInput($data['option_a']),
            'option_b' => Security::sanitizeInput($data['option_b']),
            'option_c' => Security::sanitizeInput($data['option_c']),
            'correct_answer' => $data['correct_answer'],
            'difficulty_level' => Security::sanitizeInput($data['difficulty_level'] ?? 'medium'),
            'category' => Security::sanitizeInput($data['category'] ?? ''),
            'explanation' => Security::sanitizeInput($data['explanation'] ?? ''),
            'question_order' => isset($data['question_order']) ? (int) $data['question_order'] : null,
            'is_active' => isset($data['is_active']) ? 1 : 0
        ];
    }

    /**
     * Get games dashboard statistics
     *
     * @return array Statistics data
     */
    private function getGamesDashboardStats(): array
    {
        return [
            'total_games' => Game::getTotalCount(),
            'active_games' => Game::getActiveCount(),
            'total_revenue' => Analytics::getTotalRevenue(),
            'total_participants' => Analytics::getTotalParticipants(),
            'avg_conversion_rate' => Analytics::getAverageConversionRate()
        ];
    }

    /**
     * Get supported currencies
     *
     * @return array Currency list
     */
    private function getSupportedCurrencies(): array
    {
        return [
            'GBP' => 'British Pound (£)',
            'USD' => 'US Dollar ($)',
            'EUR' => 'Euro (€)',
            'CAD' => 'Canadian Dollar (C$)',
            'AUD' => 'Australian Dollar (A$)'
        ];
    }

    /**
     * Get latest exchange rates
     *
     * @return array Exchange rates
     */
    private function getLatestExchangeRates(): array
    {
        return Database::fetchAll(
            "SELECT base_currency, target_currency, rate
             FROM exchange_rates
             WHERE DATE(updated_at) = CURDATE()
             ORDER BY base_currency, target_currency"
        );
    }

    /**
     * Get changed fields between old and new data
     *
     * @param array $oldData Original data
     * @param array $newData New data
     * @return array Changed fields
     */
    private function getChangedFields(array $oldData, array $newData): array
    {
        $changes = [];

        foreach ($newData as $key => $value) {
            if (isset($oldData[$key]) && $oldData[$key] != $value) {
                $changes[$key] = [
                    'old' => $oldData[$key],
                    'new' => $value
                ];
            }
        }

        return $changes;
    }

    /**
     * Parse CSV file for question import
     *
     * @param string $filePath CSV file path
     * @return array Parsed data
     * @throws Exception
     */
    private function parseCsvFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new Exception('CSV file not found');
        }

        $csvData = [];
        $file = fopen($filePath, 'r');

        if ($file === false) {
            throw new Exception('Could not open CSV file');
        }

        // Skip header row
        $headers = fgetcsv($file);

        while (($row = fgetcsv($file)) !== false) {
            if (count($row) >= 5) { // Minimum required columns
                $csvData[] = [
                    'question_text' => $row[0] ?? '',
                    'option_a' => $row[1] ?? '',
                    'option_b' => $row[2] ?? '',
                    'option_c' => $row[3] ?? '',
                    'correct_answer' => strtoupper($row[4] ?? ''),
                    'difficulty_level' => $row[5] ?? 'medium',
                    'category' => $row[6] ?? '',
                    'explanation' => $row[7] ?? ''
                ];
            }
        }

        fclose($file);
        return $csvData;
    }

    /**
     * Process question import data
     *
     * @param int $gameId Game ID
     * @param array $csvData CSV data
     * @return array Import results
     */
    private function processQuestionImport(int $gameId, array $csvData): array
    {
        $successCount = 0;
        $errorCount = 0;
        $errors = [];

        Database::beginTransaction();

        try {
            foreach ($csvData as $index => $row) {
                try {
                    $questionData = $this->validateQuestionData($row, $gameId);
                    Question::create($questionData);
                    $successCount++;
                } catch (Exception $e) {
                    $errorCount++;
                    $errors[] = "Row " . ($index + 2) . ": " . $e->getMessage();
                }
            }

            Database::commit();

        } catch (Exception $e) {
            Database::rollback();
            throw $e;
        }

        return [
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'errors' => $errors
        ];
    }
}
<?php
/**
 * File: views/admin/games/index.php
 * Location: views/admin/games/index.php
 */
?>

<?php $this->extend('layouts/admin') ?>

<?php $this->section('content') ?>
<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">Games Management</h1>
            <p class="text-muted">Manage competition games, prizes, and settings</p>
        </div>
        <a href="/adminportal/games/create" class="btn btn-primary">
            <i class="bi bi-plus-circle"></i> Create New Game
        </a>
    </div>

    <!-- Dashboard Stats -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= $stats['total_games'] ?></h4>
                            <p class="mb-0">Total Games</p>
                        </div>
                        <i class="bi bi-trophy fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= $stats['active_games'] ?></h4>
                            <p class="mb-0">Active Games</p>
                        </div>
                        <i class="bi bi-play-circle fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0">£<?= number_format($stats['total_revenue'], 2) ?></h4>
                            <p class="mb-0">Total Revenue</p>
                        </div>
                        <i class="bi bi-currency-pound fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= number_format($stats['total_participants']) ?></h4>
                            <p class="mb-0">Total Participants</p>
                        </div>
                        <i class="bi bi-people fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Games Table -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">All Games</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped" id="gamesTable">
                    <thead>
                        <tr>
                            <th>Game</th>
                            <th>Prize Value</th>
                            <th>Entry Fee</th>
                            <th>Max Players</th>
                            <th>Status</th>
                            <th>Active Rounds</th>
                            <th>Total Revenue</th>
                            <th>Questions</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($games as $game): ?>
                        <tr>
                            <td>
                                <div>
                                    <strong><?= e($game['name']) ?></strong>
                                    <br>
                                    <small class="text-muted"><?= e($game['slug']) ?></small>
                                </div>
                            </td>
                            <td>
                                <span class="fw-bold text-success">
                                    <?= $game['currency'] ?> <?= number_format($game['prize_value'], 2) ?>
                                </span>
                            </td>
                            <td>
                                <?= $game['currency'] ?> <?= number_format($game['entry_fee'], 2) ?>
                            </td>
                            <td>
                                <?= number_format($game['max_players']) ?>
                                <?php if ($game['auto_restart']): ?>
                                    <small class="badge bg-secondary">Auto-restart</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-<?= $game['status'] === 'active' ? 'success' : ($game['status'] === 'paused' ? 'warning' : 'secondary') ?>">
                                    <?= ucfirst($game['status']) ?>
                                </span>
                            </td>
                            <td>
                                <?= $game['active_rounds'] ?? 0 ?>
                            </td>
                            <td>
                                <span class="fw-bold">
                                    £<?= number_format($game['total_revenue'] ?? 0, 2) ?>
                                </span>
                                <br>
                                <small class="text-muted">
                                    <?= number_format($game['total_participants'] ?? 0) ?> participants
                                </small>
                            </td>
                            <td>
                                <span class="badge bg-info">
                                    <?= $game['question_count'] ?? 0 ?> questions
                                </span>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="/adminportal/games/<?= $game['id'] ?>" class="btn btn-outline-primary" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="/adminportal/games/<?= $game['id'] ?>/questions" class="btn btn-outline-info" title="Manage Questions">
                                        <i class="bi bi-question-circle"></i>
                                    </a>
                                    <button type="button" class="btn btn-outline-<?= $game['status'] === 'active' ? 'warning' : 'success' ?>"
                                            onclick="toggleGameStatus(<?= $game['id'] ?>)" title="<?= $game['status'] === 'active' ? 'Pause' : 'Activate' ?> Game">
                                        <i class="bi bi-<?= $game['status'] === 'active' ? 'pause' : 'play' ?>-circle"></i>
                                    </button>
                                    <?php if ($game['status'] !== 'active'): ?>
                                    <button type="button" class="btn btn-outline-danger" onclick="archiveGame(<?= $game['id'] ?>)" title="Archive Game">
                                        <i class="bi bi-archive"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// Toggle game status
function toggleGameStatus(gameId) {
    if (confirm('Are you sure you want to change the game status?')) {
        fetch(`/adminportal/games/${gameId}/toggle-status`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': '<?= csrf_token() ?>'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Failed to update game status');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred');
        });
    }
}

// Archive game
function archiveGame(gameId) {
    if (confirm('Are you sure you want to archive this game? This action cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = `/adminportal/games/${gameId}/delete`;

        const csrfToken = document.createElement('input');
        csrfToken.type = 'hidden';
        csrfToken.name = 'csrf_token';
        csrfToken.value = '<?= csrf_token() ?>';
        form.appendChild(csrfToken);

        document.body.appendChild(form);
        form.submit();
    }
}

// Initialize DataTable
document.addEventListener('DOMContentLoaded', function() {
    if (typeof DataTable !== 'undefined') {
        new DataTable('#gamesTable', {
            pageLength: 25,
            order: [[0, 'asc']],
            columnDefs: [
                { orderable: false, targets: [8] }
            ]
        });
    }
});
</script>
<?php $this->endSection() ?>

<?php
/**
 * File: views/admin/games/create.php
 * Location: views/admin/games/create.php
 */
?>

<?php $this->extend('layouts/admin') ?>

<?php $this->section('content') ?>
<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">Create New Game</h1>
            <p class="text-muted">Set up a new competition game with prizes and settings</p>
        </div>
        <a href="/adminportal/games" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Games
        </a>
    </div>

    <!-- Create Game Form -->
    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Game Details</h5>
                </div>
                <div class="card-body">
                    <form action="/adminportal/games" method="POST" id="createGameForm">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                        <!-- Basic Information -->
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="name" class="form-label">Game Name *</label>
                                <input type="text" class="form-control" id="name" name="name" required
                                       placeholder="e.g., Win a Brand New iPhone 15 Pro">
                            </div>
                            <div class="col-md-6">
                                <label for="slug" class="form-label">URL Slug *</label>
                                <input type="text" class="form-control" id="slug" name="slug" required
                                       placeholder="e.g., win-a-iphone-15-pro">
                                <small class="form-text text-muted">URL-friendly version of the game name</small>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"
                                      placeholder="Brief description of the game and prize"></textarea>
                        </div>

                        <!-- Prize and Pricing -->
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label for="prize_value" class="form-label">Prize Value *</label>
                                <div class="input-group">
                                    <span class="input-group-text">£</span>
                                    <input type="number" class="form-control" id="prize_value" name="prize_value"
                                           required step="0.01" min="0" placeholder="1200.00">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="entry_fee" class="form-label">Entry Fee (GBP) *</label>
                                <div class="input-group">
                                    <span class="input-group-text">£</span>
                                    <input type="number" class="form-control" id="entry_fee" name="entry_fee"
                                           required step="0.01" min="0" placeholder="10.00">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="currency" class="form-label">Base Currency</label>
                                <select class="form-control" id="currency" name="currency">
                                    <?php foreach ($currencies as $code => $name): ?>
                                    <option value="<?= $code ?>" <?= $code === 'GBP' ? 'selected' : '' ?>>
                                        <?= $name ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Multi-Currency Pricing -->
                        <div class="card mb-3">
                            <div class="card-header">
                                <h6 class="mb-0">Multi-Currency Pricing (Optional)</h6>
                                <small class="text-muted">Set specific prices for different currencies</small>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-2">
                                        <label for="entry_fee_usd" class="form-label">USD Price</label>
                                        <div class="input-group">
                                            <span class="input-group-text">$</span>
                                            <input type="number" class="form-control" id="entry_fee_usd" name="entry_fee_usd"
                                                   step="0.01" min="0" placeholder="Auto-calculated">
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <label for="entry_fee_eur" class="form-label">EUR Price</label>
                                        <div class="input-group">
                                            <span class="input-group-text">€</span>
                                            <input type="number" class="form-control" id="entry_fee_eur" name="entry_fee_eur"
                                                   step="0.01" min="0" placeholder="Auto-calculated">
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <label for="entry_fee_cad" class="form-label">CAD Price</label>
                                        <div class="input-group">
                                            <span class="input-group-text">C$</span>
                                            <input type="number" class="form-control" id="entry_fee_cad" name="entry_fee_cad"
                                                   step="0.01" min="0" placeholder="Auto-calculated">
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <label for="entry_fee_aud" class="form-label">AUD Price</label>
                                        <div class="input-group">
                                            <span class="input-group-text">A$</span>
                                            <input type="number" class="form-control" id="entry_fee_aud" name="entry_fee_aud"
                                                   step="0.01" min="0" placeholder="Auto-calculated">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Game Settings -->
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label for="max_players" class="form-label">Max Players *</label>
                                <input type="number" class="form-control" id="max_players" name="max_players"
                                       required min="1" max="10000" value="1000">
                            </div>
                            <div class="col-md-4">
                                <label for="total_questions" class="form-label">Total Questions</label>
                                <input type="number" class="form-control" id="total_questions" name="total_questions"
                                       min="1" max="20" value="9">
                            </div>
                            <div class="col-md-4">
                                <label for="free_questions" class="form-label">Free Questions</label>
                                <input type="number" class="form-control" id="free_questions" name="free_questions"
                                       min="0" max="10" value="3">
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label for="question_timeout" class="form-label">Question Timeout (seconds)</label>
                                <input type="number" class="form-control" id="question_timeout" name="question_timeout"
                                       min="5" max="60" value="10">
                            </div>
                            <div class="col-md-4">
                                <label for="status" class="form-label">Initial Status</label>
                                <select class="form-control" id="status" name="status">
                                    <option value="active">Active</option>
                                    <option value="paused">Paused</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" id="auto_restart" name="auto_restart" checked>
                                    <label class="form-check-label" for="auto_restart">
                                        Auto-restart when full
                                    </label>
                                    <small class="form-text text-muted d-block">
                                        Automatically start new rounds when current round reaches max players
                                    </small>
                                </div>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="d-flex justify-content-end gap-2">
                            <a href="/adminportal/games" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-plus-circle"></i> Create Game
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Exchange Rates Sidebar -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0">Current Exchange Rates</h6>
                    <small class="text-muted">For automatic price calculation</small>
                </div>
                <div class="card-body">
                    <?php if (!empty($exchange_rates)): ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>From</th>
                                    <th>To</th>
                                    <th>Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($exchange_rates as $rate): ?>
                                <tr>
                                    <td><?= $rate['base_currency'] ?></td>
                                    <td><?= $rate['target_currency'] ?></td>
                                    <td><?= number_format($rate['rate'], 4) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <p class="text-muted">No exchange rates available. Prices will need to be set manually.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header">
                    <h6 class="mb-0">Game Creation Tips</h6>
                </div>
                <div class="card-body">
                    <ul class="list-unstyled mb-0">
                        <li class="mb-2">
                            <i class="bi bi-lightbulb text-warning"></i>
                            <strong>Slug:</strong> Keep it short and descriptive for SEO
                        </li>
                        <li class="mb-2">
                            <i class="bi bi-lightbulb text-warning"></i>
                            <strong>Max Players:</strong> Consider server capacity (1000 recommended)
                        </li>
                        <li class="mb-2">
                            <i class="bi bi-lightbulb text-warning"></i>
                            <strong>Auto-restart:</strong> Keeps games running continuously
                        </li>
                        <li class="mb-0">
                            <i class="bi bi-lightbulb text-warning"></i>
                            <strong>Questions:</strong> You'll need at least 27 questions for optimal variety
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-generate slug from name
document.getElementById('name').addEventListener('input', function() {
    const slug = this.value
        .toLowerCase()
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/\s+/g, '-')
        .replace(/-+/g, '-')
        .trim('-');
    document.getElementById('slug').value = slug;
});

// Auto-calculate currency conversions
document.getElementById('entry_fee').addEventListener('input', function() {
    const gbpPrice = parseFloat(this.value);
    if (gbpPrice > 0) {
        // These rates should come from the exchange_rates data
        // For now, using approximate rates
        document.getElementById('entry_fee_usd').placeholder = (gbpPrice * 1.25).toFixed(2);
        document.getElementById('entry_fee_eur').placeholder = (gbpPrice * 1.15).toFixed(2);
        document.getElementById('entry_fee_cad').placeholder = (gbpPrice * 1.65).toFixed(2);
        document.getElementById('entry_fee_aud').placeholder = (gbpPrice * 1.80).toFixed(2);
    }
});

// Form validation
document.getElementById('createGameForm').addEventListener('submit', function(e) {
    const freeQuestions = parseInt(document.getElementById('free_questions').value);
    const totalQuestions = parseInt(document.getElementById('total_questions').value);

    if (freeQuestions >= totalQuestions) {
        e.preventDefault();
        alert('Free questions must be less than total questions');
        return false;
    }
});
</script>
<?php $this->endSection() ?>
