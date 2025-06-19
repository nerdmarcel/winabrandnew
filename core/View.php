<?php
declare(strict_types=1);

/**
 * File: core/View.php
 * Location: core/View.php
 *
 * WinABN Template Engine
 *
 * Provides secure template rendering with layout support, variable escaping,
 * template inheritance, and caching for the WinABN platform.
 *
 * @package WinABN\Core
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Core;

use Exception;

class View
{
    /**
     * Views directory path
     *
     * @var string
     */
    private string $viewsPath;

    /**
     * Layouts directory path
     *
     * @var string
     */
    private string $layoutsPath;

    /**
     * Cache directory path
     *
     * @var string
     */
    private string $cachePath;

    /**
     * Global template variables
     *
     * @var array<string, mixed>
     */
    private static array $globals = [];

    /**
     * Template cache enabled
     *
     * @var bool
     */
    private bool $cacheEnabled;

    /**
     * Current rendering data
     *
     * @var array<string, mixed>
     */
    private array $data = [];

    /**
     * Constructor
     *
     * @param string|null $viewsPath Views directory path
     */
    public function __construct(?string $viewsPath = null)
    {
        $this->viewsPath = $viewsPath ?? WINABN_ROOT_DIR . '/views';
        $this->layoutsPath = $this->viewsPath . '/layouts';
        $this->cachePath = WINABN_ROOT_DIR . '/cache/views';
        $this->cacheEnabled = !env('APP_DEBUG', false);

        // Ensure cache directory exists
        if ($this->cacheEnabled && !is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }
    }

    /**
     * Render template with data
     *
     * @param string $template Template name (without .php extension)
     * @param array<string, mixed> $data Template data
     * @param string|null $layout Layout template name
     * @return string Rendered HTML
     * @throws Exception
     */
    public function render(string $template, array $data = [], ?string $layout = null): string
    {
        // Merge with global data
        $this->data = array_merge(self::$globals, $data);

        // Render the main template
        $content = $this->renderTemplate($template);

        // If layout is specified, render within layout
        if ($layout) {
            $this->data['content'] = $content;
            $content = $this->renderLayout($layout);
        }

        return $content;
    }

    /**
     * Render template file
     *
     * @param string $template Template name
     * @return string Rendered content
     * @throws Exception
     */
    private function renderTemplate(string $template): string
    {
        $templatePath = $this->getTemplatePath($template);

        if (!file_exists($templatePath)) {
            throw new Exception("Template not found: $template");
        }

        // Check cache
        if ($this->cacheEnabled) {
            $cachedContent = $this->getCachedTemplate($templatePath);
            if ($cachedContent !== null) {
                return $cachedContent;
            }
        }

        // Render template
        $content = $this->includeTemplate($templatePath);

        // Cache the result
        if ($this->cacheEnabled) {
            $this->cacheTemplate($templatePath, $content);
        }

        return $content;
    }

    /**
     * Render layout template
     *
     * @param string $layout Layout name
     * @return string Rendered content
     * @throws Exception
     */
    private function renderLayout(string $layout): string
    {
        $layoutPath = $this->layoutsPath . '/' . $layout . '.php';

        if (!file_exists($layoutPath)) {
            throw new Exception("Layout not found: $layout");
        }

        return $this->includeTemplate($layoutPath);
    }

    /**
     * Include and execute template file
     *
     * @param string $templatePath Full path to template file
     * @return string Rendered content
     */
    private function includeTemplate(string $templatePath): string
    {
        // Extract variables for template scope
        extract($this->data, EXTR_SKIP);

        // Start output buffering
        ob_start();

        try {
            include $templatePath;
        } catch (Exception $e) {
            ob_end_clean();
            throw new Exception("Error rendering template: " . $e->getMessage());
        }

        return ob_get_clean();
    }

    /**
     * Get full template path
     *
     * @param string $template Template name
     * @return string Full template path
     */
    private function getTemplatePath(string $template): string
    {
        // Convert dot notation to directory separators
        $template = str_replace('.', '/', $template);

        return $this->viewsPath . '/' . $template . '.php';
    }

    /**
     * Get cached template content
     *
     * @param string $templatePath Template file path
     * @return string|null Cached content or null if not cached/expired
     */
    private function getCachedTemplate(string $templatePath): ?string
    {
        $cacheFile = $this->getCacheFilePath($templatePath);

        if (!file_exists($cacheFile)) {
            return null;
        }

        // Check if cache is newer than template
        if (filemtime($cacheFile) < filemtime($templatePath)) {
            unlink($cacheFile);
            return null;
        }

        return file_get_contents($cacheFile);
    }

    /**
     * Cache template content
     *
     * @param string $templatePath Template file path
     * @param string $content Rendered content
     * @return void
     */
    private function cacheTemplate(string $templatePath, string $content): void
    {
        $cacheFile = $this->getCacheFilePath($templatePath);
        $cacheDir = dirname($cacheFile);

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        file_put_contents($cacheFile, $content, LOCK_EX);
    }

    /**
     * Get cache file path for template
     *
     * @param string $templatePath Template file path
     * @return string Cache file path
     */
    private function getCacheFilePath(string $templatePath): string
    {
        $relativePath = str_replace($this->viewsPath, '', $templatePath);
        $cacheKey = md5($relativePath);

        return $this->cachePath . '/' . $cacheKey . '.cache';
    }

    /**
     * Escape HTML output
     *
     * @param mixed $value Value to escape
     * @param string $encoding Character encoding
     * @return string Escaped string
     */
    public static function escape($value, string $encoding = 'UTF-8'): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, $encoding, false);
    }

    /**
     * Set global template variable
     *
     * @param string $key Variable name
     * @param mixed $value Variable value
     * @return void
     */
    public static function share(string $key, $value): void
    {
        self::$globals[$key] = $value;
    }

    /**
     * Set multiple global template variables
     *
     * @param array<string, mixed> $data Variables array
     * @return void
     */
    public static function shareMultiple(array $data): void
    {
        self::$globals = array_merge(self::$globals, $data);
    }

    /**
     * Get global template variable
     *
     * @param string $key Variable name
     * @param mixed $default Default value
     * @return mixed
     */
    public static function getGlobal(string $key, $default = null)
    {
        return self::$globals[$key] ?? $default;
    }

    /**
     * Clear template cache
     *
     * @return bool Success status
     */
    public function clearCache(): bool
    {
        if (!is_dir($this->cachePath)) {
            return true;
        }

        $files = glob($this->cachePath . '/*.cache');
        $success = true;

        foreach ($files as $file) {
            if (!unlink($file)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Check if template exists
     *
     * @param string $template Template name
     * @return bool
     */
    public function exists(string $template): bool
    {
        return file_exists($this->getTemplatePath($template));
    }

    /**
     * Include partial template
     *
     * @param string $partial Partial template name
     * @param array<string, mixed> $data Additional data for partial
     * @return string Rendered partial content
     */
    public function partial(string $partial, array $data = []): string
    {
        $originalData = $this->data;
        $this->data = array_merge($this->data, $data);

        $content = $this->renderTemplate('partials/' . $partial);

        $this->data = $originalData;
        return $content;
    }

    /**
     * Create CSRF token input field
     *
     * @return string CSRF input HTML
     */
    public static function csrfField(): string
    {
        $token = csrf_token();
        return '<input type="hidden" name="csrf_token" value="' . self::escape($token) . '">';
    }

    /**
     * Generate URL helper
     *
     * @param string $path URL path
     * @param array<string, mixed> $params Query parameters
     * @return string Generated URL
     */
    public static function url(string $path = '', array $params = []): string
    {
        return url($path, $params);
    }

    /**
     * Get current URL
     *
     * @return string Current URL
     */
    public static function currentUrl(): string
    {
        return url($_SERVER['REQUEST_URI'] ?? '/');
    }

    /**
     * Format number for display
     *
     * @param float $number Number to format
     * @param int $decimals Number of decimal places
     * @param string $decimalSeparator Decimal separator
     * @param string $thousandsSeparator Thousands separator
     * @return string Formatted number
     */
    public static function number(float $number, int $decimals = 2, string $decimalSeparator = '.', string $thousandsSeparator = ','): string
    {
        return number_format($number, $decimals, $decimalSeparator, $thousandsSeparator);
    }

    /**
     * Format currency for display
     *
     * @param float $amount Amount to format
     * @param string $currency Currency code
     * @param int $decimals Number of decimal places
     * @return string Formatted currency
     */
    public static function currency(float $amount, string $currency = 'GBP', int $decimals = 2): string
    {
        $symbols = [
            'GBP' => '£',
            'USD' => '$',
            'EUR' => '€',
            'CAD' => 'C$',
            'AUD' => 'A$'
        ];

        $symbol = $symbols[$currency] ?? $currency;
        $formatted = self::number($amount, $decimals);

        return $symbol . $formatted;
    }

    /**
     * Format date for display
     *
     * @param string|\DateTime $date Date to format
     * @param string $format Date format
     * @return string Formatted date
     */
    public static function date($date, string $format = 'Y-m-d H:i:s'): string
    {
        if (is_string($date)) {
            $date = new \DateTime($date);
        }

        return $date->format($format);
    }

    /**
     * Truncate text with ellipsis
     *
     * @param string $text Text to truncate
     * @param int $limit Character limit
     * @param string $ellipsis Ellipsis string
     * @return string Truncated text
     */
    public static function truncate(string $text, int $limit = 100, string $ellipsis = '...'): string
    {
        if (strlen($text) <= $limit) {
            return $text;
        }

        return substr($text, 0, $limit - strlen($ellipsis)) . $ellipsis;
    }

    /**
     * Convert text to title case
     *
     * @param string $text Text to convert
     * @return string Title case text
     */
    public static function title(string $text): string
    {
        return ucwords(strtolower($text));
    }

    /**
     * Convert array/object to JSON for JavaScript
     *
     * @param mixed $data Data to convert
     * @return string JSON string
     */
    public static function json($data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Generate asset URL with versioning
     *
     * @param string $asset Asset path
     * @return string Asset URL with version
     */
    public static function asset(string $asset): string
    {
        $baseUrl = rtrim(env('APP_URL', ''), '/');
        $version = env('ASSET_VERSION', '1.0');

        return $baseUrl . '/assets/' . ltrim($asset, '/') . '?v=' . $version;
    }

    /**
     * Include CSS file
     *
     * @param string $file CSS file path
     * @return string CSS link tag
     */
    public static function css(string $file): string
    {
        $url = self::asset('css/' . $file);
        return '<link rel="stylesheet" href="' . self::escape($url) . '">';
    }

    /**
     * Include JavaScript file
     *
     * @param string $file JavaScript file path
     * @param bool $defer Add defer attribute
     * @return string Script tag
     */
    public static function js(string $file, bool $defer = false): string
    {
        $url = self::asset('js/' . $file);
        $deferAttr = $defer ? ' defer' : '';
        return '<script src="' . self::escape($url) . '"' . $deferAttr . '></script>';
    }

    /**
     * Get flash message
     *
     * @param string $type Message type
     * @return string|null Flash message
     */
    public static function flash(string $type): ?string
    {
        $session = new Session();
        return $session->getFlash($type);
    }

    /**
     * Display flash messages HTML
     *
     * @return string Flash messages HTML
     */
    public static function flashMessages(): string
    {
        $session = new Session();
        $messages = [
            'success' => $session->getFlash('success'),
            'error' => $session->getFlash('error'),
            'warning' => $session->getFlash('warning'),
            'info' => $session->getFlash('info')
        ];

        $html = '';
        foreach ($messages as $type => $message) {
            if ($message) {
                $alertClass = $type === 'error' ? 'danger' : $type;
                $html .= '<div class="alert alert-' . $alertClass . ' alert-dismissible fade show" role="alert">';
                $html .= self::escape($message);
                $html .= '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
                $html .= '</div>';
            }
        }

        return $html;
    }

    /**
     * Generate pagination links
     *
     * @param array<string, mixed> $pagination Pagination data
     * @param string $baseUrl Base URL for links
     * @return string Pagination HTML
     */
    public static function paginate(array $pagination, string $baseUrl = ''): string
    {
        if ($pagination['total_pages'] <= 1) {
            return '';
        }

        $currentPage = $pagination['current_page'];
        $totalPages = $pagination['total_pages'];
        $baseUrl = $baseUrl ?: $_SERVER['REQUEST_URI'] ?? '/';

        // Remove existing page parameter
        $baseUrl = preg_replace('/[?&]page=\d+/', '', $baseUrl);
        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        $html = '<nav aria-label="Page navigation"><ul class="pagination">';

        // Previous button
        if ($pagination['has_previous']) {
            $prevUrl = $baseUrl . $separator . 'page=' . $pagination['previous_page'];
            $html .= '<li class="page-item"><a class="page-link" href="' . self::escape($prevUrl) . '">Previous</a></li>';
        } else {
            $html .= '<li class="page-item disabled"><span class="page-link">Previous</span></li>';
        }

        // Page numbers
        $start = max(1, $currentPage - 2);
        $end = min($totalPages, $currentPage + 2);

        for ($i = $start; $i <= $end; $i++) {
            if ($i === $currentPage) {
                $html .= '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
            } else {
                $pageUrl = $baseUrl . $separator . 'page=' . $i;
                $html .= '<li class="page-item"><a class="page-link" href="' . self::escape($pageUrl) . '">' . $i . '</a></li>';
            }
        }

        // Next button
        if ($pagination['has_next']) {
            $nextUrl = $baseUrl . $separator . 'page=' . $pagination['next_page'];
            $html .= '<li class="page-item"><a class="page-link" href="' . self::escape($nextUrl) . '">Next</a></li>';
        } else {
            $html .= '<li class="page-item disabled"><span class="page-link">Next</span></li>';
        }

        $html .= '</ul></nav>';
        return $html;
    }
}
