<?php
/**
 * File: views/game/quiz_interface.php
 * Location: views/game/quiz_interface.php
 *
 * WinABN Quiz Interface Template
 *
 * Real-time quiz interface with countdown timer, mobile-optimized layout,
 * and secure answer submission with timing accuracy.
 */

$this->extend('layouts/main');
$this->section('content');
?>

<div class="quiz-container">
    <!-- Progress Header -->
    <div class="quiz-header bg-white shadow-sm py-3 fixed-top">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-4">
                    <div class="game-logo">
                        <img src="<?= url('assets/images/winabn-logo-small.png') ?>"
                             alt="WinABN"
                             height="30">
                    </div>
                </div>
                <div class="col-4 text-center">
                    <div class="question-counter">
                        <span class="current-question h5 fw-bold text-primary"><?= $current_question_number ?></span>
                        <span class="text-muted">of</span>
                        <span class="total-questions h5 fw-bold"><?= $total_questions ?></span>
                    </div>
                </div>
                <div class="col-4 text-end">
                    <div class="timer-display">
                        <div id="timerCircle" class="timer-circle">
                            <span id="timerText" class="timer-text fw-bold">10</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Progress Bar -->
            <div class="progress mt-2" style="height: 4px;">
                <div class="progress-bar bg-primary"
                     role="progressbar"
                     style="width: <?= $progress_percentage ?>%"
                     aria-valuenow="<?= $progress_percentage ?>"
                     aria-valuemin="0"
                     aria-valuemax="100">
                </div>
            </div>
        </div>
    </div>

    <!-- Quiz Content -->
    <div class="quiz-content pt-5 mt-4">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8">

                    <!-- Question Phase Indicator -->
                    <?php if ($is_free_question): ?>
                    <div class="phase-indicator text-center mb-4">
                        <span class="badge bg-success px-3 py-2">
                            <i class="fas fa-gift me-1"></i>
                            Free Question <?= $current_question_number ?> of <?= $free_questions ?>
                        </span>
                    </div>
                    <?php else: ?>
                    <div class="phase-indicator text-center mb-4">
                        <span class="badge bg-primary px-3 py-2">
                            <i class="fas fa-trophy me-1"></i>
                            Competition Question <?= $current_question_number - $free_questions ?> of <?= $total_questions - $free_questions ?>
                        </span>
                    </div>
                    <?php endif; ?>

                    <!-- Question Card -->
                    <div class="question-card bg-white rounded-3 shadow-lg p-4 mb-4">
                        <div class="question-content">
                            <h2 class="question-text h4 fw-bold mb-4 text-center">
                                <?= e($question['question_text']) ?>
                            </h2>

                            <!-- Answer Options -->
                            <div class="answer-options" id="answerOptions">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <button type="button"
                                                class="answer-option btn btn-outline-primary w-100 p-3 text-start"
                                                data-answer="A"
                                                id="optionA">
                                            <span class="option-letter bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center me-3" style="width: 30px; height: 30px; font-size: 14px;">A</span>
                                            <span class="option-text"><?= e($question['option_a']) ?></span>
                                        </button>
                                    </div>
                                    <div class="col-12">
                                        <button type="button"
                                                class="answer-option btn btn-outline-primary w-100 p-3 text-start"
                                                data-answer="B"
                                                id="optionB">
                                            <span class="option-letter bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center me-3" style="width: 30px; height: 30px; font-size: 14px;">B</span>
                                            <span class="option-text"><?= e($question['option_b']) ?></span>
                                        </button>
                                    </div>
                                    <div class="col-12">
                                        <button type="button"
                                                class="answer-option btn btn-outline-primary w-100 p-3 text-start"
                                                data-answer="C"
                                                id="optionC">
                                            <span class="option-letter bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center me-3" style="width: 30px; height: 30px; font-size: 14px;">C</span>
                                            <span class="option-text"><?= e($question['option_c']) ?></span>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Keyboard Hints -->
                            <div class="keyboard-hints text-center mt-3">
                                <small class="text-muted">
                                    Quick keys: <kbd>A</kbd> <kbd>B</kbd> <kbd>C</kbd> or tap to answer
                                </small>
                            </div>
                        </div>
                    </div>

                    <!-- Game Rules Reminder -->
                    <?php if ($current_question_number === 1): ?>
                    <div class="rules-reminder bg-light rounded-3 p-3 mb-4">
                        <h6 class="fw-bold mb-2">
                            <i class="fas fa-info-circle text-primary me-1"></i>
                            Quick Reminder
                        </h6>
                        <ul class="small mb-0">
                            <li>You have <strong><?= $question_timeout ?> seconds</strong> per question</li>
                            <li>All answers must be correct to continue</li>
                            <li>Fastest overall time wins the prize</li>
                            <?php if ($is_free_question): ?>
                            <li>This is a <strong>free question</strong> - no payment required yet</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <!-- Final Question Notice -->
                    <?php if ($is_final_question): ?>
                    <div class="final-question-notice bg-warning bg-opacity-25 rounded-3 p-3 mb-4 text-center">
                        <h6 class="fw-bold text-warning mb-1">
                            <i class="fas fa-flag-checkered me-1"></i>
                            Final Question!
                        </h6>
                        <small>Answer correctly to complete the game and compete for the prize!</small>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>
</div>

<!-- Result Modal -->
<div class="modal fade" id="resultModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body text-center p-4">
                <div id="resultContent">
                    <!-- Dynamic content will be inserted here -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Hidden Form for Answer Submission -->
<form id="answerForm" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
    <input type="hidden" name="question_id" value="<?= $question['id'] ?>">
    <input type="hidden" name="participant_id" value="<?= $participant['id'] ?>">
    <input type="hidden" name="answer" id="selectedAnswer">
    <input type="hidden" name="question_start_time" id="questionStartTime">
    <input type="hidden" name="game_session_id" value="<?= $game_session_id ?>">
</form>

<?php $this->endSection(); ?>

<?php $this->section('styles'); ?>
<style>
/* Quiz Interface Specific Styles */
body {
    background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
}

.quiz-header {
    backdrop-filter: blur(10px);
    background: rgba(255, 255, 255, 0.95) !important;
    border-bottom: 1px solid rgba(0, 0, 0, 0.1);
    z-index: 1030;
}

.timer-circle {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: conic-gradient(#dc3545 0deg, #e9ecef 0deg);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    transition: all 0.1s ease;
}

.timer-circle.warning {
    background: conic-gradient(#ffc107 0deg, #e9ecef 0deg);
}

.timer-circle.danger {
    background: conic-gradient(#dc3545 0deg, #e9ecef 0deg);
    animation: pulse-red 1s infinite;
}

.timer-text {
    color: #333;
    font-size: 14px;
    position: relative;
    z-index: 1;
}

@keyframes pulse-red {
    0% { transform: scale(1); }
    50% { transform: scale(1.1); }
    100% { transform: scale(1); }
}

.question-card {
    border: none;
    min-height: 400px;
    display: flex;
    align-items: center;
    animation: slideInUp 0.5s ease;
}

@keyframes slideInUp {
    from {
        transform: translateY(30px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.answer-option {
    border: 2px solid #e9ecef;
    border-radius: 12px;
    transition: all 0.2s ease;
    font-size: 16px;
    min-height: 70px;
    display: flex;
    align-items: center;
}

.answer-option:hover {
    border-color: #0d6efd;
    background-color: #f8f9fa;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(13, 110, 253, 0.15);
}

.answer-option:active {
    transform: translateY(0);
}

.answer-option.selected {
    background-color: #0d6efd;
    border-color: #0d6efd;
    color: white;
}

.answer-option.selected .option-letter {
    background-color: white !important;
    color: #0d6efd !important;
}

.answer-option.correct {
    background-color: #198754;
    border-color: #198754;
    color: white;
    animation: correctPulse 0.6s ease;
}

.answer-option.wrong {
    background-color: #dc3545;
    border-color: #dc3545;
    color: white;
    animation: wrongShake 0.6s ease;
}

@keyframes correctPulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.02); }
    100% { transform: scale(1); }
}

@keyframes wrongShake {
    0%, 100% { transform: translateX(0); }
    25% { transform: translateX(-5px); }
    75% { transform: translateX(5px); }
}

.option-letter {
    flex-shrink: 0;
}

.option-text {
    flex: 1;
    text-align: left;
}

.phase-indicator {
    animation: fadeInDown 0.5s ease;
}

@keyframes fadeInDown {
    from {
        transform: translateY(-20px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

/* Mobile Optimizations */
@media (max-width: 768px) {
    .quiz-header {
        padding: 0.75rem 0;
    }

    .question-card {
        margin: 0 -15px;
        border-radius: 0;
        min-height: calc(100vh - 200px);
    }

    .answer-option {
        font-size: 15px;
        min-height: 60px;
        padding: 0.75rem 1rem;
    }

    .question-text {
        font-size: 1.25rem;
    }

    .timer-circle {
        width: 35px;
        height: 35px;
    }

    .timer-text {
        font-size: 12px;
    }
}

/* Touch Feedback */
@media (hover: none) {
    .answer-option:hover {
        transform: none;
        box-shadow: none;
    }

    .answer-option:active {
        background-color: #e3f2fd;
        transform: scale(0.98);
    }
}

/* Loading State */
.quiz-content.loading {
    opacity: 0.6;
    pointer-events: none;
}

.quiz-content.loading::after {
    content: "";
    position: fixed;
    top: 50%;
    left: 50%;
    width: 40px;
    height: 40px;
    margin: -20px 0 0 -20px;
    border: 3px solid #f3f3f3;
    border-top: 3px solid #0d6efd;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    z-index: 9999;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Accessibility */
@media (prefers-reduced-motion: reduce) {
    * {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
}

/* High contrast mode */
@media (prefers-contrast: high) {
    .answer-option {
        border-width: 3px;
    }

    .timer-circle {
        border: 2px solid #000;
    }
}
</style>
<?php $this->endSection(); ?>

<?php $this->section('scripts'); ?>
<script>
class QuizInterface {
    constructor() {
        this.timeLeft = <?= $question_timeout ?>;
        this.timerInterval = null;
        this.questionStartTime = Date.now();
        this.isAnswering = false;
        this.selectedAnswer = null;

        this.initializeInterface();
        this.startTimer();
        this.bindEvents();
    }

    initializeInterface() {
        // Set question start time
        document.getElementById('questionStartTime').value = this.questionStartTime;

        // Focus first option for keyboard navigation
        document.getElementById('optionA').focus();

        // Add keyboard listeners
        document.addEventListener('keydown', this.handleKeyboard.bind(this));

        // Prevent context menu on long press (mobile)
        document.addEventListener('contextmenu', e => e.preventDefault());

        // Prevent zoom on double tap (mobile)
        let lastTouchEnd = 0;
        document.addEventListener('touchend', function(e) {
            const now = Date.now();
            if (now - lastTouchEnd <= 300) {
                e.preventDefault();
            }
            lastTouchEnd = now;
        }, false);
    }

    startTimer() {
        this.updateTimerDisplay();

        this.timerInterval = setInterval(() => {
            this.timeLeft--;
            this.updateTimerDisplay();

            if (this.timeLeft <= 0) {
                this.handleTimeout();
            }
        }, 1000);
    }

    updateTimerDisplay() {
        const timerText = document.getElementById('timerText');
        const timerCircle = document.getElementById('timerCircle');

        timerText.textContent = this.timeLeft;

        // Update circular progress
        const totalTime = <?= $question_timeout ?>;
        const percentage = (this.timeLeft / totalTime) * 360;

        if (this.timeLeft <= 3) {
            timerCircle.className = 'timer-circle danger';
        } else if (this.timeLeft <= 5) {
            timerCircle.className = 'timer-circle warning';
        } else {
            timerCircle.className = 'timer-circle';
        }

        // Update conic gradient
        if (this.timeLeft > 0) {
            const color = this.timeLeft <= 3 ? '#dc3545' : (this.timeLeft <= 5 ? '#ffc107' : '#198754');
            timerCircle.style.background = `conic-gradient(${color} ${percentage}deg, #e9ecef ${percentage}deg)`;
        }
    }

    bindEvents() {
        // Answer option clicks
        document.querySelectorAll('.answer-option').forEach(option => {
            option.addEventListener('click', () => {
                if (!this.isAnswering) {
                    this.selectAnswer(option.dataset.answer);
                }
            });
        });
    }

    handleKeyboard(e) {
        if (this.isAnswering) return;

        switch(e.key.toLowerCase()) {
            case 'a':
                e.preventDefault();
                this.selectAnswer('A');
                break;
            case 'b':
                e.preventDefault();
                this.selectAnswer('B');
                break;
            case 'c':
                e.preventDefault();
                this.selectAnswer('C');
                break;
        }
    }

    selectAnswer(answer) {
        if (this.isAnswering) return;

        this.isAnswering = true;
        this.selectedAnswer = answer;

        // Clear timer
        clearInterval(this.timerInterval);

        // Visual feedback
        const selectedOption = document.querySelector(`[data-answer="${answer}"]`);
        selectedOption.classList.add('selected');

        // Disable all options
        document.querySelectorAll('.answer-option').forEach(option => {
            option.disabled = true;
        });

        // Submit answer after brief delay for visual feedback
        setTimeout(() => {
            this.submitAnswer();
        }, 300);
    }

    handleTimeout() {
        if (this.isAnswering) return;

        this.isAnswering = true;
        clearInterval(this.timerInterval);

        // Visual feedback for timeout
        document.querySelectorAll('.answer-option').forEach(option => {
            option.disabled = true;
            option.style.opacity = '0.5';
        });

        this.showResult({
            success: false,
            result: 'timeout',
            message: 'Time\'s up! You took too long to answer.',
            redirect_url: '<?= url('/win-a-' . $participant['slug'] . '?failed=timeout') ?>'
        });
    }

    submitAnswer() {
        const formData = new FormData(document.getElementById('answerForm'));
        formData.set('answer', this.selectedAnswer);
        formData.set('question_start_time', this.questionStartTime);
        formData.set('client_time', Date.now());

        // Show loading state
        document.querySelector('.quiz-content').classList.add('loading');

        fetch('<?= url('/game/answer') ?>', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            this.handleResponse(data);
        })
        .catch(error => {
            console.error('Submission error:', error);
            this.showResult({
                success: false,
                result: 'error',
                message: 'Connection error. Please check your internet and try again.',
                show_retry: true
            });
        })
        .finally(() => {
            document.querySelector('.quiz-content').classList.remove('loading');
        });
    }

    handleResponse(data) {
        if (data.success) {
            if (data.result === 'complete') {
                this.showResult(data);
            } else {
                // Show correct feedback and advance
                this.showCorrectFeedback(data);
            }
        } else {
            this.showResult(data);
        }
    }

    showCorrectFeedback(data) {
        const selectedOption = document.querySelector(`[data-answer="${this.selectedAnswer}"]`);
        selectedOption.classList.remove('selected');
        selectedOption.classList.add('correct');

        // Show brief success message
        const resultContent = `
            <div class="text-center">
                <div class="mb-3">
                    <i class="fas fa-check-circle text-success fa-3x"></i>
                </div>
                <h5 class="text-success">Correct!</h5>
                <p class="mb-3">${data.message}</p>
                <div class="d-flex justify-content-center align-items-center">
                    <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                    <span>Loading next question...</span>
                </div>
            </div>
        `;

        document.getElementById('resultContent').innerHTML = resultContent;
        const modal = new bootstrap.Modal(document.getElementById('resultModal'));
        modal.show();

        // Auto advance after 2 seconds
        setTimeout(() => {
            window.location.reload();
        }, 2000);
    }

    showResult(data) {
        let resultContent = '';

        switch(data.result) {
            case 'wrong':
                const correctOption = document.querySelector(`[data-answer="${data.correct_answer}"]`);
                const selectedOption = document.querySelector(`[data-answer="${this.selectedAnswer}"]`);

                selectedOption.classList.add('wrong');
                correctOption.classList.add('correct');

                resultContent = `
                    <div class="text-center">
                        <div class="mb-3">
                            <i class="fas fa-times-circle text-danger fa-3x"></i>
                        </div>
                        <h5 class="text-danger">Incorrect Answer</h5>
                        <p class="mb-3">${data.message}</p>
                        <p class="small text-muted mb-3">The correct answer was <strong>${data.correct_answer}</strong></p>
                        <button class="btn btn-primary" onclick="window.location.href='${data.redirect_url}'">
                            Try Another Game
                        </button>
                    </div>
                `;
                break;

            case 'timeout':
                resultContent = `
                    <div class="text-center">
                        <div class="mb-3">
                            <i class="fas fa-clock text-warning fa-3x"></i>
                        </div>
                        <h5 class="text-warning">Time's Up!</h5>
                        <p class="mb-3">${data.message}</p>
                        <button class="btn btn-primary" onclick="window.location.href='${data.redirect_url}'">
                            Try Another Game
                        </button>
                    </div>
                `;
                break;

            case 'complete':
                resultContent = `
                    <div class="text-center">
                        <div class="mb-3">
                            <i class="fas fa-trophy text-warning fa-3x"></i>
                        </div>
                        <h5 class="text-success">Game Complete!</h5>
                        <p class="mb-3">${data.message}</p>
                        <div class="d-flex justify-content-center align-items-center">
                            <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                            <span>Checking results...</span>
                        </div>
                    </div>
                `;

                setTimeout(() => {
                    window.location.href = data.redirect_url;
                }, 3000);
                break;

            case 'error':
                resultContent = `
                    <div class="text-center">
                        <div class="mb-3">
                            <i class="fas fa-exclamation-triangle text-danger fa-3x"></i>
                        </div>
                        <h5 class="text-danger">Connection Error</h5>
                        <p class="mb-3">${data.message}</p>
                        <button class="btn btn-primary" onclick="location.reload()">
                            Try Again
                        </button>
                    </div>
                `;
                break;
        }

        document.getElementById('resultContent').innerHTML = resultContent;
        const modal = new bootstrap.Modal(document.getElementById('resultModal'));
        modal.show();
    }
}

// Initialize quiz interface when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    window.quizInterface = new QuizInterface();

    // Prevent page refresh/navigation during quiz
    window.addEventListener('beforeunload', function(e) {
        if (!window.quizInterface.isAnswering) {
            const message = 'Are you sure you want to leave? Your progress will be lost.';
            e.returnValue = message;
            return message;
        }
    });

    // Handle visibility change (tab switching)
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            console.log('Tab switched - timer continues running');
        }
    });
});
</script>
<?php $this->endSection(); ?>
