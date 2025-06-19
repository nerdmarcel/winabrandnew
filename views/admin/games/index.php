<?php
/**
 * File: views/admin/games/index.php
 * Location: views/admin/games/index.php
 *
 * WinABN Admin Games Overview Page
 *
 * Displays comprehensive games management interface with statistics,
 * filtering, searching, and bulk operations for game management.
 */

// Prevent direct access
if (!defined('WINABN_ADMIN')) {
    exit('Access denied');
}

$pageTitle = $title ?? 'Game Management';
$games = $games ?? [];
$gameStats = $gameStats ?? [];
$recentActivity = $recentActivity ?? [];
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
                    <h1 class="h2">
                        <i class="fas fa-gamepad me-2"></i>
                        <?= htmlspecialchars($pageTitle) ?>
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <a href="/adminportal/games/create" class="btn btn-primary">
                                <i class="fas fa-plus me-1"></i> Create New Game
                            </a>
                            <button type="button" class="btn btn-outline-secondary" onclick="refreshStats()">
                                <i class="fas fa-sync-alt me-1"></i> Refresh
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="card border-left-primary shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                            Total Games
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?= number_format($gameStats['total_games'] ?? 0) ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-gamepad fa-2x text-gray-300"></i>
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
                                            Active Games
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?= number_format($gameStats['active_games'] ?? 0) ?>
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-play fa-2x text-gray-300"></i>
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
                                            Total Revenue
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            £<?= number_format($gameStats['total_revenue'] ?? 0, 2) ?>
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
                        <div class="card border-left-warning shadow h-100 py-2">
                            <div class="card-body">
                                <div class="row no-gutters align-items-center">
                                    <div class="col mr-2">
                                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                            Avg Conversion
                                        </div>
                                        <div class="h5 mb-0 font-weight-bold text-gray-800">
                                            <?= number_format($gameStats['average_conversion_rate'] ?? 0, 1) ?>%
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <i class="fas fa-percentage fa-2x text-gray-300"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters and Search -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-filter me-1"></i> Filters & Search
                        </h6>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="/adminportal/games" class="row g-3">
                            <div class="col-md-3">
                                <label for="search" class="form-label">Search Games</label>
                                <input type="text"
                                       class="form-control"
                                       id="search"
                                       name="search"
                                       placeholder="Name, slug, or description..."
                                       value="<?= htmlspecialchars($filters['search'] ?? '') ?>">
                            </div>

                            <div class="col-md-2">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="all" <?= ($filters['status'] ?? 'all') === 'all' ? 'selected' : '' ?>>All Status</option>
                                    <option value="active" <?= ($filters['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="paused" <?= ($filters['status'] ?? '') === 'paused' ? 'selected' : '' ?>>Paused</option>
                                    <option value="archived" <?= ($filters['status'] ?? '') === 'archived' ? 'selected' : '' ?>>Archived</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label for="sort" class="form-label">Sort By</label>
                                <select class="form-select" id="sort" name="sort">
                                    <option value="created_at" <?= ($filters['sort'] ?? 'created_at') === 'created_at' ? 'selected' : '' ?>>Created Date</option>
                                    <option value="name" <?= ($filters['sort'] ?? '') === 'name' ? 'selected' : '' ?>>Name</option>
                                    <option value="prize_value" <?= ($filters['sort'] ?? '') === 'prize_value' ? 'selected' : '' ?>>Prize Value</option>
                                    <option value="entry_fee" <?= ($filters['sort'] ?? '') === 'entry_fee' ? 'selected' : '' ?>>Entry Fee</option>
                                    <option value="updated_at" <?= ($filters['sort'] ?? '') === 'updated_at' ? 'selected' : '' ?>>Last Updated</option>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <label for="order" class="form-label">Order</label>
                                <select class="form-select" id="order" name="order">
                                    <option value="DESC" <?= ($filters['order'] ?? 'DESC') === 'DESC' ? 'selected' : '' ?>>Descending</option>
                                    <option value="ASC" <?= ($filters['order'] ?? '') === 'ASC' ? 'selected' : '' ?>>Ascending</option>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">&nbsp;</label>
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-search me-1"></i> Apply Filters
                                    </button>
                                </div>
                            </div>
                        </form>

                        <?php if (!empty($filters['search']) || ($filters['status'] ?? 'all') !== 'all'): ?>
                        <div class="mt-3">
                            <a href="/adminportal/games" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-times me-1"></i> Clear Filters
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Games Table -->
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-list me-1"></i> Games List
                        </h6>
                        <span class="badge bg-info">
                            <?= number_format($pagination['total_items'] ?? 0) ?> total games
                        </span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($games)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-gamepad fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">No Games Found</h5>
                                <p class="text-muted">Create your first game to get started.</p>
                                <a href="/adminportal/games/create" class="btn btn-primary">
                                    <i class="fas fa-plus me-1"></i> Create New Game
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped" id="gamesTable">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Game</th>
                                            <th>Status</th>
                                            <th>Prize Value</th>
                                            <th>Entry Fee</th>
                                            <th>Participants</th>
                                            <th>Revenue</th>
                                            <th>Conversion</th>
                                            <th>Questions</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($games as $game): ?>
                                        <tr data-game-id="<?= (int) $game['id'] ?>">
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div>
                                                        <div class="font-weight-bold text-primary">
                                                            <a href="/adminportal/games/<?= (int) $game['id'] ?>" class="text-decoration-none">
                                                                <?= htmlspecialchars($game['name']) ?>
                                                            </a>
                                                        </div>
                                                        <small class="text-muted">
                                                            <?= htmlspecialchars($game['slug']) ?>
                                                        </small>
                                                        <div class="small text-muted mt-1">
                                                            <?= htmlspecialchars(substr($game['description'] ?? '', 0, 80)) ?>
                                                            <?= strlen($game['description'] ?? '') > 80 ? '...' : '' ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php
                                                $statusClasses = [
                                                    'active' => 'success',
                                                    'paused' => 'warning',
                                                    'archived' => 'secondary'
                                                ];
                                                $statusClass = $statusClasses[$game['status']] ?? 'secondary';
                                                ?>
                                                <span class="badge bg-<?= $statusClass ?>">
                                                    <?= ucfirst($game['status']) ?>
                                                </span>
                                                <?php if (($game['active_rounds'] ?? 0) > 0): ?>
                                                    <br><small class="text-success">
                                                        <i class="fas fa-circle-notch fa-spin me-1"></i>
                                                        <?= (int) $game['active_rounds'] ?> active round<?= $game['active_rounds'] > 1 ? 's' : '' ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($game['currency']) ?> <?= number_format($game['prize_value'], 2) ?></strong>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($game['currency']) ?> <?= number_format($game['entry_fee'], 2) ?>
                                            </td>
                                            <td>
                                                <div class="small">
                                                    <div><strong><?= number_format($game['total_participants'] ?? 0) ?></strong> total</div>
                                                    <div class="text-success"><?= number_format($game['paid_participants'] ?? 0) ?> paid</div>
                                                </div>
                                            </td>
                                            <td>
                                                <strong>£<?= number_format($game['total_revenue'] ?? 0, 2) ?></strong>
                                            </td>
                                            <td>
                                                <?php
                                                $conversionRate = $game['conversion_rate'] ?? 0;
                                                $conversionClass = $conversionRate >= 70 ? 'success' : ($conversionRate >= 50 ? 'warning' : 'danger');
                                                ?>
                                                <span class="badge bg-<?= $conversionClass ?>">
                                                    <?= number_format($conversionRate, 1) ?>%
                                                </span>
                                            </td>
                                            <td>
                                                <div class="small">
                                                    <div><strong><?= number_format($game['question_count'] ?? 0) ?></strong> questions</div>
                                                    <?php if (($game['question_count'] ?? 0) < 27): ?>
                                                        <div class="text-warning">
                                                            <i class="fas fa-exclamation-triangle me-1"></i>
                                                            Need <?= 27 - ($game['question_count'] ?? 0) ?> more
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="text-success">
                                                            <i class="fas fa-check me-1"></i>
                                                            Ready
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <a href="/adminportal/games/<?= (int) $game['id'] ?>"
                                                       class="btn btn-sm btn-outline-primary"
                                                       title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="/adminportal/games/<?= (int) $game['id'] ?>/questions"
                                                       class="btn btn-sm btn-outline-info"
                                                       title="Manage Questions">
                                                        <i class="fas fa-question-circle"></i>
                                                    </a>
                                                    <a href="/adminportal/games/<?= (int) $game['id'] ?>/analytics"
                                                       class="btn btn-sm btn-outline-success"
                                                       title="View Analytics">
                                                        <i class="fas fa-chart-line"></i>
                                                    </a>
                                                    <div class="btn-group" role="group">
                                                        <button type="button"
                                                                class="btn btn-sm btn-outline-secondary dropdown-toggle"
                                                                data-bs-toggle="dropdown">
                                                            <i class="fas fa-ellipsis-v"></i>
                                                        </button>
                                                        <ul class="dropdown-menu">
                                                            <?php if ($game['status'] === 'active'): ?>
                                                                <li>
                                                                    <a class="dropdown-item" href="#" onclick="toggleGameStatus(<?= (int) $game['id'] ?>, 'paused')">
                                                                        <i class="fas fa-pause me-1"></i> Pause Game
                                                                    </a>
                                                                </li>
                                                            <?php elseif ($game['status'] === 'paused'): ?>
                                                                <li>
                                                                    <a class="dropdown-item" href="#" onclick="toggleGameStatus(<?= (int) $game['id'] ?>, 'active')">
                                                                        <i class="fas fa-play me-1"></i> Activate Game
                                                                    </a>
                                                                </li>
                                                            <?php endif; ?>
                                                            <li>
                                                                <a class="dropdown-item" href="#" onclick="duplicateGame(<?= (int) $game['id'] ?>)">
                                                                    <i class="fas fa-copy me-1"></i> Duplicate Game
                                                                </a>
                                                            </li>
                                                            <li>
                                                                <a class="dropdown-item" href="#" onclick="exportGameData(<?= (int) $game['id'] ?>)">
                                                                    <i class="fas fa-download me-1"></i> Export Data
                                                                </a>
                                                            </li>
                                                            <li><hr class="dropdown-divider"></li>
                                                            <?php if (($game['active_rounds'] ?? 0) === 0): ?>
                                                                <li>
                                                                    <a class="dropdown-item text-danger" href="#" onclick="archiveGame(<?= (int) $game['id'] ?>)">
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
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <?php if (($pagination['total_pages'] ?? 1) > 1): ?>
                            <nav aria-label="Games pagination" class="mt-4">
                                <ul class="pagination justify-content-center">
                                    <?php
                                    $currentPage = $pagination['current_page'] ?? 1;
                                    $totalPages = $pagination['total_pages'] ?? 1;
                                    $baseUrl = '/adminportal/games?' . http_build_query(array_filter($filters));
                                    ?>

                                    <!-- Previous Page -->
                                    <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                                        <a class="page-link" href="<?= $baseUrl ?>&page=<?= $currentPage - 1 ?>">
                                            <i class="fas fa-chevron-left"></i> Previous
                                        </a>
                                    </li>

                                    <!-- Page Numbers -->
                                    <?php
                                    $startPage = max(1, $currentPage - 2);
                                    $endPage = min($totalPages, $currentPage + 2);

                                    if ($startPage > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="<?= $baseUrl ?>&page=1">1</a>
                                        </li>
                                        <?php if ($startPage > 2): ?>
                                            <li class="page-item disabled">
                                                <span class="page-link">...</span>
                                            </li>
                                        <?php endif;
                                    endif;

                                    for ($i = $startPage; $i <= $endPage; $i++): ?>
                                        <li class="page-item <?= $i === $currentPage ? 'active' : '' ?>">
                                            <a class="page-link" href="<?= $baseUrl ?>&page=<?= $i ?>"><?= $i ?></a>
                                        </li>
                                    <?php endfor;

                                    if ($endPage < $totalPages): ?>
                                        <?php if ($endPage < $totalPages - 1): ?>
                                            <li class="page-item disabled">
                                                <span class="page-link">...</span>
                                            </li>
                                        <?php endif; ?>
                                        <li class="page-item">
                                            <a class="page-link" href="<?= $baseUrl ?>&page=<?= $totalPages ?>"><?= $totalPages ?></a>
                                        </li>
                                    <?php endif; ?>

                                    <!-- Next Page -->
                                    <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                                        <a class="page-link" href="<?= $baseUrl ?>&page=<?= $currentPage + 1 ?>">
                                            Next <i class="fas fa-chevron-right"></i>
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Activity -->
                <?php if (!empty($recentActivity)): ?>
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-clock me-1"></i> Recent Activity
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="list-group list-group-flush">
                            <?php foreach ($recentActivity as $activity): ?>
                            <div class="list-group-item">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1">
                                        <i class="fas fa-<?= htmlspecialchars($activity['icon'] ?? 'info') ?> me-2"></i>
                                        <?= htmlspecialchars($activity['title']) ?>
                                    </h6>
                                    <small class="text-muted">
                                        <?= date('M j, Y g:i A', strtotime($activity['created_at'])) ?>
                                    </small>
                                </div>
                                <p class="mb-1"><?= htmlspecialchars($activity['description']) ?></p>
                                <?php if (!empty($activity['game_name'])): ?>
                                    <small class="text-muted">
                                        Game: <?= htmlspecialchars($activity['game_name']) ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
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
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showAlert('error', data.error || 'Failed to archive game');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('error', 'An error occurred while archiving game');
            });
        }

        // Refresh statistics
        function refreshStats() {
            location.reload();
        }

        // Handle duplicate game form submission
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
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showAlert('error', data.error || 'Failed to duplicate game');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('error', 'An error occurred while duplicating game');
            });
        });

        // Handle export data form submission
        document.getElementById('exportDataForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);

            // Create a temporary form to trigger file download
            const tempForm = document.createElement('form');
            tempForm.method = 'POST';
            tempForm.action = '/adminportal/games/export';
            tempForm.style.display = 'none';

            // Add form data to temp form
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

        // Create alert container if it doesn't exist
        function createAlertContainer() {
            const container = document.createElement('div');
            container.id = 'alertContainer';
            container.className = 'position-fixed top-0 end-0 p-3';
            container.style.zIndex = '1050';
            document.body.appendChild(container);
            return container;
        }

        // Initialize tooltips
        document.addEventListener('DOMContentLoaded', function() {
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            const tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });

        // Auto-submit search form on Enter key
        document.getElementById('search').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                this.form.submit();
            }
        });

        // Quick filters
        function applyQuickFilter(status) {
            const url = new URL(window.location);
            url.searchParams.set('status', status);
            url.searchParams.delete('page'); // Reset to first page
            window.location.href = url.toString();
        }

        // Table sorting enhancement
        document.querySelectorAll('#gamesTable th[data-sort]').forEach(header => {
            header.style.cursor = 'pointer';
            header.addEventListener('click', function() {
                const sortField = this.dataset.sort;
                const url = new URL(window.location);
                const currentSort = url.searchParams.get('sort');
                const currentOrder = url.searchParams.get('order') || 'DESC';

                if (currentSort === sortField) {
                    // Toggle order
                    url.searchParams.set('order', currentOrder === 'DESC' ? 'ASC' : 'DESC');
                } else {
                    // New sort field
                    url.searchParams.set('sort', sortField);
                    url.searchParams.set('order', 'DESC');
                }

                url.searchParams.delete('page'); // Reset to first page
                window.location.href = url.toString();
            });
        });

        // Bulk actions (if needed in future)
        let selectedGames = [];

        function toggleGameSelection(gameId) {
            const index = selectedGames.indexOf(gameId);
            if (index > -1) {
                selectedGames.splice(index, 1);
            } else {
                selectedGames.push(gameId);
            }
            updateBulkActionButtons();
        }

        function selectAllGames() {
            const checkboxes = document.querySelectorAll('input[name="selected_games[]"]');
            checkboxes.forEach(checkbox => {
                checkbox.checked = true;
                const gameId = parseInt(checkbox.value);
                if (!selectedGames.includes(gameId)) {
                    selectedGames.push(gameId);
                }
            });
            updateBulkActionButtons();
        }

        function deselectAllGames() {
            const checkboxes = document.querySelectorAll('input[name="selected_games[]"]');
            checkboxes.forEach(checkbox => checkbox.checked = false);
            selectedGames = [];
            updateBulkActionButtons();
        }

        function updateBulkActionButtons() {
            const bulkActions = document.getElementById('bulkActions');
            if (bulkActions) {
                bulkActions.style.display = selectedGames.length > 0 ? 'block' : 'none';
                document.getElementById('selectedCount').textContent = selectedGames.length;
            }
        }

        // Performance monitoring
        if ('performance' in window) {
            window.addEventListener('load', function() {
                setTimeout(function() {
                    const loadTime = performance.timing.loadEventEnd - performance.timing.navigationStart;
                    if (loadTime > 3000) {
                        console.warn('Page load time exceeded 3 seconds:', loadTime + 'ms');
                    }
                }, 0);
            });
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + N = New Game
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                window.location.href = '/adminportal/games/create';
            }

            // Ctrl/Cmd + R = Refresh
            if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
                e.preventDefault();
                refreshStats();
            }

            // Escape = Clear search
            if (e.key === 'Escape') {
                const searchInput = document.getElementById('search');
                if (searchInput && searchInput.value) {
                    searchInput.value = '';
                    searchInput.form.submit();
                }
            }
        });

        // Live search with debouncing
        let searchTimeout;
        document.getElementById('search').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const searchValue = this.value;

            searchTimeout = setTimeout(() => {
                if (searchValue.length >= 3 || searchValue.length === 0) {
                    // Auto-submit form after 500ms of no typing
                    this.form.submit();
                }
            }, 500);
        });

        // Status indicator updates
        function updateStatusIndicators() {
            // This could be enhanced to periodically check for status updates
            // via AJAX calls to keep the interface current
        }

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Set focus on search input if no filters are applied
            const searchInput = document.getElementById('search');
            if (searchInput && !searchInput.value) {
                searchInput.focus();
            }

            // Add loading states to buttons
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', function() {
                    const submitBtn = form.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        const originalText = submitBtn.innerHTML;
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Processing...';

                        setTimeout(() => {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalText;
                        }, 5000);
                    }
                });
            });
        });
    </script>

    <style>
        .border-left-primary {
            border-left: 0.25rem solid #4e73df !important;
        }
        .border-left-success {
            border-left: 0.25rem solid #1cc88a !important;
        }
        .border-left-info {
            border-left: 0.25rem solid #36b9cc !important;
        }
        .border-left-warning {
            border-left: 0.25rem solid #f6c23e !important;
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

        .badge {
            font-size: 0.75rem;
        }

        .btn-group .btn {
            border-color: rgba(0,0,0,.125);
        }

        .card-header {
            background-color: #f8f9fc;
            border-bottom: 1px solid #e3e6f0;
        }

        .list-group-item {
            border-left: none;
            border-right: none;
            border-top: 1px solid #e3e6f0;
        }

        .list-group-item:first-child {
            border-top: none;
        }

        .text-xs {
            font-size: 0.7rem;
        }

        .font-weight-bold {
            font-weight: 700 !important;
        }

        .text-gray-800 {
            color: #5a5c69 !important;
        }

        .text-gray-300 {
            color: #dddfeb !important;
        }

        .shadow {
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15) !important;
        }

        .cursor-pointer {
            cursor: pointer;
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
    </style>
</body>
</html>
