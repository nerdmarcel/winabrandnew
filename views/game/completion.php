<?php
/**
 * File: views/game/completion.php
 * Location: views/game/completion.php
 *
 * WinABN Game Completion Page Template
 *
 * Shows completion status, winner results, and replay options.
 * Handles both winner and non-winner scenarios with appropriate messaging.
 */

$this->extend('layouts/main');
$this->section('content');
?>

<div class="completion-container py-5">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">

                <?php if ($is_winner): ?>
                <!-- Winner Section -->
                <div class="winner-announcement text-center mb-5">
                    <div class="winner-animation mb-4">
                        <div class="trophy-container">
                            <i class="fas fa-trophy fa-5x text-warning mb-3 trophy-bounce"></i>
                        </div>
                        <div class="confetti" id="confetti"></div>
                    </div>

                    <h1 class="display-4 fw-bold text-success mb-3">
                        🎉 CONGRATULATIONS! 🎉
                    </h1>

                    <div class="winner-message bg-success bg-opacity-10 rounded-3 p-4 mb-4">
                        <h2 class="h3 text-success mb-3">
                            You won the <?= e($participant['game_name']) ?>!
                        </h2>
                        <p class="lead mb-3">
                            You completed all questions correctly in just
                            <strong><?= round($total_time, 2) ?> seconds</strong> -
                            making you the fastest player in this round!
                        </p>
                        <div class="prize-details bg-white rounded-2 p-3">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <h4 class="h5 mb-1">Your Prize:</h4>
                                    <div class="h3 fw-bold text-success mb-0">
                                        <?= e($participant['game_name']) ?>
                                    </div>
                                </div>
                                <div class="col-md-4 text-md-end">
                                    <div class="prize-value">
                                        <small class="text-muted d-block">Worth</small>
                                        <div class="h4 fw-bold text-primary mb-0">
                                            <?= $this->formatCurrency($participant['prize_value'], $participant['currency']) ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Winner Next Steps -->
                    <div class="winner-next-steps bg-light rounded-3 p-4 mb-4">
                        <h5 class="fw-bold mb-3">
                            <i class="fas fa-clipboard-list text-primary me-2"></i>
                            What happens next?
                        </h5>
                        <div class="row g-3 text-start">
                            <div class="col-md-4">
                                <div class="step-item">
                                    <div class="step-number bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width: 30px; height: 30px;">1</div>
                                    <h6 class="fw-bold">Verification</h6>
                                    <small class="text-muted">We'll verify your win within 24 hours</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="step-item">
                                    <div class="step-number bg-success text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width: 30px; height: 30px;">2</div>
                                    <h6 class="fw-bold">Contact</h6>
                                    <small class="text-muted">We'll contact you via WhatsApp/email</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="step-item">
                                    <div class="step-number bg-warning text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-2" style="width: 30px; height: 30px;">3</div>
                                    <h6 class="fw-bold">Delivery</h6>
                                    <small class="text-muted">Free delivery to your address</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Social Sharing -->
                    <div class="social-sharing mb-4">
                        <h6 class="fw-bold mb-3">Share your win!</h6>
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-primary" onclick="shareOnFacebook()">
                                <i class="fab fa-facebook-f me-1"></i>
                                Facebook
                            </button>
                            <button type="button" class="btn btn-info text-white" onclick="shareOnTwitter()">
                                <i class="fab fa-twitter me-1"></i>
                                Twitter
                            </button>
                            <button type="button" class="btn btn-success" onclick="shareOnWhatsApp()">
                                <i class="fab fa-whatsapp me-1"></i>
                                WhatsApp
                            </button>
                        </div>
                    </div>

                <?php else: ?>
                <!-- Non-Winner Section -->
                <div class="completion-announcement text-center mb-5">
                    <div class="completion-icon mb-4">
                        <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                    </div>

                    <h1 class="h2 fw-bold mb-3">
                        Well Done! Game Complete
                    </h1>

                    <div class="completion-message bg-light rounded-3 p-4 mb-4">
                        <h3 class="h4 mb-3">
                            You completed <?= e($participant['game_name']) ?>!
                        </h3>
                        <p class="lead mb-3">
                            You answered all questions correctly in
                            <strong><?= round($total_time, 2) ?> seconds</strong>.
                        </p>

                        <?php if ($round_status === 'completed'): ?>
                        <div class="alert alert-info">
                            <h6 class="fw-bold mb-2">
                                <i class="fas fa-info-circle me-1"></i>
                                Round Complete
                            </h6>
                            <p class="mb-0">
                                This round has finished and the winner has been selected.
                                Unfortunately, you weren't the fastest this time, but great job completing the challenge!
                            </p>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-warning">
                            <h6 class="fw-bold mb-2">
                                <i class="fas fa-hourglass-half me-1"></i>
                                Waiting for Results
                            </h6>
                            <p class="mb-0">
                                The round is still active. We'll determine the winner once all participants have finished
                                or the round closes. You'll be notified of the results!
                            </p>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Game Statistics -->
                <div class="game-stats bg-white rounded-3 shadow-sm p-4 mb-4">
                    <h5 class="fw-bold mb-3">
                        <i class="fas fa-chart-bar text-primary me-2"></i>
                        Your Performance
                    </h5>
                    <div class="row g-3">
                        <div class="col-sm-4">
                            <div class="stat-item text-center">
                                <div class="h3 fw-bold text-primary mb-1"><?= round($total_time, 2) ?>s</div>
                                <small class="text-muted">Total Time</small>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="stat-item text-center">
                                <div class="h3 fw-bold text-success mb-1"><?= $participant['total_questions'] ?>/<?= $participant['total_questions'] ?></div>
                                <small class="text-muted">Questions Correct</small>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="stat-item text-center">
                                <div class="h3 fw-bold text-info mb-1"><?= round($total_time / $participant['total_questions'], 2) ?>s</div>
                                <small class="text-muted">Avg per Question</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="action-buttons text-center">
                    <div class="row g-3 justify-content-center">
                        <div class="col-sm-auto">
                            <a href="<?= url('/win-a-' . $participant['slug']) ?>"
                               class="btn btn-primary btn-lg px-4">
                                <i class="fas fa-redo me-2"></i>
                                Play Again
                                <span class="badge bg-success ms-2">10% Off</span>
                            </a>
                        </div>
                        <div class="col-sm-auto">
                            <a href="<?= url('/') ?>"
                               class="btn btn-outline-primary btn-lg px-4">
                                <i class="fas fa-home me-2"></i>
                                Other Games
                            </a>
                        </div>
                        <?php if (!$is_winner): ?>
                        <div class="col-sm-auto">
                            <button type="button"
                                    class="btn btn-success btn-lg px-4"
                                    data-bs-toggle="modal"
                                    data-bs-target="#referralModal">
                                <i class="fas fa-share me-2"></i>
                                Refer Friends
                                <span class="badge bg-light text-success ms-2">Earn 10%</span>
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- More Games Section -->
                <div class="more-games mt-5">
                    <h5 class="fw-bold text-center mb-4">Try More Games</h5>
                    <div class="row g-3">
                        <!-- This would be populated with other active games -->
                        <div class="col-md-4">
                            <div class="game-card bg-white rounded-3 shadow-sm p-3 text-center">
                                <img src="<?= url('assets/images/prizes/win-a-macbook-air-m3.jpg') ?>"
                                     alt="MacBook Air"
                                     class="img-fluid rounded mb-2"
                                     style="height: 100px; object-fit: cover;">
                                <h6 class="fw-bold mb-1">MacBook Air M3</h6>
                                <small class="text-muted d-block mb-2">£15 entry</small>
                                <a href="<?= url('/win-a-macbook-air-m3') ?>" class="btn btn-outline-primary btn-sm">
                                    Play Now
                                </a>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="game-card bg-white rounded-3 shadow-sm p-3 text-center">
                                <img src="<?= url('assets/images/prizes/win-a-ps5-pro.jpg') ?>"
                                     alt="PlayStation 5"
                                     class="img-fluid rounded mb-2"
                                     style="height: 100px; object-fit: cover;">
                                <h6 class="fw-bold mb-1">PlayStation 5 Pro</h6>
                                <small class="text-muted d-block mb-2">£7.50 entry</small>
                                <a href="<?= url('/win-a-ps5-pro') ?>" class="btn btn-outline-primary btn-sm">
                                    Play Now
                                </a>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="game-card bg-white rounded-3 shadow-sm p-3 text-center">
                                <img src="<?= url('assets/images/prizes/win-1000-cash.jpg') ?>"
                                     alt="Cash Prize"
                                     class="img-fluid rounded mb-2"
                                     style="height: 100px; object-fit: cover;">
                                <h6 class="fw-bold mb-1">£1000 Cash</h6>
                                <small class="text-muted d-block mb-2">£5 entry</small>
                                <a href="<?= url('/win-1000-cash') ?>" class="btn btn-outline-primary btn-sm">
                                    Play Now
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- Referral Modal -->
<div class="modal fade" id="referralModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-share text-success me-2"></i>
                    Refer Friends & Earn
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-4">
                    <div class="referral-benefit bg-success bg-opacity-10 rounded-3 p-3 mb-3">
                        <h6 class="text-success fw-bold mb-2">Double Benefit!</h6>
                        <p class="mb-0">
                            <i class="fas fa-user-friends me-1"></i>
                            Your friend gets <strong>10% off</strong> their first game<br>
                            <i class="fas fa-gift me-1"></i>
                            You get <strong>10% off</strong> your next game
                        </p>
                    </div>
                </div>

                <div class="referral-link mb-3">
                    <label class="form-label fw-bold">Your Referral Link:</label>
                    <div class="input-group">
                        <input type="text"
                               class="form-control"
                               id="referralLink"
                               value="<?= url('/win-a-' . $participant['slug'] . '?ref=' . base64_encode($participant['user_email'])) ?>"
                               readonly>
                        <button class="btn btn-outline-secondary"
                                type="button"
                                onclick="copyReferralLink()">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                </div>

                <div class="social-share">
                    <p class="fw-bold mb-2">Share on social media:</p>
                    <div class="btn-group w-100" role="group">
                        <button type="button" class="btn btn-primary" onclick="shareReferralFacebook()">
                            <i class="fab fa-facebook-f"></i>
                        </button>
                        <button type="button" class="btn btn-info text-white" onclick="shareReferralTwitter()">
                            <i class="fab fa-twitter"></i>
                        </button>
                        <button type="button" class="btn btn-success" onclick="shareReferralWhatsApp()">
                            <i class="fab fa-whatsapp"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php $this->endSection(); ?>

<?php $this->section('styles'); ?>
<style>
/* Completion Page Specific Styles */
.completion-container {
    background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
    min-height: 100vh;
}

.trophy-bounce {
    animation: trophy-bounce 2s ease-in-out infinite;
}

@keyframes trophy-bounce {
    0%, 100% { transform: translateY(0px); }
    50% { transform: translateY(-10px); }
}

.confetti {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
}

.confetti::before,
.confetti::after {
    content: '';
    position: absolute;
    width: 10px;
    height: 10px;
    background: #ffc107;
    animation: confetti-fall 3s ease-in-out infinite;
}

.confetti::before {
    left: 20%;
    animation-delay: 0s;
    background: #dc3545;
}

.confetti::after {
    left: 80%;
    animation-delay: 1s;
    background: #198754;
}

@keyframes confetti-fall {
    0% {
        transform: translateY(-100px) rotate(0deg);
        opacity: 1;
    }
    100% {
        transform: translateY(400px) rotate(720deg);
        opacity: 0;
    }
}

.winner-announcement {
    animation: slideInUp 0.8s ease;
}

.completion-announcement {
    animation: fadeInUp 0.6s ease;
}

@keyframes slideInUp {
    from {
        transform: translateY(50px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

@keyframes fadeInUp {
    from {
        transform: translateY(30px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.stat-item {
    transition: transform 0.3s ease;
}

.stat-item:hover {
    transform: translateY(-3px);
}

.game-card {
    transition: all 0.3s ease;
    border: 1px solid rgba(0,0,0,0.1);
}

.game-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15) !important;
}

.action-buttons .btn {
    transition: all 0.3s ease;
}

.action-buttons .btn:hover {
    transform: translateY(-2px);
}

.step-item {
    transition: transform 0.3s ease;
}

.step-item:hover {
    transform: translateY(-2px);
}

/* Mobile Optimizations */
@media (max-width: 768px) {
    .display-4 {
        font-size: 2rem;
    }

    .trophy-container i {
        font-size: 3rem !important;
    }

    .action-buttons .btn {
        width: 100%;
        margin-bottom: 0.5rem;
    }

    .social-sharing .btn-group {
        width: 100%;
    }

    .social-sharing .btn {
        flex: 1;
    }
}

/* Animation delays for staggered effects */
.winner-announcement {
    animation-delay: 0.2s;
    animation-fill-mode: both;
}

.game-stats {
    animation: fadeInUp 0.6s ease 0.4s both;
}

.action-buttons {
    animation: fadeInUp 0.6s ease 0.6s both;
}

.more-games {
    animation: fadeInUp 0.6s ease 0.8s both;
}

/* Pulse effect for important elements */
.badge.bg-success {
    animation: pulse-success 2s infinite;
}

@keyframes pulse-success {
    0% { box-shadow: 0 0 0 0 rgba(25, 135, 84, 0.7); }
    70% { box-shadow: 0 0 0 10px rgba(25, 135, 84, 0); }
    100% { box-shadow: 0 0 0 0 rgba(25, 135, 84, 0); }
}
</style>
<?php $this->endSection(); ?>

<?php $this->section('scripts'); ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($is_winner): ?>
    // Winner-specific functionality
    triggerConfetti();

    // Auto-scroll to winner announcement after animation
    setTimeout(() => {
        document.querySelector('.winner-announcement').scrollIntoView({
            behavior: 'smooth',
            block: 'center'
        });
    }, 1000);
    <?php endif; ?>

    // Initialize tooltips if using Bootstrap tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});

<?php if ($is_winner): ?>
function triggerConfetti() {
    // Create multiple confetti elements
    const container = document.getElementById('confetti');

    for (let i = 0; i < 50; i++) {
        const confettiPiece = document.createElement('div');
        confettiPiece.style.position = 'absolute';
        confettiPiece.style.width = '8px';
        confettiPiece.style.height = '8px';
        confettiPiece.style.backgroundColor = `hsl(${Math.random() * 360}, 70%, 60%)`;
        confettiPiece.style.left = Math.random() * 100 + '%';
        confettiPiece.style.animationDelay = Math.random() * 3 + 's';
        confettiPiece.style.animation = 'confetti-fall 3s ease-in-out infinite';

        container.appendChild(confettiPiece);

        // Remove after animation
        setTimeout(() => {
            if (confettiPiece.parentNode) {
                confettiPiece.parentNode.removeChild(confettiPiece);
            }
        }, 6000);
    }
}

function shareOnFacebook() {
    const url = encodeURIComponent(window.location.href);
    const text = encodeURIComponent(`I just won <?= e($participant['game_name']) ?> on WinABN! 🎉`);
    window.open(`https://www.facebook.com/sharer/sharer.php?u=${url}&quote=${text}`, '_blank', 'width=600,height=400');
}

function shareOnTwitter() {
    const url = encodeURIComponent(window.location.href);
    const text = encodeURIComponent(`🎉 I just won <?= e($participant['game_name']) ?> on @WinABN! Check out their amazing competitions!`);
    window.open(`https://twitter.com/intent/tweet?text=${text}&url=${url}`, '_blank', 'width=600,height=400');
}

function shareOnWhatsApp() {
    const text = encodeURIComponent(`🎉 I just won <?= e($participant['game_name']) ?> on WinABN! Check out their competitions: ${window.location.origin}`);

    if (/Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)) {
        window.open(`whatsapp://send?text=${text}`, '_blank');
    } else {
        window.open(`https://web.whatsapp.com/send?text=${text}`, '_blank');
    }
}
<?php endif; ?>

function copyReferralLink() {
    const linkInput = document.getElementById('referralLink');
    linkInput.select();
    linkInput.setSelectionRange(0, 99999); // For mobile devices

    navigator.clipboard.writeText(linkInput.value).then(function() {
        // Show success feedback
        const btn = event.target.closest('button');
        const originalHTML = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check text-success"></i>';
        btn.classList.add('btn-success');
        btn.classList.remove('btn-outline-secondary');

        setTimeout(() => {
            btn.innerHTML = originalHTML;
            btn.classList.remove('btn-success');
            btn.classList.add('btn-outline-secondary');
        }, 2000);
    }).catch(function() {
        // Fallback for older browsers
        document.execCommand('copy');
        alert('Referral link copied to clipboard!');
    });
}

function shareReferralFacebook() {
    const url = encodeURIComponent(document.getElementById('referralLink').value);
    const text = encodeURIComponent(`Check out this amazing competition on WinABN! You can win incredible prizes by answering quick questions. Use my link to get 10% off your first game!`);
    window.open(`https://www.facebook.com/sharer/sharer.php?u=${url}&quote=${text}`, '_blank', 'width=600,height=400');
}

function shareReferralTwitter() {
    const url = encodeURIComponent(document.getElementById('referralLink').value);
    const text = encodeURIComponent(`Win amazing prizes on @WinABN! Quick questions, big rewards! Get 10% off with my referral link:`);
    window.open(`https://twitter.com/intent/tweet?text=${text}&url=${url}`, '_blank', 'width=600,height=400');
}

function shareReferralWhatsApp() {
    const link = document.getElementById('referralLink').value;
    const text = encodeURIComponent(`🎮 Check out WinABN - win amazing prizes by answering quick questions!\n\n🎁 Use my referral link to get 10% off your first game:\n${link}\n\nI just completed a game and it was so much fun! You could win iPhones, MacBooks, PlayStation and more! 🏆`);

    if (/Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)) {
        window.open(`whatsapp://send?text=${text}`, '_blank');
    } else {
        window.open(`https://web.whatsapp.com/send?text=${text}`, '_blank');
    }
}

// Auto-refresh for round status updates (if round is still active)
<?php if ($round_status === 'active'): ?>
function checkRoundStatus() {
    fetch('<?= url('/api/round-status/' . $participant['round_id']) ?>')
    .then(response => response.json())
    .then(data => {
        if (data.status === 'completed' && data.winner_id) {
            // Round completed, refresh page to show final results
            location.reload();
        }
    })
    .catch(error => console.log('Status check failed:', error));
}

// Check every 30 seconds
setInterval(checkRoundStatus, 30000);
<?php endif; ?>
</script>
<?php $this->endSection(); ?>
