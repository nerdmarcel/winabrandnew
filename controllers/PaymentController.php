<?php
declare(strict_types=1);

/**
 * File: controllers/PaymentController.php
 * Location: controllers/PaymentController.php
 *
 * WinABN Payment Controller
 *
 * Handles payment processing through Mollie and Stripe providers,
 * manages payment flow, currency conversion, and device continuity.
 *
 * @package WinABN\Controllers
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Controllers;

use WinABN\Core\{Controller, Database, Security, Session};
use WinABN\Models\{Payment, Participant, Game, Round, ExchangeRate};
use WinABN\Exceptions\{PaymentException, ValidationException};
use Exception;

class PaymentController extends Controller
{
    /**
     * Mollie API client
     *
     * @var \Mollie\Api\MollieApiClient|null
     */
    private $mollieClient = null;

    /**
     * Stripe client
     *
     * @var \Stripe\StripeClient|null
     */
    private $stripeClient = null;

    /**
     * Payment model
     *
     * @var Payment
     */
    private Payment $paymentModel;

    /**
     * Participant model
     *
     * @var Participant
     */
    private Participant $participantModel;

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
        $this->paymentModel = new Payment();
        $this->participantModel = new Participant();
        $this->initializePaymentProviders();
    }

    /**
     * Initialize payment providers
     *
     * @return void
     */
    private function initializePaymentProviders(): void
    {
        // Initialize Mollie
        if (env('MOLLIE_ENABLED', true) && env('MOLLIE_API_KEY')) {
            try {
                $this->mollieClient = new \Mollie\Api\MollieApiClient();
                $this->mollieClient->setApiKey(env('MOLLIE_API_KEY'));
            } catch (Exception $e) {
                $this->logError('Mollie initialization failed', ['error' => $e->getMessage()]);
            }
        }

        // Initialize Stripe as backup
        if (env('STRIPE_ENABLED', false) && env('STRIPE_API_KEY')) {
            try {
                $this->stripeClient = new \Stripe\StripeClient(env('STRIPE_API_KEY'));
            } catch (Exception $e) {
                $this->logError('Stripe initialization failed', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Create payment for participant
     *
     * @return void
     */
    public function createPayment(): void
    {
        try {
            // Validate CSRF token
            if (!Security::verifyCsrfToken($this->getInput('csrf_token'))) {
                throw new SecurityException('Invalid CSRF token');
            }

            // Get participant data from session
            $sessionId = Session::getId();
            $participant = $this->participantModel->findBySession($sessionId);

            if (!$participant) {
                throw new ValidationException('Invalid session - participant not found');
            }

            // Verify participant hasn't already paid
            if ($participant['payment_status'] === 'paid') {
                $this->jsonResponse([
                    'success' => false,
                    'error' => 'Payment already completed'
                ], 400);
                return;
            }

            // Verify device continuity
            $deviceFingerprint = $this->getInput('device_fingerprint');
            if ($participant['device_fingerprint'] !== $deviceFingerprint) {
                throw new SecurityException('Device mismatch - game must be completed on same device');
            }

            // Get game and round information
            $round = $this->participantModel->getRoundById($participant['round_id']);
            $game = $this->participantModel->getGameById($round['game_id']);

            // Calculate payment amount with discounts
            $paymentAmount = $this->calculatePaymentAmount($participant, $game);

            // Detect currency based on IP or user preference
            $currency = $this->detectCurrency();
            $convertedAmount = $this->convertCurrency($paymentAmount['final_amount'], 'GBP', $currency);

            // Create payment record
            $paymentData = [
                'participant_id' => $participant['id'],
                'amount' => $convertedAmount,
                'currency' => $currency,
                'original_amount' => $game['entry_fee'],
                'discount_applied' => $paymentAmount['discount_amount'],
                'discount_type' => $paymentAmount['discount_type'],
                'provider' => 'mollie',
                'status' => 'pending'
            ];

            $paymentId = $this->paymentModel->create($paymentData);

            // Create payment with primary provider (Mollie)
            $paymentUrl = null;
            $provider = 'mollie';

            if ($this->mollieClient) {
                try {
                    $paymentUrl = $this->createMolliePayment($paymentId, $convertedAmount, $currency, $participant, $game);
                } catch (Exception $e) {
                    $this->logError('Mollie payment creation failed', [
                        'participant_id' => $participant['id'],
                        'error' => $e->getMessage()
                    ]);

                    // Fallback to Stripe if available
                    if ($this->stripeClient) {
                        $provider = 'stripe';
                        $paymentUrl = $this->createStripePayment($paymentId, $convertedAmount, $currency, $participant, $game);
                    }
                }
            } elseif ($this->stripeClient) {
                $provider = 'stripe';
                $paymentUrl = $this->createStripePayment($paymentId, $convertedAmount, $currency, $participant, $game);
            }

            if (!$paymentUrl) {
                throw new PaymentException('All payment providers unavailable');
            }

            // Update payment record with provider details
            $this->paymentModel->update($paymentId, [
                'provider' => $provider,
                'payment_url' => $paymentUrl
            ]);

            // Update participant with payment reference
            $this->participantModel->update($participant['id'], [
                'payment_reference' => (string) $paymentId,
                'payment_currency' => $currency,
                'payment_amount' => $convertedAmount
            ]);

            $this->jsonResponse([
                'success' => true,
                'payment_url' => $paymentUrl,
                'payment_id' => $paymentId,
                'amount' => $convertedAmount,
                'currency' => $currency,
                'provider' => $provider
            ]);

        } catch (ValidationException $e) {
            $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (SecurityException $e) {
            $this->jsonResponse(['success' => false, 'error' => 'Security validation failed'], 403);
        } catch (PaymentException $e) {
            $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        } catch (Exception $e) {
            $this->logError('Payment creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->jsonResponse(['success' => false, 'error' => 'Payment processing failed'], 500);
        }
    }

    /**
     * Create Mollie payment
     *
     * @param int $paymentId Internal payment ID
     * @param float $amount Payment amount
     * @param string $currency Currency code
     * @param array $participant Participant data
     * @param array $game Game data
     * @return string Payment URL
     * @throws PaymentException
     */
    private function createMolliePayment(int $paymentId, float $amount, string $currency, array $participant, array $game): string
    {
        try {
            $payment = $this->mollieClient->payments->create([
                'amount' => [
                    'currency' => $currency,
                    'value' => number_format($amount, 2, '.', '')
                ],
                'description' => "WinABN: {$game['name']}",
                'redirectUrl' => url("pay?payment_id={$paymentId}&status=return"),
                'webhookUrl' => url('webhook/mollie'),
                'metadata' => [
                    'payment_id' => $paymentId,
                    'participant_id' => $participant['id'],
                    'round_id' => $participant['round_id'],
                    'game_id' => $game['id']
                ],
                'method' => null, // Let user choose payment method
                'locale' => 'en_GB'
            ]);

            // Update payment record with Mollie payment ID
            $this->paymentModel->update($paymentId, [
                'provider_payment_id' => $payment->id,
                'provider_status' => $payment->status
            ]);

            return $payment->getCheckoutUrl();

        } catch (Exception $e) {
            throw new PaymentException("Mollie payment creation failed: " . $e->getMessage());
        }
    }

    /**
     * Create Stripe payment
     *
     * @param int $paymentId Internal payment ID
     * @param float $amount Payment amount
     * @param string $currency Currency code
     * @param array $participant Participant data
     * @param array $game Game data
     * @return string Payment URL
     * @throws PaymentException
     */
    private function createStripePayment(int $paymentId, float $amount, string $currency, array $participant, array $game): string
    {
        try {
            $session = $this->stripeClient->checkout->sessions->create([
                'payment_method_types' => ['card', 'apple_pay', 'google_pay'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => strtolower($currency),
                        'product_data' => [
                            'name' => "WinABN: {$game['name']}",
                            'description' => "Entry fee for {$game['name']} competition"
                        ],
                        'unit_amount' => (int) ($amount * 100) // Stripe uses cents
                    ],
                    'quantity' => 1
                ]],
                'mode' => 'payment',
                'success_url' => url("pay?payment_id={$paymentId}&status=success"),
                'cancel_url' => url("pay?payment_id={$paymentId}&status=cancelled"),
                'metadata' => [
                    'payment_id' => $paymentId,
                    'participant_id' => $participant['id'],
                    'round_id' => $participant['round_id'],
                    'game_id' => $game['id']
                ]
            ]);

            // Update payment record with Stripe session ID
            $this->paymentModel->update($paymentId, [
                'provider_payment_id' => $session->id,
                'provider_status' => 'pending'
            ]);

            return $session->url;

        } catch (Exception $e) {
            throw new PaymentException("Stripe payment creation failed: " . $e->getMessage());
        }
    }

    /**
     * Handle payment status page
     *
     * @return void
     */
    public function paymentStatus(): void
    {
        $paymentId = (int) $this->getInput('payment_id');
        $status = $this->getInput('status', 'pending');

        if (!$paymentId) {
            $this->redirect('/');
            return;
        }

        $payment = $this->paymentModel->find($paymentId);
        if (!$payment) {
            $this->view->render('payment/failed', [
                'error' => 'Payment not found'
            ]);
            return;
        }

        $participant = $this->participantModel->find($payment['participant_id']);

        // Verify session matches
        if ($participant['session_id'] !== Session::getId()) {
            $this->view->render('payment/failed', [
                'error' => 'Invalid session'
            ]);
            return;
        }

        // Check real-time payment status
        $currentStatus = $this->checkPaymentStatus($payment);

        switch ($currentStatus) {
            case 'paid':
                $this->view->render('payment/success', [
                    'payment' => $payment,
                    'participant' => $participant,
                    'continue_url' => url("game?session={$participant['session_id']}&continue=true")
                ]);
                break;

            case 'pending':
                $this->view->render('payment/pending', [
                    'payment' => $payment,
                    'refresh_interval' => 3000 // 3 seconds
                ]);
                break;

            case 'failed':
            case 'cancelled':
                $retryUrl = url("payment/retry?payment_id={$paymentId}");
                $this->view->render('payment/failed', [
                    'payment' => $payment,
                    'retry_url' => $retryUrl,
                    'error' => 'Payment was not completed'
                ]);
                break;

            default:
                $this->view->render('payment/pending', [
                    'payment' => $payment,
                    'refresh_interval' => 3000
                ]);
        }
    }

    /**
     * Handle payment retry
     *
     * @return void
     */
    public function retryPayment(): void
    {
        $paymentId = (int) $this->getInput('payment_id');

        if (!$paymentId) {
            $this->redirect('/');
            return;
        }

        $payment = $this->paymentModel->find($paymentId);
        if (!$payment || $payment['status'] === 'paid') {
            $this->redirect('/');
            return;
        }

        $participant = $this->participantModel->find($payment['participant_id']);

        // Verify session matches
        if ($participant['session_id'] !== Session::getId()) {
            $this->redirect('/');
            return;
        }

        // Create new payment with same details
        $this->createPayment();
    }

    /**
     * Check payment status with provider
     *
     * @param array $payment Payment record
     * @return string Current payment status
     */
    private function checkPaymentStatus(array $payment): string
    {
        try {
            if ($payment['provider'] === 'mollie' && $this->mollieClient) {
                $molliePayment = $this->mollieClient->payments->get($payment['provider_payment_id']);

                // Update local status if changed
                if ($molliePayment->status !== $payment['provider_status']) {
                    $this->paymentModel->update($payment['id'], [
                        'provider_status' => $molliePayment->status
                    ]);
                }

                return $this->mapMollieStatus($molliePayment->status);

            } elseif ($payment['provider'] === 'stripe' && $this->stripeClient) {
                $session = $this->stripeClient->checkout->sessions->retrieve($payment['provider_payment_id']);

                // Update local status if changed
                if ($session->payment_status !== $payment['provider_status']) {
                    $this->paymentModel->update($payment['id'], [
                        'provider_status' => $session->payment_status
                    ]);
                }

                return $this->mapStripeStatus($session->payment_status);
            }
        } catch (Exception $e) {
            $this->logError('Payment status check failed', [
                'payment_id' => $payment['id'],
                'provider' => $payment['provider'],
                'error' => $e->getMessage()
            ]);
        }

        return $payment['status'] ?? 'pending';
    }

    /**
     * Map Mollie status to internal status
     *
     * @param string $mollieStatus Mollie payment status
     * @return string Internal status
     */
    private function mapMollieStatus(string $mollieStatus): string
    {
        $statusMap = [
            'paid' => 'paid',
            'pending' => 'pending',
            'open' => 'pending',
            'canceled' => 'cancelled',
            'expired' => 'failed',
            'failed' => 'failed'
        ];

        return $statusMap[$mollieStatus] ?? 'pending';
    }

    /**
     * Map Stripe status to internal status
     *
     * @param string $stripeStatus Stripe payment status
     * @return string Internal status
     */
    private function mapStripeStatus(string $stripeStatus): string
    {
        $statusMap = [
            'paid' => 'paid',
            'unpaid' => 'pending',
            'no_payment_required' => 'paid'
        ];

        return $statusMap[$stripeStatus] ?? 'pending';
    }

    /**
     * Calculate payment amount with discounts
     *
     * @param array $participant Participant data
     * @param array $game Game data
     * @return array Payment calculation details
     */
    private function calculatePaymentAmount(array $participant, array $game): array
    {
        $baseAmount = (float) $game['entry_fee'];
        $discountAmount = 0.0;
        $discountType = null;

        // Check for available discounts
        $discounts = $this->getAvailableDiscounts($participant['user_email']);

        if (!empty($discounts)) {
            // Replay discount takes priority
            $replayDiscount = array_filter($discounts, fn($d) => $d['action_type'] === 'replay');
            $referralDiscount = array_filter($discounts, fn($d) => $d['action_type'] === 'referral');

            if (!empty($replayDiscount)) {
                $discount = reset($replayDiscount);
                $discountAmount = ($baseAmount * $discount['discount_amount']) / 100;
                $discountType = 'replay';
            } elseif (!empty($referralDiscount)) {
                $discount = reset($referralDiscount);
                $discountAmount = ($baseAmount * $discount['discount_amount']) / 100;
                $discountType = 'referral';
            }
        }

        return [
            'base_amount' => $baseAmount,
            'discount_amount' => $discountAmount,
            'discount_type' => $discountType,
            'final_amount' => $baseAmount - $discountAmount
        ];
    }

    /**
     * Get available discounts for user
     *
     * @param string $email User email
     * @return array Available discounts
     */
    private function getAvailableDiscounts(string $email): array
    {
        $query = "
            SELECT * FROM user_actions
            WHERE email = ?
            AND is_active = 1
            AND used_at IS NULL
            AND (expires_at IS NULL OR expires_at > NOW())
            ORDER BY action_type = 'replay' DESC, created_at ASC
        ";

        return Database::fetchAll($query, [$email]);
    }

    /**
     * Detect user currency based on IP geolocation
     *
     * @return string Currency code
     */
    private function detectCurrency(): string
    {
        // Check if user has selected currency in session
        $sessionCurrency = Session::get('selected_currency');
        if ($sessionCurrency && in_array($sessionCurrency, ['GBP', 'EUR', 'USD', 'CAD', 'AUD'])) {
            return $sessionCurrency;
        }

        // Detect by IP geolocation
        $clientIp = $this->getClientIp();
        $country = $this->getCountryByIp($clientIp);

        $currencyMap = [
            'GB' => 'GBP',
            'UK' => 'GBP',
            'US' => 'USD',
            'DE' => 'EUR',
            'FR' => 'EUR',
            'ES' => 'EUR',
            'IT' => 'EUR',
            'NL' => 'EUR',
            'BE' => 'EUR',
            'AT' => 'EUR',
            'IE' => 'EUR',
            'CA' => 'CAD',
            'AU' => 'AUD'
        ];

        return $currencyMap[$country] ?? 'GBP'; // Default to GBP
    }

    /**
     * Convert currency amount
     *
     * @param float $amount Amount to convert
     * @param string $fromCurrency Source currency
     * @param string $toCurrency Target currency
     * @return float Converted amount
     */
    private function convertCurrency(float $amount, string $fromCurrency, string $toCurrency): float
    {
        if ($fromCurrency === $toCurrency) {
            return $amount;
        }

        $exchangeRate = new ExchangeRate();
        $rate = $exchangeRate->getRate($fromCurrency, $toCurrency);

        if (!$rate) {
            // Fallback to default rate or same currency
            $this->logError('Exchange rate not found', [
                'from' => $fromCurrency,
                'to' => $toCurrency
            ]);
            return $amount;
        }

        return round($amount * $rate, 2);
    }

    /**
     * Get country by IP address
     *
     * @param string $ip IP address
     * @return string Country code
     */
    private function getCountryByIp(string $ip): string
    {
        // Simple IP-to-country detection
        // In production, use a proper geolocation service

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            try {
                $apiKey = env('GEOIP_API_KEY');
                $apiUrl = env('GEOIP_API_URL');

                if ($apiKey && $apiUrl) {
                    $response = file_get_contents("{$apiUrl}{$ip}?access_key={$apiKey}");
                    $data = json_decode($response, true);

                    if ($data && isset($data['country_code'])) {
                        return $data['country_code'];
                    }
                }
            } catch (Exception $e) {
                $this->logError('Geolocation API failed', ['ip' => $ip, 'error' => $e->getMessage()]);
            }
        }

        return 'GB'; // Default to UK
    }

    /**
     * Get client IP address
     *
     * @return string Client IP
     */
    private function getClientIp(): string
    {
        return client_ip();
    }
}
