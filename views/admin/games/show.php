<?php
/**
 * File: views/admin/games/show.php
 * Location: views/admin/games/show.php
 *
 * WinABN Admin Game Details & Edit Page
 *
 * Comprehensive game management interface with real-time statistics,
 * editing capabilities, question management, and analytics overview.
 */

// Prevent direct access
if (!defined('WINABN_ADMIN')) {
    exit('Access denied');
}

$pageTitle = $title ?? 'Game Details';
$game = $game ?? [];
$gameStats = $gameStats ?? [];
$questions = $questions ?? [];
$questionStats = $questionStats ?? [];
$activeRounds = $activeRounds ?? [];
$recentRounds = $recentRounds ?? [];
$revenue_data = $revenue_data ?? [];
$performance_metrics = $performance_metrics ?? [];
$canModify = $canModify ?? true;
$currencies = $currencies ?? ['GBP', 'USD', 'EUR', 'CAD', 'AUD'];
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
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
                            <i class="fas fa-gamepad me-2"></i>
                            <?= htmlspecialchars($game['name'] ?? 'Unknown Game') ?>
                        </h1>
                        <div class="d-flex align-items-center mt-2">
                            <?php
                            $statusClasses = [
                                'active' => 'success',
                                'paused' => 'warning',
                                'archived' => 'secondary'
                            ];
                            $statusClass = $statusClasses[$game['status']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?= $statusClass ?> me-2">
                                <?= ucfirst($game['status'] ?? 'Unknown') ?>
                            </span>
                            <small class="text-muted">
                                <i class="fas fa-link me-1"></i>
                                /<strong><?= htmlspecialchars($game['slug'] ?? '') ?></strong>
                            </small>
                            <?php if (!empty($activeRounds)): ?>
                                <span class="badge bg-info ms-2">
                                    <i class="fas fa-circle-notch fa-spin me-1"></i>
                                    <?= count($activeRounds) ?> Active Round<?= count($activeRounds) > 1 ? 's' : '' ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <a href="/adminportal/games" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-1"></i> Back to Games
                            </a>
                            <a href="/adminportal/games/<?= (int) ($game['id'] ?? 0) ?>/questions" class="btn btn-outline-info">
                                <i class="fas fa-question-circle me-1"></i> Manage Questions
                            </a>
                            <a href="/adminportal/games/<?= (int) ($game['id'] ?? 0) ?>/analytics" class="btn btn-outline-success">
                                <i class="fas fa-chart-line me-1"></i> Analytics
                            </a>
                        </div>
                        <div class="btn-group">
                            <?php if ($game['status'] === 'active'): ?>
                                <button type="button" class="btn btn-warning" onclick="toggleGameStatus('<?= (int) ($game['id'] ?? 0) ?>', 'paused')">
                                    <i class="fas fa-pause me-1"></i> Pause Game
                                </button>
                            <?php elseif ($game['status'] === 'paused'): ?>
                                <button type="button" class="btn btn-success" onclick="toggleGameStatus('<?= (int) ($game['id'] ?? 0) ?>', 'active')">
                                    <i class="fas fa-play me-1"></i> Activate Game
                                </button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                            <ul class="dropdown-menu">
                                <li>
                                    <a class="dropdown-item" href="#" onclick="duplicateGame(<?= (int) ($game['id'] ?? 0) ?>)">
                                        <i class="fas fa-copy me-1"></i> Duplicate Game
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="#" onclick="exportGameData(<?= (int) ($game['id'] ?? 0) ?>)">
                                        <i class="fas fa-download me-1"></i> Export Data
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <?php if (empty($activeRounds)): ?>
                                    <li>
                                        <a class="dropdown-item text-danger" href="#" onclick="archiveGame(<?= (int) ($game['id'] ?? 0) ?>)">
                                            <i class="fas fa-archive me-1"></i> Archive Game
                                        </a>
                                    </li>
                                <?php else: ?>
                                    <li>
                                        <span class="dropdown-item-text text-muted">
                                            <i class="fas fa-info-circle me-1"></i>
                                            Cannot archive with active rounds
                                        </span>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Game Overview Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card border-left-primary shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                            Total Participants
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?= number_format($performance_metrics['total_participants'] ?? 0) ?>
                                        </div>
                                        <div class="small text-success">
                                            <?= number_format($performance_metrics['paid_participants'] ?? 0) ?> paid
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-users fa-2x text-gray-300"></i>
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
                                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                            Total Revenue
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            £<?= number_format($gameStats['total_revenue'] ?? 0, 2) ?>
                                        </div>
                                        <div class="small text-muted">
                                            Avg: £<?= number_format(($gameStats['total_revenue'] ?? 0) / max(1, $performance_metrics['paid_participants'] ?? 1), 2) ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-pound-sign fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card border-left-info shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                            Conversion Rate
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?= number_format($performance_metrics['payment_conversion_rate'] ?? 0, 1) ?>%
                                        </div>
                                        <div class="w-100">
                                            <div class="progress progress-sm">
                                                <div class="progress-bar bg-info" style="width: <?= min(100, $performance_metrics['payment_conversion_rate'] ?? 0) ?>%"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-percentage fa-2x text-gray-300"></i>
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
                                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                            Avg Completion Time
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?= number_format($performance_metrics['avg_completion_time'] ?? 0, 1) ?>s
                                        </div>
                                        <div class="small text-muted">
                                            <?= number_format($performance_metrics['completion_rate'] ?? 0, 1) ?>% complete games
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-stopwatch fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Left Column -->
                    <div class="col-lg-8">
                        <!-- Game Details & Edit Form -->
                        <div class="card shadow mb-4">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">
                                    <i class="fas fa-edit me-1"></i> Game Details
                                    <?php if (!$canModify): ?>
                                        <span class="badge bg-warning ms-2">
                                            <i class="fas fa-lock me-1"></i> Active rounds - limited editing
                                        </span>
                                    <?php endif; ?>
                                </h6>
                            </div>
                            <div class="card-body">
                                <form id="gameDetailsForm" method="POST" action="/adminportal/games/<?= (int) ($game['id'] ?? 0) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '') ?>">

                                    <!-- Basic Information -->
                                    <div class="row mb-3">
                                        <div class="col-md-8">
                                            <label for="name" class="form-label">Game Name</label>
                                            <input type="text"
                                                   class="form-control"
                                                   id="name"
                                                   name="name"
                                                   value="<?= htmlspecialchars($game['name'] ?? '') ?>"
                                                   maxlength="255"
                                                   required>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="status" class="form-label">Status</label>
                                            <select class="form-select" id="status" name="status">
                                                <option value="active" <?= ($game['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                                                <option value="paused" <?= ($game['status'] ?? '') === 'paused' ? 'selected' : '' ?>>Paused</option>
                                                <option value="archived" <?= ($game['status'] ?? '') === 'archived' ? 'selected' : '' ?>>Archived</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="slug" class="form-label">URL Slug</label>
                                            <input type="text"
                                                   class="form-control"
                                                   id="slug"
                                                   name="slug"
                                                   value="<?= htmlspecialchars($game['slug'] ?? '') ?>"
                                                   pattern="win-a-[a-z0-9-]+"
                                                   maxlength="100"
                                                   <?= $canModify ? '' : 'readonly' ?>
                                                   required>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="currency" class="form-label">Currency</label>
                                            <select class="form-select" id="currency" name="currency" <?= $canModify ? '' : 'disabled' ?>>
                                                <?php
                                                $currencyNames = [
                                                    'GBP' => 'British Pound (£)',
                                                    'USD' => 'US Dollar ($)',
                                                    'EUR' => 'Euro (€)',
                                                    'CAD' => 'Canadian Dollar (C$)',
                                                    'AUD' => 'Australian Dollar (A$)'
                                                ];
                                                foreach ($currencies as $currencyCode):
                                                    $selected = ($game['currency'] ?? 'GBP') === $currencyCode ? 'selected' : '';
                                                ?>
                                                    <option value="<?= $currencyCode ?>" <?= $selected ?>>
                                                        <?= $currencyNames[$currencyCode] ?? $currencyCode ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="description" class="form-label">Description</label>
                                        <textarea class="form-control"
                                                  id="description"
                                                  name="description"
                                                  rows="3"
                                                  maxlength="1000"
                                                  required><?= htmlspecialchars($game['description'] ?? '') ?></textarea>
                                        <div class="form-text">
                                            <span id="descriptionCount"><?= strlen($game['description'] ?? '') ?></span>/1000 characters
                                        </div>
                                    </div>

                                    <!-- Pricing -->
                                    <h6 class="text-primary mb-3">
                                        <i class="fas fa-pound-sign me-1"></i> Pricing Settings
                                    </h6>

                                    <div class="row mb-3">
                                        <div class="col-md-4">
                                            <label for="prize_value" class="form-label">Prize Value</label>
                                            <div class="input-group">
                                                <span class="input-group-text">£</span>
                                                <input type="number"
                                                       class="form-control"
                                                       id="prize_value"
                                                       name="prize_value"
                                                       value="<?= number_format($game['prize_value'] ?? 0, 2, '.', '') ?>"
                                                       min="0.01"
                                                       max="100000"
                                                       step="0.01"
                                                       required>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="entry_fee" class="form-label">Entry Fee</label>
                                            <div class="input-group">
                                                <span class="input-group-text">£</span>
                                                <input type="number"
                                                       class="form-control"
                                                       id="entry_fee"
                                                       name="entry_fee"
                                                       value="<?= number_format($game['entry_fee'] ?? 0, 2, '.', '') ?>"
                                                       min="0.50"
                                                       max="1000"
                                                       step="0.01"
                                                       <?= $canModify ? '' : 'readonly' ?>
                                                       required>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="max_players" class="form-label">Max Players</label>
                                            <input type="number"
                                                   class="form-control"
                                                   id="max_players"
                                                   name="max_players"
                                                   value="<?= (int) ($game['max_players'] ?? 1000) ?>"
                                                   min="10"
                                                   max="10000"
                                                   <?= $canModify ? '' : 'readonly' ?>
                                                   required>
                                        </div>
                                    </div>

                                    <!-- Game Settings -->
                                    <h6 class="text-primary mb-3">
                                        <i class="fas fa-cogs me-1"></i> Game Settings
                                    </h6>

                                    <div class="row mb-3">
                                        <div class="col-md-3">
                                            <label for="total_questions" class="form-label">Total Questions</label>
                                            <input type="number"
                                                   class="form-control"
                                                   id="total_questions"
                                                   name="total_questions"
                                                   value="<?= (int) ($game['total_questions'] ?? 9) ?>"
                                                   min="6"
                                                   max="20"
                                                   <?= $canModify ? '' : 'readonly' ?>
                                                   required>
                                        </div>
                                        <div class="col-md-3">
                                            <label for="free_questions" class="form-label">Free Questions</label>
                                            <input type="number"
                                                   class="form-control"
                                                   id="free_questions"
                                                   name="free_questions"
                                                   value="<?= (int) ($game['free_questions'] ?? 3) ?>"
                                                   min="1"
                                                   max="10"
                                                   <?= $canModify ? '' : 'readonly' ?>
                                                   required>
                                        </div>
                                        <div class="col-md-3">
                                            <label for="question_timeout" class="form-label">Question Timeout</label>
                                            <div class="input-group">
                                                <input type="number"
                                                       class="form-control"
                                                       id="question_timeout"
                                                       name="question_timeout"
                                                       value="<?= (int) ($game['question_timeout'] ?? 10) ?>"
                                                       min="5"
                                                       max="60"
                                                       <?= $canModify ? '' : 'readonly' ?>
                                                       required>
                                                <span class="input-group-text">sec</span>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Auto-restart</label>
                                            <div class="form-check form-switch mt-2">
                                                <input class="form-check-input"
                                                       type="checkbox"
                                                       id="auto_restart"
                                                       name="auto_restart"
                                                       <?= ($game['auto_restart'] ?? false) ? 'checked' : '' ?>
                                                       <?= $canModify ? '' : 'disabled' ?>>
                                                <label class="form-check-label" for="auto_restart">
                                                    Enabled
                                                </label>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <small class="text-muted">
                                                <i class="fas fa-clock me-1"></i>
                                                Created: <?= date('M j, Y g:i A', strtotime($game['created_at'] ?? 'now')) ?>
                                                <?php if ($game['updated_at'] ?? null): ?>
                                                    <br>Updated: <?= date('M j, Y g:i A', strtotime($game['updated_at'])) ?>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save me-1"></i> Save Changes
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Revenue Chart -->
                        <?php if (!empty($revenue_data)): ?>
                        <div class="card shadow mb-4">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">
                                    <i class="fas fa-chart-area me-1"></i> Revenue Trend (Last 30 Days)
                                </h6>
                            </div>
                            <div class="card-body">
                                <canvas id="revenueChart" height="100"></canvas>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Recent Questions Preview -->
                        <div class="card shadow mb-4">
                            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                                <h6 class="m-0 font-weight-bold text-primary">
                                    <i class="fas fa-question-circle me-1"></i> Questions Overview
                                </h6>
                                <a href="/adminportal/games/<?= (int) ($game['id'] ?? 0) ?>/questions" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-cog me-1"></i> Manage All Questions
                                </a>
                            </div>
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <div class="text-center">
                                            <div class="h4 text-primary"><?= number_format($questionStats['total_active'] ?? 0) ?></div>
                                            <div class="small text-muted">Active Questions</div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-center">
                                            <div class="h4 text-success"><?= number_format($questionStats['difficulty']['easy'] ?? 0) ?></div>
                                            <div class="small text-muted">Easy</div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-center">
                                            <div class="h4 text-warning"><?= number_format($questionStats['difficulty']['medium'] ?? 0) ?></div>
                                            <div class="small text-muted">Medium</div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-center">
                                            <div class="h4 text-danger"><?= number_format($questionStats['difficulty']['hard'] ?? 0) ?></div>
                                            <div class="small text-muted">Hard</div>
                                        </div>
                                    </div>
                                </div>

                                <?php if (($questionStats['total_active'] ?? 0) < 27): ?>
                                    <div class="alert alert-warning">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        <strong>Action Required:</strong> You need at least 27 questions for optimal gameplay.
                                        Currently have <?= (int) ($questionStats['total_active'] ?? 0) ?> questions.
                                        <a href="/adminportal/games/<?= (int) ($game['id'] ?? 0) ?>/questions" class="alert-link">Add more questions</a>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($questions)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Question</th>
                                                    <th>Difficulty</th>
                                                    <th>Usage</th>
                                                    <th>Success Rate</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach (array_slice($questions, 0, 5) as $question): ?>
                                                <tr>
                                                    <td>
                                                        <?= htmlspecialchars(substr($question['question_text'] ?? '', 0, 60)) ?>
                                                        <?= strlen($question['question_text'] ?? '') > 60 ? '...' : '' ?>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $difficultyClasses = ['easy' => 'success', 'medium' => 'warning', 'hard' => 'danger'];
                                                        $difficultyClass = $difficultyClasses[$question['difficulty_level']] ?? 'secondary';
                                                        ?>
                                                        <span class="badge bg-<?= $difficultyClass ?>">
                                                            <?= ucfirst($question['difficulty_level'] ?? '') ?>
                                                        </span>
                                                    </td>
                                                    <td><?= number_format($question['times_used'] ?? 0) ?></td>
                                                    <td>
                                                        <?php if (($question['correct_percentage'] ?? null) !== null): ?>
                                                            <?= number_format($question['correct_percentage'], 1) ?>%
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php if (count($questions) > 5): ?>
                                        <div class="text-center">
                                            <a href="/adminportal/games/<?= (int) ($game['id'] ?? 0) ?>/questions" class="btn btn-sm btn-outline-secondary">
                                                View All <?= count($questions) ?> Questions
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="text-center py-3">
                                        <i class="fas fa-question-circle fa-3x text-muted mb-3"></i>
                                        <h6 class="text-muted">No Questions Added Yet</h6>
                                        <p class="text-muted">Add questions to make this game playable.</p>
                                        <a href="/adminportal/games/<?= (int) ($game['id'] ?? 0) ?>/questions" class="btn btn-primary">
                                            <i class="fas fa-plus me-1"></i> Add Questions
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div class="col-lg-4">
                        <!-- Active Rounds -->
                        <?php if (!empty($activeRounds)): ?>
                        <div class="card shadow mb-4">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">
                                    <i class="fas fa-circle-notch fa-spin me-1"></i> Active Rounds
                                </h6>
                            </div>
                            <div class="card-body">
                                <?php foreach ($activeRounds as $round): ?>
                                    <div class="border rounded p-3 mb-3">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <h6 class="mb-0">Round #<?= (int) $round['id'] ?></h6>
                                            <span class="badge bg-success">Active</span>
                                        </div>
                                        <div class="row">
                                            <div class="col-6">
                                                <small class="text-muted">Participants</small>
                                                <div class="font-weight-bold">
                                                    <?= number_format($round['paid_participant_count'] ?? 0) ?> / <?= number_format($game['max_players'] ?? 0) ?>
                                                </div>
                                                <div class="progress progress-sm mt-1">
                                                    <?php $fillPercentage = min(100, (($round['paid_participant_count'] ?? 0) / max(1, $game['max_players'] ?? 1)) * 100); ?>
                                                    <div class="progress-bar bg-success" style="width: <?= $fillPercentage ?>%"></div>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <small class="text-muted">Started</small>
                                                <div class="small">
                                                    <?= date('M j, g:i A', strtotime($round['started_at'] ?? 'now')) ?>
                                                </div>
                                                <div class="small text-muted">
                                                    <?php
                                                    $startTime = strtotime($round['started_at'] ?? 'now');
                                                    $duration = time() - $startTime;
                                                    $hours = floor($duration / 3600);
                                                    $minutes = floor(($duration % 3600) / 60);
                                                    echo $hours > 0 ? "{$hours}h {$minutes}m ago" : "{$minutes}m ago";
                                                    ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Recent Rounds -->
                        <?php if (!empty($recentRounds)): ?>
                        <div class="card shadow mb-4">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">
                                    <i class="fas fa-history me-1"></i> Recent Rounds
                                </h6>
                            </div>
                            <div class="card-body">
                                <?php foreach ($recentRounds as $round): ?>
                                    <div class="border rounded p-3 mb-3">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <h6 class="mb-0">Round #<?= (int) $round['id'] ?></h6>
                                            <?php
                                            $statusClasses = [
                                                'completed' => 'success',
                                                'active' => 'primary',
                                                'cancelled' => 'danger'
                                            ];
                                            $statusClass = $statusClasses[$round['status']] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?= $statusClass ?>">
                                                <?= ucfirst($round['status'] ?? '') ?>
                                            </span>
                                        </div>
                                        <div class="small text-muted mb-1">
                                            <?= number_format($round['paid_participant_count'] ?? 0) ?> participants
                                            <?php if ($round['status'] === 'completed' && !empty($round['winner_participant_id'])): ?>
                                                • Winner selected
                                            <?php endif; ?>
                                        </div>
                                        <div class="small text-muted">
                                            <?= date('M j, Y g:i A', strtotime($round['started_at'] ?? 'now')) ?>
                                            <?php if ($round['completed_at'] ?? null): ?>
                                                - <?= date('g:i A', strtotime($round['completed_at'])) ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <div class="text-center">
                                    <a href="/adminportal/rounds?game_id=<?= (int) ($game['id'] ?? 0) ?>" class="btn btn-sm btn-outline-secondary">
                                        View All Rounds
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Game Statistics -->
                        <div class="card shadow mb-4">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">
                                    <i class="fas fa-chart-pie me-1"></i> Statistics
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-6">
                                        <div class="text-center border-end">
                                            <div class="h5 text-primary mb-0"><?= number_format($performance_metrics['total_rounds'] ?? 0) ?></div>
                                            <div class="small text-muted">Total Rounds</div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-center">
                                            <div class="h5 text-success mb-0"><?= number_format($performance_metrics['completed_rounds'] ?? 0) ?></div>
                                            <div class="small text-muted">Completed</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <small>Payment Conversion</small>
                                        <small><?= number_format($performance_metrics['payment_conversion_rate'] ?? 0, 1) ?>%</small>
                                    </div>
                                    <div class="progress progress-sm">
                                        <div class="progress-bar bg-info" style="width: <?= min(100, $performance_metrics['payment_conversion_rate'] ?? 0) ?>%"></div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <small>Game Completion</small>
                                        <small><?= number_format($performance_metrics['completion_rate'] ?? 0, 1) ?>%</small>
                                    </div>
                                    <div class="progress progress-sm">
                                        <div class="progress-bar bg-success" style="width: <?= min(100, $performance_metrics['completion_rate'] ?? 0) ?>%"></div>
                                    </div>
                                </div>

                                <hr>

                                <div class="row text-center">
                                    <div class="col-12 mb-2">
                                        <div class="small text-muted">Revenue per Participant</div>
                                        <div class="font-weight-bold">
                                            £<?= number_format(($gameStats['total_revenue'] ?? 0) / max(1, $performance_metrics['paid_participants'] ?? 1), 2) ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="card shadow mb-4">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">
                                    <i class="fas fa-bolt me-1"></i> Quick Actions
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <a href="/<?= htmlspecialchars($game['slug'] ?? '') ?>"
                                       class="btn btn-outline-primary btn-sm"
                                       target="_blank">
                                        <i class="fas fa-external-link-alt me-1"></i> View Public Game Page
                                    </a>

                                    <button type="button"
                                            class="btn btn-outline-info btn-sm"
                                            onclick="copyGameUrl()">
                                        <i class="fas fa-copy me-1"></i> Copy Game URL
                                    </button>

                                    <a href="/adminportal/games/<?= (int) ($game['id'] ?? 0) ?>/questions"
                                       class="btn btn-outline-secondary btn-sm">
                                        <i class="fas fa-plus me-1"></i> Add Questions
                                    </a>

                                    <button type="button"
                                            class="btn btn-outline-warning btn-sm"
                                            onclick="testGame()">
                                        <i class="fas fa-play me-1"></i> Test Game
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Game URL & Sharing -->
                        <div class="card shadow mb-4">
                            <div class="card-header py-3">
                                <h6 class="m-0 font-weight-bold text-primary">
                                    <i class="fas fa-share-alt me-1"></i> Sharing & URLs
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label small">Public Game URL</label>
                                    <div class="input-group input-group-sm">
                                        <input type="text"
                                               class="form-control"
                                               id="gameUrl"
                                               value="<?= htmlspecialchars(url($game['slug'] ?? '')) ?>"
                                               readonly>
                                        <button class="btn btn-outline-secondary" type="button" onclick="copyGameUrl()">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label small">Referral URL</label>
                                    <div class="input-group input-group-sm">
                                        <input type="text"
                                               class="form-control"
                                               id="referralUrl"
                                               value="<?= htmlspecialchars(url($game['slug'] ?? '') . '?ref=' . base64_encode('admin@winabn.com')) ?>"
                                               readonly>
                                        <button class="btn btn-outline-secondary" type="button" onclick="copyReferralUrl()">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="d-grid gap-2">
                                    <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode(url($game['slug'] ?? '')) ?>"
                                       target="_blank"
                                       class="btn btn-outline-primary btn-sm">
                                        <i class="fab fa-facebook me-1"></i> Share on Facebook
                                    </a>
                                    <a href="https://twitter.com/intent/tweet?url=<?= urlencode(url($game['slug'] ?? '')) ?>&text=<?= urlencode('Win ' . ($game['name'] ?? '')) ?>"
                                       target="_blank"
                                       class="btn btn-outline-info btn-sm">
                                        <i class="fab fa-twitter me-1"></i> Share on Twitter
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modals -->

    <!-- Duplicate Game Modal -->
    <div class="modal fade" id="duplicateGameModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Duplicate Game</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="duplicateGameForm">
                    <div class="modal-body">
                        <input type="hidden" id="duplicateGameId" name="game_id">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '') ?>">

                        <div class="mb-3">
                            <label for="newGameName" class="form-label">New Game Name</label>
                            <input type="text" class="form-control" id="newGameName" name="new_name" required>
                            <div class="form-text">Enter a unique name for the duplicated game.</div>
                        </div>

                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            This will create a copy of the game with all questions. The new game will start in paused status.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-copy me-1"></i> Duplicate Game
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Export Data Modal -->
    <div class="modal fade" id="exportDataModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Export Game Data</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="exportDataForm">
                    <div class="modal-body">
                        <input type="hidden" id="exportGameId" name="game_id">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '') ?>">

                        <div class="mb-3">
                            <label for="dataType" class="form-label">Data Type</label>
                            <select class="form-select" id="dataType" name="data_type" required>
                                <option value="participants">Participants</option>
                                <option value="questions">Questions</option>
                                <option value="rounds">Rounds</option>
                                <option value="analytics">Analytics</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="exportFormat" class="form-label">Format</label>
                            <select class="form-select" id="exportFormat" name="format" required>
                                <option value="csv">CSV</option>
                                <option value="json">JSON</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-download me-1"></i> Export Data
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
        // Revenue Chart
        <?php if (!empty($revenue_data)): ?>
        const revenueData = <?= json_encode($revenue_data) ?>;

        const ctx = document.getElementById('revenueChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: revenueData.map(item => {
                    const date = new Date(item.date);
                    return date.toLocaleDateString('en-GB', { month: 'short', day: 'numeric' });
                }),
                datasets: [{
                    label: 'Daily Revenue',
                    data: revenueData.map(item => item.revenue),
                    borderColor: 'rgb(75, 192, 192)',
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    tension: 0.1
                }, {
                    label: 'Participants',
                    data: revenueData.map(item => item.participants),
                    borderColor: 'rgb(255, 99, 132)',
                    backgroundColor: 'rgba(255, 99, 132, 0.2)',
                    yAxisID: 'y1',
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                scales: {
                    x: {
                        display: true,
                        title: {
                            display: true,
                            text: 'Date'
                        }
                    },
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Revenue (£)'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Participants'
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                    }
                }
            }
        });
        <?php endif; ?>

        // Form handling
        document.getElementById('gameDetailsForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Saving...';

            setTimeout(() => {
                this.submit();
            }, 500);
        });

        // Character counter
        document.getElementById('description').addEventListener('input', function() {
            document.getElementById('descriptionCount').textContent = this.value.length;
        });

        // Toggle game status
        function toggleGameStatus(gameId, newStatus) {
            if (!confirm(`Are you sure you want to ${newStatus} this game?`)) {
                return;
            }

            fetch('/adminportal/games/toggle-status', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    game_id: gameId,
                    csrf_token: '<?= htmlspecialchars($csrf_token ?? '') ?>'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', data.message);
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showAlert('error', data.error || 'Failed to update game status');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('error', 'An error occurred while updating game status');
            });
        }

        // Duplicate game
        function duplicateGame(gameId) {
            document.getElementById('duplicateGameId').value = gameId;
            const modal = new bootstrap.Modal(document.getElementById('duplicateGameModal'));
            modal.show();
        }

        // Export game data
        function exportGameData(gameId) {
            document.getElementById('exportGameId').value = gameId;
            const modal = new bootstrap.Modal(document.getElementById('exportDataModal'));
            modal.show();
        }

        // Archive game
        function archiveGame(gameId) {
            if (!confirm('Are you sure you want to archive this game? This action cannot be undone.')) {
                return;
            }

            fetch('/adminportal/games/delete', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    game_id: gameId,
                    csrf_token: '<?= htmlspecialchars($csrf_token ?? '') ?>'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', data.message);
                    setTimeout(() => window.location.href = '/adminportal/games', 1000);
                } else {
                    showAlert('error', data.error || 'Failed to archive game');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('error', 'An error occurred while archiving game');
            });
        }

        // Copy game URL
        function copyGameUrl() {
            const gameUrl = document.getElementById('gameUrl');
            gameUrl.select();
            document.execCommand('copy');
            showAlert('success', 'Game URL copied to clipboard');
        }

        // Copy referral URL
        function copyReferralUrl() {
            const referralUrl = document.getElementById('referralUrl');
            referralUrl.select();
            document.execCommand('copy');
            showAlert('success', 'Referral URL copied to clipboard');
        }

        // Test game
        function testGame() {
            const gameUrl = document.getElementById('gameUrl').value;
            window.open(gameUrl + '?test=1', '_blank');
        }

        // Handle duplicate game form
        document.getElementById('duplicateGameForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);

            fetch('/adminportal/games/duplicate', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', data.message);
                    bootstrap.Modal.getInstance(document.getElementById('duplicateGameModal')).hide();
                    setTimeout(() => {
                        window.location.href = `/adminportal/games/${data.new_game_id}`;
                    }, 1000);
                } else {
                    showAlert('error', data.error || 'Failed to duplicate game');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('error', 'An error occurred while duplicating game');
            });
        });

        // Handle export data form
        document.getElementById('exportDataForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);

            // Create a temporary form to trigger file download
            const tempForm = document.createElement('form');
            tempForm.method = 'POST';
            tempForm.action = '/adminportal/games/export';
            tempForm.style.display = 'none';

            for (let [key, value] of formData.entries()) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = value;
                tempForm.appendChild(input);
            }

            document.body.appendChild(tempForm);
            tempForm.submit();
            document.body.removeChild(tempForm);

            bootstrap.Modal.getInstance(document.getElementById('exportDataModal')).hide();
            showAlert('info', 'Export started. Download will begin shortly.');
        });

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

        // Real-time updates (optional - could be enhanced with WebSockets)
        function updateGameStats() {
            // This could periodically fetch updated statistics
            // For now, just refresh the page data every 5 minutes
            setTimeout(() => {
                // location.reload();
            }, 300000); // 5 minutes
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize tooltips
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            const tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });

            // Start real-time updates
            updateGameStats();
        });
    </script>

    <style>
        .border-left-primary { border-left: 0.25rem solid #4e73df !important; }
        .border-left-success { border-left: 0.25rem solid #1cc88a !important; }
        .border-left-info { border-left: 0.25rem solid #36b9cc !important; }
        .border-left-warning { border-left: 0.25rem solid #f6c23e !important; }

        .text-xs { font-size: 0.7rem; }
        .font-weight-bold { font-weight: 700 !important; }
        .text-gray-800 { color: #5a5c69 !important; }
        .text-gray-300 { color: #dddfeb !important; }
        .shadow { box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15) !important; }

        .progress-sm { height: 0.5rem; }

        .card-header {
            background-color: #f8f9fc;
            border-bottom: 1px solid #e3e6f0;
        }

        @media (max-width: 768px) {
            .table-responsive { font-size: 0.8rem; }
            .btn-group .btn { padding: 0.25rem 0.5rem; font-size: 0.75rem; }
            .badge { font-size: 0.7rem; }
        }
    </style>
</body>
</html>
