<?php
declare(strict_types=1);

/**
 * File: controllers/DiscountController.php
 * Location: controllers/DiscountController.php
 *
 * WinABN Discount Controller - Discount and Referral Logic
 *
 * Handles discount validation, application, and referral processing
 * for the WinABN platform with fraud prevention mechanisms.
 *
 * @package WinABN\Controllers
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Controllers;

use WinABN\Core\Controller;
use WinABN\Core\Security;
use WinABN\Core\Session;
use WinABN\Models\UserAction;
use WinABN\Models\Game;
use WinABN\Models\Participant;
use Exception;

class DiscountController extends Controller
{
    /**
     * UserAction model instance
     *
     * @var UserAction
     */
    private UserAction $userActionModel;

    /**
     * Game model instance
     *
     * @var Game
     */
    private Game $gameModel;

    /**
     * Participant model instance
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
        $this->userActionModel = new UserAction();
        $this->gameModel = new Game();
        $this->participantModel = new Participant();
    }

    /**
     * Process referral from URL parameter
     *
     * @return array<string, mixed>
     */
    public function processReferral(): array
    {
        try {
            $referralCode = $_GET['ref'] ?? '';
            $userEmail = Session::get('user_email', '');
            $clientIp = $this->getClientIp();

            if (empty($referralCode)) {
                return $this->jsonResponse(['success' => false, 'error' => 'No referral code provided']);
            }

            if (empty($userEmail)) {
                // Store referral in session for later use
                Session::set('pending_referral', $referralCode);
                return $this->jsonResponse([
                    'success' => true,
                    'message' => 'Referral stored for later use'
                ]);
            }

            $validation = $this->userActionModel->validateReferralCode($referralCode, $userEmail, $clientIp);

            if (!$validation['valid']) {
                return $this->jsonResponse([
                    'success' => false,
                    'error' => $validation['error']
                ]);
            }

            // Store validated referral in session
            Session::set('validated_referral', [
                'referrer_email' => $validation['referrer_email'],
                'discount_amount' => $validation['discount_amount'],
                'validated_at' => time()
            ]);

            return $this->jsonResponse([
                'success' => true,
                'discount_amount' => $validation['discount_amount'],
                'message' => 'Referral discount available'
            ]);

        } catch (Exception $e) {
            $this->logError('Referral processing failed', [
                'error' => $e->getMessage(),
                'referral_code' => $referralCode ?? '',
                'user_email' => $userEmail ?? ''
            ]);

            return $this->jsonResponse([
                'success' => false,
                'error' => 'Failed to process referral'
            ]);
        }
    }

    /**
     * Check available discounts for user
     *
     * @return array<string, mixed>
     */
    public function checkAvailableDiscounts(): array
    {
        try {
            $userEmail = $this->validateRequired(['email'])['email'];
            $gameId = (int) ($_POST['game_id'] ?? $_GET['game_id'] ?? 0);

            if (!$gameId) {
                return $this->jsonResponse(['success' => false, 'error' => 'Game ID required']);
            }

            $game = $this->gameModel->find($gameId);
            if (!$game) {
                return $this->jsonResponse(['success' => false, 'error' => 'Game not found']);
            }

            $discounts = $this->getAvailableDiscounts($userEmail, $gameId);

            return $this->jsonResponse([
                'success' => true,
                'discounts' => $discounts,
                'game' => [
                    'id' => $game['id'],
                    'name' => $game['name'],
                    'entry_fee' => $game['entry_fee']
                ]
            ]);

        } catch (Exception $e) {
            $this->logError('Check discounts failed', [
                'error' => $e->getMessage(),
                'user_email' => $_POST['email'] ?? '',
                'game_id' => $_POST['game_id'] ?? ''
            ]);

            return $this->jsonResponse([
                'success' => false,
                'error' => 'Failed to check available discounts'
            ]);
        }
    }

    /**
     * Apply discount to payment
     *
     * @return array<string, mixed>
     */
    public function applyDiscount(): array
    {
        try {
            if (!$this->validateCsrf()) {
                return $this->jsonResponse(['success' => false, 'error' => 'Invalid CSRF token'], 403);
            }

            $data = $this->validateRequired(['email', 'game_id', 'discount_type']);
            $userEmail = $data['email'];
            $gameId = (int) $data['game_id'];
            $discountType = $data['discount_type'];

            $game = $this->gameModel->find($gameId);
            if (!$game) {
                return $this->jsonResponse(['success' => false, 'error' => 'Game not found']);
            }

            $discountAction = $this->selectBestDiscount($userEmail, $discountType);
            if (!$discountAction) {
                return $this->jsonResponse(['success' => false, 'error' => 'No valid discount available']);
            }

            $calculation = $this->userActionModel->calculateDiscount(
                $game['entry_fee'],
                $discountAction['discount_amount']
            );

            // Store discount application in session for payment process
            Session::set('applied_discount', [
                'action_id' => $discountAction['id'],
                'discount_type' => $discountAction['action_type'],
                'discount_amount' => $discountAction['discount_amount'],
                'calculation' => $calculation,
                'applied_at' => time()
            ]);

            return $this->jsonResponse([
                'success' => true,
                'discount' => $discountAction,
                'calculation' => $calculation,
                'message' => 'Discount applied successfully'
            ]);

        } catch (Exception $e) {
            $this->logError('Apply discount failed', [
                'error' => $e->getMessage(),
                'user_email' => $_POST['email'] ?? '',
                'game_id' => $_POST['game_id'] ?? ''
            ]);

            return $this->jsonResponse([
                'success' => false,
                'error' => 'Failed to apply discount'
            ]);
        }
    }

    /**
     * Generate referral link for user
     *
     * @return array<string, mixed>
     */
    public function generateReferralLink(): array
    {
        try {
            $data = $this->validateRequired(['email', 'game_slug']);
            $userEmail = $data['email'];
            $gameSlug = $data['game_slug'];

            // Verify user has participated in a game (anti-spam measure)
            $hasParticipated = $this->participantModel->userHasParticipated($userEmail);
            if (!$hasParticipated) {
                return $this->jsonResponse([
                    'success' => false,
                    'error' => 'Must participate in a game before referring others'
                ]);
            }

            $referralUrl = $this->userActionModel->generateReferralUrl($userEmail, $gameSlug);
            $stats = $this->userActionModel->getReferralStats($userEmail);

            return $this->jsonResponse([
                'success' => true,
                'referral_url' => $referralUrl,
                'stats' => $stats,
                'sharing_message' => $this->generateSharingMessage($gameSlug)
            ]);

        } catch (Exception $e) {
            $this->logError('Generate referral link failed', [
                'error' => $e->getMessage(),
                'user_email' => $_POST['email'] ?? '',
                'game_slug' => $_POST['game_slug'] ?? ''
            ]);

            return $this->jsonResponse([
                'success' => false,
                'error' => 'Failed to generate referral link'
            ]);
        }
    }

    /**
     * Create replay discount after game completion
     *
     * @param int $participantId Participant who completed the game
     * @return bool Success status
     */
    public function createReplayDiscount(int $participantId): bool
    {
        try {
            $participant = $this->participantModel->find($participantId);
            if (!$participant || $participant['payment_status'] !== 'paid') {
                return false;
            }

            $this->userActionModel->createReplayDiscount(
                $participant['user_email'],
                $participant['round_id'],
                $participantId
            );

            // Log analytics event
            $this->logAnalyticsEvent('replay_discount_created', $participantId, [
                'round_id' => $participant['round_id'],
                'user_email' => $participant['user_email']
            ]);

            return true;

        } catch (Exception $e) {
            $this->logError('Create replay discount failed', [
                'error' => $e->getMessage(),
                'participant_id' => $participantId
            ]);

            return false;
        }
    }

    /**
     * Finalize discount application during payment
     *
     * @param int $participantId Participant ID
     * @return bool Success status
     */
    public function finalizeDiscountApplication(int $participantId): bool
    {
        try {
            $appliedDiscount = Session::get('applied_discount');
            if (!$appliedDiscount) {
                return true; // No discount to apply
            }

            // Check if discount is still valid
            $actionId = $appliedDiscount['action_id'];
            $discountAction = $this->userActionModel->find($actionId);

            if (!$discountAction || $discountAction['used_at']) {
                Session::forget('applied_discount');
                throw new Exception('Discount no longer valid');
            }

            // Apply discount to participant
            $success = $this->userActionModel->applyDiscount($actionId, $participantId);

            if ($success) {
                // Handle referral tracking if applicable
                if ($discountAction['action_type'] === UserAction::TYPE_REFERRAL) {
                    $this->handleReferralSuccess($discountAction, $participantId);
                }

                // Clear session
                Session::forget('applied_discount');

                // Log analytics event
                $this->logAnalyticsEvent('discount_applied', $participantId, [
                    'discount_type' => $discountAction['action_type'],
                    'discount_amount' => $discountAction['discount_amount'],
                    'action_id' => $actionId
                ]);
            }

            return $success;

        } catch (Exception $e) {
            $this->logError('Finalize discount application failed', [
                'error' => $e->getMessage(),
                'participant_id' => $participantId
            ]);

            return false;
        }
    }

    /**
     * Get user discount history
     *
     * @return array<string, mixed>
     */
    public function getUserDiscountHistory(): array
    {
        try {
            $userEmail = $_GET['email'] ?? '';
            if (empty($userEmail)) {
                return $this->jsonResponse(['success' => false, 'error' => 'Email required']);
            }

            $history = $this->userActionModel->getUserDiscountHistory($userEmail, 20);
            $stats = $this->userActionModel->getReferralStats($userEmail);

            return $this->jsonResponse([
                'success' => true,
                'history' => $history,
                'stats' => $stats
            ]);

        } catch (Exception $e) {
            $this->logError('Get user discount history failed', [
                'error' => $e->getMessage(),
                'user_email' => $_GET['email'] ?? ''
            ]);

            return $this->jsonResponse([
                'success' => false,
                'error' => 'Failed to retrieve discount history'
            ]);
        }
    }

    /**
     * Validate discount code (for manual/promotional codes)
     *
     * @return array<string, mixed>
     */
    public function validateDiscountCode(): array
    {
        try {
            $data = $this->validateRequired(['code', 'email', 'game_id']);
            $code = strtoupper(trim($data['code']));
            $userEmail = $data['email'];
            $gameId = (int) $data['game_id'];

            // Check if code exists and is valid
            $discountAction = $this->userActionModel->findByDiscountCode($code);

            if (!$discountAction) {
                return $this->jsonResponse([
                    'success' => false,
                    'error' => 'Invalid discount code'
                ]);
            }

            if ($discountAction['email'] !== $userEmail) {
                return $this->jsonResponse([
                    'success' => false,
                    'error' => 'Discount code not valid for this user'
                ]);
            }

            if ($discountAction['used_at']) {
                return $this->jsonResponse([
                    'success' => false,
                    'error' => 'Discount code already used'
                ]);
            }

            if ($discountAction['expires_at'] && strtotime($discountAction['expires_at']) < time()) {
                return $this->jsonResponse([
                    'success' => false,
                    'error' => 'Discount code has expired'
                ]);
            }

            $game = $this->gameModel->find($gameId);
            $calculation = $this->userActionModel->calculateDiscount(
                $game['entry_fee'],
                $discountAction['discount_amount']
            );

            return $this->jsonResponse([
                'success' => true,
                'discount' => $discountAction,
                'calculation' => $calculation
            ]);

        } catch (Exception $e) {
            $this->logError('Validate discount code failed', [
                'error' => $e->getMessage(),
                'code' => $_POST['code'] ?? '',
                'user_email' => $_POST['email'] ?? ''
            ]);

            return $this->jsonResponse([
                'success' => false,
                'error' => 'Failed to validate discount code'
            ]);
        }
    }

    /**
     * Get all available discounts for user
     *
     * @param string $userEmail User email
     * @param int $gameId Game ID
     * @return array<string, mixed>
     */
    private function getAvailableDiscounts(string $userEmail, int $gameId): array
    {
        $discounts = [
            'replay' => null,
            'referral' => null,
            'best' => null,
            'pending_referral' => null
        ];

        // Check for replay discount
        $replayDiscount = $this->userActionModel->getActiveReplayDiscount($userEmail);
        if ($replayDiscount) {
            $discounts['replay'] = $replayDiscount;
        }

        // Check for pending referral from session
        $pendingReferral = Session::get('pending_referral');
        $validatedReferral = Session::get('validated_referral');

        if ($pendingReferral && !$validatedReferral) {
            $validation = $this->userActionModel->validateReferralCode(
                $pendingReferral,
                $userEmail,
                $this->getClientIp()
            );

            if ($validation['valid']) {
                $discounts['pending_referral'] = $validation;
                // Store validated referral
                Session::set('validated_referral', [
                    'referrer_email' => $validation['referrer_email'],
                    'discount_amount' => $validation['discount_amount'],
                    'validated_at' => time()
                ]);
            }
        } elseif ($validatedReferral) {
            $discounts['pending_referral'] = $validatedReferral;
        }

        // Get best available discount
        $bestDiscount = $this->userActionModel->getBestAvailableDiscount($userEmail);
        if ($bestDiscount) {
            $discounts['best'] = $bestDiscount;
        }

        return $discounts;
    }

    /**
     * Select best discount based on priority
     *
     * @param string $userEmail User email
     * @param string $requestedType Requested discount type
     * @return array<string, mixed>|null
     */
    private function selectBestDiscount(string $userEmail, string $requestedType): ?array
    {
        switch ($requestedType) {
            case 'replay':
                return $this->userActionModel->getActiveReplayDiscount($userEmail);

            case 'referral':
                $validatedReferral = Session::get('validated_referral');
                if ($validatedReferral) {
                    // Create referral discount action
                    $actionIds = $this->userActionModel->createReferralDiscount(
                        $validatedReferral['referrer_email'],
                        $userEmail
                    );
                    return $this->userActionModel->find($actionIds['referee']);
                }
                return null;

            case 'best':
            default:
                return $this->userActionModel->getBestAvailableDiscount($userEmail);
        }
    }

    /**
     * Handle successful referral conversion
     *
     * @param array<string, mixed> $discountAction Discount action
     * @param int $participantId Participant ID
     * @return void
     */
    private function handleReferralSuccess(array $discountAction, int $participantId): void
    {
        $validatedReferral = Session::get('validated_referral');
        if ($validatedReferral) {
            // Track conversion
            $this->userActionModel->trackReferralConversion(
                $validatedReferral['referrer_email'],
                $participantId
            );

            // Clear referral from session
            Session::forget('validated_referral');
            Session::forget('pending_referral');

            // Log analytics event
            $this->logAnalyticsEvent('referral_conversion', $participantId, [
                'referrer_email' => $validatedReferral['referrer_email'],
                'discount_amount' => $discountAction['discount_amount']
            ]);
        }
    }

    /**
     * Generate sharing message for referral
     *
     * @param string $gameSlug Game slug
     * @return string
     */
    private function generateSharingMessage(string $gameSlug): string
    {
        $messages = [
            'win-a-iphone-15-pro' => "🎉 Win the latest iPhone 15 Pro! I found this amazing competition - we both get 10% off when you join using my link!",
            'win-a-macbook-air-m3' => "💻 Want to win a MacBook Air M3? Join this competition with my referral link and we both save 10%!",
            'win-a-ps5-pro' => "🎮 PlayStation 5 Pro up for grabs! Use my link to join and we both get a discount!",
            'default' => "🎯 Amazing competition alert! Join with my link and we both get 10% off our entry!"
        ];

        return $messages[$gameSlug] ?? $messages['default'];
    }

    /**
     * Log analytics event
     *
     * @param string $eventType Event type
     * @param int $participantId Participant ID
     * @param array<string, mixed> $additionalData Additional data
     * @return void
     */
    private function logAnalyticsEvent(string $eventType, int $participantId, array $additionalData = []): void
    {
        try {
            $participant = $this->participantModel->find($participantId);
            if (!$participant) return;

            $eventData = [
                'event_type' => $eventType,
                'participant_id' => $participantId,
                'round_id' => $participant['round_id'],
                'ip_address' => $this->getClientIp(),
                'session_id' => Session::getId(),
                'additional_data_json' => json_encode($additionalData)
            ];

            // Insert analytics event (this would typically use an Analytics model)
            $query = "
                INSERT INTO analytics_events
                (event_type, participant_id, round_id, ip_address, session_id, additional_data_json)
                VALUES (?, ?, ?, ?, ?, ?)
            ";

            Database::execute($query, [
                $eventData['event_type'],
                $eventData['participant_id'],
                $eventData['round_id'],
                $eventData['ip_address'],
                $eventData['session_id'],
                $eventData['additional_data_json']
            ]);

        } catch (Exception $e) {
            $this->logError('Analytics event logging failed', [
                'error' => $e->getMessage(),
                'event_type' => $eventType,
                'participant_id' => $participantId
            ]);
        }
    }
}
