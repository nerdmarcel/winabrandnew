<?php
declare(strict_types=1);

/**
 * File: core/StripeIntegration.php
 * Location: core/StripeIntegration.php
 *
 * WinABN Stripe Integration
 *
 * Provides Stripe payment processing as backup to Mollie.
 * Handles checkout sessions, webhooks, and payment verification.
 *
 * @package WinABN\Core
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Core;

use WinABN\Models\Payment;
use WinABN\Exceptions\PaymentException;
use Stripe\{StripeClient, Webhook, Exception\SignatureVerificationException};
use Exception;

class StripeIntegration
{
    /**
     * Stripe client instance
     *
     * @var StripeClient
     */
    private StripeClient $stripe;

    /**
     * Webhook endpoint secret
     *
     * @var string
     */
    private string $webhookSecret;

    /**
     * Constructor
     *
     * @throws Exception
     */
    public function __construct()
    {
        $apiKey = env('STRIPE_API_KEY');
        if (!$apiKey) {
            throw new Exception('Stripe API key not configured');
        }

        $this->stripe = new StripeClient($apiKey);
        $this->webhookSecret = env('STRIPE_WEBHOOK_SECRET', '');
    }

    /**
     * Create checkout session for payment
     *
     * @param array $paymentData Payment data
     * @return array Checkout session data
     * @throws PaymentException
     */
    public function createCheckoutSession(array $paymentData): array
    {
        try {
            $session = $this->stripe->checkout->sessions->create([
                'payment_method_types' => [
                    'card',
                    'apple_pay',
                    'google_pay'
                ],
                'line_items' => [[
                    'price_data' => [
                        'currency' => strtolower($paymentData['currency']),
                        'product_data' => [
                            'name' => $paymentData['description'],
                            'description' => 'Entry fee for WinABN competition'
                        ],
                        'unit_amount' => $this->convertToStripeAmount($paymentData['amount'], $paymentData['currency'])
                    ],
                    'quantity' => 1
                ]],
                'mode' => 'payment',
                'success_url' => $paymentData['success_url'],
                'cancel_url' => $paymentData['cancel_url'],
                'metadata' => $paymentData['metadata'] ?? [],
                'customer_email' => $paymentData['customer_email'] ?? null,
                'expires_at' => time() + (30 * 60), // 30 minutes
                'payment_intent_data' => [
                    'description' => $paymentData['description'],
                    'metadata' => $paymentData['metadata'] ?? []
                ]
            ]);

            return [
                'id' => $session->id,
                'url' => $session->url,
                'payment_intent' => $session->payment_intent
            ];

        } catch (Exception $e) {
            throw new PaymentException("Stripe checkout session creation failed: " . $e->getMessage());
        }
    }

    /**
     * Retrieve checkout session
     *
     * @param string $sessionId Session ID
     * @return array Session data
     * @throws PaymentException
     */
    public function retrieveSession(string $sessionId): array
    {
        try {
            $session = $this->stripe->checkout->sessions->retrieve($sessionId);

            return [
                'id' => $session->id,
                'payment_status' => $session->payment_status,
                'customer_details' => $session->customer_details,
                'amount_total' => $session->amount_total,
                'currency' => $session->currency,
                'metadata' => $session->metadata->toArray(),
                'payment_intent' => $session->payment_intent
            ];

        } catch (Exception $e) {
            throw new PaymentException("Failed to retrieve Stripe session: " . $e->getMessage());
        }
    }

    /**
     * Handle webhook event
     *
     * @param string $payload Webhook payload
     * @param string $signature Webhook signature
     * @return array Processing result
     * @throws Exception
     */
    public function handleWebhook(string $payload, string $signature): array
    {
        try {
            // Verify webhook signature
            $event = Webhook::constructEvent(
                $payload,
                $signature,
                $this->webhookSecret
            );

            // Process the event
            switch ($event->type) {
                case 'checkout.session.completed':
                    return $this->handleCheckoutCompleted($event->data->object);

                case 'payment_intent.succeeded':
                    return $this->handlePaymentSucceeded($event->data->object);

                case 'payment_intent.payment_failed':
                    return $this->handlePaymentFailed($event->data->object);

                default:
                    return [
                        'success' => true,
                        'message' => 'Event type not handled: ' . $event->type
                    ];
            }

        } catch (SignatureVerificationException $e) {
            throw new Exception('Invalid webhook signature: ' . $e->getMessage());
        } catch (Exception $e) {
            throw new Exception('Webhook processing failed: ' . $e->getMessage());
        }
    }

    /**
     * Handle checkout session completed event
     *
     * @param object $session Stripe session object
     * @return array Processing result
     */
    private function handleCheckoutCompleted(object $session): array
    {
        try {
            Database::beginTransaction();

            // Find payment by session ID
            $paymentModel = new Payment();
            $payment = $paymentModel->findByProviderPaymentId($session->id);

            if (!$payment) {
                Database::rollback();
                return [
                    'success' => false,
                    'error' => 'Payment record not found for session: ' . $session->id
                ];
            }

            // Update payment status based on payment status
            if ($session->payment_status === 'paid') {
                $webhookData = [
                    'session_id' => $session->id,
                    'payment_intent' => $session->payment_intent,
                    'amount_total' => $session->amount_total,
                    'currency' => $session->currency,
                    'customer_email' => $session->customer_details->email ?? null
                ];

                $success = $paymentModel->markAsPaid($payment['id'], $webhookData);

                if ($success) {
                    $this->recordAnalyticsEvent('payment_completed', $payment);
                }

                Database::commit();

                return [
                    'success' => true,
                    'action' => 'payment_confirmed',
                    'payment_id' => $payment['id']
                ];

            } else {
                // Payment not completed
                $paymentModel->update($payment['id'], [
                    'provider_status' => $session->payment_status,
                    'webhook_data' => json_encode($session->toArray())
                ]);

                Database::commit();

                return [
                    'success' => true,
                    'action' => 'status_updated',
                    'status' => $session->payment_status
                ];
            }

        } catch (Exception $e) {
            Database::rollback();
            throw $e;
        }
    }

    /**
     * Handle payment intent succeeded event
     *
     * @param object $paymentIntent Stripe payment intent object
     * @return array Processing result
     */
    private function handlePaymentSucceeded(object $paymentIntent): array
    {
        try {
            // Find payment by payment intent ID
            $paymentModel = new Payment();
            $payment = Database::fetchOne(
                "SELECT * FROM payments WHERE JSON_EXTRACT(webhook_data, '$.payment_intent') = ?",
                [$paymentIntent->id]
            );

            if (!$payment) {
                return [
                    'success' => false,
                    'error' => 'Payment record not found for payment intent: ' . $paymentIntent->id
                ];
            }

            // Ensure payment is marked as paid
            if ($payment['status'] !== 'paid') {
                $webhookData = [
                    'payment_intent_id' => $paymentIntent->id,
                    'amount_received' => $paymentIntent->amount_received,
                    'currency' => $paymentIntent->currency,
                    'charges' => $paymentIntent->charges->data
                ];

                $paymentModel->markAsPaid($payment['id'], $webhookData);
            }

            return [
                'success' => true,
                'action' => 'payment_confirmed',
                'payment_id' => $payment['id']
            ];

        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Handle payment intent failed event
     *
     * @param object $paymentIntent Stripe payment intent object
     * @return array Processing result
     */
    private function handlePaymentFailed(object $paymentIntent): array
    {
        try {
            // Find payment by payment intent ID
            $paymentModel = new Payment();
            $payment = Database::fetchOne(
                "SELECT * FROM payments WHERE JSON_EXTRACT(webhook_data, '$.payment_intent') = ?",
                [$paymentIntent->id]
            );

            if (!$payment) {
                return [
                    'success' => false,
                    'error' => 'Payment record not found for payment intent: ' . $paymentIntent->id
                ];
            }

            // Get failure reason
            $failureReason = 'Payment failed';
            if (isset($paymentIntent->last_payment_error)) {
                $failureReason = $paymentIntent->last_payment_error->message ?? $failureReason;
            }

            // Mark payment as failed
            $webhookData = [
                'payment_intent_id' => $paymentIntent->id,
                'failure_code' => $paymentIntent->last_payment_error->code ?? null,
                'failure_message' => $paymentIntent->last_payment_error->message ?? null
            ];

            $paymentModel->markAsFailed($payment['id'], $failureReason, $webhookData);

            return [
                'success' => true,
                'action' => 'payment_failed',
                'payment_id' => $payment['id'],
                'reason' => $failureReason
            ];

        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Convert amount to Stripe format (cents)
     *
     * @param float $amount Amount in currency units
     * @param string $currency Currency code
     * @return int Amount in smallest currency unit
     */
    private function convertToStripeAmount(float $amount, string $currency): int
    {
        // Zero-decimal currencies (e.g., JPY, KRW)
        $zeroDecimalCurrencies = ['bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'];

        if (in_array(strtolower($currency), $zeroDecimalCurrencies)) {
            return (int) $amount;
        }

        // Most currencies use 2 decimal places
        return (int) ($amount * 100);
    }

    /**
     * Convert Stripe amount to currency units
     *
     * @param int $stripeAmount Amount in smallest currency unit
     * @param string $currency Currency code
     * @return float Amount in currency units
     */
    private function convertFromStripeAmount(int $stripeAmount, string $currency): float
    {
        // Zero-decimal currencies
        $zeroDecimalCurrencies = ['bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'];

        if (in_array(strtolower($currency), $zeroDecimalCurrencies)) {
            return (float) $stripeAmount;
        }

        return $stripeAmount / 100.0;
    }

    /**
     * Create refund for payment
     *
     * @param string $paymentIntentId Payment intent ID
     * @param float $amount Refund amount (optional, null for full refund)
     * @param string $reason Refund reason
     * @return array Refund result
     * @throws PaymentException
     */
    public function createRefund(string $paymentIntentId, ?float $amount = null, string $reason = ''): array
    {
        try {
            $refundData = [
                'payment_intent' => $paymentIntentId,
                'reason' => $reason ?: 'requested_by_customer'
            ];

            if ($amount !== null) {
                // Get currency from payment intent
                $paymentIntent = $this->stripe->paymentIntents->retrieve($paymentIntentId);
                $refundData['amount'] = $this->convertToStripeAmount($amount, $paymentIntent->currency);
            }

            $refund = $this->stripe->refunds->create($refundData);

            return [
                'success' => true,
                'refund_id' => $refund->id,
                'amount' => $this->convertFromStripeAmount($refund->amount, $refund->currency),
                'currency' => $refund->currency,
                'status' => $refund->status
            ];

        } catch (Exception $e) {
            throw new PaymentException("Stripe refund creation failed: " . $e->getMessage());
        }
    }

    /**
     * Get payment method details
     *
     * @param string $paymentMethodId Payment method ID
     * @return array Payment method details
     * @throws PaymentException
     */
    public function getPaymentMethod(string $paymentMethodId): array
    {
        try {
            $paymentMethod = $this->stripe->paymentMethods->retrieve($paymentMethodId);

            return [
                'id' => $paymentMethod->id,
                'type' => $paymentMethod->type,
                'card' => $paymentMethod->card ? [
                    'brand' => $paymentMethod->card->brand,
                    'last4' => $paymentMethod->card->last4,
                    'exp_month' => $paymentMethod->card->exp_month,
                    'exp_year' => $paymentMethod->card->exp_year
                ] : null
            ];

        } catch (Exception $e) {
            throw new PaymentException("Failed to retrieve payment method: " . $e->getMessage());
        }
    }

    /**
     * Record analytics event
     *
     * @param string $eventType Event type
     * @param array $payment Payment data
     * @return void
     */
    private function recordAnalyticsEvent(string $eventType, array $payment): void
    {
        try {
            // Get participant data
            $participant = Database::fetchOne(
                "SELECT * FROM participants WHERE id = ?",
                [$payment['participant_id']]
            );

            if ($participant) {
                $eventData = [
                    'event_type' => $eventType,
                    'participant_id' => $participant['id'],
                    'round_id' => $participant['round_id'],
                    'revenue_amount' => $payment['amount'],
                    'additional_data_json' => json_encode([
                        'payment_id' => $payment['id'],
                        'currency' => $payment['currency'],
                        'provider' => 'stripe'
                    ]),
                    'created_at' => date('Y-m-d H:i:s')
                ];

                Database::execute(
                    "INSERT INTO analytics_events (event_type, participant_id, round_id, revenue_amount, additional_data_json, created_at) VALUES (?, ?, ?, ?, ?, ?)",
                    array_values($eventData)
                );
            }
        } catch (Exception $e) {
            // Log error but don't fail the webhook
            error_log("Analytics event recording failed: " . $e->getMessage());
        }
    }

    /**
     * Validate webhook payload
     *
     * @param string $payload Webhook payload
     * @param string $signature Webhook signature
     * @return bool True if valid
     */
    public function validateWebhook(string $payload, string $signature): bool
    {
        try {
            Webhook::constructEvent($payload, $signature, $this->webhookSecret);
            return true;
        } catch (SignatureVerificationException $e) {
            return false;
        }
    }

    /**
     * Get customer payment methods
     *
     * @param string $customerId Customer ID
     * @return array Payment methods
     * @throws PaymentException
     */
    public function getCustomerPaymentMethods(string $customerId): array
    {
        try {
            $paymentMethods = $this->stripe->paymentMethods->all([
                'customer' => $customerId,
                'type' => 'card'
            ]);

            return array_map(function($pm) {
                return [
                    'id' => $pm->id,
                    'type' => $pm->type,
                    'card' => [
                        'brand' => $pm->card->brand,
                        'last4' => $pm->card->last4,
                        'exp_month' => $pm->card->exp_month,
                        'exp_year' => $pm->card->exp_year
                    ]
                ];
            }, $paymentMethods->data);

        } catch (Exception $e) {
            throw new PaymentException("Failed to retrieve customer payment methods: " . $e->getMessage());
        }
    }

    /**
     * Check if Stripe is available
     *
     * @return bool True if Stripe is properly configured
     */
    public static function isAvailable(): bool
    {
        return !empty(env('STRIPE_API_KEY')) && env('STRIPE_ENABLED', false);
    }

    /**
     * Get supported payment methods
     *
     * @return array Supported payment methods
     */
    public function getSupportedPaymentMethods(): array
    {
        return [
            'card',
            'apple_pay',
            'google_pay'
        ];
    }

    /**
     * Test connection to Stripe
     *
     * @return array Connection test result
     */
    public function testConnection(): array
    {
        try {
            // Try to retrieve account details
            $account = $this->stripe->accounts->retrieve();

            return [
                'success' => true,
                'account_id' => $account->id,
                'country' => $account->country,
                'default_currency' => $account->default_currency
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
