<?php
declare(strict_types=1);

/**
 * File: controllers/AdminGameController.php
 * Location: controllers/AdminGameController.php
 *
 * WinABN Admin Game Management Controller - Complete Implementation
 *
 * Comprehensive game management including creation, editing, question management,
 * bulk operations, and performance analytics.
 *
 * @package WinABN\Controllers
 * @author WinABN Development Team
 * @version 1.1 - Extended Implementation
 */

namespace WinABN\Controllers;

use WinABN\Core\{Controller, Database, Security, Session, CurrencyConverter};
use WinABN\Models\{Game, Question, Round, Analytics, Admin, ExchangeRate};
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
            $filters = $this->getRequestFilters();

            // Apply filters if provided
            if (!empty($filters)) {
                $games = $this->applyFilters($games, $filters);
            }

            $this->view->render('admin/games/index', [
                'games' => $games,
                'stats' => $stats,
                'filters' => $filters,
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
            $exchangeRates = ExchangeRate::getLatestRates();
            $gameTemplates = $this->getGameTemplates();

            $this->view->render('admin/games/create', [
                'currencies' => $currencies,
                'exchange_rates' => $exchangeRates,
                'game_templates' => $gameTemplates,
                'csrf_token' => csrf_token(),
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

            // If template questions provided, create them
            if (!empty($_POST['use_template_questions']) && !empty($_POST['template_id'])) {
                $this->createTemplateQuestions($gameId, (int)$_POST['template_id']);
            }

            // Log admin action
            $this->logAdminAction('game_created', [
                'game_id' => $gameId,
                'game_name' => $gameData['name'],
                'admin_id' => $this->getCurrentAdminId()
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
            $recentRounds = Round::getByGameId($gameId, 10);
            $analytics = Analytics::getGamePerformance($gameId);
            $currencies = $this->getSupportedCurrencies();
            $gameStats = $this->getDetailedGameStats($gameId);

            $this->view->render('admin/games/show', [
                'game' => $game,
                'questions' => $questions,
                'recent_rounds' => $recentRounds,
                'analytics' => $analytics,
                'currencies' => $currencies,
                'game_stats' => $gameStats,
                'csrf_token' => csrf_token(),
                'page_title' => 'Game: ' . $game['name'],
                'active_tab' => 'games'
            ]);

        } catch (Exception $e) {
            $this->handleError('Failed to load game details', $e);
        }
    }

    /**
     * Show edit game form
     *
     * @param int $gameId Game ID
     * @return void
     */
    public function edit(int $gameId): void
    {
        try {
            $game = Game::findWithStats($gameId);
            if (!$game) {
                throw new Exception('Game not found');
            }

            $currencies = $this->getSupportedCurrencies();
            $exchangeRates = ExchangeRate::getLatestRates();
            $gameStats = $this->getDetailedGameStats($gameId);

            $this->view->render('admin/games/edit', [
                'game' => $game,
                'currencies' => $currencies,
                'exchange_rates' => $exchangeRates,
                'game_stats' => $gameStats,
                'csrf_token' => csrf_token(),
                'page_title' => 'Edit Game: ' . $game['name'],
                'active_tab' => 'games'
            ]);

        } catch (Exception $e) {
            $this->handleError('Failed to load edit game form', $e);
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

            // Check if game can be edited
            if (!$this->canEditGame($game)) {
                throw new Exception('Cannot edit game with active rounds');
            }

            $gameData = $this->validateGameData($_POST, $gameId);
            $changes = $this->getChangedFields($game, $gameData);

            Database::beginTransaction();

            // Update game
            Game::update($gameId, $gameData);

            // Update entry fees in other currencies if base fee changed
            if (isset($changes['entry_fee'])) {
                $this->updateCurrencyFees($gameId, $gameData['entry_fee'], $gameData['currency']);
            }

            // Log admin action
            $this->logAdminAction('game_updated', [
                'game_id' => $gameId,
                'changes' => $changes,
                'admin_id' => $this->getCurrentAdminId()
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

            // Validate status change
            if ($newStatus === 'active' && !$this->canActivateGame($game)) {
                throw new Exception('Game cannot be activated - missing questions or configuration');
            }

            Database::beginTransaction();

            Game::update($gameId, ['status' => $newStatus]);

            // If pausing, also pause active rounds
            if ($newStatus === 'paused') {
                Round::pauseActiveRounds($gameId);
            }

            $this->logAdminAction('game_status_changed', [
                'game_id' => $gameId,
                'old_status' => $game['status'],
                'new_status' => $newStatus,
                'admin_id' => $this->getCurrentAdminId()
            ]);

            Database::commit();

            Session::setFlash('success', "Game {$newStatus} successfully!");

            if ($this->isAjaxRequest()) {
                $this->jsonResponse(['success' => true, 'new_status' => $newStatus]);
            } else {
                $this->redirect('/adminportal/games');
            }

        } catch (Exception $e) {
            Database::rollback();

            if ($this->isAjaxRequest()) {
                $this->jsonResponse(['success' => false, 'error' => $e->getMessage()]);
            } else {
                $this->handleError('Failed to toggle game status', $e);
            }
        }
    }

    /**
     * Archive game (soft delete)
     *
     * @param int $gameId Game ID
     * @return void
     */
    public function archive(int $gameId): void
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
                throw new Exception('Cannot archive game with active rounds');
            }

            Database::beginTransaction();

            // Archive game instead of hard delete
            Game::update($gameId, [
                'status' => 'archived',
                'archived_at' => date('Y-m-d H:i:s')
            ]);

            $this->logAdminAction('game_archived', [
                'game_id' => $gameId,
                'game_name' => $game['name'],
                'admin_id' => $this->getCurrentAdminId()
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
     * Bulk operations handler
     *
     * @return void
     */
    public function bulkAction(): void
    {
        try {
            if (!$this->validateCsrfToken()) {
                throw new Exception('Invalid CSRF token');
            }

            $action = $_POST['bulk_action'] ?? '';
            $gameIds = $_POST['game_ids'] ?? [];

            if (empty($action) || empty($gameIds)) {
                throw new Exception('Invalid bulk action parameters');
            }

            $gameIds = array_map('intval', $gameIds);
            $results = $this->performBulkAction($action, $gameIds);

            Session::setFlash('success', $results['message']);
            $this->redirect('/adminportal/games');

        } catch (Exception $e) {
            $this->handleError('Failed to perform bulk action', $e);
        }
    }

    /**
     * Get games dashboard statistics
     *
     * @return array
     */
    private function getGamesDashboardStats(): array
    {
        $stats = Database::query("
            SELECT
                COUNT(*) as total_games,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_games,
                SUM(CASE WHEN status = 'paused' THEN 1 ELSE 0 END) as paused_games,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_games,
                AVG(entry_fee) as avg_entry_fee,
                SUM(prize_value) as total_prize_value
            FROM games
            WHERE status != 'archived'
        ")->fetch();

        // Get participant stats
        $participantStats = Database::query("
            SELECT
                COUNT(DISTINCT p.id) as total_participants,
                COUNT(DISTINCT CASE WHEN p.payment_status = 'completed' THEN p.id END) as paid_participants,
                SUM(CASE WHEN p.payment_status = 'completed' THEN g.entry_fee ELSE 0 END) as total_revenue
            FROM participants p
            JOIN rounds r ON p.round_id = r.id
            JOIN games g ON r.game_id = g.id
            WHERE g.status != 'archived'
        ")->fetch();

        return array_merge($stats, $participantStats);
    }

    /**
     * Get detailed statistics for a specific game
     *
     * @param int $gameId
     * @return array
     */
    private function getDetailedGameStats(int $gameId): array
    {
        $stats = Database::query("
            SELECT
                COUNT(DISTINCT r.id) as total_rounds,
                COUNT(DISTINCT p.id) as total_participants,
                COUNT(DISTINCT CASE WHEN p.payment_status = 'completed' THEN p.id END) as paid_participants,
                COUNT(DISTINCT CASE WHEN r.status = 'completed' AND r.winner_id IS NOT NULL THEN r.id END) as completed_rounds,
                AVG(p.completion_time) as avg_completion_time,
                SUM(CASE WHEN p.payment_status = 'completed' THEN g.entry_fee ELSE 0 END) as total_revenue,
                MIN(r.created_at) as first_round_date,
                MAX(r.created_at) as last_round_date
            FROM games g
            LEFT JOIN rounds r ON g.id = r.game_id
            LEFT JOIN participants p ON r.id = p.round_id
            WHERE g.id = ?
        ", [$gameId])->fetch();

        // Get question performance
        $questionStats = Database::query("
            SELECT
                COUNT(*) as total_questions,
                AVG(CASE WHEN pr.is_correct = 1 THEN 1 ELSE 0 END) as avg_correct_rate,
                COUNT(DISTINCT pr.participant_id) as total_responses
            FROM questions q
            LEFT JOIN participant_responses pr ON q.id = pr.question_id
            WHERE q.game_id = ?
        ", [$gameId])->fetch();

        return array_merge($stats, $questionStats);
    }

    /**
     * Validate game data
     *
     * @param array $data
     * @param int|null $gameId
     * @return array
     */
    private function validateGameData(array $data, ?int $gameId = null): array
    {
        $errors = [];

        // Required fields
        $required = ['name', 'description', 'prize_value', 'currency', 'entry_fee', 'max_players'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $errors[] = "Field '{$field}' is required";
            }
        }

        // Validate numeric fields
        if (!is_numeric($data['prize_value']) || $data['prize_value'] <= 0) {
            $errors[] = 'Prize value must be a positive number';
        }

        if (!is_numeric($data['entry_fee']) || $data['entry_fee'] <= 0) {
            $errors[] = 'Entry fee must be a positive number';
        }

        if (!is_numeric($data['max_players']) || $data['max_players'] < 1) {
            $errors[] = 'Max players must be at least 1';
        }

        // Validate currency
        $supportedCurrencies = array_keys($this->getSupportedCurrencies());
        if (!in_array($data['currency'], $supportedCurrencies)) {
            $errors[] = 'Invalid currency selected';
        }

        // Check for duplicate name/slug
        $slug = $this->generateSlug($data['name']);
        $existingGame = Game::findBySlug($slug);
        if ($existingGame && (!$gameId || $existingGame['id'] != $gameId)) {
            $errors[] = 'A game with this name already exists';
        }

        if (!empty($errors)) {
            throw new Exception('Validation failed: ' . implode(', ', $errors));
        }

        // Prepare clean data
        return [
            'name' => Security::sanitizeInput($data['name']),
            'slug' => $slug,
            'description' => Security::sanitizeInput($data['description']),
            'prize_value' => (float)$data['prize_value'],
            'currency' => $data['currency'],
            'entry_fee' => (float)$data['entry_fee'],
            'max_players' => (int)$data['max_players'],
            'auto_restart' => !empty($data['auto_restart']) ? 1 : 0,
            'total_questions' => (int)($data['total_questions'] ?? 9),
            'free_questions' => (int)($data['free_questions'] ?? 3),
            'question_timeout' => (int)($data['question_timeout'] ?? 10),
            'status' => $data['status'] ?? 'paused'
        ];
    }

    /**
     * Generate URL slug from name
     *
     * @param string $name
     * @return string
     */
    private function generateSlug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        return trim($slug, '-');
    }

    /**
     * Check if game can be activated
     *
     * @param array $game
     * @return bool
     */
    private function canActivateGame(array $game): bool
    {
        // Check if game has required questions
        $questionCount = Question::getCountByGameId($game['id']);
        if ($questionCount < $game['total_questions']) {
            return false;
        }

        // Check if all required fields are set
        $required = ['name', 'description', 'prize_value', 'entry_fee'];
        foreach ($required as $field) {
            if (empty($game[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if game can be edited
     *
     * @param array $game
     * @return bool
     */
    private function canEditGame(array $game): bool
    {
        // Cannot edit if has active rounds
        $activeRounds = Round::getActiveRoundCount($game['id']);
        return $activeRounds === 0;
    }

    /**
     * Update currency-specific entry fees
     *
     * @param int $gameId
     * @param float $baseFee
     * @param string $baseCurrency
     * @return void
     */
    private function updateCurrencyFees(int $gameId, float $baseFee, string $baseCurrency): void
    {
        $converter = new CurrencyConverter();
        $currencies = ['USD', 'EUR', 'GBP', 'CAD', 'AUD'];

        $updateData = [];
        foreach ($currencies as $currency) {
            if ($currency !== $baseCurrency) {
                $convertedFee = $converter->convert($baseFee, $baseCurrency, $currency);
                $updateData["entry_fee_" . strtolower($currency)] = $convertedFee;
            }
        }

        if (!empty($updateData)) {
            Game::update($gameId, $updateData);
        }
    }

    /**
     * Get changed fields between old and new data
     *
     * @param array $oldData
     * @param array $newData
     * @return array
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
     * Get supported currencies
     *
     * @return array
     */
    private function getSupportedCurrencies(): array
    {
        return [
            'GBP' => ['name' => 'British Pound', 'symbol' => '£'],
            'USD' => ['name' => 'US Dollar', 'symbol' => '$'],
            'EUR' => ['name' => 'Euro', 'symbol' => '€'],
            'CAD' => ['name' => 'Canadian Dollar', 'symbol' => 'C$'],
            'AUD' => ['name' => 'Australian Dollar', 'symbol' => 'A$']
        ];
    }

    /**
     * Get request filters
     *
     * @return array
     */
    private function getRequestFilters(): array
    {
        return [
            'status' => $_GET['status'] ?? '',
            'currency' => $_GET['currency'] ?? '',
            'search' => $_GET['search'] ?? ''
        ];
    }

    /**
     * Apply filters to games array
     *
     * @param array $games
     * @param array $filters
     * @return array
     */
    private function applyFilters(array $games, array $filters): array
    {
        return array_filter($games, function($game) use ($filters) {
            if (!empty($filters['status']) && $game['status'] !== $filters['status']) {
                return false;
            }

            if (!empty($filters['currency']) && $game['currency'] !== $filters['currency']) {
                return false;
            }

            if (!empty($filters['search'])) {
                $search = strtolower($filters['search']);
                $searchText = strtolower($game['name'] . ' ' . $game['description']);
                if (strpos($searchText, $search) === false) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Get game templates for quick creation
     *
     * @return array
     */
    private function getGameTemplates(): array
    {
        return [
            [
                'id' => 1,
                'name' => 'iPhone Competition',
                'description' => 'Win the latest iPhone Pro model',
                'prize_value' => 1200,
                'entry_fee' => 10,
                'max_players' => 1000
            ],
            [
                'id' => 2,
                'name' => 'Gaming Console',
                'description' => 'Win a PlayStation or Xbox console',
                'prize_value' => 500,
                'entry_fee' => 7.50,
                'max_players' => 750
            ],
            [
                'id' => 3,
                'name' => 'Cash Prize',
                'description' => 'Pure cash prize competition',
                'prize_value' => 1000,
                'entry_fee' => 5,
                'max_players' => 2000
            ]
        ];
    }

    /**
     * Create template questions for new game
     *
     * @param int $gameId
     * @param int $templateId
     * @return void
     */
    private function createTemplateQuestions(int $gameId, int $templateId): void
    {
        // This would be implemented based on predefined question sets
        // For now, just a placeholder
        $this->logAdminAction('template_questions_created', [
            'game_id' => $gameId,
            'template_id' => $templateId
        ]);
    }

    /**
     * Perform bulk action on multiple games
     *
     * @param string $action
     * @param array $gameIds
     * @return array
     */
    private function performBulkAction(string $action, array $gameIds): array
    {
        $successCount = 0;
        $errorCount = 0;

        foreach ($gameIds as $gameId) {
            try {
                switch ($action) {
                    case 'activate':
                        $game = Game::find($gameId);
                        if ($game && $this->canActivateGame($game)) {
                            Game::update($gameId, ['status' => 'active']);
                            $successCount++;
                        } else {
                            $errorCount++;
                        }
                        break;

                    case 'pause':
                        Game::update($gameId, ['status' => 'paused']);
                        Round::pauseActiveRounds($gameId);
                        $successCount++;
                        break;

                    case 'archive':
                        $activeRounds = Round::getActiveRoundCount($gameId);
                        if ($activeRounds === 0) {
                            Game::update($gameId, ['status' => 'archived']);
                            $successCount++;
                        } else {
                            $errorCount++;
                        }
                        break;

                    default:
                        throw new Exception('Invalid bulk action');
                }
            } catch (Exception $e) {
                $errorCount++;
                error_log("Bulk action error for game {$gameId}: " . $e->getMessage());
            }
        }

        return [
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'message' => "Bulk action completed: {$successCount} successful, {$errorCount} failed"
        ];
    }

    /**
     * Get current admin ID from session
     *
     * @return int
     */
    private function getCurrentAdminId(): int
    {
        return (int)Session::get('admin_id');
    }

    /**
     * Log admin action for audit trail
     *
     * @param string $action
     * @param array $details
     * @return void
     */
    private function logAdminAction(string $action, array $details): void
    {
        try {
            Analytics::logAdminAction($action, $details, $this->getCurrentAdminId());
        } catch (Exception $e) {
            error_log("Failed to log admin action: " . $e->getMessage());
        }
    }
}
