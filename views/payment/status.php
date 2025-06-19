<?php
/**
 * File: views/payment/status.php
 * Location: views/payment/status.php
 *
 * WinABN Payment Status Page
 *
 * Displays payment processing status with real-time updates
 * and appropriate actions based on payment state.
 */

$pageTitle = 'Payment Status';
$refreshInterval = $refreshInterval ?? 5000; // Default 5 seconds
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> - WinABN</title>
    <link href="<?= url('assets/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= url('assets/css/game.css') ?>" rel="stylesheet">
    <style>
        .status-container {
            max-width: 600px;
            margin: 2rem auto;
            padding: 2rem;
        }
        .status-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
        }
        .spinner-border {
            width: 3rem;
            height: 3rem;
        }
        .payment-details {
            background: #f8f9fa;
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin: 1.5rem 0;
        }
        .status-pending {
            color: #ffc107;
        }
        .status-success {
            color: #28a745;
        }
        .status-failed {
            color: #dc3545;
        }
        .progress-dots {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin: 1rem 0;
        }
        .progress-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #dee2e6;
            animation: pulse 1.5s infinite;
        }
        .progress-dot:nth-child(1) { animation-delay: 0s; }
        .progress-dot:nth-child(2) { animation-delay: 0.2s; }
        .progress-dot:nth-child(3) { animation-delay: 0.4s; }
        @keyframes pulse {
            0%, 80%, 100% { opacity: 0.3; }
            40% { opacity: 1; }
        }
    </style>
    <?php if (isset($payment) && $payment['status'] === 'pending'): ?>
    <meta http-equiv="refresh" content="<?= (int)($refreshInterval / 1000) ?>">
    <?php endif; ?>
</head>
<body>
    <div class="container">
        <div class="status-container text-center">

            <?php if (isset($payment)): ?>

                <?php if ($payment['status'] === 'paid'): ?>
                    <!-- Payment Successful -->
                    <div class="status-icon status-success">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h2 class="text-success mb-3">Payment Successful!</h2>
                    <p class="lead">Your payment has been processed successfully.</p>

                    <div class="payment-details">
                        <div class="row">
                            <div class="col-6">
                                <strong>Amount Paid:</strong>
                            </div>
                            <div class="col-6">
                                <?= e(number_format($payment['amount'], 2)) ?> <?= e($payment['currency']) ?>
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-6">
                                <strong>Payment Method:</strong>
                            </div>
                            <div class="col-6">
                                <?= e(ucfirst($payment['provider'])) ?>
                            </div>
                        </div>
                        <?php if (isset($payment['paid_at'])): ?>
                        <div class="row mt-2">
                            <div class="col-6">
                                <strong>Completed:</strong>
                            </div>
                            <div class="col-6">
                                <?= date('j M Y, H:i', strtotime($payment['paid_at'])) ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="alert alert-success">
                        <strong>Next Step:</strong> You can now continue with the remaining questions to complete your entry!
                    </div>

                    <?php if (isset($continue_url)): ?>
                    <a href="<?= e($continue_url) ?>" class="btn btn-success btn-lg">
                        Continue Game <i class="fas fa-arrow-right ms-2"></i>
                    </a>
                    <?php endif; ?>

                <?php elseif ($payment['status'] === 'pending'): ?>
                    <!-- Payment Pending -->
                    <div class="status-icon status-pending">
                        <div class="spinner-border text-warning" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                    <h2 class="text-warning mb-3">Processing Payment...</h2>
                    <p class="lead">Please wait while we confirm your payment.</p>

                    <div class="progress-dots">
                        <div class="progress-dot"></div>
                        <div class="progress-dot"></div>
                        <div class="progress-dot"></div>
                    </div>

                    <div class="payment-details">
                        <div class="row">
                            <div class="col-6">
                                <strong>Amount:</strong>
                            </div>
                            <div class="col-6">
                                <?= e(number_format($payment['amount'], 2)) ?> <?= e($payment['currency']) ?>
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-6">
                                <strong>Payment Provider:</strong>
                            </div>
                            <div class="col-6">
                                <?= e(ucfirst($payment['provider'])) ?>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        This page will automatically refresh every <?= (int)($refreshInterval / 1000) ?> seconds.
                        <br>Please do not close this page or navigate away.
                    </div>

                    <button onclick="location.reload()" class="btn btn-outline-primary">
                        <i class="fas fa-sync-alt"></i> Check Status Now
                    </button>

                <?php elseif (in_array($payment['status'], ['failed', 'cancelled'])): ?>
                    <!-- Payment Failed -->
                    <div class="status-icon status-failed">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <h2 class="text-danger mb-3">Payment <?= ucfirst($payment['status']) ?></h2>
                    <p class="lead">
                        <?php if ($payment['status'] === 'failed'): ?>
                            Your payment could not be processed.
                        <?php else: ?>
                            Your payment was cancelled.
                        <?php endif; ?>
                    </p>

                    <?php if (!empty($payment['failed_reason'])): ?>
                    <div class="alert alert-danger">
                        <strong>Reason:</strong> <?= e($payment['failed_reason']) ?>
                    </div>
                    <?php endif; ?>

                    <div class="payment-details">
                        <div class="row">
                            <div class="col-6">
                                <strong>Attempted Amount:</strong>
                            </div>
                            <div class="col-6">
                                <?= e(number_format($payment['amount'], 2)) ?> <?= e($payment['currency']) ?>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        Don't worry! You can try again with a different payment method.
                        Your game progress has been saved.
                    </div>

                    <?php if (isset($retry_url)): ?>
                    <a href="<?= e($retry_url) ?>" class="btn btn-primary btn-lg me-3">
                        <i class="fas fa-credit-card"></i> Try Again
                    </a>
                    <?php endif; ?>

                    <a href="<?= url('/') ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-home"></i> Back to Games
                    </a>

                <?php else: ?>
                    <!-- Unknown Status -->
                    <div class="status-icon status-pending">
                        <i class="fas fa-question-circle"></i>
                    </div>
                    <h2 class="text-muted mb-3">Checking Payment Status...</h2>
                    <p class="lead">Please wait while we verify your payment.</p>

                    <button onclick="location.reload()" class="btn btn-primary">
                        <i class="fas fa-sync-alt"></i> Refresh Status
                    </button>
                <?php endif; ?>

            <?php else: ?>
                <!-- No Payment Data -->
                <div class="status-icon status-failed">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h2 class="text-warning mb-3">Payment Not Found</h2>
                <p class="lead">We couldn't find information about this payment.</p>

                <div class="alert alert-warning">
                    This could happen if the payment link has expired or is invalid.
                </div>

                <a href="<?= url('/') ?>" class="btn btn-primary">
                    <i class="fas fa-home"></i> Start New Game
                </a>
            <?php endif; ?>

            <!-- Support Information -->
            <div class="mt-4 pt-4 border-top">
                <small class="text-muted">
                    Having trouble? Contact our support team at
                    <a href="mailto:support@winabn.com">support@winabn.com</a>
                    <?php if (isset($payment)): ?>
                    <br>Reference ID: #<?= e($payment['id']) ?>
                    <?php endif; ?>
                </small>
            </div>
        </div>
    </div>

    <script src="<?= url('assets/js/bootstrap.bundle.min.js') ?>"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/js/all.min.js"></script>

    <?php if (isset($payment) && $payment['status'] === 'pending'): ?>
    <script>
        // Auto-refresh for pending payments
        let refreshCount = 0;
        const maxRefreshes = 20; // Maximum 20 refreshes (about 5 minutes with 15-second intervals)

        function autoRefresh() {
            if (refreshCount >= maxRefreshes) {
                // Stop auto-refresh after maximum attempts
                document.querySelector('.alert-info').innerHTML =
                    '<i class="fas fa-exclamation-triangle"></i> Payment verification is taking longer than expected. Please contact support if the issue persists.';
                return;
            }

            refreshCount++;

            // Use fetch to check status without full page reload
            fetch(window.location.href + '&ajax=1')
                .then(response => response.json())
                .then(data => {
                    if (data.status !== 'pending') {
                        location.reload(); // Reload page to show updated status
                    }
                })
                .catch(() => {
                    // Fallback to full page refresh on error
                    location.reload();
                });
        }

        // Set up auto-refresh
        setTimeout(() => {
            setInterval(autoRefresh, <?= $refreshInterval ?>);
        }, <?= $refreshInterval ?>);

        // Add visual feedback for auto-refresh
        let dots = 0;
        setInterval(() => {
            const statusText = document.querySelector('h2');
            if (statusText && statusText.textContent.includes('Processing')) {
                dots = (dots + 1) % 4;
                const baseText = 'Processing Payment';
                statusText.textContent = baseText + '.'.repeat(dots);
            }
        }, 500);
    </script>
    <?php endif; ?>

    <?php if (isset($payment) && $payment['status'] === 'paid'): ?>
    <script>
        // Add confetti effect for successful payment
        function createConfetti() {
            const colors = ['#ff6b6b', '#4ecdc4', '#45b7d1', '#f9ca24', '#f0932b'];
            const confettiCount = 50;

            for (let i = 0; i < confettiCount; i++) {
                setTimeout(() => {
                    const confetti = document.createElement('div');
                    confetti.style.cssText = `
                        position: fixed;
                        top: -10px;
                        left: ${Math.random() * 100}vw;
                        width: 8px;
                        height: 8px;
                        background: ${colors[Math.floor(Math.random() * colors.length)]};
                        border-radius: 50%;
                        animation: confetti-fall 3s linear forwards;
                        pointer-events: none;
                        z-index: 9999;
                    `;

                    document.body.appendChild(confetti);

                    setTimeout(() => confetti.remove(), 3000);
                }, i * 50);
            }
        }

        // Add CSS for confetti animation
        const style = document.createElement('style');
        style.textContent = `
            @keyframes confetti-fall {
                0% {
                    transform: translateY(-100vh) rotate(0deg);
                    opacity: 1;
                }
                100% {
                    transform: translateY(100vh) rotate(720deg);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);

        // Trigger confetti after page load
        setTimeout(createConfetti, 500);
    </script>
    <?php endif; ?>
</body>
</html>
