<?php
/**
 * Win A Brand New - API Response Controller
 * File: /controllers/ApiController.php
 *
 * Handles RESTful API responses and AJAX request handling according to Development Specification.
 * Implements JSON response formatting, error handling, status codes, and CORS management
 * for frontend AJAX calls.
 *
 * @package WinABrandNew
 * @author Win A Brand New Development Team
 * @version 1.0.0
 */

namespace WinABrandNew\Controllers;

use WinABrandNew\Core\Database;
use WinABrandNew\Models\Game;
use WinABrandNew\Models\Participant;
use WinABrandNew\Models\Round;
use WinABrandNew\Models\Question;
use WinABrandNew\Models\Payment;
use WinABrandNew\Services\CurrencyService;
use WinABrandNew\Services\CacheService;
use Exception;

class ApiController extends BaseController
{
    /**
     * API version
     */
    private const API_VERSION = 'v1';

    /**
     * API response error codes
     */
    private const ERROR_CODES = [
        'INVALID_REQUEST' => 'API001',
        'RESOURCE_NOT_FOUND' => 'API002',
        'UNAUTHORIZED' => 'API003',
        'FORBIDDEN' => 'API004',
        'VALIDATION_ERROR' => 'API005',
        'RATE_LIMIT_EXCEEDED' => 'API006',
        'INTERNAL_ERROR' => 'API007',
        'MAINTENANCE_MODE' => 'API008'
    ];

    /**
     * Allowed origins for CORS
     */
    private array $allowedOrigins = [
        'https://winabrandnew.com',
        'https://www.winabrandnew.com',
        'https://dev.winabrandnew.com'
    ];

    /**
     * Currency service instance
     */
    private CurrencyService $currencyService;

    /**
     * Cache service instance
     */
    private CacheService $cacheService;

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();

        $this->currencyService = new CurrencyService();
        $this->cacheService = new CacheService();

        // Enable CORS for API endpoints
        $this->handleCors();
    }

    /**
     * Handle CORS preflight and headers
     *
     * @return void
     */
    private function handleCors(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        // Check if origin is allowed
        if (in_array($origin, $this->allowedOrigins) || $this->isLocalDevelopment()) {
            header("Access-Control-Allow-Origin: {$origin}");
        }

        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token');
        header('Access-Control-Max-Age: 86400'); // 24 hours

        // Handle preflight OPTIONS request
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }

    /**
     * Check if running in local development environment
     *
     * @return bool
     */
    private function isLocalDevelopment(): bool
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        return strpos($host, 'localhost') !== false ||
               strpos($host, '127.0.0.1') !== false ||
               strpos($host, '.local') !== false;
    }

    /**
     * Get list of available games
     * GET /api/v1/games
     *
     * @return void
     */
    public function games(): void
    {
        try {
            $cacheKey = 'api_games_list';
            $games = $this->cacheService->get($cacheKey);

            if ($games === null) {
                $gameModel = new Game();
                $games = $gameModel->getActiveGames();

                // Transform games for API response
                $games = array_map(function($game) {
                    return [
                        'id' => $game['id'],
                        'slug' => $game['slug'],
                        'title' => $game['title'],
                        'description' => $game['description'],
                        'prize_description' => $game['prize_description'],
                        'price' => [
                            'amount' => $game['price'],
                            'currency' => $game['currency'],
                            'formatted' => $this->currencyService->formatPrice(
                                $game['price'],
                                $game['currency']
                            )
                        ],
                        'active_round' => $game['active_round_id'] ? [
                            'id' => $game['active_round_id'],
                            'participants' => $game['participant_count'] ?? 0,
                            'status' => $game['round_status'] ?? 'pending'
                        ] : null,
                        'created_at' => $game['created_at'],
                        'is_active' => $game['is_active']
                    ];
                }, $games);

                // Cache for 5 minutes
                $this->cacheService->set($cacheKey, $games, 300);
            }

            $this->apiResponse([
                'games' => $games,
                'total' => count($games)
            ]);

        } catch (Exception $e) {
            $this->logError('API games endpoint error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->apiError(
                'Failed to retrieve games',
                500,
                self::ERROR_CODES['INTERNAL_ERROR']
            );
        }
    }

    /**
     * Get game details by slug
     * GET /api/v1/games/{slug}
     *
     * @param string $slug Game slug
     * @return void
     */
    public function gameDetails(string $slug): void
    {
        try {
            $cacheKey = "api_game_details_{$slug}";
            $gameData = $this->cacheService->get($cacheKey);

            if ($gameData === null) {
                $gameModel = new Game();
                $game = $gameModel->getBySlug($slug);

                if (!$game) {
                    $this->apiError(
                        'Game not found',
                        404,
                        self::ERROR_CODES['RESOURCE_NOT_FOUND']
                    );
                    return;
                }

                // Get active round details
                $roundModel = new Round();
                $activeRound = $roundModel->getActiveRoundForGame($game['id']);

                // Get participant count
                $participantModel = new Participant();
                $participantCount = $activeRound ?
                    $participantModel->getCountForRound($activeRound['id']) : 0;

                $gameData = [
                    'id' => $game['id'],
                    'slug' => $game['slug'],
                    'title' => $game['title'],
                    'description' => $game['description'],
                    'prize_description' => $game['prize_description'],
                    'image_url' => $game['image_url'],
                    'price' => [
                        'amount' => $game['price'],
                        'currency' => $game['currency'],
                        'formatted' => $this->currencyService->formatPrice(
                            $game['price'],
                            $game['currency']
                        )
                    ],
                    'active_round' => $activeRound ? [
                        'id' => $activeRound['id'],
                        'participants' => $participantCount,
                        'status' => $activeRound['status'],
                        'created_at' => $activeRound['created_at']
                    ] : null,
                    'rules' => [
                        'max_questions' => $game['max_questions'] ?? 10,
                        'time_limit' => $game['time_limit'] ?? 10,
                        'replay_discount' => 10, // 10% as per spec
                        'bundle_pricing' => '5 for 4' // As per spec
                    ],
                    'created_at' => $game['created_at'],
                    'is_active' => $game['is_active']
                ];

                // Cache for 2 minutes
                $this->cacheService->set($cacheKey, $gameData, 120);
            }

            $this->apiResponse($gameData);

        } catch (Exception $e) {
            $this->logError('API game details endpoint error', [
                'slug' => $slug,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->apiError(
                'Failed to retrieve game details',
                500,
                self::ERROR_CODES['INTERNAL_ERROR']
            );
        }
    }

    /**
     * Get round statistics
     * GET /api/v1/rounds/{roundId}/stats
     *
     * @param int $roundId Round ID
     * @return void
     */
    public function roundStats(int $roundId): void
    {
        try {
            $cacheKey = "api_round_stats_{$roundId}";
            $stats = $this->cacheService->get($cacheKey);

            if ($stats === null) {
                $roundModel = new Round();
                $round = $roundModel->getById($roundId);

                if (!$round) {
                    $this->apiError(
                        'Round not found',
                        404,
                        self::ERROR_CODES['RESOURCE_NOT_FOUND']
                    );
                    return;
                }

                $participantModel = new Participant();
                $participants = $participantModel->getByRoundId($roundId);

                $stats = [
                    'round_id' => $roundId,
                    'status' => $round['status'],
                    'participants' => [
                        'total' => count($participants),
                        'completed' => count(array_filter($participants, fn($p) => $p['status'] === 'completed')),
                        'in_progress' => count(array_filter($participants, fn($p) => $p['status'] === 'in_progress')),
                        'failed' => count(array_filter($participants, fn($p) => $p['status'] === 'failed'))
                    ],
                    'winner' => $round['winner_id'] ? [
                        'participant_id' => $round['winner_id'],
                        'completion_time' => $round['winner_time'],
                        'announced_at' => $round['completed_at']
                    ] : null,
                    'created_at' => $round['created_at'],
                    'completed_at' => $round['completed_at']
                ];

                // Cache for 30 seconds (stats change frequently)
                $this->cacheService->set($cacheKey, $stats, 30);
            }

            $this->apiResponse($stats);

        } catch (Exception $e) {
            $this->logError('API round stats endpoint error', [
                'round_id' => $roundId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->apiError(
                'Failed to retrieve round statistics',
                500,
                self::ERROR_CODES['INTERNAL_ERROR']
            );
        }
    }

    /**
     * Get participant progress
     * GET /api/v1/participant/{participantId}/progress
     *
     * @param int $participantId Participant ID
     * @return void
     */
    public function participantProgress(int $participantId): void
    {
        try {
            // Verify participant exists and belongs to current session
            $participantModel = new Participant();
            $participant = $participantModel->getById($participantId);

            if (!$participant) {
                $this->apiError(
                    'Participant not found',
                    404,
                    self::ERROR_CODES['RESOURCE_NOT_FOUND']
                );
                return;
            }

            // Security check: ensure participant belongs to current session
            if ($participant['session_id'] !== session_id()) {
                $this->apiError(
                    'Unauthorized access to participant data',
                    403,
                    self::ERROR_CODES['FORBIDDEN']
                );
                return;
            }

            $progress = [
                'participant_id' => $participant['id'],
                'round_id' => $participant['round_id'],
                'status' => $participant['status'],
                'current_question' => $participant['current_question'],
                'questions_answered' => $participant['questions_answered'],
                'total_questions' => $participant['total_questions'],
                'completion_time' => $participant['completion_time'],
                'payment_status' => $participant['payment_status'],
                'created_at' => $participant['created_at'],
                'completed_at' => $participant['completed_at']
            ];

            $this->apiResponse($progress);

        } catch (Exception $e) {
            $this->logError('API participant progress endpoint error', [
                'participant_id' => $participantId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->apiError(
                'Failed to retrieve participant progress',
                500,
                self::ERROR_CODES['INTERNAL_ERROR']
            );
        }
    }

    /**
     * Health check endpoint
     * GET /api/v1/health
     *
     * @return void
     */
    public function health(): void
    {
        try {
            $health = [
                'status' => 'healthy',
                'timestamp' => date('c'),
                'version' => self::API_VERSION,
                'services' => [
                    'database' => $this->checkDatabaseHealth(),
                    'cache' => $this->checkCacheHealth(),
                    'payment' => $this->checkPaymentHealth()
                ]
            ];

            $overallStatus = 'healthy';
            foreach ($health['services'] as $service) {
                if ($service['status'] !== 'healthy') {
                    $overallStatus = 'degraded';
                    break;
                }
            }

            $health['status'] = $overallStatus;
            $httpCode = $overallStatus === 'healthy' ? 200 : 503;

            $this->apiResponse($health, $httpCode);

        } catch (Exception $e) {
            $this->apiError(
                'Health check failed',
                503,
                self::ERROR_CODES['INTERNAL_ERROR']
            );
        }
    }

    /**
     * Check database health
     *
     * @return array
     */
    private function checkDatabaseHealth(): array
    {
        try {
            $db = Database::getInstance();
            $result = $db->query("SELECT 1");

            return [
                'status' => 'healthy',
                'response_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']
            ];
        } catch (Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => 'Database connection failed'
            ];
        }
    }

    /**
     * Check cache health
     *
     * @return array
     */
    private function checkCacheHealth(): array
    {
        try {
            $testKey = 'health_check_' . time();
            $this->cacheService->set($testKey, 'test', 5);
            $value = $this->cacheService->get($testKey);

            return [
                'status' => $value === 'test' ? 'healthy' : 'unhealthy',
                'response_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']
            ];
        } catch (Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => 'Cache service failed'
            ];
        }
    }

    /**
     * Check payment service health
     *
     * @return array
     */
    private function checkPaymentHealth(): array
    {
        try {
            // Simple check to see if Mollie API key is configured
            $mollieKey = $_ENV['MOLLIE_API_KEY'] ?? '';

            return [
                'status' => !empty($mollieKey) ? 'healthy' : 'unhealthy',
                'configured' => !empty($mollieKey)
            ];
        } catch (Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => 'Payment service check failed'
            ];
        }
    }

    /**
     * Send successful API response
     *
     * @param array|null $data Response data
     * @param int $statusCode HTTP status code
     * @param array $headers Additional headers
     * @return void
     */
    private function apiResponse(?array $data = null, int $statusCode = 200, array $headers = []): void
    {
        $response = [
            'success' => true,
            'data' => $data,
            'meta' => [
                'api_version' => self::API_VERSION,
                'timestamp' => date('c'),
                'execution_time' => round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 4)
            ]
        ];

        $this->jsonResponse($response, $statusCode, $headers);
    }

    /**
     * Send API error response
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status code
     * @param string $errorCode Internal error code
     * @param array $details Additional error details
     * @return void
     */
    private function apiError(string $message, int $statusCode = 400, string $errorCode = '', array $details = []): void
    {
        $response = [
            'success' => false,
            'error' => [
                'message' => $message,
                'code' => $errorCode,
                'details' => $details
            ],
            'meta' => [
                'api_version' => self::API_VERSION,
                'timestamp' => date('c'),
                'execution_time' => round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 4)
            ]
        ];

        // Log error for monitoring
        $this->logError('API Error Response', [
            'status_code' => $statusCode,
            'error_code' => $errorCode,
            'message' => $message,
            'details' => $details,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'ip' => $this->getClientIp()
        ]);

        $this->jsonResponse($response, $statusCode);
    }

    /**
     * Log error with context
     *
     * @param string $message Log message
     * @param array $context Additional context
     * @return void
     */
    private function logError(string $message, array $context = []): void
    {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => 'ERROR',
            'message' => $message,
            'context' => $context,
            'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? uniqid()
        ];

        error_log(json_encode($logEntry));
    }
}
