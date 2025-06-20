<?php
/**
 * File: /controllers/ClaimController.php
 * Prize Claim Management Controller
 *
 * Handles secure token verification, claim form processing,
 * shipping address collection, and IP security controls.
 *
 * Security Features:
 * - 32-character secure random tokens
 * - IP rate limiting (4 attempts/hour)
 * - 24-hour IP blocking after exceeded attempts
 * - 30-day token expiration
 * - Comprehensive security logging
 */

namespace WinABrandNew\Controllers;

use WinABrandNew\Models\ClaimToken;
use WinABrandNew\Models\Participant;
use WinABrandNew\Models\PrizeFulfillment;
use WinABrandNew\Models\SecurityLog;
use WinABrandNew\Models\EmailQueue;
use Exception;

class ClaimController extends BaseController
{
    private ClaimToken $claimTokenModel;
    private Participant $participantModel;
    private PrizeFulfillment $fulfillmentModel;
    private SecurityLog $securityLogModel;
    private EmailQueue $emailQueueModel;

    /**
     * IP security configuration
     */
    private array $ipSecurityConfig = [
        'max_attempts_per_hour' => 4,
        'block_duration_hours' => 24,
        'token_expiry_days' => 30
    ];

    /**
     * Required shipping address fields
     */
    private array $requiredShippingFields = [
        'full_name',
        'address_line1',
        'city',
        'postal_code',
        'country'
    ];

    /**
     * Initialize claim controller
     */
    public function __construct()
    {
        parent::__construct();

        $this->claimTokenModel = new ClaimToken();
        $this->participantModel = new Participant();
        $this->fulfillmentModel = new PrizeFulfillment();
        $this->securityLogModel = new SecurityLog();
        $this->emailQueueModel = new EmailQueue();

        // Override rate limiting for claim attempts
        $this->rateLimiting = [
            'enabled' => true,
            'max_requests' => $this->ipSecurityConfig['max_attempts_per_hour'],
            'time_window' => 3600, // 1 hour
            'identifier' => 'claim_ip'
        ];
    }

    /**
     * Display claim form for valid token
     *
     * @return void
     */
    public function showClaimForm(): void
    {
        $token = $this->request['params']['token'] ?? '';

        if (empty($token)) {
            $this->redirectWithError('/claim-error', 'Invalid claim link');
            return;
        }

        // Check IP security before processing
        if ($this->isIpBlocked()) {
            $this->logSecurityEvent('blocked_ip_claim_attempt', [
                'ip' => $this->request['ip'],
                'token' => substr($token, 0, 8) . '...',
                'user_agent' => $this->request['user_agent']
            ]);

            $this->renderError('Your IP address has been temporarily blocked due to suspicious activity. Please try again later.', 429);
            return;
        }

        try {
            // Validate and get claim token
            $claimData = $this->validateClaimToken($token);

            if (!$claimData) {
                $this->incrementFailedAttempt($token);
                $this->redirectWithError('/claim-error', 'Invalid or expired claim token');
                return;
            }

            // Check if already claimed
            if ($claimData['used_at']) {
                $this->redirectWithError('/claim-error', 'This prize has already been claimed');
                return;
            }

            // Get participant details
            $participant = $this->participantModel->getById($claimData['participant_id']);

            if (!$participant) {
                $this->logSecurityEvent('claim_participant_not_found', [
                    'token' => substr($token, 0, 8) . '...',
                    'participant_id' => $claimData['participant_id']
                ]);

                $this->redirectWithError('/claim-error', 'Participant not found');
                return;
            }

            // Render claim form
            $this->renderClaimForm($claimData, $participant);

        } catch (Exception $e) {
            $this->logError('claim_form_error', $e);
            $this->redirectWithError('/claim-error', 'An error occurred. Please try again later.');
        }
    }

    /**
     * Process claim form submission
     *
     * @return void
     */
    public function processClaim(): void
    {
        // Validate CSRF token
        $this->validateCsrfToken();

        $token = $this->request['params']['token'] ?? '';

        if (empty($token)) {
            $this->jsonResponse(['error' => 'Invalid claim token'], 400);
            return;
        }

        // Check IP security
        if ($this->isIpBlocked()) {
            $this->logSecurityEvent('blocked_ip_claim_process', [
                'ip' => $this->request['ip'],
                'token' => substr($token, 0, 8) . '...'
            ]);

            $this->jsonResponse(['error' => 'IP address temporarily blocked'], 429);
            return;
        }

        try {
            // Validate claim token again
            $claimData = $this->validateClaimToken($token);

            if (!$claimData) {
                $this->incrementFailedAttempt($token);
                $this->jsonResponse(['error' => 'Invalid or expired claim token'], 400);
                return;
            }

            // Check if already claimed
            if ($claimData['used_at']) {
                $this->jsonResponse(['error' => 'Prize already claimed'], 400);
                return;
            }

            // Validate shipping information
            $shippingData = $this->validateShippingData();

            if (!$shippingData['valid']) {
                $this->jsonResponse(['error' => 'Invalid shipping information', 'details' => $shippingData['errors']], 400);
                return;
            }

            // Process the claim
            $this->processSuccessfulClaim($claimData, $shippingData['data']);

        } catch (Exception $e) {
            $this->logError('claim_process_error', $e);
            $this->jsonResponse(['error' => 'An error occurred processing your claim'], 500);
        }
    }

    /**
     * Validate claim token and return data
     *
     * @param string $token
     * @return array|null
     */
    private function validateClaimToken(string $token): ?array
    {
        if (strlen($token) !== 32) {
            return null;
        }

        // Get token data
        $claimData = $this->claimTokenModel->getByToken($token);

        if (!$claimData) {
            return null;
        }

        // Check expiration
        $expiryTime = strtotime($claimData['created_at']) + ($this->ipSecurityConfig['token_expiry_days'] * 24 * 3600);

        if (time() > $expiryTime) {
            return null;
        }

        return $claimData;
    }

    /**
     * Check if IP is currently blocked
     *
     * @return bool
     */
    private function isIpBlocked(): bool
    {
        $ip = $this->request['ip'];

        // Check for active IP block
        $blockRecord = $this->securityLogModel->getActiveBlock($ip);

        return $blockRecord !== null;
    }

    /**
     * Increment failed attempt counter for IP
     *
     * @param string $token
     * @return void
     */
    private function incrementFailedAttempt(string $token): void
    {
        $ip = $this->request['ip'];

        // Log the failed attempt
        $this->logSecurityEvent('invalid_claim_token', [
            'ip' => $ip,
            'token' => substr($token, 0, 8) . '...',
            'user_agent' => $this->request['user_agent']
        ]);

        // Count attempts in the last hour
        $attempts = $this->securityLogModel->countAttempts($ip, 'invalid_claim_token', 3600);

        // Block IP if too many attempts
        if ($attempts >= $this->ipSecurityConfig['max_attempts_per_hour']) {
            $blockUntil = time() + ($this->ipSecurityConfig['block_duration_hours'] * 3600);

            $this->securityLogModel->blockIp($ip, $blockUntil, 'Too many invalid claim attempts');

            $this->logSecurityEvent('ip_blocked_claim_attempts', [
                'ip' => $ip,
                'attempts' => $attempts,
                'blocked_until' => date('Y-m-d H:i:s', $blockUntil)
            ]);
        }
    }

    /**
     * Validate shipping data from form
     *
     * @return array
     */
    private function validateShippingData(): array
    {
        $data = [];
        $errors = [];

        // Validate required fields
        foreach ($this->requiredShippingFields as $field) {
            $value = trim($this->request['params'][$field] ?? '');

            if (empty($value)) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
                continue;
            }

            // Basic validation
            switch ($field) {
                case 'full_name':
                    if (strlen($value) < 2 || strlen($value) > 100) {
                        $errors[] = 'Full name must be between 2 and 100 characters';
                    }
                    break;

                case 'postal_code':
                    if (!preg_match('/^[A-Z0-9\s\-]{3,10}$/i', $value)) {
                        $errors[] = 'Invalid postal code format';
                    }
                    break;

                case 'country':
                    if (!preg_match('/^[A-Z]{2}$/', strtoupper($value))) {
                        $errors[] = 'Country must be a valid 2-letter code';
                    }
                    break;
            }

            $data[$field] = $value;
        }

        // Optional address line 2
        $data['address_line2'] = trim($this->request['params']['address_line2'] ?? '');

        return [
            'valid' => empty($errors),
            'data' => $data,
            'errors' => $errors
        ];
    }

    /**
     * Process successful claim
     *
     * @param array $claimData
     * @param array $shippingData
     * @return void
     */
    private function processSuccessfulClaim(array $claimData, array $shippingData): void
    {
        try {
            // Start transaction
            $this->database->beginTransaction();

            // Mark token as used
            $this->claimTokenModel->markAsUsed($claimData['id']);

            // Create fulfillment record
            $fulfillmentId = $this->fulfillmentModel->create([
                'participant_id' => $claimData['participant_id'],
                'claim_token_id' => $claimData['id'],
                'shipping_name' => $shippingData['full_name'],
                'shipping_address_line1' => $shippingData['address_line1'],
                'shipping_address_line2' => $shippingData['address_line2'],
                'shipping_city' => $shippingData['city'],
                'shipping_postal_code' => $shippingData['postal_code'],
                'shipping_country' => strtoupper($shippingData['country']),
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s')
            ]);

            // Get participant details for notifications
            $participant = $this->participantModel->getById($claimData['participant_id']);

            // Queue confirmation email
            $this->queueConfirmationEmail($participant, $shippingData, $fulfillmentId);

            // Log successful claim
            $this->logSecurityEvent('successful_claim', [
                'participant_id' => $claimData['participant_id'],
                'fulfillment_id' => $fulfillmentId,
                'ip' => $this->request['ip'],
                'country' => $shippingData['country']
            ]);

            // Commit transaction
            $this->database->commit();

            // Return success response
            $this->jsonResponse([
                'success' => true,
                'message' => 'Prize claim submitted successfully! You will receive a confirmation email shortly.',
                'fulfillment_id' => $fulfillmentId
            ]);

        } catch (Exception $e) {
            $this->database->rollback();
            throw $e;
        }
    }

    /**
     * Queue confirmation email
     *
     * @param array $participant
     * @param array $shippingData
     * @param int $fulfillmentId
     * @return void
     */
    private function queueConfirmationEmail(array $participant, array $shippingData, int $fulfillmentId): void
    {
        $emailData = [
            'to_email' => $participant['email'],
            'to_name' => $shippingData['full_name'],
            'template' => 'claim_confirmation',
            'variables' => [
                'participant_name' => $shippingData['full_name'],
                'prize_name' => $participant['game_name'], // From joined data
                'fulfillment_id' => $fulfillmentId,
                'shipping_address' => $this->formatShippingAddress($shippingData),
                'estimated_delivery' => $this->calculateEstimatedDelivery($shippingData['country'])
            ],
            'priority' => 'high',
            'send_at' => date('Y-m-d H:i:s')
        ];

        $this->emailQueueModel->create($emailData);
    }

    /**
     * Format shipping address for display
     *
     * @param array $shippingData
     * @return string
     */
    private function formatShippingAddress(array $shippingData): string
    {
        $address = $shippingData['address_line1'];

        if (!empty($shippingData['address_line2'])) {
            $address .= "\n" . $shippingData['address_line2'];
        }

        $address .= "\n" . $shippingData['city'] . ' ' . $shippingData['postal_code'];
        $address .= "\n" . strtoupper($shippingData['country']);

        return $address;
    }

    /**
     * Calculate estimated delivery time
     *
     * @param string $country
     * @return string
     */
    private function calculateEstimatedDelivery(string $country): string
    {
        $country = strtoupper($country);

        // UK domestic delivery
        if ($country === 'GB') {
            return '3-5 business days';
        }

        // EU countries
        $euCountries = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE'];

        if (in_array($country, $euCountries)) {
            return '7-14 business days';
        }

        // International delivery
        return '14-21 business days';
    }

    /**
     * Render claim form view
     *
     * @param array $claimData
     * @param array $participant
     * @return void
     */
    private function renderClaimForm(array $claimData, array $participant): void
    {
        $viewData = [
            'token' => $claimData['token'],
            'participant' => $participant,
            'csrf_token' => $this->csrfToken,
            'required_fields' => $this->requiredShippingFields,
            'countries' => $this->getCountryList()
        ];

        $this->render('claim/form', $viewData);
    }

    /**
     * Render error page
     *
     * @param string $message
     * @param int $statusCode
     * @return void
     */
    private function renderError(string $message, int $statusCode = 400): void
    {
        http_response_code($statusCode);

        $this->render('claim/error', [
            'error_message' => $message,
            'status_code' => $statusCode
        ]);
    }

    /**
     * Redirect with error message
     *
     * @param string $url
     * @param string $message
     * @return void
     */
    private function redirectWithError(string $url, string $message): void
    {
        $_SESSION['error_message'] = $message;
        header("Location: $url");
        exit;
    }

    /**
     * Get list of countries for form
     *
     * @return array
     */
    private function getCountryList(): array
    {
        return [
            'GB' => 'United Kingdom',
            'US' => 'United States',
            'CA' => 'Canada',
            'AU' => 'Australia',
            'DE' => 'Germany',
            'FR' => 'France',
            'IT' => 'Italy',
            'ES' => 'Spain',
            'NL' => 'Netherlands',
            'BE' => 'Belgium',
            // Add more countries as needed
        ];
    }

    /**
     * Log security event
     *
     * @param string $eventType
     * @param array $details
     * @return void
     */
    private function logSecurityEvent(string $eventType, array $details): void
    {
        $this->securityLogModel->logEvent($eventType, $this->request['ip'], $details);
    }
}
