<?php
/**
 * File: views/admin/games/questions.php
 * Location: views/admin/games/questions.php
 *
 * WinABN Admin Game Questions Management Page
 *
 * Comprehensive question management interface with filtering, bulk operations,
 * performance analytics, and CSV import functionality.
 */

// Prevent direct access
if (!defined('WINABN_ADMIN')) {
    exit('Access denied');
}

$pageTitle = $title ?? 'Manage Questions';
$game = $game ?? [];
$questions = $questions ?? [];
$categories = $categories ?? [];
$question_stats = $question_stats ?? [];
$question_requirements = $question_requirements ?? [];
$filters = $filters ?? [];
$pagination = $pagination ?? [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - WinABN Admin</title>
    <link href="/assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/css/admin.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <?php include_once WINABN_ROOT_DIR . '/views/admin/layouts/header.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <?php include_once WINABN_ROOT_DIR . '/views/admin/layouts/sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <!-- Page Header -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <div>
                        <h1 class="h2">
                            <i class="fas fa-question-circle me-2"></i>
                            <?= htmlspecialchars($pageTitle) ?>
                        </h1>
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item">
                                    <a href="/adminportal/games">Games</a>
                                </li>
                                <li class="breadcrumb-item">
                                    <a href="/adminportal/games/<?= (int) ($game['id'] ?? 0) ?>"><?= htmlspecialchars($game['name'] ?? 'Unknown Game') ?></a>
                                </li>
                                <li class="breadcrumb-item active">Questions</li>
                            </ol>
                        </nav>
                    </div>

                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addQuestionModal">
                                <i class="fas fa-plus me-1"></i> Add Question
                            </button>
                            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#importQuestionsModal">
                                <i class="fas fa-upload me-1"></i> Import CSV
                            </button>
                        </div>
                        <div class="btn-group">
                            <a href="/adminportal/games/<?= (int) ($game['id'] ?? 0) ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-1"></i> Back to Game
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Question Requirements Status -->
                <?php if (!empty($question_requirements)): ?>
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">
                                    <i class="fas fa-tasks me-1"></i> Question Requirements
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <!-- Minimum Questions -->
                                    <div class="col-md-4">
                                        <div class="requirement-item">
                                            <div class="d-flex align-items-center mb-2">
                                                <?php
                                                $minStatus = $question_requirements['minimum_questions']['status'];
                                                $statusIcons = [
                                                    'success' => 'fas fa-check-circle text-success',
                                                    'warning' => 'fas fa-exclamation-triangle text-warning',
                                                    'error' => 'fas fa-times-circle text-danger'
                                                ];
                                                ?>
                                                <i class="<?= $statusIcons[$minStatus] ?? $statusIcons['error'] ?> me-2"></i>
                                                <strong>Minimum Questions</strong>
                                            </div>
                                            <div class="progress mb-2">
                                                <?php
                                                $current = $question_requirements['minimum_questions']['current'];
                                                $required = $question_requirements['minimum_questions']['required'];
                                                $percentage = min(100, ($current / $required) * 100);
                                                $progressClass = $minStatus === 'success' ? 'bg-success' : ($minStatus === 'warning' ? 'bg-warning' : 'bg-danger');
                                                ?>
                                                <div class="progress-bar <?= $progressClass ?>" style="width: <?= $percentage ?>%"></div>
                                            </div>
                                            <small class="text-muted">
                                                <?= $current ?> / <?= $required ?> questions
                                                <?php if ($current < $required): ?>
                                                    (<?= $required - $current ?> more needed)
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                    </div>

                                    <!-- Difficulty Balance -->
                                    <div class="col-md-4">
                                        <div class="requirement-item">
                                            <div class="d-flex align-items-center mb-2">
                                                <?php $diffStatus = $question_requirements['difficulty_balance']['status']; ?>
                                                <i class="<?= $statusIcons[$diffStatus] ?? $statusIcons['error'] ?> me-2"></i>
                                                <strong>Difficulty Balance</strong>
                                            </div>
                                            <div class="small">
                                                <div class="d-flex justify-content-between">
                                                    <span>Easy:</span>
                                                    <span><?= $question_requirements['difficulty_balance']['current_easy'] ?> / <?= $question_requirements['difficulty_balance']['easy_min'] ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between">
                                                    <span>Medium:</span>
                                                    <span><?= $question_requirements['difficulty_balance']['current_medium'] ?> / <?= $question_requirements['difficulty_balance']['medium_min'] ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between">
                                                    <span>Hard:</span>
                                                    <span><?= $question_requirements['difficulty_balance']['current_hard'] ?> / <?= $question_requirements['difficulty_balance']['hard_min'] ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Category Diversity -->
                                    <div class="col-md-4">
                                        <div class="requirement-item">
                                            <div class="d-flex align-items-center mb-2">
                                                <?php $catStatus = $question_requirements['category_diversity']['status']; ?>
                                                <i class="<?= $statusIcons[$catStatus] ?? $statusIcons['error'] ?> me-2"></i>
                                                <strong>Category Diversity</strong>
                                            </div>
                                            <div class="text-center">
                                                <div class="h4 mb-1"><?= $question_requirements['category_diversity']['current_categories'] ?></div>
                                                <small class="text-muted">
                                                    Categories
                                                    (<?= $question_requirements['category_diversity']['min_categories'] ?>+ recommended)
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Question Statistics -->
                <?php if (!empty($question_stats)): ?>
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card border-left-primary shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Questions</div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= number_format($question_stats['total_active'] ?? 0) ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-question-circle fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card border-left-success shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Easy Questions</div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= number_format($question_stats['difficulty']['easy'] ?? 0) ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-smile fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card border-left-warning shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Medium Questions</div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= number_format($question_stats['difficulty']['medium'] ?? 0) ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-meh fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card border-left-danger shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Hard Questions</div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= number_format($question_stats['difficulty']['hard'] ?? 0) ?></div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-frown fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <!-- Filters -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-filter me-1"></i> Filters & Search
                        </h6>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="/adminportal/games/<?= (int) ($game['id'] ?? 0) ?>/questions" class="row g-3">
                            <div class="col-md-4">
                                <label for="search" class="form-label">Search Questions</label>
                                <input type="text"
                                       class="form-control"
                                       id="search"
                                       name="search"
                                       placeholder="Question text, options, or explanation..."
                                       value="<?= htmlspecialchars($filters['search'] ?? '') ?>">
                            </div>

                            <div class="col-md-2">
                                <label for="difficulty" class="form-label">Difficulty</label>
                                <select class="form-select" id="difficulty" name="difficulty">
                                    <option value="all" <?= ($filters['difficulty'] ?? 'all') === 'all' ? 'selected' : '' ?>>All Levels</option>
                                    <option value="easy" <?= ($filters['difficulty'] ?? '') === 'easy' ? 'selected' : '' ?>>Easy</option>
                                    <option value="medium" <?= ($filters['difficulty'] ?? '') === 'medium' ? 'selected' : '' ?>>Medium</option>
                                    <option value="hard" <?= ($filters['difficulty'] ?? '') === 'hard' ? 'selected' : '' ?>>Hard</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label for="category" class="form-label">Category</label>
                                <select class="form-select" id="category" name="category">
                                    <option value="all" <?= ($filters['category'] ?? 'all') === 'all' ? 'selected' : '' ?>>All Categories</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= htmlspecialchars($cat) ?>" <?= ($filters['category'] ?? '') === $cat ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cat) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="active" <?= ($filters['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="inactive" <?= ($filters['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                    <option value="all" <?= ($filters['status'] ?? '') === 'all' ? 'selected' : '' ?>>All</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-search me-1"></i> Filter
                                    </button>
                                </div>
                            </div>
                        </form>

                        <?php if (!empty($filters['search']) || ($filters['difficulty'] ?? 'all') !== 'all' || ($filters['category'] ?? 'all') !== 'all'): ?>
                        <div class="mt-3">
                            <a href="/adminportal/games/<?= (int) ($game['id'] ?? 0) ?>/questions" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-times me-1"></i> Clear Filters
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Questions Table -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-list me-1"></i> Questions List
                        </h6>
                        <div class="d-flex align-items-center">
                            <span class="badge bg-info me-2">
                                <?= number_format($pagination['total_items'] ?? 0) ?> total questions
                            </span>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-secondary" onclick="selectAll()">
                                    <i class="fas fa-check-square me-1"></i> Select All
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="deselectAll()">
                                    <i class="fas fa-square me-1"></i> Deselect All
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($questions)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-question-circle fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Questions Found</h5>
                                <p class="text-muted">Start by adding questions to make this game playable.</p>
                                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addQuestionModal">
                                    <i class="fas fa-plus me-1"></i> Add First Question
                                </button>
                            </div>
                        <?php else: ?>
                            <!-- Bulk Actions -->
                            <div id="bulkActions" class="alert alert-info" style="display: none;">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span>
                                        <i class="fas fa-info-circle me-1"></i>
                                        <span id="selectedCount">0</span> questions selected
                                    </span>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-danger" onclick="bulkDelete()">
                                            <i class="fas fa-trash me-1"></i> Deactivate Selected
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary" onclick="bulkChangeCategory()">
                                            <i class="fas fa-tag me-1"></i> Change Category
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary" onclick="bulkChangeDifficulty()">
                                            <i class="fas fa-signal me-1"></i> Change Difficulty
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-bordered table-striped" id="questionsTable">
                                    <thead class="table-dark">
                                        <tr>
                                            <th width="40">
                                                <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll()">
                                            </th>
                                            <th>Question</th>
                                            <th>Options & Answer</th>
                                            <th>Difficulty</th>
                                            <th>Category</th>
                                            <th>Performance</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($questions as $question): ?>
                                        <tr data-question-id="<?= (int) $question['id'] ?>">
                                            <td>
                                                <input type="checkbox"
                                                       class="question-checkbox"
                                                       value="<?= (int) $question['id'] ?>"
                                                       onchange="updateBulkActions()">
                                            </td>
                                            <td>
                                                <div class="question-text">
                                                    <?= htmlspecialchars($question['question_text']) ?>
                                                </div>
                                                <?php if (!empty($question['explanation'])): ?>
                                                    <small class="text-muted">
                                                        <i class="fas fa-lightbulb me-1"></i>
                                                        <?= htmlspecialchars(substr($question['explanation'], 0, 100)) ?>
                                                        <?= strlen($question['explanation']) > 100 ? '...' : '' ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="options-preview">
                                                    <div class="small mb-1">
                                                        <span class="me-2">A)</span>
                                                        <span class="<?= $question['correct_answer'] === 'A' ? 'text-success fw-bold' : '' ?>">
                                                            <?= htmlspecialchars($question['option_a']) ?>
                                                        </span>
                                                    </div>
                                                    <div class="small mb-1">
                                                        <span class="me-2">B)</span>
                                                        <span class="<?= $question['correct_answer'] === 'B' ? 'text-success fw-bold' : '' ?>">
                                                            <?= htmlspecialchars($question['option_b']) ?>
                                                        </span>
                                                    </div>
                                                    <div class="small">
                                                        <span class="me-2">C)</span>
                                                        <span class="<?= $question['correct_answer'] === 'C' ? 'text-success fw-bold' : '' ?>">
                                                            <?= htmlspecialchars($question['option_c']) ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php
                                                $difficultyClasses = [
                                                    'easy' => 'success',
                                                    'medium' => 'warning',
                                                    'hard' => 'danger'
                                                ];
                                                $difficultyClass = $difficultyClasses[$question['difficulty_level']] ?? 'secondary';
                                                ?>
                                                <span class="badge bg-<?= $difficultyClass ?>">
                                                    <?= ucfirst($question['difficulty_level']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (!empty($question['category'])): ?>
                                                    <span class="badge bg-info">
                                                        <?= htmlspecialchars($question['category']) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="small">
                                                    <div><strong><?= number_format($question['times_used'] ?? 0) ?></strong> uses</div>
                                                    <?php if (($question['correct_percentage'] ?? null) !== null): ?>
                                                        <div class="text-<?= $question['correct_percentage'] >= 70 ? 'success' : ($question['correct_percentage'] >= 50 ? 'warning' : 'danger') ?>">
                                                            <?= number_format($question['correct_percentage'], 1) ?>% correct
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="text-muted">No data</div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <button type="button"
                                                            class="btn btn-outline-primary"
                                                            onclick="editQuestion(<?= (int) $question['id'] ?>)"
                                                            title="Edit Question">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button type="button"
                                                            class="btn btn-outline-info"
                                                            onclick="previewQuestion(<?= (int) $question['id'] ?>)"
                                                            title="Preview Question">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button type="button"
                                                            class="btn btn-outline-danger"
                                                            onclick="deleteQuestion(<?= (int) $question['id'] ?>)"
                                                            title="Deactivate Question">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <?php if (($pagination['total_pages'] ?? 1) > 1): ?>
                            <nav aria-label="Questions pagination" class="mt-4">
                                <ul class="pagination justify-content-center">
                                    <?php
                                    $currentPage = $pagination['current_page'] ?? 1;
                                    $totalPages = $pagination['total_pages'] ?? 1;
                                    $baseUrl = '/adminportal/games/' . ($game['id'] ?? 0) . '/questions?' . http_build_query(array_filter($filters));
                                    ?>

                                    <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                                        <a class="page-link" href="<?= $baseUrl ?>&page=<?= $currentPage - 1 ?>">Previous</a>
                                    </li>

                                    <?php for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++): ?>
                                        <li class="page-item <?= $i === $currentPage ? 'active' : '' ?>">
                                            <a class="page-link" href="<?= $baseUrl ?>&page=<?= $i ?>"><?= $i ?></a>
                                        </li>
                                    <?php endfor; ?>

                                    <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                                        <a class="page-link" href="<?= $baseUrl ?>&page=<?= $currentPage + 1 ?>">Next</a>
                                    </li>
                                </ul>
                            </nav>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>
    <!-- Add Question Modal -->
    <div class="modal fade" id="addQuestionModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Question</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="addQuestionForm">
                    <div class="modal-body">
                        <input type="hidden" name="game_id" value="<?= (int) ($game['id'] ?? 0) ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '') ?>">

                        <div class="mb-3">
                            <label for="question_text" class="form-label">Question Text <span class="text-danger">*</span></label>
                            <textarea class="form-control"
                                      id="question_text"
                                      name="question_text"
                                      rows="3"
                                      placeholder="Enter your question here..."
                                      maxlength="1000"
                                      required></textarea>
                            <div class="form-text">
                                <span id="questionTextCount">0</span>/1000 characters
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="option_a" class="form-label">Option A <span class="text-danger">*</span></label>
                                <input type="text"
                                       class="form-control"
                                       id="option_a"
                                       name="option_a"
                                       placeholder="Option A"
                                       maxlength="255"
                                       required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="option_b" class="form-label">Option B <span class="text-danger">*</span></label>
                                <input type="text"
                                       class="form-control"
                                       id="option_b"
                                       name="option_b"
                                       placeholder="Option B"
                                       maxlength="255"
                                       required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="option_c" class="form-label">Option C <span class="text-danger">*</span></label>
                                <input type="text"
                                       class="form-control"
                                       id="option_c"
                                       name="option_c"
                                       placeholder="Option C"
                                       maxlength="255"
                                       required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label for="correct_answer" class="form-label">Correct Answer <span class="text-danger">*</span></label>
                                <select class="form-select" id="correct_answer" name="correct_answer" required>
                                    <option value="">Select correct answer</option>
                                    <option value="A">A</option>
                                    <option value="B">B</option>
                                    <option value="C">C</option>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="difficulty_level" class="form-label">Difficulty</label>
                                <select class="form-select" id="difficulty_level" name="difficulty_level">
                                    <option value="easy">Easy</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="hard">Hard</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="category" class="form-label">Category</label>
                                <input type="text"
                                       class="form-control"
                                       id="category"
                                       name="category"
                                       placeholder="e.g., General Knowledge, Technology, Sports"
                                       maxlength="100"
                                       list="categoriesList">
                                <datalist id="categoriesList">
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= htmlspecialchars($cat) ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="explanation" class="form-label">Explanation (Optional)</label>
                            <textarea class="form-control"
                                      id="explanation"
                                      name="explanation"
                                      rows="2"
                                      placeholder="Explain why this is the correct answer..."
                                      maxlength="1000"></textarea>
                            <div class="form-text">
                                <span id="explanationCount">0</span>/1000 characters
                            </div>
                        </div>

                        <!-- Question Preview -->
                        <div class="card bg-light">
                            <div class="card-header">
                                <h6 class="mb-0">Preview</h6>
                            </div>
                            <div class="card-body" id="questionPreview">
                                <div class="text-muted">Fill in the question details to see preview</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-plus me-1"></i> Add Question
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Question Modal -->
    <div class="modal fade" id="editQuestionModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Question</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="editQuestionForm">
                    <div class="modal-body">
                        <input type="hidden" id="edit_question_id" name="question_id">
                        <input type="hidden" name="game_id" value="<?= (int) ($game['id'] ?? 0) ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '') ?>">

                        <div class="mb-3">
                            <label for="edit_question_text" class="form-label">Question Text <span class="text-danger">*</span></label>
                            <textarea class="form-control"
                                      id="edit_question_text"
                                      name="question_text"
                                      rows="3"
                                      maxlength="1000"
                                      required></textarea>
                            <div class="form-text">
                                <span id="editQuestionTextCount">0</span>/1000 characters
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="edit_option_a" class="form-label">Option A <span class="text-danger">*</span></label>
                                <input type="text"
                                       class="form-control"
                                       id="edit_option_a"
                                       name="option_a"
                                       maxlength="255"
                                       required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="edit_option_b" class="form-label">Option B <span class="text-danger">*</span></label>
                                <input type="text"
                                       class="form-control"
                                       id="edit_option_b"
                                       name="option_b"
                                       maxlength="255"
                                       required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="edit_option_c" class="form-label">Option C <span class="text-danger">*</span></label>
                                <input type="text"
                                       class="form-control"
                                       id="edit_option_c"
                                       name="option_c"
                                       maxlength="255"
                                       required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label for="edit_correct_answer" class="form-label">Correct Answer <span class="text-danger">*</span></label>
                                <select class="form-select" id="edit_correct_answer" name="correct_answer" required>
                                    <option value="A">A</option>
                                    <option value="B">B</option>
                                    <option value="C">C</option>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="edit_difficulty_level" class="form-label">Difficulty</label>
                                <select class="form-select" id="edit_difficulty_level" name="difficulty_level">
                                    <option value="easy">Easy</option>
                                    <option value="medium">Medium</option>
                                    <option value="hard">Hard</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="edit_category" class="form-label">Category</label>
                                <input type="text"
                                       class="form-control"
                                       id="edit_category"
                                       name="category"
                                       maxlength="100"
                                       list="categoriesList">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="edit_explanation" class="form-label">Explanation (Optional)</label>
                            <textarea class="form-control"
                                      id="edit_explanation"
                                      name="explanation"
                                      rows="2"
                                      maxlength="1000"></textarea>
                            <div class="form-text">
                                <span id="editExplanationCount">0</span>/1000 characters
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Preview Question Modal -->
    <div class="modal fade" id="previewQuestionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Question Preview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="previewContent">
                        <!-- Question preview will be loaded here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Import Questions Modal -->
    <div class="modal fade" id="importQuestionsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Import Questions from CSV</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="importQuestionsForm" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="game_id" value="<?= (int) ($game['id'] ?? 0) ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '') ?>">

                        <div class="mb-3">
                            <label for="questions_file" class="form-label">CSV File <span class="text-danger">*</span></label>
                            <input type="file"
                                   class="form-control"
                                   id="questions_file"
                                   name="questions_file"
                                   accept=".csv,.txt"
                                   required>
                            <div class="form-text">
                                Maximum file size: 5MB. Only CSV files are accepted.
                            </div>
                        </div>

                        <div class="alert alert-info">
                            <h6><i class="fas fa-info-circle me-2"></i>CSV Format Requirements</h6>
                            <p class="mb-2">Your CSV file must include these columns in order:</p>
                            <ul class="mb-2">
                                <li><strong>question_text</strong> - The question (required)</li>
                                <li><strong>option_a</strong> - First option (required)</li>
                                <li><strong>option_b</strong> - Second option (required)</li>
                                <li><strong>option_c</strong> - Third option (required)</li>
                                <li><strong>correct_answer</strong> - A, B, or C (required)</li>
                                <li><strong>difficulty_level</strong> - easy, medium, or hard (optional, defaults to medium)</li>
                                <li><strong>category</strong> - Category name (optional)</li>
                                <li><strong>explanation</strong> - Answer explanation (optional)</li>
                            </ul>
                            <p class="mb-0">
                                <a href="/adminportal/templates/questions_template.csv" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-download me-1"></i> Download Template
                                </a>
                            </p>
                        </div>

                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="skipDuplicates" name="skip_duplicates" checked>
                                <label class="form-check-label" for="skipDuplicates">
                                    Skip duplicate questions (based on question text)
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-upload me-1"></i> Import Questions
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bulk Actions Modal -->
    <div class="modal fade" id="bulkActionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="bulkActionTitle">Bulk Action</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="bulkActionForm">
                    <div class="modal-body">
                        <input type="hidden" id="bulkActionType" name="action_type">
                        <input type="hidden" id="bulkQuestionIds" name="question_ids">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '') ?>">

                        <div id="bulkActionContent">
                            <!-- Content will be populated by JavaScript -->
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="bulkActionSubmit">
                            Apply Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- Scripts -->
    <script src="/assets/js/bootstrap.bundle.min.js"></script>
    <script src="/assets/js/admin.js"></script>

    <script>
        // Question management
        let selectedQuestions = [];

        // Character counters
        function setupCharacterCounters() {
            const counters = [
                { input: 'question_text', counter: 'questionTextCount' },
                { input: 'explanation', counter: 'explanationCount' },
                { input: 'edit_question_text', counter: 'editQuestionTextCount' },
                { input: 'edit_explanation', counter: 'editExplanationCount' }
            ];

            counters.forEach(item => {
                const input = document.getElementById(item.input);
                const counter = document.getElementById(item.counter);

                if (input && counter) {
                    input.addEventListener('input', function() {
                        counter.textContent = this.value.length;
                        updatePreview();
                    });
                }
            });
        }

        // Update question preview
        function updatePreview() {
            const questionText = document.getElementById('question_text')?.value || '';
            const optionA = document.getElementById('option_a')?.value || '';
            const optionB = document.getElementById('option_b')?.value || '';
            const optionC = document.getElementById('option_c')?.value || '';
            const correctAnswer = document.getElementById('correct_answer')?.value || '';
            const difficulty = document.getElementById('difficulty_level')?.value || '';
            const category = document.getElementById('category')?.value || '';

            const preview = document.getElementById('questionPreview');
            if (!preview) return;

            if (!questionText) {
                preview.innerHTML = '<div class="text-muted">Fill in the question details to see preview</div>';
                return;
            }

            const difficultyClasses = {
                'easy': 'success',
                'medium': 'warning',
                'hard': 'danger'
            };

            let html = `
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h6 class="mb-0">Question Preview</h6>
                        <div>
                            ${difficulty ? `<span class="badge bg-${difficultyClasses[difficulty] || 'secondary'}">${difficulty}</span>` : ''}
                            ${category ? `<span class="badge bg-info ms-1">${category}</span>` : ''}
                        </div>
                    </div>
                    <p class="mb-3">${questionText}</p>
                    <div class="options">
                        ${optionA ? `<div class="mb-2"><span class="me-2 ${correctAnswer === 'A' ? 'text-success fw-bold' : ''}">A)</span>${optionA}</div>` : ''}
                        ${optionB ? `<div class="mb-2"><span class="me-2 ${correctAnswer === 'B' ? 'text-success fw-bold' : ''}">B)</span>${optionB}</div>` : ''}
                        ${optionC ? `<div class="mb-2"><span class="me-2 ${correctAnswer === 'C' ? 'text-success fw-bold' : ''}">C)</span>${optionC}</div>` : ''}
                    </div>
                </div>
            `;

            preview.innerHTML = html;
        }

        // Add question form
        document.getElementById('addQuestionForm').addEventListener('submit', function(e) {
            e.preventDefault();

            if (!validateQuestionForm('addQuestionForm')) {
                return false;
            }

            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Adding...';

            fetch(`/adminportal/games/${<?= (int) ($game['id'] ?? 0) ?>}/questions/add`, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', data.message);
                    bootstrap.Modal.getInstance(document.getElementById('addQuestionModal')).hide();
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showAlert('error', data.error || 'Failed to add question');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('error', 'An error occurred while adding question');
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        });

        // Edit question
        function editQuestion(questionId) {
            fetch(`/adminportal/questions/${questionId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const question = data.question;

                    document.getElementById('edit_question_id').value = question.id;
                    document.getElementById('edit_question_text').value = question.question_text;
                    document.getElementById('edit_option_a').value = question.option_a;
                    document.getElementById('edit_option_b').value = question.option_b;
                    document.getElementById('edit_option_c').value = question.option_c;
                    document.getElementById('edit_correct_answer').value = question.correct_answer;
                    document.getElementById('edit_difficulty_level').value = question.difficulty_level;
                    document.getElementById('edit_category').value = question.category || '';
                    document.getElementById('edit_explanation').value = question.explanation || '';

                    // Update character counters
                    document.getElementById('editQuestionTextCount').textContent = question.question_text.length;
                    document.getElementById('editExplanationCount').textContent = (question.explanation || '').length;

                    const modal = new bootstrap.Modal(document.getElementById('editQuestionModal'));
                    modal.show();
                } else {
                    showAlert('error', 'Failed to load question details');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('error', 'An error occurred while loading question');
            });
        }

        // Edit question form
        document.getElementById('editQuestionForm').addEventListener('submit', function(e) {
            e.preventDefault();

            if (!validateQuestionForm('editQuestionForm')) {
                return false;
            }

            const formData = new FormData(this);
            const questionId = document.getElementById('edit_question_id').value;
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Saving...';

            fetch(`/adminportal/games/${<?= (int) ($game['id'] ?? 0) ?>}/questions/${questionId}/update`, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', data.message);
                    bootstrap.Modal.getInstance(document.getElementById('editQuestionModal')).hide();
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showAlert('error', data.error || 'Failed to update question');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('error', 'An error occurred while updating question');
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        });

        // Preview question
        function previewQuestion(questionId) {
            fetch(`/adminportal/questions/${questionId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const question = data.question;
                    const difficultyClasses = {
                        'easy': 'success',
                        'medium': 'warning',
                        'hard': 'danger'
                    };

                    let html = `
                        <div class="question-preview">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <h6 class="mb-0">Question #${question.id}</h6>
                                <div>
                                    <span class="badge bg-${difficultyClasses[question.difficulty_level] || 'secondary'}">${question.difficulty_level}</span>
                                    ${question.category ? `<span class="badge bg-info ms-1">${question.category}</span>` : ''}
                                </div>
                            </div>
                            <p class="mb-3">${question.question_text}</p>
                            <div class="options mb-3">
                                <div class="mb-2"><span class="me-2 ${question.correct_answer === 'A' ? 'text-success fw-bold' : ''}">A)</span>${question.option_a}</div>
                                <div class="mb-2"><span class="me-2 ${question.correct_answer === 'B' ? 'text-success fw-bold' : ''}">B)</span>${question.option_b}</div>
                                <div class="mb-2"><span class="me-2 ${question.correct_answer === 'C' ? 'text-success fw-bold' : ''}">C)</span>${question.option_c}</div>
                            </div>
                            ${question.explanation ? `<div class="alert alert-info"><strong>Explanation:</strong> ${question.explanation}</div>` : ''}
                            <div class="row text-center">
                                <div class="col-4">
                                    <div class="small text-muted">Times Used</div>
                                    <div class="fw-bold">${question.times_used || 0}</div>
                                </div>
                                <div class="col-4">
                                    <div class="small text-muted">Success Rate</div>
                                    <div class="fw-bold">${question.correct_percentage ? question.correct_percentage.toFixed(1) + '%' : 'No data'}</div>
                                </div>
                                <div class="col-4">
                                    <div class="small text-muted">Status</div>
                                    <div class="fw-bold">${question.is_active ? 'Active' : 'Inactive'}</div>
                                </div>
                            </div>
                        </div>
                    `;

                    document.getElementById('previewContent').innerHTML = html;
                    const modal = new bootstrap.Modal(document.getElementById('previewQuestionModal'));
                    modal.show();
                } else {
                    showAlert('error', 'Failed to load question details');
                }
            });
        }

        // Delete question
        function deleteQuestion(questionId) {
            if (!confirm('Are you sure you want to deactivate this question? It will no longer appear in games.')) {
                return;
            }

            const formData = new FormData();
            formData.append('csrf_token', '<?= htmlspecialchars($csrf_token ?? '') ?>');

            fetch(`/adminportal/games/${<?= (int) ($game['id'] ?? 0) ?>}/questions/${questionId}/delete`, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', data.message);
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showAlert('error', data.error || 'Failed to delete question');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('error', 'An error occurred while deleting question');
            });
        }

        // Selection management
        function toggleSelectAll() {
            const selectAllCheckbox = document.getElementById('selectAllCheckbox');
            const questionCheckboxes = document.querySelectorAll('.question-checkbox');

            questionCheckboxes.forEach(checkbox => {
                checkbox.checked = selectAllCheckbox.checked;
            });

            updateBulkActions();
        }

        function selectAll() {
            document.getElementById('selectAllCheckbox').checked = true;
            toggleSelectAll();
        }

        function deselectAll() {
            document.getElementById('selectAllCheckbox').checked = false;
            toggleSelectAll();
        }

        function updateBulkActions() {
            const checkedBoxes = document.querySelectorAll('.question-checkbox:checked');
            selectedQuestions = Array.from(checkedBoxes).map(cb => cb.value);

            const bulkActions = document.getElementById('bulkActions');
            const selectedCount = document.getElementById('selectedCount');

            if (selectedQuestions.length > 0) {
                bulkActions.style.display = 'block';
                selectedCount.textContent = selectedQuestions.length;
            } else {
                bulkActions.style.display = 'none';
            }

            // Update select all checkbox state
            const allCheckboxes = document.querySelectorAll('.question-checkbox');
            const checkedCount = document.querySelectorAll('.question-checkbox:checked').length;
            const selectAllCheckbox = document.getElementById('selectAllCheckbox');

            if (checkedCount === 0) {
                selectAllCheckbox.indeterminate = false;
                selectAllCheckbox.checked = false;
            } else if (checkedCount === allCheckboxes.length) {
                selectAllCheckbox.indeterminate = false;
                selectAllCheckbox.checked = true;
            } else {
                selectAllCheckbox.indeterminate = true;
            }
        }

        // Bulk actions
        function bulkDelete() {
            if (selectedQuestions.length === 0) return;

            if (!confirm(`Are you sure you want to deactivate ${selectedQuestions.length} selected questions?`)) {
                return;
            }

            showBulkActionModal('delete', 'Deactivate Questions',
                `<p>You are about to deactivate <strong>${selectedQuestions.length}</strong> questions. They will no longer appear in games.</p>
                 <p class="text-danger">This action cannot be undone.</p>`);
        }

        function bulkChangeCategory() {
            if (selectedQuestions.length === 0) return;

            const categories = <?= json_encode($categories) ?>;
            let categoryOptions = '<option value="">No Category</option>';
            categories.forEach(cat => {
                categoryOptions += `<option value="${cat}">${cat}</option>`;
            });

            showBulkActionModal('change_category', 'Change Category',
                `<p>Change category for <strong>${selectedQuestions.length}</strong> selected questions:</p>
                 <div class="mb-3">
                     <label for="bulk_category" class="form-label">New Category</label>
                     <select class="form-select" id="bulk_category" name="category">
                         ${categoryOptions}
                     </select>
                 </div>`);
        }

        function bulkChangeDifficulty() {
            if (selectedQuestions.length === 0) return;

            showBulkActionModal('change_difficulty', 'Change Difficulty',
                `<p>Change difficulty for <strong>${selectedQuestions.length}</strong> selected questions:</p>
                 <div class="mb-3">
                     <label for="bulk_difficulty" class="form-label">New Difficulty</label>
                     <select class="form-select" id="bulk_difficulty" name="difficulty_level" required>
                         <option value="easy">Easy</option>
                         <option value="medium">Medium</option>
                         <option value="hard">Hard</option>
                     </select>
                 </div>`);
        }

        function showBulkActionModal(actionType, title, content) {
            document.getElementById('bulkActionType').value = actionType;
            document.getElementById('bulkQuestionIds').value = selectedQuestions.join(',');
            document.getElementById('bulkActionTitle').textContent = title;
            document.getElementById('bulkActionContent').innerHTML = content;

            const modal = new bootstrap.Modal(document.getElementById('bulkActionModal'));
            modal.show();
        }

        // Import questions
        document.getElementById('importQuestionsForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const fileInput = document.getElementById('questions_file');
            if (!fileInput.files[0]) {
                showAlert('error', 'Please select a CSV file');
                return;
            }

            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Importing...';

            fetch(`/adminportal/games/${<?= (int) ($game['id'] ?? 0) ?>}/questions/import`, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    let message = data.message;
                    if (data.imported_count && data.failed_count) {
                        message += ` (${data.imported_count} imported, ${data.failed_count} failed)`;
                    }
                    showAlert('success', message);
                    bootstrap.Modal.getInstance(document.getElementById('importQuestionsModal')).hide();
                    setTimeout(() => location.reload(), 2000);
                } else {
                    showAlert('error', data.error || 'Failed to import questions');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('error', 'An error occurred while importing questions');
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        });

        // Question validation helper
        function validateQuestionForm(formId) {
            const form = document.getElementById(formId);
            const questionText = form.querySelector('[name="question_text"]').value.trim();
            const optionA = form.querySelector('[name="option_a"]').value.trim();
            const optionB = form.querySelector('[name="option_b"]').value.trim();
            const optionC = form.querySelector('[name="option_c"]').value.trim();
            const correctAnswer = form.querySelector('[name="correct_answer"]').value;

            const errors = [];

            if (!questionText) errors.push('Question text is required');
            if (!optionA) errors.push('Option A is required');
            if (!optionB) errors.push('Option B is required');
            if (!optionC) errors.push('Option C is required');
            if (!correctAnswer) errors.push('Correct answer must be selected');

            // Check for duplicate options
            const options = [optionA, optionB, optionC].filter(opt => opt);
            const uniqueOptions = [...new Set(options)];
            if (options.length !== uniqueOptions.length) {
                errors.push('All options must be different');
            }

            if (errors.length > 0) {
                showAlert('error', errors.join('<br>'));
                return false;
            }

            return true;
        }

        // Show alert messages
        function showAlert(type, message) {
            const alertContainer = document.getElementById('alertContainer') || createAlertContainer();

            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type === 'error' ? 'danger' : type} alert-dismissible fade show`;
            alertDiv.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'} me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;

            alertContainer.appendChild(alertDiv);

            // Auto-dismiss after 5 seconds
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 5000);
        }

        // Create alert container
        function createAlertContainer() {
            const container = document.createElement('div');
            container.id = 'alertContainer';
            container.className = 'position-fixed top-0 end-0 p-3';
            container.style.zIndex = '1050';
            document.body.appendChild(container);
            return container;
        }

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Setup character counters
            setupCharacterCounters();

            // Setup preview updates
            const previewFields = ['question_text', 'option_a', 'option_b', 'option_c', 'correct_answer', 'difficulty_level', 'category'];
            previewFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (field) {
                    field.addEventListener('input', updatePreview);
                    field.addEventListener('change', updatePreview);
                }
            });

            // Setup checkbox listeners
            document.querySelectorAll('.question-checkbox').forEach(checkbox => {
                checkbox.addEventListener('change', updateBulkActions);
            });

            // Reset forms when modals are hidden
            document.getElementById('addQuestionModal').addEventListener('hidden.bs.modal', function() {
                document.getElementById('addQuestionForm').reset();
                document.getElementById('questionTextCount').textContent = '0';
                document.getElementById('explanationCount').textContent = '0';
                updatePreview();
            });
        });
    </script>

    <style>
        .border-left-primary { border-left: 0.25rem solid #4e73df !important; }
        .border-left-success { border-left: 0.25rem solid #1cc88a !important; }
        .border-left-warning { border-left: 0.25rem solid #f6c23e !important; }
        .border-left-danger { border-left: 0.25rem solid #e74a3b !important; }

        .text-xs { font-size: 0.7rem; }
        .font-weight-bold { font-weight: 700 !important; }
        .text-gray-800 { color: #5a5c69 !important; }
        .text-gray-300 { color: #dddfeb !important; }
        .shadow { box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15) !important; }

        .question-text {
            font-weight: 500;
            line-height: 1.4;
            margin-bottom: 0.5rem;
        }

        .options-preview {
            font-size: 0.875rem;
            line-height: 1.3;
        }

        .requirement-item {
            background: #f8f9fc;
            padding: 1rem;
            border-radius: 0.375rem;
            border: 1px solid #e3e6f0;
        }

        .progress {
            height: 0.5rem;
        }

        .card-header {
            background-color: #f8f9fc;
            border-bottom: 1px solid #e3e6f0;
        }

        .table th {
            border-top: none;
            font-weight: 600;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .table td {
            vertical-align: middle;
            font-size: 0.875rem;
        }

        .question-preview {
            background: #fff;
            border: 2px solid #e3e6f0;
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin: 1rem 0;
        }

        .question-preview .options div {
            padding: 0.5rem;
            margin: 0.25rem 0;
            background: #f8f9fa;
            border-radius: 0.25rem;
            border: 1px solid #e9ecef;
        }

        .question-preview .options div:hover {
            background: #e9ecef;
        }

        @media (max-width: 768px) {
            .table-responsive {
                font-size: 0.8rem;
            }

            .btn-group .btn {
                padding: 0.25rem 0.5rem;
                font-size: 0.75rem;
            }

            .badge {
                font-size: 0.7rem;
            }
        }

        .breadcrumb {
            background: none;
            padding: 0;
            margin: 0;
            font-size: 0.875rem;
        }

        .breadcrumb-item + .breadcrumb-item::before {
            content: '>';
            color: #6c757d;
        }
    </style>
</body>
</html>
