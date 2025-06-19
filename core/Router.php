<?php
declare(strict_types=1);

/**
 * File: core/Router.php
 * Location: core/Router.php
 *
 * WinABN URL Router System
 *
 * Handles URL routing, parameter extraction, and route matching for the WinABN platform.
 * Supports RESTful routes, parameter binding, middleware, and flexible route definitions.
 *
 * @package WinABN\Core
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Core;

use Exception;

class Router
{
    /**
     * Registered routes
     *
     * @var array<string, array<string, array>>
     */
    private static array $routes = [
        'GET' => [],
        'POST' => [],
        'PUT' => [],
        'DELETE' => [],
        'PATCH' => []
    ];

    /**
     * Route parameters
     *
     * @var array<string, mixed>
     */
    private static array $params = [];

    /**
     * Current matched route
     *
     * @var array<string, mixed>|null
     */
    private static ?array $currentRoute = null;

    /**
     * Middleware stack
     *
     * @var array<callable>
     */
    private static array $middleware = [];

    /**
     * Route groups
     *
     * @var array<string, mixed>
     */
    private static array $currentGroup = [
        'prefix' => '',
        'middleware' => []
    ];

    /**
     * Add GET route
     *
     * @param string $pattern URL pattern
     * @param callable|string $handler Route handler
     * @param array<string> $middleware Route middleware
     * @return void
     */
    public static function get(string $pattern, $handler, array $middleware = []): void
    {
        self::addRoute('GET', $pattern, $handler, $middleware);
    }

    /**
     * Add POST route
     *
     * @param string $pattern URL pattern
     * @param callable|string $handler Route handler
     * @param array<string> $middleware Route middleware
     * @return void
     */
    public static function post(string $pattern, $handler, array $middleware = []): void
    {
        self::addRoute('POST', $pattern, $handler, $middleware);
    }

    /**
     * Add PUT route
     *
     * @param string $pattern URL pattern
     * @param callable|string $handler Route handler
     * @param array<string> $middleware Route middleware
     * @return void
     */
    public static function put(string $pattern, $handler, array $middleware = []): void
    {
        self::addRoute('PUT', $pattern, $handler, $middleware);
    }

    /**
     * Add DELETE route
     *
     * @param string $pattern URL pattern
     * @param callable|string $handler Route handler
     * @param array<string> $middleware Route middleware
     * @return void
     */
    public static function delete(string $pattern, $handler, array $middleware = []): void
    {
        self::addRoute('DELETE', $pattern, $handler, $middleware);
    }

    /**
     * Add PATCH route
     *
     * @param string $pattern URL pattern
     * @param callable|string $handler Route handler
     * @param array<string> $middleware Route middleware
     * @return void
     */
    public static function patch(string $pattern, $handler, array $middleware = []): void
    {
        self::addRoute('PATCH', $pattern, $handler, $middleware);
    }

    /**
     * Add route for any HTTP method
     *
     * @param array<string> $methods HTTP methods
     * @param string $pattern URL pattern
     * @param callable|string $handler Route handler
     * @param array<string> $middleware Route middleware
     * @return void
     */
    public static function any(array $methods, string $pattern, $handler, array $middleware = []): void
    {
        foreach ($methods as $method) {
            self::addRoute(strtoupper($method), $pattern, $handler, $middleware);
        }
    }

    /**
     * Create route group with common attributes
     *
     * @param array<string, mixed> $attributes Group attributes
     * @param callable $callback Group routes callback
     * @return void
     */
    public static function group(array $attributes, callable $callback): void
    {
        $previousGroup = self::$currentGroup;

        // Merge group attributes
        self::$currentGroup = [
            'prefix' => ($previousGroup['prefix'] ?? '') . ($attributes['prefix'] ?? ''),
            'middleware' => array_merge(
                $previousGroup['middleware'] ?? [],
                $attributes['middleware'] ?? []
            )
        ];

        $callback();

        // Restore previous group
        self::$currentGroup = $previousGroup;
    }

    /**
     * Add middleware to route stack
     *
     * @param callable $middleware Middleware function
     * @return void
     */
    public static function middleware(callable $middleware): void
    {
        self::$middleware[] = $middleware;
    }

    /**
     * Dispatch route based on current request
     *
     * @param string|null $method HTTP method
     * @param string|null $uri Request URI
     * @return mixed Route handler response
     * @throws Exception
     */
    public static function dispatch(?string $method = null, ?string $uri = null)
    {
        $method = $method ?? request_method();
        $uri = $uri ?? self::getCurrentUri();

        // Find matching route
        $route = self::findRoute($method, $uri);

        if (!$route) {
            return self::handleNotFound($method, $uri);
        }

        self::$currentRoute = $route;

        try {
            // Execute middleware stack
            return self::executeMiddleware($route['middleware'], function() use ($route) {
                return self::executeHandler($route['handler'], $route['params']);
            });
        } catch (Exception $e) {
            return self::handleError($e);
        }
    }

    /**
     * Add route to routing table
     *
     * @param string $method HTTP method
     * @param string $pattern URL pattern
     * @param callable|string $handler Route handler
     * @param array<string> $middleware Route middleware
     * @return void
     */
    private static function addRoute(string $method, string $pattern, $handler, array $middleware = []): void
    {
        // Apply group prefix
        $pattern = self::$currentGroup['prefix'] . $pattern;

        // Merge group middleware
        $middleware = array_merge(self::$currentGroup['middleware'], $middleware);

        // Convert route pattern to regex
        $regex = self::patternToRegex($pattern);

        self::$routes[$method][] = [
            'pattern' => $pattern,
            'regex' => $regex,
            'handler' => $handler,
            'middleware' => $middleware,
            'params' => []
        ];
    }

    /**
     * Convert route pattern to regex
     *
     * @param string $pattern Route pattern
     * @return string Regex pattern
     */
    private static function patternToRegex(string $pattern): string
    {
        // Escape special regex characters except for our placeholders
        $pattern = preg_quote($pattern, '/');

        // Convert parameter placeholders to regex groups
        // {id} becomes (\d+), {slug} becomes ([a-zA-Z0-9\-_]+), {any} becomes ([^/]+)
        $pattern = preg_replace('/\\\{(\w+)\\\}/', '(?P<$1>[^/]+)', $pattern);
        $pattern = preg_replace('/\\\{(\w+):([^}]+)\\\}/', '(?P<$1>$2)', $pattern);

        // Add start and end anchors
        return '/^' . $pattern . '$/';
    }

    /**
     * Find matching route for method and URI
     *
     * @param string $method HTTP method
     * @param string $uri Request URI
     * @return array<string, mixed>|null
     */
    private static function findRoute(string $method, string $uri): ?array
    {
        if (!isset(self::$routes[$method])) {
            return null;
        }

        foreach (self::$routes[$method] as $route) {
            if (preg_match($route['regex'], $uri, $matches)) {
                // Extract named parameters
                $params = [];
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $params[$key] = $value;
                    }
                }

                $route['params'] = $params;
                self::$params = $params;

                return $route;
            }
        }

        return null;
    }

    /**
     * Execute middleware stack
     *
     * @param array<string> $middleware Middleware array
     * @param callable $next Next function to call
     * @return mixed
     */
    private static function executeMiddleware(array $middleware, callable $next)
    {
        if (empty($middleware)) {
            return $next();
        }

        $middlewareName = array_shift($middleware);
        $middlewareFunction = self::resolveMiddleware($middlewareName);

        return $middlewareFunction(function() use ($middleware, $next) {
            return self::executeMiddleware($middleware, $next);
        });
    }

    /**
     * Resolve middleware name to function
     *
     * @param string $name Middleware name
     * @return callable
     * @throws Exception
     */
    private static function resolveMiddleware(string $name): callable
    {
        // Built-in middleware
        $builtInMiddleware = [
            'auth' => [Security::class, 'authMiddleware'],
            'csrf' => [Security::class, 'csrfMiddleware'],
            'admin' => [Security::class, 'adminMiddleware'],
            'rate_limit' => [Security::class, 'rateLimitMiddleware']
        ];

        if (isset($builtInMiddleware[$name])) {
            return $builtInMiddleware[$name];
        }

        // Custom middleware class
        if (class_exists($name)) {
            return new $name();
        }

        throw new Exception("Middleware not found: $name");
    }

    /**
     * Execute route handler
     *
     * @param callable|string $handler Route handler
     * @param array<string, mixed> $params Route parameters
     * @return mixed
     * @throws Exception
     */
    private static function executeHandler($handler, array $params)
    {
        if (is_callable($handler)) {
            return call_user_func_array($handler, array_values($params));
        }

        if (is_string($handler)) {
            return self::executeControllerAction($handler, $params);
        }

        throw new Exception("Invalid route handler");
    }

    /**
     * Execute controller action
     *
     * @param string $handler Controller@method string
     * @param array<string, mixed> $params Route parameters
     * @return mixed
     * @throws Exception
     */
    private static function executeControllerAction(string $handler, array $params)
    {
        if (!str_contains($handler, '@')) {
            throw new Exception("Invalid controller action format: $handler");
        }

        [$controllerName, $methodName] = explode('@', $handler, 2);

        // Add namespace if not present
        if (!str_contains($controllerName, '\\')) {
            $controllerName = "WinABN\\Controllers\\{$controllerName}";
        }

        if (!class_exists($controllerName)) {
            throw new Exception("Controller not found: $controllerName");
        }

        $controller = new $controllerName();

        if (!method_exists($controller, $methodName)) {
            throw new Exception("Method not found: {$controllerName}::{$methodName}");
        }

        return call_user_func_array([$controller, $methodName], array_values($params));
    }

    /**
     * Get current request URI
     *
     * @return string
     */
    private static function getCurrentUri(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        // Remove query string
        if (($pos = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $pos);
        }

        // Remove trailing slash except for root
        if ($uri !== '/' && str_ends_with($uri, '/')) {
            $uri = rtrim($uri, '/');
        }

        return $uri;
    }

    /**
     * Handle 404 Not Found
     *
     * @param string $method HTTP method
     * @param string $uri Request URI
     * @return never
     */
    private static function handleNotFound(string $method, string $uri): never
    {
        http_response_code(404);

        if (self::isApiRequest()) {
            json_response(['error' => 'Route not found', 'method' => $method, 'uri' => $uri], 404);
        }

        // Load 404 page template
        $view = new View();
        echo $view->render('errors/404', [
            'method' => $method,
            'uri' => $uri
        ]);
        exit;
    }

    /**
     * Handle route execution errors
     *
     * @param Exception $e Exception to handle
     * @return never
     */
    private static function handleError(Exception $e): never
    {
        app_log('error', 'Route execution error', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'route' => self::$currentRoute
        ]);

        http_response_code(500);

        if (self::isApiRequest()) {
            $response = ['error' => 'Internal server error'];

            if (is_debug()) {
                $response['debug'] = [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ];
            }

            json_response($response, 500);
        }

        if (is_debug()) {
            echo '<pre>' . $e . '</pre>';
        } else {
            $view = new View();
            echo $view->render('errors/500', ['error' => $e->getMessage()]);
        }

        exit;
    }

    /**
     * Check if current request is API request
     *
     * @return bool
     */
    private static function isApiRequest(): bool
    {
        return str_starts_with(self::getCurrentUri(), '/api/') ||
               str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    /**
     * Get route parameter
     *
     * @param string $key Parameter key
     * @param mixed $default Default value
     * @return mixed
     */
    public static function param(string $key, $default = null)
    {
        return self::$params[$key] ?? $default;
    }

    /**
     * Get all route parameters
     *
     * @return array<string, mixed>
     */
    public static function params(): array
    {
        return self::$params;
    }

    /**
     * Get current route info
     *
     * @return array<string, mixed>|null
     */
    public static function currentRoute(): ?array
    {
        return self::$currentRoute;
    }

    /**
     * Generate URL for named route
     *
     * @param string $name Route name
     * @param array<string, mixed> $params Route parameters
     * @return string
     */
    public static function url(string $name, array $params = []): string
    {
        // This would require named routes - simplified version
        return url($name, $params);
    }

    /**
     * Check if route exists
     *
     * @param string $method HTTP method
     * @param string $uri URI to check
     * @return bool
     */
    public static function hasRoute(string $method, string $uri): bool
    {
        return self::findRoute($method, $uri) !== null;
    }

    /**
     * Clear all routes (useful for testing)
     *
     * @return void
     */
    public static function clearRoutes(): void
    {
        self::$routes = [
            'GET' => [],
            'POST' => [],
            'PUT' => [],
            'DELETE' => [],
            'PATCH' => []
        ];
        self::$params = [];
        self::$currentRoute = null;
        self::$middleware = [];
    }

    /**
     * Get all registered routes
     *
     * @return array<string, array<string, array>>
     */
    public static function getRoutes(): array
    {
        return self::$routes;
    }
}
