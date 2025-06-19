<?php

/**
 * Win a Brand New - Payment Model (Mollie Integration)
 * File: /models/Payment.php
 *
 * Handles all payment operations through Mollie API integration
 * according to the Development Specification requirements.
 *
 * Features:
 * - Mollie API integration for payment processing
 * - Support for Apple Pay, Google Pay, PayPal, Credit Card
 * - Webhook handling and signature verification
 * - Payment status management and tracking
 * - Currency conversion and tax handling
 * - Stripe fallback integration capability
 * - Fee absorption (users pay exact displayed amount)
 * - Payment retry mechanism
 * - Audit logging for all payment transactions
 *
 * @package WinABrandNew\Models
 * @version 1.0.0
 * @author NerdMarcel Development Team
 */

namespace WinABrandNew\Models;

use WinABrandNew\Core\Database;
use WinABrandNew\Core\Config;
use WinABrandNew\Models\ExchangeRate;
use WinABrandNew\Models\TaxRate;
use WinABrandNew\Models\Analytics;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Payment as MolliePayment;
use Exception;
use DateTime;

class Payment
{
    /**
     * Payment statuses according to Mollie API
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REFUNDED = 'refunded';

    /**
     * Payment providers
     */
    public const PROVIDER_MOLLIE = 'mollie';
    public const PROVIDER_STRIPE = 'stripe';

    /**
     * Supported payment methods
     */
    public const METHOD_IDEAL = 'ideal';
    public const METHOD_CREDITCARD = 'creditcard';
    public const METHOD_PAYPAL = 'paypal';
    public const METHOD_APPLEPAY = 'applepay';
    public const METHOD_GOOGLEPAY = 'googlepay';
    public const METHOD_BANCONTACT = 'bancontact';

    /**
     * Payment currencies
     */
    public const CURRENCY_EUR = 'EUR';
    public const CURRENCY_GBP = 'GBP';
    public const CURRENCY_USD = 'USD';
    public const CURRENCY_CAD = 'CAD';
    public const CURRENCY_AUD = 'AUD';

    /**
     * Mollie API client instance
     *
     * @var MollieApiClient|null
     */
    private static ?MollieApiClient $mollieClient = null;

    /**
     * Payment audit log entries
     *
     * @var array
     */
    private static array $auditLog = [];

    /**
     * Initialize Mollie API client
     *
     * @return MollieApiClient
     * @throws Exception If API client initialization fails
     */
    private static function getMollieClient(): MollieApiClient
    {
        if (self::$mollieClient === null) {
            try {
                self::$mollieClient = new MollieApiClient();
                self::$mollieClient->setApiKey(Config::get('MOLLIE_API_KEY'));

                // Set additional options
                self::$mollieClient->setVersionStrings([
                    'WinABrandNew/1.0.0',
                    'PHP/' . phpversion()
                ]);

                self::logAudit('mollie_client_initialized', null, [
                    'api_key_length' => strlen(Config::get('MOLLIE_API_KEY')),
                    'user_agent' => 'WinABrandNew/1.0.0'
                ]);

            } catch (Exception $e) {
                self::logError("Failed to initialize Mollie client: " . $e->getMessage());
                throw new Exception("Payment system initialization failed", 500);
            }
        }

        return self::$mollieClient;
    }

    /**
     * Create a new payment
     *
     * @param int $participantId Participant ID
     * @param float $amount Payment amount
     * @param string $currency Payment currency
     * @param string $description Payment description
     * @param array $metadata Additional payment metadata
     * @return array Payment creation result
     * @throws Exception If payment creation fails
     */
    public static function createPayment(
        int $participantId,
        float $amount,
        string $currency,
        string $description,
        array $metadata = []
    ): array {
        try {
            // Get participant data
            $participant = Database::selectOne(
                "SELECT * FROM participants WHERE id = ?",
                [$participantId]
            );

            if (!$participant) {
                throw new Exception("Participant not found", 404);
            }

            // Calculate final amount including tax
            $taxRate = self::getTaxRateForCountry($metadata['country_code'] ?? 'GB');
            $finalAmount = self::calculateFinalAmount($amount, $currency, $taxRate);

            // Create Mollie payment
            $mollieClient = self::getMollieClient();
            $molliePayment = $mollieClient->payments->create([
                'amount' => [
                    'currency' => $currency,
                    'value' => number_format($finalAmount, 2, '.', '')
                ],
                'description' => $description,
                'redirectUrl' => Config::get('APP_URL') . '/pay?payment_id={id}&status={status}',
                'webhookUrl' => Config::get('APP_URL') . '/webhook_mollie.php',
                'metadata' => array_merge($metadata, [
                    'participant_id' => $participantId,
                    'original_amount' => $amount,
                    'tax_rate' => $taxRate,
                    'system' => 'winabrandnew'
                ]),
                'method' => null, // Let user choose payment method
                'locale' => self::getLocaleForCountry($metadata['country_code'] ?? 'GB')
            ]);

            // Store payment in database
            $paymentId = Database::insert(
                "INSERT INTO participants (id, payment_id, payment_provider, payment_currency, payment_amount, payment_fee, payment_status, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                 payment_id = VALUES(payment_id),
                 payment_provider = VALUES(payment_provider),
                 payment_currency = VALUES(payment_currency),
                 payment_amount = VALUES(payment_amount),
                 payment_fee = VALUES(payment_fee),
                 payment_status = VALUES(payment_status),
                 updated_at = VALUES(updated_at)",
                [
                    $participantId,
                    $molliePayment->id,
                    self::PROVIDER_MOLLIE,
                    $currency,
                    $finalAmount,
                    0.00, // Fee is absorbed by platform
                    self::STATUS_PENDING
                ]
            );

            // Log analytics event
            Analytics::logEvent('payment_attempt', $participantId, [
                'payment_id' => $molliePayment->id,
                'amount' => $finalAmount,
                'currency' => $currency,
                'provider' => self::PROVIDER_MOLLIE
            ]);

            // Audit log
            self::logAudit('payment_created', $participantId, [
                'mollie_payment_id' => $molliePayment->id,
                'amount' => $finalAmount,
                'currency' => $currency,
                'tax_rate' => $taxRate,
                'description' => $description
            ]);

            return [
                'success' => true,
                'payment_id' => $molliePayment->id,
                'checkout_url' => $molliePayment->getCheckoutUrl(),
                'amount' => $finalAmount,
                'currency' => $currency,
                'status' => $molliePayment->status,
                'expires_at' => $molliePayment->expiresAt ? $molliePayment->expiresAt->format('Y-m-d H:i:s') : null
            ];

        } catch (Exception $e) {
            self::logError("Payment creation failed for participant {$participantId}: " . $e->getMessage());

            // Log failed analytics event
            Analytics::logEvent('payment_failure', $participantId, [
                'error' => $e->getMessage(),
                'amount' => $amount ?? 0,
                'currency' => $currency ?? 'GBP'
            ]);

            // Try Stripe fallback if enabled and Mollie is down
            if (Config::get('STRIPE_ENABLED') && str_contains($e->getMessage(), 'API')) {
                return self::createStripePayment($participantId, $amount, $currency, $description, $metadata);
            }

            throw new Exception("Payment creation failed: " . $e->getMessage(), 500);
        }
    }

    /**
     * Check payment status via Mollie API
     *
     * @param string $paymentId Mollie payment ID
     * @return array Payment status information
     * @throws Exception If status check fails
     */
    public static function checkPaymentStatus(string $paymentId): array
    {
        try {
            $mollieClient = self::getMollieClient();
            $molliePayment = $mollieClient->payments->get($paymentId);

            // Update database with latest status
            $updateResult = Database::update(
                "UPDATE participants SET
                 payment_status = ?,
                 payment_confirmed_at = ?,
                 updated_at = NOW()
                 WHERE payment_id = ?",
                [
                    $molliePayment->status,
                    $molliePayment->isPaid() ? date('Y-m-d H:i:s') : null,
                    $paymentId
                ]
            );

            // Get participant ID for logging
            $participant = Database::selectOne(
                "SELECT id FROM participants WHERE payment_id = ?",
                [$paymentId]
            );

            if ($participant && $molliePayment->isPaid()) {
                // Log successful payment
                Analytics::logEvent('payment_success', $participant['id'], [
                    'payment_id' => $paymentId,
                    'amount' => $molliePayment->amount->value,
                    'currency' => $molliePayment->amount->currency,
                    'method' => $molliePayment->method
                ]);

                self::logAudit('payment_confirmed', $participant['id'], [
                    'mollie_payment_id' => $paymentId,
                    'amount' => $molliePayment->amount->value,
                    'currency' => $molliePayment->amount->currency,
                    'method' => $molliePayment->method,
                    'paid_at' => $molliePayment->paidAt->format('Y-m-d H:i:s')
                ]);

                // Check if this payment completes the round
                self::checkRoundCompletion($participant['id']);
            }

            return [
                'payment_id' => $paymentId,
                'status' => $molliePayment->status,
                'is_paid' => $molliePayment->isPaid(),
                'is_pending' => $molliePayment->isPending(),
                'is_failed' => $molliePayment->isFailed(),
                'is_cancelled' => $molliePayment->isCanceled(),
                'amount' => $molliePayment->amount->value,
                'currency' => $molliePayment->amount->currency,
                'method' => $molliePayment->method,
                'paid_at' => $molliePayment->paidAt ? $molliePayment->paidAt->format('Y-m-d H:i:s') : null,
                'expires_at' => $molliePayment->expiresAt ? $molliePayment->expiresAt->format('Y-m-d H:i:s') : null
            ];

        } catch (Exception $e) {
            self::logError("Payment status check failed for payment {$paymentId}: " . $e->getMessage());
            throw new Exception("Payment status check failed", 500);
        }
    }

    /**
     * Process webhook notification from Mollie
     *
     * @param string $paymentId Payment ID from webhook
     * @param array $headers HTTP headers for signature verification
     * @param string $body Raw request body
     * @return bool Processing success
     * @throws Exception If webhook processing fails
     */
    public static function processWebhook(string $paymentId, array $headers, string $body): bool
    {
        try {
            // Verify webhook signature
            if (!self::verifyWebhookSignature($headers, $body)) {
                self::logError("Invalid webhook signature for payment {$paymentId}");
                return false;
            }

            // Get updated payment status
            $statusData = self::checkPaymentStatus($paymentId);

            // Get participant data
            $participant = Database::selectOne(
                "SELECT p.*, r.game_id, r.max_players
                 FROM participants p
                 JOIN rounds r ON p.round_id = r.id
                 WHERE p.payment_id = ?",
                [$paymentId]
            );

            if (!$participant) {
                self::logError("No participant found for payment {$paymentId}");
                return false;
            }

            self::logAudit('webhook_processed', $participant['id'], [
                'payment_id' => $paymentId,
                'old_status' => $participant['payment_status'],
                'new_status' => $statusData['status'],
                'webhook_time' => date('Y-m-d H:i:s')
            ]);

            // Handle specific status changes
            if ($statusData['is_paid'] && $participant['payment_status'] !== self::STATUS_PAID) {
                return self::handlePaymentSuccess($participant, $statusData);
            } elseif ($statusData['is_failed'] || $statusData['is_cancelled']) {
                return self::handlePaymentFailure($participant, $statusData);
            }

            return true;

        } catch (Exception $e) {
            self::logError("Webhook processing failed for payment {$paymentId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Handle successful payment
     *
     * @param array $participant Participant data
     * @param array $statusData Payment status data
     * @return bool Processing success
     */
    private static function handlePaymentSuccess(array $participant, array $statusData): bool
    {
        try {
            Database::beginTransaction();

            // Update participant payment status
            Database::update(
                "UPDATE participants SET
                 payment_status = ?,
                 payment_confirmed_at = ?,
                 updated_at = NOW()
                 WHERE id = ?",
                [
                    self::STATUS_PAID,
                    $statusData['paid_at'],
                    $participant['id']
                ]
            );

            // Increment paid participant count in round
            Database::update(
                "UPDATE rounds SET
                 paid_participant_count = paid_participant_count + 1,
                 updated_at = NOW()
                 WHERE id = ?",
                [$participant['round_id']]
            );

            // Check if round is now full
            $roundData = Database::selectOne(
                "SELECT * FROM rounds r
                 JOIN games g ON r.game_id = g.id
                 WHERE r.id = ?",
                [$participant['round_id']]
            );

            if ($roundData && $roundData['paid_participant_count'] >= $roundData['max_players']) {
                // Round is full - trigger completion
                self::triggerRoundCompletion($roundData);
            }

            // Create replay discount for this participant
            self::createReplayDiscount($participant['user_email'], $participant['round_id']);

            // Send confirmation email
            self::queueConfirmationEmail($participant, $statusData);

            Database::commit();

            self::logAudit('payment_success_handled', $participant['id'], [
                'round_id' => $participant['round_id'],
                'paid_count' => $roundData['paid_participant_count'] + 1,
                'max_players' => $roundData['max_players'],
                'round_full' => ($roundData['paid_participant_count'] + 1) >= $roundData['max_players']
            ]);

            return true;

        } catch (Exception $e) {
            Database::rollback();
            self::logError("Failed to handle payment success for participant {$participant['id']}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Handle failed or cancelled payment
     *
     * @param array $participant Participant data
     * @param array $statusData Payment status data
     * @return bool Processing success
     */
    private static function handlePaymentFailure(array $participant, array $statusData): bool
    {
        try {
            // Update participant payment status
            Database::update(
                "UPDATE participants SET
                 payment_status = ?,
                 updated_at = NOW()
                 WHERE id = ?",
                [
                    $statusData['status'],
                    $participant['id']
                ]
            );

            // Log analytics event
            Analytics::logEvent('payment_failure', $participant['id'], [
                'payment_id' => $statusData['payment_id'],
                'status' => $statusData['status'],
                'reason' => $statusData['status'] === self::STATUS_CANCELLED ? 'user_cancelled' : 'payment_failed'
            ]);

            self::logAudit('payment_failure_handled', $participant['id'], [
                'payment_id' => $statusData['payment_id'],
                'status' => $statusData['status'],
                'failure_time' => date('Y-m-d H:i:s')
            ]);

            return true;

        } catch (Exception $e) {
            self::logError("Failed to handle payment failure for participant {$participant['id']}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Verify Mollie webhook signature
     *
     * @param array $headers HTTP headers
     * @param string $body Request body
     * @return bool Signature validity
     */
    private static function verifyWebhookSignature(array $headers, string $body): bool
    {
        $webhookSecret = Config::get('MOLLIE_WEBHOOK_SECRET');
        if (!$webhookSecret) {
            // If no secret is configured, skip verification (not recommended for production)
            self::logError("No webhook secret configured - skipping signature verification");
            return true;
        }

        $signature = $headers['HTTP_X_MOLLIE_SIGNATURE'] ?? $headers['X-Mollie-Signature'] ?? '';
        if (!$signature) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $body, $webhookSecret);
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Calculate final amount including tax
     *
     * @param float $baseAmount Base amount before tax
     * @param string $currency Payment currency
     * @param float $taxRate Tax rate (percentage)
     * @return float Final amount including tax
     */
    private static function calculateFinalAmount(float $baseAmount, string $currency, float $taxRate): float
    {
        // Convert to target currency if needed
        if ($currency !== 'GBP') {
            $baseAmount = ExchangeRate::convert($baseAmount, 'GBP', $currency);
        }

        // Add tax (tax-inclusive pricing)
        $taxAmount = $baseAmount * ($taxRate / 100);
        $finalAmount = $baseAmount + $taxAmount;

        return round($finalAmount, 2);
    }

    /**
     * Get tax rate for country
     *
     * @param string $countryCode ISO country code
     * @return float Tax rate percentage
     */
    private static function getTaxRateForCountry(string $countryCode): float
    {
        $taxRate = Database::selectOne(
            "SELECT tax_rate FROM tax_rates WHERE country_code = ? AND active = 1",
            [$countryCode]
        );

        return $taxRate ? (float)$taxRate['tax_rate'] : (float)Config::get('DEFAULT_VAT_RATE', 20);
    }

    /**
     * Get locale for country
     *
     * @param string $countryCode ISO country code
     * @return string Mollie locale
     */
    private static function getLocaleForCountry(string $countryCode): string
    {
        $locales = [
            'GB' => 'en_GB',
            'US' => 'en_US',
            'CA' => 'en_US',
            'AU' => 'en_US',
            'NL' => 'nl_NL',
            'DE' => 'de_DE',
            'FR' => 'fr_FR',
            'BE' => 'nl_BE',
            'ES' => 'es_ES',
            'IT' => 'it_IT'
        ];

        return $locales[$countryCode] ?? 'en_GB';
    }

    /**
     * Check if round completion should be triggered
     *
     * @param int $participantId Participant ID who just paid
     * @return void
     */
    private static function checkRoundCompletion(int $participantId): void
    {
        $roundData = Database::selectOne(
            "SELECT r.*, g.max_players, g.auto_restart
             FROM participants p
             JOIN rounds r ON p.round_id = r.id
             JOIN games g ON r.game_id = g.id
             WHERE p.id = ?",
            [$participantId]
        );

        if ($roundData && $roundData['paid_participant_count'] >= $roundData['max_players']) {
            self::triggerRoundCompletion($roundData);
        }
    }

    /**
     * Trigger round completion when max players reached
     *
     * @param array $roundData Round information
     * @return void
     */
    private static function triggerRoundCompletion(array $roundData): void
    {
        try {
            Database::beginTransaction();

            // Update round status to full
            Database::update(
                "UPDATE rounds SET
                 status = 'full',
                 updated_at = NOW()
                 WHERE id = ?",
                [$roundData['id']]
            );

            // Auto-restart new round if enabled
            if ($roundData['auto_restart']) {
                Database::insert(
                    "INSERT INTO rounds (game_id, round_number, status, started_at, created_at, updated_at)
                     VALUES (?, ?, 'active', NOW(), NOW(), NOW())",
                    [
                        $roundData['game_id'],
                        $roundData['round_number'] + 1
                    ]
                );
            }

            // Queue winner notification emails
            self::queueWinnerNotificationEmails($roundData['id']);

            Database::commit();

            self::logAudit('round_completed', null, [
                'round_id' => $roundData['id'],
                'game_id' => $roundData['game_id'],
                'participants' => $roundData['paid_participant_count'],
                'auto_restart' => $roundData['auto_restart']
            ]);

        } catch (Exception $e) {
            Database::rollback();
            self::logError("Failed to trigger round completion for round {$roundData['id']}: " . $e->getMessage());
        }
    }

    /**
     * Create replay discount for participant
     *
     * @param string $email Participant email
     * @param int $roundId Round ID where discount was earned
     * @return void
     */
    private static function createReplayDiscount(string $email, int $roundId): void
    {
        try {
            Database::insert(
                "INSERT INTO user_actions (email, action_type, discount_amount, round_id, expires_at, created_at, updated_at)
                 VALUES (?, 'replay', 10.00, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), NOW(), NOW())",
                [$email, $roundId]
            );
        } catch (Exception $e) {
            self::logError("Failed to create replay discount for {$email}: " . $e->getMessage());
        }
    }

    /**
     * Queue confirmation email
     *
     * @param array $participant Participant data
     * @param array $statusData Payment status data
     * @return void
     */
    private static function queueConfirmationEmail(array $participant, array $statusData): void
    {
        try {
            Database::insert(
                "INSERT INTO email_queue (to_email, to_name, subject, template_name, template_vars, priority, created_at)
                 VALUES (?, ?, ?, ?, ?, 1, NOW())",
                [
                    $participant['user_email'],
                    $participant['first_name'] . ' ' . $participant['last_name'],
                    'Payment Confirmed - Continue Your Game',
                    'payment_confirmation',
                    json_encode([
                        'first_name' => $participant['first_name'],
                        'payment_amount' => $statusData['amount'],
                        'payment_currency' => $statusData['currency'],
                        'continue_url' => Config::get('APP_URL') . '/game/continue/' . $statusData['payment_id']
                    ])
                ]
            );
        } catch (Exception $e) {
            self::logError("Failed to queue confirmation email for participant {$participant['id']}: " . $e->getMessage());
        }
    }

    /**
     * Queue winner notification emails
     *
     * @param int $roundId Round ID
     * @return void
     */
    private static function queueWinnerNotificationEmails(int $roundId): void
    {
        try {
            // This will be handled by the WinnerController when a winner is selected
            // For now, just log that the round is ready for winner selection
            self::logAudit('round_ready_for_winner_selection', null, [
                'round_id' => $roundId,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            self::logError("Failed to prepare winner notifications for round {$roundId}: " . $e->getMessage());
        }
    }

    /**
     * Create Stripe payment (fallback provider)
     *
     * @param int $participantId Participant ID
     * @param float $amount Payment amount
     * @param string $currency Payment currency
     * @param string $description Payment description
     * @param array $metadata Additional payment metadata
     * @return array Payment creation result
     * @throws Exception If Stripe payment creation fails
     */
    private static function createStripePayment(
        int $participantId,
        float $amount,
        string $currency,
        string $description,
        array $metadata = []
    ): array {
        // TODO: Implement Stripe payment creation as fallback
        // This will be implemented in the StripePayment model
        throw new Exception("Stripe fallback not yet implemented", 501);
    }

    /**
     * Get payment statistics
     *
     * @param array $filters Optional filters
     * @return array Payment statistics
     */
    public static function getPaymentStatistics(array $filters = []): array
    {
        try {
            $whereClause = "WHERE p.payment_status IS NOT NULL";
            $params = [];

            if (!empty($filters['date_from'])) {
                $whereClause .= " AND p.payment_confirmed_at >= ?";
                $params[] = $filters['date_from'];
            }

            if (!empty($filters['date_to'])) {
                $whereClause .= " AND p.payment_confirmed_at <= ?";
                $params[] = $filters['date_to'];
            }

            if (!empty($filters['currency'])) {
                $whereClause .= " AND p.payment_currency = ?";
                $params[] = $filters['currency'];
            }

            $stats = Database::selectOne(
                "SELECT
                 COUNT(*) as total_payments,
                 COUNT(CASE WHEN p.payment_status = 'paid' THEN 1 END) as successful_payments,
                 COUNT(CASE WHEN p.payment_status = 'failed' THEN 1 END) as failed_payments,
                 COUNT(CASE WHEN p.payment_status = 'cancelled' THEN 1 END) as cancelled_payments,
                 SUM(CASE WHEN p.payment_status = 'paid' THEN p.payment_amount ELSE 0 END) as total_revenue,
                 AVG(CASE WHEN p.payment_status = 'paid' THEN p.payment_amount ELSE NULL END) as average_payment,
                 COUNT(DISTINCT p.payment_currency) as currencies_used
                 FROM participants p {$whereClause}",
                $params
            );

            return [
                'total_payments' => (int)$stats['total_payments'],
                'successful_payments' => (int)$stats['successful_payments'],
                'failed_payments' => (int)$stats['failed_payments'],
                'cancelled_payments' => (int)$stats['cancelled_payments'],
                'success_rate' => $stats['total_payments'] > 0 ? round(($stats['successful_payments'] / $stats['total_payments']) * 100, 2) : 0,
                'total_revenue' => round((float)$stats['total_revenue'], 2),
                'average_payment' => round((float)$stats['average_payment'], 2),
                'currencies_used' => (int)$stats['currencies_used']
            ];

        } catch (Exception $e) {
            self::logError("Failed to get payment statistics: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get payment methods statistics
     *
     * @return array Payment methods usage statistics
     */
    public static function getPaymentMethodsStats(): array
    {
        try {
            $methods = Database::select(
                "SELECT
                 COALESCE(
                     JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.method')),
                     'unknown'
                 ) as payment_method,
                 COUNT(*) as usage_count,
                 SUM(CASE WHEN payment_status = 'paid' THEN payment_amount ELSE 0 END) as total_revenue,
                 AVG(CASE WHEN payment_status = 'paid' THEN payment_amount ELSE NULL END) as average_amount,
                 COUNT(CASE WHEN payment_status = 'paid' THEN 1 END) as successful_count,
                 ROUND(
                     (COUNT(CASE WHEN payment_status = 'paid' THEN 1 END) / COUNT(*)) * 100,
                     2
                 ) as success_rate
                 FROM participants
                 WHERE payment_status IS NOT NULL
                 GROUP BY payment_method
                 ORDER BY usage_count DESC"
            );

            return $methods ?: [];

        } catch (Exception $e) {
            self::logError("Failed to get payment methods statistics: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Refund a payment
     *
     * @param string $paymentId Mollie payment ID
     * @param float|null $amount Refund amount (null for full refund)
     * @param string $reason Refund reason
     * @return array Refund result
     * @throws Exception If refund fails
     */
    public static function refundPayment(string $paymentId, ?float $amount = null, string $reason = ''): array
    {
        try {
            $mollieClient = self::getMollieClient();
            $payment = $mollieClient->payments->get($paymentId);

            if (!$payment->isPaid()) {
                throw new Exception("Cannot refund unpaid payment", 400);
            }

            // Create refund
            $refundData = [
                'description' => $reason ?: 'Refund for Win a Brand New payment'
            ];

            if ($amount !== null) {
                $refundData['amount'] = [
                    'currency' => $payment->amount->currency,
                    'value' => number_format($amount, 2, '.', '')
                ];
            }

            $refund = $payment->refund($refundData);

            // Update participant status
            $participant = Database::selectOne(
                "SELECT id FROM participants WHERE payment_id = ?",
                [$paymentId]
            );

            if ($participant) {
                Database::update(
                    "UPDATE participants SET
                     payment_status = ?,
                     updated_at = NOW()
                     WHERE payment_id = ?",
                    [
                        $amount === null || $amount >= (float)$payment->amount->value ? self::STATUS_REFUNDED : self::STATUS_PAID,
                        $paymentId
                    ]
                );

                // Log analytics event
                Analytics::logEvent('payment_refunded', $participant['id'], [
                    'payment_id' => $paymentId,
                    'refund_id' => $refund->id,
                    'refund_amount' => $refund->amount->value,
                    'refund_reason' => $reason
                ]);

                self::logAudit('payment_refunded', $participant['id'], [
                    'payment_id' => $paymentId,
                    'refund_id' => $refund->id,
                    'refund_amount' => $refund->amount->value,
                    'reason' => $reason,
                    'refund_time' => date('Y-m-d H:i:s')
                ]);
            }

            return [
                'success' => true,
                'refund_id' => $refund->id,
                'refund_amount' => $refund->amount->value,
                'refund_currency' => $refund->amount->currency,
                'status' => $refund->status,
                'description' => $refund->description
            ];

        } catch (Exception $e) {
            self::logError("Refund failed for payment {$paymentId}: " . $e->getMessage());
            throw new Exception("Refund failed: " . $e->getMessage(), 500);
        }
    }

    /**
     * Get payment details with participant information
     *
     * @param string $paymentId Mollie payment ID
     * @return array|null Payment details
     */
    public static function getPaymentDetails(string $paymentId): ?array
    {
        try {
            $payment = Database::selectOne(
                "SELECT p.*, r.game_id, r.round_number, g.name as game_name, g.prize_value
                 FROM participants p
                 JOIN rounds r ON p.round_id = r.id
                 JOIN games g ON r.game_id = g.id
                 WHERE p.payment_id = ?",
                [$paymentId]
            );

            if (!$payment) {
                return null;
            }

            // Get Mollie payment details if needed
            if ($payment['payment_provider'] === self::PROVIDER_MOLLIE) {
                try {
                    $mollieClient = self::getMollieClient();
                    $molliePayment = $mollieClient->payments->get($paymentId);

                    $payment['mollie_status'] = $molliePayment->status;
                    $payment['mollie_method'] = $molliePayment->method;
                    $payment['mollie_paid_at'] = $molliePayment->paidAt ? $molliePayment->paidAt->format('Y-m-d H:i:s') : null;
                    $payment['mollie_expires_at'] = $molliePayment->expiresAt ? $molliePayment->expiresAt->format('Y-m-d H:i:s') : null;
                } catch (Exception $e) {
                    self::logError("Failed to get Mollie details for payment {$paymentId}: " . $e->getMessage());
                }
            }

            return $payment;

        } catch (Exception $e) {
            self::logError("Failed to get payment details for {$paymentId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Cancel a pending payment
     *
     * @param string $paymentId Mollie payment ID
     * @return bool Cancellation success
     */
    public static function cancelPayment(string $paymentId): bool
    {
        try {
            // Update local status first
            Database::update(
                "UPDATE participants SET
                 payment_status = ?,
                 updated_at = NOW()
                 WHERE payment_id = ?",
                [self::STATUS_CANCELLED, $paymentId]
            );

            // Note: Mollie doesn't support payment cancellation via API
            // Payments will expire naturally if not completed

            $participant = Database::selectOne(
                "SELECT id FROM participants WHERE payment_id = ?",
                [$paymentId]
            );

            if ($participant) {
                self::logAudit('payment_cancelled', $participant['id'], [
                    'payment_id' => $paymentId,
                    'cancelled_at' => date('Y-m-d H:i:s')
                ]);
            }

            return true;

        } catch (Exception $e) {
            self::logError("Failed to cancel payment {$paymentId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Health check for payment system
     *
     * @return array Health status
     */
    public static function healthCheck(): array
    {
        try {
            $startTime = microtime(true);

            // Test Mollie API connection
            $mollieClient = self::getMollieClient();
            $profile = $mollieClient->profiles->get('me');

            $responseTime = microtime(true) - $startTime;

            // Check recent payment activity
            $recentPayments = Database::selectOne(
                "SELECT
                 COUNT(*) as total_today,
                 COUNT(CASE WHEN payment_status = 'paid' THEN 1 END) as successful_today,
                 COUNT(CASE WHEN payment_status = 'failed' THEN 1 END) as failed_today
                 FROM participants
                 WHERE DATE(created_at) = CURDATE()
                 AND payment_status IS NOT NULL"
            );

            return [
                'status' => 'healthy',
                'mollie_connection' => 'ok',
                'mollie_profile' => $profile->name,
                'response_time' => $responseTime,
                'payments_today' => [
                    'total' => (int)$recentPayments['total_today'],
                    'successful' => (int)$recentPayments['successful_today'],
                    'failed' => (int)$recentPayments['failed_today']
                ]
            ];

        } catch (Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'mollie_connection' => 'failed',
                'response_time' => null
            ];
        }
    }

    /**
     * Get failed payments for retry
     *
     * @param int $limit Maximum number of payments to return
     * @return array Failed payments list
     */
    public static function getFailedPaymentsForRetry(int $limit = 50): array
    {
        try {
            return Database::select(
                "SELECT p.*, r.game_id, g.name as game_name
                 FROM participants p
                 JOIN rounds r ON p.round_id = r.id
                 JOIN games g ON r.game_id = g.id
                 WHERE p.payment_status IN ('failed', 'cancelled', 'expired')
                 AND p.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                 ORDER BY p.updated_at DESC
                 LIMIT ?",
                [$limit]
            );

        } catch (Exception $e) {
            self::logError("Failed to get failed payments for retry: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Retry failed payment
     *
     * @param int $participantId Participant ID
     * @return array Retry result
     * @throws Exception If retry fails
     */
    public static function retryFailedPayment(int $participantId): array
    {
        try {
            $participant = Database::selectOne(
                "SELECT p.*, r.game_id, g.entry_fee, g.currency
                 FROM participants p
                 JOIN rounds r ON p.round_id = r.id
                 JOIN games g ON r.game_id = g.id
                 WHERE p.id = ?
                 AND p.payment_status IN ('failed', 'cancelled', 'expired')",
                [$participantId]
            );

            if (!$participant) {
                throw new Exception("Participant not found or payment not retryable", 404);
            }

            // Create new payment
            return self::createPayment(
                $participantId,
                (float)$participant['entry_fee'],
                $participant['currency'],
                "Retry payment for {$participant['game_name']}",
                [
                    'participant_id' => $participantId,
                    'retry_attempt' => true,
                    'original_payment_id' => $participant['payment_id']
                ]
            );

        } catch (Exception $e) {
            self::logError("Payment retry failed for participant {$participantId}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Log audit event
     *
     * @param string $event Event type
     * @param int|null $participantId Participant ID
     * @param array $details Event details
     * @return void
     */
    private static function logAudit(string $event, ?int $participantId, array $details): void
    {
        try {
            self::$auditLog[] = [
                'timestamp' => date('Y-m-d H:i:s'),
                'event' => $event,
                'participant_id' => $participantId,
                'details' => $details
            ];

            // Write to audit log file
            $logFile = $_ENV['LOG_PATH'] ?? '/var/log/winabrandnew';
            $logFile .= '/payments.log';

            if (is_writable(dirname($logFile))) {
                $logEntry = json_encode([
                    'timestamp' => date('Y-m-d H:i:s'),
                    'event' => $event,
                    'participant_id' => $participantId,
                    'details' => $details
                ]) . PHP_EOL;

                file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
            }

        } catch (Exception $e) {
            error_log("Failed to write payment audit log: " . $e->getMessage());
        }
    }

    /**
     * Log error message
     *
     * @param string $message Error message
     * @return void
     */
    private static function logError(string $message): void
    {
        error_log("Payment Error: " . $message);

        // Also log to payment log file
        self::logAudit('error', null, ['message' => $message]);
    }

    /**
     * Get audit log entries
     *
     * @param array $filters Optional filters
     * @return array Audit log entries
     */
    public static function getAuditLog(array $filters = []): array
    {
        // Return in-memory audit log for current request
        $log = self::$auditLog;

        // Apply filters if provided
        if (!empty($filters['event'])) {
            $log = array_filter($log, function($entry) use ($filters) {
                return $entry['event'] === $filters['event'];
            });
        }

        if (!empty($filters['participant_id'])) {
            $log = array_filter($log, function($entry) use ($filters) {
                return $entry['participant_id'] === $filters['participant_id'];
            });
        }

        return array_values($log);
    }

    /**
     * Cleanup expired payments
     *
     * @return int Number of cleaned up payments
     */
    public static function cleanupExpiredPayments(): int
    {
        try {
            // Mark expired payments in database
            $expiredCount = Database::update(
                "UPDATE participants SET
                 payment_status = 'expired',
                 updated_at = NOW()
                 WHERE payment_status = 'pending'
                 AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
            );

            if ($expiredCount > 0) {
                self::logAudit('cleanup_expired_payments', null, [
                    'expired_count' => $expiredCount,
                    'cleanup_time' => date('Y-m-d H:i:s')
                ]);
            }

            return $expiredCount;

        } catch (Exception $e) {
            self::logError("Failed to cleanup expired payments: " . $e->getMessage());
            return 0;
        }
    }
}
