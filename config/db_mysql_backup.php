<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Database Configuration
|--------------------------------------------------------------------------
| Edit these values if your MySQL setup changes.
| Common options:
| - XAMPP default with no password: username=root, password=''
| - XAMPP root with password: set the password below
| - Custom DB user: change username and password
*/
$DB_HOST = '127.0.0.1';
$DB_PORT = 3307;
$DB_NAME = 'financialsm';
$DB_USER = 'root';
$DB_PASS = '';
$DB_CHARSET = 'utf8mb4';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    global $DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASS, $DB_CHARSET;

    $host = trim((string) $DB_HOST);
    $port = (int) $DB_PORT;
    $dbname = trim((string) $DB_NAME);
    $username = (string) $DB_USER;
    $password = (string) $DB_PASS;
    $charset = trim((string) $DB_CHARSET);

    if ($host === '') {
        $host = '127.0.0.1';
    }

    if ($port <= 0) {
        $port = 3307;
    }

    if ($dbname === '') {
        die('Database configuration error: database name is missing.');
    }

    if ($charset === '') {
        $charset = 'utf8mb4';
    }

    $target = sprintf('%s:%d/%s', $host, $port, $dbname);
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $dbname, $charset);

    try {
        $pdo = new PDO($dsn, $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch (PDOException $e) {
        die('Database connection failed for ' . $target . ': ' . $e->getMessage());
    }
}
