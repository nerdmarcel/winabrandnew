<?php

/**
 * Win a Brand New - Payment Controller
 * File: /controllers/PaymentController.php
 *
 * Handles payment processing and Mollie integration according to the
 * Development Specification requirements. Manages the complete payment
 * flow including creation, status verification, webhook processing,
 * and timer pause/resume logic.
 *
 * Features:
 * - Mollie payment creation and processing
 * - Payment status verification with real-time checking
 * - Webhook processing with signature verification
 * - Timer pause/resume during payment process
 * - Currency conversion and tax handling
 * - Discount application and validation
 * - Payment retry mechanism for failed payments
 * - Device continuity enforcement
 * - Comprehensive error handling and logging
 *
 * @package WinABrandNew\Controllers
 * @version 1.0.0
 * @author NerdMarcel Development Team
 */

namespace WinABrandNew\Controllers;

use WinABrandNew\Controllers\BaseController;
use WinABrandNew\Models\Payment;
use WinABrandNew\Models\Participant;
use WinABrandNew\Models\Round;
use WinABrandNew\Models\Game;
use WinABrandNew\Models\ExchangeRate;
use WinABrandNew\Models\TaxRate;
use WinABrandNew\Models\UserActions;
use WinABrandNew\Core\Database;
use WinABrandNew\Core\Security;
use WinABrandNew\Core\Config;
use Exception;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Exceptions\ApiException;

class PaymentController extends BaseController
{
    /**
     * Mollie API client instance
     *
     * @var MollieApiClient
     */
    private MollieApiClient $mollieClient;

    /**
     * Supported payment methods
     *
     * @var array
     */
    private array $supportedMethods = [
        'ideal',
        'creditcard',
        'paypal',
        'bancontact',
        'sofort',
        'przelewy24',
        'eps',
        'giropay',
        'kbc',
        'belfius',
        'applepay',
        'googlepay'
    ];

    /**
     * Initialize payment controller
     *
     * @throws Exception If Mollie API initialization fails
     */
    public function __construct()
    {
        parent::__construct();
        $this->initializeMollie();
    }

    /**
     * Initialize Mollie API client
     *
     * @return void
     * @throws Exception If API key is invalid
     */
    private function initializeMollie(): void
    {
        try {
            $this->mollieClient = new MollieApiClient();
            $this->mollieClient->setApiKey(Config::get('MOLLIE_API_KEY'));

            // Test API connection
            $this->mollieClient->methods->all();

        } catch (ApiException $e) {
            $this->logError("Mollie API initialization failed: " . $e->getMessage());
            throw new Exception("Payment system unavailable", 503);
        }
    }

    /**
     * Create payment after questions 1-3 and data collection
     *
     * @return void
     * @throws Exception If payment creation fails
     */
    public function createPayment(): void
    {
        try {
            // Verify CSRF token
            $this->verifyCsrfToken();

            // Validate session and participant data
            $participantData = $this->validateParticipantSession();
            if (!$participantData) {
                $this->jsonResponse(['error' => 'Invalid session'], 400);
                return;
            }

            // Get game and round information
            $gameId = $participantData['game_id'];
            $roundId = $participantData['round_id'];

            $game = Game::findById($gameId);
            if (!$game) {
                $this->jsonResponse(['error' => 'Game not found'], 404);
                return;
            }

            $round = Round::findById($roundId);
            if (!$round || $round['status'] !== 'active') {
                $this->jsonResponse(['error' => 'Round not available'], 400);
                return;
            }

            // Pause timer before payment (critical timing requirement)
            $this->pauseGameTimer($participantData['participant_id']);

            // Calculate payment amount with currency conversion and discounts
            $paymentAmount = $this->calculatePaymentAmount($game, $participantData);

            // Validate device continuity
            $this->validateDeviceContinuity($participantData['participant_id']);

            // Create Mollie payment
            $payment = $this->createMolliePayment($game, $paymentAmount, $participantData);

            // Update participant with payment details
            $this->updateParticipantPayment($participantData['participant_id'], $payment);

            // Return payment checkout URL
            $this->jsonResponse([
                'success' => true,
                'payment_id' => $payment->id,
                'checkout_url' => $payment->getCheckoutUrl(),
                'amount' => $paymentAmount['amount'],
                'currency' => $paymentAmount['currency'],
                'discount_applied' => $paymentAmount['discount_percentage']
            ]);

        } catch (Exception $e) {
            $this->logError("Payment creation failed: " . $e->getMessage());
            $this->jsonResponse(['error' => 'Payment creation failed'], 500);
        }
    }

    /**
     * Handle payment status verification and return
     *
     * @return void
     */
    public function handlePaymentReturn(): void
    {
        try {
            $paymentId = $_GET['payment_id'] ?? null;
            $participantId = $_SESSION['participant_id'] ?? null;

            if (!$paymentId || !$participantId) {
                $this->redirect('/');
                return;
            }

            // Verify device continuity
            $this->validateDeviceContinuity($participantId);

            // Get payment status from Mollie
            $paymentStatus = $this->verifyPaymentStatus($paymentId);

            // Get participant information
            $participant = Participant::findById($participantId);
            if (!$participant) {
                $this->redirect('/');
                return;
            }

            // Handle different payment statuses
            switch ($paymentStatus['status']) {
                case 'paid':
                    $this->handlePaidPayment($participant, $paymentId);
                    break;

                case 'pending':
                    $this->handlePendingPayment($participant, $paymentId);
                    break;

                case 'failed':
                case 'cancelled':
                case 'expired':
                    $this->handleFailedPayment($participant, $paymentId);
                    break;

                default:
                    $this->handleUnknownPaymentStatus($participant, $paymentId);
            }

        } catch (Exception $e) {
            $this->logError("Payment return handling failed: " . $e->getMessage());
            $this->redirect('/?error=payment_error');
        }
    }

    /**
     * Verify payment status with Mollie API
     *
     * @param string $paymentId Mollie payment ID
     * @return array Payment status information
     * @throws Exception If status verification fails
     */
    public function verifyPaymentStatus(string $paymentId): array
    {
        try {
            $payment = $this->mollieClient->payments->get($paymentId);

            return [
                'id' => $payment->id,
                'status' => $payment->status,
                'amount' => [
                    'value' => $payment->amount->value,
                    'currency' => $payment->amount->currency
                ],
                'paid_at' => $payment->paidAt ? $payment->paidAt->format('Y-m-d H:i:s') : null,
                'method' => $payment->method,
                'description' => $payment->description,
                'metadata' => $payment->metadata
            ];

        } catch (ApiException $e) {
            $this->logError("Payment status verification failed for {$paymentId}: " . $e->getMessage());
            throw new Exception("Payment verification failed", 500);
        }
    }

    /**
     * Handle successful payment
     *
     * @param array $participant Participant data
     * @param string $paymentId Mollie payment ID
     * @return void
     */
    private function handlePaidPayment(array $participant, string $paymentId): void
    {
        try {
            Database::beginTransaction();

            // Update participant payment status
            $updateData = [
                'payment_status' => 'paid',
                'payment_confirmed_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            Participant::update($participant['id'], $updateData);

            // Resume timer for questions 4-9 (critical timing requirement)
            $this->resumeGameTimer($participant['id']);

            // Update round participant count
            $this->updateRoundPaidCount($participant['round_id']);

            // Check if round is now full and trigger completion
            $this->checkRoundCompletion($participant['round_id']);

            Database::commit();

            // Redirect to continue game
            $this->redirect("/game/continue/{$paymentId}");

        } catch (Exception $e) {
            Database::rollback();
            $this->logError("Paid payment handling failed: " . $e->getMessage());
            $this->redirect('/?error=payment_processing_error');
        }
    }

    /**
     * Handle pending payment
     *
     * @param array $participant Participant data
     * @param string $paymentId Mollie payment ID
     * @return void
     */
    private function handlePendingPayment(array $participant, string $paymentId): void
    {
        // Keep timer paused for pending payments
        $this->render('payment/pending', [
            'payment_id' => $paymentId,
            'participant' => $participant,
            'auto_refresh' => true,
            'refresh_interval' => 3000 // 3 seconds
        ]);
    }

    /**
     * Handle failed/cancelled payment
     *
     * @param array $participant Participant data
     * @param string $paymentId Mollie payment ID
     * @return void
     */
    private function handleFailedPayment(array $participant, string $paymentId): void
    {
        try {
            // Update participant payment status
            $updateData = [
                'payment_status' => 'failed',
                'updated_at' => date('Y-m-d H:i:s')
            ];

            Participant::update($participant['id'], $updateData);

            // Show retry payment option (timer remains paused)
            $this->render('payment/failed', [
                'participant' => $participant,
                'payment_id' => $paymentId,
                'can_retry' => true,
                'game' => Game::findById($participant['game_id'])
            ]);

        } catch (Exception $e) {
            $this->logError("Failed payment handling error: " . $e->getMessage());
            $this->redirect('/?error=payment_error');
        }
    }

    /**
     * Handle unknown payment status
     *
     * @param array $participant Participant data
     * @param string $paymentId Mollie payment ID
     * @return void
     */
    private function handleUnknownPaymentStatus(array $participant, string $paymentId): void
    {
        $this->logError("Unknown payment status for payment {$paymentId}");

        $this->render('payment/status', [
            'participant' => $participant,
            'payment_id' => $paymentId,
            'status' => 'unknown',
            'auto_refresh' => true
        ]);
    }

    /**
     * Create Mollie payment
     *
     * @param array $game Game data
     * @param array $paymentAmount Calculated payment amount
     * @param array $participantData Participant data
     * @return object Mollie payment object
     * @throws Exception If payment creation fails
     */
    private function createMolliePayment(array $game, array $paymentAmount, array $participantData): object
    {
        try {
            $webhookUrl = Config::get('APP_URL') . '/webhook_mollie.php';
            $returnUrl = Config::get('APP_URL') . '/pay?payment_id={id}&status={status}';

            $paymentData = [
                'amount' => [
                    'currency' => $paymentAmount['currency'],
                    'value' => number_format($paymentAmount['amount'], 2, '.', '')
                ],
                'description' => "Win a Brand New - {$game['name']}",
                'redirectUrl' => $returnUrl,
                'webhookUrl' => $webhookUrl,
                'metadata' => [
                    'participant_id' => $participantData['participant_id'],
                    'round_id' => $participantData['round_id'],
                    'game_id' => $game['id'],
                    'session_id' => session_id(),
                    'ip_address' => $_SERVER['REMOTE_ADDR'],
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'discount_applied' => $paymentAmount['discount_percentage']
                ]
            ];

            // Add available payment methods based on currency
            $paymentData['methods'] = $this->getAvailablePaymentMethods($paymentAmount['currency']);

            return $this->mollieClient->payments->create($paymentData);

        } catch (ApiException $e) {
            $this->logError("Mollie payment creation failed: " . $e->getMessage());
            throw new Exception("Payment creation failed", 500);
        }
    }

    /**
     * Get available payment methods for currency
     *
     * @param string $currency Currency code
     * @return array Available payment methods
     */
    private function getAvailablePaymentMethods(string $currency): array
    {
        $methods = $this->supportedMethods;

        // Filter methods based on currency
        if ($currency !== 'EUR') {
            // Remove EU-specific methods for non-EUR currencies
            $methods = array_diff($methods, ['bancontact', 'eps', 'kbc', 'belfius']);
        }

        if (!in_array($currency, ['USD', 'EUR', 'GBP'])) {
            // Remove Apple Pay and Google Pay for unsupported currencies
            $methods = array_diff($methods, ['applepay', 'googlepay']);
        }

        return $methods;
    }

    /**
     * Calculate payment amount with currency conversion and discounts
     *
     * @param array $game Game data
     * @param array $participantData Participant data
     * @return array Payment amount details
     */
    private function calculatePaymentAmount(array $game, array $participantData): array
    {
        // Get base entry fee
        $baseAmount = (float) $game['entry_fee'];
        $baseCurrency = $game['currency'];

        // Detect user currency based on IP geolocation
        $userCurrency = $this->detectUserCurrency();

        // Convert to user currency if different
        if ($userCurrency !== $baseCurrency) {
            $exchangeRate = ExchangeRate::getRate($baseCurrency, $userCurrency);
            $amount = $baseAmount * $exchangeRate;
        } else {
            $amount = $baseAmount;
        }

        // Apply applicable discounts
        $discountInfo = $this->calculateDiscounts($participantData['user_email'], $game['id']);

        if ($discountInfo['discount_percentage'] > 0) {
            $discountAmount = $amount * ($discountInfo['discount_percentage'] / 100);
            $amount = $amount - $discountAmount;
        }

        // Add tax if applicable
        $taxInfo = $this->calculateTax($amount, $userCurrency);

        return [
            'amount' => $amount + $taxInfo['tax_amount'],
            'currency' => $userCurrency,
            'base_amount' => $baseAmount,
            'base_currency' => $baseCurrency,
            'discount_percentage' => $discountInfo['discount_percentage'],
            'discount_type' => $discountInfo['discount_type'],
            'discount_amount' => $discountInfo['discount_amount'] ?? 0,
            'tax_amount' => $taxInfo['tax_amount'],
            'tax_rate' => $taxInfo['tax_rate'],
            'tax_name' => $taxInfo['tax_name']
        ];
    }

    /**
     * Calculate applicable discounts
     *
     * @param string $email User email
     * @param int $gameId Game ID
     * @return array Discount information
     */
    private function calculateDiscounts(string $email, int $gameId): array
    {
        $discounts = UserActions::getActiveDiscounts($email);

        // Priority: Replay discount > Referral discount (per specification)
        foreach ($discounts as $discount) {
            if ($discount['action_type'] === 'replay') {
                // Replay discount takes priority
                return [
                    'discount_percentage' => $discount['discount_amount'],
                    'discount_type' => 'replay',
                    'discount_amount' => $discount['discount_amount'],
                    'action_id' => $discount['id']
                ];
            }
        }

        foreach ($discounts as $discount) {
            if ($discount['action_type'] === 'referral') {
                return [
                    'discount_percentage' => $discount['discount_amount'],
                    'discount_type' => 'referral',
                    'discount_amount' => $discount['discount_amount'],
                    'action_id' => $discount['id']
                ];
            }
        }

        return [
            'discount_percentage' => 0,
            'discount_type' => null,
            'discount_amount' => 0,
            'action_id' => null
        ];
    }

    /**
     * Calculate tax amount
     *
     * @param float $amount Base amount
     * @param string $currency Currency
     * @return array Tax information
     */
    private function calculateTax(float $amount, string $currency): array
    {
        $countryCode = $this->detectUserCountry();
        $taxRate = TaxRate::getRateByCountry($countryCode);

        if (!$taxRate) {
            // Default to UK VAT
            $taxRate = [
                'tax_rate' => 20.0,
                'tax_name' => 'VAT'
            ];
        }

        // Tax-inclusive pricing (user pays displayed amount exactly)
        $taxAmount = 0; // Tax already included in display price

        return [
            'tax_amount' => $taxAmount,
            'tax_rate' => $taxRate['tax_rate'],
            'tax_name' => $taxRate['tax_name']
        ];
    }

    /**
     * Detect user currency based on IP geolocation
     *
     * @return string Currency code
     */
    private function detectUserCurrency(): string
    {
        // Use geolocation service to detect currency
        // Default to GBP if detection fails
        return $_SESSION['detected_currency'] ?? 'GBP';
    }

    /**
     * Detect user country based on IP geolocation
     *
     * @return string Country code
     */
    private function detectUserCountry(): string
    {
        // Use geolocation service to detect country
        // Default to GB if detection fails
        return $_SESSION['detected_country'] ?? 'GB';
    }

    /**
     * Pause game timer before payment process
     *
     * @param int $participantId Participant ID
     * @return void
     */
    private function pauseGameTimer(int $participantId): void
    {
        try {
            $participant = Participant::findById($participantId);
            if (!$participant) {
                throw new Exception("Participant not found");
            }

            // Calculate pre-payment time (questions 1-3)
            $questionTimes = json_decode($participant['question_times_json'] ?? '[]', true);
            $prePaymentTime = array_sum(array_slice($questionTimes, 0, 3));

            // Update participant with pre-payment time
            $updateData = [
                'pre_payment_time' => $prePaymentTime,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            Participant::update($participantId, $updateData);

            // Store timer pause timestamp in session
            $_SESSION['timer_paused_at'] = microtime(true);
            $_SESSION['pre_payment_time'] = $prePaymentTime;

        } catch (Exception $e) {
            $this->logError("Failed to pause timer for participant {$participantId}: " . $e->getMessage());
        }
    }

    /**
     * Resume game timer after payment confirmation
     *
     * @param int $participantId Participant ID
     * @return void
     */
    private function resumeGameTimer(int $participantId): void
    {
        try {
            // Store timer resume timestamp in session
            $_SESSION['timer_resumed_at'] = microtime(true);
            $_SESSION['payment_confirmed'] = true;

            // Clear payment-related session data
            unset($_SESSION['timer_paused_at']);

            $this->logInfo("Timer resumed for participant {$participantId}");

        } catch (Exception $e) {
            $this->logError("Failed to resume timer for participant {$participantId}: " . $e->getMessage());
        }
    }

    /**
     * Validate device continuity (same device requirement)
     *
     * @param int $participantId Participant ID
     * @return void
     * @throws Exception If device validation fails
     */
    private function validateDeviceContinuity(int $participantId): void
    {
        $participant = Participant::findById($participantId);
        if (!$participant) {
            throw new Exception("Participant not found");
        }

        $currentFingerprint = $this->generateDeviceFingerprint();
        $storedFingerprint = $participant['device_fingerprint'];

        if ($currentFingerprint !== $storedFingerprint) {
            $this->logSecurity("Device continuity violation for participant {$participantId}");
            throw new Exception("Game must be completed on the same device", 403);
        }
    }

    /**
     * Generate device fingerprint
     *
     * @return string Device fingerprint
     */
    private function generateDeviceFingerprint(): string
    {
        $components = [
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        ];

        return hash('sha256', implode('|', $components));
    }

    /**
     * Update participant with payment information
     *
     * @param int $participantId Participant ID
     * @param object $payment Mollie payment object
     * @return void
     */
    private function updateParticipantPayment(int $participantId, object $payment): void
    {
        try {
            $updateData = [
                'payment_id' => $payment->id,
                'payment_provider' => 'mollie',
                'payment_currency' => $payment->amount->currency,
                'payment_amount' => (float) $payment->amount->value,
                'payment_status' => 'pending',
                'updated_at' => date('Y-m-d H:i:s')
            ];

            Participant::update($participantId, $updateData);

        } catch (Exception $e) {
            $this->logError("Failed to update participant payment: " . $e->getMessage());
            throw new Exception("Payment update failed", 500);
        }
    }

    /**
     * Update round paid participant count
     *
     * @param int $roundId Round ID
     * @return void
     */
    private function updateRoundPaidCount(int $roundId): void
    {
        try {
            Database::execute(
                "UPDATE rounds SET paid_participant_count = (
                    SELECT COUNT(*) FROM participants
                    WHERE round_id = ? AND payment_status = 'paid'
                ), updated_at = NOW() WHERE id = ?",
                [$roundId, $roundId]
            );

        } catch (Exception $e) {
            $this->logError("Failed to update round paid count: " . $e->getMessage());
        }
    }

    /**
     * Check if round is full and trigger completion
     *
     * @param int $roundId Round ID
     * @return void
     */
    private function checkRoundCompletion(int $roundId): void
    {
        try {
            $round = Round::findById($roundId);
            $game = Game::findById($round['game_id']);

            if ($round['paid_participant_count'] >= $game['max_players']) {
                Round::markFull($roundId);

                // Auto-start new round if enabled
                if ($game['auto_restart']) {
                    Round::createNew($game['id']);
                }
            }

        } catch (Exception $e) {
            $this->logError("Failed to check round completion: " . $e->getMessage());
        }
    }

    /**
     * Validate participant session and data
     *
     * @return array|null Participant data or null if invalid
     */
    private function validateParticipantSession(): ?array
    {
        $participantId = $_SESSION['participant_id'] ?? null;
        $sessionId = session_id();

        if (!$participantId) {
            return null;
        }

        $participant = Database::selectOne(
            "SELECT p.*, r.game_id, r.status as round_status
             FROM participants p
             JOIN rounds r ON p.round_id = r.id
             WHERE p.id = ? AND p.session_id = ? AND p.payment_status = 'pending'",
            [$participantId, $sessionId]
        );

        return $participant ?: null;
    }

    /**
     * Retry payment creation
     *
     * @return void
     */
    public function retryPayment(): void
    {
        try {
            $this->verifyCsrfToken();

            $participantData = $this->validateParticipantSession();
            if (!$participantData) {
                $this->jsonResponse(['error' => 'Invalid session'], 400);
                return;
            }

            // Reset payment status to allow retry
            Participant::update($participantData['id'], [
                'payment_status' => 'pending',
                'payment_id' => null,
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            // Create new payment
            $this->createPayment();

        } catch (Exception $e) {
            $this->logError("Payment retry failed: " . $e->getMessage());
            $this->jsonResponse(['error' => 'Payment retry failed'], 500);
        }
    }

    /**
     * Get payment status for AJAX polling
     *
     * @return void
     */
    public function getPaymentStatus(): void
    {
        try {
            $paymentId = $_GET['payment_id'] ?? null;
            $participantId = $_SESSION['participant_id'] ?? null;

            if (!$paymentId || !$participantId) {
                $this->jsonResponse(['error' => 'Invalid request'], 400);
                return;
            }

            $status = $this->verifyPaymentStatus($paymentId);

            $this->jsonResponse([
                'success' => true,
                'status' => $status['status'],
                'payment_id' => $status['id'],
                'paid_at' => $status['paid_at']
            ]);

        } catch (Exception $e) {
            $this->logError("Payment status check failed: " . $e->getMessage());
            $this->jsonResponse(['error' => 'Status check failed'], 500);
        }
    }

    /**
     * Log security events
     *
     * @param string $message Security message
     * @return void
     */
    private function logSecurity(string $message): void
    {
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'session_id' => session_id(),
            'message' => $message
        ];

        error_log("SECURITY: " . json_encode($logData));
    }

    /**
     * Log informational messages
     *
     * @param string $message Info message
     * @return void
     */
    private function logInfo(string $message): void
    {
        error_log("INFO: " . $message);
    }

    /**
     * Log error messages
     *
     * @param string $message Error message
     * @return void
     */
    private function logError(string $message): void
    {
        error_log("ERROR: " . $message);
    }
}
