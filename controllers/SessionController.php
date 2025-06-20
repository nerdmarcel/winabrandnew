<?php

/**
 * Win a Brand New - Session Management Controller
 * File: /controllers/SessionController.php
 *
 * Handles session management, device continuity, cross-device detection,
 * and security enforcement according to the Development Specification.
 *
 * Key Features:
 * - Device fingerprint validation for continuity enforcement
 * - Session persistence and security monitoring
 * - Cross-device detection and prevention
 * - Session hijacking protection
 * - Device continuity validation during game flow
 * - Security event logging for audit trail
 * - Secure session lifecycle management
 * - Anti-fraud measures through device tracking
 *
 * Critical Security Features:
 * - Device fingerprinting to prevent cross-device game completion
 * - Session ID regeneration for fixation prevention
 * - IP address and User-Agent validation
 * - Session timeout enforcement
 * - Device mismatch detection and game termination
 * - Security event logging with detailed context
 *
 * @package WinABrandNew\Controllers
 * @version 1.0.0
 * @author NerdMarcel Development Team
 */

namespace WinABrandNew\Controllers;

use WinABrandNew\Controllers\BaseController;
use WinABrandNew\Core\Security;
use WinABrandNew\Core\Database;
use WinABrandNew\Models\Participant;
use Exception;

class SessionController extends BaseController
{
    /**
     * Session timeout in seconds (30 minutes)
     */
    private const SESSION_TIMEOUT = 1800;

    /**
     * Device fingerprint session key
     */
    private const DEVICE_FINGERPRINT_KEY = 'device_fingerprint';

    /**
     * Game session data key
     */
    private const GAME_SESSION_KEY = 'game_session';

    /**
     * Security validation keys
     */
    private const SECURITY_IP_KEY = 'security_ip';
    private const SECURITY_USER_AGENT_KEY = 'security_user_agent';
    private const SECURITY_LAST_ACTIVITY_KEY = 'security_last_activity';
    private const SECURITY_CREATED_KEY = 'security_created';

    /**
     * Initialize secure session for game participation
     *
     * @return array Session initialization result
     */
    public function initializeGameSession(): array
    {
        try {
            // Start secure session
            Security::startSecureSession();

            // Generate device fingerprint from request
            $deviceFingerprint = $this->generateDeviceFingerprint();

            // Initialize session security markers
            $this->initializeSecurityMarkers();

            // Store device fingerprint
            $this->setSession(self::DEVICE_FINGERPRINT_KEY, $deviceFingerprint);

            // Initialize game session data
            $gameSessionData = [
                'started_at' => microtime(true),
                'device_fingerprint' => $deviceFingerprint,
                'session_id' => session_id(),
                'ip_address' => $this->getUserIP(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'questions_completed' => 0,
                'current_question' => 0,
                'timing_data' => [],
                'security_flags' => []
            ];

            $this->setSession(self::GAME_SESSION_KEY, $gameSessionData);

            // Log session initialization
            $this->logSecurityEvent('session_initialized', [
                'device_fingerprint' => $deviceFingerprint,
                'session_id' => session_id()
            ]);

            return [
                'success' => true,
                'session_id' => session_id(),
                'device_fingerprint' => $deviceFingerprint,
                'timestamp' => microtime(true)
            ];

        } catch (Exception $e) {
            $this->logSecurityEvent('session_initialization_failed', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to initialize secure session',
                'code' => 'SESSION_INIT_FAILED'
            ];
        }
    }

    /**
     * Validate device continuity for ongoing game session
     *
     * @param int|null $participantId Participant ID for validation
     * @return array Validation result
     */
    public function validateDeviceContinuity(?int $participantId = null): array
    {
        try {
            // Ensure session is active
            if (session_status() !== PHP_SESSION_ACTIVE) {
                throw new Exception("No active session found", 401);
            }

            // Get current device fingerprint
            $currentFingerprint = $this->generateDeviceFingerprint();
            $storedFingerprint = $this->getSession(self::DEVICE_FINGERPRINT_KEY);

            // Validate device fingerprint continuity
            if (!$storedFingerprint) {
                throw new Exception("Device fingerprint not found in session", 403);
            }

            if ($currentFingerprint !== $storedFingerprint) {
                $this->flagSecurityViolation('device_mismatch', [
                    'stored_fingerprint' => $storedFingerprint,
                    'current_fingerprint' => $currentFingerprint,
                    'participant_id' => $participantId
                ]);

                throw new Exception("Game must be completed on the same device", 403);
            }

            // Validate session security markers
            $this->validateSessionSecurity();

            // Update last activity
            $this->updateSessionActivity();

            // If participant ID provided, validate against participant record
            if ($participantId) {
                $this->validateParticipantDeviceContinuity($participantId, $currentFingerprint);
            }

            $this->logSecurityEvent('device_continuity_validated', [
                'participant_id' => $participantId,
                'device_fingerprint' => $currentFingerprint
            ]);

            return [
                'success' => true,
                'device_fingerprint' => $currentFingerprint,
                'session_valid' => true,
                'timestamp' => microtime(true)
            ];

        } catch (Exception $e) {
            $this->logSecurityEvent('device_continuity_failed', [
                'error' => $e->getMessage(),
                'participant_id' => $participantId
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'code' => 'DEVICE_CONTINUITY_FAILED',
                'http_code' => $e->getCode() ?: 403
            ];
        }
    }

    /**
     * Detect and prevent cross-device usage
     *
     * @param string $gameSlug Game identifier
     * @param string $userEmail User email for tracking
     * @return array Detection result
     */
    public function detectCrossDeviceUsage(string $gameSlug, string $userEmail): array
    {
        try {
            $currentFingerprint = $this->generateDeviceFingerprint();
            $currentIP = $this->getUserIP();

            // Check for recent sessions from same user with different devices
            $db = Database::getInstance();

            $query = "
                SELECT DISTINCT
                    sl.device_fingerprint,
                    sl.ip_address,
                    sl.created_at,
                    sl.event_data
                FROM security_log sl
                WHERE sl.event_type = 'session_initialized'
                AND JSON_EXTRACT(sl.event_data, '$.user_email') = ?
                AND sl.created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                AND sl.device_fingerprint != ?
                ORDER BY sl.created_at DESC
                LIMIT 10
            ";

            $stmt = $db->prepare($query);
            $stmt->execute([$userEmail, $currentFingerprint]);
            $recentSessions = $stmt->fetchAll();

            $crossDeviceDetected = !empty($recentSessions);

            if ($crossDeviceDetected) {
                $this->flagSecurityViolation('cross_device_detected', [
                    'user_email' => $userEmail,
                    'game_slug' => $gameSlug,
                    'current_device' => $currentFingerprint,
                    'current_ip' => $currentIP,
                    'recent_sessions' => array_map(function($session) {
                        return [
                            'device_fingerprint' => $session['device_fingerprint'],
                            'ip_address' => $session['ip_address'],
                            'timestamp' => $session['created_at']
                        ];
                    }, $recentSessions)
                ]);

                return [
                    'success' => false,
                    'cross_device_detected' => true,
                    'error' => 'Multiple device usage detected for this account',
                    'code' => 'CROSS_DEVICE_VIOLATION',
                    'recent_devices' => count($recentSessions)
                ];
            }

            return [
                'success' => true,
                'cross_device_detected' => false,
                'device_fingerprint' => $currentFingerprint,
                'timestamp' => microtime(true)
            ];

        } catch (Exception $e) {
            $this->logSecurityEvent('cross_device_detection_failed', [
                'error' => $e->getMessage(),
                'user_email' => $userEmail,
                'game_slug' => $gameSlug
            ]);

            return [
                'success' => false,
                'error' => 'Failed to perform cross-device detection',
                'code' => 'DETECTION_FAILED'
            ];
        }
    }

    /**
     * Update game session progress and timing
     *
     * @param int $questionNumber Current question number
     * @param float $questionTime Time spent on question
     * @param array $additionalData Additional session data
     * @return array Update result
     */
    public function updateGameProgress(int $questionNumber, float $questionTime, array $additionalData = []): array
    {
        try {
            // Validate device continuity first
            $validationResult = $this->validateDeviceContinuity();
            if (!$validationResult['success']) {
                return $validationResult;
            }

            $gameSession = $this->getSession(self::GAME_SESSION_KEY);
            if (!$gameSession) {
                throw new Exception("No active game session found", 404);
            }

            // Update session data
            $gameSession['questions_completed'] = $questionNumber;
            $gameSession['current_question'] = $questionNumber + 1;
            $gameSession['timing_data'][$questionNumber] = $questionTime;
            $gameSession['last_update'] = microtime(true);

            // Merge additional data
            $gameSession = array_merge($gameSession, $additionalData);

            $this->setSession(self::GAME_SESSION_KEY, $gameSession);

            $this->logSecurityEvent('game_progress_updated', [
                'question_number' => $questionNumber,
                'question_time' => $questionTime,
                'total_questions_completed' => $questionNumber
            ]);

            return [
                'success' => true,
                'question_number' => $questionNumber,
                'questions_completed' => $questionNumber,
                'session_valid' => true,
                'timestamp' => microtime(true)
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'code' => 'PROGRESS_UPDATE_FAILED'
            ];
        }
    }

    /**
     * Terminate session due to security violation
     *
     * @param string $reason Termination reason
     * @param array $context Additional context
     * @return array Termination result
     */
    public function terminateSession(string $reason, array $context = []): array
    {
        try {
            $sessionId = session_id();
            $deviceFingerprint = $this->getSession(self::DEVICE_FINGERPRINT_KEY);

            // Log termination
            $this->logSecurityEvent('session_terminated', [
                'reason' => $reason,
                'context' => $context,
                'session_id' => $sessionId,
                'device_fingerprint' => $deviceFingerprint
            ]);

            // Destroy session
            $this->destroySession();

            return [
                'success' => true,
                'reason' => $reason,
                'timestamp' => microtime(true)
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to terminate session safely',
                'code' => 'TERMINATION_FAILED'
            ];
        }
    }

    /**
     * Get current session status and health
     *
     * @return array Session status information
     */
    public function getSessionStatus(): array
    {
        try {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                return [
                    'active' => false,
                    'status' => 'No active session'
                ];
            }

            $gameSession = $this->getSession(self::GAME_SESSION_KEY);
            $deviceFingerprint = $this->getSession(self::DEVICE_FINGERPRINT_KEY);
            $lastActivity = $this->getSession(self::SECURITY_LAST_ACTIVITY_KEY);
            $sessionCreated = $this->getSession(self::SECURITY_CREATED_KEY);

            $sessionAge = $sessionCreated ? (time() - $sessionCreated) : null;
            $timeSinceActivity = $lastActivity ? (time() - $lastActivity) : null;

            return [
                'active' => true,
                'session_id' => session_id(),
                'device_fingerprint' => $deviceFingerprint,
                'game_session' => $gameSession ? true : false,
                'session_age_seconds' => $sessionAge,
                'time_since_activity' => $timeSinceActivity,
                'expires_in' => $lastActivity ? (self::SESSION_TIMEOUT - $timeSinceActivity) : null,
                'security_status' => [
                    'ip_valid' => $this->validateCurrentIP(),
                    'user_agent_valid' => $this->validateCurrentUserAgent(),
                    'device_fingerprint_valid' => $this->validateCurrentDeviceFingerprint()
                ],
                'timestamp' => microtime(true)
            ];

        } catch (Exception $e) {
            return [
                'active' => false,
                'error' => $e->getMessage(),
                'status' => 'Session status check failed'
            ];
        }
    }

    /**
     * Generate device fingerprint for unique device identification
     *
     * @return string Device fingerprint hash
     */
    private function generateDeviceFingerprint(): string
    {
        $components = [
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
            $_SERVER['HTTP_ACCEPT'] ?? '',
            $this->getUserIP(),
            $_SERVER['SERVER_NAME'] ?? '',
            $_SERVER['SERVER_PORT'] ?? ''
        ];

        // Add client-side fingerprint if available (from JavaScript)
        $clientFingerprint = $_POST['client_fingerprint'] ?? $_GET['client_fingerprint'] ?? '';
        if ($clientFingerprint) {
            $components[] = $clientFingerprint;
        }

        $fingerprintString = implode('|', $components);
        return hash('sha256', $fingerprintString);
    }

    /**
     * Initialize session security markers
     *
     * @return void
     */
    private function initializeSecurityMarkers(): void
    {
        $currentTime = time();

        $this->setSession(self::SECURITY_IP_KEY, $this->getUserIP());
        $this->setSession(self::SECURITY_USER_AGENT_KEY, $_SERVER['HTTP_USER_AGENT'] ?? '');
        $this->setSession(self::SECURITY_LAST_ACTIVITY_KEY, $currentTime);
        $this->setSession(self::SECURITY_CREATED_KEY, $currentTime);
    }

    /**
     * Validate session security markers against current request
     *
     * @return void
     * @throws Exception If validation fails
     */
    private function validateSessionSecurity(): void
    {
        $storedIP = $this->getSession(self::SECURITY_IP_KEY);
        $storedUserAgent = $this->getSession(self::SECURITY_USER_AGENT_KEY);
        $lastActivity = $this->getSession(self::SECURITY_LAST_ACTIVITY_KEY);

        $currentIP = $this->getUserIP();
        $currentUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Validate IP address
        if ($storedIP && $storedIP !== $currentIP) {
            throw new Exception("Session hijacking detected - IP address mismatch", 403);
        }

        // Validate User Agent
        if ($storedUserAgent && $storedUserAgent !== $currentUserAgent) {
            throw new Exception("Session hijacking detected - User Agent mismatch", 403);
        }

        // Validate session timeout
        if ($lastActivity && (time() - $lastActivity) > self::SESSION_TIMEOUT) {
            throw new Exception("Session expired", 401);
        }
    }

    /**
     * Update session activity timestamp
     *
     * @return void
     */
    private function updateSessionActivity(): void
    {
        $this->setSession(self::SECURITY_LAST_ACTIVITY_KEY, time());
    }

    /**
     * Validate participant device continuity against database record
     *
     * @param int $participantId Participant ID
     * @param string $currentFingerprint Current device fingerprint
     * @return void
     * @throws Exception If validation fails
     */
    private function validateParticipantDeviceContinuity(int $participantId, string $currentFingerprint): void
    {
        $db = Database::getInstance();

        $query = "
            SELECT device_fingerprint, session_id
            FROM participants
            WHERE id = ?
            LIMIT 1
        ";

        $stmt = $db->prepare($query);
        $stmt->execute([$participantId]);
        $participant = $stmt->fetch();

        if (!$participant) {
            throw new Exception("Participant not found", 404);
        }

        if ($participant['device_fingerprint'] !== $currentFingerprint) {
            throw new Exception("Device fingerprint mismatch with participant record", 403);
        }

        if ($participant['session_id'] !== session_id()) {
            throw new Exception("Session ID mismatch with participant record", 403);
        }
    }

    /**
     * Flag security violation and log incident
     *
     * @param string $violationType Type of violation
     * @param array $context Violation context
     * @return void
     */
    private function flagSecurityViolation(string $violationType, array $context = []): void
    {
        $this->logSecurityEvent('security_violation', [
            'violation_type' => $violationType,
            'context' => $context,
            'severity' => 'high'
        ]);

        // Store violation in session for tracking
        $gameSession = $this->getSession(self::GAME_SESSION_KEY) ?? [];
        $gameSession['security_flags'][] = [
            'type' => $violationType,
            'timestamp' => microtime(true),
            'context' => $context
        ];
        $this->setSession(self::GAME_SESSION_KEY, $gameSession);
    }

    /**
     * Validate current IP against stored IP
     *
     * @return bool Validation result
     */
    private function validateCurrentIP(): bool
    {
        $storedIP = $this->getSession(self::SECURITY_IP_KEY);
        $currentIP = $this->getUserIP();

        return $storedIP === $currentIP;
    }

    /**
     * Validate current User Agent against stored User Agent
     *
     * @return bool Validation result
     */
    private function validateCurrentUserAgent(): bool
    {
        $storedUserAgent = $this->getSession(self::SECURITY_USER_AGENT_KEY);
        $currentUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        return $storedUserAgent === $currentUserAgent;
    }

    /**
     * Validate current device fingerprint against stored fingerprint
     *
     * @return bool Validation result
     */
    private function validateCurrentDeviceFingerprint(): bool
    {
        $storedFingerprint = $this->getSession(self::DEVICE_FINGERPRINT_KEY);
        $currentFingerprint = $this->generateDeviceFingerprint();

        return $storedFingerprint === $currentFingerprint;
    }
}
