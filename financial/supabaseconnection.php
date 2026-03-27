<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

$host = config('SUPABASE_DB_HOST', config('DB_HOST', ''));
$port = config('SUPABASE_DB_PORT', config('DB_PORT', '5432'));
$dbname = config('SUPABASE_DB_NAME', config('DB_NAME', 'postgres'));
$username = config('SUPABASE_DB_USER', config('DB_USER', ''));
$password = config('SUPABASE_DB_PASS', config('DB_PASS', ''));
$sslMode = config('SUPABASE_DB_SSL_MODE', config('DB_SSL_MODE', 'require'));

$supabaseUrl = config('SUPABASE_URL', '');
$supabaseKey = config('SUPABASE_SERVICE_ROLE_KEY', config('SUPABASE_ANON_KEY', ''));

if ($host === '' || $username === '' || $password === '') {
    throw new RuntimeException('Supabase database credentials are not configured.');
}

try {
    $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode={$sslMode};connect_timeout=5";
    $conn = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    throw new RuntimeException("Supabase connection failed: " . $e->getMessage(), 0, $e);
}
