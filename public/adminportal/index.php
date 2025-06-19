<?php
declare(strict_types=1);

/**
 * File: public/adminportal/index.php
 * Location: public/adminportal/index.php
 *
 * WinABN Admin Portal Entry Point
 *
 * Main routing and initialization for the admin portal.
 * Handles all admin routes and applies appropriate middleware.
 *
 * @package WinABN\AdminPortal
 * @author WinABN Development Team
 * @version 1.0
 */

// Include bootstrap
require_once dirname(__DIR__) . '/bootstrap.php';

use WinABN\Core\{Router, AdminMiddleware, Database};
use WinABN\Controllers\AdminController;

// Initialize admin components
AdminMiddleware::createActivityLogTable();

// Create router instance
$router = new Router();
$adminController = new AdminController();
$middleware = new AdminMiddleware();

// Get request path (remove /adminportal prefix)
$requestPath = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = parse_url($requestPath, PHP_URL_PATH);
$requestPath = preg_replace('#^/adminportal#', '', $requestPath);
$requestPath = $requestPath ?: '/dashboard';

$method = request_method();

// Define routes with their required permissions
$routes = [
    // Authentication routes (no auth required)
    'GET /' => ['controller' => [$adminController, 'showLogin'], 'auth' => false],
    'GET /login' => ['controller' => [$adminController, 'showLogin'], 'auth' => false],
    'POST /login' => ['controller' => [$adminController, 'processLogin'], 'auth' => false],

    // Authenticated routes
    'GET /dashboard' => ['controller' => [$adminController, 'dashboard'], 'auth' => true],
    'GET /logout' => ['controller' => [$adminController, 'logout'], 'auth' => true],
    'GET /profile' => ['controller' => [$adminController, 'profile'], 'auth' => true],
    'POST /profile' => ['controller' => [$adminController, 'profile'], 'auth' => true],

    // 2FA routes
    'GET /2fa/setup' => ['controller' => [$adminController, 'setup2FA'], 'auth' => true],
    'POST /2fa/setup' => ['controller' => [$adminController, 'setup2FA'], 'auth' => true],
    'POST /2fa/verify' => ['controller' => [$adminController, 'verify2FA'], 'auth' => true],
    'POST /2fa/disable' => ['controller' => [$adminController, 'disable2FA'], 'auth' => true],
    'POST /change-password' => ['controller' => [$adminController, 'changePassword'], 'auth' => true],

    // Game management routes (will be implemented in future conversations)
    'GET /games' => ['controller' => 'AdminGameController@index', 'auth' => true, 'permissions' => ['games.view']],
    'POST /games' => ['controller' => 'AdminGameController@store', 'auth' => true, 'permissions' => ['games.create']],
    'GET /games/create' => ['controller' => 'AdminGameController@create', 'auth' => true, 'permissions' => ['games.create']],
    'GET /games/{id}' => ['controller' => 'AdminGameController@show', 'auth' => true, 'permissions' => ['games.view']],
    'GET /games/{id}/edit' => ['controller' => 'AdminGameController@edit', 'auth' => true, 'permissions' => ['games.edit']],
    'POST /games/{id}' => ['controller' => 'AdminGameController@update', 'auth' => true, 'permissions' => ['games.edit']],
    'POST /games/{id}/delete' => ['controller' => 'AdminGameController@destroy', 'auth' => true, 'permissions' => ['games.delete']],

    // Participant management routes
    'GET /participants' => ['controller' => 'AdminParticipantController@index', 'auth' => true, 'permissions' => ['participants.view']],
    'GET /participants/{id}' => ['controller' => 'AdminParticipantController@show', 'auth' => true, 'permissions' => ['participants.view']],
    'POST /participants/{id}/manage' => ['controller' => 'AdminParticipantController@manage', 'auth' => true, 'permissions' => ['participants.manage']],

    // Prize fulfillment routes
    'GET /fulfillment' => ['controller' => 'FulfillmentController@index', 'auth' => true, 'permissions' => ['fulfillment.view']],
    'POST /fulfillment/{id}' => ['controller' => 'FulfillmentController@update', 'auth' => true, 'permissions' => ['fulfillment.manage']],

    // Analytics routes
    'GET /analytics' => ['controller' => 'AdminAnalyticsController@index', 'auth' => true, 'permissions' => ['analytics.view']],
    'GET /analytics/export' => ['controller' => 'AdminAnalyticsController@export', 'auth' => true, 'permissions' => ['analytics.view']],

    // Settings routes
    'GET /settings' => ['controller' => 'AdminController@settings', 'auth' => true, 'permissions' => ['settings.view']],
    'POST /settings' => ['controller' => 'AdminController@updateSettings', 'auth' => true, 'permissions' => ['settings.edit']],
];

// Route matching
$routeKey = $method . ' ' . $requestPath;
$matchedRoute = null;
$params = [];

// Direct match first
if (isset($routes[$routeKey])) {
    $matchedRoute = $routes[$routeKey];
} else {
    // Pattern matching for routes with parameters
    foreach ($routes as $pattern => $route) {
        if (preg_match('#^' . str_replace(['{id}', '{slug}'], ['(\d+)', '([a-zA-Z0-9\-_]+)'], $pattern) . '$#', $routeKey, $matches)) {
            $matchedRoute = $route;
            array_shift($matches); // Remove full match
            $params = $matches;
            break;
        }
    }
}

// Handle route
if ($matchedRoute) {
    try {
        // Check authentication requirement
        if ($matchedRoute['auth'] ?? false) {
            // Apply authentication middleware
            $middleware->handle(function() use ($matchedRoute, $params) {
                return executeController($matchedRoute, $params);
            }, $matchedRoute['permissions'] ?? []);
        } else {
            // Execute without auth check
            executeController($matchedRoute, $params);
        }
    } catch (Exception $e) {
        app_log('error', 'Admin portal route error', [
            'route' => $routeKey,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        handleError('An error occurred while processing your request.');
    }
} else {
    // 404 Not Found
    handle404();
}

/**
 * Execute controller action
 *
 * @param array<string, mixed> $route Route configuration
 * @param array<string> $params Route parameters
 * @return mixed
 */
function executeController(array $route, array $params = [])
{
    $controller = $route['controller'];

    if (is_array($controller) && is_callable($controller)) {
        // Direct callable
        return call_user_func_array($controller, $params);
    } elseif (is_string($controller)) {
        // String format: ControllerClass@method
        [$className, $method] = explode('@', $controller);
        $fullClassName = "WinABN\\Controllers\\$className";

        if (class_exists($fullClassName)) {
            $instance = new $fullClassName();
            if (method_exists($instance, $method)) {
                return call_user_func_array([$instance, $method], $params);
            }
        }

        throw new Exception("Controller method not found: $controller");
    }

    throw new Exception("Invalid controller configuration");
}

/**
 * Handle 404 errors
 *
 * @return void
 */
function handle404(): void
{
    http_response_code(404);

    if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Route not found',
            'code' => 'NOT_FOUND'
        ]);
    } else {
        echo renderErrorPage(404, 'Page Not Found', 'The requested page could not be found.');
    }

    exit;
}

/**
 * Handle general errors
 *
 * @param string $message Error message
 * @return void
 */
function handleError(string $message): void
{
    http_response_code(500);

    if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $message,
            'code' => 'INTERNAL_ERROR'
        ]);
    } else {
        echo renderErrorPage(500, 'Internal Server Error', $message);
    }

    exit;
}

/**
 * Render error page
 *
 * @param int $code HTTP status code
 * @param string $title Error title
 * @param string $message Error message
 * @return string
 */
function renderErrorPage(int $code, string $title, string $message): string
{
    return '<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($title) . ' - WinABN Admin</title>
    <link href="' . url('assets/css/bootstrap.min.css') . '" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .error-container {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }
        .error-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            padding: 40px;
            text-align: center;
            max-width: 500px;
            width: 100%;
        }
        .error-code {
            font-size: 72px;
            color: #dc3545;
            font-weight: bold;
            margin-bottom: 20px;
        }
        .error-title {
            font-size: 24px;
            margin-bottom: 15px;
            color: #333;
        }
        .error-message {
            color: #6c757d;
            margin-bottom: 30px;
            line-height: 1.5;
        }
        .btn-home {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 12px;
            padding: 12px 30px;
            color: white;
            text-decoration: none;
            font-weight: 600;
            transition: transform 0.2s;
        }
        .btn-home:hover {
            transform: translateY(-2px);
            color: white;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-card">
            <div class="error-code">' . $code . '</div>
            <div class="error-title">' . htmlspecialchars($title) . '</div>
            <div class="error-message">' . htmlspecialchars($message) . '</div>
            <a href="' . url('adminportal/dashboard') . '" class="btn-home">Return to Dashboard</a>
        </div>
    </div>
</body>
</html>';
}

// Additional helper files for the admin portal structure:

/**
 * File: public/adminportal/.htaccess
 * Location: public/adminportal/.htaccess
 *
 * Apache configuration for admin portal
 */
/*
RewriteEngine On

# Redirect all requests to index.php
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA,L]

# Security headers
Header always set X-Content-Type-Options nosniff
Header always set X-Frame-Options DENY
Header always set X-XSS-Protection "1; mode=block"
Header always set Referrer-Policy "strict-origin-when-cross-origin"

# Cache control for admin assets
<FilesMatch "\.(css|js|png|jpg|jpeg|gif|ico|svg)$">
    ExpiresActive On
    ExpiresDefault "access plus 1 month"
    Header set Cache-Control "public, max-age=2592000"
</FilesMatch>

# Prevent access to sensitive files
<FilesMatch "\.(env|log|ini|conf|sql|bak)$">
    Require all denied
</FilesMatch>
*/

/**
 * File: public/adminportal/nginx.conf
 * Location: public/adminportal/nginx.conf
 *
 * Nginx configuration example for admin portal
 */
/*
location /adminportal {
    try_files $uri $uri/ /adminportal/index.php?$query_string;

    # Security headers
    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options DENY;
    add_header X-XSS-Protection "1; mode=block";
    add_header Referrer-Policy "strict-origin-when-cross-origin";

    # Rate limiting for admin portal
    limit_req zone=admin burst=10 nodelay;
}

location ~ ^/adminportal/.*\.php$ {
    include fastcgi_params;
    fastcgi_pass php-fpm;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_param PATH_INFO $fastcgi_path_info;

    # Additional security for admin
    fastcgi_param PHP_VALUE "session.cookie_secure=1";
    fastcgi_param PHP_VALUE "session.cookie_httponly=1";
    fastcgi_param PHP_VALUE "session.cookie_samesite=Strict";
}

# Rate limiting zone for admin portal
limit_req_zone $binary_remote_addr zone=admin:10m rate=5r/m;
*/

/**
 * File: public/adminportal/web.config
 * Location: public/adminportal/web.config
 *
 * IIS configuration for admin portal (Windows servers)
 */
/*
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
    <system.webServer>
        <rewrite>
            <rules>
                <rule name="Admin Portal Rewrite" stopProcessing="true">
                    <match url="^(.*)$" ignoreCase="false" />
                    <conditions>
                        <add input="{REQUEST_FILENAME}" matchType="IsFile" ignoreCase="false" negate="true" />
                        <add input="{REQUEST_FILENAME}" matchType="IsDirectory" ignoreCase="false" negate="true" />
                    </conditions>
                    <action type="Rewrite" url="index.php" appendQueryString="true" />
                </rule>
            </rules>
        </rewrite>
        <httpProtocol>
            <customHeaders>
                <add name="X-Content-Type-Options" value="nosniff" />
                <add name="X-Frame-Options" value="DENY" />
                <add name="X-XSS-Protection" value="1; mode=block" />
                <add name="Referrer-Policy" value="strict-origin-when-cross-origin" />
            </customHeaders>
        </httpProtocol>
        <staticContent>
            <clientCache cacheControlMode="UseMaxAge" cacheControlMaxAge="30.00:00:00" />
        </staticContent>
    </system.webServer>
</configuration>
*/
