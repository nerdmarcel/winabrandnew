<?php
/**
 * File: views/admin/login.php
 * Location: views/admin/login.php
 *
 * WinABN Admin Login Interface
 *
 * Responsive login form with 2FA support, security features,
 * and user-friendly error handling for admin authentication.
 *
 * @package WinABN\Views\Admin
 * @author WinABN Development Team
 * @version 1.0
 */

// Prevent direct access
if (!defined('WINABN_ROOT_DIR')) {
    exit('Direct access not allowed');
}
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? 'Admin Login - WinABN') ?></title>

    <!-- Bootstrap 5 CSS -->
    <link href="<?= url('assets/css/bootstrap.min.css') ?>" rel="stylesheet">

    <!-- Admin specific styles -->
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            overflow: hidden;
            max-width: 420px;
            width: 100%;
        }

        .login-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .login-header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 600;
        }

        .login-header p {
            margin: 10px 0 0 0;
            opacity: 0.9;
            font-size: 16px;
        }

        .login-body {
            padding: 40px;
        }

        .form-floating {
            margin-bottom: 20px;
        }

        .form-control {
            border-radius: 12px;
            border: 2px solid #e9ecef;
            padding: 15px;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }

        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 12px;
            padding: 15px;
            font-size: 16px;
            font-weight: 600;
            color: white;
            width: 100%;
            transition: all 0.3s ease;
            margin-top: 10px;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
            color: white;
        }

        .btn-login:disabled {
            background: #6c757d;
            transform: none;
            box-shadow: none;
        }

        .security-info {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid #667eea;
        }

        .security-info h6 {
            color: #667eea;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .security-info small {
            color: #6c757d;
            line-height: 1.5;
        }

        .alert {
            border-radius: 12px;
            border: none;
            margin-bottom: 20px;
        }

        .alert-warning {
            background: linear-gradient(135deg, #ffeaa7 0%, #fab1a0 100%);
            color: #d63031;
        }

        .alert-danger {
            background: linear-gradient(135deg, #ff7675 0%, #fd79a8 100%);
            color: white;
        }

        .alert-success {
            background: linear-gradient(135deg, #00b894 0%, #00cec9 100%);
            color: white;
        }

        .form-check {
            margin: 20px 0;
        }

        .form-check-input:checked {
            background-color: #667eea;
            border-color: #667eea;
        }

        .form-check-label {
            color: #495057;
            font-size: 14px;
        }

        .two-fa-section {
            display: none;
            background: #e8f4fd;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            border: 2px dashed #667eea;
        }

        .two-fa-section.show {
            display: block;
            animation: fadeIn 0.3s ease-in-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .loading-spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 2px solid #ffffff;
            border-top: 2px solid transparent;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-right: 10px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .btn-login.loading .loading-spinner {
            display: inline-block;
        }

        .attempts-remaining {
            text-align: center;
            color: #6c757d;
            font-size: 14px;
            margin-top: 15px;
        }

        .attempts-remaining.warning {
            color: #dc3545;
            font-weight: 600;
        }

        @media (max-width: 480px) {
            .login-container {
                padding: 10px;
            }

            .login-header {
                padding: 25px 20px;
            }

            .login-body {
                padding: 30px 20px;
            }

            .login-header h1 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <!-- Login Header -->
            <div class="login-header">
                <h1><?= e($app_name ?? 'WinABN') ?></h1>
                <p>Administration Portal</p>
            </div>

            <!-- Login Body -->
            <div class="login-body">
                <!-- Security Warnings -->
                <?php if (isset($is_locked) && $is_locked): ?>
                    <div class="alert alert-danger" role="alert">
                        <strong>Account Locked</strong><br>
                        Too many failed login attempts. Please try again later.
                    </div>
                <?php endif; ?>

                <?php if (isset($login_attempts_remaining) && $login_attempts_remaining <= 3 && $login_attempts_remaining > 0): ?>
                    <div class="alert alert-warning" role="alert">
                        <strong>Security Warning</strong><br>
                        <?= $login_attempts_remaining ?> login attempts remaining before temporary lockout.
                    </div>
                <?php endif; ?>

                <!-- Error/Success Messages Container -->
                <div id="messageContainer"></div>

                <!-- Security Information -->
                <div class="security-info">
                    <h6><i class="fas fa-shield-alt"></i> Security Notice</h6>
                    <small>
                        This is a secure admin area. All login attempts are monitored and logged.
                        Two-factor authentication is recommended for enhanced security.
                    </small>
                </div>

                <!-- Login Form -->
                <form id="loginForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">

                    <!-- Username/Email Field -->
                    <div class="form-floating">
                        <input
                            type="text"
                            class="form-control"
                            id="identifier"
                            name="identifier"
                            placeholder="Username or Email"
                            required
                            autocomplete="username"
                            <?= isset($is_locked) && $is_locked ? 'disabled' : '' ?>
                        >
                        <label for="identifier">Username or Email</label>
                        <div class="invalid-feedback"></div>
                    </div>

                    <!-- Password Field -->
                    <div class="form-floating">
                        <input
                            type="password"
                            class="form-control"
                            id="password"
                            name="password"
                            placeholder="Password"
                            required
                            autocomplete="current-password"
                            <?= isset($is_locked) && $is_locked ? 'disabled' : '' ?>
                        >
                        <label for="password">Password</label>
                        <div class="invalid-feedback"></div>
                    </div>

                    <!-- 2FA Section (Hidden by default) -->
                    <div id="twoFaSection" class="two-fa-section">
                        <h6 class="text-primary mb-3">
                            <i class="fas fa-mobile-alt"></i> Two-Factor Authentication
                        </h6>
                        <p class="small text-muted mb-3">
                            Enter the 6-digit code from your authenticator app.
                        </p>
                        <div class="form-floating">
                            <input
                                type="text"
                                class="form-control text-center"
                                id="totpCode"
                                name="totp_code"
                                placeholder="000000"
                                maxlength="6"
                                pattern="[0-9]{6}"
                                autocomplete="one-time-code"
                                style="font-size: 18px; letter-spacing: 3px;"
                            >
                            <label for="totpCode">6-Digit Code</label>
                            <div class="invalid-feedback"></div>
                        </div>
                    </div>

                    <!-- Remember Me -->
                    <div class="form-check">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            id="rememberMe"
                            name="remember_me"
                            <?= isset($is_locked) && $is_locked ? 'disabled' : '' ?>
                        >
                        <label class="form-check-label" for="rememberMe">
                            Keep me signed in for 24 hours
                        </label>
                    </div>

                    <!-- Login Button -->
                    <button
                        type="submit"
                        class="btn btn-login"
                        id="loginButton"
                        <?= isset($is_locked) && $is_locked ? 'disabled' : '' ?>
                    >
                        <span class="loading-spinner"></span>
                        <span class="button-text">Sign In</span>
                    </button>
                </form>

                <!-- Login Attempts Counter -->
                <?php if (isset($login_attempts_remaining) && $login_attempts_remaining > 0): ?>
                    <div class="attempts-remaining <?= $login_attempts_remaining <= 3 ? 'warning' : '' ?>">
                        <?= $login_attempts_remaining ?> attempts remaining
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS -->
    <script src="<?= url('assets/js/bootstrap.bundle.min.js') ?>"></script>

    <!-- Login Form JavaScript -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const loginForm = document.getElementById('loginForm');
            const loginButton = document.getElementById('loginButton');
            const buttonText = document.querySelector('.button-text');
            const messageContainer = document.getElementById('messageContainer');
            const twoFaSection = document.getElementById('twoFaSection');
            const totpCodeInput = document.getElementById('totpCode');

            let requiresTwoFA = false;

            // Form submission handler
            loginForm.addEventListener('submit', function(e) {
                e.preventDefault();

                if (loginButton.disabled) return;

                // Clear previous errors
                clearErrors();

                // Validate form
                if (!validateForm()) return;

                // Show loading state
                setLoadingState(true);

                // Prepare form data
                const formData = new FormData(loginForm);

                // Submit login request
                fetch('<?= url("adminportal/login") ?>', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    setLoadingState(false);

                    if (data.success) {
                        showMessage('Login successful! Redirecting...', 'success');
                        setTimeout(() => {
                            window.location.href = data.redirect_url;
                        }, 1000);
                    } else if (data.requires_2fa) {
                        requiresTwoFA = true;
                        show2FASection();
                        showMessage(data.message, 'info');
                    } else {
                        showMessage(data.message, 'error');

                        // Update attempts counter if provided
                        if (data.attempts_remaining !== undefined) {
                            updateAttemptsCounter(data.attempts_remaining);
                        }

                        // If account is locked, disable form
                        if (data.message.includes('locked')) {
                            disableForm();
                        }
                    }
                })
                .catch(error => {
                    setLoadingState(false);
                    console.error('Login error:', error);
                    showMessage('Connection error. Please try again.', 'error');
                });
            });

            // 2FA code input formatting
            totpCodeInput.addEventListener('input', function(e) {
                // Only allow numbers
                this.value = this.value.replace(/[^0-9]/g, '');

                // Auto-submit when 6 digits are entered
                if (this.value.length === 6 && requiresTwoFA) {
                    loginForm.dispatchEvent(new Event('submit'));
                }
            });

            // Real-time validation
            document.querySelectorAll('input[required]').forEach(input => {
                input.addEventListener('blur', function() {
                    validateField(this);
                });

                input.addEventListener('input', function() {
                    if (this.classList.contains('is-invalid')) {
                        validateField(this);
                    }
                });
            });

            function validateForm() {
                let isValid = true;
                const requiredFields = document.querySelectorAll('input[required]');

                requiredFields.forEach(field => {
                    if (!validateField(field)) {
                        isValid = false;
                    }
                });

                // Additional 2FA validation
                if (requiresTwoFA) {
                    const totpCode = totpCodeInput.value.trim();
                    if (totpCode.length !== 6 || !/^\d{6}$/.test(totpCode)) {
                        showFieldError(totpCodeInput, 'Please enter a valid 6-digit code');
                        isValid = false;
                    }
                }

                return isValid;
            }

            function validateField(field) {
                const value = field.value.trim();
                let isValid = true;
                let errorMessage = '';

                if (field.hasAttribute('required') && !value) {
                    errorMessage = 'This field is required';
                    isValid = false;
                } else if (field.type === 'email' && value && !isValidEmail(value)) {
                    errorMessage = 'Please enter a valid email address';
                    isValid = false;
                } else if (field.name === 'password' && value && value.length < 6) {
                    errorMessage = 'Password must be at least 6 characters';
                    isValid = false;
                }

                if (isValid) {
                    field.classList.remove('is-invalid');
                    field.classList.add('is-valid');
                } else {
                    showFieldError(field, errorMessage);
                }

                return isValid;
            }

            function showFieldError(field, message) {
                field.classList.remove('is-valid');
                field.classList.add('is-invalid');
                const feedback = field.nextElementSibling.nextElementSibling;
                if (feedback && feedback.classList.contains('invalid-feedback')) {
                    feedback.textContent = message;
                }
            }

            function clearErrors() {
                document.querySelectorAll('.is-invalid, .is-valid').forEach(field => {
                    field.classList.remove('is-invalid', 'is-valid');
                });
                messageContainer.innerHTML = '';
            }

            function setLoadingState(loading) {
                loginButton.disabled = loading;
                loginButton.classList.toggle('loading', loading);
                buttonText.textContent = loading ? 'Signing In...' : 'Sign In';
            }

            function showMessage(message, type) {
                const alertClass = {
                    'success': 'alert-success',
                    'error': 'alert-danger',
                    'warning': 'alert-warning',
                    'info': 'alert-info'
                }[type] || 'alert-info';

                messageContainer.innerHTML = `
                    <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
                        ${message}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                `;
            }

            function show2FASection() {
                twoFaSection.classList.add('show');
                totpCodeInput.focus();
                buttonText.textContent = 'Verify & Sign In';
            }

            function updateAttemptsCounter(remaining) {
                const counter = document.querySelector('.attempts-remaining');
                if (counter) {
                    counter.textContent = `${remaining} attempts remaining`;
                    if (remaining <= 3) {
                        counter.classList.add('warning');
                    }
                }
            }

            function disableForm() {
                const inputs = loginForm.querySelectorAll('input, button');
                inputs.forEach(input => input.disabled = true);
            }

            function isValidEmail(email) {
                return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
            }
        });
    </script>
</body>
</html>
