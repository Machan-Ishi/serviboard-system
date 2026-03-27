<?php
/**
 * Application Configuration
 *
 * This file handles environment variables and global constants.
 */

// Define Base URL
if (!defined('BASE_URL')) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $port = $_SERVER['SERVER_PORT'] ?? null;

    // Don't include port 80 for HTTP or 443 for HTTPS
    if (($protocol === 'http' && $port == 80) || ($protocol === 'https' && $port == 443)) {
        $port = null;
    }

    $baseUrl = $protocol . '://' . $host;
    if ($port) {
        $baseUrl .= ':' . $port;
    }
    $baseUrl .= '/FinancialSM';

    define('BASE_URL', $baseUrl);
}

// Improved .env parser
function load_env($path) {
    if (!file_exists($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);

        // Skip comments and empty lines
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }

        // Handle quoted values
        if (strpos($line, '=') !== false) {
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $name = trim($parts[0]);
                $value = trim($parts[1]);

                // Remove surrounding quotes if present
                if ((strpos($value, '"') === 0 && strrpos($value, '"') === strlen($value) - 1) ||
                    (strpos($value, "'") === 0 && strrpos($value, "'") === strlen($value) - 1)) {
                    $value = substr($value, 1, -1);
                }

                if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                    putenv(sprintf('%s=%s', $name, $value));
                    $_ENV[$name] = $value;
                    $_SERVER[$name] = $value;
                }
            }
        }
    }
}

// Load .env from root
load_env(__DIR__ . '/../.env');

// Helper to get config with type casting
function config($key, $default = null) {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default;

    // Type casting for common patterns
    if (is_string($default)) {
        return (string) $value;
    }
    if (is_int($default)) {
        return (int) $value;
    }
    if (is_bool($default)) {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
    if (is_float($default)) {
        return (float) $value;
    }

    return $value;
}

// Improved error handling
function app_error_handler($errno, $errstr, $errfile, $errline) {
    $message = "[$errno] $errstr in $errfile on line $errline";

    // Log error
    error_log($message);

    // Only show errors in development
    if (config('APP_DEBUG', false)) {
        // Don't expose sensitive information
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code(500);
        }
        echo json_encode([
            'error' => 'Internal Server Error',
            'debug' => config('APP_ENV', 'production') === 'development' ? $message : null
        ]);
        exit;
    }

    // In production, show a generic error page
    if (!headers_sent()) {
        http_response_code(500);
        include __DIR__ . '/../templates/500.php';
        exit;
    }
}

set_error_handler("app_error_handler");

// Set up uncaught exception handler
function app_exception_handler($exception) {
    $message = 'Uncaught Exception: ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine();
    error_log($message);

    if (config('APP_DEBUG', false)) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code(500);
        }
        echo json_encode([
            'error' => 'Internal Server Error',
            'debug' => config('APP_ENV', 'production') === 'development' ? $message : null
        ]);
        exit;
    }

    if (!headers_sent()) {
        http_response_code(500);
        include __DIR__ . '/../templates/500.php';
        exit;
    }
}

set_exception_handler("app_exception_handler");

// Force display errors if debug is on
if (config('APP_DEBUG', true)) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}
