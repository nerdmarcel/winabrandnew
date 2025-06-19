<?php

/**
 * Win a Brand New - Core Router Class
 * File: /core/Router.php
 *
 * URL routing and request handling system for the Win a Brand New application.
 * Handles all public routes, admin routes, and webhook endpoints according to
 * the Development Specification API endpoints.
 *
 * Features:
 * - RESTful routing with HTTP method support (GET, POST, PUT, DELETE, etc.)
 * - Dynamic route parameters with validation
 * - Route middleware support for authentication and security
 * - Admin route protection with role-based access
 * - Webhook route handling with signature verification
 * - CSRF protection integration
 * - Request/Response handling with proper HTTP status codes
 * - Route caching for performance optimization
 *
 * @package WinABrandNew\Core
 * @version 1.0.0
 * @author NerdMarcel Development Team
 */

namespace WinABrandNew\Core;

use Exception;
use WinABrandNew\Core\Security;

class Router
{
    /**
     * Registered routes array
     *
     * @var array
     */
    private static array $routes = [
        'GET' => [],
        'POST' => [],
        'PUT' => [],
        'DELETE' => [],
        'PATCH' => [],
        'OPTIONS' => [],
        'HEAD' => []
    ];

    /**
     * Route middleware stack
     *
     * @var array
     */
    private static array $middleware = [];

    /**
     * Route groups for organization
     *
     * @var array
     */
    private static array $groups = [];

    /**
     * Current route group prefix
     *
     * @var string
     */
    private static string $currentGroupPrefix = '';

    /**
     * Current middleware stack
     *
     * @var array
     */
    private static array $currentMiddleware = [];

    /**
     * Base path for application
     *
     * @var string
     */
    private static string $basePath = '';

    /**
     * Matched route parameters
     *
     * @var array
     */
    private static array $parameters = [];

    /**
     * Route cache for performance
     *
     * @var array
     */
    private static array $routeCache = [];

    /**
     * Error handlers
     *
     * @var array
     */
    private static array $errorHandlers = [
        404 => null,
        405 => null,
        500 => null
    ];

    /**
     * Initialize router with base configuration
     *
     * @param string $basePath Base path for application
     * @return void
     */
    public static function initialize(string $basePath = ''): void
    {
        self::$basePath = rtrim($basePath, '/');

        // Register default error handlers
        self::registerDefaultErrorHandlers();

        // Enable route caching in production
        if (!($_ENV['APP_DEBUG'] ?? false)) {
            self::loadRouteCache();
        }
    }

    /**
     * Register a GET route
     *
     * @param string $pattern Route pattern
     * @param callable|string $handler Route handler
     * @param array $middleware Route-specific middleware
     * @return void
     */
    public static function get(string $pattern, callable|string $handler, array $middleware = []): void
    {
        self::addRoute('GET', $pattern, $handler, $middleware);
    }

    /**
     * Register a POST route
     *
     * @param string $pattern Route pattern
     * @param callable|string $handler Route handler
     * @param array $middleware Route-specific middleware
     * @return void
     */
    public static function post(string $pattern, callable|string $handler, array $middleware = []): void
    {
        self::addRoute('POST', $pattern, $handler, $middleware);
    }

    /**
     * Register a PUT route
     *
     * @param string $pattern Route pattern
     * @param callable|string $handler Route handler
     * @param array $middleware Route-specific middleware
     * @return void
     */
    public static function put(string $pattern, callable|string $handler, array $middleware = []): void
    {
        self::addRoute('PUT', $pattern, $handler, $middleware);
    }

    /**
     * Register a DELETE route
     *
     * @param string $pattern Route pattern
     * @param callable|string $handler Route handler
     * @param array $middleware Route-specific middleware
     * @return void
     */
    public static function delete(string $pattern, callable|string $handler, array $middleware = []): void
    {
        self::addRoute('DELETE', $pattern, $handler, $middleware);
    }

    /**
     * Register a PATCH route
     *
     * @param string $pattern Route pattern
     * @param callable|string $handler Route handler
     * @param array $middleware Route-specific middleware
     * @return void
     */
    public static function patch(string $pattern, callable|string $handler, array $middleware = []): void
    {
        self::addRoute('PATCH', $pattern, $handler, $middleware);
    }

    /**
     * Register a route for any HTTP method
     *
     * @param string $pattern Route pattern
     * @param callable|string $handler Route handler
     * @param array $middleware Route-specific middleware
     * @return void
     */
    public static function any(string $pattern, callable|string $handler, array $middleware = []): void
    {
        $methods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'];
        foreach ($methods as $method) {
            self::addRoute($method, $pattern, $handler, $middleware);
        }
    }

    /**
     * Register a route group
     *
     * @param array $attributes Group attributes (prefix, middleware, etc.)
     * @param callable $callback Group definition callback
     * @return void
     */
    public static function group(array $attributes, callable $callback): void
    {
        $previousPrefix = self::$currentGroupPrefix;
        $previousMiddleware = self::$currentMiddleware;

        // Set group prefix
        if (isset($attributes['prefix'])) {
            self::$currentGroupPrefix = $previousPrefix . '/' . trim($attributes['prefix'], '/');
        }

        // Add group middleware
        if (isset($attributes['middleware'])) {
            self::$currentMiddleware = array_merge(
                $previousMiddleware,
                is_array($attributes['middleware']) ? $attributes['middleware'] : [$attributes['middleware']]
            );
        }

        // Execute group callback
        $callback();

        // Restore previous state
        self::$currentGroupPrefix = $previousPrefix;
        self::$currentMiddleware = $previousMiddleware;
    }

    /**
     * Add a route to the router
     *
     * @param string $method HTTP method
     * @param string $pattern Route pattern
     * @param callable|string $handler Route handler
     * @param array $middleware Route-specific middleware
     * @return void
     */
    private static function addRoute(string $method, string $pattern, callable|string $handler, array $middleware = []): void
    {
        // Apply group prefix
        $fullPattern = self::$currentGroupPrefix . '/' . ltrim($pattern, '/');
        $fullPattern = rtrim($fullPattern, '/') ?: '/';

        // Combine middleware
        $allMiddleware = array_merge(self::$currentMiddleware, $middleware);

        // Convert pattern to regex
        $regex = self::convertPatternToRegex($fullPattern);

        self::$routes[$method][] = [
            'pattern' => $fullPattern,
            'regex' => $regex,
            'handler' => $handler,
            'middleware' => $allMiddleware,
            'parameters' => self::extractParameterNames($fullPattern)
        ];
    }

    /**
     * Handle incoming request
     *
     * @param string|null $uri Request URI (null for auto-detection)
     * @param string|null $method HTTP method (null for auto-detection)
     * @return void
     */
    public static function dispatch(string $uri = null, string $method = null): void
    {
        try {
            // Get request details
            $uri = $uri ?? self::getCurrentUri();
            $method = $method ?? self::getCurrentMethod();

            // Remove base path from URI
            if (self::$basePath && strpos($uri, self::$basePath) === 0) {
                $uri = substr($uri, strlen(self::$basePath));
            }

            $uri = '/' . ltrim($uri, '/');

            // Find matching route
            $route = self::findRoute($method, $uri);

            if ($route) {
                // Execute middleware
                self::executeMiddleware($route['middleware']);

                // Execute route handler
                self::executeHandler($route['handler'], self::$parameters);
            } else {
                // Check if route exists with different method
                if (self::routeExistsWithDifferentMethod($uri, $method)) {
                    self::handleError(405); // Method Not Allowed
                } else {
                    self::handleError(404); // Not Found
                }
            }

        } catch (Exception $e) {
            self::logError($e);
            self::handleError(500, $e);
        }
    }

    /**
     * Find matching route
     *
     * @param string $method HTTP method
     * @param string $uri Request URI
     * @return array|null Matched route or null
     */
    private static function findRoute(string $method, string $uri): ?array
    {
        // Check route cache first
        $cacheKey = $method . ':' . $uri;
        if (isset(self::$routeCache[$cacheKey])) {
            return self::$routeCache[$cacheKey];
        }

        if (!isset(self::$routes[$method])) {
            return null;
        }

        foreach (self::$routes[$method] as $route) {
            if (preg_match($route['regex'], $uri, $matches)) {
                // Extract parameters
                self::$parameters = [];
                foreach ($route['parameters'] as $index => $name) {
                    if (isset($matches[$index + 1])) {
                        self::$parameters[$name] = $matches[$index + 1];
                    }
                }

                // Cache successful match
                if (!($_ENV['APP_DEBUG'] ?? false)) {
                    self::$routeCache[$cacheKey] = $route;
                }

                return $route;
            }
        }

        return null;
    }

    /**
     * Convert route pattern to regex
     *
     * @param string $pattern Route pattern
     * @return string Regex pattern
     */
    private static function convertPatternToRegex(string $pattern): string
    {
        // Escape forward slashes
        $regex = str_replace('/', '\/', $pattern);

        // Convert parameters {param} to regex groups
        $regex = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '([^\/]+)', $regex);

        // Convert optional parameters {param?} to optional regex groups
        $regex = preg_replace('/\{([a-zA-Z0-9_]+)\?\}/', '([^\/]*)', $regex);

        return '/^' . $regex . '$/';
    }

    /**
     * Extract parameter names from route pattern
     *
     * @param string $pattern Route pattern
     * @return array Parameter names
     */
    private static function extractParameterNames(string $pattern): array
    {
        preg_match_all('/\{([a-zA-Z0-9_]+)\??\}/', $pattern, $matches);
        return $matches[1] ?? [];
    }

    /**
     * Execute middleware stack
     *
     * @param array $middleware Middleware array
     * @return void
     * @throws Exception If middleware fails
     */
    private static function executeMiddleware(array $middleware): void
    {
        foreach ($middleware as $middlewareName) {
            $middlewareClass = self::$middleware[$middlewareName] ?? null;

            if (!$middlewareClass) {
                throw new Exception("Middleware '{$middlewareName}' not found");
            }

            if (!$middlewareClass()) {
                throw new Exception("Middleware '{$middlewareName}' failed");
            }
        }
    }

    /**
     * Execute route handler
     *
     * @param callable|string $handler Route handler
     * @param array $parameters Route parameters
     * @return void
     * @throws Exception If handler execution fails
     */
    private static function executeHandler(callable|string $handler, array $parameters = []): void
    {
        if (is_callable($handler)) {
            // Execute closure
            $result = call_user_func_array($handler, array_values($parameters));
        } elseif (is_string($handler)) {
            // Parse controller@method format
            if (strpos($handler, '@') !== false) {
                [$controllerClass, $method] = explode('@', $handler, 2);
            } else {
                $controllerClass = $handler;
                $method = 'index';
            }

            // Instantiate controller
            if (!class_exists($controllerClass)) {
                throw new Exception("Controller '{$controllerClass}' not found");
            }

            $controller = new $controllerClass();

            if (!method_exists($controller, $method)) {
                throw new Exception("Method '{$method}' not found in controller '{$controllerClass}'");
            }

            // Execute controller method
            $result = call_user_func_array([$controller, $method], array_values($parameters));
        } else {
            throw new Exception("Invalid route handler type");
        }

        // Handle result output
        if (is_string($result) || is_numeric($result)) {
            echo $result;
        } elseif (is_array($result) || is_object($result)) {
            header('Content-Type: application/json');
            echo json_encode($result);
        }
    }

    /**
     * Register middleware
     *
     * @param string $name Middleware name
     * @param callable $handler Middleware handler
     * @return void
     */
    public static function middleware(string $name, callable $handler): void
    {
        self::$middleware[$name] = $handler;
    }

    /**
     * Register error handler
     *
     * @param int $code HTTP error code
     * @param callable $handler Error handler
     * @return void
     */
    public static function errorHandler(int $code, callable $handler): void
    {
        self::$errorHandlers[$code] = $handler;
    }

    /**
     * Handle HTTP errors
     *
     * @param int $code HTTP status code
     * @param Exception|null $exception Optional exception
     * @return void
     */
    private static function handleError(int $code, Exception $exception = null): void
    {
        http_response_code($code);

        if (isset(self::$errorHandlers[$code]) && self::$errorHandlers[$code]) {
            call_user_func(self::$errorHandlers[$code], $exception);
        } else {
            // Default error handling
            switch ($code) {
                case 404:
                    echo "404 - Page Not Found";
                    break;
                case 405:
                    header('Allow: ' . implode(', ', self::getAllowedMethods(self::getCurrentUri())));
                    echo "405 - Method Not Allowed";
                    break;
                case 500:
                    echo "500 - Internal Server Error";
                    if (($_ENV['APP_DEBUG'] ?? false) && $exception) {
                        echo "\n\n" . $exception->getMessage();
                        echo "\n\n" . $exception->getTraceAsString();
                    }
                    break;
                default:
                    echo "{$code} - Error";
            }
        }
    }

    /**
     * Get current request URI
     *
     * @return string Current URI
     */
    private static function getCurrentUri(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        // Remove query string
        if (($pos = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $pos);
        }

        return $uri;
    }

    /**
     * Get current HTTP method
     *
     * @return string HTTP method
     */
    private static function getCurrentMethod(): string
    {
        // Handle method override for forms
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_method'])) {
            return strtoupper($_POST['_method']);
        }

        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    /**
     * Check if route exists with different method
     *
     * @param string $uri Request URI
     * @param string $currentMethod Current HTTP method
     * @return bool
     */
    private static function routeExistsWithDifferentMethod(string $uri, string $currentMethod): bool
    {
        foreach (self::$routes as $method => $routes) {
            if ($method === $currentMethod) {
                continue;
            }

            foreach ($routes as $route) {
                if (preg_match($route['regex'], $uri)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get allowed methods for a URI
     *
     * @param string $uri Request URI
     * @return array Allowed methods
     */
    private static function getAllowedMethods(string $uri): array
    {
        $methods = [];

        foreach (self::$routes as $method => $routes) {
            foreach ($routes as $route) {
                if (preg_match($route['regex'], $uri)) {
                    $methods[] = $method;
                    break;
                }
            }
        }

        return $methods;
    }

    /**
     * Register default middleware
     *
     * @return void
     */
    private static function registerDefaultMiddleware(): void
    {
        // CSRF Protection Middleware
        self::middleware('csrf', function() {
            if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE', 'PATCH'])) {
                return Security::verifyCsrfToken($_POST['_token'] ?? '');
            }
            return true;
        });

        // Authentication Middleware
        self::middleware('auth', function() {
            return isset($_SESSION['user_id']);
        });

        // Admin Authentication Middleware
        self::middleware('admin', function() {
            return isset($_SESSION['admin_id']) && isset($_SESSION['admin_role']);
        });

        // Rate Limiting Middleware
        self::middleware('throttle', function() {
            // Implement rate limiting logic
            return true;
        });

        // CORS Middleware
        self::middleware('cors', function() {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization');

            if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
                http_response_code(200);
                exit;
            }

            return true;
        });
    }

    /**
     * Register default error handlers
     *
     * @return void
     */
    private static function registerDefaultErrorHandlers(): void
    {
        self::errorHandler(404, function() {
            include __DIR__ . '/../views/errors/404.php';
        });

        self::errorHandler(405, function() {
            include __DIR__ . '/../views/errors/405.php';
        });

        self::errorHandler(500, function($exception) {
            include __DIR__ . '/../views/errors/500.php';
        });
    }

    /**
     * Load route cache from file
     *
     * @return void
     */
    private static function loadRouteCache(): void
    {
        $cacheFile = __DIR__ . '/../cache/routes.cache';

        if (file_exists($cacheFile)) {
            self::$routeCache = unserialize(file_get_contents($cacheFile)) ?: [];
        }
    }

    /**
     * Save route cache to file
     *
     * @return void
     */
    public static function saveRouteCache(): void
    {
        $cacheDir = __DIR__ . '/../cache';
        $cacheFile = $cacheDir . '/routes.cache';

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        file_put_contents($cacheFile, serialize(self::$routeCache));
    }

    /**
     * Clear route cache
     *
     * @return void
     */
    public static function clearRouteCache(): void
    {
        self::$routeCache = [];
        $cacheFile = __DIR__ . '/../cache/routes.cache';

        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }

    /**
     * Get route parameter
     *
     * @param string $name Parameter name
     * @param mixed $default Default value
     * @return mixed Parameter value
     */
    public static function getParameter(string $name, mixed $default = null): mixed
    {
        return self::$parameters[$name] ?? $default;
    }

    /**
     * Get all route parameters
     *
     * @return array All parameters
     */
    public static function getParameters(): array
    {
        return self::$parameters;
    }

    /**
     * Log router errors
     *
     * @param Exception $exception Exception to log
     * @return void
     */
    private static function logError(Exception $exception): void
    {
        $message = sprintf(
            "[%s] Router Error: %s in %s:%d\n%s",
            date('Y-m-d H:i:s'),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        );

        error_log($message);

        // Also log to application log if available
        $logFile = $_ENV['LOG_PATH'] ?? '/var/log/winabrandnew';
        $logFile .= '/app.log';

        if (is_writable(dirname($logFile))) {
            file_put_contents($logFile, $message . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * Generate URL for named route
     *
     * @param string $name Route name
     * @param array $parameters Route parameters
     * @return string Generated URL
     */
    public static function url(string $name, array $parameters = []): string
    {
        // Implementation for named routes (if needed)
        // This would require storing route names during registration
        return '#';
    }

    /**
     * Redirect to URL
     *
     * @param string $url Redirect URL
     * @param int $code HTTP status code
     * @return void
     */
    public static function redirect(string $url, int $code = 302): void
    {
        http_response_code($code);
        header("Location: {$url}");
        exit;
    }

    /**
     * Get router statistics
     *
     * @return array Router statistics
     */
    public static function getStatistics(): array
    {
        $totalRoutes = 0;
        foreach (self::$routes as $methodRoutes) {
            $totalRoutes += count($methodRoutes);
        }

        return [
            'total_routes' => $totalRoutes,
            'routes_by_method' => array_map('count', self::$routes),
            'middleware_count' => count(self::$middleware),
            'cache_size' => count(self::$routeCache),
            'memory_usage' => memory_get_usage(true)
        ];
    }

    /**
     * Initialize default routes
     *
     * @return void
     */
    public static function registerDefaultRoutes(): void
    {
        // Register default middleware first
        self::registerDefaultMiddleware();

        // =============================================
        // PUBLIC ROUTES (from Development Specification)
        // =============================================

        // Platform homepage
        self::get('/', 'WinABrandNew\\Controllers\\HomeController@index');

        // Game landing pages
        self::get('/win-a-{slug}', 'WinABrandNew\\Controllers\\GameController@landing');

        // Game flow routes
        self::post('/game/start', 'WinABrandNew\\Controllers\\GameController@start', ['csrf']);
        self::post('/game/submit-data', 'WinABrandNew\\Controllers\\GameController@submitData', ['csrf']);
        self::post('/game/payment', 'WinABrandNew\\Controllers\\PaymentController@create', ['csrf']);
        self::get('/game/continue/{payment_id}', 'WinABrandNew\\Controllers\\PaymentController@continue');
        self::post('/game/answer', 'WinABrandNew\\Controllers\\QuestionController@answer', ['csrf']);
        self::get('/game/complete', 'WinABrandNew\\Controllers\\GameController@complete');

        // Payment status page
        self::get('/pay', 'WinABrandNew\\Controllers\\PaymentController@status');

        // Prize claim routes
        self::get('/claim/{token}', 'WinABrandNew\\Controllers\\ClaimController@show');
        self::post('/claim/{token}', 'WinABrandNew\\Controllers\\ClaimController@process', ['csrf']);

        // =============================================
        // WEBHOOK ROUTES
        // =============================================

        // Payment webhooks
        self::post('/webhook/mollie', 'WinABrandNew\\Controllers\\WebhookController@mollie');
        self::post('/webhook/stripe', 'WinABrandNew\\Controllers\\WebhookController@stripe');

        // WhatsApp webhooks
        self::post('/webhook/whatsapp', 'WinABrandNew\\Controllers\\WebhookController@whatsapp');
        self::get('/webhook/whatsapp', 'WinABrandNew\\Controllers\\WebhookController@verifyWhatsapp');

        // =============================================
        // API ROUTES
        // =============================================

        self::group(['prefix' => 'api/v1', 'middleware' => ['cors']], function() {
            // Game API endpoints
            self::get('/games', 'WinABrandNew\\Controllers\\ApiController@games');
            self::get('/games/{slug}', 'WinABrandNew\\Controllers\\ApiController@gameDetails');
            self::get('/rounds/{id}/status', 'WinABrandNew\\Controllers\\ApiController@roundStatus');

            // Timer API
            self::post('/timer/sync', 'WinABrandNew\\Controllers\\TimerController@sync', ['csrf']);

            // Health check
            self::get('/health', 'WinABrandNew\\Controllers\\ApiController@health');
        });

        // =============================================
        // ADMIN ROUTES (Authentication Required)
        // =============================================

        self::group(['prefix' => 'adminportal', 'middleware' => ['admin']], function() {
            // Admin authentication
            self::get('/login', 'WinABrandNew\\Controllers\\Admin\\AuthController@showLogin');
            self::post('/login', 'WinABrandNew\\Controllers\\Admin\\AuthController@login', ['csrf']);
            self::post('/logout', 'WinABrandNew\\Controllers\\Admin\\AuthController@logout', ['csrf']);

            // Dashboard
            self::get('/dashboard', 'WinABrandNew\\Controllers\\Admin\\DashboardController@index');

            // Games Management
            self::get('/games', 'WinABrandNew\\Controllers\\Admin\\GamesController@index');
            self::get('/games/create', 'WinABrandNew\\Controllers\\Admin\\GamesController@create');
            self::post('/games', 'WinABrandNew\\Controllers\\Admin\\GamesController@store', ['csrf']);
            self::get('/games/{id}/edit', 'WinABrandNew\\Controllers\\Admin\\GamesController@edit');
            self::put('/games/{id}', 'WinABrandNew\\Controllers\\Admin\\GamesController@update', ['csrf']);
            self::delete('/games/{id}', 'WinABrandNew\\Controllers\\Admin\\GamesController@delete', ['csrf']);

            // Rounds Management
            self::get('/rounds', 'WinABrandNew\\Controllers\\Admin\\RoundsController@index');
            self::get('/rounds/{id}', 'WinABrandNew\\Controllers\\Admin\\RoundsController@show');
            self::post('/rounds/{id}/complete', 'WinABrandNew\\Controllers\\Admin\\RoundsController@complete', ['csrf']);
            self::post('/rounds/{id}/pause', 'WinABrandNew\\Controllers\\Admin\\RoundsController@pause', ['csrf']);

            // Questions Management
            self::get('/questions', 'WinABrandNew\\Controllers\\Admin\\QuestionsController@index');
            self::get('/questions/create', 'WinABrandNew\\Controllers\\Admin\\QuestionsController@create');
            self::post('/questions', 'WinABrandNew\\Controllers\\Admin\\QuestionsController@store', ['csrf']);
            self::get('/questions/{id}/edit', 'WinABrandNew\\Controllers\\Admin\\QuestionsController@edit');
            self::put('/questions/{id}', 'WinABrandNew\\Controllers\\Admin\\QuestionsController@update', ['csrf']);

            // Participants Management
            self::get('/participants', 'WinABrandNew\\Controllers\\Admin\\ParticipantsController@index');
            self::get('/participants/{id}', 'WinABrandNew\\Controllers\\Admin\\ParticipantsController@show');
            self::post('/participants/{id}/mark-fraud', 'WinABrandNew\\Controllers\\Admin\\ParticipantsController@markFraud', ['csrf']);

            // Prize Fulfillment
            self::get('/fulfillment', 'WinABrandNew\\Controllers\\Admin\\FulfillmentController@index');
            self::get('/fulfillment/{id}', 'WinABrandNew\\Controllers\\Admin\\FulfillmentController@show');
            self::post('/fulfillment/{id}/tracking', 'WinABrandNew\\Controllers\\Admin\\FulfillmentController@addTracking', ['csrf']);

            // Analytics
            self::get('/analytics', 'WinABrandNew\\Controllers\\Admin\\AnalyticsController@index');
            self::get('/analytics/revenue', 'WinABrandNew\\Controllers\\Admin\\AnalyticsController@revenue');
            self::get('/analytics/conversions', 'WinABrandNew\\Controllers\\Admin\\AnalyticsController@conversions');

            // Settings
            self::get('/settings', 'WinABrandNew\\Controllers\\Admin\\SettingsController@index');
            self::post('/settings', 'WinABrandNew\\Controllers\\Admin\\SettingsController@update', ['csrf']);

            // User Management
            self::get('/users', 'WinABrandNew\\Controllers\\Admin\\UsersController@index');
            self::get('/users/create', 'WinABrandNew\\Controllers\\Admin\\UsersController@create');
            self::post('/users', 'WinABrandNew\\Controllers\\Admin\\UsersController@store', ['csrf']);
            self::get('/users/{id}/edit', 'WinABrandNew\\Controllers\\Admin\\UsersController@edit');
            self::put('/users/{id}', 'WinABrandNew\\Controllers\\Admin\\UsersController@update', ['csrf']);

            // Audit Trail
            self::get('/audit', 'WinABrandNew\\Controllers\\Admin\\AuditController@index');
            self::get('/audit/{id}', 'WinABrandNew\\Controllers\\Admin\\AuditController@show');

            // Security Monitoring
            self::get('/security', 'WinABrandNew\\Controllers\\Admin\\SecurityController@index');
            self::get('/security/logs', 'WinABrandNew\\Controllers\\Admin\\SecurityController@logs');

            // Customer Support
            self::get('/support', 'WinABrandNew\\Controllers\\Admin\\SupportController@index');
            self::post('/support/resend-claim/{id}', 'WinABrandNew\\Controllers\\Admin\\SupportController@resendClaim', ['csrf']);
            self::post('/support/refund/{id}', 'WinABrandNew\\Controllers\\Admin\\SupportController@processRefund', ['csrf']);

            // API endpoints for admin dashboard
            self::get('/api/stats', 'WinABrandNew\\Controllers\\Admin\\ApiController@stats');
            self::get('/api/participants/search', 'WinABrandNew\\Controllers\\Admin\\ApiController@searchParticipants');
            self::get('/api/revenue/chart', 'WinABrandNew\\Controllers\\Admin\\ApiController@revenueChart');
        });

        // =============================================
        // SSO ROUTES (Optional)
        // =============================================

        if ($_ENV['FEATURE_SSO_LOGIN'] ?? false) {
            self::group(['prefix' => 'auth'], function() {
                // Google OAuth
                self::get('/google', 'WinABrandNew\\Controllers\\SSOController@redirectToGoogle');
                self::get('/google/callback', 'WinABrandNew\\Controllers\\SSOController@handleGoogleCallback');

                // Facebook OAuth
                self::get('/facebook', 'WinABrandNew\\Controllers\\SSOController@redirectToFacebook');
                self::get('/facebook/callback', 'WinABrandNew\\Controllers\\SSOController@handleFacebookCallback');

                // Apple Sign In
                self::get('/apple', 'WinABrandNew\\Controllers\\SSOController@redirectToApple');
                self::post('/apple/callback', 'WinABrandNew\\Controllers\\SSOController@handleAppleCallback');
            });
        }

        // =============================================
        // MAINTENANCE & UTILITY ROUTES
        // =============================================

        // Maintenance mode check
        self::get('/maintenance', function() {
            if ($_ENV['MAINTENANCE_MODE'] ?? false) {
                http_response_code(503);
                include __DIR__ . '/../views/maintenance.php';
                exit;
            }
            self::redirect('/');
        });

        // Robots.txt
        self::get('/robots.txt', function() {
            header('Content-Type: text/plain');
            echo "User-agent: *\n";
            if ($_ENV['APP_DEBUG'] ?? false) {
                echo "Disallow: /\n"; // Block crawlers in development
            } else {
                echo "Disallow: /adminportal/\n";
                echo "Disallow: /api/\n";
                echo "Disallow: /webhook/\n";
                echo "Allow: /\n";
            }
        });

        // Sitemap.xml
        self::get('/sitemap.xml', 'WinABrandNew\\Controllers\\SitemapController@index');

        // Privacy Policy & Terms
        self::get('/privacy', 'WinABrandNew\\Controllers\\LegalController@privacy');
        self::get('/terms', 'WinABrandNew\\Controllers\\LegalController@terms');
        self::get('/cookies', 'WinABrandNew\\Controllers\\LegalController@cookies');

        // GDPR Data Export/Deletion
        self::get('/gdpr/export', 'WinABrandNew\\Controllers\\GDPRController@showExportForm');
        self::post('/gdpr/export', 'WinABrandNew\\Controllers\\GDPRController@processExport', ['csrf']);
        self::get('/gdpr/delete', 'WinABrandNew\\Controllers\\GDPRController@showDeleteForm');
        self::post('/gdpr/delete', 'WinABrandNew\\Controllers\\GDPRController@processDelete', ['csrf']);
    }

    /**
     * Debug function to display all registered routes
     *
     * @return void
     */
    public static function debugRoutes(): void
    {
        if (!($_ENV['APP_DEBUG'] ?? false)) {
            return;
        }

        echo "<h2>Registered Routes</h2>";
        echo "<style>
            .route-debug { font-family: monospace; margin: 20px; }
            .route-method { padding: 2px 6px; color: white; border-radius: 3px; font-weight: bold; }
            .method-get { background: #28a745; }
            .method-post { background: #007bff; }
            .method-put { background: #ffc107; color: black; }
            .method-delete { background: #dc3545; }
            .method-patch { background: #6610f2; }
            .route-pattern { margin: 0 10px; }
            .route-handler { color: #666; }
            .route-middleware { color: #17a2b8; font-size: 0.9em; }
        </style>";

        echo "<div class='route-debug'>";
        foreach (self::$routes as $method => $routes) {
            foreach ($routes as $route) {
                $methodClass = 'method-' . strtolower($method);
                echo "<div style='margin: 5px 0;'>";
                echo "<span class='route-method {$methodClass}'>{$method}</span>";
                echo "<span class='route-pattern'>{$route['pattern']}</span>";
                echo "<span class='route-handler'>" . (is_string($route['handler']) ? $route['handler'] : 'Closure') . "</span>";

                if (!empty($route['middleware'])) {
                    echo "<span class='route-middleware'> [" . implode(', ', $route['middleware']) . "]</span>";
                }
                echo "</div>";
            }
        }
        echo "</div>";

        echo "<h3>Middleware</h3>";
        echo "<div class='route-debug'>";
        foreach (self::$middleware as $name => $handler) {
            echo "<div>{$name}</div>";
        }
        echo "</div>";

        echo "<h3>Statistics</h3>";
        echo "<div class='route-debug'>";
        $stats = self::getStatistics();
        foreach ($stats as $key => $value) {
            if (is_array($value)) {
                echo "<div>{$key}: " . json_encode($value) . "</div>";
            } else {
                echo "<div>{$key}: {$value}</div>";
            }
        }
        echo "</div>";
    }

    /**
     * Validate route configuration
     *
     * @return array Validation results
     */
    public static function validateRoutes(): array
    {
        $errors = [];
        $warnings = [];

        // Check for duplicate routes
        $seenRoutes = [];
        foreach (self::$routes as $method => $routes) {
            foreach ($routes as $route) {
                $key = $method . ':' . $route['pattern'];
                if (isset($seenRoutes[$key])) {
                    $errors[] = "Duplicate route: {$key}";
                }
                $seenRoutes[$key] = true;
            }
        }

        // Check for undefined middleware
        foreach (self::$routes as $method => $routes) {
            foreach ($routes as $route) {
                foreach ($route['middleware'] as $middlewareName) {
                    if (!isset(self::$middleware[$middlewareName])) {
                        $errors[] = "Undefined middleware '{$middlewareName}' used in {$method} {$route['pattern']}";
                    }
                }
            }
        }

        // Check for potential security issues
        foreach (self::$routes as $method => $routes) {
            foreach ($routes as $route) {
                // Check if POST/PUT/DELETE routes have CSRF protection
                if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) {
                    if (!in_array('csrf', $route['middleware'])) {
                        $warnings[] = "Route {$method} {$route['pattern']} may need CSRF protection";
                    }
                }

                // Check if admin routes have admin middleware
                if (strpos($route['pattern'], '/adminportal') === 0) {
                    if (!in_array('admin', $route['middleware'])) {
                        $errors[] = "Admin route {$method} {$route['pattern']} missing admin middleware";
                    }
                }
            }
        }

        return [
            'errors' => $errors,
            'warnings' => $warnings,
            'valid' => empty($errors)
        ];
    }

    /**
     * Performance optimization: Pre-compile routes
     *
     * @return void
     */
    public static function compileRoutes(): void
    {
        // Sort routes by pattern complexity (most specific first)
        foreach (self::$routes as $method => &$routes) {
            usort($routes, function($a, $b) {
                // Routes with more path segments should be checked first
                $aSegments = substr_count($a['pattern'], '/');
                $bSegments = substr_count($b['pattern'], '/');

                if ($aSegments !== $bSegments) {
                    return $bSegments - $aSegments;
                }

                // Routes with fewer parameters should be checked first
                $aParams = substr_count($a['pattern'], '{');
                $bParams = substr_count($b['pattern'], '{');

                return $aParams - $bParams;
            });
        }
    }

    /**
     * Cleanup method for graceful shutdown
     *
     * @return void
     */
    public static function cleanup(): void
    {
        // Save route cache if in production
        if (!($_ENV['APP_DEBUG'] ?? false)) {
            self::saveRouteCache();
        }

        // Log router statistics
        if ($_ENV['LOG_PERFORMANCE'] ?? false) {
            $stats = self::getStatistics();
            error_log("Router Statistics: " . json_encode($stats));
        }
    }
}

// =============================================
// HELPER FUNCTIONS
// =============================================

/**
 * Quick route registration helpers
 */

function route_get(string $pattern, callable|string $handler, array $middleware = []): void
{
    Router::get($pattern, $handler, $middleware);
}

function route_post(string $pattern, callable|string $handler, array $middleware = []): void
{
    Router::post($pattern, $handler, $middleware);
}

function route_put(string $pattern, callable|string $handler, array $middleware = []): void
{
    Router::put($pattern, $handler, $middleware);
}

function route_delete(string $pattern, callable|string $handler, array $middleware = []): void
{
    Router::delete($pattern, $handler, $middleware);
}

function route_any(string $pattern, callable|string $handler, array $middleware = []): void
{
    Router::any($pattern, $handler, $middleware);
}

function route_group(array $attributes, callable $callback): void
{
    Router::group($attributes, $callback);
}

function route_middleware(string $name, callable $handler): void
{
    Router::middleware($name, $handler);
}

function route_redirect(string $url, int $code = 302): void
{
    Router::redirect($url, $code);
}

function route_param(string $name, mixed $default = null): mixed
{
    return Router::getParameter($name, $default);
}

function route_params(): array
{
    return Router::getParameters();
}

/**
 * Register shutdown function to cleanup router
 */
register_shutdown_function([Router::class, 'cleanup']);
