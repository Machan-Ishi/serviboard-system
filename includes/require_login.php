<?php
// includes/require_login.php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';

$sessionTimeoutSeconds = 900;
$configuredTimeout = (int) ini_get('session.gc_maxlifetime');
if ($configuredTimeout > 0) {
    $sessionTimeoutSeconds = $configuredTimeout;
}

if (!isset($_SESSION['user_id'])) {
    header('Location: /FinancialSM/auth/login.php');
    exit;
}

$lastActivityAt = (int) ($_SESSION['last_activity_at'] ?? 0);
if ($lastActivityAt > 0 && (time() - $lastActivityAt) > $sessionTimeoutSeconds) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
    header('Location: ' . BASE_URL . '/auth/login.php?timeout=1');
    exit;
}

$_SESSION['last_activity_at'] = time();
