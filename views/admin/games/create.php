<?php
/**
 * File: views/admin/games/create.php
 * Location: views/admin/games/create.php
 *
 * WinABN Admin Create Game Page
 *
 * Form for creating new games with comprehensive validation,
 * multi-currency pricing, and real-time preview functionality.
 */

// Prevent direct access
if (!defined('WINABN_ADMIN')) {
    exit('Access denied');
}

$pageTitle = $title ?? 'Create New Game';
$exchangeRates = $exchangeRates ?? [];
$questionCategories = $questionCategories ?? [];
$supportedCurrencies = $supportedCurrencies ?? ['GBP', 'USD', 'EUR', 'CAD', 'AUD'];
$defaultSettings = $defaultSettings ?? [];
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
                        <i class="fas fa-plus-circle me-2"></i>
                        <?= htmlspecialchars($pageTitle) ?>
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <a href="/adminportal/games" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-1"></i> Back to Games
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Progress Steps -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <div class="step-progress">
                                    <div class="step active" data-step="1">
                                        <div class="step-circle">1</div>
                                        <div class="step-label">Basic Info</div>
                                    </div>
                                    <div class="step" data-step="2">
                                        <div class="step-circle">2</div>
                                        <div class="step-label">Pricing</div>
                                    </div>
                                    <div class="step" data-step="3">
                                        <div class="step-circle">3</div>
                                        <div class="step-label">Settings</div>
                                    </div>
                                    <div class="step" data-step="4">
                                        <div class="step-circle">4</div>
                                        <div class="step-label">Review</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Create Game Form -->
                <form id="createGameForm" method="POST" action="/adminportal/games/create" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token ?? '') ?>">

                    <div class="row">
                        <!-- Main Form -->
                        <div class="col-lg-8">
                            <!-- Step 1: Basic Information -->
                            <div class="card shadow mb-4 form-step" data-step="1">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">
                                        <i class="fas fa-info-circle me-1"></i> Basic Information
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-12 mb-3">
                                            <label for="name" class="form-label">
                                                Game Name <span class="text-danger">*</span>
                                            </label>
                                            <input type="text"
                                                   class="form-control"
                                                   id="name"
                                                   name="name"
                                                   placeholder="e.g., Win a Brand New iPhone 15 Pro"
                                                   maxlength="255"
                                                   required>
                                            <div class="invalid-feedback"></div>
                                            <div class="form-text">
                                                This will be displayed as the main game title. Keep it engaging and clear.
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="slug" class="form-label">
                                                URL Slug <span class="text-danger">*</span>
                                            </label>
                                            <div class="input-group">
                                                <span class="input-group-text">/</span>
                                                <input type="text"
                                                       class="form-control"
                                                       id="slug"
                                                       name="slug"
                                                       placeholder="win-a-iphone-15-pro"
                                                       pattern="win-a-[a-z0-9-]+"
                                                       maxlength="100"
                                                       required>
                                            </div>
                                            <div class="invalid-feedback"></div>
                                            <div class="form-text">
                                                URL-friendly version. Must start with "win-a-". Will be auto-generated from name if left empty.
                                            </div>
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label for="status" class="form-label">Initial Status</label>
                                            <select class="form-select" id="status" name="status">
                                                <option value="active">Active - Ready to accept participants</option>
                                                <option value="paused" selected>Paused - Hidden from public</option>
                                            </select>
                                            <div class="form-text">
                                                Paused is recommended while setting up questions.
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-12 mb-3">
                                            <label for="description" class="form-label">
                                                Description <span class="text-danger">*</span>
                                            </label>
                                            <textarea class="form-control"
                                                      id="description"
                                                      name="description"
                                                      rows="4"
                                                      placeholder="Describe the prize and what participants need to do to win..."
                                                      maxlength="1000"
                                                      required></textarea>
                                            <div class="invalid-feedback"></div>
                                            <div class="form-text">
                                                <span id="descriptionCount">0</span>/1000 characters.
                                                This will be shown on the game landing page.
                                            </div>
                                        </div>
                                    </div>

                                    <div class="d-flex justify-content-end">
                                        <button type="button" class="btn btn-primary" onclick="nextStep()">
                                            Next: Pricing <i class="fas fa-arrow-right ms-1"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Step 2: Pricing & Currency -->
                            <div class="card shadow mb-4 form-step" data-step="2" style="display: none;">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">
                                        <i class="fas fa-pound-sign me-1"></i> Pricing & Currency
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="prize_value" class="form-label">
                                                Prize Value <span class="text-danger">*</span>
                                            </label>
                                            <div class="input-group">
                                                <span class="input-group-text" id="prizeValueCurrency">£</span>
                                                <input type="number"
                                                       class="form-control"
                                                       id="prize_value"
                                                       name="prize_value"
                                                       placeholder="1200.00"
                                                       min="0.01"
                                                       max="100000"
                                                       step="0.01"
                                                       required>
                                            </div>
                                            <div class="invalid-feedback"></div>
                                            <div class="form-text">
                                                The actual retail value of the prize in your base currency.
                                            </div>
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label for="currency" class="form-label">
                                                Base Currency <span class="text-danger">*</span>
                                            </label>
                                            <select class="form-select" id="currency" name="currency" required>
                                                <?php foreach ($supportedCurrencies as $currencyCode): ?>
                                                    <?php
                                                    $currencyNames = [
                                                        'GBP' => 'British Pound (£)',
                                                        'USD' => 'US Dollar ($)',
                                                        'EUR' => 'Euro (€)',
                                                        'CAD' => 'Canadian Dollar (C$)',
                                                        'AUD' => 'Australian Dollar (A$)'
                                                    ];
                                                    $selected = $currencyCode === 'GBP' ? 'selected' : '';
                                                    ?>
                                                    <option value="<?= $currencyCode ?>" <?= $selected ?>>
                                                        <?= $currencyNames[$currencyCode] ?? $currencyCode ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="form-text">
                                                Currency for prize value and entry fee. Other currencies calculated automatically.
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="entry_fee" class="form-label">
                                                Entry Fee <span class="text-danger">*</span>
                                            </label>
                                            <div class="input-group">
                                                <span class="input-group-text" id="entryFeeCurrency">£</span>
                                                <input type="number"
                                                       class="form-control"
                                                       id="entry_fee"
                                                       name="entry_fee"
                                                       placeholder="10.00"
                                                       min="0.50"
                                                       max="1000"
                                                       step="0.01"
                                                       required>
                                            </div>
                                            <div class="invalid-feedback"></div>
                                            <div class="form-text">
                                                Amount participants pay to enter the game.
                                            </div>
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label for="max_players" class="form-label">
                                                Maximum Players <span class="text-danger">*</span>
                                            </label>
                                            <input type="number"
                                                   class="form-control"
                                                   id="max_players"
                                                   name="max_players"
                                                   placeholder="1000"
                                                   min="10"
                                                   max="10000"
                                                   value="<?= $defaultSettings['max_players'] ?? 1000 ?>"
                                                   required>
                                            <div class="invalid-feedback"></div>
                                            <div class="form-text">
                                                Maximum number of paid participants per round.
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Multi-Currency Preview -->
                                    <div class="row">
                                        <div class="col-12">
                                            <div class="card bg-light">
                                                <div class="card-header">
                                                    <h6 class="mb-0">
                                                        <i class="fas fa-calculator me-1"></i>
                                                        Multi-Currency Pricing Preview
                                                    </h6>
                                                </div>
                                                <div class="card-body">
                                                    <div class="row" id="currencyPreview">
                                                        <div class="col-12 text-muted">
                                                            Enter entry fee to see pricing in all supported currencies
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="d-flex justify-content-between mt-3">
                                        <button type="button" class="btn btn-outline-secondary" onclick="prevStep()">
                                            <i class="fas fa-arrow-left me-1"></i> Previous
                                        </button>
                                        <button type="button" class="btn btn-primary" onclick="nextStep()">
                                            Next: Settings <i class="fas fa-arrow-right ms-1"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Step 3: Game Settings -->
                            <div class="card shadow mb-4 form-step" data-step="3" style="display: none;">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">
                                        <i class="fas fa-cogs me-1"></i> Game Settings
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label for="total_questions" class="form-label">
                                                Total Questions <span class="text-danger">*</span>
                                            </label>
                                            <input type="number"
                                                   class="form-control"
                                                   id="total_questions"
                                                   name="total_questions"
                                                   min="6"
                                                   max="20"
                                                   value="<?= $defaultSettings['total_questions'] ?? 9 ?>"
                                                   required>
                                            <div class="invalid-feedback"></div>
                                            <div class="form-text">
                                                Total questions per game (6-20).
                                            </div>
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label for="free_questions" class="form-label">
                                                Free Questions <span class="text-danger">*</span>
                                            </label>
                                            <input type="number"
                                                   class="form-control"
                                                   id="free_questions"
                                                   name="free_questions"
                                                   min="1"
                                                   max="10"
                                                   value="<?= $defaultSettings['free_questions'] ?? 3 ?>"
                                                   required>
                                            <div class="invalid-feedback"></div>
                                            <div class="form-text">
                                                Questions shown before payment.
                                            </div>
                                        </div>

                                        <div class="col-md-4 mb-3">
                                            <label for="question_timeout" class="form-label">
                                                Question Timeout <span class="text-danger">*</span>
                                            </label>
                                            <div class="input-group">
                                                <input type="number"
                                                       class="form-control"
                                                       id="question_timeout"
                                                       name="question_timeout"
                                                       min="5"
                                                       max="60"
                                                       value="<?= $defaultSettings['question_timeout'] ?? 10 ?>"
                                                       required>
                                                <span class="input-group-text">seconds</span>
                                            </div>
                                            <div class="invalid-feedback"></div>
                                            <div class="form-text">
                                                Time limit per question (5-60 seconds).
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-12 mb-3">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input"
                                                       type="checkbox"
                                                       id="auto_restart"
                                                       name="auto_restart"
                                                       <?= ($defaultSettings['auto_restart'] ?? true) ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="auto_restart">
                                                    <strong>Auto-restart Rounds</strong>
                                                </label>
                                                <div class="form-text">
                                                    Automatically start new rounds when the current round fills up.
                                                    Recommended for continuous gameplay.
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Game Flow Preview -->
                                    <div class="row">
                                        <div class="col-12">
                                            <div class="card bg-light">
                                                <div class="card-header">
                                                    <h6 class="mb-0">
                                                        <i class="fas fa-route me-1"></i>
                                                        Game Flow Preview
                                                    </h6>
                                                </div>
                                                <div class="card-body">
                                                    <div class="game-flow-preview">
                                                        <div class="flow-step">
                                                            <div class="flow-number">1-<span id="flowFreeQuestions">3</span></div>
                                                            <div class="flow-content">
                                                                <strong>Free Questions</strong>
                                                                <br><small>No payment required</small>
                                                            </div>
                                                        </div>
                                                        <div class="flow-arrow">→</div>
                                                        <div class="flow-step">
                                                            <div class="flow-number">💳</div>
                                                            <div class="flow-content">
                                                                <strong>Payment</strong>
                                                                <br><small><span id="flowEntryFee">£10.00</span></small>
                                                            </div>
                                                        </div>
                                                        <div class="flow-arrow">→</div>
                                                        <div class="flow-step">
                                                            <div class="flow-number"><span id="flowPaidStart">4</span>-<span id="flowTotalQuestions">9</span></div>
                                                            <div class="flow-content">
                                                                <strong>Paid Questions</strong>
                                                                <br><small><span id="flowTimeout">10</span>s per question</small>
                                                            </div>
                                                        </div>
                                                        <div class="flow-arrow">→</div>
                                                        <div class="flow-step">
                                                            <div class="flow-number">🏆</div>
                                                            <div class="flow-content">
                                                                <strong>Winner</strong>
                                                                <br><small>Fastest completion</small>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="d-flex justify-content-between mt-3">
                                        <button type="button" class="btn btn-outline-secondary" onclick="prevStep()">
                                            <i class="fas fa-arrow-left me-1"></i> Previous
                                        </button>
                                        <button type="button" class="btn btn-primary" onclick="nextStep()">
                                            Next: Review <i class="fas fa-arrow-right ms-1"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Step 4: Review & Submit -->
                            <div class="card shadow mb-4 form-step" data-step="4" style="display: none;">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">
                                        <i class="fas fa-check-circle me-1"></i> Review & Submit
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-12">
                                            <div class="alert alert-info">
                                                <h6><i class="fas fa-info-circle me-2"></i>Before You Continue</h6>
                                                <p class="mb-2">Please review all settings carefully. You can modify most settings later, but some changes may affect active rounds.</p>
                                                <p class="mb-0"><strong>Next Step:</strong> After creating the game, you'll need to add at least 27 questions before participants can play.</p>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Game Summary -->
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h6>Basic Information</h6>
                                            <table class="table table-sm">
                                                <tr>
                                                    <td><strong>Name:</strong></td>
                                                    <td id="reviewName">-</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Slug:</strong></td>
                                                    <td id="reviewSlug">-</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Status:</strong></td>
                                                    <td id="reviewStatus">-</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Description:</strong></td>
                                                    <td id="reviewDescription">-</td>
                                                </tr>
                                            </table>
                                        </div>
                                        <div class="col-md-6">
                                            <h6>Pricing & Settings</h6>
                                            <table class="table table-sm">
                                                <tr>
                                                    <td><strong>Prize Value:</strong></td>
                                                    <td id="reviewPrizeValue">-</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Entry Fee:</strong></td>
                                                    <td id="reviewEntryFee">-</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Max Players:</strong></td>
                                                    <td id="reviewMaxPlayers">-</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Questions:</strong></td>
                                                    <td id="reviewQuestions">-</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Auto-restart:</strong></td>
                                                    <td id="reviewAutoRestart">-</td>
                                                </tr>
                                            </table>
                                        </div>
                                    </div>

                                    <div class="d-flex justify-content-between mt-3">
                                        <button type="button" class="btn btn-outline-secondary" onclick="prevStep()">
                                            <i class="fas fa-arrow-left me-1"></i> Previous
                                        </button>
                                        <button type="submit" class="btn btn-success btn-lg">
                                            <i class="fas fa-plus-circle me-1"></i> Create Game
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Sidebar -->
                        <div class="col-lg-4">
                            <!-- Quick Tips -->
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">
                                        <i class="fas fa-lightbulb me-1"></i> Quick Tips
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="tip-step" data-for-step="1">
                                        <h6><i class="fas fa-info-circle me-1 text-info"></i> Basic Information</h6>
                                        <ul class="small mb-0">
                                            <li>Make the game name exciting and specific</li>
                                            <li>Include the prize in the name for clarity</li>
                                            <li>Keep descriptions engaging but concise</li>
                                            <li>Start with "Paused" status while setting up</li>
                                        </ul>
                                    </div>
                                    <div class="tip-step" data-for-step="2" style="display: none;">
                                        <h6><i class="fas fa-pound-sign me-1 text-success"></i> Pricing Strategy</h6>
                                        <ul class="small mb-0">
                                            <li>Entry fee should be 0.5-2% of prize value</li>
                                            <li>Higher fees = fewer participants but more revenue per player</li>
                                            <li>Consider your target audience's spending power</li>
                                            <li>Multi-currency pricing is automatic</li>
                                        </ul>
                                    </div>
                                    <div class="tip-step" data-for-step="3" style="display: none;">
                                        <h6><i class="fas fa-cogs me-1 text-warning"></i> Game Settings</h6>
                                        <ul class="small mb-0">
                                            <li>9 questions is optimal for engagement</li>
                                            <li>3 free questions allow users to test before paying</li>
                                            <li>10-second timeout balances difficulty and accessibility</li>
                                            <li>Auto-restart keeps games running continuously</li>
                                        </ul>
                                    </div>
                                    <div class="tip-step" data-for-step="4" style="display: none;">
                                        <h6><i class="fas fa-check-circle me-1 text-success"></i> Final Steps</h6>
                                        <ul class="small mb-0">
                                            <li>You'll need to add at least 27 questions</li>
                                            <li>Mix difficulty levels for better engagement</li>
                                            <li>Test the game before making it active</li>
                                            <li>Monitor analytics after launch</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <!-- Requirements Checklist -->
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">
                                        <i class="fas fa-tasks me-1"></i> Requirements Checklist
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="requirements-list">
                                        <div class="requirement-item" data-requirement="name">
                                            <i class="fas fa-circle text-muted me-2"></i>
                                            Game name entered
                                        </div>
                                        <div class="requirement-item" data-requirement="description">
                                            <i class="fas fa-circle text-muted me-2"></i>
                                            Description provided
                                        </div>
                                        <div class="requirement-item" data-requirement="pricing">
                                            <i class="fas fa-circle text-muted me-2"></i>
                                            Pricing configured
                                        </div>
                                        <div class="requirement-item" data-requirement="settings">
                                            <i class="fas fa-circle text-muted me-2"></i>
                                            Game settings defined
                                        </div>
                                    </div>
                                    <div class="mt-3">
                                        <div class="progress">
                                            <div class="progress-bar" id="completionProgress" role="progressbar" style="width: 0%"></div>
                                        </div>
                                        <small class="text-muted">Form completion: <span id="completionPercentage">0%</span></small>
                                    </div>
                                </div>
                            </div>

                            <!-- Exchange Rates Info -->
                            <?php if (!empty($exchangeRates)): ?>
                            <div class="card shadow mb-4">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">
                                        <i class="fas fa-exchange-alt me-1"></i> Current Exchange Rates
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <small class="text-muted d-block mb-2">Last updated: <?= date('M j, Y') ?></small>
                                    <div class="row">
                                        <?php foreach ($exchangeRates as $from => $rates): ?>
                                            <?php if ($from === 'GBP'): ?>
                                                <?php foreach ($rates as $to => $rate): ?>
                                                    <?php if ($to !== 'GBP'): ?>
                                                        <div class="col-6 mb-1">
                                                            <small>£1 = <?= $to ?> <?= number_format($rate, 2) ?></small>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </main>
        </div>
    </div>

    <!-- Scripts -->
    <script src="/assets/js/bootstrap.bundle.min.js"></script>
    <script src="/assets/js/admin.js"></script>

    <script>
        // Form state management
        let currentStep = 1;
        const totalSteps = 4;
        const exchangeRates = <?= json_encode($exchangeRates) ?>;

        // Step navigation
        function nextStep() {
            if (validateCurrentStep()) {
                if (currentStep < totalSteps) {
                    currentStep++;
                    showStep(currentStep);
                    updateProgress();
                    updateRequirements();
                }
            }
        }

        function prevStep() {
            if (currentStep > 1) {
                currentStep--;
                showStep(currentStep);
                updateProgress();
            }
        }

        function showStep(step) {
            // Hide all steps
            document.querySelectorAll('.form-step').forEach(el => {
                el.style.display = 'none';
            });

            // Show current step
            const currentStepEl = document.querySelector(`.form-step[data-step="${step}"]`);
            if (currentStepEl) {
                currentStepEl.style.display = 'block';
            }

            // Update step indicators
            document.querySelectorAll('.step').forEach((el, index) => {
                el.classList.remove('active', 'completed');
                if (index + 1 < step) {
                    el.classList.add('completed');
                } else if (index + 1 === step) {
                    el.classList.add('active');
                }
            });

            // Update tips
            document.querySelectorAll('.tip-step').forEach(el => {
                el.style.display = 'none';
            });
            const currentTip = document.querySelector(`[data-for-step="${step}"]`);
            if (currentTip) {
                currentTip.style.display = 'block';
            }

            // Update review section
            if (step === 4) {
                updateReviewSection();
            }
        }

        function updateProgress() {
            const progress = (currentStep - 1) / (totalSteps - 1) * 100;
            document.querySelectorAll('.step-progress .step').forEach((el, index) => {
                if (index < currentStep) {
                    el.classList.add('completed');
                } else {
                    el.classList.remove('completed');
                }
            });
        }

        // Validation
        function validateCurrentStep() {
            const currentStepEl = document.querySelector(`.form-step[data-step="${currentStep}"]`);
            const inputs = currentStepEl.querySelectorAll('input[required], textarea[required], select[required]');
            let isValid = true;

            inputs.forEach(input => {
                if (!validateField(input)) {
                    isValid = false;
                }
            });

            // Additional step-specific validation
            if (currentStep === 3) {
                const totalQuestions = parseInt(document.getElementById('total_questions').value);
                const freeQuestions = parseInt(document.getElementById('free_questions').value);

                if (freeQuestions >= totalQuestions) {
                    showFieldError(document.getElementById('free_questions'), 'Free questions must be less than total questions');
                    isValid = false;
                }
            }

            return isValid;
        }

        function validateField(field) {
            const value = field.value.trim();
            let isValid = true;
            let errorMessage = '';

            // Required field check
            if (field.hasAttribute('required') && !value) {
                errorMessage = 'This field is required';
                isValid = false;
            }

            // Field-specific validation
            if (isValid && value) {
                switch (field.id) {
                    case 'name':
                        if (value.length < 3) {
                            errorMessage = 'Game name must be at least 3 characters';
                            isValid = false;
                        }
                        break;

                    case 'slug':
                        if (value && !value.match(/^win-a-[a-z0-9-]+$/)) {
                            errorMessage = 'Slug must start with "win-a-" and contain only lowercase letters, numbers, and hyphens';
                            isValid = false;
                        }
                        break;

                    case 'description':
                        if (value.length < 10) {
                            errorMessage = 'Description must be at least 10 characters';
                            isValid = false;
                        }
                        break;

                    case 'prize_value':
                        const prizeValue = parseFloat(value);
                        if (prizeValue < 0.01 || prizeValue > 100000) {
                            errorMessage = 'Prize value must be between £0.01 and £100,000';
                            isValid = false;
                        }
                        break;

                    case 'entry_fee':
                        const entryFee = parseFloat(value);
                        if (entryFee < 0.50 || entryFee > 1000) {
                            errorMessage = 'Entry fee must be between £0.50 and £1,000';
                            isValid = false;
                        }
                        break;

                    case 'max_players':
                        const maxPlayers = parseInt(value);
                        if (maxPlayers < 10 || maxPlayers > 10000) {
                            errorMessage = 'Maximum players must be between 10 and 10,000';
                            isValid = false;
                        }
                        break;
                }
            }

            if (isValid) {
                clearFieldError(field);
            } else {
                showFieldError(field, errorMessage);
            }

            return isValid;
        }

        function showFieldError(field, message) {
            field.classList.add('is-invalid');
            const feedback = field.parentNode.querySelector('.invalid-feedback');
            if (feedback) {
                feedback.textContent = message;
            }
        }

        function clearFieldError(field) {
            field.classList.remove('is-invalid');
            field.classList.add('is-valid');
        }

        // Auto-generate slug from name
        document.getElementById('name').addEventListener('input', function() {
            const name = this.value;
            const slugField = document.getElementById('slug');

            if (name && !slugField.value) {
                const slug = 'win-a-' + name.toLowerCase()
                    .replace(/[^a-z0-9\s-]/g, '')
                    .replace(/\s+/g, '-')
                    .replace(/-+/g, '-')
                    .trim();
                slugField.value = slug;
            }
        });

        // Currency symbol updates
        document.getElementById('currency').addEventListener('change', function() {
            const currency = this.value;
            const symbols = {
                'GBP': '£',
                'USD': ',
                'EUR': '€',
                'CAD': 'C,
                'AUD': 'A
            };

            document.getElementById('prizeValueCurrency').textContent = symbols[currency] || currency;
            document.getElementById('entryFeeCurrency').textContent = symbols[currency] || currency;

            updateCurrencyPreview();
        });

        // Entry fee currency preview
        function updateCurrencyPreview() {
            const entryFee = parseFloat(document.getElementById('entry_fee').value);
            const baseCurrency = document.getElementById('currency').value;
            const previewContainer = document.getElementById('currencyPreview');

            if (!entryFee || !baseCurrency) {
                previewContainer.innerHTML = '<div class="col-12 text-muted">Enter entry fee to see pricing in all supported currencies</div>';
                return;
            }

            const currencies = ['GBP', 'USD', 'EUR', 'CAD', 'AUD'];
            const symbols = {
                'GBP': '£',
                'USD': ',
                'EUR': '€',
                'CAD': 'C,
                'AUD': 'A
            };

            let html = '';
            currencies.forEach(currency => {
                let amount = entryFee;

                if (currency !== baseCurrency && exchangeRates[baseCurrency] && exchangeRates[baseCurrency][currency]) {
                    amount = entryFee * exchangeRates[baseCurrency][currency];
                }

                const isBase = currency === baseCurrency;
                html += `
                    <div class="col-md-4 col-6 mb-2">
                        <div class="text-center p-2 ${isBase ? 'bg-primary text-white' : 'bg-white'} rounded">
                            <strong>${symbols[currency] || currency} ${amount.toFixed(2)}</strong>
                            <br><small>${currency}${isBase ? ' (Base)' : ''}</small>
                        </div>
                    </div>
                `;
            });

            previewContainer.innerHTML = html;
        }

        document.getElementById('entry_fee').addEventListener('input', updateCurrencyPreview);

        // Description character counter
        document.getElementById('description').addEventListener('input', function() {
            const count = this.value.length;
            document.getElementById('descriptionCount').textContent = count;

            if (count > 1000) {
                this.classList.add('is-invalid');
            } else {
                this.classList.remove('is-invalid');
            }
        });

        // Game flow preview updates
        function updateGameFlowPreview() {
            const totalQuestions = parseInt(document.getElementById('total_questions').value) || 9;
            const freeQuestions = parseInt(document.getElementById('free_questions').value) || 3;
            const timeout = parseInt(document.getElementById('question_timeout').value) || 10;
            const entryFee = parseFloat(document.getElementById('entry_fee').value) || 10;
            const currency = document.getElementById('currency').value || 'GBP';

            const symbols = {
                'GBP': '£',
                'USD': ',
                'EUR': '€',
                'CAD': 'C,
                'AUD': 'A
            };

            document.getElementById('flowFreeQuestions').textContent = freeQuestions;
            document.getElementById('flowPaidStart').textContent = freeQuestions + 1;
            document.getElementById('flowTotalQuestions').textContent = totalQuestions;
            document.getElementById('flowTimeout').textContent = timeout;
            document.getElementById('flowEntryFee').textContent = `${symbols[currency] || currency}${entryFee.toFixed(2)}`;
        }

        // Update flow preview when settings change
        ['total_questions', 'free_questions', 'question_timeout', 'entry_fee', 'currency'].forEach(id => {
            document.getElementById(id).addEventListener('input', updateGameFlowPreview);
            document.getElementById(id).addEventListener('change', updateGameFlowPreview);
        });

        // Requirements tracking
        function updateRequirements() {
            const requirements = {
                name: document.getElementById('name').value.trim().length >= 3,
                description: document.getElementById('description').value.trim().length >= 10,
                pricing: parseFloat(document.getElementById('entry_fee').value) >= 0.50 && parseFloat(document.getElementById('prize_value').value) >= 0.01,
                settings: parseInt(document.getElementById('total_questions').value) >= 6
            };

            let completed = 0;
            Object.keys(requirements).forEach(key => {
                const item = document.querySelector(`[data-requirement="${key}"]`);
                const icon = item.querySelector('i');

                if (requirements[key]) {
                    icon.className = 'fas fa-check-circle text-success me-2';
                    completed++;
                } else {
                    icon.className = 'fas fa-circle text-muted me-2';
                }
            });

            const percentage = Math.round((completed / Object.keys(requirements).length) * 100);
            document.getElementById('completionProgress').style.width = `${percentage}%`;
            document.getElementById('completionPercentage').textContent = `${percentage}%`;
        }

        // Review section update
        function updateReviewSection() {
            const currency = document.getElementById('currency').value;
            const symbols = {
                'GBP': '£', 'USD': ', 'EUR': '€', 'CAD': 'C, 'AUD': 'A
            };
            const symbol = symbols[currency] || currency;

            document.getElementById('reviewName').textContent = document.getElementById('name').value || '-';
            document.getElementById('reviewSlug').textContent = document.getElementById('slug').value || '-';
            document.getElementById('reviewStatus').textContent = document.getElementById('status').selectedOptions[0].text || '-';
            document.getElementById('reviewDescription').textContent = document.getElementById('description').value.substring(0, 100) + (document.getElementById('description').value.length > 100 ? '...' : '') || '-';
            document.getElementById('reviewPrizeValue').textContent = `${symbol}${parseFloat(document.getElementById('prize_value').value || 0).toFixed(2)}`;
            document.getElementById('reviewEntryFee').textContent = `${symbol}${parseFloat(document.getElementById('entry_fee').value || 0).toFixed(2)}`;
            document.getElementById('reviewMaxPlayers').textContent = parseInt(document.getElementById('max_players').value || 0).toLocaleString();
            document.getElementById('reviewQuestions').textContent = `${document.getElementById('total_questions').value} total (${document.getElementById('free_questions').value} free)`;
            document.getElementById('reviewAutoRestart').textContent = document.getElementById('auto_restart').checked ? 'Yes' : 'No';
        }

        // Form submission
        document.getElementById('createGameForm').addEventListener('submit', function(e) {
            e.preventDefault();

            if (!validateCurrentStep()) {
                return;
            }

            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Creating Game...';

            // Submit the form
            this.submit();
        });

        // Real-time validation
        document.querySelectorAll('input, textarea, select').forEach(field => {
            field.addEventListener('blur', function() {
                validateField(this);
                updateRequirements();
            });

            field.addEventListener('input', function() {
                updateRequirements();
            });
        });

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            showStep(1);
            updateGameFlowPreview();
            updateRequirements();

            // Focus on first field
            document.getElementById('name').focus();
        });

        // Keyboard navigation
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
                e.preventDefault();
                if (currentStep < totalSteps) {
                    nextStep();
                }
            }
        });

        // Auto-save to localStorage (optional)
        function autoSave() {
            const formData = new FormData(document.getElementById('createGameForm'));
            const data = {};
            for (let [key, value] of formData.entries()) {
                data[key] = value;
            }
            localStorage.setItem('winabn_game_draft', JSON.stringify(data));
        }

        function loadDraft() {
            const draft = localStorage.getItem('winabn_game_draft');
            if (draft) {
                try {
                    const data = JSON.parse(draft);
                    Object.keys(data).forEach(key => {
                        const field = document.querySelector(`[name="${key}"]`);
                        if (field) {
                            if (field.type === 'checkbox') {
                                field.checked = data[key] === 'on';
                            } else {
                                field.value = data[key];
                            }
                        }
                    });
                } catch (e) {
                    console.warn('Failed to load draft:', e);
                }
            }
        }

        // Auto-save every 30 seconds
        setInterval(autoSave, 30000);

        // Clear draft on successful submission
        window.addEventListener('beforeunload', function() {
            if (document.getElementById('createGameForm').querySelector('button[type="submit"]').disabled) {
                localStorage.removeItem('winabn_game_draft');
            }
        });
    </script>

    <style>
        .step-progress {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 0;
            position: relative;
        }

        .step-progress::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 2rem;
            right: 2rem;
            height: 2px;
            background: #dee2e6;
            z-index: 1;
        }

        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            z-index: 2;
        }

        .step-circle {
            width: 2rem;
            height: 2rem;
            border-radius: 50%;
            background: #dee2e6;
            color: #6c757d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-bottom: 0.5rem;
            transition: all 0.3s ease;
        }

        .step.active .step-circle {
            background: #0d6efd;
            color: white;
        }

        .step.completed .step-circle {
            background: #198754;
            color: white;
        }

        .step-label {
            font-size: 0.875rem;
            font-weight: 500;
            color: #6c757d;
        }

        .step.active .step-label {
            color: #0d6efd;
        }

        .step.completed .step-label {
            color: #198754;
        }

        .game-flow-preview {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .flow-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            min-width: 80px;
        }

        .flow-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
        }

        .flow-arrow {
            font-size: 1.25rem;
            color: #6c757d;
            margin: 0 0.5rem;
        }

        .requirement-item {
            display: flex;
            align-items: center;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
        }

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

        @media (max-width: 768px) {
            .step-progress {
                flex-direction: column;
                gap: 1rem;
            }

            .step-progress::before {
                display: none;
            }

            .game-flow-preview {
                flex-direction: column;
            }

            .flow-arrow {
                transform: rotate(90deg);
            }
        }

        .form-control:focus, .form-select:focus {
            border-color: #86b7fe;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }

        .is-valid {
            border-color: #198754;
        }

        .is-invalid {
            border-color: #dc3545;
        }

        .tip-step h6 {
            color: #495057;
            margin-bottom: 0.75rem;
        }

        .tip-step ul {
            padding-left: 1.25rem;
        }

        .tip-step li {
            margin-bottom: 0.25rem;
            line-height: 1.4;
        }
    </style>
</body>
</html>
