<?php
declare(strict_types=1);

/**
 * File: core/Controller.php
 * Location: core/Controller.php
 *
 * WinABN Base Controller Class
 *
 * Provides common functionality for all controllers including view rendering,
 * JSON responses, validation, error handling, and security features.
 *
 * @package WinABN\Core
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Core;

use Exception;

abstract class Controller
{
    /**
     * View instance
     *
     * @var View
     */
    protected View $view;

    /**
     * Database instance
     *
     * @var Database
     */
    protected Database $db;

    /**
     * Session instance
     *
     * @var Session
     */
    protected Session $session;

    /**
     * Request data
     *
     * @var array<string, mixed>
     */
    protected array $request;

    /**
     * Current user data
     *
     * @var array<string, mixed>|null
     */
    protected ?array $currentUser = null;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->view = new View();
        $this->db = new Database();
        $this->session = new Session();
        $this->request = $this->parseRequest();

        // Initialize current user if authenticated
        $this->initializeCurrentUser();
    }

    /**
     * Render view template
     *
     * @param string $template Template name
     * @param array<string, mixed> $data Template data
     * @param string|null $layout Layout template
     * @return string Rendered HTML
     */
    protected function render(string $template, array $data = [], ?string $layout = null): string
    {
        return $this->view->render($template, $data, $layout);
    }

    /**
     * Return JSON response
     *
     * @param mixed $data Response data
     * @param int $statusCode HTTP status code
     * @param array<string, string> $headers Additional headers
     * @return never
     */
    protected function jsonResponse($data, int $statusCode = 200, array $headers = []): never
    {
        json_response($data, $statusCode, $headers);
    }

    /**
     * Return success JSON response
     *
     * @param mixed $data Response data
     * @param string $message Success message
     * @return never
     */
    protected function jsonSuccess($data = null, string $message = 'Success'): never
    {
        $response = [
            'success' => true,
            'message' => $message
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        $this->jsonResponse($response);
    }

    /**
     * Return error JSON response
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status code
     * @param array<string, mixed> $details Error details
     * @return never
     */
    protected function jsonError(string $message, int $statusCode = 400, array $details = []): never
    {
        $response = [
            'success' => false,
            'error' => $message
        ];

        if (!empty($details)) {
            $response['details'] = $details;
        }

        $this->jsonResponse($response, $statusCode);
    }

    /**
     * Redirect to URL
     *
     * @param string $url Target URL
     * @param int $statusCode HTTP status code
     * @return never
     */
    protected function redirect(string $url, int $statusCode = 302): never
    {
        redirect($url, $statusCode);
    }

    /**
     * Redirect back to previous page
     *
     * @param string $fallback Fallback URL
     * @return never
     */
    protected function redirectBack(string $fallback = '/'): never
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? $fallback;
        $this->redirect($referer);
    }

    /**
     * Get request input value
     *
     * @param string $key Input key
     * @param mixed $default Default value
     * @return mixed
     */
    protected function input(string $key, $default = null)
    {
        return $this->request[$key] ?? $default;
    }

    /**
     * Get all request input
     *
     * @return array<string, mixed>
     */
    protected function allInput(): array
    {
        return $this->request;
    }

    /**
     * Check if request has input key
     *
     * @param string $key Input key
     * @return bool
     */
    protected function hasInput(string $key): bool
    {
        return isset($this->request[$key]);
    }

    /**
     * Get only specified input keys
     *
     * @param array<string> $keys Keys to retrieve
     * @return array<string, mixed>
     */
    protected function only(array $keys): array
    {
        return array_intersect_key($this->request, array_flip($keys));
    }

    /**
     * Get all input except specified keys
     *
     * @param array<string> $keys Keys to exclude
     * @return array<string, mixed>
     */
    protected function except(array $keys): array
    {
        return array_diff_key($this->request, array_flip($keys));
    }

    /**
     * Validate request input
     *
     * @param array<string, string> $rules Validation rules
     * @param array<string, string> $messages Custom error messages
     * @return array<string, mixed> Validated data
     * @throws Exception
     */
    protected function validate(array $rules, array $messages = []): array
    {
        $errors = [];
        $validated = [];

        foreach ($rules as $field => $rule) {
            $value = $this->input($field);
            $fieldRules = explode('|', $rule);

            foreach ($fieldRules as $fieldRule) {
                $ruleParts = explode(':', $fieldRule, 2);
                $ruleName = $ruleParts[0];
                $ruleParam = $ruleParts[1] ?? null;

                $result = $this->validateField($field, $value, $ruleName, $ruleParam);

                if ($result !== true) {
                    $errorKey = "{$field}.{$ruleName}";
                    $errors[$field] = $messages[$errorKey] ?? $result;
                    break; // Stop validating this field on first error
                }
            }

            if (!isset($errors[$field])) {
                $validated[$field] = $value;
            }
        }

        if (!empty($errors)) {
            $this->jsonError('Validation failed', 422, ['field_errors' => $errors]);
        }

        return $validated;
    }

    /**
     * Validate individual field
     *
     * @param string $field Field name
     * @param mixed $value Field value
     * @param string $rule Validation rule
     * @param string|null $param Rule parameter
     * @return string|bool Error message or true if valid
     */
    private function validateField(string $field, $value, string $rule, ?string $param)
    {
        switch ($rule) {
            case 'required':
                return !empty($value) || "The {$field} field is required";

            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL) !== false || "The {$field} must be a valid email address";

            case 'numeric':
                return is_numeric($value) || "The {$field} must be a number";

            case 'integer':
                return filter_var($value, FILTER_VALIDATE_INT) !== false || "The {$field} must be an integer";

            case 'min':
                if (is_string($value)) {
                    return strlen($value) >= (int)$param || "The {$field} must be at least {$param} characters";
                } else {
                    return $value >= (int)$param || "The {$field} must be at least {$param}";
                }

            case 'max':
                if (is_string($value)) {
                    return strlen($value) <= (int)$param || "The {$field} must not exceed {$param} characters";
                } else {
                    return $value <= (int)$param || "The {$field} must not exceed {$param}";
                }

            case 'in':
                $allowedValues = explode(',', $param);
                return in_array($value, $allowedValues) || "The {$field} must be one of: " . implode(', ', $allowedValues);

            case 'regex':
                return preg_match($param, $value) || "The {$field} format is invalid";

            case 'phone':
                $phoneRegex = '/^[\+]?[0-9\s\-\(\)]{10,}$/';
                return preg_match($phoneRegex, $value) || "The {$field} must be a valid phone number";

            case 'unique':
                [$table, $column] = explode(',', $param);
                $existing = Database::fetchColumn("SELECT COUNT(*) FROM {$table} WHERE {$column} = ?", [$value]);
                return $existing == 0 || "The {$field} is already taken";

            case 'confirmed':
                $confirmField = $field . '_confirmation';
                $confirmValue = $this->input($confirmField);
                return $value === $confirmValue || "The {$field} confirmation does not match";

            default:
                return true;
        }
    }

    /**
     * Check CSRF token
     *
     * @return bool
     */
    protected function checkCsrfToken(): bool
    {
        $token = $this->input('csrf_token') ?? $this->input('_token');
        return Security::verifyCsrfToken($token);
    }

    /**
     * Require CSRF token or throw error
     *
     * @return void
     */
    protected function requireCsrfToken(): void
    {
        if (!$this->checkCsrfToken()) {
            $this->jsonError('CSRF token mismatch', 403);
        }
    }

    /**
     * Get current authenticated user
     *
     * @return array<string, mixed>|null
     */
    protected function getCurrentUser(): ?array
    {
        return $this->currentUser;
    }

    /**
     * Check if user is authenticated
     *
     * @return bool
     */
    protected function isAuthenticated(): bool
    {
        return $this->currentUser !== null;
    }

    /**
     * Require authentication or redirect/error
     *
     * @param string $redirectUrl URL to redirect if not authenticated
     * @return void
     */
    protected function requireAuth(string $redirectUrl = '/login'): void
    {
        if (!$this->isAuthenticated()) {
            if ($this->isJsonRequest()) {
                $this->jsonError('Authentication required', 401);
            } else {
                $this->redirect($redirectUrl);
            }
        }
    }

    /**
     * Check if current request expects JSON
     *
     * @return bool
     */
    protected function isJsonRequest(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') ||
               str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
    }

    /**
     * Get client IP address
     *
     * @return string
     */
    protected function getClientIp(): string
    {
        return client_ip();
    }

    /**
     * Get current request method
     *
     * @return string
     */
    protected function getRequestMethod(): string
    {
        return request_method();
    }

    /**
     * Log controller action
     *
     * @param string $action Action description
     * @param array<string, mixed> $context Additional context
     * @return void
     */
    protected function logAction(string $action, array $context = []): void
    {
        app_log('info', $action, array_merge([
            'controller' => static::class,
            'user_id' => $this->currentUser['id'] ?? null,
            'ip_address' => $this->getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ], $context));
    }

    /**
     * Log controller error
     *
     * @param string $message Error message
     * @param array<string, mixed> $context Error context
     * @return void
     */
    protected function logError(string $message, array $context = []): void
    {
        app_log('error', $message, array_merge([
            'controller' => static::class,
            'user_id' => $this->currentUser['id'] ?? null,
            'ip_address' => $this->getClientIp(),
            'request_data' => $this->request
        ], $context));
    }

    /**
     * Parse request data from various sources
     *
     * @return array<string, mixed>
     */
    private function parseRequest(): array
    {
        $request = [];

        // Parse GET parameters
        $request = array_merge($request, $_GET);

        // Parse POST data
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $request = array_merge($request, $_POST);

            // Parse JSON body if present
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (str_contains($contentType, 'application/json')) {
                $jsonData = json_decode(file_get_contents('php://input'), true);
                if (is_array($jsonData)) {
                    $request = array_merge($request, $jsonData);
                }
            }
        }

        // Parse PUT/PATCH/DELETE data
        if (in_array($_SERVER['REQUEST_METHOD'], ['PUT', 'PATCH', 'DELETE'])) {
            parse_str(file_get_contents('php://input'), $putData);
            $request = array_merge($request, $putData);
        }

        // Sanitize all input
        return $this->sanitizeInput($request);
    }

    /**
     * Sanitize input data
     *
     * @param array<string, mixed> $data Input data
     * @return array<string, mixed>
     */
    private function sanitizeInput(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeInput($value);
            } else {
                // Basic sanitization - remove null bytes and trim
                $sanitized[$key] = trim(str_replace("\0", '', (string)$value));
            }
        }

        return $sanitized;
    }

    /**
     * Initialize current user from session
     *
     * @return void
     */
    private function initializeCurrentUser(): void
    {
        $userId = $this->session->get('user_id');

        if ($userId) {
            // This would typically load user from database
            // For now, we'll just set basic session data
            $this->currentUser = [
                'id' => $userId,
                'email' => $this->session->get('user_email'),
                'role' => $this->session->get('user_role', 'user')
            ];
        }
    }

    /**
     * Set flash message for next request
     *
     * @param string $type Message type (success, error, warning, info)
     * @param string $message Flash message
     * @return void
     */
    protected function setFlash(string $type, string $message): void
    {
        $this->session->setFlash($type, $message);
    }

    /**
     * Get flash message
     *
     * @param string $type Message type
     * @return string|null
     */
    protected function getFlash(string $type): ?string
    {
        return $this->session->getFlash($type);
    }

    /**
     * Get uploaded file information
     *
     * @param string $key File input name
     * @return array<string, mixed>|null
     */
    protected function getUploadedFile(string $key): ?array
    {
        if (!isset($_FILES[$key]) || $_FILES[$key]['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        return $_FILES[$key];
    }

    /**
     * Validate uploaded file
     *
     * @param array<string, mixed> $file File information
     * @param array<string, mixed> $rules Validation rules
     * @return string|bool Error message or true if valid
     */
    protected function validateFile(array $file, array $rules = [])
    {
        $maxSize = $rules['max_size'] ?? 10485760; // 10MB default
        $allowedTypes = $rules['allowed_types'] ?? ['jpg', 'jpeg', 'png', 'gif'];
        $allowedMimes = $rules['allowed_mimes'] ?? ['image/jpeg', 'image/png', 'image/gif'];

        // Check file size
        if ($file['size'] > $maxSize) {
            return "File size must not exceed " . number_format($maxSize / 1024 / 1024, 1) . "MB";
        }

        // Check file type by extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedTypes)) {
            return "File type must be one of: " . implode(', ', $allowedTypes);
        }

        // Check MIME type
        if (!in_array($file['type'], $allowedMimes)) {
            return "Invalid file format";
        }

        return true;
    }

    /**
     * Generate unique filename for upload
     *
     * @param string $originalName Original filename
     * @param string $prefix Optional prefix
     * @return string
     */
    protected function generateUniqueFilename(string $originalName, string $prefix = ''): string
    {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $uniqueId = uniqid($prefix, true);

        return $uniqueId . '.' . $extension;
    }

    /**
     * Rate limit check
     *
     * @param string $action Action identifier
     * @param int $maxAttempts Maximum attempts
     * @param int $timeWindow Time window in seconds
     * @return bool True if under limit
     */
    protected function checkRateLimit(string $action, int $maxAttempts = 5, int $timeWindow = 300): bool
    {
        return Security::checkRateLimit($action, $this->getClientIp(), $maxAttempts, $timeWindow);
    }

    /**
     * Require rate limit or throw error
     *
     * @param string $action Action identifier
     * @param int $maxAttempts Maximum attempts
     * @param int $timeWindow Time window in seconds
     * @return void
     */
    protected function requireRateLimit(string $action, int $maxAttempts = 5, int $timeWindow = 300): void
    {
        if (!$this->checkRateLimit($action, $maxAttempts, $timeWindow)) {
            $this->jsonError('Rate limit exceeded. Please try again later.', 429);
        }
    }

    /**
     * Cache response data
     *
     * @param string $key Cache key
     * @param mixed $data Data to cache
     * @param int $ttl Time to live in seconds
     * @return void
     */
    protected function cache(string $key, $data, int $ttl = 3600): void
    {
        // This would integrate with a caching system
        // For now, we'll use session-based caching
        $this->session->set("cache_$key", [
            'data' => $data,
            'expires' => time() + $ttl
        ]);
    }

    /**
     * Get cached data
     *
     * @param string $key Cache key
     * @return mixed Cached data or null
     */
    protected function getCached(string $key)
    {
        $cached = $this->session->get("cache_$key");

        if ($cached && $cached['expires'] > time()) {
            return $cached['data'];
        }

        return null;
    }

    /**
     * Clear cache entry
     *
     * @param string $key Cache key
     * @return void
     */
    protected function clearCache(string $key): void
    {
        $this->session->remove("cache_$key");
    }

    /**
     * Handle method not allowed
     *
     * @param array<string> $allowedMethods Allowed HTTP methods
     * @return never
     */
    protected function methodNotAllowed(array $allowedMethods = []): never
    {
        http_response_code(405);

        if (!empty($allowedMethods)) {
            header('Allow: ' . implode(', ', $allowedMethods));
        }

        if ($this->isJsonRequest()) {
            $this->jsonError('Method not allowed', 405);
        }

        $this->render('errors/405', ['allowed_methods' => $allowedMethods]);
        exit;
    }

    /**
     * Handle unauthorized access
     *
     * @param string $message Error message
     * @return never
     */
    protected function unauthorized(string $message = 'Unauthorized'): never
    {
        if ($this->isJsonRequest()) {
            $this->jsonError($message, 401);
        }

        $this->redirect('/login');
    }

    /**
     * Handle forbidden access
     *
     * @param string $message Error message
     * @return never
     */
    protected function forbidden(string $message = 'Forbidden'): never
    {
        if ($this->isJsonRequest()) {
            $this->jsonError($message, 403);
        }

        http_response_code(403);
        echo $this->render('errors/403', ['message' => $message]);
        exit;
    }

    /**
     * Execute database transaction
     *
     * @param callable $callback Transaction callback
     * @return mixed Callback return value
     * @throws Exception
     */
    protected function transaction(callable $callback)
    {
        return Database::transaction($callback);
    }

    /**
     * Paginate query results
     *
     * @param string $query SQL query
     * @param array<mixed> $params Query parameters
     * @param int $page Current page
     * @param int $perPage Items per page
     * @return array<string, mixed> Pagination data
     */
    protected function paginate(string $query, array $params = [], int $page = 1, int $perPage = 15): array
    {
        // Count total records
        $countQuery = "SELECT COUNT(*) as total FROM ($query) as count_table";
        $total = (int) Database::fetchColumn($countQuery, $params);

        // Calculate pagination
        $totalPages = ceil($total / $perPage);
        $offset = ($page - 1) * $perPage;

        // Get paginated results
        $paginatedQuery = $query . " LIMIT $perPage OFFSET $offset";
        $data = Database::fetchAll($paginatedQuery, $params);

        return [
            'data' => $data,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_previous' => $page > 1,
                'has_next' => $page < $totalPages,
                'previous_page' => $page > 1 ? $page - 1 : null,
                'next_page' => $page < $totalPages ? $page + 1 : null
            ]
        ];
    }
}
