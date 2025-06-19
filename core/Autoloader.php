<?php
declare(strict_types=1);

/**
 * File: core/Autoloader.php
 * Location: core/Autoloader.php
 *
 * WinABN PSR-4 Autoloader
 *
 * Implements PSR-4 autoloading standard for the WinABN application.
 * This autoloader will be used if Composer is not available or during bootstrap.
 *
 * @package WinABN\Core
 * @author WinABN Development Team
 * @version 1.0
 */
class Autoloader
{
    /**
     * Namespace to directory mappings
     *
     * @var array<string, string>
     */
    private static array $namespaces = [];

    /**
     * Register the autoloader
     *
     * @return void
     */
    public static function register(): void
    {
        spl_autoload_register([self::class, 'loadClass']);

        // Register default namespaces
        self::addNamespace('WinABN\\Controllers\\', __DIR__ . '/../controllers/');
        self::addNamespace('WinABN\\Models\\', __DIR__ . '/../models/');
        self::addNamespace('WinABN\\Core\\', __DIR__ . '/../core/');
        self::addNamespace('WinABN\\SSO\\', __DIR__ . '/../sso/');
        self::addNamespace('WinABN\\Exceptions\\', __DIR__ . '/../exceptions/');
        self::addNamespace('WinABN\\Tests\\', __DIR__ . '/../tests/');
    }

    /**
     * Add a namespace to directory mapping
     *
     * @param string $namespace The namespace prefix
     * @param string $directory The base directory for the namespace
     * @return void
     */
    public static function addNamespace(string $namespace, string $directory): void
    {
        // Normalize namespace - ensure it ends with backslash
        $namespace = rtrim($namespace, '\\') . '\\';

        // Normalize directory - ensure it ends with directory separator
        $directory = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        // Store the mapping
        self::$namespaces[$namespace] = $directory;
    }

    /**
     * Load a class file
     *
     * @param string $className Fully qualified class name
     * @return bool True if class was loaded, false otherwise
     */
    public static function loadClass(string $className): bool
    {
        // Check each registered namespace
        foreach (self::$namespaces as $namespace => $directory) {
            // Check if the class belongs to this namespace
            if (strpos($className, $namespace) === 0) {
                // Remove namespace prefix
                $relativeClass = substr($className, strlen($namespace));

                // Convert namespace separators to directory separators
                $relativeClass = str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass);

                // Build the file path
                $filePath = $directory . $relativeClass . '.php';

                // Load the file if it exists
                if (self::loadFile($filePath)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Load a file if it exists
     *
     * @param string $filePath Path to the file
     * @return bool True if file was loaded, false otherwise
     */
    private static function loadFile(string $filePath): bool
    {
        if (file_exists($filePath)) {
            require_once $filePath;
            return true;
        }

        return false;
    }

    /**
     * Get all registered namespaces
     *
     * @return array<string, string>
     */
    public static function getNamespaces(): array
    {
        return self::$namespaces;
    }

    /**
     * Check if a namespace is registered
     *
     * @param string $namespace The namespace to check
     * @return bool True if namespace is registered
     */
    public static function hasNamespace(string $namespace): bool
    {
        $namespace = rtrim($namespace, '\\') . '\\';
        return isset(self::$namespaces[$namespace]);
    }

    /**
     * Remove a namespace registration
     *
     * @param string $namespace The namespace to remove
     * @return bool True if namespace was removed
     */
    public static function removeNamespace(string $namespace): bool
    {
        $namespace = rtrim($namespace, '\\') . '\\';

        if (isset(self::$namespaces[$namespace])) {
            unset(self::$namespaces[$namespace]);
            return true;
        }

        return false;
    }
}

// Auto-register the autoloader when this file is included
Autoloader::register();
