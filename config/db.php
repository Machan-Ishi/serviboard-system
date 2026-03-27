<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (!function_exists('db_pdo_options')) {
    function db_pdo_options(string $driver, int $timeoutSeconds = 5): array
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        if ($driver === 'mysql') {
            $options[PDO::ATTR_TIMEOUT] = $timeoutSeconds;
            if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
                $options[PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci';
            }
        }

        return $options;
    }
}

if (!function_exists('db_connect_pgsql')) {
    function db_connect_pgsql(): PDO
    {
        $host = (string) config('DB_HOST', 'aws-1-ap-southeast-1.pooler.supabase.com');
        $port = (string) config('DB_PORT', '5432');
        $dbname = (string) config('DB_NAME', 'postgres');
        $user = (string) config('DB_USER', 'postgres');
        $pass = (string) config('DB_PASS', '');
        $ssl = (string) config('DB_SSL_MODE', 'require');

        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode={$ssl};connect_timeout=5";
        return new PDO($dsn, $user, $pass, db_pdo_options('pgsql'));
    }
}

if (!function_exists('db_connect_mysql')) {
    function db_connect_mysql(
        ?string $host = null,
        ?string $port = null,
        ?string $dbname = null,
        ?string $user = null,
        ?string $pass = null,
        int $timeoutSeconds = 5
    ): PDO {
        $host ??= (string) config('DB_HOST', '127.0.0.1');
        $port ??= (string) config('DB_PORT', '3306');
        $dbname ??= (string) config('DB_NAME', 'financialsm');
        $user ??= (string) config('DB_USER', 'root');
        $pass ??= (string) config('DB_PASS', '');

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
        return new PDO($dsn, $user, $pass, db_pdo_options('mysql', $timeoutSeconds));
    }
}

/**
 * Primary database connection logic.
 * Respects .env configuration for the primary driver and falls back cleanly.
 */
$configuredDriver = strtolower((string) config('DB_DRIVER', ''));
$primaryHost = strtolower((string) config('DB_HOST', 'localhost'));
$prefersPostgres = $configuredDriver === 'pgsql'
    || str_contains($primaryHost, 'supabase.co')
    || str_contains($primaryHost, 'pooler.supabase.com');
$availableDrivers = PDO::getAvailableDrivers();
$primaryErrors = [];
$fallbackErrors = [];

try {
    if ($prefersPostgres && in_array('pgsql', $availableDrivers, true)) {
        $pdo = db_connect_pgsql();
    } elseif (!$prefersPostgres && in_array('mysql', $availableDrivers, true)) {
        $pdo = db_connect_mysql();
    } elseif ($prefersPostgres && in_array('mysql', $availableDrivers, true)) {
        $primaryErrors[] = 'pdo_pgsql is not loaded; attempting local MySQL fallback.';
        $pdo = db_connect_mysql(
            (string) config('DB_FALLBACK_HOST', '127.0.0.1'),
            (string) config('DB_FALLBACK_PORT', '3307'),
            (string) config('DB_FALLBACK_NAME', 'financialsm'),
            (string) config('DB_FALLBACK_USER', 'root'),
            (string) config('DB_FALLBACK_PASS', ''),
            2
        );
    } elseif (!$prefersPostgres && in_array('pgsql', $availableDrivers, true)) {
        $primaryErrors[] = 'pdo_mysql is not loaded; attempting PostgreSQL fallback.';
        $pdo = db_connect_pgsql();
    } else {
        throw new RuntimeException(
            'No supported PDO database drivers are loaded. Available drivers: '
            . implode(', ', $availableDrivers)
        );
    }
} catch (Throwable $e) {
    $primaryErrors[] = $e->getMessage();

    try {
        if ($prefersPostgres && in_array('mysql', $availableDrivers, true)) {
            $pdo = db_connect_mysql(
                (string) config('DB_FALLBACK_HOST', '127.0.0.1'),
                (string) config('DB_FALLBACK_PORT', '3307'),
                (string) config('DB_FALLBACK_NAME', 'financialsm'),
                (string) config('DB_FALLBACK_USER', 'root'),
                (string) config('DB_FALLBACK_PASS', ''),
                2
            );
        } elseif (!$prefersPostgres && in_array('pgsql', $availableDrivers, true)) {
            $pdo = db_connect_pgsql();
        } else {
            throw new RuntimeException('No fallback database driver is available.');
        }
    } catch (Throwable $fallbackException) {
        $fallbackErrors[] = $fallbackException->getMessage();
        error_log('Database connection failed. Primary: ' . implode(' | ', $primaryErrors));
        error_log('Database fallback failed: ' . implode(' | ', $fallbackErrors));
        header('Content-Type: text/plain');
        die('System is currently unavailable. Check PHP PDO drivers and database credentials.');
    }
}

if (!function_exists('db')) {
    function db(): PDO
    {
        global $pdo;

        if (!$pdo instanceof PDO) {
            throw new RuntimeException('Database connection is not initialized.');
        }

        return $pdo;
    }
}
