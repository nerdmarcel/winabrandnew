<?php
/**
 * File: views/admin/games/edit.php
 * Location: views/admin/games/edit.php
 *
 * WinABN Admin Edit Game Form - Deel 1 van 3
 *
 * Comprehensive game editing interface with statistics, question management,
 * and round monitoring capabilities.
 */

$pageTitle = 'Edit Game: ' . htmlspecialchars($game['name']);
$breadcrumbs = [
    'Dashboard' => '/adminportal/dashboard',
    'Games' => '/adminportal/games',
    'Edit Game' => '/adminportal/games/edit/' . $game['id']
];
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-md-8">
            <h1 class="h3 mb-0">Edit Game</h1>
            <p class="text-muted"><?= htmlspecialchars($game['name']) ?></p>
        </div>
        <div class="col-md-4 text-end">
            <div class="btn-group" role="group">
                <a href="/win-a-<?= htmlspecialchars($game['slug']) ?>" target="_blank" class="btn btn-outline-primary">
                    <i class="fas fa-external-link-alt me-2"></i>Preview Game
                </a>
                <a href="/adminportal/games" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Games
                </a>
            </div>
        </div>
    </div>

    <!-- Game Statistics Row -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-primary bg-opacity-10 rounded-3 p-3">
                                <i class="fas fa-users text-primary fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Total Participants</h6>
                            <h3 class="mb-0"><?= number_format($gameStats['total_participants'] ?? 0) ?></h3>
                            <small class="text-muted">All rounds combined</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-success bg-opacity-10 rounded-3 p-3">
                                <i class="fas fa-pound-sign text-success fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Total Revenue</h6>
                            <h3 class="mb-0">£<?= number_format($gameStats['total_revenue'] ?? 0, 2) ?></h3>
                            <small class="text-muted">All time earnings</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-warning bg-opacity-10 rounded-3 p-3">
                                <i class="fas fa-trophy text-warning fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Completed Rounds</h6>
                            <h3 class="mb-0"><?= number_format($gameStats['completed_rounds'] ?? 0) ?></h3>
                            <small class="text-muted">Winners selected</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-info bg-opacity-10 rounded-3 p-3">
                                <i class="fas fa-question-circle text-info fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Questions</h6>
                            <h3 class="mb-0"><?= count($questions) ?></h3>
                            <small class="text-muted">In question pool</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Main Content -->
        <div class="col-lg-8">
            <!-- Game Settings Form -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white">
                    <ul class="nav nav-tabs card-header-tabs" id="gameEditTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="settings-tab" data-bs-toggle="tab"
                                    data-bs-target="#settings" type="button" role="tab">
                                <i class="fas fa-cog me-2"></i>Settings
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="questions-tab" data-bs-toggle="tab"
                                    data-bs-target="#questions" type="button" role="tab">
                                <i class="fas fa-question-circle me-2"></i>Questions (<?= count($questions) ?>)
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="rounds-tab" data-bs-toggle="tab"
                                    data-bs-target="#rounds" type="button" role="tab">
                                <i class="fas fa-list me-2"></i>Rounds
                            </button>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content" id="gameEditTabsContent">
                        <!-- Settings Tab -->
                        <div class="tab-pane fade show active" id="settings" role="tabpanel">
                            <form method="POST" id="editGameForm">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                                <div class="row g-3">
                                    <div class="col-md-8">
                                        <label for="name" class="form-label">Game Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="name" name="name"
                                               value="<?= htmlspecialchars($game['name']) ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="status" class="form-label">Status</label>
                                        <select class="form-select" id="status" name="status">
                                            <option value="active" <?= $game['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                            <option value="paused" <?= $game['status'] === 'paused' ? 'selected' : '' ?>>Paused</option>
                                            <option value="archived" <?= $game['status'] === 'archived' ? 'selected' : '' ?>>Archived</option>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label for="slug" class="form-label">Game Slug <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text">/win-a-</span>
                                            <input type="text" class="form-control" id="slug" name="slug"
                                                   value="<?= htmlspecialchars($game['slug']) ?>" pattern="[a-z0-9-]+" required>
                                        </div>
                                        <?php if (!empty($activeRounds)): ?>
                                            <div class="form-text text-warning">
                                                <i class="fas fa-exclamation-triangle me-1"></i>
                                                Warning: This game has active rounds. Changing the slug will affect the live URL.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-12">
                                        <label for="description" class="form-label">Description</label>
                                        <textarea class="form-control" id="description" name="description" rows="3"><?= htmlspecialchars($game['description'] ?? '') ?></textarea>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="prize_value" class="form-label">Prize Value <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text">£</span>
                                            <input type="number" class="form-control" id="prize_value" name="prize_value"
                                                   step="0.01" min="0" value="<?= $game['prize_value'] ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="currency" class="form-label">Display Currency</label>
                                        <select class="form-select" id="currency" name="currency">
                                            <option value="GBP" <?= $game['currency'] === 'GBP' ? 'selected' : '' ?>>GBP (£)</option>
                                            <option value="USD" <?= $game['currency'] === 'USD' ? 'selected' : '' ?>>USD ($)</option>
                                            <option value="EUR" <?= $game['currency'] === 'EUR' ? 'selected' : '' ?>>EUR (€)</option>
                                        </select>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="entry_fee" class="form-label">Entry Fee (GBP) <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text">£</span>
                                            <input type="number" class="form-control" id="entry_fee" name="entry_fee"
                                                   step="0.01" min="0.01" value="<?= $game['entry_fee'] ?>" required>
                                        </div>
                                        <?php if (!empty($activeRounds)): ?>
                                            <div class="form-text text-warning">
                                                <i class="fas fa-exclamation-triangle me-1"></i>
                                                Changing entry fee affects new rounds only.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="max_players" class="form-label">Max Participants <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" id="max_players" name="max_players"
                                               min="1" max="10000" value="<?= $game['max_players'] ?>" required>
                                        <?php if (!empty($activeRounds)): ?>
                                            <div class="form-text text-warning">
                                                <i class="fas fa-exclamation-triangle me-1"></i>
                                                Changing max players affects new rounds only.
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="col-md-4">
                                        <label for="total_questions" class="form-label">Total Questions</label>
                                        <input type="number" class="form-control" id="total_questions" name="total_questions"
                                               min="3" max="20" value="<?= $game['total_questions'] ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="free_questions" class="form-label">Free Questions</label>
                                        <input type="number" class="form-control" id="free_questions" name="free_questions"
                                               min="1" max="5" value="<?= $game['free_questions'] ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label for="question_timeout" class="form-label">Question Timeout (seconds)</label>
                                        <input type="number" class="form-control" id="question_timeout" name="question_timeout"
                                               min="5" max="60" value="<?= $game['question_timeout'] ?>">
                                    </div>

                                    <div class="col-12">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="auto_restart" name="auto_restart"
                                                   <?= $game['auto_restart'] ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="auto_restart">
                                                Auto-restart rounds
                                            </label>
                                            <div class="form-text">Automatically start new rounds when current round reaches max participants.</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-4 pt-3 border-top">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Save Changes
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary ms-2" onclick="location.reload()">
                                        <i class="fas fa-undo me-2"></i>Reset
                                    </button>
                                </div>
                            </form>
                        </div>
                        <!-- Questions Tab -->
                        <div class="tab-pane fade" id="questions" role="tabpanel">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <h6 class="mb-1">Question Management</h6>
                                    <small class="text-muted">
                                        <?= count($questions) ?> questions in pool
                                        (recommended: <?= $game['total_questions'] * 3 ?>+ for variety)
                                    </small>
                                </div>
                                <div class="btn-group" role="group">
                                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addQuestionModal">
                                        <i class="fas fa-plus me-1"></i>Add Question
                                    </button>
                                    <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#importQuestionsModal">
                                        <i class="fas fa-upload me-1"></i>Import CSV
                                    </button>
                                </div>
                            </div>

                            <?php if (count($questions) < ($game['total_questions'] * 2)): ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    <strong>Recommendation:</strong> Add more questions for better variety.
                                    Aim for at least <?= $game['total_questions'] * 3 ?> questions total.
                                </div>
                            <?php endif; ?>

                            <?php if (empty($questions)): ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-question-circle text-muted" style="font-size: 3rem;"></i>
                                    <h5 class="text-muted mt-3">No questions added yet</h5>
                                    <p class="text-muted">Add questions to enable this game</p>
                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addQuestionModal">
                                        Add First Question
                                    </button>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Question</th>
                                                <th>Options</th>
                                                <th>Correct</th>
                                                <th>Difficulty</th>
                                                <th>Usage</th>
                                                <th width="100">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($questions as $question): ?>
                                                <tr class="<?= !$question['is_active'] ? 'text-muted' : '' ?>">
                                                    <td>
                                                        <div class="fw-bold"><?= htmlspecialchars(substr($question['question_text'], 0, 60)) ?><?= strlen($question['question_text']) > 60 ? '...' : '' ?></div>
                                                        <?php if (!empty($question['category'])): ?>
                                                            <small class="text-muted">
                                                                <i class="fas fa-tag me-1"></i><?= htmlspecialchars($question['category']) ?>
                                                            </small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <small>
                                                            <strong>A:</strong> <?= htmlspecialchars(substr($question['option_a'], 0, 20)) ?><?= strlen($question['option_a']) > 20 ? '...' : '' ?><br>
                                                            <strong>B:</strong> <?= htmlspecialchars(substr($question['option_b'], 0, 20)) ?><?= strlen($question['option_b']) > 20 ? '...' : '' ?><br>
                                                            <strong>C:</strong> <?= htmlspecialchars(substr($question['option_c'], 0, 20)) ?><?= strlen($question['option_c']) > 20 ? '...' : '' ?>
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-success"><?= $question['correct_answer'] ?></span>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $difficultyColors = [
                                                            'easy' => 'success',
                                                            'medium' => 'warning',
                                                            'hard' => 'danger'
                                                        ];
                                                        $color = $difficultyColors[$question['difficulty_level']] ?? 'secondary';
                                                        ?>
                                                        <span class="badge bg-<?= $color ?>"><?= ucfirst($question['difficulty_level']) ?></span>
                                                    </td>
                                                    <td>
                                                        <small class="text-muted">
                                                            <?= number_format($question['times_used'] ?? 0) ?> times
                                                            <?php if (isset($question['correct_percentage'])): ?>
                                                                <br><?= number_format($question['correct_percentage'], 1) ?>% correct
                                                            <?php endif; ?>
                                                        </small>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group" role="group">
                                                            <button type="button" class="btn btn-outline-primary btn-sm"
                                                                    onclick="editQuestion(<?= $question['id'] ?>)" title="Edit">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-outline-danger btn-sm"
                                                                    onclick="deleteQuestion(<?= $question['id'] ?>)" title="Delete">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Rounds Tab -->
                        <div class="tab-pane fade" id="rounds" role="tabpanel">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <h6 class="mb-1">Game Rounds</h6>
                                    <small class="text-muted">Monitor current and past rounds for this game</small>
                                </div>
                                <a href="/adminportal/rounds?game_id=<?= $game['id'] ?>" class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-external-link-alt me-1"></i>View All Rounds
                                </a>
                            </div>

                            <?php if (!empty($activeRounds)): ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <strong>Active Rounds:</strong> This game currently has <?= count($activeRounds) ?> active round(s).
                                </div>

                                <div class="row">
                                    <?php foreach ($activeRounds as $round): ?>
                                        <div class="col-md-6 mb-3">
                                            <div class="card border-primary">
                                                <div class="card-header bg-primary bg-opacity-10">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <h6 class="mb-0">Round #<?= $round['id'] ?></h6>
                                                        <span class="badge bg-primary"><?= ucfirst($round['status']) ?></span>
                                                    </div>
                                                </div>
                                                <div class="card-body">
                                                    <div class="row text-center">
                                                        <div class="col-6">
                                                            <div class="border-end">
                                                                <h4 class="text-primary mb-0"><?= number_format($round['paid_participant_count']) ?></h4>
                                                                <small class="text-muted">Paid Participants</small>
                                                            </div>
                                                        </div>
                                                        <div class="col-6">
                                                            <h4 class="text-secondary mb-0"><?= number_format($game['max_players']) ?></h4>
                                                            <small class="text-muted">Max Players</small>
                                                        </div>
                                                    </div>
                                                    <div class="mt-3">
                                                        <div class="progress">
                                                            <?php $fillPercentage = ($round['paid_participant_count'] / $game['max_players']) * 100; ?>
                                                            <div class="progress-bar" style="width: <?= min(100, $fillPercentage) ?>%"></div>
                                                        </div>
                                                        <small class="text-muted">
                                                            <?= number_format($fillPercentage, 1) ?>% full
                                                        </small>
                                                    </div>
                                                    <div class="mt-2">
                                                        <small class="text-muted">
                                                            Started: <?= date('M j, Y H:i', strtotime($round['started_at'])) ?>
                                                        </small>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-list text-muted" style="font-size: 2rem;"></i>
                                    <h6 class="text-muted mt-2">No Active Rounds</h6>
                                    <p class="text-muted">New rounds will start automatically when players join</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Quick Actions -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h6 class="card-title mb-0">
                        <i class="fas fa-bolt me-2 text-warning"></i>Quick Actions
                    </h6>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="/win-a-<?= htmlspecialchars($game['slug']) ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-external-link-alt me-2"></i>Preview Game Page
                        </a>
                        <button type="button" class="btn btn-outline-success btn-sm" onclick="toggleGameStatus(<?= $game['id'] ?>)">
                            <?php if ($game['status'] === 'active'): ?>
                                <i class="fas fa-pause me-2"></i>Pause Game
                            <?php else: ?>
                                <i class="fas fa-play me-2"></i>Activate Game
                            <?php endif; ?>
                        </button>
                        <a href="/adminportal/games/<?= $game['id'] ?>/questions" class="btn btn-outline-info btn-sm">
                            <i class="fas fa-question-circle me-2"></i>Manage Questions
                        </a>
                        <a href="/adminportal/participants?game_id=<?= $game['id'] ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-users me-2"></i>View Participants
                        </a>
                    </div>
                </div>
            </div>

            <!-- Game Status -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white">
                    <h6 class="card-title mb-0">
                        <i class="fas fa-info-circle me-2 text-info"></i>Game Status
                    </h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted">Status:</span>
                            <?php
                            $statusColors = [
                                'active' => 'success',
                                'paused' => 'warning',
                                'archived' => 'secondary'
                            ];
                            $statusColor = $statusColors[$game['status']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?= $statusColor ?>"><?= ucfirst($game['status']) ?></span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted">Auto-restart:</span>
                            <span class="badge bg-<?= $game['auto_restart'] ? 'success' : 'secondary' ?>">
                                <?= $game['auto_restart'] ? 'Enabled' : 'Disabled' ?>
                            </span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted">Created:</span>
                            <small><?= date('M j, Y', strtotime($game['created_at'])) ?></small>
                        </div>
                    </div>
                    <div class="mb-0">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted">Last updated:</span>
                            <small><?= date('M j, Y H:i', strtotime($game['updated_at'])) ?></small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Danger Zone -->
            <div class="card shadow-sm border-danger">
                <div class="card-header bg-danger bg-opacity-10 border-danger">
                    <h6 class="card-title mb-0 text-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>Danger Zone
                    </h6>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        These actions cannot be undone. Please proceed with caution.
                    </p>
                    <div class="d-grid">
                        <button type="button" class="btn btn-outline-danger btn-sm"
                                onclick="archiveGame(<?= $game['id'] ?>, '<?= htmlspecialchars($game['name'], ENT_QUOTES) ?>')">
                            <i class="fas fa-archive me-2"></i>Archive Game
                        </button>
                    </div>
                </div>
            </div>
        </div>
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
                    <div class="row g-3">
                        <div class="col-12">
                            <label for="question_text" class="form-label">Question Text <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="question_text" name="question_text" rows="3"
                                      placeholder="Enter your question here..." required></textarea>
                        </div>
                        <div class="col-md-6">
                            <label for="option_a" class="form-label">Option A <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="option_a" name="option_a"
                                   placeholder="First option" required>
                        </div>
                        <div class="col-md-6">
                            <label for="option_b" class="form-label">Option B <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="option_b" name="option_b"
                                   placeholder="Second option" required>
                        </div>
                        <div class="col-md-6">
                            <label for="option_c" class="form-label">Option C <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="option_c" name="option_c"
                                   placeholder="Third option" required>
                        </div>
                        <div class="col-md-6">
                            <label for="correct_answer" class="form-label">Correct Answer <span class="text-danger">*</span></label>
                            <select class="form-select" id="correct_answer" name="correct_answer" required>
                                <option value="">Select correct answer</option>
                                <option value="A">A</option>
                                <option value="B">B</option>
                                <option value="C">C</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="difficulty_level" class="form-label">Difficulty Level</label>
                            <select class="form-select" id="difficulty_level" name="difficulty_level">
                                <option value="easy">Easy</option>
                                <option value="medium" selected>Medium</option>
                                <option value="hard">Hard</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="category" class="form-label">Category</label>
                            <input type="text" class="form-control" id="category" name="category"
                                   placeholder="e.g., Technology, Science, General" list="categoryList">
                            <datalist id="categoryList">
                                <option value="Technology">
                                <option value="Science">
                                <option value="General">
                                <option value="Geography">
                                <option value="History">
                                <option value="Sports">
                                <option value="Entertainment">
                            </datalist>
                        </div>
                        <div class="col-12">
                            <label for="explanation" class="form-label">Explanation (Optional)</label>
                            <textarea class="form-control" id="explanation" name="explanation" rows="2"
                                      placeholder="Explain why this is the correct answer..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Question</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Import Questions Modal -->
<div class="modal fade" id="importQuestionsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Import Questions from CSV</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="importQuestionsForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="csv_file" class="form-label">CSV File <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" id="csv_file" name="csv_file"
                               accept=".csv" required>
                        <div class="form-text">Upload a CSV file with question data.</div>
                    </div>

                    <div class="alert alert-info">
                        <strong>CSV Format Requirements:</strong>
                        <ul class="mb-0 mt-2">
                            <li><code>question_text</code> - The question (required)</li>
                            <li><code>option_a</code> - First option (required)</li>
                            <li><code>option_b</code> - Second option (required)</li>
                            <li><code>option_c</code> - Third option (required)</li>
                            <li><code>correct_answer</code> - A, B, or C (required)</li>
                            <li><code>difficulty_level</code> - easy, medium, or hard</li>
                            <li><code>category</code> - Question category</li>
                            <li><code>explanation</code> - Answer explanation</li>
                        </ul>
                    </div>

                    <div class="mb-3">
                        <a href="/adminportal/games/sample-questions.csv" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-download me-1"></i>Download Sample CSV
                        </a>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Import Questions</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const gameId = <?= $game['id'] ?>;

// Toggle game status
function toggleGameStatus(gameId) {
    if (confirm('Are you sure you want to change this game\'s status?')) {
        fetch(`/adminportal/games/${gameId}/toggle-status`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': '<?= $csrfToken ?>'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showAlert('success', data.message);
                setTimeout(() => location.reload(), 1000);
            } else {
                showAlert('danger', data.error);
            }
        })
        .catch(error => {
            showAlert('danger', 'An error occurred');
        });
    }
}

// Archive game
function archiveGame(gameId, gameName) {
    if (confirm(`Are you sure you want to archive "${gameName}"? This action cannot be undone.`)) {
        fetch(`/adminportal/games/${gameId}/delete`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': '<?= $csrfToken ?>'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showAlert('success', data.message);
                setTimeout(() => window.location.href = '/adminportal/games', 1500);
            } else {
                showAlert('danger', data.error);
            }
        })
        .catch(error => {
            showAlert('danger', 'An error occurred');
        });
    }
}

// Add question form submission
document.getElementById('addQuestionForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    formData.append('csrf_token', '<?= $csrfToken ?>');

    fetch(`/adminportal/games/${gameId}/add-question`, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert('success', data.message);
            document.getElementById('addQuestionModal').querySelector('.btn-close').click();
            this.reset();
            setTimeout(() => location.reload(), 1000);
        } else {
            showAlert('danger', data.error);
        }
    })
    .catch(error => {
        showAlert('danger', 'An error occurred while adding question');
    });
});

// Import questions form submission
document.getElementById('importQuestionsForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    formData.append('csrf_token', '<?= $csrfToken ?>');

    const submitBtn = this.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Importing...';

    fetch(`/adminportal/games/${gameId}/import-questions`, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert('success', data.message);
            document.getElementById('importQuestionsModal').querySelector('.btn-close').click();
            this.reset();
            setTimeout(() => location.reload(), 1000);
        } else {
            showAlert('danger', data.error);
        }
    })
    .catch(error => {
        showAlert('danger', 'An error occurred during import');
    })
    .finally(() => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = 'Import Questions';
    });
});

// Edit question
function editQuestion(questionId) {
    // This would typically open a modal with the question data pre-filled
    // For now, redirect to a dedicated edit page
    window.location.href = `/adminportal/games/${gameId}/questions/${questionId}/edit`;
}

// Delete question
function deleteQuestion(questionId) {
    if (confirm('Are you sure you want to delete this question? This action cannot be undone.')) {
        fetch(`/adminportal/games/${gameId}/questions/${questionId}/delete`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': '<?= $csrfToken ?>'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showAlert('success', data.message);
                setTimeout(() => location.reload(), 1000);
            } else {
                showAlert('danger', data.error);
            }
        })
        .catch(error => {
            showAlert('danger', 'An error occurred');
        });
    }
}

// Form validation for edit game
document.getElementById('editGameForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (response.ok) {
            showAlert('success', 'Game updated successfully');
            setTimeout(() => location.reload(), 1000);
        } else {
            showAlert('danger', 'Failed to update game');
        }
    })
    .catch(error => {
        showAlert('danger', 'An error occurred');
    })
    .finally(() => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-save me-2"></i>Save Changes';
    });
});

// Validate free questions don't exceed total questions
document.getElementById('total_questions').addEventListener('input', function() {
    const totalQuestions = parseInt(this.value) || 9;
    const freeQuestionsField = document.getElementById('free_questions');
    const currentFreeQuestions = parseInt(freeQuestionsField.value) || 3;

    if (currentFreeQuestions >= totalQuestions) {
        freeQuestionsField.value = Math.max(1, totalQuestions - 1);
    }

    freeQuestionsField.max = totalQuestions - 1;
});

// Show alert function
function showAlert(type, message) {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
    alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;

    document.body.appendChild(alertDiv);

    setTimeout(() => {
        if (alertDiv.parentNode) {
            alertDiv.parentNode.removeChild(alertDiv);
        }
    }, 5000);
}

// Auto-save draft functionality (optional enhancement)
let autoSaveTimeout;
document.querySelectorAll('#editGameForm input, #editGameForm textarea, #editGameForm select').forEach(field => {
    field.addEventListener('input', function() {
        clearTimeout(autoSaveTimeout);
        autoSaveTimeout = setTimeout(() => {
            // Could implement auto-save draft functionality here
            console.log('Auto-saving draft...');
        }, 2000);
    });
});
</script>
