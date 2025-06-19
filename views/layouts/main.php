<?php
/**
 * File: views/layouts/main.php
 * Location: views/layouts/main.php
 *
 * WinABN Main Layout Template
 *
 * Mobile-first responsive layout with Bootstrap 5, optimized for fast loading
 * and excellent user experience across all devices.
 */
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">

    <!-- Title and Meta -->
    <title><?= isset($title) ? e($title) : 'WinABN - Win Amazing Prizes' ?></title>
    <meta name="description" content="<?= isset($meta_description) ? e($meta_description) : 'Win incredible prizes by answering quick questions. iPhone, MacBook, PlayStation and cash prizes available. Fast, fun, and fair competitions.' ?>">
    <meta name="keywords" content="win prizes, competitions, quiz games, iPhone giveaway, MacBook contest, PlayStation competition, cash prizes">
    <meta name="author" content="WinABN">
    <meta name="robots" content="index, follow">

    <!-- Open Graph / Social Media -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= url(request_uri()) ?>">
    <meta property="og:title" content="<?= isset($og_title) ? e($og_title) : (isset($title) ? e($title) : 'WinABN - Win Amazing Prizes') ?>">
    <meta property="og:description" content="<?= isset($og_description) ? e($og_description) : (isset($meta_description) ? e($meta_description) : 'Win incredible prizes by answering quick questions') ?>">
    <meta property="og:image" content="<?= isset($og_image) ? $og_image : url('assets/images/winabn-og.jpg') ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:site_name" content="WinABN">
    <meta property="og:locale" content="en_GB">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="@WinABN">
    <meta name="twitter:title" content="<?= isset($og_title) ? e($og_title) : (isset($title) ? e($title) : 'WinABN - Win Amazing Prizes') ?>">
    <meta name="twitter:description" content="<?= isset($og_description) ? e($og_description) : (isset($meta_description) ? e($meta_description) : 'Win incredible prizes by answering quick questions') ?>">
    <meta name="twitter:image" content="<?= isset($og_image) ? $og_image : url('assets/images/winabn-og.jpg') ?>">

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?= url('assets/images/favicon.ico') ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= url('assets/images/apple-touch-icon.png') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= url('assets/images/favicon-32x32.png') ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= url('assets/images/favicon-16x16.png') ?>">
    <link rel="manifest" href="<?= url('assets/images/site.webmanifest') ?>">

    <!-- DNS Prefetch -->
    <link rel="dns-prefetch" href="//cdnjs.cloudflare.com">
    <link rel="dns-prefetch" href="//fonts.googleapis.com">
    <link rel="dns-prefetch" href="//fonts.gstatic.com">

    <!-- Preconnect to external resources -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <!-- Critical CSS (inline for performance) -->
    <style>
        /* Critical above-the-fold styles */
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f8f9fa;
        }

        .loading-screen {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            opacity: 1;
            transition: opacity 0.5s ease;
        }

        .loading-screen.fade-out {
            opacity: 0;
            pointer-events: none;
        }

        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 4px solid rgba(255,255,255,0.3);
            border-top: 4px solid #fff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Prevent FOUC */
        .main-content {
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .main-content.loaded {
            opacity: 1;
        }

        /* Basic responsive utilities */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 15px;
        }

        @media (max-width: 768px) {
            .container {
                padding: 0 10px;
            }
        }
    </style>

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer">

    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?= url('assets/css/game.css?v=' . env('APP_VERSION', '1.0')) ?>">

    <!-- Page-specific styles -->
    <?php if (isset($styles)): ?>
        <?= $styles ?>
    <?php endif; ?>

    <?php $this->renderSection('styles'); ?>

    <!-- Structured Data -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "Organization",
        "name": "WinABN",
        "url": "<?= url('/') ?>",
        "logo": "<?= url('assets/images/winabn-logo.png') ?>",
        "description": "Win amazing prizes by answering quick questions. Fair competitions with instant results.",
        "contactPoint": {
            "@type": "ContactPoint",
            "telephone": "+44-20-7946-0958",
            "contactType": "customer service",
            "availableLanguage": "English"
        },
        "sameAs": [
            "https://facebook.com/winabn",
            "https://twitter.com/winabn",
            "https://instagram.com/winabn"
        ]
    }
    </script>

    <?php if (isset($game)): ?>
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "Game",
        "name": "<?= e($game['name']) ?>",
        "description": "<?= e($game['description']) ?>",
        "gamePlatform": "Web Browser",
        "applicationCategory": "Quiz Game",
        "offers": {
            "@type": "Offer",
            "price": "<?= $game['entry_fee'] ?>",
            "priceCurrency": "<?= $game['currency'] ?>",
            "availability": "https://schema.org/InStock"
        },
        "award": "<?= e($game['name']) ?> worth £<?= $game['prize_value'] ?>"
    }
    </script>
    <?php endif; ?>
</head>
<body>
    <!-- Loading Screen -->
    <div id="loadingScreen" class="loading-screen">
        <div class="loading-spinner"></div>
    </div>

    <!-- Skip Navigation (Accessibility) -->
    <a href="#main-content" class="visually-hidden-focusable btn btn-primary position-absolute top-0 start-0 m-2" style="z-index: 10000;">
        Skip to main content
    </a>

    <!-- Header -->
    <header class="navbar navbar-expand-lg navbar-light bg-white shadow-sm sticky-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="<?= url('/') ?>">
                <img src="<?= url('assets/images/winabn-logo.png') ?>"
                     alt="WinABN"
                     height="40"
                     class="me-2">
                <span class="fw-bold text-primary d-none d-sm-inline">WinABN</span>
            </a>

            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="<?= url('/') ?>">
                            <i class="fas fa-home me-1 d-lg-none"></i>
                            Home
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= url('/how-it-works') ?>">
                            <i class="fas fa-question-circle me-1 d-lg-none"></i>
                            How It Works
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= url('/winners') ?>">
                            <i class="fas fa-trophy me-1 d-lg-none"></i>
                            Winners
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= url('/faq') ?>">
                            <i class="fas fa-comments me-1 d-lg-none"></i>
                            FAQ
                        </a>
                    </li>
                </ul>

                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="<?= url('/contact') ?>">
                            <i class="fas fa-envelope me-1"></i>
                            Contact
                        </a>
                    </li>
                    <?php if (env('APP_ENV') !== 'production'): ?>
                    <li class="nav-item">
                        <a class="nav-link text-warning" href="<?= url('/adminportal/') ?>">
                            <i class="fas fa-cog me-1"></i>
                            Admin
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main id="main-content" class="main-content">
        <?php $this->renderSection('content'); ?>
    </main>

    <!-- Footer -->
    <footer class="bg-dark text-light py-5 mt-5">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="footer-brand mb-3">
                        <img src="<?= url('assets/images/winabn-logo-white.png') ?>"
                             alt="WinABN"
                             height="35"
                             class="mb-2">
                        <p class="text-muted">
                            Win amazing prizes by answering quick questions.
                            Fair competitions with instant results.
                        </p>
                    </div>
                    <div class="social-links">
                        <a href="https://facebook.com/winabn" class="text-light me-3" target="_blank" rel="noopener">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                        <a href="https://twitter.com/winabn" class="text-light me-3" target="_blank" rel="noopener">
                            <i class="fab fa-twitter"></i>
                        </a>
                        <a href="https://instagram.com/winabn" class="text-light me-3" target="_blank" rel="noopener">
                            <i class="fab fa-instagram"></i>
                        </a>
                        <a href="https://tiktok.com/@winabn" class="text-light" target="_blank" rel="noopener">
                            <i class="fab fa-tiktok"></i>
                        </a>
                    </div>
                </div>

                <div class="col-lg-2 col-md-6">
                    <h6 class="fw-bold mb-3">Games</h6>
                    <ul class="list-unstyled">
                        <li><a href="<?= url('/') ?>" class="text-muted text-decoration-none">Active Games</a></li>
                        <li><a href="<?= url('/winners') ?>" class="text-muted text-decoration-none">Recent Winners</a></li>
                        <li><a href="<?= url('/how-it-works') ?>" class="text-muted text-decoration-none">How It Works</a></li>
                    </ul>
                </div>

                <div class="col-lg-2 col-md-6">
                    <h6 class="fw-bold mb-3">Support</h6>
                    <ul class="list-unstyled">
                        <li><a href="<?= url('/faq') ?>" class="text-muted text-decoration-none">FAQ</a></li>
                        <li><a href="<?= url('/contact') ?>" class="text-muted text-decoration-none">Contact Us</a></li>
                        <li><a href="mailto:support@winabn.com" class="text-muted text-decoration-none">support@winabn.com</a></li>
                    </ul>
                </div>

                <div class="col-lg-2 col-md-6">
                    <h6 class="fw-bold mb-3">Legal</h6>
                    <ul class="list-unstyled">
                        <li><a href="<?= url('/terms') ?>" class="text-muted text-decoration-none">Terms of Service</a></li>
                        <li><a href="<?= url('/privacy') ?>" class="text-muted text-decoration-none">Privacy Policy</a></li>
                        <li><a href="<?= url('/cookies') ?>" class="text-muted text-decoration-none">Cookie Policy</a></li>
                    </ul>
                </div>

                <div class="col-lg-2 col-md-6">
                    <h6 class="fw-bold mb-3">Security</h6>
                    <div class="security-badges">
                        <img src="<?= url('assets/images/ssl-badge.png') ?>"
                             alt="SSL Secured"
                             height="30"
                             class="mb-2 d-block">
                        <img src="<?= url('assets/images/secure-payment.png') ?>"
                             alt="Secure Payments"
                             height="30"
                             class="mb-2 d-block">
                    </div>
                </div>
            </div>

            <hr class="my-4 border-secondary">

            <div class="row align-items-center">
                <div class="col-md-6">
                    <p class="text-muted mb-0">
                        &copy; <?= date('Y') ?> WinABN. All rights reserved.
                    </p>
                </div>
                <div class="col-md-6 text-md-end">
                    <p class="text-muted mb-0">
                        <small>
                            Made with <i class="fas fa-heart text-danger"></i> in the UK
                        </small>
                    </p>
                </div>
            </div>
        </div>
    </footer>

    <!-- Cookie Consent Banner -->
    <div id="cookieConsent" class="cookie-consent position-fixed bottom-0 start-0 end-0 bg-dark text-white p-3" style="z-index: 1050; display: none;">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-8">
                    <p class="mb-2 mb-lg-0">
                        <i class="fas fa-cookie-bite me-2"></i>
                        We use cookies to enhance your experience and analyze our traffic.
                        <a href="<?= url('/privacy') ?>" class="text-warning">Learn more</a>
                    </p>
                </div>
                <div class="col-lg-4 text-lg-end">
                    <button type="button" class="btn btn-success btn-sm me-2" onclick="acceptCookies()">
                        Accept All
                    </button>
                    <button type="button" class="btn btn-outline-light btn-sm" onclick="manageCookies()">
                        Manage
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>

    <!-- Core JavaScript -->
    <script src="<?= url('assets/js/game.js?v=' . env('APP_VERSION', '1.0')) ?>" defer></script>

    <!-- Page-specific scripts -->
    <?php if (isset($scripts)): ?>
        <?= $scripts ?>
    <?php endif; ?>

    <?php $this->renderSection('scripts'); ?>

    <!-- Analytics -->
    <?php if (env('GOOGLE_ANALYTICS_ID')): ?>
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?= env('GOOGLE_ANALYTICS_ID') ?>"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', '<?= env('GOOGLE_ANALYTICS_ID') ?>', {
            page_title: '<?= isset($title) ? addslashes($title) : 'WinABN' ?>',
            custom_map: {'custom_parameter_1': 'game_id'}
        });
    </script>
    <?php endif; ?>

    <?php if (env('FACEBOOK_PIXEL_ID')): ?>
    <script>
        !function(f,b,e,v,n,t,s)
        {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};
        if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
        n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];
        s.parentNode.insertBefore(t,s)}(window, document,'script',
        'https://connect.facebook.net/en_US/fbevents.js');
        fbq('init', '<?= env('FACEBOOK_PIXEL_ID') ?>');
        fbq('track', 'PageView');
    </script>
    <noscript>
        <img height="1" width="1" style="display:none"
             src="https://www.facebook.com/tr?id=<?= env('FACEBOOK_PIXEL_ID') ?>&ev=PageView&noscript=1"/>
    </noscript>
    <?php endif; ?>

    <!-- Core App JavaScript -->
    <script>
        // App initialization
        document.addEventListener('DOMContentLoaded', function() {
            // Hide loading screen
            const loadingScreen = document.getElementById('loadingScreen');
            const mainContent = document.querySelector('.main-content');

            setTimeout(() => {
                loadingScreen.classList.add('fade-out');
                mainContent.classList.add('loaded');

                setTimeout(() => {
                    loadingScreen.style.display = 'none';
                }, 500);
            }, 300);

            // Initialize cookie consent
            initCookieConsent();

            // Initialize performance monitoring
            if ('performance' in window) {
                window.addEventListener('load', function() {
                    setTimeout(function() {
                        const perfData = performance.timing;
                        const pageLoadTime = perfData.loadEventEnd - perfData.navigationStart;

                        if (window.gtag && pageLoadTime > 0) {
                            gtag('event', 'page_load_time', {
                                event_category: 'Performance',
                                value: Math.round(pageLoadTime),
                                custom_parameter_1: '<?= isset($game) ? $game['id'] : 'homepage' ?>'
                            });
                        }
                    }, 0);
                });
            }

            // Service Worker registration (for PWA features)
            if ('serviceWorker' in navigator && '<?= env('APP_ENV') ?>' === 'production') {
                navigator.serviceWorker.register('/sw.js')
                .then(registration => console.log('SW registered'))
                .catch(error => console.log('SW registration failed'));
            }
        });

        // Cookie consent functions
        function initCookieConsent() {
            if (!localStorage.getItem('cookieConsent')) {
                document.getElementById('cookieConsent').style.display = 'block';
            }
        }

        function acceptCookies() {
            localStorage.setItem('cookieConsent', 'accepted');
            document.getElementById('cookieConsent').style.display = 'none';
        }

        function manageCookies() {
            window.location.href = '<?= url('/cookies') ?>';
        }

        // Global error handling
        window.addEventListener('error', function(e) {
            console.error('Global error:', e.error);

            if (window.gtag) {
                gtag('event', 'exception', {
                    description: e.error.toString(),
                    fatal: false
                });
            }
        });

        // Network status monitoring
        window.addEventListener('online', function() {
            console.log('Network connection restored');
        });

        window.addEventListener('offline', function() {
            console.log('Network connection lost');
            // Show offline notification
        });
    </script>
</body>
</html>
