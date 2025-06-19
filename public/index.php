<?php
declare(strict_types=1);

/**
 * File: public/index.php
 * Location: public/index.php
 *
 * WinABN Main Landing Page and Router
 *
 * Handles routing for game landing pages and main platform routes.
 * Optimized for mobile-first experience with fast loading times.
 *
 * @package WinABN
 * @author WinABN Development Team
 * @version 1.0
 */

// Bootstrap the application
require_once __DIR__ . '/bootstrap.php';

use WinABN\Core\{Router, Security, Database};
use WinABN\Controllers\GameController;

// Initialize security headers
Security::setSecurityHeaders();

// Initialize database connection
Database::init();

// Start secure session
session()->start();

try {
    // Initialize router
    $router = new Router();

    // Get current request
    $method = request_method();
    $uri = request_uri();
    $path = parse_url($uri, PHP_URL_PATH);

    // Route definitions

    // Homepage
    $router->get('/', function() {
        $view = new \WinABN\Core\View();

        // Get active games for homepage
        $games = Database::fetchAll("
            SELECT g.*,
                   COUNT(DISTINCT r.id) as total_rounds,
                   SUM(r.paid_participant_count) as total_participants
            FROM games g
            LEFT JOIN rounds r ON g.id = r.game_id
            WHERE g.status = 'active'
            GROUP BY g.id
            ORDER BY g.created_at DESC
        ");

        return $view->render('layouts/homepage', [
            'title' => 'WinABN - Win Amazing Prizes',
            'games' => $games,
            'meta_description' => 'Win incredible prizes by answering quick questions. iPhone, MacBook, PlayStation and cash prizes available. Fast, fun, and fair competitions.',
            'og_image' => url('assets/images/winabn-og.jpg')
        ]);
    });

    // Game landing pages - Dynamic route for /win-a-{slug}
    $router->get('/win-a-([a-z0-9\-]+)', function($slug) {
        $gameController = new GameController();
        return $gameController->showLandingPage($slug);
    });

    // Game start (AJAX endpoint)
    $router->post('/game/start', function() {
        Security::validateCsrfToken($_POST['csrf_token'] ?? '');

        $gameController = new GameController();
        return $gameController->startGame($_POST);
    });

    // Submit user data and create payment
    $router->post('/game/submit-data', function() {
        Security::validateCsrfToken($_POST['csrf_token'] ?? '');

        $gameController = new GameController();
        return $gameController->submitUserData($_POST);
    });

    // Continue game after payment
    $router->get('/game/continue/([a-zA-Z0-9]+)', function($paymentId) {
        $gameController = new GameController();
        return $gameController->continueAfterPayment($paymentId);
    });

    // Submit question answer
    $router->post('/game/answer', function() {
        Security::validateCsrfToken($_POST['csrf_token'] ?? '');

        $gameController = new GameController();
        return $gameController->submitAnswer($_POST);
    });

    // Game completion page
    $router->get('/game/complete', function() {
        $gameController = new GameController();
        return $gameController->showCompletion();
    });

    // Health check endpoint
    $router->get('/health', function() {
        $health = [
            'status' => 'ok',
            'timestamp' => date('c'),
            'database' => Database::healthCheck() ? 'connected' : 'disconnected',
            'version' => '1.0.0'
        ];

        http_response_code(Database::healthCheck() ? 200 : 503);
        json_response($health);
    });

    // Privacy Policy
    $router->get('/privacy', function() {
        $view = new \WinABN\Core\View();
        return $view->render('legal/privacy', [
            'title' => 'Privacy Policy - WinABN'
        ]);
    });

    // Terms of Service
    $router->get('/terms', function() {
        $view = new \WinABN\Core\View();
        return $view->render('legal/terms', [
            'title' => 'Terms of Service - WinABN'
        ]);
    });

    // Contact page
    $router->get('/contact', function() {
        $view = new \WinABN\Core\View();
        return $view->render('pages/contact', [
            'title' => 'Contact Us - WinABN'
        ]);
    });

    // How it works
    $router->get('/how-it-works', function() {
        $view = new \WinABN\Core\View();
        return $view->render('pages/how-it-works', [
            'title' => 'How It Works - WinABN'
        ]);
    });

    // Winners page
    $router->get('/winners', function() {
        $view = new \WinABN\Core\View();

        // Get recent winners
        $winners = Database::fetchAll("
            SELECT p.first_name, p.last_name, g.name as game_name,
                   g.prize_value, g.currency, r.completed_at,
                   p.total_time_all_questions
            FROM participants p
            JOIN rounds r ON p.round_id = r.id
            JOIN games g ON r.game_id = g.id
            WHERE p.is_winner = 1
            AND r.status = 'completed'
            ORDER BY r.completed_at DESC
            LIMIT 20
        ");

        return $view->render('pages/winners', [
            'title' => 'Recent Winners - WinABN',
            'winners' => $winners
        ]);
    });

    // FAQ page
    $router->get('/faq', function() {
        $view = new \WinABN\Core\View();
        return $view->render('pages/faq', [
            'title' => 'Frequently Asked Questions - WinABN'
        ]);
    });

    // Sitemap (for SEO)
    $router->get('/sitemap.xml', function() {
        header('Content-Type: application/xml');

        $games = Database::fetchAll("
            SELECT slug, updated_at
            FROM games
            WHERE status = 'active'
        ");

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        // Homepage
        $xml .= '<url><loc>' . url('/') . '</loc><priority>1.0</priority></url>' . "\n";

        // Game pages
        foreach ($games as $game) {
            $xml .= '<url>';
            $xml .= '<loc>' . url('/win-a-' . $game['slug']) . '</loc>';
            $xml .= '<lastmod>' . date('c', strtotime($game['updated_at'])) . '</lastmod>';
            $xml .= '<priority>0.8</priority>';
            $xml .= '</url>' . "\n";
        }

        // Static pages
        $staticPages = ['how-it-works', 'winners', 'faq', 'privacy', 'terms', 'contact'];
        foreach ($staticPages as $page) {
            $xml .= '<url>';
            $xml .= '<loc>' . url('/' . $page) . '</loc>';
            $xml .= '<priority>0.6</priority>';
            $xml .= '</url>' . "\n";
        }

        $xml .= '</urlset>';
        echo $xml;
        exit;
    });

    // Handle 404 for favicon requests
    $router->get('/favicon.ico', function() {
        $faviconPath = __DIR__ . '/assets/images/favicon.ico';
        if (file_exists($faviconPath)) {
            header('Content-Type: image/x-icon');
            readfile($faviconPath);
        } else {
            http_response_code(404);
        }
        exit;
    });

    // Robots.txt
    $router->get('/robots.txt', function() {
        header('Content-Type: text/plain');
        echo "User-agent: *\n";
        echo "Allow: /\n";
        echo "Sitemap: " . url('/sitemap.xml') . "\n";
        exit;
    });

    // Process the request
    $router->dispatch($method, $path);

} catch (Exception $e) {
    // Log the error
    app_log('error', 'Router exception: ' . $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'uri' => request_uri(),
        'method' => request_method()
    ]);

    // Show appropriate error page
    if (is_debug()) {
        echo '<pre>' . $e . '</pre>';
    } else {
        http_response_code(500);
        $view = new \WinABN\Core\View();
        echo $view->render('errors/500', [
            'title' => 'Something went wrong'
        ]);
    }
}

// Performance monitoring
if (is_debug()) {
    $endTime = microtime(true);
    $executionTime = round(($endTime - WINABN_START_TIME) * 1000, 2);
    $memoryUsage = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

    echo "<!-- Debug Info: {$executionTime}ms, {$memoryUsage}MB -->";
}
