<?php
/**
 * File: views/game/landing.php
 * Location: views/game/landing.php
 *
 * WinABN Game Landing Page Template
 *
 * Mobile-first responsive landing page for individual games.
 * Optimized for conversion with clear CTAs and social proof.
 */

$this->extend('layouts/main');
$this->section('content');
?>

<!-- Hero Section -->
<div class="hero-section bg-gradient-primary text-white py-5">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-6 order-lg-1 order-2">
                <div class="hero-content">
                    <h1 class="display-4 fw-bold mb-3"><?= e($game['name']) ?></h1>
                    <p class="lead mb-4"><?= e($game['description']) ?></p>

                    <!-- Prize Value -->
                    <div class="prize-value-box bg-white text-dark rounded-3 p-4 mb-4">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <h3 class="h4 mb-1 text-primary">Prize Value</h3>
                                <div class="prize-amount h2 fw-bold text-success mb-0">
                                    <?= $this->formatCurrency($game['prize_value'], $game['currency']) ?>
                                </div>
                            </div>
                            <div class="prize-icon">
                                <i class="fas fa-trophy fa-3x text-warning"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Entry Info -->
                    <div class="entry-info mb-4">
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="info-card bg-white bg-opacity-20 rounded-2 p-3 text-center">
                                    <div class="h5 fw-bold mb-1"><?= $display_price['formatted'] ?></div>
                                    <small class="opacity-75">Entry Fee</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="info-card bg-white bg-opacity-20 rounded-2 p-3 text-center">
                                    <div class="h5 fw-bold mb-1"><?= $game['total_questions'] ?></div>
                                    <small class="opacity-75">Questions</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- CTA Button -->
                    <button id="startGameBtn" class="btn btn-warning btn-lg px-5 py-3 fw-bold rounded-pill">
                        <i class="fas fa-play me-2"></i>
                        Start Playing Now
                    </button>

                    <?php if ($discount_info['available']): ?>
                    <div class="discount-badge mt-3">
                        <span class="badge bg-success fs-6 px-3 py-2">
                            <i class="fas fa-percentage me-1"></i>
                            <?= $discount_info['percentage'] ?>% Discount Available!
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-lg-6 order-lg-2 order-1 mb-4 mb-lg-0">
                <div class="prize-image-container text-center">
                    <img src="<?= url("assets/images/prizes/{$game['slug']}.jpg") ?>"
                         alt="<?= e($game['name']) ?>"
                         class="img-fluid rounded-3 shadow-lg prize-image"
                         style="max-height: 400px; object-fit: cover;">
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Round Progress Section -->
<div class="round-progress-section py-4 bg-light">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="progress-info">
                    <h5 class="mb-2">Current Round Progress</h5>
                    <div class="progress mb-2" style="height: 25px;">
                        <div class="progress-bar bg-success progress-bar-striped progress-bar-animated"
                             role="progressbar"
                             style="width: <?= $current_round['fill_percentage'] ?>%"
                             aria-valuenow="<?= $current_round['fill_percentage'] ?>"
                             aria-valuemin="0"
                             aria-valuemax="100">
                            <?= round($current_round['fill_percentage'], 1) ?>%
                        </div>
                    </div>
                    <small class="text-muted">
                        <?= $current_round['paid_participant_count'] ?> of <?= $game['max_players'] ?> players entered
                    </small>
                </div>
            </div>
            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                <div class="urgency-message">
                    <?php if ($current_round['fill_percentage'] > 90): ?>
                        <span class="badge bg-danger fs-6 px-3 py-2 pulse">
                            <i class="fas fa-hourglass-half me-1"></i>
                            Almost Full!
                        </span>
                    <?php elseif ($current_round['fill_percentage'] > 75): ?>
                        <span class="badge bg-warning fs-6 px-3 py-2">
                            <i class="fas fa-fire me-1"></i>
                            Filling Fast!
                        </span>
                    <?php else: ?>
                        <span class="badge bg-primary fs-6 px-3 py-2">
                            <i class="fas fa-users me-1"></i>
                            Join Now!
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- How It Works Section -->
<div class="how-it-works-section py-5">
    <div class="container">
        <div class="row">
            <div class="col-lg-8 mx-auto text-center">
                <h2 class="h3 fw-bold mb-5">How to Win</h2>
                <div class="row g-4">
                    <div class="col-md-3 col-6">
                        <div class="step-card text-center">
                            <div class="step-icon bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 60px; height: 60px;">
                                <span class="fw-bold h4 mb-0">1</span>
                            </div>
                            <h6 class="fw-bold">Answer Questions</h6>
                            <small class="text-muted">First <?= $game['free_questions'] ?> questions are free</small>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="step-card text-center">
                            <div class="step-icon bg-success text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 60px; height: 60px;">
                                <span class="fw-bold h4 mb-0">2</span>
                            </div>
                            <h6 class="fw-bold">Pay Entry Fee</h6>
                            <small class="text-muted">Quick & secure payment</small>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="step-card text-center">
                            <div class="step-icon bg-warning text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 60px; height: 60px;">
                                <span class="fw-bold h4 mb-0">3</span>
                            </div>
                            <h6 class="fw-bold">Complete Quiz</h6>
                            <small class="text-muted">Answer remaining questions fast</small>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="step-card text-center">
                            <div class="step-icon bg-info text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 60px; height: 60px;">
                                <i class="fas fa-trophy"></i>
                            </div>
                            <h6 class="fw-bold">Win Prize</h6>
                            <small class="text-muted">Fastest correct time wins!</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Recent Winners Section -->
<?php if (!empty($recent_winners)): ?>
<div class="winners-section py-5 bg-light">
    <div class="container">
        <div class="row">
            <div class="col-lg-10 mx-auto">
                <h3 class="text-center mb-4">Recent Winners</h3>
                <div class="winners-carousel">
                    <div class="row g-3">
                        <?php foreach (array_slice($recent_winners, 0, 4) as $winner): ?>
                        <div class="col-md-6 col-lg-3">
                            <div class="winner-card bg-white rounded-3 p-3 text-center shadow-sm">
                                <div class="winner-avatar bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width: 50px; height: 50px;">
                                    <?= strtoupper(substr($winner['first_name'], 0, 1) . substr($winner['last_name'], 0, 1)) ?>
                                </div>
                                <h6 class="mb-1"><?= e($winner['first_name']) ?> <?= e(substr($winner['last_name'], 0, 1)) ?>.</h6>
                                <small class="text-muted d-block">Won <?= $this->formatCurrency($game['prize_value'], $game['currency']) ?></small>
                                <small class="text-success">Time: <?= round($winner['total_time_all_questions'], 2) ?>s</small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Trust Signals Section -->
<div class="trust-section py-4">
    <div class="container">
        <div class="row align-items-center text-center">
            <div class="col-md-3 col-6 mb-3 mb-md-0">
                <div class="trust-item">
                    <i class="fas fa-shield-alt text-success fa-2x mb-2"></i>
                    <small class="d-block fw-bold">Secure Payments</small>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-3 mb-md-0">
                <div class="trust-item">
                    <i class="fas fa-clock text-primary fa-2x mb-2"></i>
                    <small class="d-block fw-bold">Instant Results</small>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="trust-item">
                    <i class="fas fa-shipping-fast text-warning fa-2x mb-2"></i>
                    <small class="d-block fw-bold">Free Shipping</small>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="trust-item">
                    <i class="fas fa-users text-info fa-2x mb-2"></i>
                    <small class="d-block fw-bold">Thousands Playing</small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Start Game Modal -->
<div class="modal fade" id="startGameModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title">Ready to Play?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <p class="mb-4">You're about to start playing for the <strong><?= e($game['name']) ?></strong>!</p>

                <div class="game-rules bg-light rounded-3 p-3 mb-4 text-start">
                    <h6 class="fw-bold mb-2">Game Rules:</h6>
                    <ul class="small mb-0">
                        <li>Answer all <?= $game['total_questions'] ?> questions correctly</li>
                        <li>You have <?= $game['question_timeout'] ?> seconds per question</li>
                        <li>First <?= $game['free_questions'] ?> questions are free to try</li>
                        <li>Fastest correct completion wins the prize</li>
                        <li>One wrong answer = game over</li>
                    </ul>
                </div>

                <form id="startGameForm">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="game_id" value="<?= $game['id'] ?>">
                    <input type="hidden" name="device_fingerprint" id="deviceFingerprint">

                    <div class="mb-3">
                        <input type="email"
                               class="form-control form-control-lg"
                               name="email"
                               placeholder="Enter your email address"
                               required>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-100" id="confirmStartBtn">
                        <i class="fas fa-play me-2"></i>
                        Start Game Now
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php $this->endSection(); ?>

<?php $this->section('styles'); ?>
<style>
/* Game Landing Specific Styles */
.hero-section {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 70vh;
    display: flex;
    align-items: center;
}

.prize-image {
    transition: transform 0.3s ease;
}

.prize-image:hover {
    transform: scale(1.05);
}

.progress-bar-animated {
    animation: progress-bar-stripes 1s linear infinite;
}

.pulse {
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.05); }
    100% { transform: scale(1); }
}

.step-card {
    transition: transform 0.3s ease;
}

.step-card:hover {
    transform: translateY(-5px);
}

.winner-card {
    transition: all 0.3s ease;
}

.winner-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.1) !important;
}

.trust-item {
    transition: transform 0.3s ease;
}

.trust-item:hover {
    transform: translateY(-2px);
}

/* Mobile Optimizations */
@media (max-width: 768px) {
    .hero-section {
        min-height: auto;
        padding: 3rem 0;
    }

    .display-4 {
        font-size: 2rem;
    }

    .prize-value-box {
        text-align: center;
    }

    .info-card {
        margin-bottom: 1rem;
    }
}

/* Loading States */
.btn.loading {
    position: relative;
    color: transparent;
}

.btn.loading::after {
    content: "";
    position: absolute;
    width: 16px;
    height: 16px;
    top: 50%;
    left: 50%;
    margin-left: -8px;
    margin-top: -8px;
    border: 2px solid #ffffff;
    border-radius: 50%;
    border-top-color: transparent;
    animation: spin 1s ease-in-out infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>
<?php $this->endSection(); ?>

<?php $this->section('scripts'); ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Generate device fingerprint
    function generateDeviceFingerprint() {
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        ctx.textBaseline = 'top';
        ctx.font = '14px Arial';
        ctx.fillText('Device fingerprint', 2, 2);

        const fingerprint = [
            navigator.userAgent,
            navigator.language,
            screen.width + 'x' + screen.height,
            new Date().getTimezoneOffset(),
            canvas.toDataURL()
        ].join('|');

        return 'fp_' + btoa(fingerprint).substring(0, 32);
    }

    document.getElementById('deviceFingerprint').value = generateDeviceFingerprint();

    // Start game button handler
    document.getElementById('startGameBtn').addEventListener('click', function() {
        const modal = new bootstrap.Modal(document.getElementById('startGameModal'));
        modal.show();
    });

    // Start game form handler
    document.getElementById('startGameForm').addEventListener('submit', function(e) {
        e.preventDefault();

        const submitBtn = document.getElementById('confirmStartBtn');
        const formData = new FormData(this);

        // Add loading state
        submitBtn.classList.add('loading');
        submitBtn.disabled = true;

        fetch('<?= url('/game/start') ?>', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Store timing start
                sessionStorage.setItem('gameStartTime', Date.now());
                window.location.href = data.redirect_url;
            } else {
                throw new Error(data.error || 'Failed to start game');
            }
        })
        .catch(error => {
            alert('Error: ' + error.message);
            submitBtn.classList.remove('loading');
            submitBtn.disabled = false;
        });
    });

    // Real-time progress updates (optional)
    function updateProgress() {
        fetch('<?= url('/api/round-progress/' . $current_round['id']) ?>')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const progressBar = document.querySelector('.progress-bar');
                const progressText = document.querySelector('.round-progress-section small');

                progressBar.style.width = data.fill_percentage + '%';
                progressBar.textContent = Math.round(data.fill_percentage * 10) / 10 + '%';
                progressText.textContent = `${data.paid_participants} of <?= $game['max_players'] ?> players entered`;

                // Update urgency message
                const urgencyBadge = document.querySelector('.urgency-message .badge');
                if (data.fill_percentage > 90) {
                    urgencyBadge.className = 'badge bg-danger fs-6 px-3 py-2 pulse';
                    urgencyBadge.innerHTML = '<i class="fas fa-hourglass-half me-1"></i>Almost Full!';
                } else if (data.fill_percentage > 75) {
                    urgencyBadge.className = 'badge bg-warning fs-6 px-3 py-2';
                    urgencyBadge.innerHTML = '<i class="fas fa-fire me-1"></i>Filling Fast!';
                }
            }
        })
        .catch(error => console.log('Progress update failed:', error));
    }

    // Update progress every 30 seconds
    setInterval(updateProgress, 30000);

    // Add scroll animations
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };

    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, observerOptions);

    // Observe elements for animation
    document.querySelectorAll('.step-card, .winner-card, .trust-item').forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(20px)';
        el.style.transition = 'all 0.6s ease';
        observer.observe(el);
    });
});
</script>
<?php $this->endSection(); ?>
